from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from atlas_voice_agent.activation_contract import build_activation_contract
from atlas_voice_agent.contract import AtlasVoiceRuntimeContract

from test_contract import manifest


def write_manifest() -> Path:
    handle = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
    json.dump(manifest(), handle)
    handle.close()

    return Path(handle.name)


def env(path: Path) -> dict[str, str]:
    return {
        "ATLAS_BASE_URL": "http://atlas.test",
        "ATLAS_TOKEN": "atlas-token",
        "LIVEKIT_URL": "http://livekit.test",
        "LIVEKIT_API_KEY": "livekit-key",
        "LIVEKIT_API_SECRET": "livekit-secret",
        "ATLAS_VOICE_BOOTSTRAP": str(path),
    }


class AtlasVoiceActivationContractTest(unittest.TestCase):
    def test_activation_contract_blocks_until_all_startup_gates_are_ready(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = build_activation_contract(contract, env=env(write_manifest()))

        self.assertEqual("atlas.voice_realtime.activation_contract.v1", payload["schema_version"])
        self.assertIn(payload["status"], ["blocked", "ready_to_start_worker"])
        self.assertTrue(payload["kernel_only"])
        self.assertTrue(payload["mobile_first"])
        self.assertIn("atlas:ai:voice preflight --require-sdk --json", payload["required_sequence"])
        self.assertIn("sdk_callback_direct_to_provider", payload["forbidden_shortcuts"])
        self.assertIn("start_worker_before_callback_loop_wired", payload["forbidden_shortcuts"])
        self.assertIn("start_worker_before_production_sdk_loop_wired", payload["forbidden_shortcuts"])
        self.assertFalse(payload["gates"]["settings_loaded"])
        self.assertFalse(payload["gates"]["boundary_created"])
        self.assertFalse(payload["gates"]["callback_loop_wired"])
        self.assertFalse(payload["gates"]["production_sdk_loop_wired"])
        self.assertIn(payload["next_action"], [
            "upgrade_python_runtime_for_livekit_agents_sdk",
            "install_livekit_agents_sdk",
            "load_runtime_settings_and_kernel_boundary",
            "wire_real_sdk_callback_loop",
            "wire_production_sdk_loop",
        ])

    def test_activation_contract_never_allows_mock_kernel(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = build_activation_contract(
            contract,
            env=env(write_manifest()),
            settings_loaded=True,
            boundary_created=True,
            mock_kernel=True,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertFalse(payload["gates"]["real_kernel_required"])
        self.assertIn(payload["next_action"], [
            "upgrade_python_runtime_for_livekit_agents_sdk",
            "install_livekit_agents_sdk",
            "wire_real_sdk_callback_loop",
            "wire_production_sdk_loop",
            "use_real_kernel_not_mock",
        ])

    def test_activation_contract_blocks_probe_only_missing_sdk_without_startup(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        sdk_status = {
            "schema_version": "atlas.voice_realtime.sdk_check.v1",
            "status": "missing_optional_dependency",
            "sdk_imported": False,
            "import_probe_only": True,
            "missing_imports": ["livekit.agents"],
            "package_checks": [{
                "pip": "livekit-agents",
                "import": "livekit.agents",
                "installed": False,
                "version": None,
            }],
        }
        preflight = {
            "schema_version": "atlas.voice_realtime.runtime_preflight.v1",
            "status": "blocked",
            "sdk_status": sdk_status,
            "next_action": "install_livekit_agents_sdk",
        }
        worker_plan = {
            "schema_version": "atlas.voice_realtime.worker_plan.v1",
            "status": "blocked_missing_sdk",
            "sdk_status": sdk_status,
            "activation": {"can_start_long_running_worker": False},
            "guardrails": {
                "kernel_decides": True,
                "direct_provider_call_allowed": False,
                "direct_tool_execution_allowed": False,
            },
            "next_action": "install_livekit_agents_sdk",
        }

        with patch("atlas_voice_agent.activation_contract.run_runtime_preflight", return_value=preflight), patch(
            "atlas_voice_agent.activation_contract.build_livekit_worker_plan",
            return_value=worker_plan,
        ):
            payload = build_activation_contract(
                contract,
                env=env(write_manifest()),
                settings_loaded=True,
                boundary_created=True,
                callback_loop_wired=True,
                production_sdk_loop_wired=True,
                mock_kernel=False,
            )

        self.assertEqual("blocked", payload["status"])
        self.assertFalse(payload["gates"]["preflight_ready"])
        self.assertFalse(payload["gates"]["sdk_ready"])
        self.assertEqual("install_livekit_agents_sdk", payload["next_action"])
        self.assertFalse(payload["preflight"]["sdk_status"]["sdk_imported"])
        self.assertTrue(payload["preflight"]["sdk_status"]["import_probe_only"])
        self.assertEqual(["livekit.agents"], payload["worker_plan"]["sdk_status"]["missing_imports"])
        self.assertIsNone(payload["worker_plan"]["sdk_status"]["package_checks"][0]["version"])

    def test_activation_contract_blocks_until_production_sdk_loop_is_wired(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        sdk_status = {
            "schema_version": "atlas.voice_realtime.sdk_check.v1",
            "status": "ready",
            "sdk_imported": False,
            "import_probe_only": True,
            "missing_imports": [],
            "package_checks": [],
        }
        preflight = {
            "schema_version": "atlas.voice_realtime.runtime_preflight.v1",
            "status": "ready",
            "sdk_status": sdk_status,
        }
        worker_plan = {
            "schema_version": "atlas.voice_realtime.worker_plan.v1",
            "status": "ready_to_wire_callbacks",
            "sdk_status": sdk_status,
            "activation": {"can_start_long_running_worker": True},
            "guardrails": {
                "kernel_decides": True,
                "direct_provider_call_allowed": False,
                "direct_tool_execution_allowed": False,
            },
        }

        with patch("atlas_voice_agent.activation_contract.run_runtime_preflight", return_value=preflight), patch(
            "atlas_voice_agent.activation_contract.build_livekit_worker_plan",
            return_value=worker_plan,
        ):
            payload = build_activation_contract(
                contract,
                env=env(write_manifest()),
                settings_loaded=True,
                boundary_created=True,
                callback_loop_wired=True,
                production_sdk_loop_wired=False,
                mock_kernel=False,
            )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["callback_loop_wired"])
        self.assertFalse(payload["gates"]["production_sdk_loop_wired"])
        self.assertEqual("wire_production_sdk_loop", payload["next_action"])


if __name__ == "__main__":
    unittest.main()
