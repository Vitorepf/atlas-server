from __future__ import annotations

import unittest
from typing import Any, Mapping

from atlas_voice_agent.agent_runtime import AtlasVoiceAgentRuntime
from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.kernel_client import AtlasKernelClient
from atlas_voice_agent.livekit_boundary import LiveKitAgentBoundary
from atlas_voice_agent.livekit_callback_router import LiveKitCallbackRouter
from atlas_voice_agent.livekit_production_loop_runner import LiveKitProductionLoopRunner
from atlas_voice_agent.livekit_sdk_adapter import LiveKitSdkAdapter
from atlas_voice_agent.livekit_sdk_event_bridge import LiveKitSdkEventBridge
from atlas_voice_agent.livekit_worker import AtlasLiveKitWorker
from atlas_voice_agent.turn_payload import UnsafeVoicePayload

from test_contract import manifest


class ProductionLoopTransport:
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
                    "room_name": "atlas-voice-production-loop",
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
                        "receipt_id": "receipt_production_loop_1",
                        "dry_run": True,
                    },
                },
            }

        return {"status": "ok"}


def runner(transport: ProductionLoopTransport) -> LiveKitProductionLoopRunner:
    contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
    client = AtlasKernelClient(
        contract=contract,
        atlas_token="token",
        post_json=transport,
    )
    boundary = LiveKitAgentBoundary(AtlasVoiceAgentRuntime(client))
    worker = AtlasLiveKitWorker(boundary)
    adapter = LiveKitSdkAdapter(worker)
    bridge = LiveKitSdkEventBridge(LiveKitCallbackRouter(adapter))

    return LiveKitProductionLoopRunner(bridge)


class LiveKitProductionLoopRunnerTest(unittest.TestCase):
    def test_runner_executes_sdk_shaped_events_without_daemon_or_sdk_import(self) -> None:
        transport = ProductionLoopTransport()
        payload = runner(transport).run_sdk_events([
            {
                "event_kind": "room_connected",
                "session_id": "voice_session",
                "participant_identity": "mobile:vitor",
                "room_name": "atlas-voice-production-loop",
            },
            {
                "event_kind": "transcript_final",
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "transcript": "continue",
            },
            {
                "event_kind": "audio_played",
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "played_duration_ms": 450,
            },
            {
                "event_kind": "room_disconnected",
                "session_id": "voice_session",
            },
        ])

        self.assertEqual("atlas.voice_realtime.production_loop_smoke.v1", payload["schema_version"])
        self.assertEqual("production_loop_smoke_completed", payload["status"])
        self.assertTrue(payload["kernel_only"])
        self.assertTrue(payload["mobile_first"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["sdk_imported"])
        self.assertEqual(4, payload["event_count"])
        self.assertEqual(4, payload["result_count"])
        self.assertEqual(0, payload["active_session_count"])
        self.assertEqual("atlas.voice_realtime.sdk_event_bridge.v1", payload["bridge_contract"]["schema_version"])
        self.assertFalse(payload["guardrails"]["direct_provider_call_allowed"])
        self.assertFalse(payload["guardrails"]["raw_audio_persistence_allowed"])
        self.assertEqual("http://atlas.test/ai/voice/session/start", transport.calls[0][0])
        self.assertEqual("http://atlas.test/ai/voice/turn", transport.calls[1][0])
        self.assertEqual("http://atlas.test/ai/voice/turn/played", transport.calls[2][0])
        self.assertEqual("http://atlas.test/ai/voice/session/end", transport.calls[3][0])

    def test_runner_rejects_empty_or_unsafe_sdk_event_sequence(self) -> None:
        subject = runner(ProductionLoopTransport())

        with self.assertRaises(ValueError):
            subject.run_sdk_events([])

        with self.assertRaises(UnsafeVoicePayload):
            subject.run_sdk_events([
                {
                    "event_kind": "room_connected",
                    "session_id": "voice_session",
                    "participant_identity": "mobile:vitor",
                    "room_name": "atlas-voice-production-loop",
                    "access_token": "header.payload.signature",
                },
            ])

    def test_runner_requires_sdk_smoke_sequence_to_close_sessions(self) -> None:
        subject = runner(ProductionLoopTransport())

        with self.assertRaises(UnsafeVoicePayload) as context:
            subject.run_sdk_events([
                {
                    "event_kind": "room_connected",
                    "session_id": "voice_session",
                    "participant_identity": "mobile:vitor",
                    "room_name": "atlas-voice-production-loop",
                },
            ])

        self.assertIn("all sessions closed", str(context.exception))


if __name__ == "__main__":
    unittest.main()
