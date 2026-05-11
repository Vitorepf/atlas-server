from __future__ import annotations

from typing import Any, Mapping

from .payload_safety import reject_forbidden_keys_recursive
from .turn_payload import UnsafeVoicePayload


class VoiceStatusPacketViolation(ValueError):
    """Raised when Kernel voice status responses relax runtime guardrails."""


FORBIDDEN_STATUS_PACKET_KEYS = {
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


def validate_readiness_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden_payload(payload, "readiness")
    _require_schema(payload, "atlas.voice.readiness.v1", "readiness")
    _require_string(payload, "status", "readiness")
    _require_bool(payload, "mobile_first", True, "readiness")

    gates = _require_mapping(payload, "gates", "readiness")
    _require_bool(gates, "raw_audio_forbidden", True, "readiness.gates")
    _require_bool(gates, "kernel_decision_per_turn", True, "readiness.gates")

    if "phase0_hardening" in payload:
        phase0 = _require_mapping(payload, "phase0_hardening", "readiness")
        _require_schema(phase0, "atlas.voice_realtime.phase0_hardening_gate.v1", "readiness.phase0_hardening")
        _require_identity(phase0, "readiness.phase0_hardening")
        _require_bool(phase0, "kernel_only", True, "readiness.phase0_hardening")
        _require_bool(phase0, "mobile_first", True, "readiness.phase0_hardening")
        _require_bool(phase0, "promotion_allowed", False, "readiness.phase0_hardening")
        _require_bool(phase0, "auto_promotion_allowed", False, "readiness.phase0_hardening")

    if "product_loop_check" in payload:
        product_loop = _require_mapping(payload, "product_loop_check", "readiness")
        _require_bool(product_loop, "promotion_allowed", False, "readiness.product_loop_check")
        _require_bool(product_loop, "auto_promotion_allowed", False, "readiness.product_loop_check")
        _require_bool(product_loop, "daemon_started", False, "readiness.product_loop_check")

    if "runtime_dependency_summary" in payload:
        dependency_summary = _require_mapping(payload, "runtime_dependency_summary", "readiness")
        if "auto_install_allowed" in dependency_summary:
            _require_bool(dependency_summary, "auto_install_allowed", False, "readiness.runtime_dependency_summary")

    _require_string(payload, "next_action", "readiness")

    return payload


def validate_rivals_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden_payload(payload, "rivals")
    _require_schema(payload, "atlas.voice.rivals.v1", "rivals")
    _require_string(payload, "status", "rivals")

    if "readiness" in payload:
        validate_readiness_packet(_require_mapping(payload, "readiness", "rivals"))

    if "runtime_id" in payload:
        _require_identity(payload, "rivals")

    if "promotion_allowed" in payload:
        _require_bool(payload, "promotion_allowed", False, "rivals")
    if "auto_promotion_allowed" in payload:
        _require_bool(payload, "auto_promotion_allowed", False, "rivals")
    if "daemon_started" in payload:
        _require_bool(payload, "daemon_started", False, "rivals")

    _require_forbidden_false_if_present(payload, "direct_provider_call_allowed", "rivals")
    _require_forbidden_false_if_present(payload, "direct_tool_execution_allowed", "rivals")
    _require_forbidden_false_if_present(payload, "raw_audio_persistence_allowed", "rivals")

    if "runtime_certification" in payload:
        certification = _require_mapping(payload, "runtime_certification", "rivals")
        _require_identity(certification, "rivals.runtime_certification")
        _require_bool(certification, "kernel_only", True, "rivals.runtime_certification")
        _require_bool(certification, "mobile_first", True, "rivals.runtime_certification")
        _require_bool(certification, "daemon_started", False, "rivals.runtime_certification")
        if "product_loop_check" in certification:
            product_loop = _require_mapping(certification, "product_loop_check", "rivals.runtime_certification")
            _require_bool(product_loop, "daemon_started", False, "rivals.runtime_certification.product_loop_check")

    if "production_promotion_gate" in payload:
        promotion_gate = _require_mapping(payload, "production_promotion_gate", "rivals")
        _require_identity(promotion_gate, "rivals.production_promotion_gate")
        _require_bool(promotion_gate, "kernel_only", True, "rivals.production_promotion_gate")
        _require_bool(promotion_gate, "mobile_first", True, "rivals.production_promotion_gate")
        _require_bool(promotion_gate, "promotion_allowed", False, "rivals.production_promotion_gate")
        _require_bool(promotion_gate, "auto_promotion_allowed", False, "rivals.production_promotion_gate")
        _require_bool(promotion_gate, "human_review_required", True, "rivals.production_promotion_gate")

    _require_string(payload, "next_action", "rivals", required=False)

    return payload


def _require_schema(payload: Mapping[str, Any], expected: str, label: str) -> None:
    actual = payload.get("schema_version")
    if actual != expected:
        raise VoiceStatusPacketViolation(f"{label}.schema_version must be {expected}")


def _require_identity(payload: Mapping[str, Any], label: str) -> None:
    if payload.get("surface_id", "voice_realtime") != "voice_realtime":
        raise VoiceStatusPacketViolation(f"{label}.surface_id must be voice_realtime")
    if payload.get("runtime_id", "livekit_agents_sdk") != "livekit_agents_sdk":
        raise VoiceStatusPacketViolation(f"{label}.runtime_id must be livekit_agents_sdk")


def _require_mapping(payload: Mapping[str, Any], key: str, label: str) -> Mapping[str, Any]:
    value = payload.get(key)
    if not isinstance(value, Mapping):
        raise VoiceStatusPacketViolation(f"{label}.{key} must be an object")

    return value


def _require_string(payload: Mapping[str, Any], key: str, label: str, *, required: bool = True) -> None:
    value = payload.get(key)
    if value is None and not required:
        return
    if not isinstance(value, str) or value.strip() == "":
        raise VoiceStatusPacketViolation(f"{label}.{key} must be a non-empty string")


def _require_bool(payload: Mapping[str, Any], key: str, expected: bool, label: str) -> None:
    value = payload.get(key)
    if value is not expected:
        raise VoiceStatusPacketViolation(f"{label}.{key} must be {expected}")


def _require_forbidden_false_if_present(payload: Mapping[str, Any], key: str, label: str) -> None:
    if key in payload:
        _require_bool(payload, key, False, label)


def _reject_forbidden_payload(payload: Mapping[str, Any], label: str) -> None:
    try:
        reject_forbidden_keys_recursive(payload, FORBIDDEN_STATUS_PACKET_KEYS, label=label)
    except UnsafeVoicePayload as exc:
        raise VoiceStatusPacketViolation(str(exc)) from exc
