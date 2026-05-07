from __future__ import annotations

import copy
import unittest

from atlas_voice_agent.session_lease import AtlasVoiceSessionLease, UnsafeSessionLease


def response() -> dict:
    return {
        "status": "session_started_scaffold",
        "session_lease": {
            "schema_version": "atlas.voice.session_lease.v1",
            "mode": "mobile_push_to_talk",
            "room_name": "atlas-voice-vitor",
            "participant_identity": "mobile:vitor",
            "runtime_id": "livekit_agents_sdk",
            "transport": "livekit_webrtc",
            "livekit_url": "http://livekit.test",
            "token_status": "issued",
            "token_issuer": "atlas_voice_livekit_token_issuer",
            "expires_at": "2026-05-07T12:15:00Z",
            "ttl_seconds": 900,
            "access_token": "header.payload.signature",
            "kernel_decision_required_per_turn": True,
            "raw_audio_persistence_allowed": False,
        },
    }


class AtlasVoiceSessionLeaseTest(unittest.TestCase):
    def test_accepts_issued_kernel_session_lease_without_logging_token(self) -> None:
        lease = AtlasVoiceSessionLease.from_kernel_response(response())
        log_payload = lease.to_log_payload()

        self.assertEqual("issued", lease.token_status)
        self.assertEqual("header.payload.signature", lease.access_token)
        self.assertTrue(log_payload["access_token_present"])
        self.assertNotIn("access_token", log_payload)

    def test_accepts_scaffold_session_lease_without_token(self) -> None:
        payload = response()
        payload["session_lease"]["token_status"] = "not_issued_scaffold"
        payload["session_lease"].pop("access_token")

        lease = AtlasVoiceSessionLease.from_kernel_response(payload)

        self.assertEqual("not_issued_scaffold", lease.token_status)
        self.assertIsNone(lease.access_token)

    def test_rejects_issued_lease_without_token(self) -> None:
        payload = response()
        payload["session_lease"].pop("access_token")

        with self.assertRaises(UnsafeSessionLease):
            AtlasVoiceSessionLease.from_kernel_response(payload)

    def test_rejects_non_issued_lease_with_token(self) -> None:
        payload = response()
        payload["session_lease"]["token_status"] = "not_issued_scaffold"

        with self.assertRaises(UnsafeSessionLease):
            AtlasVoiceSessionLease.from_kernel_response(payload)

    def test_rejects_lease_that_can_bypass_kernel_or_persist_audio(self) -> None:
        for key, value in [
            ("kernel_decision_required_per_turn", False),
            ("raw_audio_persistence_allowed", True),
        ]:
            payload = copy.deepcopy(response())
            payload["session_lease"][key] = value

            with self.assertRaises(UnsafeSessionLease):
                AtlasVoiceSessionLease.from_kernel_response(payload)


if __name__ == "__main__":
    unittest.main()
