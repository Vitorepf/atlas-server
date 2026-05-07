from __future__ import annotations


REQUIRED_CALLBACK_METHODS: dict[str, str] = {
    "participant_joined": "on_participant_joined",
    "transcript_final": "on_transcript_final",
    "wake_word_detected": "on_wake_word_detected",
    "tts_synthesized": "on_tts_synthesized",
    "audio_played": "on_audio_played",
    "barge_in": "on_barge_in",
    "runtime_failed": "on_runtime_failed",
    "provider_health_degraded": "on_provider_health_degraded",
    "participant_left": "on_participant_left",
}


REQUIRED_CALLBACK_PAYLOAD_SCHEMAS: dict[str, dict[str, list[str]]] = {
    "participant_joined": {
        "required": ["session_id", "participant_identity", "room_name"],
        "optional": ["client_surface", "transport", "privacy_class", "rivals_arm"],
        "prohibited": ["access_token", "token", "livekit_token", "api_key", "api_secret", "raw_audio", "audio_bytes"],
    },
    "transcript_final": {
        "required": ["session_id", "turn_id", "transcript"],
        "optional": ["language", "domain_hint", "flow_hint"],
        "prohibited": ["raw_audio", "audio_bytes", "tool_call", "tool_args", "llm_provider", "provider_api_key"],
    },
    "wake_word_detected": {
        "required": ["session_id"],
        "optional": ["wake_word_engine", "confidence", "latency_ms"],
        "prohibited": ["raw_audio", "audio_bytes", "pcm", "wav"],
    },
    "tts_synthesized": {
        "required": ["session_id", "turn_id"],
        "optional": ["response_text_hash", "audio_hash", "audio_duration_ms", "tts_provider", "provider", "model", "latency_ms"],
        "prohibited": ["response_text", "raw_response_text", "tts_text", "raw_audio", "audio_bytes"],
    },
    "audio_played": {
        "required": ["session_id", "turn_id"],
        "optional": ["played_duration_ms", "latency_ms"],
        "prohibited": ["raw_audio", "audio_bytes", "pcm", "wav"],
    },
    "barge_in": {
        "required": ["session_id", "turn_id"],
        "optional": ["reason", "interrupted_stage", "latency_ms"],
        "prohibited": ["raw_audio", "audio_bytes", "pcm", "wav"],
    },
    "runtime_failed": {
        "required": ["session_id", "turn_id"],
        "optional": ["failure_code", "error_class", "latency_ms"],
        "prohibited": ["raw_audio", "audio_bytes", "response_text", "tool_call", "tool_args"],
    },
    "provider_health_degraded": {
        "required": ["session_id", "turn_id", "provider"],
        "optional": ["reason", "latency_ms"],
        "prohibited": ["provider_api_key", "api_key", "api_secret", "token"],
    },
    "participant_left": {
        "required": ["session_id"],
        "optional": ["reason"],
        "prohibited": ["access_token", "token", "livekit_token", "api_key", "api_secret"],
    },
}
