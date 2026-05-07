from __future__ import annotations

import unittest

from atlas_voice_agent.livekit_callback_loop import inspect_callback_loop_contract


class LiveKitCallbackLoopTest(unittest.TestCase):
    def test_callback_loop_contract_proves_translation_layer_without_starting_daemon(self) -> None:
        payload = inspect_callback_loop_contract()

        self.assertEqual("atlas.voice_realtime.callback_loop_contract.v1", payload["schema_version"])
        self.assertEqual("ready", payload["status"])
        self.assertTrue(payload["translation_layer_ready"])
        self.assertFalse(payload["production_sdk_loop_wired"])
        self.assertFalse(payload["worker_start_callback_loop_wired"])
        self.assertEqual("wire_real_livekit_agents_sdk_loop", payload["next_action"])
        self.assertEqual([], payload["missing_router_callbacks"])
        self.assertEqual([], payload["extra_router_callbacks"])
        self.assertEqual([], payload["missing_adapter_methods"])
        self.assertEqual([], payload["missing_payload_schemas"])
        self.assertEqual([], payload["payload_schema_without_callback"])
        self.assertEqual([], payload["invalid_payload_schemas"])
        self.assertEqual(["session_id", "turn_id", "transcript"], payload["required_callback_payload_schemas"]["transcript_final"]["required"])
        self.assertIn("raw_audio", payload["required_callback_payload_schemas"]["tts_synthesized"]["prohibited"])
        self.assertIn("provider_api_key", payload["required_callback_payload_schemas"]["provider_health_degraded"]["prohibited"])
        self.assertFalse(payload["guardrails"]["direct_provider_call_allowed"])
        self.assertFalse(payload["guardrails"]["direct_tool_execution_allowed"])
        self.assertFalse(payload["guardrails"]["raw_audio_persistence_allowed"])

    def test_callback_loop_contract_allows_worker_start_gate_only_when_production_loop_is_wired(self) -> None:
        payload = inspect_callback_loop_contract(production_sdk_loop_wired=True)

        self.assertTrue(payload["translation_layer_ready"])
        self.assertTrue(payload["production_sdk_loop_wired"])
        self.assertTrue(payload["worker_start_callback_loop_wired"])
        self.assertEqual("run_worker_start_check", payload["next_action"])


if __name__ == "__main__":
    unittest.main()
