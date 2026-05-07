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
        self.assertEqual("not_issued_scaffold", response["session_lease"]["token_status"])
        self.assertTrue(response["session_lease"]["kernel_decision_required_per_turn"])
        self.assertFalse(response["session_lease"]["raw_audio_persistence_allowed"])
        self.assertNotIn("access_token", response["session_lease"])

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


if __name__ == "__main__":
    unittest.main()
