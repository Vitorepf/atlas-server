from __future__ import annotations

import unittest

from atlas_voice_agent.turn_payload import UnsafeVoicePayload
from atlas_voice_agent.wake_word_payload import AtlasVoiceWakeWordPayload


class AtlasVoiceWakeWordPayloadTest(unittest.TestCase):
    def test_builds_safe_wake_word_payload(self) -> None:
        payload = AtlasVoiceWakeWordPayload.from_runtime_event({
            "session_id": "voice_session",
            "wake_word_engine": "swift_local_edge",
            "confidence": 0.91,
            "latency_ms": 42,
        }).to_kernel_payload()

        self.assertEqual("voice_session", payload["session_id"])
        self.assertEqual("swift_local_edge", payload["wake_word_engine"])
        self.assertEqual(0.91, payload["confidence"])
        self.assertEqual(42, payload["latency_ms"])
        self.assertEqual("livekit_webrtc", payload["transport"])
        self.assertNotIn("raw_audio", payload)

    def test_rejects_raw_audio_text_provider_and_tool_authority(self) -> None:
        for key, value in {
            "raw_audio": b"nope",
            "audio_bytes": b"nope",
            "transcript": "hey atlas",
            "response_text": "hello",
            "llm_provider": "claude",
            "tool_call": {"name": "shell"},
            "access_token": "do-not-pass-through",
            "token": "do-not-pass-through",
            "livekit_token": "do-not-pass-through",
            "api_key": "do-not-pass-through",
            "api_secret": "do-not-pass-through",
        }.items():
            with self.subTest(key=key):
                with self.assertRaises(UnsafeVoicePayload):
                    AtlasVoiceWakeWordPayload.from_runtime_event({
                        "session_id": "voice_session",
                        key: value,
                    })

    def test_rejects_invalid_confidence_and_latency(self) -> None:
        with self.assertRaises(UnsafeVoicePayload):
            AtlasVoiceWakeWordPayload.from_runtime_event({
                "session_id": "voice_session",
                "confidence": 1.2,
            })

        with self.assertRaises(UnsafeVoicePayload):
            AtlasVoiceWakeWordPayload.from_runtime_event({
                "session_id": "voice_session",
                "latency_ms": -1,
            })

    def test_rejects_unknown_runtime_surface_transport_or_privacy_class(self) -> None:
        for key, value in {
            "runtime": "direct_provider_voice",
            "client_surface": "browser_extension",
            "transport": "raw_udp",
            "privacy_class": "secretish",
        }.items():
            with self.subTest(key=key):
                with self.assertRaises(UnsafeVoicePayload):
                    AtlasVoiceWakeWordPayload.from_runtime_event({
                        "session_id": "voice_session",
                        key: value,
                    })


if __name__ == "__main__":
    unittest.main()
