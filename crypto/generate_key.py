#!/usr/bin/env python3
"""
Génération de la clé maître du coffre-fort.

Deux modes :

1. Fichier brut 0600 (défaut) :
       python generate_key.py
       -> crypto/keystore/master.key (32 octets aléatoires, permissions 0600)

2. Keystore protégé par OpenSSL (recommandé si OpenSSL est installé) :
       set COFFRE_KEY_PASSPHRASE=...   (ou invitation interactive)
       python generate_key.py --openssl
       -> crypto/keystore/master.key.enc
          (AES-256-CBC + PBKDF2, sel OpenSSL, phrase de passe NON stockée)

La clé n'est JAMAIS affichée ni écrite dans les logs. Le JSON de sortie ne
contient que le chemin, le mode de protection et une empreinte du keystore.

Attention : en mode OpenSSL, l'application doit avoir COFFRE_KEY_PASSPHRASE
dans son environnement pour déverrouiller la clé au démarrage d'un
chiffrement/déchiffrement.
"""

from __future__ import annotations

import argparse
import getpass
import hashlib
import json
import os
import secrets
import subprocess
import sys
from pathlib import Path

from crypto import (
    DEFAULT_KEY_FILE,
    EXIT_ERROR,
    EXIT_KEY,
    EXIT_OK,
    KEY_SIZE,
    OPENSSL_CIPHER,
    OPENSSL_ITER,
    MasterKeyError,
    _atomic_write,
    _openssl_unwrap,
)


def _read_passphrase(confirm: bool) -> str:
    passphrase = os.environ.get("COFFRE_KEY_PASSPHRASE", "")
    if passphrase:
        if len(passphrase) < 12:
            raise MasterKeyError(
                "COFFRE_KEY_PASSPHRASE doit contenir au moins 12 caractères.",
                "WEAK_PASSPHRASE",
            )
        return passphrase

    if not sys.stdin.isatty():
        raise MasterKeyError(
            "Mode --openssl : définissez COFFRE_KEY_PASSPHRASE "
            "(>= 12 caractères) ou lancez la commande de façon interactive.",
            "MASTER_KEY_PASSPHRASE_REQUIRED",
        )

    first = getpass.getpass("Phrase de passe du keystore : ")
    if confirm:
        second = getpass.getpass("Confirmation : ")
        if first != second:
            raise MasterKeyError("Les phrases de passe ne correspondent pas.")
    if len(first) < 12:
        raise MasterKeyError(
            "La phrase de passe doit contenir au moins 12 caractères.",
            "WEAK_PASSPHRASE",
        )
    return first


def _openssl_wrap(raw_path: Path, target: Path, passphrase: str) -> None:
    openssl = os.environ.get("COFFRE_OPENSSL_BIN", "openssl")
    env = os.environ.copy()
    env["COFFRE_KEY_PASSPHRASE"] = passphrase
    command = [
        openssl,
        "enc",
        f"-{OPENSSL_CIPHER}",
        "-pbkdf2",
        "-iter",
        OPENSSL_ITER,
        "-md",
        "sha256",
        "-salt",
        "-in",
        str(raw_path),
        "-out",
        str(target),
        "-pass",
        "env:COFFRE_KEY_PASSPHRASE",
    ]
    try:
        result = subprocess.run(
            command, capture_output=True, check=False, env=env, timeout=60
        )
    except FileNotFoundError as exc:
        raise MasterKeyError(
            "OpenSSL est introuvable. Installez-le ou utilisez le mode fichier 0600.",
            "OPENSSL_NOT_FOUND",
        ) from exc
    if result.returncode != 0:
        raise MasterKeyError(
            "Échec du chiffrement OpenSSL du keystore.",
            "OPENSSL_WRAP_FAILED",
        )


def main() -> int:
    parser = argparse.ArgumentParser(description="Génère la clé maître AES-256.")
    parser.add_argument(
        "--key-file",
        default=None,
        help="Chemin du keystore à créer (défaut : crypto/keystore/master.key)",
    )
    parser.add_argument(
        "--openssl",
        action="store_true",
        help="Protège la clé avec OpenSSL (AES-256-CBC + PBKDF2)",
    )
    parser.add_argument(
        "--force",
        action="store_true",
        help="Écrase un keystore existant (DANGEREUX : les documents existants deviennent illisibles)",
    )
    args = parser.parse_args()

    target = Path(
        args.key_file
        or os.environ.get("COFFRE_MASTER_KEY_FILE")
        or DEFAULT_KEY_FILE
    ).expanduser()

    if args.openssl and target.suffix != ".enc":
        target = target.with_name(target.name + ".enc")

    if target.exists() and not args.force:
        print(
            json.dumps(
                {
                    "error": "KEY_ALREADY_EXISTS",
                    "message": f"Le keystore {target} existe déjà. "
                    "Utilisez --force pour l'écraser (irréversible).",
                },
                ensure_ascii=False,
            ),
            file=sys.stderr,
        )
        return EXIT_ERROR

    target.parent.mkdir(parents=True, exist_ok=True)
    try:
        os.chmod(target.parent, 0o700)
    except OSError:
        pass

    master_key = secrets.token_bytes(KEY_SIZE)

    try:
        if args.openssl:
            passphrase = _read_passphrase(confirm=True)
            raw_path = target.parent / f".{target.name}.raw-{secrets.token_hex(8)}"
            _atomic_write(raw_path, master_key, 0o600)
            try:
                _openssl_wrap(raw_path, target, passphrase)
                os.chmod(target, 0o600)
                # Vérification immédiate : on doit pouvoir relire la clé.
                unwrapped = _openssl_unwrap(target, passphrase)
                if unwrapped != master_key:
                    raise MasterKeyError(
                        "Vérification du keystore OpenSSL échouée.",
                        "OPENSSL_VERIFY_FAILED",
                    )
            finally:
                if raw_path.exists():
                    raw_path.unlink()
            protection = "openssl-aes-256-cbc-pbkdf2"
        else:
            _atomic_write(target, master_key, 0o600)
            protection = "fichier-brut-0600"
    except (MasterKeyError, OSError) as exc:
        print(
            json.dumps(
                {"error": getattr(exc, "code", "KEYGEN_ERROR"), "message": str(exc)},
                ensure_ascii=False,
            ),
            file=sys.stderr,
        )
        return EXIT_KEY

    payload = {
        "chemin": str(target.resolve()),
        "protection": protection,
        "taille_cle": KEY_SIZE,
        "empreinte_keystore_sha256": hashlib.sha256(target.read_bytes()).hexdigest(),
        "message": "Clé maître générée. Ne jamais la committer ni la journaliser.",
    }
    print(json.dumps(payload, ensure_ascii=False))
    return EXIT_OK


if __name__ == "__main__":
    sys.exit(main())
