from __future__ import annotations

import unittest
from typing import Any, Mapping

from atlas_voice_agent.agent_runtime import AtlasVoiceAgentRuntime
from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.kernel_client import AtlasKernelClient
from atlas_voice_agent.livekit_boundary import LiveKitAgentBoundary
from atlas_voice_agent.livekit_callback_router import LiveKitCallbackRouter
from atlas_voice_agent.livekit_sdk_adapter import LiveKitSdkAdapter
from atlas_voice_agent.livekit_worker import AtlasLiveKitWorker
from atlas_voice_agent.turn_payload import UnsafeVoicePayload

from test_contract import manifest


class RouterTransport:
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
                    "room_name": "atlas-voice-router",
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
                        "receipt_id": "receipt_router_1",
                        "dry_run": True,
                    },
                },
            }

        return {"status": "ok"}


def router(transport: RouterTransport) -> LiveKitCallbackRouter:
    contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
    client = AtlasKernelClient(
        contract=contract,
        atlas_token="token",
        post_json=transport,
    )
    boundary = LiveKitAgentBoundary(AtlasVoiceAgentRuntime(client))
    adapter = LiveKitSdkAdapter(AtlasLiveKitWorker(boundary))

    return LiveKitCallbackRouter(adapter)


class LiveKitCallbackRouterTest(unittest.TestCase):
    def test_routes_supported_callbacks_through_adapter_and_kernel(self) -> None:
        transport = RouterTransport()
        subject = router(transport)

        joined = subject.route({
            "callback_kind": "participant_joined",
            "payload": {
                "session_id": "voice_session",
                "participant_identity": "mobile:vitor",
                "room_name": "atlas-voice-router",
            },
        })
        turn = subject.route({
            "callback_kind": "transcript_final",
            "payload": {
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "transcript": "continue",
            },
        })
        degraded = subject.route({
            "callback_kind": "provider_health_degraded",
            "payload": {
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "provider": "deepgram",
                "reason": "high_latency",
            },
        })
        left = subject.route({
            "callback_kind": "participant_left",
            "payload": {
                "session_id": "voice_session",
            },
        })

        self.assertEqual("session_started", joined.event_kind)
        self.assertEqual("turn_accepted_scaffold", turn.status)
        self.assertEqual("ok", degraded.status)
        self.assertEqual("ok", left.status)
        self.assertEqual("http://atlas.test/ai/voice/session/start", transport.calls[0][0])
        self.assertEqual("http://atlas.test/ai/voice/turn", transport.calls[1][0])
        self.assertEqual("http://atlas.test/ai/voice/provider/health-degraded", transport.calls[2][0])
        self.assertEqual("http://atlas.test/ai/voice/session/end", transport.calls[3][0])

    def test_fails_closed_for_unknown_callback_or_non_object_payload(self) -> None:
        subject = router(RouterTransport())

        with self.assertRaises(UnsafeVoicePayload):
            subject.route({
                "callback_kind": "direct_provider_call",
                "payload": {},
            })

        with self.assertRaises(UnsafeVoicePayload):
            subject.route({
                "callback_kind": "participant_joined",
                "payload": "not-object",
            })

    def test_router_rejects_forbidden_nested_authority_and_raw_payloads(self) -> None:
        subject = router(RouterTransport())

        with self.assertRaises(UnsafeVoicePayload):
            subject.route({
                "callback_kind": "participant_joined",
                "payload": {
                    "session_id": "voice_session",
                    "participant_identity": "mobile:vitor",
                    "room_name": "atlas-voice-router",
                    "metadata": {"provider_api_key": "secret"},
                },
            })

        with self.assertRaises(UnsafeVoicePayload):
            subject.route({
                "callback_kind": "transcript_final",
                "payload": {
                    "session_id": "voice_session",
                    "turn_id": "voice_turn",
                    "transcript": "continue",
                    "metadata": {"raw_audio_bytes": "base64"},
                },
            })

    def test_supported_callbacks_are_stable_for_sdk_loop(self) -> None:
        self.assertEqual([
            "participant_joined",
            "transcript_final",
            "wake_word_detected",
            "tts_synthesized",
            "audio_played",
            "barge_in",
            "runtime_failed",
            "provider_health_degraded",
            "participant_left",
        ], LiveKitCallbackRouter.supported_callbacks())

    def test_router_rejects_tts_callback_before_kernel_accepted_turn(self) -> None:
        transport = RouterTransport()
        subject = router(transport)
        subject.route({
            "callback_kind": "participant_joined",
            "payload": {
                "session_id": "voice_session",
                "participant_identity": "mobile:vitor",
                "room_name": "atlas-voice-router",
            },
        })

        with self.assertRaises(UnsafeVoicePayload):
            subject.route({
                "callback_kind": "tts_synthesized",
                "payload": {
                    "session_id": "voice_session",
                    "turn_id": "unaccepted_turn",
                    "response_text_hash": "990cd70b1bfe9e7b30370699399e3d30281b9f3515533e4629ad2a182c7cdf0a",
                },
            })


if __name__ == "__main__":
    unittest.main()
