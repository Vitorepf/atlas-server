from __future__ import annotations

import unittest

from atlas_voice_agent.mock_kernel import MockKernelTransport


class MockKernelTransportTest(unittest.TestCase):
    def test_mock_kernel_issues_token_free_session_lease(self) -> None:
        transport = MockKernelTransport()

        response = transport.post_json("http://atlas.test/ai/voice/session/start", {
            "session_id": "voice_session",
            "room_name": "atlas-voice-room",
            "participant_identity": "mobile:vitor",
        })

        self.assertEqual("session_started_scaffold", response["status"])
        self.assertEqual("atlas.voice.session_lease.v1", response["session_lease"]["schema_version"])
        self.assertEqual("atlas-voice-room", response["session_lease"]["room_name"])
        self.assertEqual("mobile:vitor", response["session_lease"]["participant_identity"])
        self.assertEqual("not_issued_scaffold", response["session_lease"]["token_status"])
        self.assertTrue(response["session_lease"]["kernel_decision_required_per_turn"])
        self.assertFalse(response["session_lease"]["raw_audio_persistence_allowed"])
        self.assertNotIn("access_token", response["session_lease"])

    def test_mock_kernel_normalizes_unsafe_session_lease_namespaces(self) -> None:
        transport = MockKernelTransport()

        response = transport.post_json("http://atlas.test/ai/voice/session/start", {
            "session_id": "voice_session",
            "room_name": "prod-room",
            "participant_identity": "adminroot",
        })

        self.assertEqual("atlas-voice-prod-room", response["session_lease"]["room_name"])
        self.assertEqual("mobile:adminroot", response["session_lease"]["participant_identity"])

    def test_mock_kernel_turn_returns_dry_run_decision_receipt(self) -> None:
        transport = MockKernelTransport()

        response = transport.post_json("http://atlas.test/ai/voice/turn", {
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "domain_hint": "programming",
            "flow_hint": "programming.dev",
            "transcript": "continue a implementacao",
        })

        receipt = response["turn"]["decision_receipt"]
        self.assertEqual("turn_accepted_scaffold", response["status"])
        self.assertTrue(receipt["dry_run"])
        self.assertEqual("programming", receipt["domain"])
        self.assertEqual("programming.dev", receipt["flow"])
        self.assertTrue(receipt["receipt_id"].startswith("mock_receipt_"))
        self.assertFalse(response["turn"]["provider_execution_enabled"])

    def test_product_loop_check_reflects_wiring_flags_without_starting_daemon(self) -> None:
        transport = MockKernelTransport()

        unwired = transport.get_json("http://atlas.test/ai/voice/runtime/product-loop-check", {
            "runtime": "livekit_agents_sdk",
        })
        wired = transport.get_json("http://atlas.test/ai/voice/runtime/product-loop-check", {
            "runtime": "livekit_agents_sdk",
            "callback_loop_wired": 1,
            "production_sdk_loop_wired": 1,
        })

        self.assertFalse(unwired["gates"]["callback_loop_wired"])
        self.assertFalse(unwired["gates"]["production_sdk_loop_wired"])
        self.assertTrue(wired["gates"]["callback_loop_wired"])
        self.assertTrue(wired["gates"]["production_sdk_loop_wired"])
        self.assertFalse(wired["daemon_started"])

    def test_promotion_review_packet_hash_is_bound_to_wiring_flags(self) -> None:
        transport = MockKernelTransport()

        unwired = transport.get_json("http://atlas.test/ai/voice/runtime/promotion-review-packet", {
            "runtime": "livekit_agents_sdk",
            "hours": 24,
        })
        wired = transport.get_json("http://atlas.test/ai/voice/runtime/promotion-review-packet", {
            "runtime": "livekit_agents_sdk",
            "hours": 24,
            "callback_loop_wired": 1,
            "production_sdk_loop_wired": 1,
        })

        self.assertFalse(unwired["callback_loop_wired"])
        self.assertFalse(unwired["production_sdk_loop_wired"])
        self.assertTrue(wired["callback_loop_wired"])
        self.assertTrue(wired["production_sdk_loop_wired"])
        self.assertNotEqual(unwired["bundle_hash"], wired["bundle_hash"])
        self.assertFalse(wired["daemon_started"])


if __name__ == "__main__":
    unittest.main()
