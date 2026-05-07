from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Mapping


class UnsafeSessionLease(RuntimeError):
    """Raised when the Kernel returns an unsafe or malformed voice session lease."""


@dataclass(frozen=True)
class AtlasVoiceSessionLease:
    schema_version: str
    mode: str
    room_name: str
    participant_identity: str
    runtime_id: str
    transport: str
    token_status: str
    token_issuer: str
    expires_at: str
    livekit_url: str | None = None
    access_token: str | None = None
    kernel_decision_required_per_turn: bool = True
    raw_audio_persistence_allowed: bool = False

    ALLOWED_TOKEN_STATUS = {
        "not_issued_scaffold",
        "not_issued_missing_config",
        "issued",
    }

    @classmethod
    def from_kernel_response(cls, response: Mapping[str, Any]) -> "AtlasVoiceSessionLease":
        lease = response.get("session_lease")
        if not isinstance(lease, Mapping):
            raise UnsafeSessionLease("session_lease is required")

        schema_version = cls._required(lease, "schema_version")
        if schema_version != "atlas.voice.session_lease.v1":
            raise UnsafeSessionLease(f"unsupported session_lease schema: {schema_version}")

        token_status = cls._required(lease, "token_status")
        if token_status not in cls.ALLOWED_TOKEN_STATUS:
            raise UnsafeSessionLease(f"unsupported session_lease token_status: {token_status}")

        access_token = cls._optional(lease.get("access_token"))
        if token_status == "issued" and access_token is None:
            raise UnsafeSessionLease("issued session_lease requires access_token")
        if token_status != "issued" and access_token is not None:
            raise UnsafeSessionLease("non-issued session_lease must not include access_token")

        if lease.get("kernel_decision_required_per_turn") is not True:
            raise UnsafeSessionLease("kernel_decision_required_per_turn must be true")
        if lease.get("raw_audio_persistence_allowed") is not False:
            raise UnsafeSessionLease("raw_audio_persistence_allowed must be false")

        return cls(
            schema_version=schema_version,
            mode=cls._required(lease, "mode"),
            room_name=cls._required(lease, "room_name"),
            participant_identity=cls._required(lease, "participant_identity"),
            runtime_id=cls._required(lease, "runtime_id"),
            transport=cls._required(lease, "transport"),
            token_status=token_status,
            token_issuer=cls._required(lease, "token_issuer"),
            expires_at=cls._required(lease, "expires_at"),
            livekit_url=cls._optional(lease.get("livekit_url")),
            access_token=access_token,
            kernel_decision_required_per_turn=True,
            raw_audio_persistence_allowed=False,
        )

    def to_log_payload(self) -> dict[str, Any]:
        return {
            "schema_version": self.schema_version,
            "mode": self.mode,
            "room_name": self.room_name,
            "participant_identity": self.participant_identity,
            "runtime_id": self.runtime_id,
            "transport": self.transport,
            "token_status": self.token_status,
            "token_issuer": self.token_issuer,
            "expires_at": self.expires_at,
            "livekit_url": self.livekit_url,
            "access_token_present": self.access_token is not None,
            "kernel_decision_required_per_turn": self.kernel_decision_required_per_turn,
            "raw_audio_persistence_allowed": self.raw_audio_persistence_allowed,
        }

    @staticmethod
    def _required(payload: Mapping[str, Any], key: str) -> str:
        value = str(payload.get(key) or "").strip()
        if value == "":
            raise UnsafeSessionLease(f"{key} is required")

        return value

    @staticmethod
    def _optional(value: Any) -> str | None:
        if value is None:
            return None

        parsed = str(value).strip()

        return parsed or None
