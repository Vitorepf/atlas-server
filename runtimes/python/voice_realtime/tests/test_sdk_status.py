from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.sdk_status import inspect_livekit_sdk, load_dependency_manifest

from test_contract import manifest


class SdkStatusTest(unittest.TestCase):
    def test_dependency_manifest_declares_probe_policy_and_optional_livekit_package(self) -> None:
        payload = load_dependency_manifest()

        self.assertEqual("atlas.voice_realtime.runtime_dependencies.v1", payload["schema_version"])
        self.assertEqual([], payload["core"]["third_party_dependencies"])
        package = payload["optional_livekit"]["packages"][0]
        self.assertEqual("livekit-agents", package["pip"])
        self.assertEqual("livekit.agents", package["import"])
        self.assertEqual("product_loop_daemon", package["required_for"])
        self.assertIsNone(package["minimum_version"])
        self.assertIn("importlib metadata/spec only", payload["optional_livekit"]["probe_policy"])

    def test_sdk_check_reports_package_checks_without_importing_sdk(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = inspect_livekit_sdk(contract)

        self.assertEqual("atlas.voice_realtime.sdk_check.v1", payload["schema_version"])
        self.assertTrue(payload["kernel_only"])
        self.assertFalse(payload["sdk_imported"])
        self.assertTrue(payload["import_probe_only"])
        self.assertEqual("python_ai_data", payload["runtime_family"])
        self.assertIn(payload["status"], ["ready", "missing_optional_dependency"])
        self.assertIn("livekit.agents", payload["packages"])
        self.assertEqual("atlas.voice_realtime.runtime_dependencies.v1", payload["dependency_manifest"]["schema_version"])
        self.assertEqual("python3 -m pip install livekit-agents", payload["dependency_manifest"]["install_command"])
        self.assertIn("probe_policy", payload["dependency_manifest"])
        self.assertEqual(1, len(payload["package_checks"]))
        self.assertEqual("livekit-agents", payload["package_checks"][0]["pip"])
        self.assertEqual("livekit.agents", payload["package_checks"][0]["import"])
        self.assertEqual("not_pinned_yet", payload["package_checks"][0]["version_policy"])
        if payload["status"] == "missing_optional_dependency":
            self.assertIn("livekit.agents", payload["missing_imports"])
            self.assertEqual("install_livekit_agents_sdk", payload["next_action"])

    def test_load_dependency_manifest_rejects_non_object_payload(self) -> None:
        handle = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
        json.dump(["not", "object"], handle)
        handle.close()

        with self.assertRaises(ValueError):
            load_dependency_manifest(Path(handle.name))


if __name__ == "__main__":
    unittest.main()
