from __future__ import annotations

import unittest

from atlas_voice_agent.status_packet import (
    VoiceStatusPacketViolation,
    validate_readiness_packet,
    validate_rivals_packet,
)

from test_kernel_client import readiness_payload, rivals_payload


class StatusPacketTest(unittest.TestCase):
    def test_readiness_rejects_nested_secret_or_raw_payload(self) -> None:
        payload = dict(readiness_payload())
        payload["phase0_hardening"] = {
            **payload["phase0_hardening"],
            "debug": {"provider_api_key": "secret"},
        }

        with self.assertRaises(VoiceStatusPacketViolation):
            validate_readiness_packet(payload)

    def test_rivals_rejects_nested_provider_or_audio_payload(self) -> None:
        payload = dict(rivals_payload())
        payload["runtime_certification"] = {
            **payload["runtime_certification"],
            "debug": {"raw_audio_bytes": "base64"},
        }

        with self.assertRaises(VoiceStatusPacketViolation):
            validate_rivals_packet(payload)


if __name__ == "__main__":
    unittest.main()
