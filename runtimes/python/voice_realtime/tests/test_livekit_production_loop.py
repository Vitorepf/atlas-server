from __future__ import annotations

import unittest

from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.livekit_callback_router import LiveKitCallbackRouter
from atlas_voice_agent.livekit_production_loop import build_production_loop_plan

from test_contract import manifest


class LiveKitProductionLoopTest(unittest.TestCase):
    def test_production_loop_plan_is_fail_closed_and_kernel_only(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = build_production_loop_plan(contract)

        self.assertEqual("atlas.voice_realtime.production_loop_plan.v1", payload["schema_version"])
        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["kernel_only"])
        self.assertTrue(payload["mobile_first"])
        self.assertFalse(payload["settings_loaded"])
        self.assertFalse(payload["boundary_created"])
        self.assertFalse(payload["production_sdk_loop_wired"])
        self.assertFalse(payload["worker_start_callback_loop_wired"])
        self.assertIn(payload["next_action"], [
            "upgrade_python_runtime_for_livekit_agents_sdk",
            "install_livekit_agents_sdk",
            "load_runtime_settings",
            "create_real_kernel_boundary",
            "wire_real_livekit_agents_sdk_loop",
        ])
        self.assertIn("route_all_sdk_callbacks_through_LiveKitCallbackRouter", payload["implementation_sequence"])
        self.assertIn("normalize_raw_sdk_objects_through_LiveKitSdkEventBridge", payload["implementation_sequence"])
        self.assertIn("transcript_final", payload["required_loop_hooks"])
        self.assertEqual(LiveKitCallbackRouter.supported_callbacks(), payload["required_loop_hooks"])
        self.assertEqual(
            "atlas.voice_realtime.sdk_event_bridge.v1",
            payload["sdk_event_bridge_contract"]["schema_version"],
        )
        self.assertEqual(
            "atlas.voice_realtime.sdk_wiring_contract.v1",
            payload["sdk_wiring_contract"]["schema_version"],
        )
        self.assertTrue(payload["sdk_wiring_contract"]["complete_callback_coverage"])
        self.assertIn("wiring_invariants", payload["sdk_wiring_contract"])
        self.assertIn(
            "validate_every_sdk_event_through_kernel_normalizer",
            payload["sdk_wiring_contract"]["wiring_invariants"],
        )
        self.assertIn("handler_blueprint", payload["sdk_wiring_contract"]["required_handlers"][0])
        self.assertFalse(payload["guardrails"]["direct_provider_call_allowed"])
        self.assertFalse(payload["guardrails"]["direct_tool_execution_allowed"])
        self.assertFalse(payload["guardrails"]["raw_audio_persistence_allowed"])
        self.assertFalse(payload["guardrails"]["worker_start_allowed_by_this_plan"])

    def test_production_loop_plan_becomes_ready_to_wire_only_after_sdk_settings_and_boundary(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = build_production_loop_plan(
            contract,
            settings_loaded=True,
            boundary_created=True,
        )

        if payload["sdk_status"]["status"] == "ready":
            self.assertEqual("ready_to_wire", payload["status"])
            self.assertEqual("wire_real_livekit_agents_sdk_loop", payload["next_action"])
        else:
            self.assertEqual("blocked", payload["status"])
            self.assertIn(payload["next_action"], [
                "upgrade_python_runtime_for_livekit_agents_sdk",
                "install_livekit_agents_sdk",
            ])


if __name__ == "__main__":
    unittest.main()
