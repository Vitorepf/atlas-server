"""PDF document ingestion into code-graph doc nodes (AP-811/812 P-11).

Completes the *document modality* of the code graph: a PDF is read page-by-page
and turned into ``kind:'document'`` nodes so written knowledge (specs, papers,
runbooks shipped as PDF) lands in the same graph as code/markdown/MCP artifacts.

Two layers, kept separate so the transform is testable without the heavy dep:

* ``text_to_doc_nodes(text, path)`` is a PURE function — no I/O, no PDF library.
  It splits already-extracted text into deterministic document nodes. This is the
  unit-testable core and is what ``ingest_pdf`` delegates to per page.
* ``ingest_pdf(path)`` uses ``pypdf`` (venv-only) to read text per page, then
  feeds each page's text through ``text_to_doc_nodes``.

OUT OF SCOPE — image / video / scanned-PDF OCR. Those need an audio/vision stack
(e.g. whisper for video transcripts, an OCR engine for image-only PDFs). This
module does NOT attempt them: a scanned PDF with no extractable text layer simply
yields no nodes for that page rather than guessing. Adding image/video is a
separate slice with its own heavy deps; do not bolt it on here.

Read-model only: this extracts structure for the graph. It never decides
provider/model/domain/policy, never executes anything it reads, and degrades to an
empty result instead of raising on bad input. Heavy-dep capability lives in the
python_ai_data runtime per atlas-ai-runtime-language-boundaries.md and stays
behind the promotion gate (human review) until wired into the Kernel adapter.
"""

from __future__ import annotations

SCHEMA = "atlas.code_graph.pdf.v1"

# Node id namespace. Distinct from doc/heading/scip namespaces in ingest_lite so
# PDF page nodes never collide with those of other artifact kinds.
_NS_PDF = "pdf"

# Cap the label snapshot so a huge page doesn't bloat the graph payload. The full
# page text is preserved in metadata["text"]; the label is a human-readable peek.
_LABEL_MAX = 120


def _clean(value):
    """Return a trimmed non-empty string, or None for anything else."""
    if not isinstance(value, str):
        return None
    trimmed = value.strip()
    return trimmed or None


def _label_for(text: str) -> str:
    """First non-empty line of the page, truncated, as a readable node label."""
    for line in text.splitlines():
        stripped = line.strip()
        if stripped:
            return stripped[:_LABEL_MAX]
    return text.strip()[:_LABEL_MAX]


def text_to_doc_nodes(text, path, page=1):
    """PURE: turn one page's already-extracted text into document node(s).

    Returns a list of nodes shaped ``{id, label, kind:'document', path, page}``
    with the full page text under ``metadata['text']``. Empty/whitespace-only
    text or an unusable path yields ``[]`` (no node), so blank/scanned pages
    don't create empty nodes. ``page`` is 1-based.

    No I/O, no PDF library — deterministic given its inputs, which is what makes
    it independently unit-testable.
    """
    doc_path = _clean(path)
    body = _clean(text)
    if doc_path is None or body is None:
        return []

    try:
        page_no = int(page)
    except (TypeError, ValueError):
        page_no = 1
    if page_no < 1:
        page_no = 1

    node_id = f"{_NS_PDF}:{doc_path}#page-{page_no}"
    return [
        {
            "id": node_id,
            "label": _label_for(body),
            "kind": "document",
            "path": doc_path,
            "page": page_no,
            "metadata": {"ingester": SCHEMA, "text": body},
        }
    ]


def ingest_pdf(path):
    """Read a PDF at ``path`` and emit ``kind:'document'`` nodes, one per page.

    Uses ``pypdf`` (venv-only) to extract the text layer of each page, then runs
    each page's text through :func:`text_to_doc_nodes`. Pages with no extractable
    text (e.g. scanned/image-only — out of scope, see module docstring) are
    silently skipped rather than guessed at.

    Returns ``{schema_version, nodes, edges}`` with ``edges`` always ``[]`` (a
    flat document modality — page-to-page structure is left to a later slice).
    A missing/unreadable/encrypted file degrades to an empty result.
    """
    empty = {"schema_version": SCHEMA, "nodes": [], "edges": []}

    doc_path = _clean(path)
    if doc_path is None:
        return empty

    from pypdf import PdfReader  # venv-only heavy dep

    try:
        reader = PdfReader(doc_path)
        pages = list(reader.pages)
    except Exception:
        return empty

    nodes = []
    for index, page in enumerate(pages, start=1):
        try:
            text = page.extract_text() or ""
        except Exception:
            text = ""
        nodes.extend(text_to_doc_nodes(text, doc_path, page=index))

    nodes.sort(key=lambda n: n["id"])
    return {"schema_version": SCHEMA, "nodes": nodes, "edges": []}
