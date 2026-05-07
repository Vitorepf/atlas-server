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


def boundary(transport: ScriptedTransport) -> LiveKitAgentBoundary:
    contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
    client = AtlasKernelClient(contract=contract, atlas_token="token", post_json=transport)

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
    def test_submits_livekit_turn_without_exposing_provider_or_tool_authority(self) -> None:
        transport = ScriptedTransport({
            "status": "turn_accepted_scaffold",
            "turn": {"decision_receipt": {"receipt_id": "receipt_1"}},
        })
        adapter = boundary(transport)

        result = adapter.submit_transcribed_turn(event(domain_hint="programming", flow_hint="programming.dev"))

        self.assertTrue(result.accepted)
        self.assertEqual("http://atlas.test/ai/voice/turn", transport.calls[0][0])
        submitted = transport.calls[0][1]
        self.assertEqual("livekit_agents_sdk", submitted["runtime"])
        self.assertEqual("livekit_webrtc", submitted["transport"])
        self.assertEqual("programming", submitted["domain_hint"])
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

    def test_rejects_callback_until_kernel_turn_is_accepted_with_receipt(self) -> None:
        adapter = boundary(ScriptedTransport({"status": "turn_accepted_scaffold", "turn": {}}))
        adapter.submit_transcribed_turn(event())

        with self.assertRaises(AtlasVoiceRuntimeError):
            adapter.report_synthesized({
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "response_text": "ok",
            })

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
