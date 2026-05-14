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
        self._accepted_turn_receipt_hashes: dict[str, str] = {}

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
                turn_id = self._required(event, "turn_id")
                self._accepted_turns.setdefault(session_id, set()).add(turn_id)
                receipt_hash = str(result.decision_receipt.get("receipt_hash") or "").strip()
                if receipt_hash != "":
                    self._accepted_turn_receipt_hashes[self._turn_key(session_id, turn_id)] = receipt_hash

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
            for key in [key for key in self._accepted_turn_receipt_hashes if key.startswith(f"{session_id}:")]:
                self._accepted_turn_receipt_hashes.pop(key, None)

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

    def _accepted_turn_receipt_hash(self, session_id: str, turn_id: str) -> str | None:
        return self._accepted_turn_receipt_hashes.get(self._turn_key(session_id, turn_id))

    @staticmethod
    def _turn_result(session: LiveKitVoiceSession, result: AtlasVoiceTurnResult) -> LiveKitWorkerResult:
        receipt = result.decision_receipt or {}
        kernel_response = result.kernel_response
        envelope_id = _string_or_none(_deep_get(kernel_response, ["turn", "operation_envelope", "envelope_id"]))
        receipt_hash = _string_or_none(receipt.get("receipt_hash"))
        artifacts: dict[str, Any] = {}
        # ADR 0002 Option B: preserve `transient` mapping (e.g. `tts_input_text`)
        # so the runtime LLM bridge can hand it off to TTS in-memory. Forbidden
        # keys (`response_text`, `raw_response_text`, `tts_text`) are still
        # rejected by `_reject_forbidden_worker_return_fields`.
        transient = kernel_response.get("transient") if isinstance(kernel_response, Mapping) else None
        if isinstance(transient, Mapping):
            artifacts["transient"] = dict(transient)
        payload = _worker_return_payload({
            "schema_version": "atlas.voice_realtime.worker_return.v1",
            "envelope_id": envelope_id,
            "decision_receipt_hash": receipt_hash,
            "accepted": result.accepted,
            "blocked": result.blocked,
            "receipt_id": receipt.get("receipt_id"),
            "dry_run": receipt.get("dry_run"),
            "artifacts": artifacts,
            "metrics": {},
            "evidence_refs": _evidence_refs(kernel_response.get("evidence_ledger")),
            "errors": [],
        })

        return LiveKitWorkerResult(
            event_kind="transcribed_turn",
            session_id=session.session_id,
            status=result.status,
            payload=payload,
        )

    def _callback_result(
        self,
        event_kind: str,
        session: LiveKitVoiceSession,
        response: Mapping[str, Any],
    ) -> LiveKitWorkerResult:
        turn_id = _string_or_none(_deep_get(response, ["turn", "turn_id"]))
        status = str(response.get("status") or "ok")
        payload = _worker_return_payload({
            "schema_version": "atlas.voice_realtime.worker_return.v1",
            "envelope_id": _string_or_none(response.get("envelope_id")),
            "decision_receipt_hash": self._accepted_turn_receipt_hash(session.session_id, turn_id) if turn_id else None,
            "artifacts": {"kernel_response": response},
            "metrics": {},
            "evidence_refs": _evidence_refs(response.get("evidence_ledger")),
            "errors": [] if status.endswith("recorded") or status in {"ok", "session_ended_scaffold"} else [status],
        })

        return LiveKitWorkerResult(
            event_kind=event_kind,
            session_id=session.session_id,
            status=status,
            payload=payload,
        )

    @staticmethod
    def _required(event: Mapping[str, Any], key: str) -> str:
        value = str(event.get(key) or "").strip()
        if value == "":
            raise UnsafeVoicePayload(f"{key} is required")

        return value

    @staticmethod
    def _turn_key(session_id: str, turn_id: str) -> str:
        return f"{session_id}:{turn_id}"


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
        "tool_call",
        "tool_args",
        "provider_api_key",
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


def _deep_get(value: Mapping[str, Any], path: list[str]) -> Any:
    current: Any = value
    for key in path:
        if not isinstance(current, Mapping):
            return None
        current = current.get(key)

    return current


def _string_or_none(value: Any) -> str | None:
    text = str(value or "").strip()

    return text if text != "" else None


def _evidence_refs(value: Any) -> list[str]:
    refs: list[str] = []

    def visit(item: Any) -> None:
        if isinstance(item, Mapping):
            event_id = item.get("event_id")
            if isinstance(event_id, str) and event_id.strip() != "":
                refs.append(event_id)
            for child in item.values():
                visit(child)
        elif isinstance(item, list):
            for child in item:
                visit(child)

    visit(value)

    return sorted(set(refs))


def _worker_return_payload(payload: Mapping[str, Any]) -> dict[str, Any]:
    required_keys = {
        "schema_version",
        "envelope_id",
        "decision_receipt_hash",
        "artifacts",
        "metrics",
        "evidence_refs",
        "errors",
    }
    missing = sorted(required_keys - set(payload.keys()))
    if missing:
        raise LiveKitWorkerError(f"worker return contract missing required keys: {', '.join(missing)}")
    if payload.get("schema_version") != "atlas.voice_realtime.worker_return.v1":
        raise LiveKitWorkerError("worker return contract schema_version is invalid")
    if not isinstance(payload.get("artifacts"), Mapping):
        raise LiveKitWorkerError("worker return contract artifacts must be a mapping")
    if not isinstance(payload.get("metrics"), Mapping):
        raise LiveKitWorkerError("worker return contract metrics must be a mapping")
    if not _is_string_list(payload.get("evidence_refs")):
        raise LiveKitWorkerError("worker return contract evidence_refs must be a list of strings")
    if not _is_string_list(payload.get("errors")):
        raise LiveKitWorkerError("worker return contract errors must be a list of strings")

    _reject_forbidden_worker_return_fields(payload)

    return dict(payload)


def _is_string_list(value: Any) -> bool:
    return isinstance(value, list) and all(isinstance(item, str) for item in value)


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
        "direct_provider_call",
        "direct_tool_execution",
        "memory_write",
        "tool_call",
        "tool_args",
        "provider_api_key",
    }
    reject_forbidden_keys_recursive(event, forbidden, label="LiveKit worker event")


def _reject_forbidden_worker_return_fields(payload: Mapping[str, Any]) -> None:
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
        "direct_provider_call",
        "direct_tool_execution",
        "memory_write",
        "tool_call",
        "tool_args",
        "provider_api_key",
    }
    reject_forbidden_keys_recursive(payload, forbidden, label="LiveKit worker return")
