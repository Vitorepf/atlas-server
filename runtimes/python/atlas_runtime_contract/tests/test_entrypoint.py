from __future__ import annotations

import json
import tempfile
import unittest
from contextlib import redirect_stdout
from io import StringIO
from pathlib import Path

from atlas_runtime_contract import run_json_manifest_entrypoint


class RuntimeEntrypointContractTest(unittest.TestCase):
    def _run(self, argv, runner, *, include_exception_type=True):
        stream = StringIO()
        with redirect_stdout(stream):
            code = run_json_manifest_entrypoint(
                argv,
                runner,
                include_exception_type=include_exception_type,
            )

        return code, json.loads(stream.getvalue())

    def test_requires_manifest_path(self) -> None:
        code, payload = self._run(["main.py"], lambda manifest: manifest)

        self.assertEqual(2, code)
        self.assertEqual({"ok": False, "error": "manifest_path_required"}, payload)

    def test_runs_manifest_and_wraps_success_result(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            manifest = Path(tmp) / "manifest.json"
            manifest.write_text(json.dumps({"operation": "probe"}), encoding="utf-8")

            code, payload = self._run(
                ["main.py", str(manifest)],
                lambda request: {"seen": request["operation"]},
            )

        self.assertEqual(0, code)
        self.assertEqual({"ok": True, "result": {"seen": "probe"}}, payload)

    def test_typed_error_format_matches_existing_data_runtimes(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            manifest = Path(tmp) / "manifest.json"
            manifest.write_text("{}", encoding="utf-8")

            code, payload = self._run(
                ["main.py", str(manifest)],
                lambda _request: (_ for _ in ()).throw(ValueError("bad_manifest")),
            )

        self.assertEqual(1, code)
        self.assertEqual({"ok": False, "error": "ValueError: bad_manifest"}, payload)

    def test_untyped_error_format_matches_legacy_entrypoints(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            manifest = Path(tmp) / "manifest.json"
            manifest.write_text("{}", encoding="utf-8")

            code, payload = self._run(
                ["main.py", str(manifest)],
                lambda _request: (_ for _ in ()).throw(ValueError("bad_manifest")),
                include_exception_type=False,
            )

        self.assertEqual(1, code)
        self.assertEqual({"ok": False, "error": "bad_manifest"}, payload)


if __name__ == "__main__":
    unittest.main()
