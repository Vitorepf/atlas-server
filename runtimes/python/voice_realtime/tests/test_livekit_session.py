from __future__ import annotations

import unittest
from typing import Any, Mapping

from atlas_voice_agent.agent_runtime import AtlasVoiceAgentRuntime
from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.kernel_client import AtlasKernelClient
from atlas_voice_agent.livekit_boundary import LiveKitAgentBoundary
from atlas_voice_agent.livekit_session import LiveKitVoiceSession
from atlas_voice_agent.main import start_livekit_voice_session

from test_contract import manifest


class SessionTransport:
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
                    "room_name": "atlas-voice-vitor",
                    "participant_identity": "mobile:vitor",
                    "runtime_id": "livekit_agents_sdk",
                    "transport": "livekit_webrtc",
                    "livekit_url": "http://livekit.test",
                    "token_status": "issued",
                    "token_issuer": "atlas_voice_livekit_token_issuer",
                    "access_token": "header.payload.signature",
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
                        "receipt_id": "receipt_1",
                        "dry_run": True,
                    },
                },
            }

        return {"status": "ok"}


def boundary(transport: SessionTransport) -> LiveKitAgentBoundary:
    contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
    client = AtlasKernelClient(
        contract=contract,
        atlas_token="token",
        post_json=transport,
    )

    return LiveKitAgentBoundary(AtlasVoiceAgentRuntime(client))


class LiveKitVoiceSessionTest(unittest.TestCase):
    def test_starts_kernel_governed_session_without_logging_token(self) -> None:
        transport = SessionTransport()
        session = LiveKitVoiceSession.start(boundary(transport), {
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
            "room_name": "atlas-voice-vitor",
            "client_surface": "mobile",
        })

        self.assertEqual("header.payload.signature", session.access_token)
        self.assertEqual("atlas-voice-vitor", session.lease.room_name)
        self.assertEqual("http://atlas.test/ai/voice/session/start", transport.calls[0][0])
        self.assertNotIn("access_token", session.log_payload()["session_lease"])
        self.assertTrue(session.log_payload()["session_lease"]["access_token_present"])

    def test_main_helper_starts_same_governed_session_handle(self) -> None:
        transport = SessionTransport()
        session = start_livekit_voice_session(boundary(transport), {
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
            "room_name": "atlas-voice-vitor",
        })

        self.assertIsInstance(session, LiveKitVoiceSession)
        self.assertEqual("header.payload.signature", session.access_token)

    def test_session_routes_turn_and_callbacks_through_kernel_boundary(self) -> None:
        transport = SessionTransport()
        session = LiveKitVoiceSession.start(boundary(transport), {
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
            "room_name": "atlas-voice-vitor",
        })

        result = session.submit_transcribed_turn({
            "turn_id": "voice_turn",
            "transcript": "continue",
            "domain_hint": "programming",
        })
        synthesized = session.report_synthesized({
            "turn_id": "voice_turn",
            "response_text_hash": "990cd70b1bfe9e7b30370699399e3d30281b9f3515533e4629ad2a182c7cdf0a",
        })
        played = session.report_played({
            "turn_id": "voice_turn",
            "played_duration_ms": 300,
        })
        ended = session.end()

        self.assertTrue(result.accepted)
        self.assertEqual("ok", synthesized["status"])
        self.assertEqual("ok", played["status"])
        self.assertEqual("ok", ended["status"])
        self.assertEqual("http://atlas.test/ai/voice/turn", transport.calls[1][0])
        self.assertEqual("http://atlas.test/ai/voice/turn/synthesized", transport.calls[2][0])
        self.assertEqual("http://atlas.test/ai/voice/turn/played", transport.calls[3][0])
        self.assertEqual("http://atlas.test/ai/voice/session/end", transport.calls[4][0])
        self.assertEqual("voice_session", transport.calls[1][1]["session_id"])
        self.assertEqual("livekit_webrtc", transport.calls[1][1]["transport"])
        self.assertNotIn("access_token", transport.calls[1][1])

    def test_session_reports_provider_health_after_kernel_turn(self) -> None:
        transport = SessionTransport()
        session = LiveKitVoiceSession.start(boundary(transport), {
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
            "room_name": "atlas-voice-vitor",
        })
        session.submit_transcribed_turn({
            "turn_id": "voice_turn",
            "transcript": "continue",
        })
        response = session.report_provider_health({
            "turn_id": "voice_turn",
            "provider": "deepgram",
            "reason": "latency_p95_breach",
        })

        self.assertEqual("ok", response["status"])
        self.assertEqual("http://atlas.test/ai/voice/provider/health-degraded", transport.calls[2][0])
        self.assertEqual("deepgram", transport.calls[2][1]["provider"])


if __name__ == "__main__":
    unittest.main()
