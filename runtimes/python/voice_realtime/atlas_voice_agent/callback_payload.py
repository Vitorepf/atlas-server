from __future__ import annotations

import hashlib
import re
from dataclasses import dataclass
from typing import Any, Mapping

from .payload_safety import reject_forbidden_keys_recursive
from .turn_payload import UnsafeVoicePayload


@dataclass(frozen=True)
class AtlasVoiceCallbackBase:
    session_id: str
    turn_id: str
    envelope_id: str | None = None
    receipt_id: str | None = None
    runtime: str = "livekit_agents_sdk"
    provider: str | None = None
    model: str | None = None
    latency_ms: int | None = None
    rivals_arm: str = "atlas_voice"

    FORBIDDEN_KEYS = {
        "audio",
        "audio_bytes",
        "audio_raw",
        "raw_audio",
        "raw_audio_bytes",
        "pcm",
        "wav",
        "tts_text",
        "raw_response_text",
        "access_token",
        "token",
        "livekit_token",
        "api_key",
        "api_secret",
    }
    SHA256_PATTERN = re.compile(r"^[a-fA-F0-9]{64}$")
    ALLOWED_RUNTIMES = {"livekit_agents_sdk"}
    ALLOWED_RIVALS_ARMS = {"atlas_voice", "direct_provider_baseline"}

    def base_payload(self) -> dict[str, Any]:
        payload: dict[str, Any] = {
            "session_id": self.session_id,
            "turn_id": self.turn_id,
            "runtime": self.runtime,
            "rivals_arm": self.rivals_arm,
        }
        for key, value in {
            "envelope_id": self.envelope_id,
            "receipt_id": self.receipt_id,
            "provider": self.provider,
            "model": self.model,
            "latency_ms": self.latency_ms,
        }.items():
            if value is not None:
                payload[key] = value

        self.assert_no_forbidden_keys(payload)

        return payload

    @classmethod
    def assert_no_forbidden_keys(cls, payload: Mapping[str, Any]) -> None:
        reject_forbidden_keys_recursive(payload, cls.FORBIDDEN_KEYS, label="voice callback")

    @staticmethod
    def require_string(payload: Mapping[str, Any], key: str) -> str:
        value = str(payload.get(key) or "").strip()
        if value == "":
            raise UnsafeVoicePayload(f"{key} is required")

        return value

    @staticmethod
    def optional_int(value: Any) -> int | None:
        if value is None:
            return None

        parsed = int(value)
        if parsed < 0:
            raise UnsafeVoicePayload("metric cannot be negative")

        return parsed

    @staticmethod
    def rivals_arm_value(value: Any) -> str:
        return "direct_provider_baseline" if value == "direct_provider_baseline" else "atlas_voice"

    @classmethod
    def runtime_value(cls, value: Any) -> str:
        parsed = str(value or "livekit_agents_sdk").strip()
        if parsed not in cls.ALLOWED_RUNTIMES:
            raise UnsafeVoicePayload("runtime is not allowed")

        return parsed

    @classmethod
    def assert_sha256(cls, value: str, field: str) -> None:
        if cls.SHA256_PATTERN.match(value) is None:
            raise UnsafeVoicePayload(f"{field} must be a sha256 hex digest")


@dataclass(frozen=True)
class AtlasVoiceSynthesizedPayload(AtlasVoiceCallbackBase):
    response_text_hash: str | None = None
    audio_hash: str | None = None
    audio_duration_ms: int | None = None
    tts_provider: str | None = None

    @classmethod
    def from_runtime_output(cls, payload: Mapping[str, Any]) -> "AtlasVoiceSynthesizedPayload":
        cls.assert_no_forbidden_keys(payload)
        response_text = payload.get("response_text")
        response_text_hash = payload.get("response_text_hash")
        response_text_hash_value = str(response_text_hash).strip() if response_text_hash is not None else None
        if response_text is not None:
            response_text_hash_value = hashlib.sha256(str(response_text).encode("utf-8")).hexdigest()
        if response_text_hash_value is not None:
            cls.assert_sha256(response_text_hash_value, "response_text_hash")

        audio_hash = payload.get("audio_hash")
        audio_hash_value = str(audio_hash).strip() if audio_hash is not None else None
        if audio_hash_value is not None:
            cls.assert_sha256(audio_hash_value, "audio_hash")
        if not response_text_hash_value and not audio_hash_value:
            raise UnsafeVoicePayload("synthesis callback requires response_text_hash or audio_hash")

        return cls(
            session_id=cls.require_string(payload, "session_id"),
            turn_id=cls.require_string(payload, "turn_id"),
            envelope_id=str(payload["envelope_id"]) if payload.get("envelope_id") is not None else None,
            receipt_id=str(payload["receipt_id"]) if payload.get("receipt_id") is not None else None,
            runtime=cls.runtime_value(payload.get("runtime")),
            provider=str(payload["provider"]) if payload.get("provider") is not None else None,
            model=str(payload["model"]) if payload.get("model") is not None else None,
            latency_ms=cls.optional_int(payload.get("latency_ms")),
            rivals_arm=cls.rivals_arm_value(payload.get("rivals_arm")),
            response_text_hash=response_text_hash_value,
            audio_hash=audio_hash_value,
            audio_duration_ms=cls.optional_int(payload.get("audio_duration_ms")),
            tts_provider=str(payload["tts_provider"]) if payload.get("tts_provider") is not None else None,
        )

    def to_kernel_payload(self) -> dict[str, Any]:
        payload = self.base_payload()
        if self.response_text_hash is not None:
            payload["response_text_hash"] = self.response_text_hash
        if self.audio_hash is not None:
            payload["audio_hash"] = self.audio_hash
        if self.audio_duration_ms is not None:
            payload["audio_duration_ms"] = self.audio_duration_ms
        if self.tts_provider is not None:
            payload["tts_provider"] = self.tts_provider

        self.assert_no_forbidden_keys(payload)

        return payload


@dataclass(frozen=True)
class AtlasVoicePlayedPayload(AtlasVoiceCallbackBase):
    played_duration_ms: int | None = None

    @classmethod
    def from_runtime_output(cls, payload: Mapping[str, Any]) -> "AtlasVoicePlayedPayload":
        cls.assert_no_forbidden_keys(payload)

        return cls(
            session_id=cls.require_string(payload, "session_id"),
            turn_id=cls.require_string(payload, "turn_id"),
            envelope_id=str(payload["envelope_id"]) if payload.get("envelope_id") is not None else None,
            receipt_id=str(payload["receipt_id"]) if payload.get("receipt_id") is not None else None,
            runtime=cls.runtime_value(payload.get("runtime")),
            provider=str(payload["provider"]) if payload.get("provider") is not None else None,
            model=str(payload["model"]) if payload.get("model") is not None else None,
            latency_ms=cls.optional_int(payload.get("latency_ms")),
            rivals_arm=cls.rivals_arm_value(payload.get("rivals_arm")),
            played_duration_ms=cls.optional_int(payload.get("played_duration_ms")),
        )

    def to_kernel_payload(self) -> dict[str, Any]:
        payload = self.base_payload()
        if self.played_duration_ms is not None:
            payload["played_duration_ms"] = self.played_duration_ms

        self.assert_no_forbidden_keys(payload)

        return payload


@dataclass(frozen=True)
class AtlasVoiceInterruptedPayload(AtlasVoiceCallbackBase):
    reason: str = "operator_interrupted"
    interrupted_stage: str = "runtime_or_tts"

    @classmethod
    def from_runtime_output(cls, payload: Mapping[str, Any]) -> "AtlasVoiceInterruptedPayload":
        cls.assert_no_forbidden_keys(payload)

        return cls(
            session_id=cls.require_string(payload, "session_id"),
            turn_id=cls.require_string(payload, "turn_id"),
            envelope_id=str(payload["envelope_id"]) if payload.get("envelope_id") is not None else None,
            receipt_id=str(payload["receipt_id"]) if payload.get("receipt_id") is not None else None,
            runtime=cls.runtime_value(payload.get("runtime")),
            provider=str(payload["provider"]) if payload.get("provider") is not None else None,
            model=str(payload["model"]) if payload.get("model") is not None else None,
            latency_ms=cls.optional_int(payload.get("latency_ms")),
            rivals_arm=cls.rivals_arm_value(payload.get("rivals_arm")),
            reason=str(payload.get("reason") or "operator_interrupted"),
            interrupted_stage=str(payload.get("interrupted_stage") or "runtime_or_tts"),
        )

    def to_kernel_payload(self) -> dict[str, Any]:
        payload = self.base_payload()
        payload["reason"] = self.reason
        payload["interrupted_stage"] = self.interrupted_stage

        self.assert_no_forbidden_keys(payload)

        return payload


@dataclass(frozen=True)
class AtlasVoiceFailurePayload(AtlasVoiceCallbackBase):
    failure_code: str = "runtime_failed"
    error_class: str | None = None

    @classmethod
    def from_runtime_output(cls, payload: Mapping[str, Any]) -> "AtlasVoiceFailurePayload":
        cls.assert_no_forbidden_keys(payload)

        return cls(
            session_id=cls.require_string(payload, "session_id"),
            turn_id=cls.require_string(payload, "turn_id"),
            envelope_id=str(payload["envelope_id"]) if payload.get("envelope_id") is not None else None,
            receipt_id=str(payload["receipt_id"]) if payload.get("receipt_id") is not None else None,
            runtime=cls.runtime_value(payload.get("runtime")),
            provider=str(payload["provider"]) if payload.get("provider") is not None else None,
            model=str(payload["model"]) if payload.get("model") is not None else None,
            latency_ms=cls.optional_int(payload.get("latency_ms")),
            rivals_arm=cls.rivals_arm_value(payload.get("rivals_arm")),
            failure_code=str(payload.get("failure_code") or "runtime_failed"),
            error_class=str(payload["error_class"]) if payload.get("error_class") is not None else None,
        )

    def to_kernel_payload(self) -> dict[str, Any]:
        payload = self.base_payload()
        payload["failure_code"] = self.failure_code
        if self.error_class is not None:
            payload["error_class"] = self.error_class

        self.assert_no_forbidden_keys(payload)

        return payload


@dataclass(frozen=True)
class AtlasVoiceProviderHealthPayload(AtlasVoiceCallbackBase):
    reason: str = "provider_degraded"

    @classmethod
    def from_runtime_output(cls, payload: Mapping[str, Any]) -> "AtlasVoiceProviderHealthPayload":
        cls.assert_no_forbidden_keys(payload)
        provider = cls.require_string(payload, "provider")

        return cls(
            session_id=cls.require_string(payload, "session_id"),
            turn_id=cls.require_string(payload, "turn_id"),
            envelope_id=str(payload["envelope_id"]) if payload.get("envelope_id") is not None else None,
            receipt_id=str(payload["receipt_id"]) if payload.get("receipt_id") is not None else None,
            runtime=cls.runtime_value(payload.get("runtime")),
            provider=provider,
            model=str(payload["model"]) if payload.get("model") is not None else None,
            latency_ms=cls.optional_int(payload.get("latency_ms")),
            rivals_arm=cls.rivals_arm_value(payload.get("rivals_arm")),
            reason=str(payload.get("reason") or "provider_degraded"),
        )

    def to_kernel_payload(self) -> dict[str, Any]:
        payload = self.base_payload()
        payload["reason"] = self.reason

        self.assert_no_forbidden_keys(payload)

        return payload
