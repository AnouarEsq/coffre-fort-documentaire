#!/usr/bin/env python3
"""
Tests d'intégration HTTP, RBAC, audit et intégrité (Module G).

Prérequis :
  - PostgreSQL initialisé et peuplé (php scripts/init_db.php && php scripts/seed_demo.php) ;
  - serveur lancé : php -S 127.0.0.1:8000 -t public ;
  - PHP disponible pour les scripts de diagnostic.

Exemple :
  python tests/http_security_tests.py --base-url http://127.0.0.1:8000 --php php

Le script crée un document de test (PDF), le consulte, puis le retire via
l'interface admin. Le retrait est logique : une ligne de test reste en base et
les événements d'audit sont conservés (comportement attendu d'un journal
append-only).

Ce script utilise uniquement la bibliothèque standard Python.
"""

from __future__ import annotations

import argparse
import http.cookiejar
import json
import os
import re
import secrets
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

PASS, FAIL, SKIP = "PASS", "FAIL", "SKIP"
results: list[tuple[str, str, str, str, bool]] = []


def record(code: str, description: str, expected: str, obtained: str, ok: bool) -> None:
    results.append((code, description, expected, obtained, ok))
    status = PASS if ok else FAIL
    print(f"[{status}] {code} — {description}")
    print(f"        attendu : {expected}")
    print(f"        obtenu  : {obtained}")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Client:
    def __init__(self, base_url: str) -> None:
        self.base_url = base_url.rstrip("/")
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.jar), NoRedirect()
        )
        self.last_headers = {}

    def request(
        self,
        method: str,
        path: str,
        data: bytes | None = None,
        headers: dict[str, str] | None = None,
    ) -> tuple[int, dict[str, str], bytes]:
        url = self.base_url + path
        request = urllib.request.Request(url, data=data, method=method)
        request.add_header("User-Agent", "CoffreSecurityTests/1.0")
        if headers:
            for key, value in headers.items():
                request.add_header(key, value)
        try:
            response = self.opener.open(request, timeout=30)
            status = response.status
            body = response.read()
            hdrs = dict(response.headers.items())
        except urllib.error.HTTPError as error:
            status = error.code
            body = error.read()
            hdrs = dict(error.headers.items())
        self.last_headers = hdrs
        return status, hdrs, body

    def get(self, path: str) -> tuple[int, dict[str, str], bytes]:
        return self.request("GET", path)

    def post(self, path: str, fields: dict[str, str]) -> tuple[int, dict[str, str], bytes]:
        data = urllib.parse.urlencode(fields).encode("utf-8")
        return self.request(
            "POST",
            path,
            data,
            {"Content-Type": "application/x-www-form-urlencoded"},
        )

    def post_multipart(self, path: str, fields: dict[str, str], filename: str, content: bytes):
        boundary = "----coffre" + secrets.token_hex(12)
        chunks: list[bytes] = []
        for name, value in fields.items():
            chunks.append(
                f"--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"\r\n\r\n{value}\r\n".encode()
            )
        chunks.append(
            (
                f"--{boundary}\r\nContent-Disposition: form-data; name=\"document\"; "
                f"filename=\"{filename}\"\r\nContent-Type: application/pdf\r\n\r\n"
            ).encode()
        )
        chunks.append(content)
        chunks.append(f"\r\n--{boundary}--\r\n".encode())
        data = b"".join(chunks)
        return self.request(
            "POST",
            path,
            data,
            {"Content-Type": f"multipart/form-data; boundary={boundary}"},
        )


def csrf_from(body: bytes) -> str:
    match = re.search(rb'name="csrf_token"\s+value="([a-f0-9]+)"', body)
    return match.group(1).decode() if match else ""


def login(client: Client, email: str, password: str) -> tuple[bool, bytes]:
    status, _, body = client.get("/login.php")
    token = csrf_from(body)
    status, headers, body = client.post(
        "/login.php", {"csrf_token": token, "email": email, "password": password}
    )
    ok = status == 302 and "/dashboard.php" in headers.get("Location", "")
    return ok, body


def audit_check(client: Client, action: str, needle: str, document_id: int | None = None) -> tuple[bool, str]:
    query = {"action": action}
    if document_id is not None:
        query["document_id"] = str(document_id)
    status, _, body = client.get("/audit.php?" + urllib.parse.urlencode(query))
    text = body.decode("utf-8", "replace")
    return (status == 200 and needle in text), f"HTTP {status}, motif « {needle} » {'trouvé' if needle in text else 'ABSENT'}"


def php_json(php_bin: str, script: str, *args: str) -> dict:
    proc = subprocess.run(
        [php_bin, str(ROOT / "scripts" / script), *args],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
        timeout=60,
    )
    if proc.returncode != 0:
        raise RuntimeError(f"{script} a échoué : {proc.stderr.strip()}")
    return json.loads(proc.stdout)


def first_document_id(body: bytes) -> int | None:
    match = re.search(rb"/view\.php\?id=(\d+)", body)
    return int(match.group(1)) if match else None


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default="http://127.0.0.1:8000")
    parser.add_argument("--php", default=os.environ.get("PHP_BIN", "php"))
    parser.add_argument("--skip-upload", action="store_true", help="Ne pas tester le cycle upload/retrait")
    args = parser.parse_args()

    base = args.base_url.rstrip("/")
    try:
        status, _, _ = Client(base).get("/login.php")
    except Exception as exc:  # noqa: BLE001
        print(f"Serveur inaccessible sur {base} : {exc}")
        return 2
    if status != 200:
        print(f"/login.php renvoie HTTP {status}. Vérifiez le serveur et la base.")
        return 2

    print(f"\n=== Tests HTTP sur {base} ===\n")

    # ------------------------------------------------------------------
    # T01 — accès direct sans session
    # ------------------------------------------------------------------
    anonymous = Client(base)
    status, headers, _ = anonymous.get("/dashboard.php")
    location = headers.get("Location", "")
    record(
        "T01",
        "Accès direct au tableau de bord sans session",
        "302 vers /login.php",
        f"HTTP {status}, Location={location}",
        status == 302 and "/login.php" in location,
    )

    # ------------------------------------------------------------------
    # T02 — POST sans jeton CSRF
    # ------------------------------------------------------------------
    status, _, body = anonymous.post(
        "/login.php", {"email": "admin@test.com", "password": "peu-importe"}
    )
    record(
        "T02",
        "Connexion POST sans jeton CSRF",
        "403 avec message CSRF",
        f"HTTP {status}, message={'Jeton CSRF' in body.decode('utf-8', 'replace')}",
        status == 403 and "Jeton CSRF" in body.decode("utf-8", "replace"),
    )

    # ------------------------------------------------------------------
    # T03 — mauvais mot de passe + journal LOGIN_FAILED
    # ------------------------------------------------------------------
    bad = Client(base)
    ok, body = login(bad, "admin@test.com", "mauvais-mot-de-passe")
    text = body.decode("utf-8", "replace")
    record(
        "T03",
        "Connexion avec mauvais mot de passe",
        "refus + message générique",
        f"login={ok}, message={'Email ou mot de passe incorrect' in text}",
        (not ok) and "Email ou mot de passe incorrect" in text,
    )

    # ------------------------------------------------------------------
    # Connexions des 4 rôles
    # ------------------------------------------------------------------
    admin = Client(base)
    medecin = Client(base)
    agent = Client(base)
    auditeur = Client(base)
    logins = {
        "admin": login(admin, "admin@test.com", "Admin#2026!")[0],
        "medecin": login(medecin, "medecin@test.com", "Medecin#2026!")[0],
        "agent": login(agent, "agent@test.com", "Agent#2026!")[0],
        "auditeur": login(auditeur, "auditeur@test.com", "Auditeur#2026!")[0],
    }
    record(
        "T04",
        "Connexion des quatre comptes de démonstration",
        "4 sessions ouvertes",
        ", ".join(f"{role}={ok}" for role, ok in logins.items()),
        all(logins.values()),
    )

    # ------------------------------------------------------------------
    # T05 — attributs du cookie de session
    # ------------------------------------------------------------------
    probe = Client(base)
    probe.get("/login.php")
    cookie_header = ""
    for cookie in probe.jar:
        cookie_header += f"{cookie.name};"
    set_cookie = probe.last_headers.get("Set-Cookie", "")
    record(
        "T05",
        "Attributs du cookie de session",
        "HttpOnly + SameSite",
        f"Set-Cookie: {set_cookie[:160]}",
        "httponly" in set_cookie.lower() and "samesite" in set_cookie.lower(),
    )

    # ------------------------------------------------------------------
    # T06 — en-têtes de sécurité
    # ------------------------------------------------------------------
    status, headers, _ = admin.get("/dashboard.php")
    csp = headers.get("Content-Security-Policy", "")
    record(
        "T06",
        "En-têtes de sécurité HTTP",
        "CSP + X-Frame-Options + nosniff",
        f"CSP={'default-src' in csp}, XFO={headers.get('X-Frame-Options')}, "
        f"nosniff={headers.get('X-Content-Type-Options')}",
        "default-src" in csp
        and headers.get("X-Frame-Options") == "DENY"
        and headers.get("X-Content-Type-Options") == "nosniff",
    )

    # ------------------------------------------------------------------
    # T07 — document de référence
    # ------------------------------------------------------------------
    status, _, dashboard = admin.get("/dashboard.php")
    document_id = first_document_id(dashboard)
    if document_id is None:
        print("\nAucun document en base : lancez php scripts/seed_demo.php puis relancez les tests.")
        return 2
    info = php_json(args.php, "document_info.php", str(document_id))
    record(
        "T07",
        "Métadonnées complètes du document de démonstration",
        "fichier .enc présent + nonce 24 hex + tag 32 hex + sha256 64 hex",
        f"id={document_id}, fichier={info['fichier_present']}, "
        f"nonce={len(info['nonce_hex'])} car., tag={len(info['tag_hex'])} car.",
        bool(info["fichier_present"])
        and len(info["nonce_hex"]) == 24
        and len(info["tag_hex"]) == 32
        and len(info["sha256"]) == 64,
    )

    # ------------------------------------------------------------------
    # T08 — agent refuse en consultation + audit ACCESS_DENIED
    # ------------------------------------------------------------------
    status, _, body = agent.get(f"/view.php?id={document_id}")
    text = body.decode("utf-8", "replace")
    record(
        "T08",
        "Rôle agent : consultation directe d'un document",
        "403 Accès refusé",
        f"HTTP {status}, message={'Accès refusé' in text}",
        status == 403 and "Accès refusé" in text,
    )
    found, detail = audit_check(admin, "ACCESS_DENIED", "agent@test.com", document_id)
    record(
        "T08b",
        "Journalisation du refus RBAC de l'agent",
        "ACCESS_DENIED avec agent@test.com + motif RBAC",
        detail,
        found and "RBAC" in admin.get("/audit.php?action=ACCESS_DENIED")[2].decode("utf-8", "replace"),
    )

    # ------------------------------------------------------------------
    # T09 — auditeur : journal autorisé, documents interdits
    # ------------------------------------------------------------------
    status_audit, _, audit_body = auditeur.get("/audit.php")
    status_view, _, _ = auditeur.get(f"/view.php?id={document_id}")
    status_upload, _, _ = auditeur.get("/upload.php")
    record(
        "T09",
        "Rôle auditeur : audit.read oui, document.view/upload non",
        "audit=200, view=403, upload=403",
        f"audit={status_audit}, view={status_view}, upload={status_upload}",
        status_audit == 200 and status_view == 403 and status_upload == 403,
    )

    # ------------------------------------------------------------------
    # T10 — consultation médecin + audit VIEW
    # ------------------------------------------------------------------
    status, headers, body = medecin.get(f"/view.php?id={document_id}")
    record(
        "T10",
        "Rôle médecin : consultation d'un PDF déchiffré",
        "200 application/pdf + contenu %PDF",
        f"HTTP {status}, Content-Type={headers.get('Content-Type')}, PDF={body[:4] == b'%PDF'}",
        status == 200 and "application/pdf" in headers.get("Content-Type", "") and body[:4] == b"%PDF",
    )
    found, detail = audit_check(admin, "VIEW", "medecin@test.com", document_id)
    record("T10b", "Journalisation de VIEW", "événement VIEW pour le médecin", detail, found)

    # ------------------------------------------------------------------
    # T11 — téléchargement + audit DOWNLOAD
    # ------------------------------------------------------------------
    status, headers, body = medecin.get(f"/download.php?id={document_id}")
    disposition = headers.get("Content-Disposition", "")
    record(
        "T11",
        "Rôle médecin : téléchargement en pièce jointe",
        "200 + Content-Disposition attachment",
        f"HTTP {status}, disposition={disposition[:60]}",
        status == 200 and "attachment" in disposition.lower() and body[:4] == b"%PDF",
    )
    found, detail = audit_check(admin, "DOWNLOAD", "medecin@test.com", document_id)
    record("T11b", "Journalisation de DOWNLOAD", "événement DOWNLOAD pour le médecin", detail, found)

    # ------------------------------------------------------------------
    # T12 — altération d'un octet du .enc -> refus + audit
    # ------------------------------------------------------------------
    encrypted_path = Path(info["chemin_absolu"] or "")
    if not encrypted_path.is_file():
        record("T12", "Altération du fichier chiffré", "fichier .enc disponible", "fichier absent", False)
    else:
        original = encrypted_path.read_bytes()
        try:
            tampered = bytearray(original)
            tampered[-20] ^= 0x01  # dans le ciphertext, avant le tag
            encrypted_path.write_bytes(bytes(tampered))
            status, _, body = medecin.get(f"/download.php?id={document_id}")
            text = body.decode("utf-8", "replace")
            record(
                "T12",
                "Altération d'un octet du fichier .enc",
                "409 Intégrité compromise, aucun clair",
                f"HTTP {status}, message={'Intégrité compromise' in text}",
                status == 409 and "Intégrité compromise" in text,
            )
            found, detail = audit_check(
                admin, "ACCESS_DENIED", "INTEGRITY_CHECK_FAILED", document_id
            )
            record(
                "T12b",
                "Journalisation de l'échec d'intégrité",
                "ACCESS_DENIED motif INTEGRITY_CHECK_FAILED",
                detail,
                found,
            )
        finally:
            encrypted_path.write_bytes(original)

        status, _, body = medecin.get(f"/view.php?id={document_id}")
        record(
            "T12c",
            "Retour à la normale après restauration du .enc",
            "200 + PDF",
            f"HTTP {status}, PDF={body[:4] == b'%PDF'}",
            status == 200 and body[:4] == b"%PDF",
        )

    # ------------------------------------------------------------------
    # T13 — journal LOGIN_FAILED visible pour l'auditeur
    # ------------------------------------------------------------------
    found, detail = audit_check(auditeur, "LOGIN_FAILED", "admin@test.com")
    record(
        "T13",
        "Traçabilité des échecs de connexion",
        "LOGIN_FAILED avec l'adresse tentée",
        detail,
        found,
    )

    # ------------------------------------------------------------------
    # T14 — NO_SESSION et CSRF journalisés
    # ------------------------------------------------------------------
    found_no_session, detail_ns = audit_check(auditeur, "ACCESS_DENIED", "NO_SESSION")
    found_csrf, detail_csrf = audit_check(auditeur, "ACCESS_DENIED", "CSRF_TOKEN_INVALID")
    record(
        "T14",
        "Journalisation des accès sans session et des CSRF invalides",
        "NO_SESSION et CSRF_TOKEN_INVALID présents",
        f"NO_SESSION={found_no_session}, CSRF={found_csrf}",
        found_no_session and found_csrf,
    )

    # ------------------------------------------------------------------
    # T15 — cycle upload / consultation / retrait (optionnel)
    # ------------------------------------------------------------------
    if not args.skip_upload:
        with tempfile.TemporaryDirectory(prefix="coffre-http-") as tmp:
            proc = subprocess.run(
                [sys.executable, str(ROOT / "scripts" / "make_demo_pdfs.py"), tmp],
                capture_output=True,
                text=True,
                encoding="utf-8",
            )
            if proc.returncode != 0:
                record("T15", "Cycle upload", "PDF de test généré", proc.stderr.strip(), False)
            else:
                generated = json.loads(proc.stdout)["fichiers"]
                pdf_bytes = Path(generated[0]).read_bytes()
                upload_name = f"test_securite_{int(time.time())}.pdf"

                status, _, page = medecin.get("/upload.php")
                token = csrf_from(page)
                status, headers, _ = medecin.post_multipart(
                    "/upload.php", {"csrf_token": token}, upload_name, pdf_bytes
                )
                record(
                    "T15",
                    "Dépôt d'un PDF par le rôle médecin",
                    "302 vers /dashboard.php",
                    f"HTTP {status}, Location={headers.get('Location')}",
                    status == 302 and "/dashboard.php" in headers.get("Location", ""),
                )

                status, _, dashboard = medecin.get("/dashboard.php")
                dashboard_text = dashboard.decode("utf-8", "replace")
                rows = re.findall(r"<tr>.*?</tr>", dashboard_text, re.S)
                new_id = None
                for row in rows:
                    if upload_name in row:
                        match = re.search(r"/view\.php\?id=(\d+)", row)
                        if match:
                            new_id = int(match.group(1))
                            break
                record(
                    "T15b",
                    "Le document déposé apparaît dans le tableau de bord",
                    "nom présent + identifiant",
                    f"document_id={new_id}",
                    new_id is not None,
                )

                if new_id is not None:
                    status, _, body = medecin.get(f"/view.php?id={new_id}")
                    record(
                        "T15c",
                        "Consultation du document fraîchement chiffré",
                        "200 + PDF",
                        f"HTTP {status}, PDF={body[:4] == b'%PDF'}",
                        status == 200 and body[:4] == b"%PDF",
                    )
                    found, detail = audit_check(admin, "UPLOAD", upload_name, new_id)
                    record("T15d", "Journalisation de l'UPLOAD", "UPLOAD avec le nom du fichier", detail, found)

                    status, _, page = admin.get("/dashboard.php")
                    token = csrf_from(page)
                    status, headers, _ = admin.post(
                        "/delete.php", {"csrf_token": token, "id": str(new_id)}
                    )
                    record(
                        "T15e",
                        "Retrait du document par l'admin",
                        "302 vers /dashboard.php",
                        f"HTTP {status}, Location={headers.get('Location')}",
                        status == 302,
                    )
                    status, _, _ = medecin.get(f"/view.php?id={new_id}")
                    record(
                        "T15f",
                        "Document retiré inaccessible en consultation",
                        "410 Gone",
                        f"HTTP {status}",
                        status == 410,
                    )
                    found, detail = audit_check(admin, "DELETE", "admin@test.com", new_id)
                    record("T15g", "Journalisation du DELETE", "DELETE pour l'admin", detail, found)

    # ------------------------------------------------------------------
    # T16 — triggers append-only
    # ------------------------------------------------------------------
    try:
        guard = php_json(args.php, "test_audit_append_only.php")
        record(
            "T16",
            "Immutabilité de audit_log (UPDATE/DELETE/TRUNCATE)",
            "toutes les mutations rejetées",
            json.dumps(guard, ensure_ascii=False),
            guard.get("update_bloque") and guard.get("delete_bloque") and guard.get("truncate_bloque"),
        )
    except Exception as exc:  # noqa: BLE001
        record("T16", "Immutabilité de audit_log", "triggers actifs", str(exc), False)

    # ------------------------------------------------------------------
    # Résumé
    # ------------------------------------------------------------------
    failures = [row for row in results if not row[4]]
    passed = len(results) - len(failures)
    failed = len(failures)
    if failures:
        print("\nÉchecs :")
        for row in failures:
            print(f"  - {row[0]} : {row[1]} (obtenu : {row[3]})")
    print("\n" + "=" * 72)
    print(f"Résumé : {passed} réussis, {failed} échoués, {len(results)} scénarios.")
    print("=" * 72)
    return 0 if failed == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
