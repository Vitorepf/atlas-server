"""Real LiveKit Agents SDK loop for Atlas Voice Realtime.

This is the only module in `atlas_voice_agent` that imports `livekit.agents`
at runtime. Every callback the SDK emits passes through
`LiveKitSdkHandlerRegistry`, which routes via bridge -> router -> adapter ->
worker -> kernel_client per `atlas-ai-voice-realtime-surface.md`.

Module import is safe even when `livekit-agents` is not installed: the SDK
imports happen inside `entrypoint()` and inside the factories returned by
`build_session_factory()`. Tests must use mocks; real loop must be invoked by
an operator under `python -m atlas_voice_agent.main --start-worker` (which
remains BLOCKED by the worker_start packet contract until a Decision Receipt
unlocks Phase 1 promotion).

Status today (2026-05-13): ADR 0002 still `proposed`. The loop runs; the
Kernel-side `transient.tts_input_text` field that supplies TTS text is gated
by ADR 0002 acceptance. Until then this module routes everything correctly
but TTS will be silent and `runtime_failed(atlas_kernel_llm_empty_response)`
fires per turn — exactly the fail-closed behavior the surface contract
requires.
"""
from __future__ import annotations

import logging
import uuid
from dataclasses import dataclass, field
from typing import Any, Mapping, TYPE_CHECKING

from .atlas_kernel_llm import AtlasKernelLLMBridge, build_livekit_llm_factory
from .livekit_sdk_handlers import LiveKitSdkHandlerRegistry
from .livekit_worker import LiveKitWorkerResult
from .turn_payload import UnsafeVoicePayload

if TYPE_CHECKING:  # pragma: no cover - type-check only
    from livekit.agents import AgentSession, JobContext  # noqa: F401


logger = logging.getLogger("atlas_voice_agent.real_sdk_loop")


SCHEMA_VERSION = "atlas.voice_realtime.real_sdk_loop.v1"
ADR_REFERENCE = "adr-0002-voice-realtime-sdk-loop-kernel-response-path"
ROOM_NAMESPACE_PREFIX = "atlas-voice"


@dataclass(frozen=True)
class RealSdkLoopSettings:
    """Settings required to start the real LiveKit Agents loop.

    `livekit_token` is held only in memory while the worker connects to the
    room; the contract enforces that no logger or kernel callback ever sees it.
    `participant_identity` follows the `mobile:<operator_id>` namespace so the
    Kernel can identify the surface.
    """

    session_id: str
    room_name: str
    participant_identity: str
    livekit_url: str
    livekit_token: str
    language: str = "pt-BR"
    domain_hint: str = "general"
    flow_hint: str = "general.answer"
    privacy_class: str = "p3_audio"
    rivals_arm: str = "atlas_voice"
    stt_model: str = "gpt-4o-transcribe"
    tts_voice: str = "alloy"
    min_interruption_duration: float = 0.25
    allow_interruptions: bool = True
    test_mode: bool = False
    forbidden_keys: tuple[str, ...] = field(default_factory=lambda: (
        "access_token",
        "api_key",
        "api_secret",
        "raw_audio",
        "audio_bytes",
        "response_text",
        "raw_response_text",
        "tts_text",
    ))

    def __post_init__(self) -> None:
        if not self.session_id.strip():
            raise UnsafeVoicePayload("session_id is required")
        if not self.room_name.startswith(ROOM_NAMESPACE_PREFIX):
            raise UnsafeVoicePayload(
                f"room_name must start with '{ROOM_NAMESPACE_PREFIX}'"
            )
        if not self.participant_identity.startswith("mobile:"):
            raise UnsafeVoicePayload(
                "participant_identity must use the mobile: namespace"
            )
        if not self.livekit_url.strip():
            raise UnsafeVoicePayload("livekit_url is required")
        if not self.livekit_token.strip():
            raise UnsafeVoicePayload("livekit_token is required")
        if self.min_interruption_duration < 0.05 or self.min_interruption_duration > 1.0:
            raise UnsafeVoicePayload(
                "min_interruption_duration must be between 0.05 and 1.0 seconds"
            )

    def session_event_payload(self) -> Mapping[str, Any]:
        return {
            "session_id": self.session_id,
            "participant_identity": self.participant_identity,
            "room_name": self.room_name,
            "client_surface": "mobile",
            "transport": "livekit_webrtc",
            "privacy_class": self.privacy_class,
            "rivals_arm": self.rivals_arm,
        }

    def safe_log_dict(self) -> dict[str, Any]:
        """Loggable subset: never includes `livekit_token`."""
        return {
            "session_id": self.session_id,
            "room_name": self.room_name,
            "participant_identity": self.participant_identity,
            "livekit_url": _redact_url(self.livekit_url),
            "language": self.language,
            "domain_hint": self.domain_hint,
            "flow_hint": self.flow_hint,
            "privacy_class": self.privacy_class,
            "rivals_arm": self.rivals_arm,
            "stt_model": self.stt_model,
            "tts_voice": self.tts_voice,
            "min_interruption_duration": self.min_interruption_duration,
            "allow_interruptions": self.allow_interruptions,
            "test_mode": self.test_mode,
        }


def assert_sdk_available() -> None:
    """Raise if `livekit-agents` is not importable."""
    try:
        import livekit.agents  # noqa: F401
    except ImportError as exc:
        raise RuntimeError(
            "livekit-agents is not installed. "
            "Run `php artisan atlas:ai:voice dependency-install-plan --json` "
            "and follow the operator-managed install."
        ) from exc


def build_session_factory(
    *,
    handler_registry: LiveKitSdkHandlerRegistry,
    settings: RealSdkLoopSettings,
):
    """Return a callable that materializes the AgentSession at runtime.

    The returned factory must be called inside the LiveKit Agents worker
    process; it imports `livekit.agents` and the OpenAI plugin lazily.
    """

    bridge = AtlasKernelLLMBridge(
        handler_registry=handler_registry,
        session_id=settings.session_id,
        language=settings.language,
        domain_hint=settings.domain_hint,
        flow_hint=settings.flow_hint,
        test_mode=settings.test_mode,
    )
    llm_factory = build_livekit_llm_factory(bridge, label="atlas-kernel-llm")

    def factory():  # pragma: no cover - requires SDK at runtime
        assert_sdk_available()
        from livekit import agents as la
        from livekit.plugins import openai as openai_plugin
        from livekit.plugins import silero as silero_plugin  # noqa: F401  optional

        session = la.AgentSession(
            stt=openai_plugin.STT(model=settings.stt_model),
            llm=llm_factory(),
            tts=openai_plugin.TTS(voice=settings.tts_voice),
            allow_interruptions=settings.allow_interruptions,
            min_interruption_duration=settings.min_interruption_duration,
        )
        register_session_callbacks(
            session=session,
            handler_registry=handler_registry,
            settings=settings,
            bridge=bridge,
        )
        return session

    factory.bridge = bridge  # exposed for tests
    return factory


def register_session_callbacks(
    *,
    session: Any,
    handler_registry: LiveKitSdkHandlerRegistry,
    settings: RealSdkLoopSettings,
    bridge: AtlasKernelLLMBridge,
) -> None:
    """Bind AgentSession events to handler_registry callbacks.

    `session` is loosely typed so this function can be unit-tested with a
    minimal mock that exposes `.on(event, fn)`. In production it is a
    `livekit.agents.AgentSession`.
    """
    if not hasattr(session, "on") or not callable(session.on):
        raise UnsafeVoicePayload("session must expose an .on(event, callback) emitter")

    bound = SessionEventBindings(
        handler_registry=handler_registry,
        settings=settings,
        bridge=bridge,
    )

    session.on("user_started_speaking", bound.on_user_started_speaking)
    session.on("agent_started_speaking", bound.on_agent_started_speaking)
    session.on("agent_speech_committed", bound.on_agent_speech_committed)
    session.on("agent_speech_finished", bound.on_agent_speech_finished)
    session.on("agent_speech_interrupted", bound.on_agent_speech_interrupted)
    session.on("error", bound.on_error)
    session.on("close", bound.on_close)


class SessionEventBindings:
    """Translation table from AgentSession events to handler_registry calls.

    Each method extracts only primitive fields, drops anything in the
    forbidden allowlist, and routes through `LiveKitSdkHandlerRegistry`. None
    of these methods may call providers, tools, memory or persist audio.

    The `turn_id` and `response_text_hash` are owned by the
    `AtlasKernelLLMBridge`: the LLM mints both when the user transcript is
    submitted to the Kernel; this binding reads them back to populate the
    `tts_synthesized` and `audio_played` callbacks.
    """

    def __init__(
        self,
        *,
        handler_registry: LiveKitSdkHandlerRegistry,
        settings: RealSdkLoopSettings,
        bridge: AtlasKernelLLMBridge,
    ) -> None:
        self._registry = handler_registry
        self._settings = settings
        self._bridge = bridge

    def announce_session(self) -> LiveKitWorkerResult:
        """Fire participant_joined when the worker actually connects."""
        return self._registry.route(
            "participant_joined",
            self._settings.session_event_payload(),
        )

    def on_user_started_speaking(self, event: Any = None) -> LiveKitWorkerResult | None:
        # User turn begins; nothing to route until STT delivers transcript and
        # the LLM bridge mints the turn_id via chat_chunks_for_user_text.
        return None

    def on_agent_started_speaking(self, event: Any = None) -> LiveKitWorkerResult | None:
        # The transcript_final + LLM stream already routed; agent speech start
        # is observability-only here.
        return None

    def on_agent_speech_committed(self, event: Any = None) -> LiveKitWorkerResult:
        turn_id = self._require_active_turn_id()
        return self._registry.route(
            "tts_synthesized",
            _drop_none({
                "session_id": self._settings.session_id,
                "turn_id": turn_id,
                "response_text_hash": self._bridge.current_response_text_hash,
                "tts_provider": "openai",
                "provider": "openai",
                "model": self._settings.tts_voice,
                "audio_duration_ms": _safe_int(getattr(event, "audio_duration_ms", None)),
                "latency_ms": _safe_int(getattr(event, "latency_ms", None)),
            }),
        )

    def on_agent_speech_finished(self, event: Any = None) -> LiveKitWorkerResult:
        turn_id = self._require_active_turn_id()
        result = self._registry.route(
            "audio_played",
            _drop_none({
                "session_id": self._settings.session_id,
                "turn_id": turn_id,
                "played_duration_ms": _safe_int(getattr(event, "played_duration_ms", None)),
                "latency_ms": _safe_int(getattr(event, "latency_ms", None)),
            }),
        )
        self._bridge.clear_turn()
        return result

    def on_agent_speech_interrupted(self, event: Any = None) -> LiveKitWorkerResult:
        turn_id = self._require_active_turn_id()
        result = self._registry.route(
            "barge_in",
            _drop_none({
                "session_id": self._settings.session_id,
                "turn_id": turn_id,
                "reason": str(getattr(event, "reason", "operator_started_speaking")),
                "interrupted_stage": str(getattr(event, "interrupted_stage", "tts_streaming")),
                "played_duration_ms": _safe_int(getattr(event, "played_duration_ms", None)),
                "latency_ms": _safe_int(getattr(event, "latency_ms", None)),
            }),
        )
        self._bridge.clear_turn()
        return result

    def on_error(self, event: Any = None) -> LiveKitWorkerResult:
        turn_id = self._bridge.current_turn_id or f"voice-{uuid.uuid4().hex[:24]}"
        error_class = type(event).__name__ if event is not None else "UnknownError"
        return self._bridge.report_runtime_failure(
            turn_id=turn_id,
            failure_code="livekit_session_error",
            error_class=error_class,
        )

    def on_close(self, event: Any = None) -> LiveKitWorkerResult:
        return self._registry.route(
            "participant_left",
            _drop_none({
                "session_id": self._settings.session_id,
                "reason": str(getattr(event, "reason", "session_closed")),
            }),
        )

    def _require_active_turn_id(self) -> str:
        turn_id = self._bridge.current_turn_id
        if not turn_id:
            raise UnsafeVoicePayload(
                "agent speech callback fired without an active turn (LLM did not mint one)"
            )
        return turn_id


async def entrypoint(ctx: Any) -> None:  # pragma: no cover - requires SDK + room
    """LiveKit Agents Worker entrypoint.

    `ctx` is a `livekit.agents.JobContext`. The Atlas Kernel must have issued
    a fresh LiveKit token and bootstrap manifest before this runs. This
    function does NOT install the SDK, mutate Kernel policy, or persist audio.
    It only:

    1. Imports the SDK lazily.
    2. Fires `participant_joined` through the handler registry.
    3. Builds and starts the AgentSession.
    4. Awaits room close.
    5. Fires `participant_left`.
    """
    assert_sdk_available()

    bootstrap = ctx.proc.userdata.get("atlas_voice_runtime")
    handler_registry: LiveKitSdkHandlerRegistry = bootstrap["handler_registry"]
    settings: RealSdkLoopSettings = bootstrap["settings"]

    bindings = SessionEventBindings(
        handler_registry=handler_registry,
        settings=settings,
        bridge=AtlasKernelLLMBridge(
            handler_registry=handler_registry,
            session_id=settings.session_id,
            language=settings.language,
            domain_hint=settings.domain_hint,
            flow_hint=settings.flow_hint,
            test_mode=settings.test_mode,
        ),
    )

    await ctx.connect()
    bindings.announce_session()

    session_factory = build_session_factory(
        handler_registry=handler_registry,
        settings=settings,
    )
    session = session_factory()
    try:
        await session.start(room=ctx.room)
        await ctx.wait_for_disconnect()
    finally:
        bindings.on_close(None)
        try:
            await session.aclose()
        except Exception:  # noqa: BLE001
            logger.exception("error closing AgentSession")


def _drop_none(payload: Mapping[str, Any]) -> dict[str, Any]:
    return {key: value for key, value in payload.items() if value is not None}


def _safe_int(value: Any) -> int | None:
    if value is None:
        return None
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def _redact_url(url: str) -> str:
    """Drop credentials from a URL but keep host visible for logs."""
    from urllib.parse import urlparse, urlunparse

    parsed = urlparse(url)
    if parsed.username or parsed.password:
        netloc = parsed.hostname or ""
        if parsed.port:
            netloc = f"{netloc}:{parsed.port}"
        return urlunparse(parsed._replace(netloc=netloc))
    return url
