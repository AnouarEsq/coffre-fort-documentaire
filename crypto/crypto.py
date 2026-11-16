#!/usr/bin/env python3
"""
Module cryptographique du coffre-fort documentaire.

Rôle :
  - chiffrer un fichier en AES-256-GCM ;
  - déchiffrer un fichier .enc et vérifier le tag d'authentification ;
  - charger la clé maître depuis un keystore protégé (jamais de clé en dur).

Format binaire d'un fichier .enc (version 1) :

    +--------+---------+--------------+------------------------------+
    | CFV1   | version | nonce (12 o) | ciphertext + tag GCM (16 o)  |
    | 4 o    | 1 o     |              |                              |
    +--------+---------+--------------+------------------------------+

  - "CFV1" est une signature de format (magic) ;
  - version = 0x01 ;
  - nonce = 12 octets aléatoires générés par os.urandom/`secrets` pour
    CHAQUE chiffrement (jamais réutilisé avec la même clé) ;
  - le tag d'authentification GCM (16 octets) est concaténé au ciphertext
    par la librairie `cryptography`, et se trouve donc en fin de fichier.

La clé maître fait 32 octets (AES-256). Elle est lue :
  1. depuis la variable d'environnement COFFRE_MASTER_KEY (64 caractères hex) ;
  2. sinon depuis COFFRE_MASTER_KEY_FILE (chemin du keystore) ;
  3. sinon depuis crypto/keystore/master.key.

Le keystore peut être :
  - un fichier binaire brut de 32 octets, permissions 0600 ;
  - un fichier chiffré par OpenSSL (`openssl enc -aes-256-cbc -pbkdf2`),
    reconnaissable à son en-tête "Salted__". Dans ce cas la phrase de passe
    est lue dans COFFRE_KEY_PASSPHRASE et passée à OpenSSL via
    `-pass env:...` (jamais sur la ligne de commande).

Aucune primitive cryptographique n'est implémentée à la main : seules les
API de `cryptography` (AESGCM) et d'OpenSSL (protection du keystore) sont
utilisées.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import os
import secrets
import subprocess
import sys
import tempfile
from pathlib import Path
from typing import Any

from cryptography.exceptions import InvalidTag
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

# ---------------------------------------------------------------------------
# Constantes du format
# ---------------------------------------------------------------------------
MAGIC = b"CFV1"
VERSION = 1
NONCE_SIZE = 12          # 96 bits : taille recommandée pour GCM
TAG_SIZE = 16            # 128 bits
KEY_SIZE = 32            # AES-256
HEADER_SIZE = len(MAGIC) + 1 + NONCE_SIZE

# Données authentifiées mais non chiffrées (Additional Authenticated Data).
# Elles lient le chiffrement à son contexte applicatif : un fichier .enc
# produit pour un autre usage ne sera pas accepté ici.
AAD = b"coffre-fort-documentaire|document|v1"

DEFAULT_KEY_FILE = Path(__file__).resolve().parent / "keystore" / "master.key"
OPENSSL_CIPHER = "aes-256-cbc"
OPENSSL_ITER = "200000"

# Codes de sortie CLI (utilisés par le pont PHP -> Python).
EXIT_OK = 0
EXIT_ERROR = 1
EXIT_USAGE = 2
EXIT_INTEGRITY = 3
EXIT_KEY = 4
EXIT_IO = 5

# ---------------------------------------------------------------------------
# Exceptions typées : le code est exploité par PHP sans parser de message.
# ---------------------------------------------------------------------------
class CryptoVaultError(Exception):
    """Erreur de base du module."""

    code = "CRYPTO_ERROR"
    exit_code = EXIT_ERROR

    def __init__(self, message: str, code: str | None = None) -> None:
        super().__init__(message)
        if code is not None:
            self.code = code

    def as_dict(self) -> dict[str, str]:
        return {"error": self.code, "message": str(self)}


class MasterKeyError(CryptoVaultError):
    """Clé maître absente, illisible ou invalide."""

    code = "MASTER_KEY_ERROR"
    exit_code = EXIT_KEY


class IntegrityError(CryptoVaultError):
    """Le tag GCM est invalide : le fichier a été modifié ou la clé est fausse."""

    code = "INTEGRITY_CHECK_FAILED"
    exit_code = EXIT_INTEGRITY


class FormatError(CryptoVaultError):
    """Le fichier .enc ne respecte pas le format attendu."""

    code = "INVALID_FORMAT"
    exit_code = EXIT_INTEGRITY


class InputOutputError(CryptoVaultError):
    """Erreur d'accès disque."""

    code = "IO_ERROR"
    exit_code = EXIT_IO


# ---------------------------------------------------------------------------
# Utilitaires
# ---------------------------------------------------------------------------
def _sha256_hex(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def _atomic_write(path: str | os.PathLike[str], data: bytes, mode: int = 0o600) -> None:
    """Écriture atomique (fichier temporaire + os.replace) avec permissions 0600."""
    target = Path(path)
    target.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp_name = tempfile.mkstemp(
        dir=str(target.parent), prefix=".coffre-", suffix=".part"
    )
    try:
        with os.fdopen(fd, "wb") as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        try:
            os.chmod(tmp_name, mode)
        except OSError:
            # Windows ne gère pas finement les modes POSIX : non bloquant.
            pass
        os.replace(tmp_name, target)
        try:
            os.chmod(target, mode)
        except OSError:
            pass
    finally:
        if os.path.exists(tmp_name):
            try:
                os.unlink(tmp_name)
            except OSError:
                pass


def _warn_permissions(path: Path) -> None:
    """Avertit si le keystore est lisible par d'autres utilisateurs (POSIX)."""
    if os.name == "nt":
        return
    try:
        mode = path.stat().st_mode & 0o777
    except OSError:
        return
    if mode & 0o077:
        print(
            f"AVERTISSEMENT: permissions trop ouvertes sur {path} "
            f"({oct(mode)}), attendu 0600.",
            file=sys.stderr,
        )


def _resolve_key_file(key_file: str | os.PathLike[str] | None = None) -> Path:
    if key_file:
        return Path(key_file).expanduser()
    env_path = os.environ.get("COFFRE_MASTER_KEY_FILE")
    if env_path:
        return Path(env_path).expanduser()
    return DEFAULT_KEY_FILE


def _openssl_unwrap(path: Path, passphrase: str) -> bytes:
    """Déchiffre un keystore OpenSSL sans exposer la phrase de passe en argv."""
    openssl = os.environ.get("COFFRE_OPENSSL_BIN", "openssl")
    env = os.environ.copy()
    env["COFFRE_KEY_PASSPHRASE"] = passphrase
    command = [
        openssl,
        "enc",
        "-d",
        f"-{OPENSSL_CIPHER}",
        "-pbkdf2",
        "-iter",
        OPENSSL_ITER,
        "-md",
        "sha256",
        "-in",
        str(path),
        "-pass",
        "env:COFFRE_KEY_PASSPHRASE",
    ]
    try:
        result = subprocess.run(
            command, capture_output=True, check=False, env=env, timeout=60
        )
    except FileNotFoundError as exc:
        raise MasterKeyError(
            "OpenSSL introuvable : impossible de déverrouiller le keystore.",
            "MASTER_KEY_UNWRAP_FAILED",
        ) from exc
    except subprocess.TimeoutExpired as exc:
        raise MasterKeyError(
            "Délai dépassé lors du déverrouillage OpenSSL.",
            "MASTER_KEY_UNWRAP_FAILED",
        ) from exc
    if result.returncode != 0:
        raise MasterKeyError(
            "Impossible de déverrouiller le keystore OpenSSL "
            "(phrase de passe incorrecte ou fichier corrompu).",
            "MASTER_KEY_UNWRAP_FAILED",
        )
    return result.stdout


def load_master_key(
    key_file: str | os.PathLike[str] | None = None,
) -> bytes:
    """
    Charge et valide la clé maître AES-256.

    Priorité : COFFRE_MASTER_KEY (hex) > fichier keystore.
    Lève MasterKeyError si la clé est absente ou invalide.
    """
    env_key = os.environ.get("COFFRE_MASTER_KEY", "").strip()
    if env_key:
        try:
            key = bytes.fromhex(env_key)
        except ValueError as exc:
            raise MasterKeyError(
                "COFFRE_MASTER_KEY doit contenir 64 caractères hexadécimaux.",
                "MASTER_KEY_INVALID",
            ) from exc
        if len(key) != KEY_SIZE:
            raise MasterKeyError(
                f"La clé maître doit faire {KEY_SIZE} octets.", "MASTER_KEY_INVALID"
            )
        return key

    path = _resolve_key_file(key_file)
    if not path.is_file():
        raise MasterKeyError(
            f"Clé maître introuvable : {path}. "
            "Générez-la avec crypto/generate_key.py.",
            "MASTER_KEY_NOT_FOUND",
        )

    try:
        raw = path.read_bytes()
    except OSError as exc:
        raise MasterKeyError(
            f"Impossible de lire le keystore {path}.", "MASTER_KEY_UNREADABLE"
        ) from exc

    if raw.startswith(b"Salted__"):
        passphrase = os.environ.get("COFFRE_KEY_PASSPHRASE", "")
        if not passphrase:
            raise MasterKeyError(
                "Le keystore est protégé par OpenSSL : "
                "la variable COFFRE_KEY_PASSPHRASE est requise.",
                "MASTER_KEY_PASSPHRASE_REQUIRED",
            )
        key = _openssl_unwrap(path, passphrase)
    else:
        key = raw

    if len(key) != KEY_SIZE:
        raise MasterKeyError(
            f"Clé maître invalide : {len(key)} octets au lieu de {KEY_SIZE}.",
            "MASTER_KEY_INVALID",
        )
    _warn_permissions(path)
    return key


# ---------------------------------------------------------------------------
# API principale
# ---------------------------------------------------------------------------
def encrypt_file(
    input_path: str | os.PathLike[str],
    output_path: str | os.PathLike[str],
    key: bytes | None = None,
    key_file: str | os.PathLike[str] | None = None,
) -> dict[str, Any]:
    """
    Chiffre un fichier avec AES-256-GCM.

    Un nonce aléatoire de 12 octets est généré pour chaque appel. Le fichier
    de sortie contient : CFV1 | version | nonce | ciphertext+tag.

    Retourne un dictionnaire JSON-sérialisable :
      chemin, nonce (hex), tag (hex), sha256 (hex), tailles, version.
    """
    source = Path(input_path)
    destination = Path(output_path)

    if not source.is_file():
        raise InputOutputError(f"Fichier d'entrée introuvable : {source}")
    try:
        if source.resolve() == destination.resolve():
            raise InputOutputError(
                "Le fichier de sortie doit être différent du fichier d'entrée."
            )
    except OSError:
        pass

    try:
        plaintext = source.read_bytes()
    except OSError as exc:
        raise InputOutputError(f"Lecture impossible : {source}") from exc

    master_key = key if key is not None else load_master_key(key_file)
    if len(master_key) != KEY_SIZE:
        raise MasterKeyError("La clé fournie doit faire 32 octets.", "MASTER_KEY_INVALID")

    # NONCE UNIQUE PAR CHIFFREMENT : os.urandom via secrets (CSPRNG de l'OS).
    nonce = secrets.token_bytes(NONCE_SIZE)
    sealed = AESGCM(master_key).encrypt(nonce, plaintext, AAD)
    tag = sealed[-TAG_SIZE:]
    blob = MAGIC + bytes([VERSION]) + nonce + sealed

    try:
        _atomic_write(destination, blob, 0o600)
    except OSError as exc:
        raise InputOutputError(f"Écriture impossible : {destination}") from exc

    return {
        "chemin": str(destination.resolve()),
        "version": VERSION,
        "nonce": nonce.hex(),
        "tag": tag.hex(),
        "sha256": _sha256_hex(blob),
        "taille_claire": len(plaintext),
        "taille_chiffree": len(blob),
    }


def decrypt_file(
    encrypted_path: str | os.PathLike[str],
    output_path: str | os.PathLike[str],
    key: bytes | None = None,
    key_file: str | os.PathLike[str] | None = None,
    expected_sha256: str | None = None,
) -> dict[str, Any]:
    """
    Déchiffre un fichier .enc et vérifie le tag GCM.

    Si le tag est invalide (fichier modifié, mauvaise clé), lève
    IntegrityError("INTEGRITY_CHECK_FAILED") et n'écrit AUCUN fichier clair.
    Si expected_sha256 est fourni, l'empreinte du .enc est vérifiée avant
    déchiffrement (défense en profondeur).
    """
    source = Path(encrypted_path)
    destination = Path(output_path)

    if not source.is_file():
        raise InputOutputError(f"Fichier chiffré introuvable : {source}")
    try:
        if source.resolve() == destination.resolve():
            raise InputOutputError(
                "Le fichier de sortie doit être différent du fichier chiffré."
            )
    except OSError:
        pass

    try:
        blob = source.read_bytes()
    except OSError as exc:
        raise InputOutputError(f"Lecture impossible : {source}") from exc

    if expected_sha256:
        actual = _sha256_hex(blob)
        if not hmac.compare_digest(actual, expected_sha256.strip().lower()):
            raise IntegrityError(
                "INTEGRITY_CHECK_FAILED: l'empreinte SHA-256 du fichier chiffré "
                "ne correspond pas à celle enregistrée en base."
            )

    # Toute altération d'en-tête est traitée comme un échec d'intégrité.
    if len(blob) < HEADER_SIZE + TAG_SIZE:
        raise IntegrityError(
            "INTEGRITY_CHECK_FAILED: fichier .enc tronqué ou en-tête incomplet."
        )
    if blob[: len(MAGIC)] != MAGIC:
        raise IntegrityError(
            "INTEGRITY_CHECK_FAILED: signature de fichier invalide."
        )
    version = blob[len(MAGIC)]
    if version != VERSION:
        raise IntegrityError(
            f"INTEGRITY_CHECK_FAILED: version de format non supportée ({version})."
        )

    nonce = blob[len(MAGIC) + 1 : HEADER_SIZE]
    sealed = blob[HEADER_SIZE:]
    if len(nonce) != NONCE_SIZE or len(sealed) < TAG_SIZE:
        raise IntegrityError(
            "INTEGRITY_CHECK_FAILED: structure du fichier .enc invalide."
        )

    master_key = key if key is not None else load_master_key(key_file)
    if len(master_key) != KEY_SIZE:
        raise MasterKeyError("La clé fournie doit faire 32 octets.", "MASTER_KEY_INVALID")

    try:
        plaintext = AESGCM(master_key).decrypt(nonce, sealed, AAD)
    except InvalidTag as exc:
        raise IntegrityError(
            "INTEGRITY_CHECK_FAILED: tag d'authentification GCM invalide. "
            "Le document a été modifié ou la clé est incorrecte."
        ) from exc

    try:
        _atomic_write(destination, plaintext, 0o600)
    except OSError as exc:
        raise InputOutputError(f"Écriture impossible : {destination}") from exc

    return {
        "chemin": str(destination.resolve()),
        "version": version,
        "nonce": nonce.hex(),
        "tag": sealed[-TAG_SIZE:].hex(),
        "sha256": _sha256_hex(plaintext),
        "sha256_chiffre": _sha256_hex(blob),
        "taille": len(plaintext),
    }


def inspect_file(encrypted_path: str | os.PathLike[str]) -> dict[str, Any]:
    """Lit les métadonnées d'un .enc sans le déchiffrer (diagnostic/tests)."""
    source = Path(encrypted_path)
    if not source.is_file():
        raise InputOutputError(f"Fichier chiffré introuvable : {source}")
    blob = source.read_bytes()
    if len(blob) < HEADER_SIZE + TAG_SIZE or blob[: len(MAGIC)] != MAGIC:
        raise FormatError("Fichier .enc invalide.")
    return {
        "chemin": str(source.resolve()),
        "version": blob[len(MAGIC)],
        "nonce": blob[len(MAGIC) + 1 : HEADER_SIZE].hex(),
        "tag": blob[-TAG_SIZE:].hex(),
        "sha256": _sha256_hex(blob),
        "taille_chiffree": len(blob),
    }


def cli_error(exc: Exception) -> int:
    """Affiche une erreur JSON sur stderr et renvoie le code de sortie adapté."""
    if isinstance(exc, CryptoVaultError):
        payload = exc.as_dict()
        code = exc.exit_code
    else:
        payload = {"error": "CRYPTO_ERROR", "message": str(exc)}
        code = EXIT_ERROR
    print(json.dumps(payload, ensure_ascii=False), file=sys.stderr)
    return code


def print_json(payload: dict[str, Any]) -> None:
    print(json.dumps(payload, ensure_ascii=False))
