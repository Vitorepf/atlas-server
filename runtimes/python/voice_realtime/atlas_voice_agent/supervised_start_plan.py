from __future__ import annotations

from typing import Any, Mapping


SCHEMA_VERSION = "atlas.voice_realtime.supervised_start_plan.v1"
SUPERVISOR_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.daemon_supervisor_contract.v1"
SUPERVISOR_HEALTH_SCHEMA_VERSION = "atlas.voice_realtime.daemon_supervisor_health_snapshot.v1"
SUPERVISOR_PREFLIGHT_SCHEMA_VERSION = "atlas.voice_realtime.daemon_supervisor_preflight.v1"

LIFECYCLE_STATES = [
    "planned",
    "preflight",
    "starting",
    "healthy",
    "degraded",
    "stopping",
    "stopped",
    "failed",
]


def build_supervised_start_plan(
    *,
    worker_start_status: str,
    production_review_valid: bool,
    daemon_implementation_review_valid: bool,
    activation_contract: Mapping[str, Any],
    production_loop_plan: Mapping[str, Any],
) -> Mapping[str, Any]:
    """Describe the future supervised daemon start without starting it.

    This is the final fail-closed handoff before a real long-running worker can
    exist. It proves the runtime understands the next implementation step while
    keeping process launch disabled until a supervisor is explicitly built.
    """

    activation_ready = activation_contract.get("status") == "ready_to_start_worker"
    production_loop_wired = production_loop_plan.get("production_sdk_loop_wired") is True
    sdk_wiring_contract = production_loop_plan.get("sdk_wiring_contract")
    kernel_normalizer_required = (
        isinstance(sdk_wiring_contract, Mapping)
        and sdk_wiring_contract.get("guardrails", {}).get("kernel_event_normalizer_required_for_real_loop") is True
        and sdk_wiring_contract.get("required_components", {}).get("kernel_event_normalizer") == "KernelRuntimeEventNormalizerGuard"
    )
    prerequisites_ready = (
        production_review_valid
        and daemon_implementation_review_valid
        and activation_ready
        and production_loop_wired
        and kernel_normalizer_required
        and worker_start_status == "blocked_unimplemented_start"
    )

    gates = {
        "production_review_valid": production_review_valid,
        "daemon_implementation_review_valid": daemon_implementation_review_valid,
        "activation_contract_ready": activation_ready,
        "production_sdk_loop_wired": production_loop_wired,
        "kernel_event_normalizer_required": kernel_normalizer_required,
        "worker_start_status_is_blocked_unimplemented_start": worker_start_status == "blocked_unimplemented_start",
        "supervisor_required": True,
        "supervisor_contract_declared": True,
        "supervisor_health_snapshot_declared": True,
        "worker_process_launch_disabled": True,
    }
    supervisor_contract = _supervisor_contract()

    return {
        "schema_version": SCHEMA_VERSION,
        "status": _status(
            production_review_valid=production_review_valid,
            daemon_implementation_review_valid=daemon_implementation_review_valid,
            activation_ready=activation_ready,
            production_loop_wired=production_loop_wired,
            kernel_normalizer_required=kernel_normalizer_required,
            prerequisites_ready=prerequisites_ready,
        ),
        "surface_id": "voice_realtime",
        "runtime_id": "livekit_agents_sdk",
        "start_allowed": False,
        "daemon_started": False,
        "execution_implemented": False,
        "supervisor_required": True,
        "supervisor_contract": supervisor_contract,
        "supervisor_health_snapshot": _supervisor_health_snapshot(
            gates=gates,
            supervisor_contract=supervisor_contract,
            prerequisites_ready=prerequisites_ready,
        ),
        "supervisor_preflight": _supervisor_preflight(
            gates=gates,
            supervisor_contract=supervisor_contract,
            prerequisites_ready=prerequisites_ready,
        ),
        "kernel_only": True,
        "mobile_first": True,
        "gates": gates,
        "guardrails": {
            "direct_provider_call_allowed": False,
            "direct_tool_execution_allowed": False,
            "raw_audio_persistence_allowed": False,
            "access_token_log_allowed": False,
            "start_without_supervisor_allowed": False,
            "unbounded_restart_loop_allowed": False,
            "health_snapshot_starts_process": False,
        },
        "next_action": "implement_supervised_daemon_start" if prerequisites_ready else "fix_supervised_start_prerequisites",
    }


def _status(
    *,
    production_review_valid: bool,
    daemon_implementation_review_valid: bool,
    activation_ready: bool,
    production_loop_wired: bool,
    kernel_normalizer_required: bool,
    prerequisites_ready: bool,
) -> str:
    if not production_review_valid:
        return "blocked_pending_human_review"
    if not daemon_implementation_review_valid:
        return "blocked_pending_daemon_implementation_review"
    if not activation_ready:
        return "blocked_activation_contract"
    if not production_loop_wired:
        return "blocked_production_loop_wiring"
    if not kernel_normalizer_required:
        return "blocked_kernel_normalizer_contract"
    if prerequisites_ready:
        return "ready_for_supervised_start_implementation"

    return "blocked_supervised_start_prerequisites"


def _supervisor_contract() -> Mapping[str, Any]:
    return {
        "schema_version": SUPERVISOR_CONTRACT_SCHEMA_VERSION,
        "status": "planned_not_implemented",
        "process_launch_implemented": False,
        "lifecycle_states": LIFECYCLE_STATES,
        "required_health_checks": [
            "kernel_health",
            "livekit_room_connectivity",
            "callback_router_roundtrip",
            "kernel_event_normalizer_roundtrip",
            "turn_receipt_roundtrip",
        ],
        "restart_policy": {
            "automatic_restart_allowed": False,
            "max_attempts": 0,
            "requires_new_decision_receipt": True,
            "requires_human_review_after_failure": True,
        },
        "rollback_actions": [
            "disable_livekit_worker_launch",
            "stop_livekit_worker",
            "revoke_livekit_session_leases",
            "revert_runtime_policy",
        ],
        "evidence_events": [
            "VOICE_DAEMON_SUPERVISOR_PLANNED",
            "VOICE_DAEMON_START_BLOCKED",
            "VOICE_DAEMON_HEALTH_CHECK_PLANNED",
            "VOICE_DAEMON_ROLLBACK_PLANNED",
        ],
        "forbidden_shortcuts": [
            "start_process_without_supervisor",
            "restart_without_decision_receipt",
            "emit_audio_without_kernel_turn",
            "call_provider_from_daemon",
            "persist_raw_audio",
        ],
    }


def _supervisor_health_snapshot(
    *,
    gates: Mapping[str, bool],
    supervisor_contract: Mapping[str, Any],
    prerequisites_ready: bool,
) -> Mapping[str, Any]:
    blocked_reasons = sorted(key for key, passed in gates.items() if passed is not True)
    health_status = "ready_for_supervisor_implementation" if prerequisites_ready else "blocked"
    check_status = "planned_ready" if prerequisites_ready else "planned_blocked"

    return {
        "schema_version": SUPERVISOR_HEALTH_SCHEMA_VERSION,
        "status": health_status,
        "process_state": "not_started",
        "daemon_started": False,
        "process_launch_implemented": False,
        "snapshot_is_observational": True,
        "health_checks": [
            {
                "check": check,
                "status": check_status,
                "implemented": False,
                "starts_process": False,
            }
            for check in supervisor_contract.get("required_health_checks", [])
        ],
        "blocked_reasons": blocked_reasons,
        "lifecycle_state": "planned",
        "next_lifecycle_state": "preflight" if prerequisites_ready else "planned",
        "evidence_events": [
            "VOICE_DAEMON_HEALTH_CHECK_PLANNED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "guardrails": {
            "starts_process": False,
            "direct_provider_call_allowed": False,
            "raw_audio_persistence_allowed": False,
            "restart_allowed": False,
        },
    }


def _supervisor_preflight(
    *,
    gates: Mapping[str, bool],
    supervisor_contract: Mapping[str, Any],
    prerequisites_ready: bool,
) -> Mapping[str, Any]:
    failed_gates = sorted(key for key, passed in gates.items() if passed is not True)
    status = "ready_for_supervisor_execution_implementation" if prerequisites_ready else "blocked"

    return {
        "schema_version": SUPERVISOR_PREFLIGHT_SCHEMA_VERSION,
        "status": status,
        "process_launch_attempted": False,
        "daemon_started": False,
        "execution_implemented": False,
        "preflight_only": True,
        "required_runtime_state": {
            "settings_loaded": True,
            "kernel_boundary_created": True,
            "real_kernel_required": True,
            "mock_kernel_allowed": False,
        },
        "required_supervisor_methods": [
            "prepare_environment",
            "verify_kernel_health",
            "verify_livekit_room_connectivity",
            "verify_callback_router_roundtrip",
            "verify_kernel_event_normalizer_roundtrip",
            "verify_turn_receipt_roundtrip",
            "start_worker_process",
            "capture_health_snapshot",
            "stop_worker_process",
            "rollback",
        ],
        "health_checks": [
            {
                "check": check,
                "required_before_start": True,
                "implemented": False,
                "starts_process": False,
            }
            for check in supervisor_contract.get("required_health_checks", [])
        ],
        "failed_gates": failed_gates,
        "evidence_events": [
            "VOICE_DAEMON_PREFLIGHT_CHECKED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "guardrails": {
            "direct_provider_call_allowed": False,
            "direct_tool_execution_allowed": False,
            "raw_audio_persistence_allowed": False,
            "access_token_log_allowed": False,
            "start_without_decision_receipt_allowed": False,
            "start_without_supervisor_allowed": False,
            "restart_without_human_review_allowed": False,
        },
        "next_action": "implement_supervisor_execution" if prerequisites_ready else "fix_supervisor_preflight_gates",
    }
