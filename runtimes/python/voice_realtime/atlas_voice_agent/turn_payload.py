from __future__ import annotations

import hashlib
from dataclasses import dataclass
from typing import Any, Mapping


class UnsafeVoicePayload(RuntimeError):
    """Raised when the runtime tries to send unsafe data to the Kernel."""


@dataclass(frozen=True)
class AtlasVoiceTurnPayload:
    session_id: str
    turn_id: str
    transcript: str | None = None
    audio_hash: str | None = None
    audio_duration_ms: int | None = None
    language: str = "pt-BR"
    domain_hint: str = "general"
    flow_hint: str = "general.answer"
    runtime: str = "livekit_agents_sdk"
    client_surface: str = "mobile"
    transport: str = "mobile_push_to_talk"
    privacy_class: str = "p3_audio"

    FORBIDDEN_KEYS = {
        "audio",
        "audio_bytes",
        "audio_raw",
        "raw_audio",
        "raw_audio_bytes",
        "pcm",
        "wav",
    }

    @classmethod
    def from_runtime_input(cls, payload: Mapping[str, Any]) -> "AtlasVoiceTurnPayload":
        cls.assert_no_forbidden_keys(payload)

        transcript = payload.get("transcript")
        transcript_value = str(transcript).strip() if transcript is not None else None
        audio_hash = payload.get("audio_hash")
        audio_hash_value = str(audio_hash).strip() if audio_hash is not None else None
        if not transcript_value and not audio_hash_value:
            raise UnsafeVoicePayload("turn requires transcript or audio_hash")

        return cls(
            session_id=cls._required_string(payload, "session_id"),
            turn_id=cls._required_string(payload, "turn_id"),
            transcript=transcript_value,
            audio_hash=audio_hash_value,
            audio_duration_ms=cls._optional_int(payload.get("audio_duration_ms")),
            language=str(payload.get("language") or "pt-BR"),
            domain_hint=str(payload.get("domain_hint") or "general"),
            flow_hint=str(payload.get("flow_hint") or "general.answer"),
            runtime=str(payload.get("runtime") or "livekit_agents_sdk"),
            client_surface=str(payload.get("client_surface") or "mobile"),
            transport=str(payload.get("transport") or "mobile_push_to_talk"),
            privacy_class=str(payload.get("privacy_class") or "p3_audio"),
        )

    @classmethod
    def from_transcript(cls, session_id: str, turn_id: str, transcript: str, **kwargs: Any) -> "AtlasVoiceTurnPayload":
        transcript = transcript.strip()
        if transcript == "":
            raise UnsafeVoicePayload("transcript cannot be empty")

        return cls(session_id=session_id, turn_id=turn_id, transcript=transcript, **kwargs)

    @classmethod
    def from_audio_digest(
        cls,
        session_id: str,
        turn_id: str,
        audio_digest: str,
        audio_duration_ms: int | None = None,
        **kwargs: Any,
    ) -> "AtlasVoiceTurnPayload":
        audio_digest = audio_digest.strip()
        if audio_digest == "":
            raise UnsafeVoicePayload("audio_hash cannot be empty")

        return cls(
            session_id=session_id,
            turn_id=turn_id,
            audio_hash=audio_digest,
            audio_duration_ms=audio_duration_ms,
            **kwargs,
        )

    def to_kernel_payload(self) -> dict[str, Any]:
        payload: dict[str, Any] = {
            "session_id": self.session_id,
            "turn_id": self.turn_id,
            "language": self.language,
            "domain_hint": self.domain_hint,
            "flow_hint": self.flow_hint,
            "runtime": self.runtime,
            "client_surface": self.client_surface,
            "transport": self.transport,
            "privacy_class": self.privacy_class,
        }
        if self.transcript is not None:
            payload["transcript"] = self.transcript
            payload["transcript_hash"] = hashlib.sha256(self.transcript.encode("utf-8")).hexdigest()
        if self.audio_hash is not None:
            payload["audio_hash"] = self.audio_hash
        if self.audio_duration_ms is not None:
            payload["audio_duration_ms"] = self.audio_duration_ms

        self.assert_no_forbidden_keys(payload)

        return payload

    @classmethod
    def assert_no_forbidden_keys(cls, payload: Mapping[str, Any]) -> None:
        present = sorted(cls.FORBIDDEN_KEYS.intersection(payload.keys()))
        if present:
            raise UnsafeVoicePayload(f"forbidden voice payload keys: {present}")

    @staticmethod
    def _required_string(payload: Mapping[str, Any], key: str) -> str:
        value = str(payload.get(key) or "").strip()
        if value == "":
            raise UnsafeVoicePayload(f"{key} is required")

        return value

    @staticmethod
    def _optional_int(value: Any) -> int | None:
        if value is None:
            return None

        parsed = int(value)
        if parsed < 0:
            raise UnsafeVoicePayload("duration cannot be negative")

        return parsed
