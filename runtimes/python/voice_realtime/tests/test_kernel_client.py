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
        self.response: Mapping[str, Any] = {"status": "ok", "url": ""}

    def __call__(self, url: str, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        self.calls.append((url, dict(payload)))

        if "url" not in self.response:
            return self.response

        return {**self.response, "url": url}


class RecordingGetTransport:
    def __init__(self) -> None:
        self.calls: list[tuple[str, Mapping[str, Any]]] = []

    def __call__(self, url: str, query: Mapping[str, Any]) -> Mapping[str, Any]:
        self.calls.append((url, dict(query)))

        return {"status": "ready", "url": url}


class AtlasKernelClientTest(unittest.TestCase):
    def client(self, transport: RecordingTransport, get_transport: RecordingGetTransport | None = None) -> AtlasKernelClient:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())

        return AtlasKernelClient(contract=contract, atlas_token="token", post_json=transport, get_json=get_transport)

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

    def test_start_and_end_session_use_kernel_session_endpoints(self) -> None:
        transport = RecordingTransport()
        client = self.client(transport)
        client.start_session({
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
            "room_name": "atlas-voice-vitor",
        })
        client.end_session({
            "session_id": "voice_session",
            "reason": "operator_finished",
        })

        self.assertEqual("http://atlas.test/ai/voice/session/start", transport.calls[0][0])
        self.assertEqual("mobile:vitor", transport.calls[0][1]["participant_identity"])
        self.assertNotIn("token", transport.calls[0][1])
        self.assertEqual("http://atlas.test/ai/voice/session/end", transport.calls[1][0])
        self.assertEqual("operator_finished", transport.calls[1][1]["reason"])

    def test_start_session_lease_parses_kernel_lease_without_logging_token(self) -> None:
        transport = RecordingTransport()
        transport.response = {
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
                "access_token": "header.payload.signature",
                "kernel_decision_required_per_turn": True,
                "raw_audio_persistence_allowed": False,
            },
        }

        lease = self.client(transport).start_session_lease({
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
        })

        self.assertEqual("header.payload.signature", lease.access_token)
        self.assertNotIn("access_token", lease.to_log_payload())
        self.assertEqual("http://atlas.test/ai/voice/session/start", transport.calls[0][0])

    def test_readiness_uses_kernel_readiness_endpoint_with_bounded_hours(self) -> None:
        post_transport = RecordingTransport()
        get_transport = RecordingGetTransport()
        response = self.client(post_transport, get_transport).readiness(hours=99999)

        self.assertEqual("ready", response["status"])
        self.assertEqual("http://atlas.test/ai/voice/readiness", get_transport.calls[0][0])
        self.assertEqual(8760, get_transport.calls[0][1]["hours"])
        self.assertEqual([], post_transport.calls)

    def test_rivals_uses_kernel_rivals_endpoint_with_bounded_hours(self) -> None:
        post_transport = RecordingTransport()
        get_transport = RecordingGetTransport()
        response = self.client(post_transport, get_transport).rivals(hours=0)

        self.assertEqual("ready", response["status"])
        self.assertEqual("http://atlas.test/ai/voice/rivals", get_transport.calls[0][0])
        self.assertEqual(1, get_transport.calls[0][1]["hours"])
        self.assertEqual([], post_transport.calls)

    def test_normalize_runtime_event_uses_kernel_normalizer_without_execution(self) -> None:
        transport = RecordingTransport()
        response = self.client(transport).normalize_runtime_event({
            "event_kind": "transcribed_turn",
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "transcript": "continue",
            "ignored_sdk_object": {"safe": True},
        })

        self.assertEqual("ok", response["status"])
        self.assertEqual("http://atlas.test/ai/voice/runtime/events/normalize", transport.calls[0][0])
        self.assertEqual("transcribed_turn", transport.calls[0][1]["event"]["event_kind"])
        self.assertIn("ignored_sdk_object", transport.calls[0][1]["event"])

    def test_normalize_runtime_event_sequence_uses_kernel_sequence_normalizer(self) -> None:
        transport = RecordingTransport()
        response = self.client(transport).normalize_runtime_event_sequence([
            {
                "event_kind": "room_connected",
                "session_id": "voice_session",
                "participant_identity": "mobile:vitor",
                "room_name": "atlas-voice-vitor",
            },
            {
                "event_kind": "room_disconnected",
                "session_id": "voice_session",
            },
        ])

        self.assertEqual("ok", response["status"])
        self.assertEqual("http://atlas.test/ai/voice/runtime/events/normalize-sequence", transport.calls[0][0])
        self.assertEqual(2, len(transport.calls[0][1]["events"]))

    def test_normalize_runtime_event_rejects_raw_audio_and_secrets_before_transport(self) -> None:
        transport = RecordingTransport()
        client = self.client(transport)

        with self.assertRaises(UnsafeVoicePayload):
            client.normalize_runtime_event({
                "event_kind": "transcribed_turn",
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "raw_audio": "blocked",
            })

        with self.assertRaises(UnsafeVoicePayload):
            client.normalize_runtime_event_sequence([
                {
                    "event_kind": "room_connected",
                    "session_id": "voice_session",
                    "participant_identity": "mobile:vitor",
                    "room_name": "atlas-voice-vitor",
                    "metadata": {"provider_api_key": "blocked"},
                },
            ])

        self.assertEqual([], transport.calls)

    def test_normalize_runtime_event_sequence_rejects_empty_sequence(self) -> None:
        transport = RecordingTransport()

        with self.assertRaises(UnsafeVoicePayload):
            self.client(transport).normalize_runtime_event_sequence([])

        self.assertEqual([], transport.calls)

    def test_report_wake_word_sends_safe_payload_before_transport(self) -> None:
        transport = RecordingTransport()
        self.client(transport).report_wake_word({
            "session_id": "voice_session",
            "wake_word_engine": "swift_local_edge",
            "latency_ms": 42,
        })

        url, payload = transport.calls[0]
        self.assertEqual("http://atlas.test/ai/voice/wake-word", url)
        self.assertEqual("swift_local_edge", payload["wake_word_engine"])
        self.assertNotIn("raw_audio", payload)

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
