from __future__ import annotations

from typing import Any, Mapping

from .livekit_callback_router import LiveKitCallbackRouter
from .livekit_worker import LiveKitWorkerResult
from .payload_safety import reject_forbidden_keys_recursive
from .turn_payload import UnsafeVoicePayload


class LiveKitSdkEventBridge:
    """Normalize raw SDK-ish mappings before they reach CallbackRouter.

    The real LiveKit Agents SDK exposes objects, not Atlas payloads. This
    bridge is the only allowed translation boundary: SDK code should extract
    primitive values, call `to_callback_event`, and route the resulting event.
    """

    EVENT_TO_CALLBACK = {
        "room_connected": "participant_joined",
        "participant_joined": "participant_joined",
        "transcript_final": "transcript_final",
        "wake_word_detected": "wake_word_detected",
        "tts_synthesized": "tts_synthesized",
        "audio_played": "audio_played",
        "barge_in": "barge_in",
        "runtime_failed": "runtime_failed",
        "provider_health_degraded": "provider_health_degraded",
        "participant_left": "participant_left",
        "room_disconnected": "participant_left",
    }

    REQUIRED_KEYS = {
        "participant_joined": ["session_id", "participant_identity", "room_name"],
        "transcript_final": ["session_id", "turn_id", "transcript"],
        "wake_word_detected": ["session_id"],
        "tts_synthesized": ["session_id", "turn_id"],
        "audio_played": ["session_id", "turn_id"],
        "barge_in": ["session_id", "turn_id"],
        "runtime_failed": ["session_id", "turn_id", "error_message_hash"],
        "provider_health_degraded": ["session_id", "turn_id", "provider"],
        "participant_left": ["session_id"],
    }

    ALLOWED_PAYLOAD_KEYS = {
        "participant_joined": [
            "session_id",
            "participant_identity",
            "room_name",
            "client_surface",
            "transport",
            "privacy_class",
            "rivals_arm",
        ],
        "transcript_final": [
            "session_id",
            "turn_id",
            "transcript",
            "language",
            "domain_hint",
            "flow_hint",
        ],
        "wake_word_detected": [
            "session_id",
            "wake_word_engine",
            "confidence",
            "latency_ms",
        ],
        "tts_synthesized": [
            "session_id",
            "turn_id",
            "response_text_hash",
            "audio_hash",
            "audio_duration_ms",
            "tts_provider",
            "provider",
            "model",
            "latency_ms",
        ],
        "audio_played": ["session_id", "turn_id", "played_duration_ms", "latency_ms"],
        "barge_in": ["session_id", "turn_id", "reason", "interrupted_stage", "played_duration_ms", "latency_ms"],
        "runtime_failed": ["session_id", "turn_id", "failure_code", "error_class", "error_message_hash", "latency_ms"],
        "provider_health_degraded": ["session_id", "turn_id", "provider", "reason", "latency_ms"],
        "participant_left": ["session_id", "reason"],
    }

    FORBIDDEN_KEYS = {
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

    def __init__(self, router: LiveKitCallbackRouter) -> None:
        self.router = router

    def route_sdk_event(self, event: Mapping[str, Any]) -> LiveKitWorkerResult:
        return self.router.route(self.to_callback_event(event))

    @classmethod
    def to_callback_event(cls, event: Mapping[str, Any]) -> dict[str, Any]:
        event_kind = _required(event, "event_kind")
        callback_kind = cls.EVENT_TO_CALLBACK.get(event_kind)
        if callback_kind is None:
            raise UnsafeVoicePayload(f"unsupported LiveKit SDK event_kind: {event_kind}")

        reject_forbidden_keys_recursive(event, cls.FORBIDDEN_KEYS, label="LiveKit SDK event")

        payload = {
            key: event[key]
            for key in cls.ALLOWED_PAYLOAD_KEYS[callback_kind]
            if key in event and event[key] is not None
        }
        for key in cls.REQUIRED_KEYS[callback_kind]:
            if str(payload.get(key) or "").strip() == "":
                raise UnsafeVoicePayload(f"{key} is required for {callback_kind}")

        return {
            "callback_kind": callback_kind,
            "payload": payload,
        }

    @classmethod
    def contract(cls) -> dict[str, Any]:
        return {
            "schema_version": "atlas.voice_realtime.sdk_event_bridge.v1",
            "status": "ready",
            "kernel_only": True,
            "supported_event_kinds": list(cls.EVENT_TO_CALLBACK.keys()),
            "supported_callbacks": LiveKitCallbackRouter.supported_callbacks(),
            "event_to_callback": dict(cls.EVENT_TO_CALLBACK),
            "required_keys": {key: list(value) for key, value in cls.REQUIRED_KEYS.items()},
            "allowed_payload_keys": {key: list(value) for key, value in cls.ALLOWED_PAYLOAD_KEYS.items()},
            "forbidden_keys": sorted(cls.FORBIDDEN_KEYS),
            "guardrails": {
                "direct_provider_call_allowed": False,
                "direct_tool_execution_allowed": False,
                "raw_audio_persistence_allowed": False,
                "access_token_log_allowed": False,
            },
        }


def _required(event: Mapping[str, Any], key: str) -> str:
    value = str(event.get(key) or "").strip()
    if value == "":
        raise UnsafeVoicePayload(f"{key} is required")

    return value
