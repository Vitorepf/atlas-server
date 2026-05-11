from __future__ import annotations

from typing import Any, Callable, Mapping

from .livekit_sdk_adapter import LiveKitSdkAdapter
from .livekit_worker import LiveKitWorkerResult
from .payload_safety import reject_forbidden_keys_recursive
from .turn_payload import UnsafeVoicePayload


class LiveKitCallbackRouter:
    """Single dispatch point for LiveKit Agents SDK callbacks.

    Future SDK code should translate raw SDK objects into plain mappings and
    call this router. That keeps callback naming, fail-closed behavior and
    token-safe logging in one place.
    """

    def __init__(self, adapter: LiveKitSdkAdapter) -> None:
        self.adapter = adapter

    def route(self, event: Mapping[str, Any]) -> LiveKitWorkerResult:
        reject_forbidden_keys_recursive(event, FORBIDDEN_CALLBACK_KEYS, label="LiveKit callback router event")
        callback_kind = _required(event, "callback_kind")
        payload = event.get("payload")
        if not isinstance(payload, Mapping):
            raise UnsafeVoicePayload("payload must be a JSON object")

        handlers: dict[str, Callable[[Mapping[str, Any]], LiveKitWorkerResult]] = {
            "participant_joined": self.adapter.on_participant_joined,
            "transcript_final": self.adapter.on_transcript_final,
            "wake_word_detected": self.adapter.on_wake_word_detected,
            "tts_synthesized": self.adapter.on_tts_synthesized,
            "audio_played": self.adapter.on_audio_played,
            "barge_in": self.adapter.on_barge_in,
            "runtime_failed": self.adapter.on_runtime_failed,
            "provider_health_degraded": self.adapter.on_provider_health_degraded,
            "participant_left": self.adapter.on_participant_left,
        }

        handler = handlers.get(callback_kind)
        if handler is None:
            raise UnsafeVoicePayload(f"unsupported LiveKit callback_kind: {callback_kind}")

        try:
            return handler(payload)
        except UnsafeVoicePayload:
            raise
        except (RuntimeError, ValueError) as exc:
            raise UnsafeVoicePayload(str(exc)) from exc

    @staticmethod
    def supported_callbacks() -> list[str]:
        return [
            "participant_joined",
            "transcript_final",
            "wake_word_detected",
            "tts_synthesized",
            "audio_played",
            "barge_in",
            "runtime_failed",
            "provider_health_degraded",
            "participant_left",
        ]


FORBIDDEN_CALLBACK_KEYS = {
    "access_token",
    "api_key",
    "api_secret",
    "audio",
    "audio_bytes",
    "audio_raw",
    "direct_llm_provider_call",
    "direct_provider_call",
    "direct_tool_execution",
    "livekit_token",
    "memory_write",
    "pcm",
    "provider_api_key",
    "raw_audio",
    "raw_audio_bytes",
    "raw_response_text",
    "response_text",
    "token",
    "tool_args",
    "tool_call",
    "tts_text",
    "wav",
}


def _required(event: Mapping[str, Any], key: str) -> str:
    value = str(event.get(key) or "").strip()
    if value == "":
        raise UnsafeVoicePayload(f"{key} is required")

    return value
