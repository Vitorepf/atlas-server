from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Mapping

from .agent_runtime import AtlasVoiceTurnResult
from .livekit_boundary import LiveKitAgentBoundary
from .livekit_session import LiveKitVoiceSession
from .payload_safety import reject_forbidden_keys_recursive
from .turn_payload import UnsafeVoicePayload


class LiveKitWorkerError(RuntimeError):
    """Raised when a LiveKit worker event would bypass the Kernel contract."""


@dataclass(frozen=True)
class LiveKitWorkerResult:
    event_kind: str
    session_id: str
    status: str
    payload: Mapping[str, Any]

    def log_payload(self) -> dict[str, Any]:
        return {
            "event_kind": self.event_kind,
            "session_id": self.session_id,
            "status": self.status,
            "payload": _sanitize_for_log(self.payload),
        }


class AtlasLiveKitWorker:
    """Dependency-light worker loop boundary for LiveKit Agents SDK.

    The real SDK should translate room, participant and turn callbacks into
    these event kinds. This class keeps sessions governed by the Kernel and
    returns only token-free log payloads.
    """

    def __init__(self, boundary: LiveKitAgentBoundary) -> None:
        self.boundary = boundary
        self._sessions: dict[str, LiveKitVoiceSession] = {}
        self._accepted_turns: dict[str, set[str]] = {}

    def start_session(self, event: Mapping[str, Any]) -> LiveKitWorkerResult:
        session = LiveKitVoiceSession.start(self.boundary, event)
        self._sessions[session.session_id] = session
        self._accepted_turns[session.session_id] = set()

        return LiveKitWorkerResult(
            event_kind="session_started",
            session_id=session.session_id,
            status="ready",
            payload=session.log_payload(),
        )

    def process_scripted_events(self, events: list[Mapping[str, Any]]) -> list[LiveKitWorkerResult]:
        if not events:
            raise LiveKitWorkerError("scripted worker events cannot be empty")

        results: list[LiveKitWorkerResult] = []
        for event in events:
            event_kind = self._required(event, "event_kind")
            if event_kind == "start_session":
                results.append(self.start_session(event))
                continue

            results.append(self.handle_event(event))

        return results

    def handle_event(self, event: Mapping[str, Any]) -> LiveKitWorkerResult:
        _reject_forbidden_worker_fields(event)
        event_kind = self._required(event, "event_kind")
        session_id = self._required(event, "session_id")
        session = self._session(session_id)

        if event_kind == "transcribed_turn":
            result = session.submit_transcribed_turn(event)
            if result.accepted and result.decision_receipt is not None:
                self._accepted_turns.setdefault(session_id, set()).add(self._required(event, "turn_id"))

            return self._turn_result(session, result)
        if event_kind == "wake_word_detected":
            return self._callback_result(event_kind, session, session.report_wake_word(event))
        if event_kind == "synthesized":
            self._require_accepted_turn(session_id, event)
            return self._callback_result(event_kind, session, session.report_synthesized(event))
        if event_kind == "played":
            self._require_accepted_turn(session_id, event)
            return self._callback_result(event_kind, session, session.report_played(event))
        if event_kind == "interrupted":
            self._require_accepted_turn(session_id, event)
            return self._callback_result(event_kind, session, session.report_interrupted(event))
        if event_kind == "failed":
            self._require_accepted_turn(session_id, event)
            return self._callback_result(event_kind, session, session.report_failed(event))
        if event_kind == "provider_health_degraded":
            self._require_accepted_turn(session_id, event)
            return self._callback_result(event_kind, session, session.report_provider_health(event))
        if event_kind == "session_ended":
            reason = str(event.get("reason") or "operator_finished")
            response = session.end(reason=reason)
            self._sessions.pop(session_id, None)
            self._accepted_turns.pop(session_id, None)

            return self._callback_result(event_kind, session, response)

        raise LiveKitWorkerError(f"unsupported LiveKit worker event_kind: {event_kind}")

    def active_session_count(self) -> int:
        return len(self._sessions)

    def accepted_turn_count(self, session_id: str) -> int:
        return len(self._accepted_turns.get(session_id, set()))

    def _session(self, session_id: str) -> LiveKitVoiceSession:
        if session_id not in self._sessions:
            raise LiveKitWorkerError("session must be started through Kernel before worker events")

        return self._sessions[session_id]

    def _require_accepted_turn(self, session_id: str, event: Mapping[str, Any]) -> None:
        turn_id = self._required(event, "turn_id")
        if turn_id not in self._accepted_turns.get(session_id, set()):
            raise LiveKitWorkerError("turn must be accepted by Kernel Decision Receipt before runtime callback")

    @staticmethod
    def _turn_result(session: LiveKitVoiceSession, result: AtlasVoiceTurnResult) -> LiveKitWorkerResult:
        receipt = result.decision_receipt or {}

        return LiveKitWorkerResult(
            event_kind="transcribed_turn",
            session_id=session.session_id,
            status=result.status,
            payload={
                "accepted": result.accepted,
                "blocked": result.blocked,
                "receipt_id": receipt.get("receipt_id"),
                "dry_run": receipt.get("dry_run"),
            },
        )

    @staticmethod
    def _callback_result(
        event_kind: str,
        session: LiveKitVoiceSession,
        response: Mapping[str, Any],
    ) -> LiveKitWorkerResult:
        return LiveKitWorkerResult(
            event_kind=event_kind,
            session_id=session.session_id,
            status=str(response.get("status") or "ok"),
            payload=response,
        )

    @staticmethod
    def _required(event: Mapping[str, Any], key: str) -> str:
        value = str(event.get(key) or "").strip()
        if value == "":
            raise UnsafeVoicePayload(f"{key} is required")

        return value


def _sanitize_for_log(value: Any) -> Any:
    forbidden = {
        "access_token",
        "token",
        "livekit_token",
        "api_key",
        "api_secret",
        "response_text",
        "raw_response_text",
        "tts_text",
        "raw_audio",
        "audio_bytes",
        "raw_audio_bytes",
    }
    if isinstance(value, Mapping):
        return {
            str(key): _sanitize_for_log(item)
            for key, item in value.items()
            if str(key) not in forbidden
        }
    if isinstance(value, list):
        return [_sanitize_for_log(item) for item in value]

    return value


def _reject_forbidden_worker_fields(event: Mapping[str, Any]) -> None:
    forbidden = {
        "access_token",
        "token",
        "livekit_token",
        "api_key",
        "api_secret",
        "response_text",
        "raw_response_text",
        "tts_text",
        "raw_audio",
        "audio_bytes",
        "raw_audio_bytes",
        "audio_raw",
        "pcm",
        "wav",
        "direct_llm_provider_call",
        "direct_tool_execution",
        "tool_call",
    }
    reject_forbidden_keys_recursive(event, forbidden, label="LiveKit worker event")
