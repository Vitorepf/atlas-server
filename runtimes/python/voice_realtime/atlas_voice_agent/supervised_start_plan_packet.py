from __future__ import annotations

from typing import Any, Mapping

from .payload_safety import reject_forbidden_keys_recursive
from .turn_payload import UnsafeVoicePayload


SCHEMA_VERSION = "atlas.voice_realtime.supervised_start_plan.v1"
FORBIDDEN_SUPERVISED_START_KEYS = {
    "access_token",
    "api_key",
    "api_secret",
    "audio",
    "audio_bytes",
    "audio_raw",
    "direct_llm_provider_call",
    "direct_provider_call",
    "direct_tool_execution",
    "env_file_contents",
    "livekit_token",
    "memory_write",
    "pcm",
    "provider_api_key",
    "raw_audio",
    "raw_audio_bytes",
    "raw_response_text",
    "response_text",
    "secret",
    "secret_value",
    "token",
    "tool_args",
    "tool_call",
    "transcript",
    "tts_text",
    "wav",
}


class SupervisedStartPlanViolation(RuntimeError):
    """Raised when the supervised start plan would relax daemon guardrails."""


def validate_supervised_start_plan(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    try:
        reject_forbidden_keys_recursive(payload, FORBIDDEN_SUPERVISED_START_KEYS, label="supervised_start_plan")
    except UnsafeVoicePayload as exc:
        raise SupervisedStartPlanViolation(str(exc)) from exc

    _expect("schema_version", payload.get("schema_version"), SCHEMA_VERSION)
    _expect("surface_id", payload.get("surface_id"), "voice_realtime")
    _expect("runtime_id", payload.get("runtime_id"), "livekit_agents_sdk")
    _expect("start_allowed", payload.get("start_allowed"), False)
    _expect("daemon_started", payload.get("daemon_started"), False)
    _expect("execution_implemented", payload.get("execution_implemented"), False)
    _expect("supervisor_required", payload.get("supervisor_required"), True)
    _expect("kernel_only", payload.get("kernel_only"), True)
    _expect("mobile_first", payload.get("mobile_first"), True)

    status = payload.get("status")
    if status not in {
        "blocked_pending_human_review",
        "blocked_pending_daemon_implementation_review",
        "blocked_activation_contract",
        "blocked_production_loop_wiring",
        "blocked_kernel_normalizer_contract",
        "blocked_supervised_start_prerequisites",
        "ready_for_supervised_start_implementation",
    }:
        raise SupervisedStartPlanViolation(f"status must be governed, got {status!r}")

    gates = _expect_mapping("gates", payload.get("gates"))
    for key in [
        "production_review_valid",
        "daemon_implementation_review_valid",
        "activation_contract_ready",
        "production_sdk_loop_wired",
        "kernel_event_normalizer_required",
        "worker_start_status_is_blocked_unimplemented_start",
        "supervisor_required",
        "supervisor_contract_declared",
        "supervisor_health_snapshot_declared",
        "worker_process_launch_disabled",
    ]:
        _expect_bool(f"gates.{key}", gates.get(key))
    _expect("gates.supervisor_required", gates.get("supervisor_required"), True)
    _expect("gates.supervisor_contract_declared", gates.get("supervisor_contract_declared"), True)
    _expect("gates.supervisor_health_snapshot_declared", gates.get("supervisor_health_snapshot_declared"), True)
    _expect("gates.worker_process_launch_disabled", gates.get("worker_process_launch_disabled"), True)

    supervisor_contract = _expect_mapping("supervisor_contract", payload.get("supervisor_contract"))
    _expect("supervisor_contract.schema_version", supervisor_contract.get("schema_version"), "atlas.voice_realtime.daemon_supervisor_contract.v1")
    _expect("supervisor_contract.process_launch_implemented", supervisor_contract.get("process_launch_implemented"), False)
    restart_policy = _expect_mapping("supervisor_contract.restart_policy", supervisor_contract.get("restart_policy"))
    _expect("supervisor_contract.restart_policy.automatic_restart_allowed", restart_policy.get("automatic_restart_allowed"), False)
    _expect("supervisor_contract.restart_policy.requires_new_decision_receipt", restart_policy.get("requires_new_decision_receipt"), True)
    _expect("supervisor_contract.restart_policy.requires_human_review_after_failure", restart_policy.get("requires_human_review_after_failure"), True)

    health = _expect_mapping("supervisor_health_snapshot", payload.get("supervisor_health_snapshot"))
    _expect("supervisor_health_snapshot.schema_version", health.get("schema_version"), "atlas.voice_realtime.daemon_supervisor_health_snapshot.v1")
    _expect("supervisor_health_snapshot.daemon_started", health.get("daemon_started"), False)
    _expect("supervisor_health_snapshot.process_launch_implemented", health.get("process_launch_implemented"), False)
    _expect("supervisor_health_snapshot.snapshot_is_observational", health.get("snapshot_is_observational"), True)
    health_guardrails = _expect_mapping("supervisor_health_snapshot.guardrails", health.get("guardrails"))
    _expect("supervisor_health_snapshot.guardrails.starts_process", health_guardrails.get("starts_process"), False)
    _expect("supervisor_health_snapshot.guardrails.direct_provider_call_allowed", health_guardrails.get("direct_provider_call_allowed"), False)
    _expect("supervisor_health_snapshot.guardrails.raw_audio_persistence_allowed", health_guardrails.get("raw_audio_persistence_allowed"), False)
    _expect("supervisor_health_snapshot.guardrails.restart_allowed", health_guardrails.get("restart_allowed"), False)

    preflight = _expect_mapping("supervisor_preflight", payload.get("supervisor_preflight"))
    _expect("supervisor_preflight.schema_version", preflight.get("schema_version"), "atlas.voice_realtime.daemon_supervisor_preflight.v1")
    _expect("supervisor_preflight.process_launch_attempted", preflight.get("process_launch_attempted"), False)
    _expect("supervisor_preflight.daemon_started", preflight.get("daemon_started"), False)
    _expect("supervisor_preflight.execution_implemented", preflight.get("execution_implemented"), False)
    _expect("supervisor_preflight.preflight_only", preflight.get("preflight_only"), True)
    preflight_guardrails = _expect_mapping("supervisor_preflight.guardrails", preflight.get("guardrails"))
    for key in [
        "direct_provider_call_allowed",
        "direct_tool_execution_allowed",
        "raw_audio_persistence_allowed",
        "access_token_log_allowed",
        "start_without_decision_receipt_allowed",
        "start_without_supervisor_allowed",
        "restart_without_human_review_allowed",
    ]:
        _expect(f"supervisor_preflight.guardrails.{key}", preflight_guardrails.get(key), False)

    guardrails = _expect_mapping("guardrails", payload.get("guardrails"))
    for key in [
        "direct_provider_call_allowed",
        "direct_tool_execution_allowed",
        "raw_audio_persistence_allowed",
        "access_token_log_allowed",
        "start_without_supervisor_allowed",
        "unbounded_restart_loop_allowed",
        "health_snapshot_starts_process",
    ]:
        _expect(f"guardrails.{key}", guardrails.get(key), False)

    return payload


def _expect(path: str, actual: Any, expected: Any) -> None:
    if actual != expected:
        raise SupervisedStartPlanViolation(f"{path} expected {expected!r}, got {actual!r}")


def _expect_bool(path: str, value: Any) -> None:
    if not isinstance(value, bool):
        raise SupervisedStartPlanViolation(f"{path} must be a bool")


def _expect_mapping(path: str, value: Any) -> Mapping[str, Any]:
    if not isinstance(value, Mapping):
        raise SupervisedStartPlanViolation(f"{path} must be an object")

    return value
