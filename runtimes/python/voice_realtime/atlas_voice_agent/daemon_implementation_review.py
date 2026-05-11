from __future__ import annotations

from typing import Any, Mapping

from .payload_safety import reject_forbidden_keys_recursive
from .turn_payload import UnsafeVoicePayload


SCHEMA_VERSION = "atlas.voice_realtime.daemon_implementation_review.v1"
CHECK_SCHEMA_VERSION = "atlas.voice_realtime.daemon_implementation_review_check.v1"

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
    "disable_livekit_worker_launch",
    "stop_livekit_worker",
    "revert_runtime_policy",
}

REQUIRED_FORBIDDEN_ACKS = {
    "bypass_kernel_decision_receipt",
    "direct_provider_call_from_daemon",
    "persist_raw_audio",
    "start_without_supervisor",
}


def validate_daemon_implementation_review(review: Mapping[str, Any] | None) -> Mapping[str, Any]:
    """Validate the implementation review before any supervised daemon start.

    Production promotion approval and daemon implementation review are separate
    controls. This receipt proves the long-running worker path was reviewed
    without giving the runtime authority to start itself.
    """

    errors: list[str] = []
    sanitized_review: dict[str, Any] | None = None

    if review is None:
        errors.append("daemon_implementation_review_missing")
    elif not isinstance(review, Mapping):
        errors.append("daemon_implementation_review_must_be_object")
    else:
        try:
            reject_forbidden_keys_recursive(review, FORBIDDEN_KEYS, label="daemon implementation review")
        except UnsafeVoicePayload as error:
            errors.append(str(error))

        sanitized_review = _sanitize_review(review)
        for key in [
            "schema_version",
            "decision_receipt_id",
            "implementation_ref",
            "reviewed_by",
            "reviewed_at",
        ]:
            _validate_required_string(review, key, errors)

        if review.get("schema_version") != SCHEMA_VERSION:
            errors.append("schema_version_must_be_atlas_voice_realtime_daemon_implementation_review_v1")
        if review.get("status") != "approved":
            errors.append("status_must_be_approved")
        if review.get("surface_id") != "voice_realtime":
            errors.append("surface_id_must_be_voice_realtime")
        if review.get("runtime_id") != "livekit_agents_sdk":
            errors.append("runtime_id_must_be_livekit_agents_sdk")
        if review.get("supervised_start_required") is not True:
            errors.append("supervised_start_required_must_be_true")
        if review.get("kernel_decision_receipt_required") is not True:
            errors.append("kernel_decision_receipt_required_must_be_true")
        if review.get("direct_provider_call_allowed") is not False:
            errors.append("direct_provider_call_allowed_must_be_false")
        if review.get("raw_audio_persistence_allowed") is not False:
            errors.append("raw_audio_persistence_allowed_must_be_false")

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
            "supervised_start_required": True,
            "kernel_decision_receipt_required": True,
            "direct_provider_call_allowed": False,
            "raw_audio_persistence_allowed": False,
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
        "implementation_ref": review.get("implementation_ref"),
        "reviewed_by": review.get("reviewed_by"),
        "reviewed_at": review.get("reviewed_at"),
        "rollback_plan": rollback_actions,
        "forbidden_actions_acknowledged": forbidden_acks,
        "supervised_start_required": review.get("supervised_start_required"),
        "kernel_decision_receipt_required": review.get("kernel_decision_receipt_required"),
        "direct_provider_call_allowed": review.get("direct_provider_call_allowed"),
        "raw_audio_persistence_allowed": review.get("raw_audio_persistence_allowed"),
    }
