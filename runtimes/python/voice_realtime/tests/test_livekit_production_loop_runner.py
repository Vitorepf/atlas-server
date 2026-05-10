from __future__ import annotations

import unittest
from typing import Any, Mapping

from atlas_voice_agent.agent_runtime import AtlasVoiceAgentRuntime
from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.kernel_client import AtlasKernelClient
from atlas_voice_agent.livekit_boundary import LiveKitAgentBoundary
from atlas_voice_agent.livekit_callback_router import LiveKitCallbackRouter
from atlas_voice_agent.livekit_production_loop_runner import (
    LiveKitProductionLoopRunner,
    _bridge_contract_report,
    _handler_registry_contract_report,
    _normalizer_contract_report,
)
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
        if url.endswith("/runtime/events/normalize-sequence"):
            return {
                "schema_version": "atlas.voice_realtime.runtime_event_normalizer.v1",
                "status": "normalized_sequence",
                "valid": True,
                "event_count": len(payload.get("events") or []),
                "errors": [],
                "contract": {
                    "guardrails": {
                        "runtime_execution_enabled": False,
                        "provider_execution_enabled": False,
                        "raw_audio_persistence_allowed": False,
                        "secret_persistence_allowed": False,
                    },
                },
            }
        if url.endswith("/runtime/events/normalize"):
            return {
                "schema_version": "atlas.voice_realtime.runtime_event_normalizer.v1",
                "status": "normalized",
                "valid": True,
                "event_count": 1,
                "errors": [],
                "contract": {
                    "guardrails": {
                        "runtime_execution_enabled": False,
                        "provider_execution_enabled": False,
                        "raw_audio_persistence_allowed": False,
                        "secret_persistence_allowed": False,
                    },
                },
            }
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
        self.assertEqual(
            "atlas.voice_realtime.bridge_contract_report.v1",
            payload["bridge_contract_report"]["schema_version"],
        )
        self.assertEqual("valid", payload["bridge_contract_report"]["status"])
        self.assertTrue(payload["bridge_contract_report"]["bridge_schema_valid"])
        self.assertEqual([], payload["bridge_contract_report"]["missing_callbacks"])
        self.assertEqual([], payload["bridge_contract_report"]["invalid_guardrails"])
        self.assertFalse(payload["bridge_contract_report"]["required_guardrails"]["direct_provider_call_allowed"])
        self.assertEqual(
            "atlas.voice_realtime.sdk_handler_registry.v1",
            payload["handler_registry_contract"]["schema_version"],
        )
        self.assertEqual(
            "atlas.voice_realtime.handler_registry_contract_report.v1",
            payload["handler_registry_contract_report"]["schema_version"],
        )
        self.assertEqual("valid", payload["handler_registry_contract_report"]["status"])
        self.assertTrue(payload["handler_registry_contract_report"]["sdk_import_safe"])
        self.assertEqual([], payload["handler_registry_contract_report"]["missing_handlers"])
        self.assertEqual([], payload["handler_registry_contract_report"]["invalid_guardrails"])
        self.assertEqual(
            "atlas.voice_realtime.kernel_normalizer_contract_report.v1",
            payload["kernel_normalizer_contract_report"]["schema_version"],
        )
        self.assertEqual("valid", payload["kernel_normalizer_contract_report"]["status"])
        self.assertTrue(payload["kernel_normalizer_contract_report"]["normalizer_schema_valid"])
        self.assertTrue(payload["kernel_normalizer_contract_report"]["normalizer_valid"])
        self.assertEqual(4, payload["kernel_normalizer_contract_report"]["event_count"])
        self.assertEqual(
            "atlas.voice_realtime.worker_return_contract_report.v1",
            payload["worker_return_contract"]["schema_version"],
        )
        self.assertEqual("valid", payload["worker_return_contract"]["status"])
        self.assertEqual(3, payload["worker_return_contract"]["checked_result_count"])
        self.assertEqual(0, payload["worker_return_contract"]["invalid_result_count"])
        self.assertIn("evidence_refs", payload["worker_return_contract"]["required_keys"])
        self.assertFalse(payload["guardrails"]["direct_provider_call_allowed"])
        self.assertFalse(payload["guardrails"]["raw_audio_persistence_allowed"])
        called_urls = [call[0] for call in transport.calls]
        self.assertEqual("http://atlas.test/ai/voice/runtime/events/normalize-sequence", called_urls[0])
        self.assertEqual(4, called_urls.count("http://atlas.test/ai/voice/runtime/events/normalize"))
        self.assertIn("http://atlas.test/ai/voice/session/start", called_urls)
        self.assertIn("http://atlas.test/ai/voice/turn", called_urls)
        self.assertIn("http://atlas.test/ai/voice/turn/played", called_urls)
        self.assertIn("http://atlas.test/ai/voice/session/end", called_urls)

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

    def test_bridge_contract_report_fails_closed_for_missing_callback_or_guardrail(self) -> None:
        invalid = {
            "schema_version": "atlas.voice_realtime.sdk_event_bridge.v1",
            "supported_callbacks": ["participant_joined"],
            "guardrails": {
                "direct_provider_call_allowed": True,
                "direct_tool_execution_allowed": False,
                "raw_audio_persistence_allowed": False,
                "access_token_log_allowed": False,
            },
        }

        payload = _bridge_contract_report(invalid)

        self.assertEqual("atlas.voice_realtime.bridge_contract_report.v1", payload["schema_version"])
        self.assertEqual("invalid", payload["status"])
        self.assertIn("transcript_final", payload["missing_callbacks"])
        self.assertEqual(["direct_provider_call_allowed"], payload["invalid_guardrails"])

    def test_handler_registry_contract_report_fails_closed_for_missing_handler_or_guardrail(self) -> None:
        bridge_contract = LiveKitSdkEventBridge.contract()
        invalid = {
            "schema_version": "atlas.voice_realtime.sdk_handler_registry.v1",
            "sdk_import_required_for_contract": False,
            "handler_names": ["handle_room_connected"],
            "guardrails": {
                "direct_provider_call_allowed": False,
                "direct_tool_execution_allowed": False,
                "memory_write_allowed": True,
                "policy_mutation_allowed": False,
                "raw_audio_persistence_allowed": False,
                "access_token_log_allowed": False,
            },
        }

        payload = _handler_registry_contract_report(invalid, bridge_contract)

        self.assertEqual("atlas.voice_realtime.handler_registry_contract_report.v1", payload["schema_version"])
        self.assertEqual("invalid", payload["status"])
        self.assertIn("transcript_final", payload["missing_handlers"])
        self.assertEqual(
            ["kernel_event_normalizer_required_for_real_loop", "memory_write_allowed"],
            payload["invalid_guardrails"],
        )

    def test_normalizer_contract_report_fails_closed_for_invalid_kernel_result(self) -> None:
        payload = _normalizer_contract_report({
            "schema_version": "atlas.voice_realtime.runtime_event_normalizer.v1",
            "status": "invalid_sequence",
            "valid": False,
            "event_count": 1,
            "errors": ["event_0:payload_session_id_required"],
            "contract": {
                "guardrails": {
                    "runtime_execution_enabled": True,
                    "provider_execution_enabled": False,
                    "raw_audio_persistence_allowed": False,
                    "secret_persistence_allowed": False,
                },
            },
        })

        self.assertEqual("atlas.voice_realtime.kernel_normalizer_contract_report.v1", payload["schema_version"])
        self.assertEqual("invalid", payload["status"])
        self.assertFalse(payload["normalizer_valid"])
        self.assertEqual(["runtime_execution_enabled"], payload["invalid_guardrails"])


if __name__ == "__main__":
    unittest.main()
