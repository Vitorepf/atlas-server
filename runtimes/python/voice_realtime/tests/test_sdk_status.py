from __future__ import annotations

import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.sdk_status import (
    build_dependency_install_plan,
    inspect_livekit_sdk,
    load_dependency_manifest,
    validate_dependency_install_plan,
)

from test_contract import manifest


class SdkStatusTest(unittest.TestCase):
    def test_dependency_manifest_declares_probe_policy_and_optional_livekit_package(self) -> None:
        payload = load_dependency_manifest()

        self.assertEqual("atlas.voice_realtime.runtime_dependencies.v1", payload["schema_version"])
        self.assertEqual("3.10", payload["python"]["minimum_version"])
        self.assertEqual("ATLAS_VOICE_PYTHON_BIN or config atlas_ai.voice_realtime.python_binary", payload["python"]["binary_config"])
        self.assertEqual([], payload["core"]["third_party_dependencies"])
        package = payload["optional_livekit"]["packages"][0]
        self.assertEqual("livekit-agents", package["pip"])
        self.assertEqual("livekit.agents", package["import"])
        self.assertEqual("product_loop_daemon", package["required_for"])
        self.assertEqual("1.3.12", package["minimum_version"])
        self.assertEqual(">=1.3.12,<2.0.0", package["version_specifier"])
        self.assertEqual(
            "runtimes/python/voice_realtime/requirements-livekit.txt",
            payload["optional_livekit"]["requirements_file"],
        )
        self.assertEqual(
            "${ATLAS_VOICE_PYTHON_BIN:-python3} -m pip install -r runtimes/python/voice_realtime/requirements-livekit.txt",
            payload["optional_livekit"]["install_command"],
        )
        self.assertIn("operator-managed", payload["optional_livekit"]["install_policy"])
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
        self.assertEqual("3.10", payload["python_minimum_version"])
        self.assertIn(payload["python_satisfies_minimum"], [True, False, None])
        self.assertIn("livekit.agents", payload["packages"])
        self.assertEqual("atlas.voice_realtime.runtime_dependencies.v1", payload["dependency_manifest"]["schema_version"])
        self.assertEqual("3.10", payload["dependency_manifest"]["python"]["minimum_version"])
        self.assertEqual(
            "${ATLAS_VOICE_PYTHON_BIN:-python3} -m pip install -r runtimes/python/voice_realtime/requirements-livekit.txt",
            payload["dependency_manifest"]["install_command"],
        )
        self.assertEqual(
            "runtimes/python/voice_realtime/requirements-livekit.txt",
            payload["dependency_manifest"]["requirements_file"],
        )
        self.assertIn("operator-managed", payload["dependency_manifest"]["install_policy"])
        self.assertIn("probe_policy", payload["dependency_manifest"])
        self.assertEqual(1, len(payload["package_checks"]))
        self.assertEqual("livekit-agents", payload["package_checks"][0]["pip"])
        self.assertEqual("livekit.agents", payload["package_checks"][0]["import"])
        self.assertEqual("1.3.12", payload["package_checks"][0]["minimum_version"])
        self.assertEqual(">=1.3.12,<2.0.0", payload["package_checks"][0]["version_specifier"])
        self.assertEqual("version_specifier_required", payload["package_checks"][0]["version_policy"])
        if payload["python_satisfies_minimum"] is False:
            self.assertEqual("upgrade_python_runtime_for_livekit_agents_sdk", payload["next_action"])
        elif payload["status"] == "missing_optional_dependency":
            self.assertIn("livekit.agents", payload["missing_imports"])
            self.assertEqual("install_livekit_agents_sdk", payload["next_action"])

    def test_sdk_check_fails_closed_when_livekit_parent_import_probe_raises(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        sys.modules.pop("livekit", None)
        sys.modules.pop("livekit.agents", None)

        def broken_find_spec(import_name: str):
            if import_name.startswith("livekit"):
                raise ImportError("missing parent package")

            return None

        with patch("atlas_voice_agent.sdk_status.importlib.util.find_spec", side_effect=broken_find_spec):
            payload = inspect_livekit_sdk(contract)

        self.assertEqual("missing_optional_dependency", payload["status"])
        self.assertFalse(payload["sdk_imported"])
        self.assertTrue(payload["import_probe_only"])
        self.assertEqual(["livekit.agents"], payload["missing_imports"])
        self.assertFalse(payload["packages"]["livekit"])
        self.assertFalse(payload["packages"]["livekit.agents"])
        self.assertNotIn("livekit", sys.modules)
        self.assertNotIn("livekit.agents", sys.modules)

    def test_load_dependency_manifest_rejects_non_object_payload(self) -> None:
        handle = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
        json.dump(["not", "object"], handle)
        handle.close()

        with self.assertRaises(ValueError):
            load_dependency_manifest(Path(handle.name))

    def test_dependency_install_plan_is_operator_managed_and_never_runs_pip(self) -> None:
        payload = build_dependency_install_plan()

        self.assertEqual("atlas.voice_realtime.dependency_install_plan.v1", payload["schema_version"])
        self.assertEqual("ready_to_install_optional_dependency", payload["status"])
        self.assertTrue(payload["operator_managed"])
        self.assertFalse(payload["pip_execution_attempted"])
        self.assertFalse(payload["sdk_imported"])
        self.assertFalse(payload["daemon_started"])
        self.assertEqual(
            "runtimes/python/voice_realtime/requirements-livekit.txt",
            payload["requirements_file"],
        )
        self.assertIsInstance(payload["requirements_sha256"], str)
        self.assertEqual(["livekit-agents"], payload["expected_packages"])
        self.assertEqual(["livekit-agents>=1.3.12,<2.0.0"], payload["requirements_packages"])
        self.assertEqual([], payload["missing_requirements"])
        self.assertEqual([], payload["unsafe_requirements"])
        self.assertTrue(payload["gates"]["requirements_file_exists"])
        self.assertTrue(payload["gates"]["requirements_match_manifest"])
        self.assertTrue(payload["gates"]["pip_not_executed"])
        self.assertIn("run_pip_from_sdk_check", payload["forbidden_shortcuts"])
        self.assertEqual("run_install_command_then_sdk_check", payload["next_action"])

        validated = validate_dependency_install_plan(payload)
        self.assertEqual("atlas.voice_realtime.dependency_install_plan.v1", validated["schema_version"])

    def test_dependency_install_plan_validation_rejects_pip_execution(self) -> None:
        payload = dict(build_dependency_install_plan())
        payload["pip_execution_attempted"] = True

        validated = validate_dependency_install_plan(payload)

        self.assertEqual("invalid", validated["status"])
        self.assertIn("pip_execution_must_be_false", validated["errors"])
        self.assertFalse(validated["trusted"])

    def test_dependency_install_plan_blocks_unsafe_requirements(self) -> None:
        manifest_file = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
        requirements_file = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
        requirements_path = Path(requirements_file.name)
        requirements_file.write("git+https://example.test/livekit-agents.git\n")
        requirements_file.close()
        json.dump({
            "schema_version": "atlas.voice_realtime.runtime_dependencies.v1",
            "optional_livekit": {
                "requirements_file": str(requirements_path),
                "packages": [{
                    "pip": "livekit-agents",
                    "import": "livekit.agents",
                    "required_for": "product_loop_daemon",
                }],
            },
        }, manifest_file)
        manifest_file.close()

        payload = build_dependency_install_plan(Path(manifest_file.name))

        self.assertEqual("blocked", payload["status"])
        self.assertEqual(["livekit-agents"], payload["missing_requirements"])
        self.assertEqual(["git+https://example.test/livekit-agents.git"], payload["unsafe_requirements"])
        self.assertFalse(payload["gates"]["requirements_match_manifest"])
        self.assertFalse(payload["gates"]["requirements_safe"])
        self.assertFalse(payload["pip_execution_attempted"])


if __name__ == "__main__":
    unittest.main()
