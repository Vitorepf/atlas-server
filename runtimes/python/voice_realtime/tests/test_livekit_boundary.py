from __future__ import annotations

import unittest
from typing import Any, Mapping

from atlas_voice_agent.agent_runtime import AtlasVoiceAgentRuntime, AtlasVoiceRuntimeError
from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.kernel_client import AtlasKernelClient
from atlas_voice_agent.livekit_boundary import LiveKitAgentBoundary
from atlas_voice_agent.turn_payload import UnsafeVoicePayload

from test_contract import manifest


class ScriptedTransport:
    def __init__(self, turn_response: Mapping[str, Any]) -> None:
        self.turn_response = turn_response
        self.calls: list[tuple[str, Mapping[str, Any]]] = []

    def __call__(self, url: str, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        self.calls.append((url, dict(payload)))
        if url.endswith("/turn"):
            return self.turn_response

        return {"status": "ok"}


class ScriptedReadinessTransport:
    def __init__(self) -> None:
        self.calls: list[tuple[str, Mapping[str, Any]]] = []

    def __call__(self, url: str, query: Mapping[str, Any]) -> Mapping[str, Any]:
        self.calls.append((url, dict(query)))

        if url.endswith("/rivals"):
            return {"status": "not_ready", "schema_version": "atlas.voice.rivals.v1"}

        return {"status": "ready", "schema_version": "atlas.voice.readiness.v1"}


def boundary(transport: ScriptedTransport, readiness_transport: ScriptedReadinessTransport | None = None) -> LiveKitAgentBoundary:
    contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
    client = AtlasKernelClient(
        contract=contract,
        atlas_token="token",
        post_json=transport,
        get_json=readiness_transport,
    )

    return LiveKitAgentBoundary(AtlasVoiceAgentRuntime(client))


def event(**overrides: Any) -> dict[str, Any]:
    payload: dict[str, Any] = {
        "session_id": "voice_session",
        "turn_id": "voice_turn",
        "participant_identity": "mobile:vitor",
        "room_name": "atlas-voice-vitor",
        "transcript": "continue a implementacao",
    }
    payload.update(overrides)

    return payload


class LiveKitAgentBoundaryTest(unittest.TestCase):
    def test_starts_and_ends_session_through_kernel_only(self) -> None:
        transport = ScriptedTransport({
            "status": "turn_accepted_scaffold",
            "turn": {"decision_receipt": {"receipt_id": "receipt_1"}},
        })
        adapter = boundary(transport)
        adapter.start_session({
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
            "room_name": "atlas-voice-vitor",
        })
        adapter.end_session({
            "session_id": "voice_session",
            "reason": "operator_finished",
        })

        self.assertEqual("http://atlas.test/ai/voice/session/start", transport.calls[0][0])
        self.assertEqual("http://atlas.test/ai/voice/session/end", transport.calls[1][0])
        self.assertNotIn("token", transport.calls[0][1])

    def test_exposes_kernel_readiness_for_livekit_preflight(self) -> None:
        post_transport = ScriptedTransport({
            "status": "turn_accepted_scaffold",
            "turn": {"decision_receipt": {"receipt_id": "receipt_1"}},
        })
        readiness_transport = ScriptedReadinessTransport()
        adapter = boundary(post_transport, readiness_transport)

        response = adapter.readiness(hours=12)

        self.assertEqual("ready", response["status"])
        self.assertEqual("http://atlas.test/ai/voice/readiness", readiness_transport.calls[0][0])
        self.assertEqual({"hours": 12}, readiness_transport.calls[0][1])
        self.assertEqual([], post_transport.calls)

    def test_exposes_kernel_rivals_for_livekit_preflight(self) -> None:
        post_transport = ScriptedTransport({
            "status": "turn_accepted_scaffold",
            "turn": {"decision_receipt": {"receipt_id": "receipt_1"}},
        })
        readiness_transport = ScriptedReadinessTransport()
        adapter = boundary(post_transport, readiness_transport)

        response = adapter.rivals(hours=12)

        self.assertEqual("not_ready", response["status"])
        self.assertEqual("http://atlas.test/ai/voice/rivals", readiness_transport.calls[0][0])
        self.assertEqual({"hours": 12}, readiness_transport.calls[0][1])
        self.assertEqual([], post_transport.calls)

    def test_submits_livekit_turn_without_exposing_provider_or_tool_authority(self) -> None:
        transport = ScriptedTransport({
            "status": "turn_accepted_scaffold",
            "turn": {"decision_receipt": {"receipt_id": "receipt_1"}},
        })
        adapter = boundary(transport)

        result = adapter.submit_transcribed_turn(event(
            domain_hint="programming",
            flow_hint="programming.dev",
            rivals_arm="direct_provider_baseline",
        ))

        self.assertTrue(result.accepted)
        self.assertEqual("http://atlas.test/ai/voice/turn", transport.calls[0][0])
        submitted = transport.calls[0][1]
        self.assertEqual("livekit_agents_sdk", submitted["runtime"])
        self.assertEqual("livekit_webrtc", submitted["transport"])
        self.assertEqual("programming", submitted["domain_hint"])
        self.assertEqual("direct_provider_baseline", submitted["rivals_arm"])
        self.assertNotIn("llm_provider", submitted)
        self.assertNotIn("tool_call", submitted)

    def test_rejects_raw_audio_and_direct_provider_authority_from_livekit_event(self) -> None:
        adapter = boundary(ScriptedTransport({"status": "turn_accepted_scaffold"}))

        with self.assertRaises(UnsafeVoicePayload):
            adapter.submit_transcribed_turn(event(raw_audio=b"nope"))

        with self.assertRaises(UnsafeVoicePayload):
            adapter.submit_transcribed_turn(event(llm_provider="claude"))

        with self.assertRaises(UnsafeVoicePayload):
            adapter.submit_transcribed_turn(event(tool_call={"name": "shell"}))

    def test_rejects_tokens_and_api_secrets_from_livekit_event(self) -> None:
        adapter = boundary(ScriptedTransport({"status": "turn_accepted_scaffold"}))

        for key in ["access_token", "token", "livekit_token", "api_key", "api_secret"]:
            with self.subTest(key=key):
                with self.assertRaises(UnsafeVoicePayload):
                    adapter.submit_transcribed_turn(event(**{key: "do-not-pass-through"}))

    def test_rejects_callback_until_kernel_turn_is_accepted_with_receipt(self) -> None:
        adapter = boundary(ScriptedTransport({"status": "turn_accepted_scaffold", "turn": {}}))
        adapter.submit_transcribed_turn(event())

        with self.assertRaises(AtlasVoiceRuntimeError):
            adapter.report_synthesized({
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "response_text_hash": "990cd70b1bfe9e7b30370699399e3d30281b9f3515533e4629ad2a182c7cdf0a",
            })

    def test_rejects_raw_response_text_from_livekit_callback(self) -> None:
        adapter = boundary(ScriptedTransport({
            "status": "turn_accepted_scaffold",
            "turn": {"decision_receipt": {"receipt_id": "receipt_1"}},
        }))
        adapter.submit_transcribed_turn(event())

        with self.assertRaises(UnsafeVoicePayload):
            adapter.report_synthesized({
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "response_text": "raw response text",
            })

    def test_reports_wake_word_without_waiting_for_turn_receipt(self) -> None:
        transport = ScriptedTransport({
            "status": "turn_accepted_scaffold",
            "turn": {"decision_receipt": {"receipt_id": "receipt_1"}},
        })
        adapter = boundary(transport)
        response = adapter.report_wake_word({
            "session_id": "voice_session",
            "wake_word_engine": "swift_local_edge",
            "latency_ms": 42,
        })

        self.assertEqual("ok", response["status"])
        self.assertEqual("http://atlas.test/ai/voice/wake-word", transport.calls[0][0])

    def test_allows_playback_only_after_kernel_receipt(self) -> None:
        transport = ScriptedTransport({
            "status": "turn_accepted_scaffold",
            "turn": {"decision_receipt": {"receipt_id": "receipt_1"}},
        })
        adapter = boundary(transport)
        adapter.submit_transcribed_turn(event())

        response = adapter.report_played({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "played_duration_ms": 300,
        })

        self.assertEqual("ok", response["status"])
        self.assertEqual("http://atlas.test/ai/voice/turn/played", transport.calls[1][0])


if __name__ == "__main__":
    unittest.main()
