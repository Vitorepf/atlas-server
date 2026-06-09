from __future__ import annotations

from typing import Any, Mapping

from .packet_validation import PacketValidator


SCHEMA_VERSION = "atlas.voice_realtime.production_promotion_review_bundle.v1"


class PromotionReviewPacketViolation(RuntimeError):
    """Raised when the Kernel promotion review bundle is unsafe to consume."""


VALIDATOR = PacketValidator(PromotionReviewPacketViolation)

FORBIDDEN_PROMOTION_REVIEW_KEYS = {
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


def validate_promotion_review_packet(
    payload: Mapping[str, Any],
    *,
    expected_runtime_id: str = "livekit_agents_sdk",
) -> Mapping[str, Any]:
    VALIDATOR.reject_forbidden(payload, FORBIDDEN_PROMOTION_REVIEW_KEYS, label="promotion_review")

    VALIDATOR.expect("schema_version", payload.get("schema_version"), SCHEMA_VERSION)
    VALIDATOR.expect("surface_id", payload.get("surface_id"), "voice_realtime")
    VALIDATOR.expect("runtime_id", payload.get("runtime_id"), expected_runtime_id)
    VALIDATOR.expect("kernel_only", payload.get("kernel_only"), True)
    VALIDATOR.expect("mobile_first", payload.get("mobile_first"), True)
    VALIDATOR.expect("promotion_allowed", payload.get("promotion_allowed"), False)
    VALIDATOR.expect("auto_promotion_allowed", payload.get("auto_promotion_allowed"), False)
    VALIDATOR.expect("daemon_started", payload.get("daemon_started"), False)
    VALIDATOR.expect("human_review_required", payload.get("human_review_required"), True)
    VALIDATOR.expect("decision_receipt_required", payload.get("decision_receipt_required"), True)
    VALIDATOR.expect("rollback_plan_required", payload.get("rollback_plan_required"), True)
    VALIDATOR.expect_bool("callback_loop_wired", payload.get("callback_loop_wired"))
    VALIDATOR.expect_bool("production_sdk_loop_wired", payload.get("production_sdk_loop_wired"))

    guardrails = payload.get("guardrails")
    if not isinstance(guardrails, Mapping):
        raise PromotionReviewPacketViolation("guardrails must be an object")

    for key in [
        "raw_audio_persistence_allowed",
        "direct_provider_call_allowed",
        "direct_tool_execution_allowed",
        "memory_write_allowed",
        "start_daemon_allowed",
        "boolean_approval_is_sufficient",
    ]:
        VALIDATOR.expect(f"guardrails.{key}", guardrails.get(key), False)

    production_gate = VALIDATOR.expect_mapping("production_promotion_gate", payload.get("production_promotion_gate"))
    VALIDATOR.expect("production_promotion_gate.schema_version", production_gate.get("schema_version"), "atlas.voice_realtime.production_promotion_gate.v1")
    if str(production_gate.get("status") or "") not in ["blocked", "review_required"]:
        raise PromotionReviewPacketViolation("production_promotion_gate.status must be blocked or review_required")
    if not isinstance(production_gate.get("failed_keys"), list):
        raise PromotionReviewPacketViolation("production_promotion_gate.failed_keys must be a list")

    review_packet = VALIDATOR.expect_mapping("review_packet", payload.get("review_packet"))
    VALIDATOR.expect("review_packet.schema_version", review_packet.get("schema_version"), "atlas.voice_realtime.production_promotion_review_packet.v1")
    VALIDATOR.expect("review_packet.required_decision_receipt", review_packet.get("required_decision_receipt"), True)
    VALIDATOR.expect_non_empty_list("review_packet.required_rollback_plan", review_packet.get("required_rollback_plan"))
    VALIDATOR.expect_non_empty_list("review_packet.required_evidence", review_packet.get("required_evidence"))
    VALIDATOR.expect_non_empty_list("review_packet.forbidden_actions", review_packet.get("forbidden_actions"))

    evidence = VALIDATOR.expect_mapping("evidence", payload.get("evidence"))
    for key in [
        "runtime_certification",
        "product_loop_check",
        "pre_start_health_checks_smoke",
        "rivals_voice_comparison",
    ]:
        _validate_evidence_summary(key, VALIDATOR.expect_mapping(f"evidence.{key}", evidence.get(key)))

    summary = VALIDATOR.expect_mapping("summary", payload.get("summary"))
    VALIDATOR.expect("summary.evidence_count", summary.get("evidence_count"), len(evidence))
    if not isinstance(summary.get("failed_machine_gates"), list):
        raise PromotionReviewPacketViolation("summary.failed_machine_gates must be a list")
    if not isinstance(summary.get("review_ready"), bool):
        raise PromotionReviewPacketViolation("summary.review_ready must be a bool")

    VALIDATOR.expect_sha256_hex("bundle_hash", payload.get("bundle_hash"))

    return payload


def _validate_evidence_summary(name: str, summary: Mapping[str, Any]) -> None:
    VALIDATOR.expect(f"evidence.{name}.name", summary.get("name"), name)
    VALIDATOR.expect(f"evidence.{name}.daemon_started", summary.get("daemon_started"), False)
    VALIDATOR.expect(f"evidence.{name}.promotion_allowed", summary.get("promotion_allowed"), False)
    VALIDATOR.expect(f"evidence.{name}.auto_promotion_allowed", summary.get("auto_promotion_allowed"), False)

    VALIDATOR.expect_sha256_hex(f"evidence.{name}.payload_hash", summary.get("payload_hash"))
