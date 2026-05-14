from __future__ import annotations

import unittest
from typing import Any, Callable, Mapping

from atlas_voice_agent.agent_runtime import AtlasVoiceAgentRuntime
from atlas_voice_agent.atlas_kernel_llm import AtlasKernelLLMBridge
from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.kernel_client import AtlasKernelClient
from atlas_voice_agent.livekit_boundary import LiveKitAgentBoundary
from atlas_voice_agent.livekit_callback_router import LiveKitCallbackRouter
from atlas_voice_agent.livekit_real_sdk_loop import (
    ADR_REFERENCE,
    ROOM_NAMESPACE_PREFIX,
    SCHEMA_VERSION,
    RealSdkLoopSettings,
    SessionEventBindings,
    build_session_factory,
    register_session_callbacks,
)
from atlas_voice_agent.livekit_sdk_adapter import LiveKitSdkAdapter
from atlas_voice_agent.livekit_sdk_handlers import LiveKitSdkHandlerRegistry
from atlas_voice_agent.livekit_worker import AtlasLiveKitWorker
from atlas_voice_agent.turn_payload import UnsafeVoicePayload

from test_contract import manifest


class StubTransport:
    def __init__(self, *, transient_text: str | None = "Atlas teste OK.") -> None:
        self.calls: list[tuple[str, Mapping[str, Any]]] = []
        self._transient_text = transient_text

    def __call__(self, url: str, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        self.calls.append((url, dict(payload)))
        if url.endswith("/session/start"):
            return {
                "status": "session_started_scaffold",
                "session_lease": {
                    "schema_version": "atlas.voice.session_lease.v1",
                    "mode": "mobile_push_to_talk",
                    "room_name": "atlas-voice-loop",
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
            response: dict[str, Any] = {
                "status": "turn_accepted_scaffold",
                "turn": {
                    "decision_receipt": {
                        "receipt_id": "receipt_loop_test",
                        "receipt_hash": "deadbeef",
                        "dry_run": True,
                    },
                },
            }
            if self._transient_text is not None:
                response["transient"] = {"tts_input_text": self._transient_text}
            return response
        return {"status": "ok"}


def _registry() -> tuple[LiveKitSdkHandlerRegistry, StubTransport]:
    transport = StubTransport()
    contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
    client = AtlasKernelClient(
        contract=contract,
        atlas_token="token",
        post_json=transport,
    )
    boundary = LiveKitAgentBoundary(AtlasVoiceAgentRuntime(client))
    worker = AtlasLiveKitWorker(boundary)
    adapter = LiveKitSdkAdapter(worker)
    return LiveKitSdkHandlerRegistry(LiveKitCallbackRouter(adapter)), transport


def _settings(**overrides: Any) -> RealSdkLoopSettings:
    base = dict(
        session_id="voice_session_loop",
        room_name="atlas-voice-loop",
        participant_identity="mobile:vitor",
        livekit_url="ws://127.0.0.1:7880",
        livekit_token="dev-token-not-logged",
    )
    base.update(overrides)
    return RealSdkLoopSettings(**base)


class FakeSession:
    """Minimal stand-in for `livekit.agents.AgentSession`.

    Captures handler registrations so tests can assert event wiring without
    importing the SDK. The handlers can be invoked manually to simulate SDK
    callbacks firing.
    """

    def __init__(self) -> None:
        self.bindings: dict[str, Callable[[Any], Any]] = {}

    def on(self, event: str, callback: Callable[[Any], Any]) -> None:
        self.bindings[event] = callback


class RealSdkLoopSettingsTest(unittest.TestCase):
    def test_constants_reference_canonical_adr(self) -> None:
        self.assertEqual("atlas.voice_realtime.real_sdk_loop.v1", SCHEMA_VERSION)
        self.assertEqual(
            "adr-0002-voice-realtime-sdk-loop-kernel-response-path",
            ADR_REFERENCE,
        )
        self.assertEqual("atlas-voice", ROOM_NAMESPACE_PREFIX)

    def test_session_id_is_required(self) -> None:
        with self.assertRaises(UnsafeVoicePayload):
            _settings(session_id=" ")

    def test_room_name_must_use_atlas_voice_namespace(self) -> None:
        with self.assertRaises(UnsafeVoicePayload):
            _settings(room_name="random-room")

    def test_participant_identity_must_use_mobile_namespace(self) -> None:
        with self.assertRaises(UnsafeVoicePayload):
            _settings(participant_identity="vitor")

    def test_livekit_token_is_required(self) -> None:
        with self.assertRaises(UnsafeVoicePayload):
            _settings(livekit_token=" ")

    def test_min_interruption_duration_must_be_in_range(self) -> None:
        with self.assertRaises(UnsafeVoicePayload):
            _settings(min_interruption_duration=0.01)
        with self.assertRaises(UnsafeVoicePayload):
            _settings(min_interruption_duration=2.0)

    def test_safe_log_dict_excludes_token(self) -> None:
        log = _settings().safe_log_dict()
        self.assertNotIn("livekit_token", log)
        # Sanity: includes room_name + participant for ops.
        self.assertEqual("atlas-voice-loop", log["room_name"])
        self.assertEqual("mobile:vitor", log["participant_identity"])


class SessionEventBindingsTest(unittest.TestCase):
    def setUp(self) -> None:
        self.registry, self.transport = _registry()
        self.settings = _settings()
        self.bridge = AtlasKernelLLMBridge(
            handler_registry=self.registry,
            session_id=self.settings.session_id,
        )
        self.bindings = SessionEventBindings(
            handler_registry=self.registry,
            settings=self.settings,
            bridge=self.bridge,
        )

    def test_announce_session_routes_participant_joined(self) -> None:
        result = self.bindings.announce_session()
        self.assertEqual("session_started", result.event_kind)
        self.assertTrue(any(url.endswith("/session/start") for url, _ in self.transport.calls))

    def test_speech_committed_requires_active_turn(self) -> None:
        self.bindings.announce_session()
        with self.assertRaises(UnsafeVoicePayload):
            self.bindings.on_agent_speech_committed(None)

    def test_user_started_speaking_does_not_allocate_turn_id(self) -> None:
        self.bindings.announce_session()
        # Bridge owns turn ids; user_started_speaking is observation only.
        self.assertIsNone(self.bridge.current_turn_id)
        self.bindings.on_user_started_speaking(None)
        self.assertIsNone(self.bridge.current_turn_id)

    def test_full_turn_lifecycle_routes_callbacks(self) -> None:
        # Open session
        self.bindings.announce_session()
        # User speaks (observation only)
        self.bindings.on_user_started_speaking(None)
        # LLM bridge mints turn_id and routes transcript_final + receives transient
        chunks = self.bridge.chat_chunks_for_user_text("vai")
        self.assertGreater(len(chunks), 0)
        active_turn = self.bridge.current_turn_id
        self.assertIsNotNone(active_turn)
        self.assertIsNotNone(self.bridge.current_response_text_hash)
        # Agent speech committed -> tts_synthesized (uses bridge state)
        synth = self.bindings.on_agent_speech_committed(None)
        self.assertEqual("synthesized", synth.event_kind)
        # Agent speech finished -> audio_played AND clears bridge turn state
        played = self.bindings.on_agent_speech_finished(None)
        self.assertEqual("played", played.event_kind)
        self.assertIsNone(self.bridge.current_turn_id)

    def test_barge_in_routes_and_clears_turn(self) -> None:
        self.bindings.announce_session()
        chunks = self.bridge.chat_chunks_for_user_text("primeiro turno")
        self.assertGreater(len(chunks), 0)
        result = self.bindings.on_agent_speech_interrupted(None)
        self.assertEqual("interrupted", result.event_kind)
        self.assertIsNone(self.bridge.current_turn_id)

    def test_close_routes_participant_left(self) -> None:
        self.bindings.announce_session()
        result = self.bindings.on_close(None)
        self.assertEqual("session_ended", result.event_kind)


class RegisterSessionCallbacksTest(unittest.TestCase):
    def test_register_session_callbacks_binds_required_events(self) -> None:
        registry, _ = _registry()
        settings = _settings()
        bridge = AtlasKernelLLMBridge(
            handler_registry=registry,
            session_id=settings.session_id,
        )
        session = FakeSession()

        register_session_callbacks(
            session=session,
            handler_registry=registry,
            settings=settings,
            bridge=bridge,
        )

        for event in (
            "user_started_speaking",
            "agent_started_speaking",
            "agent_speech_committed",
            "agent_speech_finished",
            "agent_speech_interrupted",
            "error",
            "close",
        ):
            self.assertIn(event, session.bindings, msg=f"missing wiring for {event}")

    def test_register_rejects_session_without_emitter(self) -> None:
        registry, _ = _registry()
        settings = _settings()
        bridge = AtlasKernelLLMBridge(
            handler_registry=registry,
            session_id=settings.session_id,
        )

        class BadSession:
            pass

        with self.assertRaises(UnsafeVoicePayload):
            register_session_callbacks(
                session=BadSession(),
                handler_registry=registry,
                settings=settings,
                bridge=bridge,
            )


class BuildSessionFactoryTest(unittest.TestCase):
    def test_factory_exposes_bridge_and_does_not_import_sdk_until_called(self) -> None:
        registry, _ = _registry()
        settings = _settings()
        factory = build_session_factory(handler_registry=registry, settings=settings)
        self.assertIsInstance(factory.bridge, AtlasKernelLLMBridge)
        # We deliberately do NOT call factory() here because that would
        # require livekit.agents room infrastructure. The fact that
        # build_session_factory returns without importing livekit.agents is
        # the contract we test.


if __name__ == "__main__":
    unittest.main()
