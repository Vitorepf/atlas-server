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
        self.assertTrue(payload["complete_handler_registry"])
        self.assertEqual([], payload["missing_callbacks"])
        self.assertEqual([], payload["missing_handler_event_kinds"])
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
        self.assertIn("LiveKitSdkHandlerRegistry", payload["required_components"]["sdk_handler_registry"])
        self.assertIn("access_token", payload["forbidden_keys"])
        self.assertIn("call_LiveKitSdkEventBridge_to_callback_event", payload["wiring_invariants"])
        self.assertIn("route_all_livekit_sdk_handlers_through_registry", payload["wiring_invariants"])
        self.assertIn("never_call_provider_tool_memory_or_policy_from_sdk_handler", payload["wiring_invariants"])
        self.assertEqual("atlas.voice_realtime.sdk_handler_registry.v1", payload["handler_registry_contract"]["schema_version"])
        self.assertIn("handle_transcript_final", payload["handler_registry_contract"]["handler_names"])

        room_connected = next(
            handler for handler in payload["required_handlers"]
            if handler["sdk_event_kind"] == "room_connected"
        )
        self.assertEqual("handle_room_connected", room_connected["handler_blueprint"]["name"])
        self.assertIn("LiveKitCallbackRouter.route", room_connected["handler_blueprint"]["required_path"])
        self.assertIn("provider SDK call", room_connected["handler_blueprint"]["forbidden_path"])


if __name__ == "__main__":
    unittest.main()
