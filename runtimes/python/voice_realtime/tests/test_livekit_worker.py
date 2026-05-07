from __future__ import annotations

import unittest
from typing import Any, Mapping

from atlas_voice_agent.agent_runtime import AtlasVoiceAgentRuntime
from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.kernel_client import AtlasKernelClient
from atlas_voice_agent.livekit_boundary import LiveKitAgentBoundary
from atlas_voice_agent.livekit_worker import AtlasLiveKitWorker, LiveKitWorkerError
from atlas_voice_agent.main import create_livekit_worker
from atlas_voice_agent.turn_payload import UnsafeVoicePayload

from test_contract import manifest


class WorkerTransport:
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
                    "room_name": "atlas-voice-worker",
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
                        "receipt_id": "receipt_worker_1",
                        "dry_run": True,
                    },
                },
            }

        return {"status": "ok", "echo_url": url}


def worker(transport: WorkerTransport) -> AtlasLiveKitWorker:
    contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
    client = AtlasKernelClient(
        contract=contract,
        atlas_token="token",
        post_json=transport,
    )
    boundary = LiveKitAgentBoundary(AtlasVoiceAgentRuntime(client))

    return AtlasLiveKitWorker(boundary)


class AtlasLiveKitWorkerTest(unittest.TestCase):
    def test_starts_session_with_token_free_log_payload(self) -> None:
        transport = WorkerTransport()
        result = worker(transport).start_session({
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
            "room_name": "atlas-voice-worker",
        })

        self.assertEqual("session_started", result.event_kind)
        self.assertEqual("ready", result.status)
        self.assertEqual("http://atlas.test/ai/voice/session/start", transport.calls[0][0])
        self.assertNotIn("header.payload.signature", str(result.log_payload()))
        self.assertTrue(result.log_payload()["payload"]["session_lease"]["access_token_present"])

    def test_main_helper_creates_worker_adapter(self) -> None:
        transport = WorkerTransport()
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        client = AtlasKernelClient(
            contract=contract,
            atlas_token="token",
            post_json=transport,
        )
        boundary = LiveKitAgentBoundary(AtlasVoiceAgentRuntime(client))

        self.assertIsInstance(create_livekit_worker(boundary), AtlasLiveKitWorker)

    def test_worker_routes_session_lifecycle_through_kernel(self) -> None:
        transport = WorkerTransport()
        subject = worker(transport)
        subject.start_session({
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
            "room_name": "atlas-voice-worker",
        })

        turn = subject.handle_event({
            "event_kind": "transcribed_turn",
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "transcript": "continue",
            "domain_hint": "programming",
        })
        synthesized = subject.handle_event({
            "event_kind": "synthesized",
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "response_text_hash": "990cd70b1bfe9e7b30370699399e3d30281b9f3515533e4629ad2a182c7cdf0a",
        })
        self.assertEqual(1, subject.accepted_turn_count("voice_session"))
        ended = subject.handle_event({
            "event_kind": "session_ended",
            "session_id": "voice_session",
        })

        self.assertEqual("turn_accepted_scaffold", turn.status)
        self.assertEqual("receipt_worker_1", turn.payload["receipt_id"])
        self.assertEqual("ok", synthesized.status)
        self.assertEqual("ok", ended.status)
        self.assertEqual(0, subject.active_session_count())
        self.assertEqual(0, subject.accepted_turn_count("voice_session"))
        self.assertEqual("http://atlas.test/ai/voice/turn", transport.calls[1][0])
        self.assertEqual("http://atlas.test/ai/voice/turn/synthesized", transport.calls[2][0])
        self.assertEqual("http://atlas.test/ai/voice/session/end", transport.calls[3][0])
        self.assertNotIn("access_token", str(turn.log_payload()))

    def test_worker_processes_scripted_events_in_order(self) -> None:
        transport = WorkerTransport()
        subject = worker(transport)
        results = subject.process_scripted_events([
            {
                "event_kind": "start_session",
                "session_id": "voice_session",
                "participant_identity": "mobile:vitor",
                "room_name": "atlas-voice-worker",
            },
            {
                "event_kind": "wake_word_detected",
                "session_id": "voice_session",
                "wake_word_engine": "mobile_local",
                "confidence": 0.91,
            },
            {
                "event_kind": "transcribed_turn",
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "transcript": "continue",
            },
            {
                "event_kind": "played",
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "played_duration_ms": 250,
            },
            {
                "event_kind": "session_ended",
                "session_id": "voice_session",
            },
        ])

        self.assertEqual(["session_started", "wake_word_detected", "transcribed_turn", "played", "session_ended"], [
            result.event_kind for result in results
        ])
        self.assertEqual(0, subject.active_session_count())
        self.assertEqual("http://atlas.test/ai/voice/session/start", transport.calls[0][0])
        self.assertEqual("http://atlas.test/ai/voice/wake-word", transport.calls[1][0])
        self.assertEqual("http://atlas.test/ai/voice/turn", transport.calls[2][0])
        self.assertEqual("http://atlas.test/ai/voice/turn/played", transport.calls[3][0])
        self.assertEqual("http://atlas.test/ai/voice/session/end", transport.calls[4][0])
        self.assertNotIn("header.payload.signature", str([result.log_payload() for result in results]))

    def test_worker_rejects_empty_scripted_events(self) -> None:
        with self.assertRaises(LiveKitWorkerError):
            worker(WorkerTransport()).process_scripted_events([])

    def test_worker_fails_closed_for_unknown_session_or_event_kind(self) -> None:
        subject = worker(WorkerTransport())

        with self.assertRaises(LiveKitWorkerError):
            subject.handle_event({
                "event_kind": "transcribed_turn",
                "session_id": "missing",
                "turn_id": "voice_turn",
                "transcript": "continue",
            })

        subject.start_session({
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
            "room_name": "atlas-voice-worker",
        })

        with self.assertRaises(LiveKitWorkerError):
            subject.handle_event({
                "event_kind": "direct_provider_call",
                "session_id": "voice_session",
            })

    def test_worker_rejects_missing_event_kind(self) -> None:
        subject = worker(WorkerTransport())

        with self.assertRaises(UnsafeVoicePayload):
            subject.handle_event({"session_id": "voice_session"})

    def test_worker_rejects_raw_response_text_from_livekit_event(self) -> None:
        subject = worker(WorkerTransport())
        subject.start_session({
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
            "room_name": "atlas-voice-worker",
        })

        with self.assertRaises(UnsafeVoicePayload):
            subject.handle_event({
                "event_kind": "synthesized",
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "response_text": "raw text must be hashed before worker",
            })

        subject.handle_event({
            "event_kind": "transcribed_turn",
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "transcript": "continue",
        })

        with self.assertRaises(UnsafeVoicePayload):
            subject.handle_event({
                "event_kind": "synthesized",
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "response_text": "raw text must be hashed before worker",
            })

    def test_worker_rejects_runtime_callbacks_before_kernel_accepts_turn(self) -> None:
        subject = worker(WorkerTransport())
        subject.start_session({
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
            "room_name": "atlas-voice-worker",
        })

        for event_kind in ["synthesized", "played", "interrupted", "provider_health_degraded"]:
            with self.subTest(event_kind=event_kind):
                event: dict[str, Any] = {
                    "event_kind": event_kind,
                    "session_id": "voice_session",
                    "turn_id": "unaccepted_turn",
                }
                if event_kind == "synthesized":
                    event["response_text_hash"] = "990cd70b1bfe9e7b30370699399e3d30281b9f3515533e4629ad2a182c7cdf0a"
                if event_kind == "provider_health_degraded":
                    event["provider"] = "deepgram"

                with self.assertRaises(LiveKitWorkerError):
                    subject.handle_event(event)

        self.assertEqual(0, subject.accepted_turn_count("voice_session"))

    def test_worker_rejects_runtime_callback_for_different_turn_than_decision_receipt(self) -> None:
        subject = worker(WorkerTransport())
        subject.start_session({
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
            "room_name": "atlas-voice-worker",
        })
        subject.handle_event({
            "event_kind": "transcribed_turn",
            "session_id": "voice_session",
            "turn_id": "accepted_turn",
            "transcript": "continue",
        })

        with self.assertRaises(LiveKitWorkerError):
            subject.handle_event({
                "event_kind": "played",
                "session_id": "voice_session",
                "turn_id": "other_turn",
                "played_duration_ms": 250,
            })

        played = subject.handle_event({
            "event_kind": "played",
            "session_id": "voice_session",
            "turn_id": "accepted_turn",
            "played_duration_ms": 250,
        })

        self.assertEqual("ok", played.status)


if __name__ == "__main__":
    unittest.main()
