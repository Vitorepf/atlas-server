from __future__ import annotations

import unittest

from atlas_voice_agent.livekit_callback_router import LiveKitCallbackRouter
from atlas_voice_agent.livekit_sdk_event_bridge import LiveKitSdkEventBridge
from atlas_voice_agent.livekit_sdk_wiring_contract import build_livekit_sdk_wiring_contract


class LiveKitSdkWiringContractTest(unittest.TestCase):
    def test_wiring_contract_is_pending_until_real_loop_is_wired(self) -> None:
        payload = build_livekit_sdk_wiring_contract()

        self.assertEqual("atlas.voice_realtime.sdk_wiring_contract.v1", payload["schema_version"])
        self.assertEqual("pending", payload["status"])
        self.assertTrue(payload["kernel_only"])
        self.assertTrue(payload["mobile_first"])
        self.assertFalse(payload["production_sdk_loop_wired"])
        self.assertTrue(payload["complete_callback_coverage"])
        self.assertEqual([], payload["missing_callbacks"])
        self.assertEqual("wire_sdk_handlers_to_event_bridge", payload["next_action"])
        self.assertFalse(payload["guardrails"]["direct_provider_call_allowed"])
        self.assertFalse(payload["guardrails"]["raw_audio_persistence_allowed"])
        self.assertFalse(payload["guardrails"]["sdk_import_required_for_contract"])

    def test_wiring_contract_becomes_wired_only_when_loop_flag_is_true(self) -> None:
        payload = build_livekit_sdk_wiring_contract(production_sdk_loop_wired=True)

        self.assertEqual("wired", payload["status"])
        self.assertTrue(payload["production_sdk_loop_wired"])
        self.assertEqual("run_worker_start_check", payload["next_action"])

    def test_wiring_contract_uses_bridge_and_router_as_sources_of_truth(self) -> None:
        payload = build_livekit_sdk_wiring_contract()
        event_bridge = LiveKitSdkEventBridge.contract()

        self.assertEqual(
            set(LiveKitCallbackRouter.supported_callbacks()),
            {handler["callback_kind"] for handler in payload["required_handlers"]},
        )
        self.assertEqual(event_bridge["forbidden_keys"], payload["forbidden_keys"])
        self.assertIn("LiveKitSdkEventBridge", payload["required_components"]["sdk_event_bridge"])
        self.assertIn("access_token", payload["forbidden_keys"])


if __name__ == "__main__":
    unittest.main()
