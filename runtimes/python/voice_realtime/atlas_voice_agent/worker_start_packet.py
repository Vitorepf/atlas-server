from __future__ import annotations

from typing import Any, Mapping

from .packet_validation import PacketValidator


class WorkerStartPacketViolation(RuntimeError):
    """Raised when a worker-start packet would relax voice runtime guardrails."""


VALIDATOR = PacketValidator(WorkerStartPacketViolation)

FORBIDDEN_WORKER_START_KEYS = {
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


def validate_worker_start_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    VALIDATOR.reject_forbidden(payload, FORBIDDEN_WORKER_START_KEYS, label="worker_start")

    VALIDATOR.expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.worker_start.v1")
    VALIDATOR.expect("surface_id", payload.get("surface_id"), "voice_realtime")
    VALIDATOR.expect("runtime_id", payload.get("runtime_id"), "livekit_agents_sdk")
    VALIDATOR.expect("kernel_only", payload.get("kernel_only"), True)
    VALIDATOR.expect("mobile_first", payload.get("mobile_first"), True)
    VALIDATOR.expect("started", payload.get("started"), False)
    VALIDATOR.expect_bool("callback_loop_wired", payload.get("callback_loop_wired"))
    VALIDATOR.expect_bool("production_sdk_loop_wired", payload.get("production_sdk_loop_wired"))
    VALIDATOR.expect_bool("production_promotion_review_valid", payload.get("production_promotion_review_valid"))
    VALIDATOR.expect_bool("daemon_implementation_review_valid", payload.get("daemon_implementation_review_valid"))
    VALIDATOR.expect_mapping("worker_plan", payload.get("worker_plan"))
    VALIDATOR.expect_mapping("activation_contract", payload.get("activation_contract"))
    VALIDATOR.expect_mapping("production_loop_plan", payload.get("production_loop_plan"))
    VALIDATOR.expect_mapping("supervised_start_plan", payload.get("supervised_start_plan"))

    guardrails = VALIDATOR.expect_mapping("guardrails", payload.get("guardrails"))
    for key in [
        "direct_provider_call_allowed",
        "direct_tool_execution_allowed",
        "raw_audio_persistence_allowed",
        "access_token_log_allowed",
        "worker_start_without_production_promotion_allowed",
    ]:
        VALIDATOR.expect(f"guardrails.{key}", guardrails.get(key), False)

    production_promotion = VALIDATOR.expect_mapping("production_promotion", payload.get("production_promotion"))
    VALIDATOR.expect("production_promotion.required", production_promotion.get("required"), True)
    VALIDATOR.expect("production_promotion.human_review_required", production_promotion.get("human_review_required"), True)
    VALIDATOR.expect("production_promotion.decision_receipt_required", production_promotion.get("decision_receipt_required"), True)
    VALIDATOR.expect("production_promotion.rollback_plan_required", production_promotion.get("rollback_plan_required"), True)
    VALIDATOR.expect("production_promotion.boolean_approval_is_sufficient", production_promotion.get("boolean_approval_is_sufficient"), False)
    VALIDATOR.expect("production_promotion.auto_promotion_allowed", production_promotion.get("auto_promotion_allowed"), False)

    daemon_implementation = VALIDATOR.expect_mapping("daemon_implementation", payload.get("daemon_implementation"))
    VALIDATOR.expect("daemon_implementation.required", daemon_implementation.get("required"), True)
    VALIDATOR.expect("daemon_implementation.review_required", daemon_implementation.get("review_required"), True)
    VALIDATOR.expect("daemon_implementation.decision_receipt_required", daemon_implementation.get("decision_receipt_required"), True)
    VALIDATOR.expect("daemon_implementation.supervised_start_required", daemon_implementation.get("supervised_start_required"), True)
    VALIDATOR.expect("daemon_implementation.start_allowed_by_review", daemon_implementation.get("start_allowed_by_review"), False)

    return payload
