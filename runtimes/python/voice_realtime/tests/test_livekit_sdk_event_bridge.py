from __future__ import annotations

import unittest
from typing import Any, Mapping

from atlas_voice_agent.agent_runtime import AtlasVoiceAgentRuntime
from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.kernel_client import AtlasKernelClient
from atlas_voice_agent.livekit_boundary import LiveKitAgentBoundary
from atlas_voice_agent.livekit_callback_router import LiveKitCallbackRouter
from atlas_voice_agent.livekit_sdk_adapter import LiveKitSdkAdapter
from atlas_voice_agent.livekit_sdk_event_bridge import LiveKitSdkEventBridge
from atlas_voice_agent.livekit_worker import AtlasLiveKitWorker
from atlas_voice_agent.turn_payload import UnsafeVoicePayload

from test_contract import manifest


class BridgeTransport:
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
                    "room_name": "atlas-voice-bridge",
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
                        "receipt_id": "receipt_bridge_1",
                        "dry_run": True,
                    },
                },
            }

        return {"status": "ok"}


def bridge(transport: BridgeTransport) -> LiveKitSdkEventBridge:
    contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
    client = AtlasKernelClient(
        contract=contract,
        atlas_token="token",
        post_json=transport,
    )
    boundary = LiveKitAgentBoundary(AtlasVoiceAgentRuntime(client))
    worker = AtlasLiveKitWorker(boundary)
    adapter = LiveKitSdkAdapter(worker)

    return LiveKitSdkEventBridge(LiveKitCallbackRouter(adapter))


class LiveKitSdkEventBridgeTest(unittest.TestCase):
    def test_normalizes_sdk_events_into_callback_events(self) -> None:
        payload = LiveKitSdkEventBridge.to_callback_event({
            "event_kind": "room_connected",
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
            "room_name": "atlas-voice-bridge",
            "ignored_sdk_object": object(),
        })

        self.assertEqual("participant_joined", payload["callback_kind"])
        self.assertEqual("voice_session", payload["payload"]["session_id"])
        self.assertNotIn("ignored_sdk_object", payload["payload"])

    def test_routes_sdk_events_through_kernel_only_callback_router(self) -> None:
        transport = BridgeTransport()
        subject = bridge(transport)

        joined = subject.route_sdk_event({
            "event_kind": "room_connected",
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
            "room_name": "atlas-voice-bridge",
        })
        turn = subject.route_sdk_event({
            "event_kind": "transcript_final",
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "transcript": "continue",
        })
        played = subject.route_sdk_event({
            "event_kind": "audio_played",
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "played_duration_ms": 320,
        })
        interrupted = subject.route_sdk_event({
            "event_kind": "barge_in",
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "played_duration_ms": 240,
            "latency_ms": 31,
        })

        self.assertEqual("session_started", joined.event_kind)
        self.assertEqual("turn_accepted_scaffold", turn.status)
        self.assertEqual("ok", played.status)
        self.assertEqual("ok", interrupted.status)
        self.assertEqual("http://atlas.test/ai/voice/session/start", transport.calls[0][0])
        self.assertEqual("http://atlas.test/ai/voice/turn", transport.calls[1][0])
        self.assertEqual("http://atlas.test/ai/voice/turn/played", transport.calls[2][0])
        self.assertEqual("http://atlas.test/ai/voice/turn/interrupted", transport.calls[3][0])
        self.assertEqual(240, transport.calls[3][1]["played_duration_ms"])

    def test_bridge_contract_is_stable_and_token_safe(self) -> None:
        payload = LiveKitSdkEventBridge.contract()

        self.assertEqual("atlas.voice_realtime.sdk_event_bridge.v1", payload["schema_version"])
        self.assertEqual("ready", payload["status"])
        self.assertTrue(payload["kernel_only"])
        self.assertIn("room_connected", payload["supported_event_kinds"])
        self.assertIn("participant_joined", payload["supported_callbacks"])
        self.assertFalse(payload["guardrails"]["direct_provider_call_allowed"])
        self.assertFalse(payload["guardrails"]["raw_audio_persistence_allowed"])
        self.assertIn("access_token", payload["forbidden_keys"])
        self.assertIn("direct_provider_call", payload["forbidden_keys"])
        self.assertIn("direct_tool_execution", payload["forbidden_keys"])
        self.assertIn("memory_write", payload["forbidden_keys"])

    def test_rejects_forbidden_or_incomplete_sdk_events(self) -> None:
        with self.assertRaises(UnsafeVoicePayload):
            LiveKitSdkEventBridge.to_callback_event({
                "event_kind": "room_connected",
                "session_id": "voice_session",
                "participant_identity": "mobile:vitor",
                "room_name": "atlas-voice-bridge",
                "access_token": "header.payload.signature",
            })

        with self.assertRaises(UnsafeVoicePayload):
            LiveKitSdkEventBridge.to_callback_event({
                "event_kind": "transcript_final",
                "session_id": "voice_session",
                "turn_id": "voice_turn",
            })

        with self.assertRaises(UnsafeVoicePayload):
            LiveKitSdkEventBridge.to_callback_event({
                "event_kind": "direct_provider_call",
                "session_id": "voice_session",
            })

        with self.assertRaises(UnsafeVoicePayload):
            LiveKitSdkEventBridge.to_callback_event({
                "event_kind": "room_connected",
                "session_id": "voice_session",
                "participant_identity": "mobile:vitor",
                "room_name": "atlas-voice-bridge",
                "metadata": {"raw_audio": "nested-audio"},
            })

        with self.assertRaises(UnsafeVoicePayload):
            LiveKitSdkEventBridge.to_callback_event({
                "event_kind": "transcript_final",
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "transcript": "decide provider here",
                "metadata": {"direct_provider_call": {"provider": "claude"}},
            })


if __name__ == "__main__":
    unittest.main()
