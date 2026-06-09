"""Multimodal ingestion into code-graph nodes (AP-815 P-12, python_ai_data).

Extends the *document modality* (see pdf_ingest.py P-11) to non-text artifacts so
that images and audio land in the same graph as code / markdown / PDF / MCP
nodes. Two modalities, dispatched by file extension:

* ``ingest_image(path)`` — uses Pillow (``PIL.Image``) to read image metadata
  (dimensions, format, mode). Cheap, deterministic, no model download.
* ``ingest_audio(path, model_size)`` — uses ``openai-whisper`` to transcribe a
  real audio file to text. The whisper model (~tiny) is downloaded on first use,
  so this is the heavy path: it is NEVER touched unless a real, existing audio
  file is handed to it.

Both are the kind of heavy-dep capability that, per
atlas-ai-runtime-language-boundaries.md, belongs in the python_ai_data runtime
(the muscle) rather than the Laravel Kernel. Read-model only: this extracts
structure for the graph — it never decides provider/model/domain/policy, never
executes anything it reads, and stays behind the promotion gate (human review)
until wired into the Kernel adapter.

FAIL-SAFE CONTRACT (matches co_change.py / pdf_ingest.py): every public function
returns a well-formed dict and NEVER raises on bad input. A missing/unreadable
file, an unsupported extension, or an unavailable heavy dep/model degrades to
``{"ok": False, "error": ...}`` rather than throwing. Heavy deps are lazy-imported
INSIDE the functions so importing this module stays cheap.
"""

from __future__ import annotations

import os
from typing import Any, Dict

SCHEMA = "atlas.code_graph.multimodal.v1"

# Extension -> modality routing. Lower-cased, leading-dot keys.
_IMAGE_EXTS = frozenset({".png", ".jpg", ".jpeg", ".gif", ".bmp", ".webp"})
_AUDIO_EXTS = frozenset({".wav", ".mp3", ".m4a", ".flac"})


def _clean_path(path: Any) -> str | None:
    """Return a trimmed non-empty path string, or ``None`` for anything else."""
    if not isinstance(path, str):
        return None
    trimmed = path.strip()
    return trimmed or None


def _ext(path: str) -> str:
    """Lower-cased file extension including the leading dot (``""`` if none)."""
    return os.path.splitext(path)[1].lower()


def ingest_image(path: Any) -> Dict[str, Any]:
    """Read image metadata at ``path`` into a single code-graph image node.

    Uses Pillow (lazy ``from PIL import Image``) to read dimensions/format/mode
    WITHOUT decoding pixel data, so it is cheap and deterministic. No model
    download is ever involved.

    Args:
        path: filesystem path to an image file.

    Returns:
        On success ``{"schema_version", "kind": "image", "path", "width",
        "height", "format", "mode", "ok": True}``. On a missing/unreadable file,
        an unusable path, or a missing Pillow dep, ``{"schema_version", "kind":
        "image", "path", "ok": False, "error": <reason>}``. Never raises.
    """
    doc_path = _clean_path(path)
    base: Dict[str, Any] = {
        "schema_version": SCHEMA,
        "kind": "image",
        "path": doc_path if doc_path is not None else path,
    }

    if doc_path is None:
        return {**base, "ok": False, "error": "invalid or empty path"}

    if not os.path.isfile(doc_path):
        return {**base, "ok": False, "error": "file not found"}

    try:
        from PIL import Image  # venv-only heavy dep, lazy-imported
    except Exception as exc:  # noqa: BLE001 — degrade instead of raising
        return {**base, "ok": False, "error": f"pillow unavailable: {exc!r}"}

    try:
        with Image.open(doc_path) as img:
            width, height = img.size
            fmt = img.format
            mode = img.mode
    except Exception as exc:  # noqa: BLE001 — unreadable/corrupt image
        return {**base, "ok": False, "error": f"unreadable image: {exc!r}"}

    return {
        **base,
        "width": int(width),
        "height": int(height),
        "format": fmt,
        "mode": mode,
        "ok": True,
    }


def ingest_audio(path: Any, model_size: str = "tiny") -> Dict[str, Any]:
    """Transcribe an audio file at ``path`` into a single code-graph audio node.

    Uses ``openai-whisper`` (lazy ``import whisper``) to load a model and
    transcribe. The model (``model_size``, default ``"tiny"``, ~75MB) is
    downloaded on first use — so this function GUARDS the existence of the file
    first and returns early for a missing path, ensuring NO model download is
    triggered for a nonexistent input.

    Args:
        path: filesystem path to an audio file.
        model_size: whisper model name (``"tiny"``/``"base"``/...). Coerced to a
            safe default if not a usable string.

    Returns:
        On success ``{"schema_version", "kind": "audio", "path", "text",
        "language", "ok": True}``. On a missing file, a whisper import/model-load
        failure, or a transcription error, ``{"schema_version", "kind": "audio",
        "path", "ok": False, "error": <reason>}``. Never raises.
    """
    doc_path = _clean_path(path)
    base: Dict[str, Any] = {
        "schema_version": SCHEMA,
        "kind": "audio",
        "path": doc_path if doc_path is not None else path,
    }

    if doc_path is None:
        return {**base, "ok": False, "error": "invalid or empty path"}

    # GUARD: bail before any heavy import / model download for a missing file.
    if not os.path.isfile(doc_path):
        return {**base, "ok": False, "error": "file not found"}

    size = model_size if isinstance(model_size, str) and model_size.strip() else "tiny"

    try:
        import whisper  # venv-only heavy dep, lazy-imported
    except Exception as exc:  # noqa: BLE001 — degrade instead of raising
        return {**base, "ok": False, "error": f"whisper unavailable: {exc!r}"}

    try:
        model = whisper.load_model(size)
    except Exception as exc:  # noqa: BLE001 — model download/load failure
        return {**base, "ok": False, "error": f"model load failed: {exc!r}"}

    try:
        result = model.transcribe(doc_path)
    except Exception as exc:  # noqa: BLE001 — decode/transcription failure
        return {**base, "ok": False, "error": f"transcription failed: {exc!r}"}

    text = result.get("text", "") if isinstance(result, dict) else ""
    language = result.get("language") if isinstance(result, dict) else None

    return {
        **base,
        "text": text.strip() if isinstance(text, str) else "",
        "language": language,
        "ok": True,
    }


def ingest(path: Any) -> Dict[str, Any]:
    """Dispatch ``path`` to the image or audio ingester by file extension.

    Routing (case-insensitive): ``.png/.jpg/.jpeg/.gif/.bmp/.webp`` -> image;
    ``.wav/.mp3/.m4a/.flac`` -> audio. Any other extension yields a fail-safe
    ``{"schema_version", "kind": "unknown", "path", "ok": False, "error":
    "unsupported file type"}``. Never raises.

    Args:
        path: filesystem path to a multimodal artifact.

    Returns:
        The dict produced by the selected ingester, or the unsupported-type
        fail-safe result above.
    """
    doc_path = _clean_path(path)
    if doc_path is None:
        return {
            "schema_version": SCHEMA,
            "kind": "unknown",
            "path": path,
            "ok": False,
            "error": "invalid or empty path",
        }

    ext = _ext(doc_path)
    if ext in _IMAGE_EXTS:
        return ingest_image(doc_path)
    if ext in _AUDIO_EXTS:
        return ingest_audio(doc_path)

    return {
        "schema_version": SCHEMA,
        "kind": "unknown",
        "path": doc_path,
        "ok": False,
        "error": f"unsupported file type: {ext or '(none)'}",
    }
