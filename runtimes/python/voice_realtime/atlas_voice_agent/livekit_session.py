from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Mapping

from .agent_runtime import AtlasVoiceTurnResult
from .livekit_boundary import LiveKitAgentBoundary
from .session_lease import AtlasVoiceSessionLease
from .turn_payload import UnsafeVoicePayload


@dataclass(frozen=True)
class LiveKitVoiceSession:
    """Governed session handle for the future LiveKit Agents SDK worker.

    This handle is intentionally thin: it owns no provider, tool or memory
    authority. It only keeps the Kernel-issued lease in memory and routes every
    turn or callback back through the Kernel boundary.
    """

    boundary: LiveKitAgentBoundary
    lease: AtlasVoiceSessionLease
    session_id: str
    client_surface: str = "mobile"
    privacy_class: str = "p3_audio"
    rivals_arm: str = "atlas_voice"

    @classmethod
    def start(cls, boundary: LiveKitAgentBoundary, event: Mapping[str, Any]) -> "LiveKitVoiceSession":
        lease = boundary.start_session_lease(event)
        session_id = cls._required(event, "session_id")

        return cls(
            boundary=boundary,
            lease=lease,
            session_id=session_id,
            client_surface=str(event.get("client_surface") or "mobile"),
            privacy_class=str(event.get("privacy_class") or "p3_audio"),
            rivals_arm="direct_provider_baseline" if event.get("rivals_arm") == "direct_provider_baseline" else "atlas_voice",
        )

    @property
    def access_token(self) -> str | None:
        """Return the token for SDK room join code only. Never log this value."""

        return self.lease.access_token

    def log_payload(self) -> dict[str, Any]:
        return {
            "session_id": self.session_id,
            "client_surface": self.client_surface,
            "privacy_class": self.privacy_class,
            "rivals_arm": self.rivals_arm,
            "session_lease": self.lease.to_log_payload(),
        }

    def submit_transcribed_turn(self, event: Mapping[str, Any]) -> AtlasVoiceTurnResult:
        return self.boundary.submit_transcribed_turn(self._with_session(event))

    def report_wake_word(self, event: Mapping[str, Any]) -> Mapping[str, Any]:
        return self.boundary.report_wake_word(self._with_session(event))

    def report_synthesized(self, event: Mapping[str, Any]) -> Mapping[str, Any]:
        return self.boundary.report_synthesized(self._with_session(event))

    def report_played(self, event: Mapping[str, Any]) -> Mapping[str, Any]:
        return self.boundary.report_played(self._with_session(event))

    def report_interrupted(self, event: Mapping[str, Any]) -> Mapping[str, Any]:
        return self.boundary.report_interrupted(self._with_session(event))

    def report_failed(self, event: Mapping[str, Any]) -> Mapping[str, Any]:
        return self.boundary.report_failed(self._with_session(event))

    def report_provider_health(self, event: Mapping[str, Any]) -> Mapping[str, Any]:
        return self.boundary.report_provider_health_degraded(self._with_session(event))

    def end(self, reason: str = "operator_finished") -> Mapping[str, Any]:
        return self.boundary.end_session({
            "session_id": self.session_id,
            "participant_identity": self.lease.participant_identity,
            "room_name": self.lease.room_name,
            "client_surface": self.client_surface,
            "transport": self.lease.transport,
            "privacy_class": self.privacy_class,
            "rivals_arm": self.rivals_arm,
            "reason": reason,
        })

    def _with_session(self, event: Mapping[str, Any]) -> dict[str, Any]:
        merged = dict(event)
        merged.setdefault("session_id", self.session_id)
        merged.setdefault("participant_identity", self.lease.participant_identity)
        merged.setdefault("room_name", self.lease.room_name)
        merged.setdefault("runtime", self.lease.runtime_id)
        merged.setdefault("transport", self.lease.transport)
        merged.setdefault("client_surface", self.client_surface)
        merged.setdefault("privacy_class", self.privacy_class)
        merged.setdefault("rivals_arm", self.rivals_arm)

        return merged

    @staticmethod
    def _required(event: Mapping[str, Any], key: str) -> str:
        value = str(event.get(key) or "").strip()
        if value == "":
            raise UnsafeVoicePayload(f"{key} is required")

        return value
