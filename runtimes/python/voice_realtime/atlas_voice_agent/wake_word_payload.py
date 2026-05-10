from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Mapping

from .payload_safety import reject_forbidden_keys_recursive
from .turn_payload import UnsafeVoicePayload


@dataclass(frozen=True)
class AtlasVoiceWakeWordPayload:
    session_id: str
    turn_id: str | None = None
    wake_word_engine: str = "local_edge"
    confidence: float | None = None
    latency_ms: int | None = None
    runtime: str = "livekit_agents_sdk"
    client_surface: str = "mobile"
    transport: str = "livekit_webrtc"
    privacy_class: str = "p3_audio"

    FORBIDDEN_KEYS = {
        "audio",
        "audio_bytes",
        "audio_raw",
        "raw_audio",
        "raw_audio_bytes",
        "pcm",
        "wav",
        "transcript",
        "response_text",
        "raw_response_text",
        "tts_text",
        "response_text_hash",
        "llm_provider",
        "tool_call",
        "access_token",
        "token",
        "livekit_token",
        "api_key",
        "api_secret",
    }
    ALLOWED_RUNTIMES = {"livekit_agents_sdk"}
    ALLOWED_CLIENT_SURFACES = {"mobile", "mac_edge"}
    ALLOWED_TRANSPORTS = {"livekit_webrtc", "mobile_push_to_talk"}
    ALLOWED_PRIVACY_CLASSES = {"p1_public", "p2_internal", "p3_audio", "p4_secret"}

    @classmethod
    def from_runtime_event(cls, payload: Mapping[str, Any]) -> "AtlasVoiceWakeWordPayload":
        cls.assert_no_forbidden_keys(payload)

        return cls(
            session_id=cls._required_string(payload, "session_id"),
            turn_id=cls._optional_string(payload.get("turn_id")),
            wake_word_engine=str(payload.get("wake_word_engine") or "local_edge"),
            confidence=cls._optional_confidence(payload.get("confidence")),
            latency_ms=cls._optional_int(payload.get("latency_ms")),
            runtime=cls._allowed(payload.get("runtime") or "livekit_agents_sdk", cls.ALLOWED_RUNTIMES, "runtime"),
            client_surface=cls._allowed(payload.get("client_surface") or "mobile", cls.ALLOWED_CLIENT_SURFACES, "client_surface"),
            transport=cls._allowed(payload.get("transport") or "livekit_webrtc", cls.ALLOWED_TRANSPORTS, "transport"),
            privacy_class=cls._allowed(payload.get("privacy_class") or "p3_audio", cls.ALLOWED_PRIVACY_CLASSES, "privacy_class"),
        )

    def to_kernel_payload(self) -> dict[str, Any]:
        payload: dict[str, Any] = {
            "session_id": self.session_id,
            "wake_word_engine": self.wake_word_engine,
            "runtime": self.runtime,
            "client_surface": self.client_surface,
            "transport": self.transport,
            "privacy_class": self.privacy_class,
        }
        if self.turn_id is not None:
            payload["turn_id"] = self.turn_id
        if self.confidence is not None:
            payload["confidence"] = self.confidence
        if self.latency_ms is not None:
            payload["latency_ms"] = self.latency_ms

        self.assert_no_forbidden_keys(payload)

        return payload

    @classmethod
    def assert_no_forbidden_keys(cls, payload: Mapping[str, Any]) -> None:
        reject_forbidden_keys_recursive(payload, cls.FORBIDDEN_KEYS, label="wake word payload")

    @staticmethod
    def _required_string(payload: Mapping[str, Any], key: str) -> str:
        value = str(payload.get(key) or "").strip()
        if value == "":
            raise UnsafeVoicePayload(f"{key} is required")

        return value

    @staticmethod
    def _optional_string(value: Any) -> str | None:
        if value is None:
            return None

        parsed = str(value).strip()
        return parsed or None

    @staticmethod
    def _optional_int(value: Any) -> int | None:
        if value is None:
            return None

        parsed = int(value)
        if parsed < 0:
            raise UnsafeVoicePayload("latency cannot be negative")

        return parsed

    @staticmethod
    def _optional_confidence(value: Any) -> float | None:
        if value is None:
            return None

        parsed = float(value)
        if parsed < 0 or parsed > 1:
            raise UnsafeVoicePayload("confidence must be between 0 and 1")

        return parsed

    @staticmethod
    def _allowed(value: Any, allowed: set[str], field: str) -> str:
        parsed = str(value).strip()
        if parsed not in allowed:
            raise UnsafeVoicePayload(f"{field} is not allowed")

        return parsed
