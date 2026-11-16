#!/usr/bin/env python3
"""
CLI de chiffrement du coffre-fort.

Usage :
    python chiffrer.py <fichier_entree> <fichier_sortie.enc> [--key-file CHEMIN]

Sortie (stdout, JSON) :
    {"chemin": "...", "version": 1, "nonce": "<hex>", "tag": "<hex>",
     "sha256": "<hex>", "taille_claire": N, "taille_chiffree": M}

Codes de sortie :
    0 succès | 2 usage | 3 intégrité | 4 clé | 5 E/S | 1 autre erreur
"""

from __future__ import annotations

import argparse
import sys

from crypto import (
    EXIT_USAGE,
    CryptoVaultError,
    cli_error,
    encrypt_file,
    print_json,
)


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Chiffre un fichier en AES-256-GCM (format .enc CFV1)."
    )
    parser.add_argument("entree", help="Fichier en clair à chiffrer")
    parser.add_argument("sortie", help="Fichier .enc à produire")
    parser.add_argument(
        "--key-file",
        default=None,
        help="Keystore (défaut : COFFRE_MASTER_KEY_FILE ou crypto/keystore/master.key)",
    )
    args = parser.parse_args()

    try:
        result = encrypt_file(args.entree, args.sortie, key_file=args.key_file)
    except CryptoVaultError as exc:
        return cli_error(exc)
    except Exception as exc:  # garde-fou : aucune trace Python sur stdout
        return cli_error(exc)

    print_json(result)
    return 0


if __name__ == "__main__":
    sys.exit(main())
