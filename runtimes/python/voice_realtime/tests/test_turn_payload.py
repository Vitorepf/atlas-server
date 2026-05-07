from __future__ import annotations

import hashlib
import unittest

from atlas_voice_agent.turn_payload import AtlasVoiceTurnPayload, UnsafeVoicePayload


class AtlasVoiceTurnPayloadTest(unittest.TestCase):
    def test_builds_safe_transcript_turn_payload(self) -> None:
        payload = AtlasVoiceTurnPayload.from_transcript(
            session_id="voice_session",
            turn_id="voice_turn",
            transcript="corrija o teste",
            domain_hint="programming",
            flow_hint="programming.repair",
        ).to_kernel_payload()

        self.assertEqual("voice_session", payload["session_id"])
        self.assertEqual("voice_turn", payload["turn_id"])
        self.assertEqual("corrija o teste", payload["transcript"])
        self.assertEqual(hashlib.sha256("corrija o teste".encode("utf-8")).hexdigest(), payload["transcript_hash"])
        self.assertEqual("programming", payload["domain_hint"])
        self.assertNotIn("raw_audio", payload)
        self.assertNotIn("audio_bytes", payload)

    def test_builds_safe_audio_hash_payload_without_raw_audio(self) -> None:
        payload = AtlasVoiceTurnPayload.from_audio_digest(
            session_id="voice_session",
            turn_id="voice_turn",
            audio_digest="abc123",
            audio_duration_ms=1200,
        ).to_kernel_payload()

        self.assertEqual("abc123", payload["audio_hash"])
        self.assertEqual(1200, payload["audio_duration_ms"])
        self.assertNotIn("transcript", payload)
        self.assertNotIn("audio", payload)

    def test_rejects_raw_audio_from_runtime_input(self) -> None:
        with self.assertRaises(UnsafeVoicePayload):
            AtlasVoiceTurnPayload.from_runtime_input({
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "raw_audio": "not allowed",
                "transcript": "hello",
            })

    def test_rejects_turn_without_transcript_or_audio_hash(self) -> None:
        with self.assertRaises(UnsafeVoicePayload):
            AtlasVoiceTurnPayload.from_runtime_input({
                "session_id": "voice_session",
                "turn_id": "voice_turn",
            })

    def test_rejects_negative_audio_duration(self) -> None:
        with self.assertRaises(UnsafeVoicePayload):
            AtlasVoiceTurnPayload.from_runtime_input({
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "audio_hash": "abc123",
                "audio_duration_ms": -1,
            })


if __name__ == "__main__":
    unittest.main()
