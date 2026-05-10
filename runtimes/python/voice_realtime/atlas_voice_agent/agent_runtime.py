from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Mapping

from .kernel_client import AtlasKernelClient
from .session_lease import AtlasVoiceSessionLease
from .turn_payload import UnsafeVoicePayload


class AtlasVoiceRuntimeError(RuntimeError):
    """Raised when the voice runtime would violate Kernel authority."""


@dataclass(frozen=True)
class AtlasVoiceTurnResult:
    status: str
    session_id: str
    turn_id: str
    kernel_response: Mapping[str, Any]

    @property
    def accepted(self) -> bool:
        return self.status == "turn_accepted_scaffold"

    @property
    def blocked(self) -> bool:
        return self.status == "blocked_by_eclipse"

    @property
    def decision_receipt(self) -> Mapping[str, Any] | None:
        receipt = self.kernel_response.get("turn", {})
        if not isinstance(receipt, Mapping):
            return None
        value = receipt.get("decision_receipt")

        return value if isinstance(value, Mapping) else None


class AtlasVoiceAgentRuntime:
    """Small runtime facade that LiveKit Agents SDK can wrap later.

    This class is deliberately conservative. It cannot synthesize, play audio
    or report provider health until a turn has been accepted by the Kernel.
    """

    def __init__(self, client: AtlasKernelClient) -> None:
        self.client = client
        self._turns: dict[str, AtlasVoiceTurnResult] = {}

    def start_session(self, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        return self.client.start_session(payload)

    def start_session_lease(self, payload: Mapping[str, Any]) -> AtlasVoiceSessionLease:
        return self.client.start_session_lease(payload)

    def end_session(self, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        return self.client.end_session(payload)

    def readiness(self, hours: int = 24) -> Mapping[str, Any]:
        return self.client.readiness(hours=hours)

    def rivals(self, hours: int = 24) -> Mapping[str, Any]:
        return self.client.rivals(hours=hours)

    def submit_turn(self, payload: Mapping[str, Any]) -> AtlasVoiceTurnResult:
        response = self.client.submit_turn(payload)
        session_id = str(payload.get("session_id") or "")
        turn_id = str(payload.get("turn_id") or "")
        if session_id == "" or turn_id == "":
            raise UnsafeVoicePayload("session_id and turn_id are required")

        status = str(response.get("status") or "unknown")
        result = AtlasVoiceTurnResult(
            status=status,
            session_id=session_id,
            turn_id=turn_id,
            kernel_response=response,
        )
        self._turns[self._key(session_id, turn_id)] = result

        return result

    def report_wake_word(self, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        return self.client.report_wake_word(payload)

    def report_synthesized(self, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        self._assert_turn_can_emit_runtime_callback(payload)

        return self.client.report_synthesized(payload)

    def report_played(self, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        self._assert_turn_can_emit_runtime_callback(payload)

        return self.client.report_played(payload)

    def report_interrupted(self, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        self._assert_known_turn(payload)

        return self.client.report_interrupted(payload)

    def report_failed(self, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        self._assert_turn_can_emit_runtime_callback(payload)

        return self.client.report_failed(payload)

    def report_provider_health_degraded(self, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        self._assert_turn_can_emit_runtime_callback(payload)

        return self.client.report_provider_health_degraded(payload)

    def _assert_turn_can_emit_runtime_callback(self, payload: Mapping[str, Any]) -> None:
        result = self._assert_known_turn(payload)
        if result.blocked:
            raise AtlasVoiceRuntimeError("blocked turn cannot emit synthesis or playback callbacks")
        if not result.accepted or result.decision_receipt is None:
            raise AtlasVoiceRuntimeError("Kernel decision receipt is required before runtime callback")

    def _assert_known_turn(self, payload: Mapping[str, Any]) -> AtlasVoiceTurnResult:
        session_id = str(payload.get("session_id") or "")
        turn_id = str(payload.get("turn_id") or "")
        if session_id == "" or turn_id == "":
            raise UnsafeVoicePayload("session_id and turn_id are required")

        key = self._key(session_id, turn_id)
        if key not in self._turns:
            raise AtlasVoiceRuntimeError("turn must be submitted to Kernel before callback")

        return self._turns[key]

    @staticmethod
    def _key(session_id: str, turn_id: str) -> str:
        return f"{session_id}:{turn_id}"
