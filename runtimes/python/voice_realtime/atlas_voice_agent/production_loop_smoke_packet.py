from __future__ import annotations

from typing import Any, Mapping

from .packet_validation import PacketValidator


SCHEMA_VERSION = "atlas.voice_realtime.production_loop_smoke.v1"
FORBIDDEN_PRODUCTION_LOOP_KEYS = {
    "access_token",
    "api_key",
    "api_secret",
    "audio",
    "audio_bytes",
    "audio_raw",
    "livekit_token",
    "provider_api_key",
    "raw_audio",
    "raw_audio_bytes",
    "raw_response_text",
    "raw_transcript",
    "response_text",
    "token",
    "tool_args",
    "tool_call",
    "transcript",
    "tts_text",
    "wav",
}


class ProductionLoopSmokeViolation(RuntimeError):
    """Raised when the production-loop smoke payload is unsafe to publish."""


VALIDATOR = PacketValidator(ProductionLoopSmokeViolation)


def validate_production_loop_smoke(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    VALIDATOR.reject_forbidden(payload, FORBIDDEN_PRODUCTION_LOOP_KEYS, label="Voice production-loop smoke")

    VALIDATOR.expect("schema_version", payload.get("schema_version"), SCHEMA_VERSION)
    VALIDATOR.expect("status", payload.get("status"), "production_loop_smoke_completed")
    VALIDATOR.expect("kernel_only", payload.get("kernel_only"), True)
    VALIDATOR.expect("mobile_first", payload.get("mobile_first"), True)
    VALIDATOR.expect("daemon_started", payload.get("daemon_started"), False)
    VALIDATOR.expect("sdk_imported", payload.get("sdk_imported"), False)
    VALIDATOR.expect("active_session_count", payload.get("active_session_count"), 0)

    event_count = VALIDATOR.expect_int("event_count", payload.get("event_count"))
    result_count = VALIDATOR.expect_int("result_count", payload.get("result_count"))
    if event_count <= 0:
        raise ProductionLoopSmokeViolation("event_count must be positive")
    if result_count != event_count:
        raise ProductionLoopSmokeViolation("result_count must match event_count")

    for path in [
        "bridge_contract_report",
        "handler_registry_contract_report",
        "kernel_normalizer_contract_report",
        "worker_return_contract",
    ]:
        report = VALIDATOR.expect_mapping(path, payload.get(path))
        VALIDATOR.expect(f"{path}.status", report.get("status"), "valid")

    guardrails = VALIDATOR.expect_mapping("guardrails", payload.get("guardrails"))
    for key in [
        "direct_provider_call_allowed",
        "direct_tool_execution_allowed",
        "raw_audio_persistence_allowed",
        "access_token_log_allowed",
    ]:
        VALIDATOR.expect(f"guardrails.{key}", guardrails.get(key), False)

    results = payload.get("results")
    if not isinstance(results, list):
        raise ProductionLoopSmokeViolation("results must be a list")
    if len(results) != result_count:
        raise ProductionLoopSmokeViolation("results length must match result_count")

    return payload
