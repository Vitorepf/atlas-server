from __future__ import annotations

import unittest
from typing import Any, Mapping

from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.kernel_client import AtlasKernelClient
from atlas_voice_agent.turn_payload import UnsafeVoicePayload

from test_contract import manifest


class RecordingTransport:
    def __init__(self) -> None:
        self.calls: list[tuple[str, Mapping[str, Any]]] = []

    def __call__(self, url: str, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        self.calls.append((url, dict(payload)))

        return {"status": "ok", "url": url}


class AtlasKernelClientTest(unittest.TestCase):
    def client(self, transport: RecordingTransport) -> AtlasKernelClient:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())

        return AtlasKernelClient(contract=contract, atlas_token="token", post_json=transport)

    def test_rejects_empty_atlas_token(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())

        with self.assertRaises(ValueError):
            AtlasKernelClient(contract=contract, atlas_token=" ")

    def test_submit_turn_sanitizes_payload_before_transport(self) -> None:
        transport = RecordingTransport()
        self.client(transport).submit_turn({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "transcript": "corrija o teste",
        })

        url, payload = transport.calls[0]
        self.assertEqual("http://atlas.test/ai/voice/turn", url)
        self.assertIn("transcript_hash", payload)
        self.assertNotIn("raw_audio", payload)

    def test_submit_turn_rejects_raw_audio_before_transport(self) -> None:
        transport = RecordingTransport()

        with self.assertRaises(UnsafeVoicePayload):
            self.client(transport).submit_turn({
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "transcript": "hello",
                "raw_audio": "blocked",
            })

        self.assertEqual([], transport.calls)

    def test_report_synthesized_hashes_text_before_transport(self) -> None:
        transport = RecordingTransport()
        self.client(transport).report_synthesized({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "response_text": "resposta sensivel",
        })

        url, payload = transport.calls[0]
        self.assertEqual("http://atlas.test/ai/voice/turn/synthesized", url)
        self.assertIn("response_text_hash", payload)
        self.assertNotIn("response_text", payload)

    def test_reports_interruption_and_provider_health_to_expected_urls(self) -> None:
        transport = RecordingTransport()
        client = self.client(transport)
        client.report_interrupted({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "reason": "operator_started_speaking",
        })
        client.report_provider_health_degraded({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "provider": "deepgram",
            "reason": "latency_p95_breach",
        })

        self.assertEqual("http://atlas.test/ai/voice/turn/interrupted", transport.calls[0][0])
        self.assertEqual("http://atlas.test/ai/voice/provider/health-degraded", transport.calls[1][0])
        self.assertEqual("deepgram", transport.calls[1][1]["provider"])


if __name__ == "__main__":
    unittest.main()
