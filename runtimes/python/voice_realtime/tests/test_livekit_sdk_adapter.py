from __future__ import annotations

import unittest
from typing import Any, Mapping

from atlas_voice_agent.agent_runtime import AtlasVoiceAgentRuntime
from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.kernel_client import AtlasKernelClient
from atlas_voice_agent.livekit_boundary import LiveKitAgentBoundary
from atlas_voice_agent.livekit_sdk_adapter import LiveKitSdkAdapter
from atlas_voice_agent.livekit_worker import AtlasLiveKitWorker
from atlas_voice_agent.turn_payload import UnsafeVoicePayload

from test_contract import manifest


class AdapterTransport:
    def __init__(self) -> None:
        self.calls: list[tuple[str, Mapping[str, Any]]] = []

    def __call__(self, url: str, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        self.calls.append((url, dict(payload)))

        if url.endswith("/session/start"):
            return {
                "status": "session_started_scaffold",
                "session_lease": {
                    "schema_version": "atlas.voice.session_lease.v1",
                    "mode": "mobile_push_to_talk",
                    "room_name": "atlas-voice-adapter",
                    "participant_identity": "mobile:vitor",
                    "runtime_id": "livekit_agents_sdk",
                    "transport": "livekit_webrtc",
                    "token_status": "not_issued_scaffold",
                    "token_issuer": "livekit_pending",
                    "expires_at": "2026-05-07T12:00:00.000000Z",
                    "kernel_decision_required_per_turn": True,
                    "raw_audio_persistence_allowed": False,
                },
            }

        if url.endswith("/turn"):
            return {
                "status": "turn_accepted_scaffold",
                "turn": {
                    "decision_receipt": {
                        "receipt_id": "receipt_adapter_1",
                        "dry_run": True,
                    },
                },
            }

        return {"status": "ok", "echo_url": url}


def adapter(transport: AdapterTransport) -> LiveKitSdkAdapter:
    contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
    client = AtlasKernelClient(
        contract=contract,
        atlas_token="token",
        post_json=transport,
    )
    boundary = LiveKitAgentBoundary(AtlasVoiceAgentRuntime(client))

    return LiveKitSdkAdapter(AtlasLiveKitWorker(boundary))


class LiveKitSdkAdapterTest(unittest.TestCase):
    def test_translates_sdk_lifecycle_callbacks_into_kernel_worker_events(self) -> None:
        transport = AdapterTransport()
        subject = adapter(transport)

        joined = subject.on_participant_joined({
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
            "room_name": "atlas-voice-adapter",
        })
        turn = subject.on_transcript_final({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "transcript": "continue",
            "domain_hint": "programming",
        })
        wake = subject.on_wake_word_detected({
            "session_id": "voice_session",
            "wake_word_engine": "mobile_local",
            "confidence": 0.92,
            "latency_ms": 80,
        })
        synthesized = subject.on_tts_synthesized({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "response_text_hash": "990cd70b1bfe9e7b30370699399e3d30281b9f3515533e4629ad2a182c7cdf0a",
            "audio_hash": "3485e85a70c3b7a9ca69e3e4c46c66e18fc02aa038e10e1323b64fa857bca81f",
            "tts_provider": "local",
        })
        played = subject.on_audio_played({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "played_duration_ms": 500,
        })
        left = subject.on_participant_left({
            "session_id": "voice_session",
        })

        self.assertEqual("session_started", joined.event_kind)
        self.assertEqual("turn_accepted_scaffold", turn.status)
        self.assertEqual("ok", wake.status)
        self.assertEqual("ok", synthesized.status)
        self.assertEqual("ok", played.status)
        self.assertEqual("ok", left.status)
        self.assertEqual("http://atlas.test/ai/voice/session/start", transport.calls[0][0])
        self.assertEqual("http://atlas.test/ai/voice/turn", transport.calls[1][0])
        self.assertEqual("http://atlas.test/ai/voice/wake-word", transport.calls[2][0])
        self.assertEqual("http://atlas.test/ai/voice/turn/synthesized", transport.calls[3][0])
        self.assertEqual("http://atlas.test/ai/voice/turn/played", transport.calls[4][0])
        self.assertEqual("http://atlas.test/ai/voice/session/end", transport.calls[5][0])
        self.assertFalse(joined.log_payload()["payload"]["session_lease"]["access_token_present"])
        self.assertNotIn("header.payload.signature", str([joined.log_payload(), turn.log_payload()]))

    def test_translates_barge_in_and_runtime_failure(self) -> None:
        transport = AdapterTransport()
        subject = adapter(transport)
        subject.on_participant_joined({
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
            "room_name": "atlas-voice-adapter",
        })
        subject.on_transcript_final({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "transcript": "continue",
        })

        interrupted = subject.on_barge_in({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
        })
        failed = subject.on_runtime_failed({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "failure_code": "tts_timeout",
        })
        degraded = subject.on_provider_health_degraded({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "provider": "deepgram",
            "reason": "high_latency",
            "latency_ms": 1200,
        })

        self.assertEqual("ok", interrupted.status)
        self.assertEqual("ok", failed.status)
        self.assertEqual("ok", degraded.status)
        self.assertEqual("http://atlas.test/ai/voice/turn/interrupted", transport.calls[2][0])
        self.assertEqual("http://atlas.test/ai/voice/runtime/failed", transport.calls[3][0])
        self.assertEqual("http://atlas.test/ai/voice/provider/health-degraded", transport.calls[4][0])

    def test_rejects_missing_required_sdk_fields(self) -> None:
        with self.assertRaises(UnsafeVoicePayload):
            adapter(AdapterTransport()).on_participant_joined({
                "session_id": "voice_session",
            })

        transport = AdapterTransport()
        subject = adapter(transport)
        subject.on_participant_joined({
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
            "room_name": "atlas-voice-adapter",
        })

        with self.assertRaises(UnsafeVoicePayload):
            subject.on_provider_health_degraded({
                "session_id": "voice_session",
                "provider": "deepgram",
            })

    def test_rejects_forbidden_sdk_callback_fields_even_when_other_fields_are_valid(self) -> None:
        transport = AdapterTransport()
        subject = adapter(transport)

        with self.assertRaises(UnsafeVoicePayload):
            subject.on_participant_joined({
                "session_id": "voice_session",
                "participant_identity": "mobile:vitor",
                "room_name": "atlas-voice-adapter",
                "access_token": "header.payload.signature",
            })

        subject.on_participant_joined({
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
            "room_name": "atlas-voice-adapter",
        })

        with self.assertRaises(UnsafeVoicePayload):
            subject.on_tts_synthesized({
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "response_text": "raw text must be hashed before callback",
            })

        with self.assertRaises(UnsafeVoicePayload):
            subject.on_transcript_final({
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "transcript": "continue",
                "tool_call": {"name": "shell"},
            })

        with self.assertRaises(UnsafeVoicePayload):
            subject.on_participant_joined({
                "session_id": "voice_session_2",
                "participant_identity": "mobile:vitor",
                "room_name": "atlas-voice-adapter",
                "metadata": {"livekit_token": "nested-token"},
            })


if __name__ == "__main__":
    unittest.main()
