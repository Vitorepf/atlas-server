from __future__ import annotations

import hashlib
import unittest

from atlas_voice_agent.callback_payload import (
    AtlasVoiceFailurePayload,
    AtlasVoiceInterruptedPayload,
    AtlasVoicePlayedPayload,
    AtlasVoiceProviderHealthPayload,
    AtlasVoiceSynthesizedPayload,
)
from atlas_voice_agent.turn_payload import UnsafeVoicePayload


class AtlasVoiceCallbackPayloadTest(unittest.TestCase):
    def test_synthesized_hashes_response_text_before_kernel_callback(self) -> None:
        payload = AtlasVoiceSynthesizedPayload.from_runtime_output({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "response_text": "resposta sensivel",
            "tts_provider": "elevenlabs",
            "audio_hash": "audio123",
        }).to_kernel_payload()

        self.assertEqual(hashlib.sha256("resposta sensivel".encode("utf-8")).hexdigest(), payload["response_text_hash"])
        self.assertEqual("audio123", payload["audio_hash"])
        self.assertEqual("elevenlabs", payload["tts_provider"])
        self.assertNotIn("response_text", payload)
        self.assertNotIn("raw_audio", payload)

    def test_synthesized_rejects_raw_audio(self) -> None:
        with self.assertRaises(UnsafeVoicePayload):
            AtlasVoiceSynthesizedPayload.from_runtime_output({
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "audio_bytes": "nope",
                "response_text": "hello",
            })

    def test_synthesized_requires_text_hash_or_audio_hash(self) -> None:
        with self.assertRaises(UnsafeVoicePayload):
            AtlasVoiceSynthesizedPayload.from_runtime_output({
                "session_id": "voice_session",
                "turn_id": "voice_turn",
            })

    def test_played_payload_is_minimal_and_safe(self) -> None:
        payload = AtlasVoicePlayedPayload.from_runtime_output({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "played_duration_ms": 900,
        }).to_kernel_payload()

        self.assertEqual(900, payload["played_duration_ms"])
        self.assertNotIn("audio", payload)

    def test_failure_payload_reports_failure_without_raw_error_body(self) -> None:
        payload = AtlasVoiceFailurePayload.from_runtime_output({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "failure_code": "tts_timeout",
            "error_class": "TimeoutError",
        }).to_kernel_payload()

        self.assertEqual("tts_timeout", payload["failure_code"])
        self.assertEqual("TimeoutError", payload["error_class"])
        self.assertNotIn("raw_response_text", payload)

    def test_interrupted_payload_reports_barge_in_safely(self) -> None:
        payload = AtlasVoiceInterruptedPayload.from_runtime_output({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "reason": "operator_started_speaking",
            "interrupted_stage": "tts_streaming",
            "latency_ms": 90,
        }).to_kernel_payload()

        self.assertEqual("operator_started_speaking", payload["reason"])
        self.assertEqual("tts_streaming", payload["interrupted_stage"])
        self.assertEqual(90, payload["latency_ms"])
        self.assertNotIn("audio_bytes", payload)

    def test_provider_health_payload_requires_provider(self) -> None:
        with self.assertRaises(UnsafeVoicePayload):
            AtlasVoiceProviderHealthPayload.from_runtime_output({
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "reason": "latency_p95_breach",
            })

    def test_provider_health_payload_reports_degradation_safely(self) -> None:
        payload = AtlasVoiceProviderHealthPayload.from_runtime_output({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "provider": "deepgram",
            "reason": "latency_p95_breach",
            "latency_ms": 1800,
        }).to_kernel_payload()

        self.assertEqual("deepgram", payload["provider"])
        self.assertEqual("latency_p95_breach", payload["reason"])
        self.assertEqual(1800, payload["latency_ms"])


if __name__ == "__main__":
    unittest.main()
