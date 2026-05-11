from __future__ import annotations

import unittest
from unittest.mock import patch

from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.worker_plan import build_livekit_worker_plan

from test_contract import manifest


class LiveKitWorkerPlanTest(unittest.TestCase):
    def test_worker_plan_is_kernel_only_and_fail_closed_until_sdk_is_ready(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = build_livekit_worker_plan(contract)

        self.assertEqual("atlas.voice_realtime.worker_plan.v1", payload["schema_version"])
        self.assertIn(payload["status"], ["ready_to_wire_callbacks", "blocked_missing_sdk"])
        self.assertEqual("voice_realtime", payload["surface_id"])
        self.assertTrue(payload["kernel_only"])
        self.assertTrue(payload["mobile_first"])
        self.assertFalse(payload["activation"]["can_start_long_running_worker"])
        self.assertEqual("AtlasLiveKitWorker", payload["entrypoint"]["worker_adapter"])
        self.assertEqual("LiveKitSdkAdapter", payload["entrypoint"]["sdk_adapter"])
        self.assertEqual("LiveKitCallbackRouter", payload["entrypoint"]["callback_router"])
        self.assertTrue(payload["activation"]["requires_kernel_boundary"])
        self.assertTrue(payload["activation"]["requires_callback_loop"])
        self.assertFalse(payload["callback_loop_wired"])
        self.assertEqual(["livekit_agents_sdk"], payload["allowlists"]["runtimes"])
        self.assertEqual(["mobile", "mac_edge"], payload["allowlists"]["client_surfaces"])
        self.assertTrue(payload["guardrails"]["kernel_decides"])
        self.assertFalse(payload["guardrails"]["direct_provider_call_allowed"])
        self.assertFalse(payload["guardrails"]["direct_tool_execution_allowed"])
        self.assertFalse(payload["guardrails"]["raw_audio_persistence_allowed"])
        self.assertIn(payload["next_action"], [
            "upgrade_python_runtime_for_livekit_agents_sdk",
            "install_livekit_agents_sdk",
            "load_runtime_settings_and_kernel_boundary",
            "wire_real_sdk_callback_loop",
        ])

    def test_worker_plan_can_start_only_with_sdk_settings_and_real_kernel(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = build_livekit_worker_plan(
            contract,
            settings_loaded=True,
            boundary_created=True,
            mock_kernel=False,
        )

        self.assertFalse(payload["activation"]["can_start_long_running_worker"])
        if payload["sdk_status"]["status"] == "ready":
            self.assertEqual("wire_real_sdk_callback_loop", payload["next_action"])

    def test_worker_plan_can_start_only_after_callback_loop_is_wired(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = build_livekit_worker_plan(
            contract,
            settings_loaded=True,
            boundary_created=True,
            callback_loop_wired=True,
            mock_kernel=False,
        )

        can_start = payload["sdk_status"]["status"] == "ready"
        self.assertEqual(can_start, payload["activation"]["can_start_long_running_worker"])

    def test_worker_plan_requires_kernel_boundary_even_when_sdk_and_settings_exist(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = build_livekit_worker_plan(
            contract,
            settings_loaded=True,
            boundary_created=False,
            mock_kernel=False,
        )

        self.assertFalse(payload["activation"]["can_start_long_running_worker"])
        self.assertTrue(payload["activation"]["requires_kernel_boundary"])
        self.assertTrue(payload["activation"]["requires_callback_loop"])

    def test_worker_plan_never_starts_against_mock_kernel(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = build_livekit_worker_plan(
            contract,
            settings_loaded=True,
            boundary_created=True,
            mock_kernel=True,
        )

        self.assertFalse(payload["activation"]["can_start_long_running_worker"])

    def test_worker_plan_propagates_sdk_probe_missing_imports_without_daemon_start(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        sdk_status = {
            "schema_version": "atlas.voice_realtime.sdk_check.v1",
            "status": "missing_optional_dependency",
            "sdk_imported": False,
            "import_probe_only": True,
            "package_checks": [{
                "pip": "livekit-agents",
                "import": "livekit.agents",
                "installed": False,
                "version": None,
                "minimum_version": None,
                "version_policy": "not_pinned_yet",
                "required_for": "product_loop_daemon",
            }],
            "missing_imports": ["livekit.agents"],
        }

        with patch("atlas_voice_agent.worker_plan.inspect_livekit_sdk", return_value=sdk_status):
            payload = build_livekit_worker_plan(
                contract,
                settings_loaded=True,
                boundary_created=True,
                callback_loop_wired=True,
                mock_kernel=False,
            )

        self.assertEqual("blocked_missing_sdk", payload["status"])
        self.assertEqual("install_livekit_agents_sdk", payload["next_action"])
        self.assertFalse(payload["activation"]["can_start_long_running_worker"])
        self.assertFalse(payload["sdk_status"]["sdk_imported"])
        self.assertTrue(payload["sdk_status"]["import_probe_only"])
        self.assertEqual(["livekit.agents"], payload["sdk_status"]["missing_imports"])
        self.assertEqual("livekit-agents", payload["sdk_status"]["package_checks"][0]["pip"])
        self.assertIsNone(payload["sdk_status"]["package_checks"][0]["version"])


if __name__ == "__main__":
    unittest.main()
