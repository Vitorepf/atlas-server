from __future__ import annotations

import unittest

from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.livekit_runtime_entrypoint import start_livekit_agents_worker

from test_contract import manifest


class LiveKitRuntimeEntrypointTest(unittest.TestCase):
    def test_start_worker_fails_closed_without_optional_sdk(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = start_livekit_agents_worker(contract)

        self.assertEqual("atlas.voice_realtime.worker_start.v1", payload["schema_version"])
        self.assertIn(payload["status"], [
            "blocked_missing_sdk",
            "blocked_missing_runtime_settings",
            "blocked_by_activation_gate",
            "blocked_unwired_sdk_callbacks",
            "blocked_unimplemented_start",
        ])
        self.assertFalse(payload["started"])
        self.assertFalse(payload["callback_loop_wired"])
        self.assertFalse(payload["production_sdk_loop_wired"])
        self.assertTrue(payload["kernel_only"])
        self.assertTrue(payload["mobile_first"])
        self.assertFalse(payload["guardrails"]["direct_provider_call_allowed"])
        self.assertFalse(payload["guardrails"]["direct_tool_execution_allowed"])
        self.assertFalse(payload["guardrails"]["raw_audio_persistence_allowed"])
        self.assertFalse(payload["guardrails"]["worker_start_without_production_promotion_allowed"])
        self.assertTrue(payload["production_promotion"]["required"])
        self.assertTrue(payload["production_promotion"]["human_review_required"])
        self.assertTrue(payload["production_promotion"]["decision_receipt_required"])
        self.assertTrue(payload["production_promotion"]["rollback_plan_required"])
        self.assertFalse(payload["production_promotion"]["auto_promotion_allowed"])
        self.assertEqual(
            "atlas.voice_realtime.activation_contract.v1",
            payload["activation_contract"]["schema_version"],
        )
        self.assertEqual(
            "atlas.voice_realtime.production_loop_plan.v1",
            payload["production_loop_plan"]["schema_version"],
        )
        self.assertEqual(
            "atlas.voice_realtime.sdk_wiring_contract.v1",
            payload["sdk_wiring_contract"]["schema_version"],
        )
        self.assertEqual("voice_realtime", payload["activation_contract"]["surface_id"])
        self.assertIn(payload["activation_contract"]["status"], ["blocked", "ready_to_start_worker"])
        self.assertEqual(payload["activation_contract"]["next_action"], payload["activation_next_action"])

    def test_start_worker_never_starts_against_mock_kernel(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = start_livekit_agents_worker(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            mock_kernel=True,
        )

        self.assertFalse(payload["started"])
        self.assertFalse(payload["activation_contract"]["gates"]["real_kernel_required"])
        if payload["worker_plan"]["sdk_status"]["status"] == "ready":
            self.assertEqual("blocked_mock_kernel", payload["status"])

    def test_start_worker_blocks_until_callback_loop_is_wired(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = start_livekit_agents_worker(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            callback_loop_wired=False,
            mock_kernel=False,
        )

        self.assertFalse(payload["started"])
        self.assertFalse(payload["callback_loop_wired"])
        if payload["worker_plan"]["sdk_status"]["status"] == "ready":
            self.assertEqual("blocked_unwired_sdk_callbacks", payload["status"])
            self.assertEqual("wire_real_sdk_callback_loop", payload["activation_next_action"])

    def test_start_worker_blocks_until_production_sdk_loop_is_wired(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = start_livekit_agents_worker(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            callback_loop_wired=True,
            production_sdk_loop_wired=False,
            mock_kernel=False,
        )

        self.assertFalse(payload["started"])
        self.assertTrue(payload["callback_loop_wired"])
        self.assertFalse(payload["production_sdk_loop_wired"])
        self.assertFalse(payload["production_loop_plan"]["guardrails"]["worker_start_allowed_by_this_plan"])
        if payload["worker_plan"]["sdk_status"]["status"] == "ready":
            self.assertEqual("blocked_unwired_production_loop", payload["status"])

    def test_start_worker_exposes_fully_wired_product_loop_without_starting_daemon(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = start_livekit_agents_worker(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            callback_loop_wired=True,
            production_sdk_loop_wired=True,
            mock_kernel=False,
        )

        self.assertFalse(payload["started"])
        self.assertTrue(payload["callback_loop_wired"])
        self.assertTrue(payload["production_sdk_loop_wired"])
        self.assertTrue(payload["activation_contract"]["gates"]["callback_loop_wired"])
        self.assertTrue(payload["production_loop_plan"]["production_sdk_loop_wired"])
        self.assertTrue(payload["production_loop_plan"]["worker_start_callback_loop_wired"])
        self.assertEqual("wired", payload["sdk_wiring_contract"]["status"])
        self.assertFalse(payload["production_promotion"]["auto_promotion_allowed"])
        if payload["worker_plan"]["sdk_status"]["status"] == "ready":
            self.assertEqual("blocked_unimplemented_start", payload["status"])


if __name__ == "__main__":
    unittest.main()
