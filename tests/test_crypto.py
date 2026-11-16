#!/usr/bin/env python3
"""
Tests automatisés du module cryptographique (Module A / Module G).

Aucune base de données ni serveur web n'est nécessaire :
    python tests/test_crypto.py -v

Scénarios couverts :
  - aller-retour chiffrement/déchiffrement ;
  - format binaire (magic, version, nonce 12 o, tag 16 o) ;
  - unicité du nonce sur 1000 chiffrements ;
  - altération d'un octet (ciphertext, tag, nonce, magic) -> INTEGRITY_CHECK_FAILED ;
  - mauvaise clé -> INTEGRITY_CHECK_FAILED ;
  - fichier tronqué -> refus ;
  - SHA-256 attendu invalide -> refus avant déchiffrement ;
  - clé maître absente -> MASTER_KEY_NOT_FOUND ;
  - interface CLI : JSON de succès, code de sortie 3, aucun fichier clair produit.
"""

from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

CRYPTO_DIR = Path(__file__).resolve().parent.parent / "crypto"
sys.path.insert(0, str(CRYPTO_DIR))

from crypto import (  # noqa: E402
    HEADER_SIZE,
    KEY_SIZE,
    MAGIC,
    NONCE_SIZE,
    TAG_SIZE,
    VERSION,
    IntegrityError,
    MasterKeyError,
    decrypt_file,
    encrypt_file,
    inspect_file,
)


class CryptoVaultTests(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = Path(tempfile.mkdtemp(prefix="coffre-test-"))
        self.key = bytes(range(KEY_SIZE))
        self.plain = self.tmp / "document.pdf"
        self.plain.write_bytes(b"%PDF-1.4\n% contenu sensible de test\n%%EOF\n")
        self.enc = self.tmp / "document.pdf.enc"

    def tearDown(self) -> None:
        shutil.rmtree(self.tmp, ignore_errors=True)

    # -- 1. Aller-retour -----------------------------------------------------
    def test_roundtrip(self) -> None:
        meta = encrypt_file(self.plain, self.enc, key=self.key)
        self.assertTrue(self.enc.is_file())
        self.assertEqual(meta["taille_claire"], self.plain.stat().st_size)
        out = self.tmp / "sortie.pdf"
        result = decrypt_file(self.enc, out, key=self.key)
        self.assertEqual(out.read_bytes(), self.plain.read_bytes())
        self.assertEqual(result["sha256_chiffre"], meta["sha256"])

    def test_empty_file_roundtrip(self) -> None:
        empty = self.tmp / "vide.bin"
        empty.write_bytes(b"")
        enc = self.tmp / "vide.enc"
        encrypt_file(empty, enc, key=self.key)
        out = self.tmp / "vide.out"
        decrypt_file(enc, out, key=self.key)
        self.assertEqual(out.read_bytes(), b"")

    # -- 2. Format -----------------------------------------------------------
    def test_binary_format(self) -> None:
        meta = encrypt_file(self.plain, self.enc, key=self.key)
        blob = self.enc.read_bytes()
        self.assertEqual(blob[:4], MAGIC)
        self.assertEqual(blob[4], VERSION)
        self.assertEqual(len(blob), HEADER_SIZE + len(self.plain.read_bytes()) + TAG_SIZE)
        nonce = blob[5:HEADER_SIZE]
        self.assertEqual(len(nonce), NONCE_SIZE)
        self.assertEqual(nonce.hex(), meta["nonce"])
        self.assertEqual(blob[-TAG_SIZE:].hex(), meta["tag"])
        self.assertEqual(len(bytes.fromhex(meta["tag"])), TAG_SIZE)
        self.assertEqual(len(meta["sha256"]), 64)
        self.assertNotIn(self.plain.read_bytes(), blob)

        inspected = inspect_file(self.enc)
        self.assertEqual(inspected["nonce"], meta["nonce"])
        self.assertEqual(inspected["tag"], meta["tag"])

    # -- 3. Nonce unique -----------------------------------------------------
    def test_nonce_uniqueness(self) -> None:
        nonces = set()
        for _ in range(1000):
            meta = encrypt_file(self.plain, self.enc, key=self.key)
            nonces.add(meta["nonce"])
        self.assertEqual(len(nonces), 1000, "réutilisation de nonce détectée !")

    # -- 4. Altérations ------------------------------------------------------
    def _tampered_copy(self, offset: int) -> Path:
        encrypt_file(self.plain, self.enc, key=self.key)
        blob = bytearray(self.enc.read_bytes())
        blob[offset] ^= 0x01
        tampered = self.tmp / "tampered.enc"
        tampered.write_bytes(bytes(blob))
        return tampered

    def test_tamper_ciphertext(self) -> None:
        tampered = self._tampered_copy(HEADER_SIZE + 3)
        with self.assertRaises(IntegrityError) as ctx:
            decrypt_file(tampered, self.tmp / "out", key=self.key)
        self.assertEqual(ctx.exception.code, "INTEGRITY_CHECK_FAILED")
        self.assertFalse((self.tmp / "out").exists())

    def test_tamper_tag(self) -> None:
        tampered = self._tampered_copy(-1)
        with self.assertRaises(IntegrityError):
            decrypt_file(tampered, self.tmp / "out", key=self.key)

    def test_tamper_nonce(self) -> None:
        tampered = self._tampered_copy(HEADER_SIZE - 1)
        with self.assertRaises(IntegrityError):
            decrypt_file(tampered, self.tmp / "out", key=self.key)

    def test_tamper_magic(self) -> None:
        tampered = self._tampered_copy(0)
        with self.assertRaises(IntegrityError):
            decrypt_file(tampered, self.tmp / "out", key=self.key)

    def test_truncated_file(self) -> None:
        encrypt_file(self.plain, self.enc, key=self.key)
        truncated = self.tmp / "tronque.enc"
        truncated.write_bytes(self.enc.read_bytes()[:20])
        with self.assertRaises(IntegrityError):
            decrypt_file(truncated, self.tmp / "out", key=self.key)

    # -- 5. Clés -------------------------------------------------------------
    def test_wrong_key(self) -> None:
        encrypt_file(self.plain, self.enc, key=self.key)
        with self.assertRaises(IntegrityError):
            decrypt_file(self.enc, self.tmp / "out", key=b"\x00" * KEY_SIZE)

    def test_missing_master_key(self) -> None:
        saved = {
            name: os.environ.get(name)
            for name in ("COFFRE_MASTER_KEY", "COFFRE_MASTER_KEY_FILE")
        }
        os.environ.pop("COFFRE_MASTER_KEY", None)
        os.environ["COFFRE_MASTER_KEY_FILE"] = str(self.tmp / "absent.key")
        try:
            with self.assertRaises(MasterKeyError) as ctx:
                encrypt_file(self.plain, self.enc, key=None)
            self.assertEqual(ctx.exception.code, "MASTER_KEY_NOT_FOUND")
        finally:
            for name, value in saved.items():
                if value is None:
                    os.environ.pop(name, None)
                else:
                    os.environ[name] = value

    # -- 6. SHA-256 attendu --------------------------------------------------
    def test_expected_sha256_mismatch(self) -> None:
        encrypt_file(self.plain, self.enc, key=self.key)
        with self.assertRaises(IntegrityError):
            decrypt_file(
                self.enc,
                self.tmp / "out",
                key=self.key,
                expected_sha256="0" * 64,
            )


class CryptoCliTests(unittest.TestCase):
    """Teste l'interface réellement utilisée par PHP (subprocess + JSON)."""

    def setUp(self) -> None:
        self.tmp = Path(tempfile.mkdtemp(prefix="coffre-cli-"))
        self.env = os.environ.copy()
        self.env.pop("COFFRE_MASTER_KEY", None)
        self.env.pop("COFFRE_KEY_PASSPHRASE", None)
        self.env["COFFRE_MASTER_KEY_FILE"] = str(self.tmp / "master.key")
        self.env["PYTHONIOENCODING"] = "utf-8"
        subprocess.run(
            [
                sys.executable,
                str(CRYPTO_DIR / "generate_key.py"),
                "--key-file",
                str(self.tmp / "master.key"),
            ],
            check=True,
            capture_output=True,
            env=self.env,
        )
        self.plain = self.tmp / "acte.pdf"
        self.plain.write_bytes(b"%PDF-1.4\nacte authentique\n%%EOF\n")
        self.enc = self.tmp / "acte.pdf.enc"

    def tearDown(self) -> None:
        shutil.rmtree(self.tmp, ignore_errors=True)

    def run_cli(self, script: str, *args: str) -> subprocess.CompletedProcess:
        return subprocess.run(
            [sys.executable, str(CRYPTO_DIR / script), *args],
            capture_output=True,
            env=self.env,
            text=True,
            encoding="utf-8",
        )

    def test_cli_success_json(self) -> None:
        proc = self.run_cli("chiffrer.py", str(self.plain), str(self.enc))
        self.assertEqual(proc.returncode, 0, proc.stderr)
        payload = json.loads(proc.stdout)
        for key in ("chemin", "nonce", "tag", "sha256"):
            self.assertIn(key, payload)
        self.assertEqual(len(payload["nonce"]), 24)
        self.assertEqual(len(payload["tag"]), 32)
        self.assertEqual(len(payload["sha256"]), 64)

        out = self.tmp / "acte.out.pdf"
        proc = self.run_cli(
            "dechiffrer.py",
            str(self.enc),
            str(out),
            "--expected-sha256",
            payload["sha256"],
        )
        self.assertEqual(proc.returncode, 0, proc.stderr)
        self.assertEqual(out.read_bytes(), self.plain.read_bytes())
        self.assertTrue(out.is_file())

    def test_cli_integrity_exit_code(self) -> None:
        self.assertEqual(
            self.run_cli("chiffrer.py", str(self.plain), str(self.enc)).returncode, 0
        )
        blob = bytearray(self.enc.read_bytes())
        blob[HEADER_SIZE + 1] ^= 0xFF
        tampered = self.tmp / "tampered.enc"
        tampered.write_bytes(bytes(blob))

        out = self.tmp / "ne-doit-pas-exister.pdf"
        proc = self.run_cli("dechiffrer.py", str(tampered), str(out))
        self.assertEqual(proc.returncode, 3, proc.stderr)
        error = json.loads(proc.stderr)
        self.assertEqual(error["error"], "INTEGRITY_CHECK_FAILED")
        self.assertFalse(out.exists(), "un fichier clair a été produit malgré l'échec")

    def test_cli_missing_key_exit_code(self) -> None:
        self.env["COFFRE_MASTER_KEY_FILE"] = str(self.tmp / "absent.key")
        proc = self.run_cli("chiffrer.py", str(self.plain), str(self.enc))
        self.assertEqual(proc.returncode, 4)
        self.assertEqual(json.loads(proc.stderr)["error"], "MASTER_KEY_NOT_FOUND")

    def test_generate_key_refuses_overwrite(self) -> None:
        proc = self.run_cli(
            "generate_key.py", "--key-file", str(self.tmp / "master.key")
        )
        self.assertEqual(proc.returncode, 1)
        self.assertEqual(json.loads(proc.stderr)["error"], "KEY_ALREADY_EXISTS")


if __name__ == "__main__":
    unittest.main(verbosity=2)
