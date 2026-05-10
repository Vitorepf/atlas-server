from __future__ import annotations

import unittest

from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.product_loop_check import build_product_loop_check

from test_contract import manifest


class ProductLoopCheckTest(unittest.TestCase):
    def test_product_loop_check_aggregates_wired_gates_without_starting_daemon(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = build_product_loop_check(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            mock_kernel=False,
        )

        self.assertEqual("atlas.voice_realtime.product_loop_check.v1", payload["schema_version"])
        self.assertEqual("voice_realtime", payload["surface_id"])
        self.assertTrue(payload["kernel_only"])
        self.assertTrue(payload["mobile_first"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["callback_loop_wired"])
        self.assertTrue(payload["gates"]["production_sdk_loop_wired"])
        self.assertTrue(payload["gates"]["worker_start_still_blocked"])
        self.assertTrue(payload["gates"]["production_promotion_blocked"])
        self.assertFalse(payload["guardrails"]["direct_provider_call_allowed"])
        self.assertFalse(payload["guardrails"]["raw_audio_persistence_allowed"])
        self.assertFalse(payload["guardrails"]["auto_promotion_allowed"])
        self.assertEqual("atlas.voice_realtime.callback_loop_contract.v1", payload["callback_loop"]["schema_version"])
        self.assertEqual("atlas.voice_realtime.production_loop_plan.v1", payload["production_loop_plan"]["schema_version"])
        self.assertEqual("atlas.voice_realtime.worker_start.v1", payload["worker_start"]["schema_version"])

        if payload["worker_start"]["status"] == "blocked_unimplemented_start":
            self.assertEqual("ready_for_daemon_implementation_review", payload["status"])
            self.assertEqual("submit_daemon_implementation_review", payload["next_action"])
        else:
            self.assertEqual("blocked", payload["status"])

    def test_product_loop_check_never_treats_mock_kernel_as_product_ready(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = build_product_loop_check(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            mock_kernel=True,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertFalse(payload["daemon_started"])
        if payload["worker_start"]["status"] == "blocked_mock_kernel":
            self.assertEqual("use_real_kernel_not_mock", payload["next_action"])


if __name__ == "__main__":
    unittest.main()
