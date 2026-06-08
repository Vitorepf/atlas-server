"""Tests for PDF document ingestion (AP-811/812 P-11). Run with the venv python:
    .venv/bin/python tests/test_pdf_ingest.py

text_to_doc_nodes is tested PURELY (no PDF lib). The ingest_pdf path is an
integration test: a tiny TEXT pdf is generated at runtime with fpdf2 (a pdf with
a real extractable text layer — image/video/scanned OCR are out of scope), then
read back through pypdf to prove a document node carrying the text comes out.
"""

from __future__ import annotations

import sys
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.pdf_ingest import (  # noqa: E402
    SCHEMA,
    ingest_pdf,
    text_to_doc_nodes,
)

# --------------------------------------------------------------------------- #
# Pure transform: text_to_doc_nodes
# --------------------------------------------------------------------------- #
def test_pure_builds_document_node() -> None:
    nodes = text_to_doc_nodes("Hello Atlas\nsecond line", "spec.pdf", page=3)
    assert len(nodes) == 1
    node = nodes[0]
    assert node["kind"] == "document"
    assert node["path"] == "spec.pdf"
    assert node["page"] == 3
    assert node["id"] == "pdf:spec.pdf#page-3"
    assert node["label"] == "Hello Atlas"  # first non-empty line
    assert node["metadata"]["text"] == "Hello Atlas\nsecond line"
    assert node["metadata"]["ingester"] == SCHEMA


def test_pure_default_page_is_one() -> None:
    nodes = text_to_doc_nodes("body", "d.pdf")
    assert nodes[0]["page"] == 1
    assert nodes[0]["id"] == "pdf:d.pdf#page-1"


def test_pure_empty_text_yields_no_node() -> None:
    assert text_to_doc_nodes("", "d.pdf") == []
    assert text_to_doc_nodes("   \n\t ", "d.pdf") == []


def test_pure_unusable_path_yields_no_node() -> None:
    assert text_to_doc_nodes("body", "") == []
    assert text_to_doc_nodes("body", None) == []
    assert text_to_doc_nodes("body", 123) == []


def test_pure_bad_page_falls_back_to_one() -> None:
    assert text_to_doc_nodes("body", "d.pdf", page="nope")[0]["page"] == 1
    assert text_to_doc_nodes("body", "d.pdf", page=0)[0]["page"] == 1
    assert text_to_doc_nodes("body", "d.pdf", page=-5)[0]["page"] == 1


def test_pure_label_truncated() -> None:
    long_line = "A" * 500
    node = text_to_doc_nodes(long_line, "d.pdf")[0]
    assert len(node["label"]) == 120
    assert node["metadata"]["text"] == long_line  # full text preserved


def test_pure_deterministic() -> None:
    a = text_to_doc_nodes("Hello Atlas", "d.pdf", page=2)
    b = text_to_doc_nodes("Hello Atlas", "d.pdf", page=2)
    assert a == b


# --------------------------------------------------------------------------- #
# ingest_pdf: missing-file / degrade behaviour (no heavy dep needed)
# --------------------------------------------------------------------------- #
def test_ingest_missing_file_degrades_to_empty() -> None:
    r = ingest_pdf("/nonexistent/path/does-not-exist.pdf")
    assert r == {"schema_version": SCHEMA, "nodes": [], "edges": []}


def test_ingest_unusable_path_degrades_to_empty() -> None:
    assert ingest_pdf(None) == {"schema_version": SCHEMA, "nodes": [], "edges": []}
    assert ingest_pdf("") == {"schema_version": SCHEMA, "nodes": [], "edges": []}


# --------------------------------------------------------------------------- #
# ingest_pdf: real pypdf integration over an fpdf2-generated TEXT pdf
# --------------------------------------------------------------------------- #
def _write_text_pdf(path: str, text: str) -> None:
    from fpdf import FPDF  # venv-only, test-time

    pdf = FPDF()
    pdf.add_page()
    pdf.set_font("Helvetica", size=14)
    pdf.cell(0, 10, text)
    pdf.output(path)


def test_ingest_real_pdf_returns_document_node_with_text() -> None:
    with tempfile.TemporaryDirectory() as tmp:
        pdf_path = str(Path(tmp) / "hello.pdf")
        _write_text_pdf(pdf_path, "Hello Atlas")

        result = ingest_pdf(pdf_path)

        assert result["schema_version"] == SCHEMA
        assert result["edges"] == []
        assert len(result["nodes"]) == 1
        node = result["nodes"][0]
        assert node["kind"] == "document"
        assert node["page"] == 1
        assert node["path"] == pdf_path
        assert node["id"] == f"pdf:{pdf_path}#page-1"
        # The extracted text layer carries our string through pypdf.
        assert "Hello Atlas" in node["metadata"]["text"]
        assert "Hello Atlas" in node["label"]


if __name__ == "__main__":
    failures = 0
    for name, fn in sorted(globals().items()):
        if name.startswith("test_") and callable(fn):
            try:
                fn()
                print(f"PASS {name}")
            except AssertionError as exc:
                failures += 1
                print(f"FAIL {name}: {exc}")
            except Exception as exc:  # noqa: BLE001
                failures += 1
                print(f"ERROR {name}: {exc}")
    print(f"\n{'OK' if failures == 0 else 'FAILED'}: {failures} failure(s)")
    raise SystemExit(1 if failures else 0)
