from __future__ import annotations

from typing import Any, Mapping

from .packet_validation import PacketValidator


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


VALIDATOR = PacketValidator(SupervisedStartPlanViolation)


def validate_supervised_start_plan(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    VALIDATOR.reject_forbidden(payload, FORBIDDEN_SUPERVISED_START_KEYS, label="supervised_start_plan")

    VALIDATOR.expect("schema_version", payload.get("schema_version"), SCHEMA_VERSION)
    VALIDATOR.expect("surface_id", payload.get("surface_id"), "voice_realtime")
    VALIDATOR.expect("runtime_id", payload.get("runtime_id"), "livekit_agents_sdk")
    VALIDATOR.expect("start_allowed", payload.get("start_allowed"), False)
    VALIDATOR.expect("daemon_started", payload.get("daemon_started"), False)
    VALIDATOR.expect("execution_implemented", payload.get("execution_implemented"), False)
    VALIDATOR.expect("supervisor_required", payload.get("supervisor_required"), True)
    VALIDATOR.expect("kernel_only", payload.get("kernel_only"), True)
    VALIDATOR.expect("mobile_first", payload.get("mobile_first"), True)

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

    gates = VALIDATOR.expect_mapping("gates", payload.get("gates"))
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
        VALIDATOR.expect_bool(f"gates.{key}", gates.get(key))
    VALIDATOR.expect("gates.supervisor_required", gates.get("supervisor_required"), True)
    VALIDATOR.expect("gates.supervisor_contract_declared", gates.get("supervisor_contract_declared"), True)
    VALIDATOR.expect("gates.supervisor_health_snapshot_declared", gates.get("supervisor_health_snapshot_declared"), True)
    VALIDATOR.expect("gates.worker_process_launch_disabled", gates.get("worker_process_launch_disabled"), True)

    supervisor_contract = VALIDATOR.expect_mapping("supervisor_contract", payload.get("supervisor_contract"))
    VALIDATOR.expect("supervisor_contract.schema_version", supervisor_contract.get("schema_version"), "atlas.voice_realtime.daemon_supervisor_contract.v1")
    VALIDATOR.expect("supervisor_contract.process_launch_implemented", supervisor_contract.get("process_launch_implemented"), False)
    restart_policy = VALIDATOR.expect_mapping("supervisor_contract.restart_policy", supervisor_contract.get("restart_policy"))
    VALIDATOR.expect("supervisor_contract.restart_policy.automatic_restart_allowed", restart_policy.get("automatic_restart_allowed"), False)
    VALIDATOR.expect("supervisor_contract.restart_policy.requires_new_decision_receipt", restart_policy.get("requires_new_decision_receipt"), True)
    VALIDATOR.expect("supervisor_contract.restart_policy.requires_human_review_after_failure", restart_policy.get("requires_human_review_after_failure"), True)

    health = VALIDATOR.expect_mapping("supervisor_health_snapshot", payload.get("supervisor_health_snapshot"))
    VALIDATOR.expect("supervisor_health_snapshot.schema_version", health.get("schema_version"), "atlas.voice_realtime.daemon_supervisor_health_snapshot.v1")
    VALIDATOR.expect("supervisor_health_snapshot.daemon_started", health.get("daemon_started"), False)
    VALIDATOR.expect("supervisor_health_snapshot.process_launch_implemented", health.get("process_launch_implemented"), False)
    VALIDATOR.expect("supervisor_health_snapshot.snapshot_is_observational", health.get("snapshot_is_observational"), True)
    health_guardrails = VALIDATOR.expect_mapping("supervisor_health_snapshot.guardrails", health.get("guardrails"))
    VALIDATOR.expect("supervisor_health_snapshot.guardrails.starts_process", health_guardrails.get("starts_process"), False)
    VALIDATOR.expect("supervisor_health_snapshot.guardrails.direct_provider_call_allowed", health_guardrails.get("direct_provider_call_allowed"), False)
    VALIDATOR.expect("supervisor_health_snapshot.guardrails.raw_audio_persistence_allowed", health_guardrails.get("raw_audio_persistence_allowed"), False)
    VALIDATOR.expect("supervisor_health_snapshot.guardrails.restart_allowed", health_guardrails.get("restart_allowed"), False)

    preflight = VALIDATOR.expect_mapping("supervisor_preflight", payload.get("supervisor_preflight"))
    VALIDATOR.expect("supervisor_preflight.schema_version", preflight.get("schema_version"), "atlas.voice_realtime.daemon_supervisor_preflight.v1")
    VALIDATOR.expect("supervisor_preflight.process_launch_attempted", preflight.get("process_launch_attempted"), False)
    VALIDATOR.expect("supervisor_preflight.daemon_started", preflight.get("daemon_started"), False)
    VALIDATOR.expect("supervisor_preflight.execution_implemented", preflight.get("execution_implemented"), False)
    VALIDATOR.expect("supervisor_preflight.preflight_only", preflight.get("preflight_only"), True)
    preflight_guardrails = VALIDATOR.expect_mapping("supervisor_preflight.guardrails", preflight.get("guardrails"))
    for key in [
        "direct_provider_call_allowed",
        "direct_tool_execution_allowed",
        "raw_audio_persistence_allowed",
        "access_token_log_allowed",
        "start_without_decision_receipt_allowed",
        "start_without_supervisor_allowed",
        "restart_without_human_review_allowed",
    ]:
        VALIDATOR.expect(f"supervisor_preflight.guardrails.{key}", preflight_guardrails.get(key), False)

    guardrails = VALIDATOR.expect_mapping("guardrails", payload.get("guardrails"))
    for key in [
        "direct_provider_call_allowed",
        "direct_tool_execution_allowed",
        "raw_audio_persistence_allowed",
        "access_token_log_allowed",
        "start_without_supervisor_allowed",
        "unbounded_restart_loop_allowed",
        "health_snapshot_starts_process",
    ]:
        VALIDATOR.expect(f"guardrails.{key}", guardrails.get(key), False)

    return payload
