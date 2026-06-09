from __future__ import annotations

from typing import Any, Mapping

from .packet_validation import PacketValidator


SCHEMA_VERSION = "atlas.voice_realtime.product_loop_check.v1"
FORBIDDEN_PRODUCT_LOOP_KEYS = {
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
    "response_text",
    "token",
    "tool_args",
    "tool_call",
    "tts_text",
    "wav",
}


class ProductLoopCheckViolation(RuntimeError):
    """Raised when a Kernel product-loop check is unsafe to consume."""


VALIDATOR = PacketValidator(ProductLoopCheckViolation)


def validate_product_loop_check(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    VALIDATOR.reject_forbidden(payload, FORBIDDEN_PRODUCT_LOOP_KEYS, label="Voice product-loop check")

    VALIDATOR.expect("schema_version", payload.get("schema_version"), SCHEMA_VERSION)
    VALIDATOR.expect("surface_id", payload.get("surface_id"), "voice_realtime")
    VALIDATOR.expect("runtime_id", payload.get("runtime_id"), "livekit_agents_sdk")
    VALIDATOR.expect("kernel_only", payload.get("kernel_only"), True)
    VALIDATOR.expect("mobile_first", payload.get("mobile_first"), True)
    VALIDATOR.expect("daemon_started", payload.get("daemon_started"), False)

    status = payload.get("status")
    if status not in [
        "blocked",
        "ready_for_human_review",
        "ready_for_daemon_implementation_review",
        "ready_for_supervised_start_implementation",
    ]:
        raise ProductLoopCheckViolation(f"status must be a governed voice product-loop state, got {status!r}")

    gates = VALIDATOR.expect_mapping("gates", payload.get("gates"))
    for key in [
        "callback_loop_wired",
        "production_sdk_loop_wired",
        "worker_start_still_blocked",
        "production_promotion_blocked",
        "direct_provider_forbidden",
        "raw_audio_forbidden",
    ]:
        VALIDATOR.expect_bool(f"gates.{key}", gates.get(key))
    for key in [
        "worker_start_still_blocked",
        "production_promotion_blocked",
        "direct_provider_forbidden",
        "raw_audio_forbidden",
    ]:
        VALIDATOR.expect(f"gates.{key}", gates.get(key), True)
    if status != "blocked":
        VALIDATOR.expect("gates.callback_loop_wired", gates.get("callback_loop_wired"), True)
        VALIDATOR.expect("gates.production_sdk_loop_wired", gates.get("production_sdk_loop_wired"), True)

    guardrails = VALIDATOR.expect_mapping("guardrails", payload.get("guardrails"))
    for key in [
        "direct_provider_call_allowed",
        "direct_tool_execution_allowed",
        "raw_audio_persistence_allowed",
        "access_token_log_allowed",
        "auto_promotion_allowed",
    ]:
        VALIDATOR.expect(f"guardrails.{key}", guardrails.get(key), False)

    next_action = payload.get("next_action")
    if not (isinstance(next_action, str) and next_action.strip() != ""):
        raise ProductLoopCheckViolation("next_action must be a non-empty string")

    return payload
