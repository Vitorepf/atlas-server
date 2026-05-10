from __future__ import annotations

import unittest

from atlas_voice_agent.session_payload import AtlasVoiceSessionPayload
from atlas_voice_agent.turn_payload import UnsafeVoicePayload


class AtlasVoiceSessionPayloadTest(unittest.TestCase):
    def test_builds_safe_session_payload_without_token_or_audio(self) -> None:
        payload = AtlasVoiceSessionPayload.from_runtime_event({
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
            "room_name": "atlas-voice-vitor",
            "rivals_arm": "direct_provider_baseline",
            "reason": "operator_finished",
        }).to_kernel_payload()

        self.assertEqual("voice_session", payload["session_id"])
        self.assertEqual("mobile:vitor", payload["participant_identity"])
        self.assertEqual("atlas-voice-vitor", payload["room_name"])
        self.assertEqual("direct_provider_baseline", payload["rivals_arm"])
        self.assertEqual("operator_finished", payload["reason"])
        self.assertNotIn("token", payload)
        self.assertNotIn("audio_bytes", payload)

    def test_defaults_unknown_rivals_arm_to_atlas_voice(self) -> None:
        payload = AtlasVoiceSessionPayload.from_runtime_event({
            "session_id": "voice_session",
            "rivals_arm": "unknown",
        }).to_kernel_payload()

        self.assertEqual("atlas_voice", payload["rivals_arm"])

    def test_rejects_session_payload_with_livekit_token(self) -> None:
        with self.assertRaises(UnsafeVoicePayload):
            AtlasVoiceSessionPayload.from_runtime_event({
                "session_id": "voice_session",
                "livekit_token": "do-not-pass-through",
            })

    def test_rejects_session_payload_with_access_token_or_api_secret(self) -> None:
        for key in ["access_token", "api_secret"]:
            with self.subTest(key=key):
                with self.assertRaises(UnsafeVoicePayload):
                    AtlasVoiceSessionPayload.from_runtime_event({
                        "session_id": "voice_session",
                        key: "do-not-pass-through",
                    })

    def test_rejects_nested_session_payload_secrets(self) -> None:
        with self.assertRaises(UnsafeVoicePayload):
            AtlasVoiceSessionPayload.from_runtime_event({
                "session_id": "voice_session",
                "metadata": {
                    "sdk": {
                        "api_secret": "nested-secret",
                    },
                },
            })

    def test_rejects_unknown_runtime_surface_transport_or_privacy_class(self) -> None:
        for key, value in {
            "runtime": "python_sidecar_direct",
            "client_surface": "unknown_surface",
            "transport": "raw_socket",
            "privacy_class": "publicish",
        }.items():
            with self.subTest(key=key):
                with self.assertRaises(UnsafeVoicePayload):
                    AtlasVoiceSessionPayload.from_runtime_event({
                        "session_id": "voice_session",
                        key: value,
                    })


if __name__ == "__main__":
    unittest.main()
