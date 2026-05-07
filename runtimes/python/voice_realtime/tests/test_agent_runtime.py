from __future__ import annotations

import unittest
from typing import Any, Mapping

from atlas_voice_agent.agent_runtime import AtlasVoiceAgentRuntime, AtlasVoiceRuntimeError
from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.kernel_client import AtlasKernelClient

from test_contract import manifest


class ScriptedTransport:
    def __init__(self, turn_response: Mapping[str, Any]) -> None:
        self.turn_response = turn_response
        self.calls: list[tuple[str, Mapping[str, Any]]] = []

    def __call__(self, url: str, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        self.calls.append((url, dict(payload)))
        if url.endswith("/turn"):
            return self.turn_response

        return {"status": "ok", "url": url}


class ScriptedReadinessTransport:
    def __init__(self) -> None:
        self.calls: list[tuple[str, Mapping[str, Any]]] = []

    def __call__(self, url: str, query: Mapping[str, Any]) -> Mapping[str, Any]:
        self.calls.append((url, dict(query)))

        if url.endswith("/rivals"):
            return {"status": "not_ready", "schema_version": "atlas.voice.rivals.v1"}

        return {"status": "attention", "schema_version": "atlas.voice.readiness.v1"}


def runtime(transport: ScriptedTransport, readiness_transport: ScriptedReadinessTransport | None = None) -> AtlasVoiceAgentRuntime:
    contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
    client = AtlasKernelClient(
        contract=contract,
        atlas_token="token",
        post_json=transport,
        get_json=readiness_transport,
    )

    return AtlasVoiceAgentRuntime(client)


class AtlasVoiceAgentRuntimeTest(unittest.TestCase):
    def test_starts_and_ends_session_before_turns(self) -> None:
        transport = ScriptedTransport({"status": "turn_accepted_scaffold"})
        agent = runtime(transport)
        agent.start_session({
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
        })
        agent.end_session({
            "session_id": "voice_session",
            "reason": "operator_finished",
        })

        self.assertEqual("http://atlas.test/ai/voice/session/start", transport.calls[0][0])
        self.assertEqual("http://atlas.test/ai/voice/session/end", transport.calls[1][0])

    def test_reads_kernel_readiness_without_runtime_authority(self) -> None:
        post_transport = ScriptedTransport({"status": "turn_accepted_scaffold"})
        readiness_transport = ScriptedReadinessTransport()
        agent = runtime(post_transport, readiness_transport)

        response = agent.readiness(hours=48)

        self.assertEqual("attention", response["status"])
        self.assertEqual("http://atlas.test/ai/voice/readiness", readiness_transport.calls[0][0])
        self.assertEqual({"hours": 48}, readiness_transport.calls[0][1])
        self.assertEqual([], post_transport.calls)

    def test_reads_kernel_rivals_without_runtime_authority(self) -> None:
        post_transport = ScriptedTransport({"status": "turn_accepted_scaffold"})
        readiness_transport = ScriptedReadinessTransport()
        agent = runtime(post_transport, readiness_transport)

        response = agent.rivals(hours=24)

        self.assertEqual("not_ready", response["status"])
        self.assertEqual("http://atlas.test/ai/voice/rivals", readiness_transport.calls[0][0])
        self.assertEqual({"hours": 24}, readiness_transport.calls[0][1])
        self.assertEqual([], post_transport.calls)

    def test_submits_turn_and_allows_synthesis_only_after_decision_receipt(self) -> None:
        transport = ScriptedTransport({
            "status": "turn_accepted_scaffold",
            "turn": {
                "decision_receipt": {
                    "receipt_id": "receipt_1",
                    "dry_run": True,
                },
            },
        })
        agent = runtime(transport)
        result = agent.submit_turn({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "transcript": "corrija o teste",
        })
        response = agent.report_synthesized({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "response_text": "ok",
        })

        self.assertTrue(result.accepted)
        self.assertEqual("ok", response["status"])
        self.assertEqual("http://atlas.test/ai/voice/turn", transport.calls[0][0])
        self.assertEqual("http://atlas.test/ai/voice/turn/synthesized", transport.calls[1][0])

    def test_rejects_synthesis_for_blocked_eclipse_turn(self) -> None:
        transport = ScriptedTransport({"status": "blocked_by_eclipse"})
        agent = runtime(transport)
        result = agent.submit_turn({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "transcript": "nao responder",
        })

        with self.assertRaises(AtlasVoiceRuntimeError):
            agent.report_synthesized({
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "response_text": "blocked",
            })

        self.assertTrue(result.blocked)
        self.assertEqual(1, len(transport.calls))

    def test_rejects_callback_before_turn_reaches_kernel(self) -> None:
        agent = runtime(ScriptedTransport({"status": "turn_accepted_scaffold"}))

        with self.assertRaises(AtlasVoiceRuntimeError):
            agent.report_played({
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "played_duration_ms": 200,
            })

    def test_reports_wake_word_before_turn_without_provider_authority(self) -> None:
        transport = ScriptedTransport({"status": "turn_accepted_scaffold"})
        agent = runtime(transport)
        response = agent.report_wake_word({
            "session_id": "voice_session",
            "wake_word_engine": "swift_local_edge",
            "confidence": 0.91,
            "latency_ms": 44,
        })

        self.assertEqual("ok", response["status"])
        self.assertEqual("http://atlas.test/ai/voice/wake-word", transport.calls[0][0])
        self.assertEqual("swift_local_edge", transport.calls[0][1]["wake_word_engine"])
        self.assertEqual(1, len(transport.calls))

    def test_rejects_synthesis_when_kernel_omits_decision_receipt(self) -> None:
        transport = ScriptedTransport({"status": "turn_accepted_scaffold", "turn": {}})
        agent = runtime(transport)
        agent.submit_turn({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "transcript": "corrija o teste",
        })

        with self.assertRaises(AtlasVoiceRuntimeError):
            agent.report_synthesized({
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "response_text": "no receipt",
            })

    def test_allows_interruption_after_known_turn_even_when_blocked(self) -> None:
        transport = ScriptedTransport({"status": "blocked_by_eclipse"})
        agent = runtime(transport)
        agent.submit_turn({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "transcript": "nao responder",
        })
        agent.report_interrupted({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "reason": "operator_started_speaking",
        })

        self.assertEqual("http://atlas.test/ai/voice/turn/interrupted", transport.calls[1][0])


if __name__ == "__main__":
    unittest.main()
