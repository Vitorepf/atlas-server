from __future__ import annotations

from typing import Any, Mapping

from .payload_safety import reject_forbidden_keys_recursive
from .turn_payload import UnsafeVoicePayload


SCHEMA_VERSION = "atlas.voice_realtime.production_promotion_review.v1"
CHECK_SCHEMA_VERSION = "atlas.voice_realtime.production_promotion_review_check.v1"

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


def validate_production_promotion_review(review: Mapping[str, Any] | None) -> Mapping[str, Any]:
    """Validate the human review receipt required before product worker startup.

    A CLI boolean is intentionally not enough. The product loop must carry an
    auditable, token-safe receipt that proves a Decision Receipt, rollback plan
    and constitutional forbidden-action acknowledgements exist.
    """

    errors: list[str] = []
    sanitized_review: dict[str, Any] | None = None

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

        if review.get("schema_version") != SCHEMA_VERSION:
            errors.append("schema_version_must_be_atlas_voice_realtime_production_promotion_review_v1")
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

    valid = len(errors) == 0

    return {
        "schema_version": CHECK_SCHEMA_VERSION,
        "valid": valid,
        "status": "approved" if valid else ("missing" if review is None else "invalid"),
        "surface_id": "voice_realtime",
        "runtime_id": "livekit_agents_sdk",
        "errors": errors,
        "sanitized_review": sanitized_review,
        "required": {
            "schema_version": SCHEMA_VERSION,
            "status": "approved",
            "surface_id": "voice_realtime",
            "runtime_id": "livekit_agents_sdk",
            "rollback_actions": sorted(REQUIRED_ROLLBACK_ACTIONS),
            "forbidden_actions_acknowledged": sorted(REQUIRED_FORBIDDEN_ACKS),
            "auto_promotion_allowed": False,
        },
    }


def _validate_required_string(review: Mapping[str, Any], key: str, errors: list[str]) -> None:
    value = review.get(key)
    if not isinstance(value, str) or value.strip() == "":
        errors.append(f"{key}_required")


def _string_set(value: Any) -> set[str]:
    if not isinstance(value, list):
        return set()

    return {str(item).strip() for item in value if str(item).strip() != ""}


def _sanitize_review(review: Mapping[str, Any]) -> dict[str, Any]:
    rollback_actions = sorted(_string_set(review.get("rollback_plan")))
    forbidden_acks = sorted(_string_set(review.get("forbidden_actions_acknowledged")))

    return {
        "schema_version": review.get("schema_version"),
        "status": review.get("status"),
        "surface_id": review.get("surface_id"),
        "runtime_id": review.get("runtime_id"),
        "decision_receipt_id": review.get("decision_receipt_id"),
        "approved_by": review.get("approved_by"),
        "approved_at": review.get("approved_at"),
        "rollback_plan": rollback_actions,
        "forbidden_actions_acknowledged": forbidden_acks,
        "auto_promotion_allowed": review.get("auto_promotion_allowed"),
    }
