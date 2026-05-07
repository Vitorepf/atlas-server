from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Mapping

from .agent_runtime import AtlasVoiceAgentRuntime, AtlasVoiceTurnResult
from .session_lease import AtlasVoiceSessionLease
from .turn_payload import UnsafeVoicePayload


@dataclass(frozen=True)
class LiveKitTurnContext:
    session_id: str
    turn_id: str
    participant_identity: str
    room_name: str
    language: str = "pt-BR"
    domain_hint: str = "general"
    flow_hint: str = "general.answer"
    client_surface: str = "mobile"
    transport: str = "livekit_webrtc"
    privacy_class: str = "p3_audio"
    rivals_arm: str = "atlas_voice"

    @classmethod
    def from_event(cls, event: Mapping[str, Any]) -> "LiveKitTurnContext":
        return cls(
            session_id=cls._required(event, "session_id"),
            turn_id=cls._required(event, "turn_id"),
            participant_identity=cls._required(event, "participant_identity"),
            room_name=cls._required(event, "room_name"),
            language=str(event.get("language") or "pt-BR"),
            domain_hint=str(event.get("domain_hint") or "general"),
            flow_hint=str(event.get("flow_hint") or "general.answer"),
            client_surface=str(event.get("client_surface") or "mobile"),
            transport=str(event.get("transport") or "livekit_webrtc"),
            privacy_class=str(event.get("privacy_class") or "p3_audio"),
            rivals_arm="direct_provider_baseline" if event.get("rivals_arm") == "direct_provider_baseline" else "atlas_voice",
        )

    @staticmethod
    def _required(event: Mapping[str, Any], key: str) -> str:
        value = str(event.get(key) or "").strip()
        if value == "":
            raise UnsafeVoicePayload(f"{key} is required")

        return value


class LiveKitAgentBoundary:
    """Adapter boundary for the future LiveKit Agents SDK integration.

    The SDK should call this object, not AtlasKernelClient directly. That keeps
    turn admission, receipt checks and callback ordering inside the governed
    AtlasVoiceAgentRuntime.
    """

    FORBIDDEN_EVENT_KEYS = {
        "raw_audio",
        "raw_audio_bytes",
        "audio",
        "audio_bytes",
        "pcm",
        "wav",
        "response_text",
        "raw_response_text",
        "tts_text",
        "llm_provider",
        "provider_api_key",
        "tool_call",
        "tool_args",
        "access_token",
        "token",
        "livekit_token",
        "api_key",
        "api_secret",
    }

    def __init__(self, runtime: AtlasVoiceAgentRuntime) -> None:
        self.runtime = runtime

    def start_session(self, event: Mapping[str, Any]) -> Mapping[str, Any]:
        self._assert_safe_event(event)

        return self.runtime.start_session(dict(event))

    def start_session_lease(self, event: Mapping[str, Any]) -> AtlasVoiceSessionLease:
        self._assert_safe_event(event)

        return self.runtime.start_session_lease(dict(event))

    def end_session(self, event: Mapping[str, Any]) -> Mapping[str, Any]:
        self._assert_safe_event(event)

        return self.runtime.end_session(dict(event))

    def readiness(self, hours: int = 24) -> Mapping[str, Any]:
        return self.runtime.readiness(hours=hours)

    def rivals(self, hours: int = 24) -> Mapping[str, Any]:
        return self.runtime.rivals(hours=hours)

    def submit_transcribed_turn(self, event: Mapping[str, Any]) -> AtlasVoiceTurnResult:
        self._assert_safe_event(event)
        context = LiveKitTurnContext.from_event(event)
        transcript = str(event.get("transcript") or "").strip()
        audio_hash = str(event.get("audio_hash") or "").strip()
        if transcript == "" and audio_hash == "":
            raise UnsafeVoicePayload("LiveKit turn requires transcript or audio_hash")

        payload: dict[str, Any] = {
            "session_id": context.session_id,
            "turn_id": context.turn_id,
            "language": context.language,
            "domain_hint": context.domain_hint,
            "flow_hint": context.flow_hint,
            "runtime": "livekit_agents_sdk",
            "client_surface": context.client_surface,
            "transport": context.transport,
            "privacy_class": context.privacy_class,
            "rivals_arm": context.rivals_arm,
            "participant_identity": context.participant_identity,
            "room_name": context.room_name,
        }
        if transcript != "":
            payload["transcript"] = transcript
        if audio_hash != "":
            payload["audio_hash"] = audio_hash
        if event.get("audio_duration_ms") is not None:
            payload["audio_duration_ms"] = event["audio_duration_ms"]

        return self.runtime.submit_turn(payload)

    def report_wake_word(self, event: Mapping[str, Any]) -> Mapping[str, Any]:
        self._assert_safe_event(event)

        return self.runtime.report_wake_word(dict(event))

    def report_synthesized(self, event: Mapping[str, Any]) -> Mapping[str, Any]:
        self._assert_safe_event(event)

        return self.runtime.report_synthesized(dict(event))

    def report_played(self, event: Mapping[str, Any]) -> Mapping[str, Any]:
        self._assert_safe_event(event)

        return self.runtime.report_played(dict(event))

    def report_interrupted(self, event: Mapping[str, Any]) -> Mapping[str, Any]:
        self._assert_safe_event(event)

        return self.runtime.report_interrupted(dict(event))

    def report_failed(self, event: Mapping[str, Any]) -> Mapping[str, Any]:
        self._assert_safe_event(event)

        return self.runtime.report_failed(dict(event))

    def report_provider_health_degraded(self, event: Mapping[str, Any]) -> Mapping[str, Any]:
        self._assert_safe_event(event)

        return self.runtime.report_provider_health_degraded(dict(event))

    @classmethod
    def _assert_safe_event(cls, event: Mapping[str, Any]) -> None:
        present = sorted(cls.FORBIDDEN_EVENT_KEYS.intersection(event.keys()))
        if present:
            raise UnsafeVoicePayload(f"forbidden LiveKit event keys: {present}")
