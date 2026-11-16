#!/usr/bin/env python3
"""
CLI de déchiffrement du coffre-fort.

Usage :
    python dechiffrer.py <fichier.enc> <fichier_sortie> [--key-file CHEMIN]
                         [--expected-sha256 HEX]

Vérifications effectuées :
  1. intégrité du format (magic, version, longueurs) ;
  2. empreinte SHA-256 du .enc si --expected-sha256 est fourni ;
  3. tag d'authentification GCM (AES-GCM).

En cas de tag invalide, la sortie stderr contient
{"error": "INTEGRITY_CHECK_FAILED", ...} et le code de sortie est 3.
Aucun fichier clair n'est écrit en cas d'échec.
"""

from __future__ import annotations

import argparse
import sys

from crypto import (
    CryptoVaultError,
    cli_error,
    decrypt_file,
    print_json,
)


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Déchiffre un fichier .enc et vérifie son tag GCM."
    )
    parser.add_argument("entree", help="Fichier .enc à déchiffrer")
    parser.add_argument("sortie", help="Fichier en clair à produire")
    parser.add_argument("--key-file", default=None, help="Keystore à utiliser")
    parser.add_argument(
        "--expected-sha256",
        default=None,
        help="Empreinte SHA-256 attendue du fichier chiffré (optionnelle)",
    )
    args = parser.parse_args()

    try:
        result = decrypt_file(
            args.entree,
            args.sortie,
            key_file=args.key_file,
            expected_sha256=args.expected_sha256,
        )
    except CryptoVaultError as exc:
        return cli_error(exc)
    except Exception as exc:
        return cli_error(exc)

    print_json(result)
    return 0


if __name__ == "__main__":
    sys.exit(main())
