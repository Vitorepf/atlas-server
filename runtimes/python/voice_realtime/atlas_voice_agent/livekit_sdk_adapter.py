from __future__ import annotations

from typing import Any, Mapping

from .livekit_worker import AtlasLiveKitWorker, LiveKitWorkerResult
from .payload_safety import reject_forbidden_keys_recursive
from .turn_payload import UnsafeVoicePayload


class LiveKitSdkAdapter:
    """Translation shell for the real LiveKit Agents SDK.

    This class deliberately imports no LiveKit package. The SDK integration
    should call these methods from its room/participant/turn callbacks, keeping
    AtlasLiveKitWorker as the only path into the Kernel.
    """

    def __init__(self, worker: AtlasLiveKitWorker) -> None:
        self.worker = worker

    def on_participant_joined(self, event: Mapping[str, Any]) -> LiveKitWorkerResult:
        _reject_forbidden_sdk_fields(event)

        return self.worker.start_session({
            "event_kind": "start_session",
            "session_id": _required(event, "session_id"),
            "participant_identity": _required(event, "participant_identity"),
            "room_name": _required(event, "room_name"),
            "client_surface": str(event.get("client_surface") or "mobile"),
            "transport": str(event.get("transport") or "livekit_webrtc"),
            "privacy_class": str(event.get("privacy_class") or "p3_audio"),
            "rivals_arm": str(event.get("rivals_arm") or "atlas_voice"),
        })

    def on_transcript_final(self, event: Mapping[str, Any]) -> LiveKitWorkerResult:
        _reject_forbidden_sdk_fields(event)

        return self.worker.handle_event({
            "event_kind": "transcribed_turn",
            "session_id": _required(event, "session_id"),
            "turn_id": _required(event, "turn_id"),
            "transcript": _required(event, "transcript"),
            "language": str(event.get("language") or "pt-BR"),
            "domain_hint": str(event.get("domain_hint") or "general"),
            "flow_hint": str(event.get("flow_hint") or "general.answer"),
        })

    def on_wake_word_detected(self, event: Mapping[str, Any]) -> LiveKitWorkerResult:
        _reject_forbidden_sdk_fields(event)

        return self.worker.handle_event(_drop_none({
            "event_kind": "wake_word_detected",
            "session_id": _required(event, "session_id"),
            "wake_word_engine": str(event.get("wake_word_engine") or "mobile_local"),
            "confidence": event.get("confidence"),
            "latency_ms": event.get("latency_ms"),
        }))

    def on_tts_synthesized(self, event: Mapping[str, Any]) -> LiveKitWorkerResult:
        _reject_forbidden_sdk_fields(event)

        payload = {
            "event_kind": "synthesized",
            "session_id": _required(event, "session_id"),
            "turn_id": _required(event, "turn_id"),
            "response_text_hash": event.get("response_text_hash"),
            "audio_hash": event.get("audio_hash"),
            "audio_duration_ms": event.get("audio_duration_ms"),
            "tts_provider": event.get("tts_provider"),
            "provider": event.get("provider"),
            "model": event.get("model"),
            "latency_ms": event.get("latency_ms"),
        }

        return self.worker.handle_event(_drop_none(payload))

    def on_audio_played(self, event: Mapping[str, Any]) -> LiveKitWorkerResult:
        _reject_forbidden_sdk_fields(event)

        return self.worker.handle_event(_drop_none({
            "event_kind": "played",
            "session_id": _required(event, "session_id"),
            "turn_id": _required(event, "turn_id"),
            "played_duration_ms": event.get("played_duration_ms"),
            "latency_ms": event.get("latency_ms"),
        }))

    def on_barge_in(self, event: Mapping[str, Any]) -> LiveKitWorkerResult:
        _reject_forbidden_sdk_fields(event)

        return self.worker.handle_event(_drop_none({
            "event_kind": "interrupted",
            "session_id": _required(event, "session_id"),
            "turn_id": _required(event, "turn_id"),
            "reason": str(event.get("reason") or "operator_started_speaking"),
            "interrupted_stage": str(event.get("interrupted_stage") or "tts_streaming"),
            "played_duration_ms": event.get("played_duration_ms"),
            "latency_ms": event.get("latency_ms"),
        }))

    def on_runtime_failed(self, event: Mapping[str, Any]) -> LiveKitWorkerResult:
        _reject_forbidden_sdk_fields(event)

        return self.worker.handle_event(_drop_none({
            "event_kind": "failed",
            "session_id": _required(event, "session_id"),
            "turn_id": _required(event, "turn_id"),
            "failure_code": str(event.get("failure_code") or "runtime_failed"),
            "error_class": event.get("error_class"),
            "error_message_hash": event.get("error_message_hash"),
            "latency_ms": event.get("latency_ms"),
        }))

    def on_provider_health_degraded(self, event: Mapping[str, Any]) -> LiveKitWorkerResult:
        _reject_forbidden_sdk_fields(event)

        return self.worker.handle_event(_drop_none({
            "event_kind": "provider_health_degraded",
            "session_id": _required(event, "session_id"),
            "turn_id": _required(event, "turn_id"),
            "provider": _required(event, "provider"),
            "reason": str(event.get("reason") or "provider_degraded"),
            "latency_ms": event.get("latency_ms"),
        }))

    def on_participant_left(self, event: Mapping[str, Any]) -> LiveKitWorkerResult:
        _reject_forbidden_sdk_fields(event)

        return self.worker.handle_event({
            "event_kind": "session_ended",
            "session_id": _required(event, "session_id"),
            "reason": str(event.get("reason") or "participant_left"),
        })


def _required(event: Mapping[str, Any], key: str) -> str:
    value = str(event.get(key) or "").strip()
    if value == "":
        raise UnsafeVoicePayload(f"{key} is required")

    return value


def _reject_forbidden_sdk_fields(event: Mapping[str, Any]) -> None:
    forbidden = {
        "audio",
        "audio_bytes",
        "audio_raw",
        "raw_audio",
        "raw_audio_bytes",
        "pcm",
        "wav",
        "response_text",
        "raw_response_text",
        "tts_text",
        "llm_provider",
        "provider_api_key",
        "tool_call",
        "tool_args",
        "direct_provider_call",
        "direct_tool_execution",
        "memory_write",
        "access_token",
        "token",
        "livekit_token",
        "api_key",
        "api_secret",
    }
    reject_forbidden_keys_recursive(event, forbidden, label="LiveKit SDK callback")


def _drop_none(payload: Mapping[str, Any]) -> dict[str, Any]:
    return {key: value for key, value in payload.items() if value is not None}
