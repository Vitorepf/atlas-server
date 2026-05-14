from __future__ import annotations

import unittest
from typing import Any, Mapping

from atlas_voice_agent.agent_runtime import AtlasVoiceAgentRuntime
from atlas_voice_agent.atlas_kernel_llm import (
    ADR_REFERENCE,
    AtlasKernelLLMBridge,
    SCHEMA_VERSION,
    _chunk_text,
    _extract_latest_user_text,
    _hash_default_message,
)
from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.kernel_client import AtlasKernelClient
from atlas_voice_agent.livekit_boundary import LiveKitAgentBoundary
from atlas_voice_agent.livekit_callback_router import LiveKitCallbackRouter
from atlas_voice_agent.livekit_sdk_adapter import LiveKitSdkAdapter
from atlas_voice_agent.livekit_sdk_handlers import LiveKitSdkHandlerRegistry
from atlas_voice_agent.livekit_worker import AtlasLiveKitWorker
from atlas_voice_agent.turn_payload import UnsafeVoicePayload

from test_contract import manifest


class HandlerTransport:
    def __init__(self, *, transient_text: str | None = None) -> None:
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
                    "room_name": "atlas-voice-test",
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
                        "receipt_id": "receipt_kernel_llm_test",
                        "receipt_hash": "deadbeef",
                        "dry_run": True,
                    },
                    "operation_envelope": {"envelope_id": "env-1"},
                },
            }
            if self._transient_text is not None:
                response["transient"] = {"tts_input_text": self._transient_text}
            return response
        if url.endswith("/runtime/failed"):
            return {"status": "runtime_failure_recorded"}
        return {"status": "ok"}


def _registry(transport: HandlerTransport) -> LiveKitSdkHandlerRegistry:
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


def _open_session(transport: HandlerTransport, registry: LiveKitSdkHandlerRegistry) -> None:
    """Open a session through the handler registry so subsequent turns route."""
    registry.route(
        "participant_joined",
        {
            "session_id": "voice_session_x",
            "participant_identity": "mobile:vitor",
            "room_name": "atlas-voice-test",
        },
    )


class AtlasKernelLLMBridgeTest(unittest.TestCase):
    def test_constants_reference_canonical_adr(self) -> None:
        self.assertEqual("atlas.voice_realtime.kernel_llm_bridge.v1", SCHEMA_VERSION)
        self.assertEqual(
            "adr-0002-voice-realtime-sdk-loop-kernel-response-path",
            ADR_REFERENCE,
        )

    def test_session_id_is_required(self) -> None:
        transport = HandlerTransport()
        registry = _registry(transport)
        with self.assertRaises(UnsafeVoicePayload):
            AtlasKernelLLMBridge(handler_registry=registry, session_id="")

    def test_user_transcript_must_be_non_empty(self) -> None:
        transport = HandlerTransport()
        registry = _registry(transport)
        _open_session(transport, registry)
        bridge = AtlasKernelLLMBridge(
            handler_registry=registry,
            session_id="voice_session_x",
        )
        with self.assertRaises(UnsafeVoicePayload):
            bridge.chat_chunks_for_user_text("   ")

    def test_chunks_for_user_text_routes_through_handler_registry(self) -> None:
        transport = HandlerTransport(transient_text="Atlas nao concorda. O backup quebra.")
        registry = _registry(transport)
        _open_session(transport, registry)
        bridge = AtlasKernelLLMBridge(
            handler_registry=registry,
            session_id="voice_session_x",
        )

        chunks = bridge.chat_chunks_for_user_text("preciso revisar o backup")

        self.assertGreater(len(chunks), 0)
        self.assertTrue(all(isinstance(chunk, str) and chunk for chunk in chunks))
        # A POST to /turn must have happened.
        urls = [call[0] for call in transport.calls]
        self.assertTrue(any(url.endswith("/turn") for url in urls))

    def test_empty_kernel_response_returns_no_chunks_outside_test_mode(self) -> None:
        transport = HandlerTransport(transient_text=None)  # Kernel did not return text
        registry = _registry(transport)
        _open_session(transport, registry)
        bridge = AtlasKernelLLMBridge(
            handler_registry=registry,
            session_id="voice_session_x",
        )

        chunks = bridge.chat_chunks_for_user_text("preciso revisar o backup")

        self.assertEqual([], chunks)

    def test_test_mode_yields_placeholder_when_kernel_text_missing(self) -> None:
        transport = HandlerTransport(transient_text=None)
        registry = _registry(transport)
        _open_session(transport, registry)
        bridge = AtlasKernelLLMBridge(
            handler_registry=registry,
            session_id="voice_session_x",
            test_mode=True,
            test_mode_response_text="placeholder atlas response",
        )

        chunks = bridge.chat_chunks_for_user_text("test transcript")

        self.assertGreater(len(chunks), 0)
        self.assertTrue(any("placeholder" in chunk for chunk in chunks))

    def test_runtime_failure_routes_through_handler_registry(self) -> None:
        transport = HandlerTransport()
        registry = _registry(transport)
        _open_session(transport, registry)
        # Submit a turn first so the worker has an accepted turn to fail on.
        registry.route(
            "transcript_final",
            {
                "session_id": "voice_session_x",
                "turn_id": "voice_turn_failure",
                "transcript": "first",
            },
        )
        bridge = AtlasKernelLLMBridge(
            handler_registry=registry,
            session_id="voice_session_x",
        )

        result = bridge.report_runtime_failure(
            turn_id="voice_turn_failure",
            failure_code="atlas_kernel_llm_empty_response",
        )

        self.assertEqual("failed", result.event_kind)
        urls = [call[0] for call in transport.calls]
        self.assertTrue(any(url.endswith("/runtime/failed") for url in urls))

    def test_default_error_message_hash_is_64_hex_chars(self) -> None:
        digest = _hash_default_message("atlas_kernel_llm_empty_response")
        self.assertEqual(64, len(digest))
        self.assertTrue(all(c in "0123456789abcdef" for c in digest))


class ChunkTextTest(unittest.TestCase):
    def test_short_text_yields_single_chunk(self) -> None:
        chunks = list(_chunk_text("Atlas calado."))
        self.assertEqual(["Atlas calado."], chunks)

    def test_empty_text_yields_no_chunks(self) -> None:
        self.assertEqual([], list(_chunk_text("")))
        self.assertEqual([], list(_chunk_text("   ")))

    def test_long_text_breaks_at_word_boundary_under_target(self) -> None:
        text = "uma frase relativamente densa que precisa ser quebrada em pedacos para o tts streaming nao explodir"
        chunks = list(_chunk_text(text, target_chars=40))
        self.assertGreaterEqual(len(chunks), 2)
        for chunk in chunks:
            # Word-bounded: never breaks mid-word.
            self.assertFalse(chunk.startswith(" "))
            self.assertFalse(chunk.endswith(" "))


class ExtractLatestUserTextTest(unittest.TestCase):
    class _FakeMsg:
        def __init__(self, role: str, content: Any) -> None:
            self.role = role
            self.content = content

    class _FakeCtx:
        def __init__(self, items: list[Any]) -> None:
            self.items = items

    def test_returns_empty_when_no_items(self) -> None:
        self.assertEqual("", _extract_latest_user_text(None))
        self.assertEqual("", _extract_latest_user_text(self._FakeCtx([])))

    def test_returns_last_user_message_string_content(self) -> None:
        ctx = self._FakeCtx([
            self._FakeMsg("system", "ignore"),
            self._FakeMsg("user", "primeira"),
            self._FakeMsg("assistant", "resposta"),
            self._FakeMsg("user", "segunda"),
        ])
        self.assertEqual("segunda", _extract_latest_user_text(ctx))

    def test_returns_joined_string_when_user_content_is_list(self) -> None:
        ctx = self._FakeCtx([
            self._FakeMsg("user", ["parte um", "parte dois"]),
        ])
        self.assertEqual("parte um parte dois", _extract_latest_user_text(ctx))

    def test_supports_dict_chat_context(self) -> None:
        ctx = {"items": [{"role": "user", "content": "via dict"}]}
        self.assertEqual("via dict", _extract_latest_user_text(ctx))


if __name__ == "__main__":
    unittest.main()
