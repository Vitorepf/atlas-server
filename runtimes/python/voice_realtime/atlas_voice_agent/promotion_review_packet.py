from __future__ import annotations

from typing import Any, Mapping

from .payload_safety import reject_forbidden_keys_recursive
from .turn_payload import UnsafeVoicePayload


SCHEMA_VERSION = "atlas.voice_realtime.production_promotion_review_bundle.v1"


class PromotionReviewPacketViolation(RuntimeError):
    """Raised when the Kernel promotion review bundle is unsafe to consume."""


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
    try:
        reject_forbidden_keys_recursive(payload, FORBIDDEN_PROMOTION_REVIEW_KEYS, label="promotion_review")
    except UnsafeVoicePayload as exc:
        raise PromotionReviewPacketViolation(str(exc)) from exc

    _expect("schema_version", payload.get("schema_version"), SCHEMA_VERSION)
    _expect("surface_id", payload.get("surface_id"), "voice_realtime")
    _expect("runtime_id", payload.get("runtime_id"), expected_runtime_id)
    _expect("kernel_only", payload.get("kernel_only"), True)
    _expect("mobile_first", payload.get("mobile_first"), True)
    _expect("promotion_allowed", payload.get("promotion_allowed"), False)
    _expect("auto_promotion_allowed", payload.get("auto_promotion_allowed"), False)
    _expect("daemon_started", payload.get("daemon_started"), False)
    _expect("human_review_required", payload.get("human_review_required"), True)
    _expect("decision_receipt_required", payload.get("decision_receipt_required"), True)
    _expect("rollback_plan_required", payload.get("rollback_plan_required"), True)
    _expect_bool("callback_loop_wired", payload.get("callback_loop_wired"))
    _expect_bool("production_sdk_loop_wired", payload.get("production_sdk_loop_wired"))

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
        _expect(f"guardrails.{key}", guardrails.get(key), False)

    production_gate = _expect_mapping("production_promotion_gate", payload.get("production_promotion_gate"))
    _expect("production_promotion_gate.schema_version", production_gate.get("schema_version"), "atlas.voice_realtime.production_promotion_gate.v1")
    if str(production_gate.get("status") or "") not in ["blocked", "review_required"]:
        raise PromotionReviewPacketViolation("production_promotion_gate.status must be blocked or review_required")
    if not isinstance(production_gate.get("failed_keys"), list):
        raise PromotionReviewPacketViolation("production_promotion_gate.failed_keys must be a list")

    review_packet = _expect_mapping("review_packet", payload.get("review_packet"))
    _expect("review_packet.schema_version", review_packet.get("schema_version"), "atlas.voice_realtime.production_promotion_review_packet.v1")
    _expect("review_packet.required_decision_receipt", review_packet.get("required_decision_receipt"), True)
    _expect_non_empty_list("review_packet.required_rollback_plan", review_packet.get("required_rollback_plan"))
    _expect_non_empty_list("review_packet.required_evidence", review_packet.get("required_evidence"))
    _expect_non_empty_list("review_packet.forbidden_actions", review_packet.get("forbidden_actions"))

    evidence = _expect_mapping("evidence", payload.get("evidence"))
    for key in [
        "runtime_certification",
        "product_loop_check",
        "pre_start_health_checks_smoke",
        "rivals_voice_comparison",
    ]:
        _validate_evidence_summary(key, _expect_mapping(f"evidence.{key}", evidence.get(key)))

    summary = _expect_mapping("summary", payload.get("summary"))
    _expect("summary.evidence_count", summary.get("evidence_count"), len(evidence))
    if not isinstance(summary.get("failed_machine_gates"), list):
        raise PromotionReviewPacketViolation("summary.failed_machine_gates must be a list")
    if not isinstance(summary.get("review_ready"), bool):
        raise PromotionReviewPacketViolation("summary.review_ready must be a bool")

    bundle_hash = payload.get("bundle_hash")
    if not (isinstance(bundle_hash, str) and len(bundle_hash) == 64 and all(char in "0123456789abcdef" for char in bundle_hash)):
        raise PromotionReviewPacketViolation("bundle_hash must be a lowercase sha256 hex string")

    return payload


def _expect(path: str, actual: Any, expected: Any) -> None:
    if actual != expected:
        raise PromotionReviewPacketViolation(f"{path} expected {expected!r}, got {actual!r}")


def _expect_bool(path: str, value: Any) -> None:
    if not isinstance(value, bool):
        raise PromotionReviewPacketViolation(f"{path} must be a bool")


def _expect_mapping(path: str, value: Any) -> Mapping[str, Any]:
    if not isinstance(value, Mapping):
        raise PromotionReviewPacketViolation(f"{path} must be an object")

    return value


def _expect_non_empty_list(path: str, value: Any) -> None:
    if not isinstance(value, list) or value == []:
        raise PromotionReviewPacketViolation(f"{path} must be a non-empty list")


def _validate_evidence_summary(name: str, summary: Mapping[str, Any]) -> None:
    _expect(f"evidence.{name}.name", summary.get("name"), name)
    _expect(f"evidence.{name}.daemon_started", summary.get("daemon_started"), False)
    _expect(f"evidence.{name}.promotion_allowed", summary.get("promotion_allowed"), False)
    _expect(f"evidence.{name}.auto_promotion_allowed", summary.get("auto_promotion_allowed"), False)

    payload_hash = summary.get("payload_hash")
    if not (isinstance(payload_hash, str) and len(payload_hash) == 64 and all(char in "0123456789abcdef" for char in payload_hash)):
        raise PromotionReviewPacketViolation(f"evidence.{name}.payload_hash must be a lowercase sha256 hex string")
