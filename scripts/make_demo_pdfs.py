#!/usr/bin/env python3
"""
Génère trois PDF de démonstration valides, sans dépendance externe.

Usage : python scripts/make_demo_pdfs.py <dossier_sortie>
Sortie : JSON {"fichiers": [...]}.

Les documents sont entièrement FICTIFS et portent une mention explicite.
Ils servent uniquement à alimenter le prototype (données de démo).
"""

from __future__ import annotations

import json
import sys
from pathlib import Path


def _escape_pdf_text(value: str) -> str:
    return value.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)")


def build_pdf(title: str, header_lines: list[str], body_lines: list[str]) -> bytes:
    content = (
        "BT\n"
        "/F1 17 Tf\n60 790 Td\n"
        f"({_escape_pdf_text(title)}) Tj\n"
        "/F1 10 Tf\n0 -26 Td\n"
    )
    for line in header_lines:
        content += f"({_escape_pdf_text(line)}) Tj\nT*\n"
    content += "/F1 12 Tf\n0 -18 Td\n15 TL\n"
    for line in body_lines:
        content += f"({_escape_pdf_text(line)}) Tj\nT*\n"
    content += (
        "/F1 9 Tf\n0 -30 Td\n"
        "(Document de demonstration - donnees fictives - prototype de stage)\nTj\n"
        "ET\n"
    )
    stream = content.encode("latin-1", "replace")

    objects = [
        b"<< /Type /Catalog /Pages 2 0 R >>",
        b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] "
        b"/Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>",
        b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica "
        b"/Encoding /WinAnsiEncoding >>",
        b"<< /Length " + str(len(stream)).encode("ascii") + b" >>\nstream\n"
        + stream + b"endstream",
    ]

    output = bytearray(b"%PDF-1.4\n%\xe2\xe3\xcf\xd3\n")
    offsets: list[int] = []
    for index, obj in enumerate(objects, start=1):
        offsets.append(len(output))
        output += f"{index} 0 obj\n".encode("ascii") + obj + b"\nendobj\n"

    xref_offset = len(output)
    output += b"xref\n0 6\n0000000000 65535 f \n"
    for offset in offsets:
        output += f"{offset:010d} 00000 n \n".encode("ascii")
    output += (
        b"trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n"
        + str(xref_offset).encode("ascii")
        + b"\n%%EOF\n"
    )
    return bytes(output)


DOCUMENTS = [
    {
        "name": "certificat_medical.pdf",
        "title": "CERTIFICAT MEDICAL",
        "header": [
            "Cabinet medical de demonstration - 12 rue de la Sante, 75000 Paris",
            "N° de certificat : DEMO-CM-2026-001",
        ],
        "body": [
            "Je soussigne, Dr Camille MARTIN, docteur en medecine,",
            "certifie avoir examine le patient :",
            "",
            "    Anouar ESSADIQ, ne le 01/01/1995",
            "",
            "Le patient presente un etat de sante compatible avec une reprise",
            "d'activite a temps plein. Aucune contre-indication medicale n'a ete",
            "identifiee lors de cet examen.",
            "",
            "Fait a Paris, le 10/11/2026",
            "",
            "Signature : Dr C. MARTIN",
        ],
    },
    {
        "name": "attestation_domicile.pdf",
        "title": "ATTESTATION DE DOMICILE",
        "header": [
            "Mairie de demonstration - Service population",
            "Reference : DEMO-ATT-2026-042",
        ],
        "body": [
            "La Mairie atteste que la personne suivante reside sur le territoire",
            "de la commune :",
            "",
            "    Anouar ESSADIQ",
            "    25 avenue des Exemples, 75000 Paris",
            "",
            "Cette attestation est delivree pour servir et valoir ce que de droit.",
            "",
            "Fait a Paris, le 08/11/2026",
            "",
            "Pour la Mairie : Service population",
        ],
    },
    {
        "name": "ordonnance_medicale.pdf",
        "title": "ORDONNANCE MEDICALE",
        "header": [
            "Cabinet medical de demonstration - 12 rue de la Sante, 75000 Paris",
            "N° d'ordonnance : DEMO-ORD-2026-007",
        ],
        "body": [
            "Patient : Anouar ESSADIQ",
            "",
            "Prescription :",
            "  - Traitement A : 1 comprime le matin pendant 7 jours",
            "  - Traitement B : 1 gelule le soir pendant 14 jours",
            "",
            "Recommandations :",
            "  - Repos relatif pendant 48 heures",
            "  - Hydratation reguliere",
            "  - Consultation de controle si persistance des symptomes",
            "",
            "Fait a Paris, le 12/11/2026",
            "",
            "Signature : Dr C. MARTIN",
        ],
    },
]


def main() -> int:
    if len(sys.argv) != 2:
        print(json.dumps({"error": "USAGE", "message": "Usage: make_demo_pdfs.py <dossier>"}), file=sys.stderr)
        return 2

    output_dir = Path(sys.argv[1])
    output_dir.mkdir(parents=True, exist_ok=True)
    files: list[str] = []
    for document in DOCUMENTS:
        path = output_dir / document["name"]
        path.write_bytes(build_pdf(document["title"], document["header"], document["body"]))
        files.append(str(path.resolve()))

    print(json.dumps({"fichiers": files}))
    return 0


if __name__ == "__main__":
    sys.exit(main())
