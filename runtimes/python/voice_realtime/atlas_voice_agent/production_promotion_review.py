from __future__ import annotations

from typing import Any, Mapping

from .payload_safety import reject_forbidden_keys_recursive
from .promotion_review_packet import PromotionReviewPacketViolation, validate_promotion_review_packet
from .turn_payload import UnsafeVoicePayload


SCHEMA_VERSION = "atlas.voice_realtime.production_promotion_review.v1"
CHECK_SCHEMA_VERSION = "atlas.voice_realtime.production_promotion_review_check.v1"
REVIEWED_BUNDLE_SCHEMA_VERSION = "atlas.voice_realtime.production_promotion_review_bundle.v1"

FORBIDDEN_KEYS = {
    "access_token",
    "api_key",
    "api_secret",
    "audio",
    "audio_bytes",
    "audio_raw",
    "jwt",
    "LIVEKIT_API_KEY",
    "LIVEKIT_API_SECRET",
    "livekit_token",
    "provider_response",
    "raw_audio",
    "raw_audio_bytes",
    "token",
}

REQUIRED_ROLLBACK_ACTIONS = {
    "disable_livekit_token_issuer",
    "stop_livekit_worker",
    "revert_runtime_policy",
}

REQUIRED_FORBIDDEN_ACKS = {
    "bypass_kernel_decision_receipt",
    "auto_promote_voice_runtime",
    "persist_raw_audio",
}

REQUIRED_EVIDENCE = {
    "runtime_certification",
    "product_loop_check",
    "pre_start_health_checks_smoke",
    "rivals_voice_comparison",
}

ALLOWED_REVIEWED_MACHINE_GATE_STATUSES = {
    "ready_for_human_review",
    "ready_for_daemon_implementation_review",
    "ready_for_supervised_start_implementation",
}


def validate_production_promotion_review(
    review: Mapping[str, Any] | None,
    *,
    expected_bundle: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Validate the human review receipt required before product worker startup.

    A CLI boolean is intentionally not enough. The product loop must carry an
    auditable, token-safe receipt that proves a Decision Receipt, rollback plan
    and constitutional forbidden-action acknowledgements exist.
    """

    errors: list[str] = []
    sanitized_review: dict[str, Any] | None = None
    expected_bundle_summary = _expected_bundle_summary(expected_bundle)

    if expected_bundle is not None:
        try:
            validate_promotion_review_packet(expected_bundle)
        except PromotionReviewPacketViolation as error:
            errors.append(f"reviewed_bundle_invalid:{error}")

    if review is None:
        errors.append("review_receipt_missing")
    elif not isinstance(review, Mapping):
        errors.append("review_receipt_must_be_object")
    else:
        try:
            reject_forbidden_keys_recursive(review, FORBIDDEN_KEYS, label="production promotion review")
        except UnsafeVoicePayload as error:
            errors.append(str(error))

        sanitized_review = _sanitize_review(review)
        _validate_required_string(review, "schema_version", errors)
        _validate_required_string(review, "decision_receipt_id", errors)
        _validate_required_string(review, "approved_by", errors)
        _validate_required_string(review, "approved_at", errors)
        _validate_required_string(review, "reviewed_bundle_hash", errors)

        if review.get("schema_version") != SCHEMA_VERSION:
            errors.append("schema_version_must_be_atlas_voice_realtime_production_promotion_review_v1")
        if review.get("reviewed_bundle_schema_version") != REVIEWED_BUNDLE_SCHEMA_VERSION:
            errors.append("reviewed_bundle_schema_version_must_be_atlas_voice_realtime_production_promotion_review_bundle_v1")
        if not _is_lowercase_sha256(review.get("reviewed_bundle_hash")):
            errors.append("reviewed_bundle_hash_must_be_lowercase_sha256")
        if review.get("reviewed_machine_gate_status") not in ALLOWED_REVIEWED_MACHINE_GATE_STATUSES:
            errors.append("reviewed_machine_gate_status_must_be_ready_for_review")
        if review.get("status") != "approved":
            errors.append("status_must_be_approved")
        if review.get("surface_id") != "voice_realtime":
            errors.append("surface_id_must_be_voice_realtime")
        if review.get("runtime_id") != "livekit_agents_sdk":
            errors.append("runtime_id_must_be_livekit_agents_sdk")
        if review.get("auto_promotion_allowed") is not False:
            errors.append("auto_promotion_allowed_must_be_false")

        rollback_actions = _string_set(review.get("rollback_plan"))
        missing_rollback = sorted(REQUIRED_ROLLBACK_ACTIONS - rollback_actions)
        if missing_rollback:
            errors.append("rollback_plan_missing_required_actions:"+",".join(missing_rollback))

        forbidden_acks = _string_set(review.get("forbidden_actions_acknowledged"))
        missing_acks = sorted(REQUIRED_FORBIDDEN_ACKS - forbidden_acks)
        if missing_acks:
            errors.append("forbidden_actions_acknowledged_missing:"+",".join(missing_acks))

        evidence_reviewed = _string_set(review.get("required_evidence_reviewed"))
        missing_evidence = sorted(REQUIRED_EVIDENCE - evidence_reviewed)
        if missing_evidence:
            errors.append("required_evidence_reviewed_missing:"+",".join(missing_evidence))

        if not isinstance(review.get("failed_machine_gates_acknowledged"), list):
            errors.append("failed_machine_gates_acknowledged_must_be_list")
        if expected_bundle is not None:
            _validate_review_matches_expected_bundle(review, expected_bundle, errors)

    valid = len(errors) == 0

    return {
        "schema_version": CHECK_SCHEMA_VERSION,
        "valid": valid,
        "status": "approved" if valid else ("missing" if review is None else "invalid"),
        "surface_id": "voice_realtime",
        "runtime_id": "livekit_agents_sdk",
        "errors": errors,
        "sanitized_review": sanitized_review,
        "expected_bundle": expected_bundle_summary,
        "expected_bundle_required_for_final_promotion": True,
        "required": {
            "schema_version": SCHEMA_VERSION,
            "status": "approved",
            "surface_id": "voice_realtime",
            "runtime_id": "livekit_agents_sdk",
            "reviewed_bundle_schema_version": REVIEWED_BUNDLE_SCHEMA_VERSION,
            "reviewed_machine_gate_status": sorted(ALLOWED_REVIEWED_MACHINE_GATE_STATUSES),
            "required_evidence_reviewed": sorted(REQUIRED_EVIDENCE),
            "rollback_actions": sorted(REQUIRED_ROLLBACK_ACTIONS),
            "forbidden_actions_acknowledged": sorted(REQUIRED_FORBIDDEN_ACKS),
            "failed_machine_gates_acknowledged": "explicit_list_required",
            "auto_promotion_allowed": False,
        },
}


def _validate_review_matches_expected_bundle(
    review: Mapping[str, Any],
    expected_bundle: Mapping[str, Any],
    errors: list[str],
) -> None:
    expected_hash = expected_bundle.get("bundle_hash")
    if review.get("reviewed_bundle_hash") != expected_hash:
        errors.append("reviewed_bundle_hash_mismatch")

    if review.get("reviewed_bundle_schema_version") != expected_bundle.get("schema_version"):
        errors.append("reviewed_bundle_schema_version_mismatch")

    expected_status = expected_bundle.get("status")
    if review.get("reviewed_machine_gate_status") != expected_status:
        errors.append("reviewed_machine_gate_status_mismatch")


def _expected_bundle_summary(expected_bundle: Mapping[str, Any] | None) -> dict[str, Any] | None:
    if expected_bundle is None:
        return None

    return {
        "schema_version": expected_bundle.get("schema_version"),
        "status": expected_bundle.get("status"),
        "surface_id": expected_bundle.get("surface_id"),
        "runtime_id": expected_bundle.get("runtime_id"),
        "callback_loop_wired": expected_bundle.get("callback_loop_wired"),
        "production_sdk_loop_wired": expected_bundle.get("production_sdk_loop_wired"),
        "bundle_hash": expected_bundle.get("bundle_hash"),
    }


def _validate_required_string(review: Mapping[str, Any], key: str, errors: list[str]) -> None:
    value = review.get(key)
    if not isinstance(value, str) or value.strip() == "":
        errors.append(f"{key}_required")


def _string_set(value: Any) -> set[str]:
    if not isinstance(value, list):
        return set()

    return {str(item).strip() for item in value if str(item).strip() != ""}


def _is_lowercase_sha256(value: Any) -> bool:
    return isinstance(value, str) and len(value) == 64 and all(char in "0123456789abcdef" for char in value)


def _sanitize_review(review: Mapping[str, Any]) -> dict[str, Any]:
    rollback_actions = sorted(_string_set(review.get("rollback_plan")))
    forbidden_acks = sorted(_string_set(review.get("forbidden_actions_acknowledged")))
    evidence_reviewed = sorted(_string_set(review.get("required_evidence_reviewed")))
    failed_gates_acknowledged = sorted(_string_set(review.get("failed_machine_gates_acknowledged")))

    return {
        "schema_version": review.get("schema_version"),
        "status": review.get("status"),
        "surface_id": review.get("surface_id"),
        "runtime_id": review.get("runtime_id"),
        "reviewed_bundle_schema_version": review.get("reviewed_bundle_schema_version"),
        "reviewed_bundle_hash": review.get("reviewed_bundle_hash"),
        "reviewed_machine_gate_status": review.get("reviewed_machine_gate_status"),
        "decision_receipt_id": review.get("decision_receipt_id"),
        "approved_by": review.get("approved_by"),
        "approved_at": review.get("approved_at"),
        "required_evidence_reviewed": evidence_reviewed,
        "failed_machine_gates_acknowledged": failed_gates_acknowledged,
        "rollback_plan": rollback_actions,
        "forbidden_actions_acknowledged": forbidden_acks,
        "auto_promotion_allowed": review.get("auto_promotion_allowed"),
    }
