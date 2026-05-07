from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Mapping

from .turn_payload import UnsafeVoicePayload


@dataclass(frozen=True)
class AtlasVoiceSessionPayload:
    session_id: str
    participant_identity: str | None = None
    room_name: str | None = None
    runtime: str = "livekit_agents_sdk"
    client_surface: str = "mobile"
    transport: str = "livekit_webrtc"
    privacy_class: str = "p3_audio"
    rivals_arm: str = "atlas_voice"
    reason: str | None = None

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
        "provider_api_key",
        "tool_call",
        "tool_args",
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
    def from_runtime_event(cls, payload: Mapping[str, Any]) -> "AtlasVoiceSessionPayload":
        cls.assert_no_forbidden_keys(payload)

        return cls(
            session_id=cls._required_string(payload, "session_id"),
            participant_identity=cls._optional_string(payload.get("participant_identity")),
            room_name=cls._optional_string(payload.get("room_name")),
            runtime=cls._allowed(payload.get("runtime") or "livekit_agents_sdk", cls.ALLOWED_RUNTIMES, "runtime"),
            client_surface=cls._allowed(payload.get("client_surface") or "mobile", cls.ALLOWED_CLIENT_SURFACES, "client_surface"),
            transport=cls._allowed(payload.get("transport") or "livekit_webrtc", cls.ALLOWED_TRANSPORTS, "transport"),
            privacy_class=cls._allowed(payload.get("privacy_class") or "p3_audio", cls.ALLOWED_PRIVACY_CLASSES, "privacy_class"),
            rivals_arm=cls._rivals_arm(payload.get("rivals_arm")),
            reason=cls._optional_string(payload.get("reason")),
        )

    def to_kernel_payload(self) -> dict[str, Any]:
        payload: dict[str, Any] = {
            "session_id": self.session_id,
            "runtime": self.runtime,
            "client_surface": self.client_surface,
            "transport": self.transport,
            "privacy_class": self.privacy_class,
            "rivals_arm": self.rivals_arm,
        }
        if self.participant_identity is not None:
            payload["participant_identity"] = self.participant_identity
        if self.room_name is not None:
            payload["room_name"] = self.room_name
        if self.reason is not None:
            payload["reason"] = self.reason

        self.assert_no_forbidden_keys(payload)

        return payload

    @classmethod
    def assert_no_forbidden_keys(cls, payload: Mapping[str, Any]) -> None:
        present = sorted(cls.FORBIDDEN_KEYS.intersection(payload.keys()))
        if present:
            raise UnsafeVoicePayload(f"forbidden session payload keys: {present}")

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
    def _rivals_arm(value: Any) -> str:
        return "direct_provider_baseline" if value == "direct_provider_baseline" else "atlas_voice"

    @staticmethod
    def _allowed(value: Any, allowed: set[str], field: str) -> str:
        parsed = str(value).strip()
        if parsed not in allowed:
            raise UnsafeVoicePayload(f"{field} is not allowed")

        return parsed
