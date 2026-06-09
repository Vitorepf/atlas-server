"""Tests for code_graph multimodal ingestion (AP-815 P-12).

Runnable with pytest OR directly: `python3 tests/test_multimodal_ingest.py`
(pytest may be absent in the local runtime, so it self-runs).

The audio model (whisper) is intentionally kept OUT of the test path: we only
assert graceful handling of a missing audio file, which the ingester guards
BEFORE any model download. Image tests create a tiny in-memory PNG via
PIL.Image.new — no external asset, no network.
"""

from __future__ import annotations

import sys
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.multimodal_ingest import (  # noqa: E402
    SCHEMA,
    ingest,
    ingest_audio,
    ingest_image,
)


def _write_tiny_png(directory: str, name: str = "tiny.png", size=(3, 2)) -> str:
    """Create a tiny PNG via PIL.Image.new and return its path."""
    from PIL import Image  # available in venv per task deps

    path = str(Path(directory) / name)
    Image.new("RGB", size, color=(10, 20, 30)).save(path, format="PNG")
    return path


def test_ingest_image_ok_with_dimensions() -> None:
    with tempfile.TemporaryDirectory() as tmp:
        path = _write_tiny_png(tmp, size=(3, 2))
        node = ingest_image(path)

    assert node["ok"] is True, node
    assert node["schema_version"] == SCHEMA
    assert node["kind"] == "image"
    assert node["path"] == path
    assert node["width"] == 3
    assert node["height"] == 2
    assert node["format"] == "PNG"
    assert node["mode"] == "RGB"


def test_ingest_dispatch_image_by_extension() -> None:
    # ingest() must route a .png to the image ingester and succeed.
    with tempfile.TemporaryDirectory() as tmp:
        path = _write_tiny_png(tmp, name="pic.PNG", size=(5, 4))  # mixed-case ext
        node = ingest(path)

    assert node["ok"] is True, node
    assert node["kind"] == "image"
    assert node["width"] == 5
    assert node["height"] == 4


def test_ingest_image_missing_file_is_safe() -> None:
    node = ingest_image("/no/such/file/really_missing.png")
    assert node["ok"] is False
    assert node["kind"] == "image"
    assert "error" in node
    # No exception escaped — reaching this line is the assertion.


def test_ingest_missing_file_via_dispatch_is_safe() -> None:
    node = ingest("/no/such/file/really_missing.png")
    assert node["ok"] is False
    assert "error" in node


def test_ingest_image_invalid_path_is_safe() -> None:
    for bad in (None, "", "   ", 123, ["x"]):
        node = ingest_image(bad)
        assert node["ok"] is False, bad
        assert node["kind"] == "image"


def test_ingest_image_unreadable_file_is_safe() -> None:
    # A .png path that is not a real image must degrade, not raise.
    with tempfile.TemporaryDirectory() as tmp:
        path = str(Path(tmp) / "fake.png")
        Path(path).write_text("this is not a png", encoding="utf-8")
        node = ingest_image(path)
    assert node["ok"] is False
    assert "error" in node


def test_ingest_audio_missing_file_no_model_download() -> None:
    # MUST return fail-safe WITHOUT attempting a whisper model download.
    # The file-existence guard runs before any heavy import; reaching the
    # assertion quickly (no network/model load) proves the guard holds.
    node = ingest_audio("/no/such/file/really_missing.wav")
    assert node["ok"] is False
    assert node["kind"] == "audio"
    assert node["schema_version"] == SCHEMA
    assert "error" in node


def test_ingest_audio_invalid_path_is_safe() -> None:
    for bad in (None, "", "   ", 42):
        node = ingest_audio(bad)
        assert node["ok"] is False, bad
        assert node["kind"] == "audio"


def test_ingest_dispatch_audio_missing_file_is_safe() -> None:
    # Dispatch a .wav for a missing file -> audio ingester -> fail-safe,
    # again without any model download (guarded by existence check).
    node = ingest("/no/such/file/really_missing.mp3")
    assert node["ok"] is False
    assert node["kind"] == "audio"
    assert "error" in node


def test_ingest_unsupported_extension_is_safe() -> None:
    node = ingest("/tmp/whatever.xyz")
    assert node["ok"] is False
    assert node["kind"] == "unknown"
    assert "unsupported" in node["error"]


def test_ingest_no_extension_is_safe() -> None:
    node = ingest("/tmp/README")
    assert node["ok"] is False
    assert node["kind"] == "unknown"


def test_ingest_invalid_path_is_safe() -> None:
    for bad in (None, "", 999):
        node = ingest(bad)
        assert node["ok"] is False, bad


def test_image_result_deterministic() -> None:
    with tempfile.TemporaryDirectory() as tmp:
        path = _write_tiny_png(tmp, size=(7, 6))
        assert ingest_image(path) == ingest_image(path)


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
            except Exception as exc:  # noqa: BLE001 — surface unexpected raises
                failures += 1
                print(f"ERROR {name}: {exc!r}")
    print(f"\n{'OK' if failures == 0 else 'FAILED'}: {failures} failure(s)")
    raise SystemExit(1 if failures else 0)
