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
            rivals_arm="direct_provider_baseline",
        ).to_kernel_payload()

        self.assertEqual("voice_session", payload["session_id"])
        self.assertEqual("voice_turn", payload["turn_id"])
        self.assertEqual("corrija o teste", payload["transcript"])
        self.assertEqual(hashlib.sha256("corrija o teste".encode("utf-8")).hexdigest(), payload["transcript_hash"])
        self.assertEqual("programming", payload["domain_hint"])
        self.assertEqual("direct_provider_baseline", payload["rivals_arm"])
        self.assertNotIn("raw_audio", payload)
        self.assertNotIn("audio_bytes", payload)

    def test_builds_safe_audio_hash_payload_without_raw_audio(self) -> None:
        payload = AtlasVoiceTurnPayload.from_audio_digest(
            session_id="voice_session",
            turn_id="voice_turn",
            audio_digest="6ca13d52ca70c883e0f0bb101e425a89e8624de51db2d2392593af6a84118090",
            audio_duration_ms=1200,
        ).to_kernel_payload()

        self.assertEqual("6ca13d52ca70c883e0f0bb101e425a89e8624de51db2d2392593af6a84118090", payload["audio_hash"])
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

    def test_rejects_token_or_api_secret_from_runtime_input(self) -> None:
        for key in ["access_token", "livekit_token", "api_key", "api_secret"]:
            with self.subTest(key=key):
                with self.assertRaises(UnsafeVoicePayload):
                    AtlasVoiceTurnPayload.from_runtime_input({
                        "session_id": "voice_session",
                        "turn_id": "voice_turn",
                        "transcript": "hello",
                        key: "do-not-pass-through",
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
                "audio_hash": hashlib.sha256("abc123".encode("utf-8")).hexdigest(),
                "audio_duration_ms": -1,
            })

    def test_rejects_non_sha256_audio_hash(self) -> None:
        with self.assertRaises(UnsafeVoicePayload):
            AtlasVoiceTurnPayload.from_runtime_input({
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "audio_hash": "not-a-sha256-hash",
            })

    def test_rejects_unknown_runtime_surface_transport_or_privacy_class(self) -> None:
        for key, value in {
            "runtime": "direct_runtime",
            "client_surface": "unknown",
            "transport": "raw_udp",
            "privacy_class": "privateish",
        }.items():
            with self.subTest(key=key):
                with self.assertRaises(UnsafeVoicePayload):
                    AtlasVoiceTurnPayload.from_runtime_input({
                        "session_id": "voice_session",
                        "turn_id": "voice_turn",
                        "transcript": "hello",
                        key: value,
                    })


if __name__ == "__main__":
    unittest.main()
