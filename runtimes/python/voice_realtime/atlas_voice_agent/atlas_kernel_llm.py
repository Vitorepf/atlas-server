"""Atlas Kernel LLM bridge for the real LiveKit Agents SDK loop.

This module is the only allowed bridge between LiveKit Agents' LLM contract
and the Atlas Kernel. Per `atlas-ai-voice-realtime-surface.md` and ADR 0002
(`adr-0002-voice-realtime-sdk-loop-kernel-response-path`), the LLM adapter:

1. Routes every user transcript through `LiveKitSdkHandlerRegistry` so the
   call goes via bridge -> router -> adapter -> worker -> kernel_client and
   never touches a provider/tool/memory directly.
2. Reads the response text from the Kernel response under the transient field
   `transient.tts_input_text` (Option B in ADR 0002). If the operator has not
   yet enabled Option B server-side, the LLM yields nothing and fires
   `runtime_failed` via the handler registry.
3. Never logs `response_text`, `transcript`, audio bytes, tokens or provider
   keys; the text lives only inside `_run()` long enough to be chunked and
   sent to the LiveKit TTS pipeline.
4. Owns the `turn_id` lifecycle: mints when the LLM is invoked; exposes
   `current_turn_id` and `current_response_text_hash` so the SessionEventBindings
   can populate `tts_synthesized` / `audio_played` callbacks without smuggling
   the response text out.

LiveKit Agents SDK is imported lazily — module import must succeed even when
`livekit-agents` is not installed (so the rest of the runtime can introspect
the contract without the SDK present).
"""
from __future__ import annotations

import hashlib
import logging
import uuid
from typing import Any, Mapping, TYPE_CHECKING

from .livekit_sdk_handlers import LiveKitSdkHandlerRegistry
from .livekit_worker import LiveKitWorkerResult
from .turn_payload import UnsafeVoicePayload

if TYPE_CHECKING:  # pragma: no cover - only for type checkers
    from livekit.agents import llm as _lk_llm  # noqa: F401


logger = logging.getLogger("atlas_voice_agent.kernel_llm")


SCHEMA_VERSION = "atlas.voice_realtime.kernel_llm_bridge.v1"
ADR_REFERENCE = "adr-0002-voice-realtime-sdk-loop-kernel-response-path"


class AtlasKernelLlmError(RuntimeError):
    """Raised when the Kernel response cannot become spoken text."""


class AtlasKernelLLMBridge:
    """SDK-free orchestrator that turns a user transcript into response chunks.

    The LiveKit-aware LLM/LLMStream subclasses delegate to this object so the
    governance logic stays testable without importing `livekit.agents`.
    """

    def __init__(
        self,
        *,
        handler_registry: LiveKitSdkHandlerRegistry,
        session_id: str,
        language: str = "pt-BR",
        domain_hint: str = "general",
        flow_hint: str = "general.answer",
        test_mode: bool = False,
        test_mode_response_text: str | None = None,
    ) -> None:
        if not isinstance(handler_registry, LiveKitSdkHandlerRegistry):
            raise UnsafeVoicePayload("AtlasKernelLLMBridge requires a real handler registry")
        if not session_id or not session_id.strip():
            raise UnsafeVoicePayload("session_id is required for AtlasKernelLLMBridge")
        self._handler_registry = handler_registry
        self._session_id = session_id.strip()
        self._language = language
        self._domain_hint = domain_hint
        self._flow_hint = flow_hint
        self._test_mode = test_mode
        self._test_mode_response_text = test_mode_response_text or "Atlas em test mode."
        self._current_turn_id: str | None = None
        self._current_response_text_hash: str | None = None

    @property
    def session_id(self) -> str:
        return self._session_id

    @property
    def current_turn_id(self) -> str | None:
        return self._current_turn_id

    @property
    def current_response_text_hash(self) -> str | None:
        return self._current_response_text_hash

    def clear_turn(self) -> None:
        self._current_turn_id = None
        self._current_response_text_hash = None

    def chat_chunks_for_user_text(self, user_text: str) -> list[str]:
        """Submit a user transcript to the Kernel and return TTS chunks.

        Returns a list of word-bounded chunks suitable for streaming into a
        LiveKit TTS plugin. Returns an empty list when the Kernel accepted the
        turn but did not return `transient.tts_input_text` (Option B not wired
        server-side); the caller is responsible for emitting `runtime_failed`
        in that case.
        """
        cleaned = (user_text or "").strip()
        if cleaned == "":
            raise UnsafeVoicePayload("AtlasKernelLLMBridge requires non-empty user transcript")

        turn_id = self._mint_turn_id()
        self._current_turn_id = turn_id
        self._current_response_text_hash = None
        result = self._handler_registry.route(
            "transcript_final",
            {
                "session_id": self._session_id,
                "turn_id": turn_id,
                "transcript": cleaned,
                "language": self._language,
                "domain_hint": self._domain_hint,
                "flow_hint": self._flow_hint,
            },
        )

        response_text = self._extract_response_text(result)
        if response_text == "":
            if self._test_mode:
                response_text = self._test_mode_response_text
            else:
                return []

        # Hash the response text BEFORE chunking so we can populate
        # `tts_synthesized.response_text_hash` later. Text is then forgotten
        # (not stored on the bridge); only the hash and the chunks survive.
        self._current_response_text_hash = hashlib.sha256(
            response_text.encode("utf-8")
        ).hexdigest()
        return list(_chunk_text(response_text))

    def report_runtime_failure(
        self,
        *,
        turn_id: str,
        failure_code: str,
        error_class: str | None = None,
        error_message_hash: str | None = None,
        latency_ms: int | None = None,
    ) -> LiveKitWorkerResult:
        """Route a runtime failure through the handler registry.

        Used when the Kernel response is missing required text or when the SDK
        loop traps an exception. `error_message_hash` is required by the bridge
        contract; callers must hash any error string before calling.
        """
        return self._handler_registry.route(
            "runtime_failed",
            _drop_none({
                "session_id": self._session_id,
                "turn_id": turn_id,
                "failure_code": failure_code,
                "error_class": error_class,
                "error_message_hash": error_message_hash or _hash_default_message(failure_code),
                "latency_ms": latency_ms,
            }),
        )

    @staticmethod
    def _extract_response_text(result: LiveKitWorkerResult) -> str:
        """Pull `transient.tts_input_text` from a worker result, if present.

        Per ADR 0002 Option B, the Kernel `/turn` response carries
        `transient.tts_input_text` only as an in-memory hand-off to TTS. The
        worker's `_turn_result` preserves the `transient` mapping under
        `payload.artifacts.transient` (no `response_text` survives — only
        `tts_input_text`, which is in the artifact-allowlist).
        """
        if result is None:
            return ""
        payload = getattr(result, "payload", None)
        if not isinstance(payload, Mapping):
            return ""
        artifacts = payload.get("artifacts")
        if not isinstance(artifacts, Mapping):
            return ""
        transient = artifacts.get("transient")
        if not isinstance(transient, Mapping):
            return ""
        text = transient.get("tts_input_text")
        if isinstance(text, str):
            return text.strip()
        return ""

    @staticmethod
    def _mint_turn_id() -> str:
        return f"voice-{uuid.uuid4().hex[:24]}"


def _chunk_text(text: str, target_chars: int = 80):
    """Split text into ~80-char word-bounded chunks for streaming TTS."""
    cleaned = (text or "").strip()
    if cleaned == "":
        return
    buffer: list[str] = []
    size = 0
    for word in cleaned.split():
        word_len = len(word) + (1 if buffer else 0)
        if size + word_len > target_chars and buffer:
            yield " ".join(buffer)
            buffer = [word]
            size = len(word)
        else:
            buffer.append(word)
            size += word_len
    if buffer:
        yield " ".join(buffer)


def _drop_none(payload: Mapping[str, Any]) -> dict[str, Any]:
    return {key: value for key, value in payload.items() if value is not None}


def _hash_default_message(failure_code: str) -> str:
    """Deterministic placeholder hash for failure messages without origin text.

    The bridge contract requires `error_message_hash` even when no message text
    is available. We synthesize a 64-char hex hash from the failure code so the
    contract validator passes; this never carries provider error text.
    """
    import hashlib

    digest = hashlib.sha256(failure_code.encode("utf-8")).hexdigest()
    return digest


def build_livekit_llm_factory(
    bridge: AtlasKernelLLMBridge,
    *,
    label: str = "atlas-kernel-llm",
):
    """Return a callable that materializes the LiveKit LLM at runtime.

    The factory must be called from inside the LiveKit Agents worker process
    (after `livekit.agents` is importable). It returns a `livekit.agents.llm.LLM`
    subclass instance whose `_run` routes through `bridge`.
    """

    def factory():
        from livekit.agents import llm as lk_llm
        from livekit.agents.utils import aio  # type: ignore

        class _AtlasLLM(lk_llm.LLM):  # pragma: no cover - requires SDK at runtime
            def chat(
                self,
                *,
                chat_ctx: "lk_llm.ChatContext",
                tools: list | None = None,
                conn_options=None,
                **_: Any,
            ) -> "lk_llm.LLMStream":
                effective_tools = tools or []
                effective_conn = conn_options or lk_llm.DEFAULT_API_CONNECT_OPTIONS  # type: ignore[attr-defined]
                return _AtlasLLMStream(
                    llm=self,
                    chat_ctx=chat_ctx,
                    tools=effective_tools,
                    conn_options=effective_conn,
                )

        class _AtlasLLMStream(lk_llm.LLMStream):  # pragma: no cover - requires SDK at runtime
            async def _run(self) -> None:
                user_text = _extract_latest_user_text(self._chat_ctx)
                turn_id = bridge._mint_turn_id()
                try:
                    chunks = bridge.chat_chunks_for_user_text(user_text)
                except Exception as exc:  # noqa: BLE001
                    bridge.report_runtime_failure(
                        turn_id=turn_id,
                        failure_code="atlas_kernel_llm_dispatch_failed",
                        error_class=type(exc).__name__,
                    )
                    raise

                if not chunks:
                    bridge.report_runtime_failure(
                        turn_id=turn_id,
                        failure_code="atlas_kernel_llm_empty_response",
                        error_class="AtlasKernelLlmError",
                    )
                    return

                for chunk in chunks:
                    self._event_ch.send_nowait(  # type: ignore[attr-defined]
                        lk_llm.ChatChunk(
                            id=f"atlas-{uuid.uuid4().hex[:12]}",
                            delta=lk_llm.ChoiceDelta(role="assistant", content=chunk),
                        )
                    )

        instance = _AtlasLLM()
        try:
            instance._label = label  # type: ignore[attr-defined]
        except Exception:
            pass
        return instance

    return factory


def _extract_latest_user_text(chat_ctx: Any) -> str:
    """Pull the most recent user message text from a LiveKit ChatContext."""
    if isinstance(chat_ctx, Mapping):
        items = chat_ctx.get("items")
    else:
        items = getattr(chat_ctx, "items", None)
    if not items:
        return ""
    for item in reversed(list(items)):
        role = getattr(item, "role", None)
        if role is None and isinstance(item, Mapping):
            role = item.get("role")
        if role != "user":
            continue
        content = getattr(item, "content", None)
        if content is None and isinstance(item, Mapping):
            content = item.get("content")
        if isinstance(content, list):
            return " ".join(str(piece) for piece in content if piece)
        if content is None:
            return ""
        return str(content).strip()
    return ""
