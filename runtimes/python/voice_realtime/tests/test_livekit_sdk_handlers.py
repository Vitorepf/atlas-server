from __future__ import annotations

import unittest
from typing import Any, Mapping

from atlas_voice_agent.agent_runtime import AtlasVoiceAgentRuntime
from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.kernel_client import AtlasKernelClient
from atlas_voice_agent.kernel_event_normalizer import KernelRuntimeEventNormalizerGuard
from atlas_voice_agent.livekit_boundary import LiveKitAgentBoundary
from atlas_voice_agent.livekit_callback_router import LiveKitCallbackRouter
from atlas_voice_agent.livekit_sdk_adapter import LiveKitSdkAdapter
from atlas_voice_agent.livekit_sdk_event_bridge import LiveKitSdkEventBridge
from atlas_voice_agent.livekit_sdk_handlers import LiveKitSdkHandlerRegistry, build_livekit_sdk_handler_contract
from atlas_voice_agent.livekit_worker import AtlasLiveKitWorker
from atlas_voice_agent.turn_payload import UnsafeVoicePayload

from test_contract import manifest


class HandlerTransport:
    def __init__(self, normalizer_valid: bool = True) -> None:
        self.calls: list[tuple[str, Mapping[str, Any]]] = []
        self.normalizer_valid = normalizer_valid

    def __call__(self, url: str, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        self.calls.append((url, dict(payload)))
        if url.endswith("/runtime/events/normalize"):
            return {
                "schema_version": "atlas.voice_realtime.runtime_event_normalizer.v1",
                "status": "normalized" if self.normalizer_valid else "invalid",
                "valid": self.normalizer_valid,
                "event_count": 1,
                "errors": [] if self.normalizer_valid else ["payload_session_id_required"],
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
                    "room_name": "atlas-voice-handler",
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
                        "receipt_id": "receipt_handler_1",
                        "dry_run": True,
                    },
                },
            }

        return {"status": "ok"}


def registry(transport: HandlerTransport) -> LiveKitSdkHandlerRegistry:
    contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
    client = AtlasKernelClient(
        contract=contract,
        atlas_token="token",
        post_json=transport,
    )
    boundary = LiveKitAgentBoundary(AtlasVoiceAgentRuntime(client))
    worker = AtlasLiveKitWorker(boundary)
    adapter = LiveKitSdkAdapter(worker)

    return LiveKitSdkHandlerRegistry(LiveKitCallbackRouter(adapter))


def guarded_registry(transport: HandlerTransport) -> LiveKitSdkHandlerRegistry:
    contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
    client = AtlasKernelClient(
        contract=contract,
        atlas_token="token",
        post_json=transport,
    )
    boundary = LiveKitAgentBoundary(AtlasVoiceAgentRuntime(client))
    worker = AtlasLiveKitWorker(boundary)
    adapter = LiveKitSdkAdapter(worker)

    return LiveKitSdkHandlerRegistry(
        LiveKitCallbackRouter(adapter),
        normalizer=KernelRuntimeEventNormalizerGuard(client),
    )


class LiveKitSdkHandlerRegistryTest(unittest.TestCase):
    def test_handler_registry_contract_is_complete_without_importing_sdk(self) -> None:
        payload = build_livekit_sdk_handler_contract()

        self.assertEqual("atlas.voice_realtime.sdk_handler_registry.v1", payload["schema_version"])
        self.assertEqual("ready", payload["status"])
        self.assertTrue(payload["kernel_only"])
        self.assertTrue(payload["mobile_first"])
        self.assertFalse(payload["sdk_import_required_for_contract"])
        self.assertEqual(
            set(LiveKitSdkEventBridge.EVENT_TO_CALLBACK.keys()),
            set(payload["supported_event_kinds"]),
        )
        self.assertIn("handle_room_connected", payload["handler_names"])
        self.assertIn("KernelRuntimeEventNormalizerGuard.assert_event_valid", payload["required_path"])
        self.assertIn("LiveKitSdkEventBridge.to_callback_event", payload["required_path"])
        self.assertIn("LiveKitCallbackRouter.route", payload["required_path"])
        self.assertIn("provider SDK call", payload["forbidden_path"])
        self.assertFalse(payload["guardrails"]["direct_provider_call_allowed"])
        self.assertTrue(payload["guardrails"]["kernel_event_normalizer_required_for_real_loop"])

    def test_handlers_route_sdk_events_through_kernel_only_router(self) -> None:
        transport = HandlerTransport()
        subject = registry(transport)
        handlers = subject.handlers()

        self.assertIn("room_connected", handlers)
        self.assertIn("transcript_final", handlers)

        joined = handlers["room_connected"]({
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
            "room_name": "atlas-voice-handler",
        })
        turn = handlers["transcript_final"]({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "transcript": "continue",
        })

        self.assertEqual("session_started", joined.event_kind)
        self.assertEqual("turn_accepted_scaffold", turn.status)
        self.assertEqual("http://atlas.test/ai/voice/session/start", transport.calls[0][0])
        self.assertEqual("http://atlas.test/ai/voice/turn", transport.calls[1][0])

    def test_handlers_validate_sdk_events_with_kernel_normalizer_before_routing(self) -> None:
        transport = HandlerTransport()
        subject = guarded_registry(transport)

        joined = subject.route("room_connected", {
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
            "room_name": "atlas-voice-handler",
        })

        self.assertEqual("session_started", joined.event_kind)
        self.assertEqual("http://atlas.test/ai/voice/runtime/events/normalize", transport.calls[0][0])
        self.assertEqual("room_connected", transport.calls[0][1]["event"]["event_kind"])
        self.assertEqual("http://atlas.test/ai/voice/session/start", transport.calls[1][0])

    def test_handlers_fail_closed_when_kernel_normalizer_rejects_event(self) -> None:
        transport = HandlerTransport(normalizer_valid=False)
        subject = guarded_registry(transport)

        with self.assertRaises(UnsafeVoicePayload):
            subject.route("room_connected", {
                "session_id": "voice_session",
                "participant_identity": "mobile:vitor",
                "room_name": "atlas-voice-handler",
            })

        self.assertEqual(1, len(transport.calls))
        self.assertEqual("http://atlas.test/ai/voice/runtime/events/normalize", transport.calls[0][0])

    def test_handlers_reject_forbidden_authority_and_raw_audio_fields(self) -> None:
        subject = registry(HandlerTransport())

        with self.assertRaises(UnsafeVoicePayload):
            subject.route("room_connected", {
                "session_id": "voice_session",
                "participant_identity": "mobile:vitor",
                "room_name": "atlas-voice-handler",
                "metadata": {"direct_provider_call": {"provider": "claude"}},
            })

        with self.assertRaises(UnsafeVoicePayload):
            subject.route("transcript_final", {
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "transcript": "continue",
                "audio_bytes": "raw audio must not cross handler boundary",
            })


if __name__ == "__main__":
    unittest.main()
