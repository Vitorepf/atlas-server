from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from atlas_programming_intelligence import analyze_manifest


class ProgrammingIntelligenceContractTest(unittest.TestCase):
    def test_analyzes_php_python_and_provider_safe_embeddings(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            workspace = Path(tmp)
            (workspace / "app").mkdir()
            (workspace / "app" / "Service.php").write_text(
                "<?php\nnamespace App;\nuse App\\Foo;\nclass Service { function run() {} }\n",
                encoding="utf-8",
            )
            (workspace / "tool.py").write_text(
                "import json\nclass Worker:\n    def run(self):\n        return json.dumps({})\n",
                encoding="utf-8",
            )

            result = analyze_manifest({
                "schema_version": "atlas.programming.python_runtime.request.v1",
                "workspace": str(workspace),
                "files": ["app/Service.php", "tool.py"],
                "limits": {"max_files": 10, "max_bytes_per_file": 10000},
            })

            self.assertEqual("atlas.programming.python_runtime.analysis.v1", result["schema_version"])
            self.assertEqual("ready", result["status"])
            self.assertFalse(result["runtime"]["provider_calls"])
            self.assertFalse(result["runtime"]["shell_calls"])
            self.assertFalse(result["runtime"]["network_calls"])
            self.assertEqual(2, result["file_count"])
            self.assertRegex(result["receipt_hash"], r"^[a-f0-9]{64}$")
            symbols = [symbol["name"] for file in result["files"] for symbol in file["symbols"]]
            self.assertIn("Service", symbols)
            self.assertIn("Worker", symbols)
            self.assertIn("run", symbols)
            self.assertIn("local_embedding", result["files"][0])

    def test_rejects_forbidden_provider_metadata(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            workspace = Path(tmp)
            (workspace / "a.py").write_text("x = 1\n", encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "forbidden_key"):
                analyze_manifest({
                    "schema_version": "atlas.programming.python_runtime.request.v1",
                    "workspace": str(workspace),
                    "files": ["a.py"],
                    "provider": "openai",
                })

    def test_rejects_outside_workspace_files(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            workspace = Path(tmp)
            result = analyze_manifest({
                "schema_version": "atlas.programming.python_runtime.request.v1",
                "workspace": str(workspace),
                "files": ["../outside.py"],
            })

            self.assertEqual("empty", result["status"])
            self.assertEqual("outside_workspace", result["skipped"][0]["reason"])

    def test_main_entrypoint_returns_json(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            workspace = Path(tmp)
            (workspace / "a.py").write_text("def run():\n    return 1\n", encoding="utf-8")
            manifest = workspace / "manifest.json"
            manifest.write_text(json.dumps({
                "schema_version": "atlas.programming.python_runtime.request.v1",
                "workspace": str(workspace),
                "files": ["a.py"],
            }), encoding="utf-8")

            runtime_root = Path(__file__).resolve().parents[1]
            process = subprocess.run(
                [sys.executable, str(runtime_root / "main.py"), str(manifest)],
                cwd=str(runtime_root),
                check=False,
                capture_output=True,
                text=True,
            )

            self.assertEqual(0, process.returncode, process.stderr)
            payload = json.loads(process.stdout)
            self.assertTrue(payload["ok"])
            self.assertEqual("atlas.programming.python_runtime.analysis.v1", payload["result"]["schema_version"])


if __name__ == "__main__":
    unittest.main()
