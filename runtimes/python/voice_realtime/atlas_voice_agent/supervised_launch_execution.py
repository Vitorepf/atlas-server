from __future__ import annotations

from typing import Any, Mapping

from .supervised_launch_packet import (
    validate_final_start_executor_disabled_packet,
    validate_final_start_executor_enablement_gate_packet,
    validate_guarded_start_final_enablement_gate_contract_packet,
    validate_guarded_start_human_review_contract_packet,
    validate_guarded_start_policy_enablement_contract_packet,
    validate_guarded_start_activation_contract_packet,
    validate_guarded_start_dry_run_contract_packet,
    validate_guarded_start_policy_patch_review_contract_packet,
    validate_guarded_start_runtime_handoff_contract_packet,
    validate_guarded_start_simulation_contract_packet,
    validate_guarded_start_executor_disabled_packet,
    validate_guarded_start_executor_enablement_gate_packet,
    validate_reviewed_guarded_start_execution_contract_packet,
    validate_pre_start_health_checks_packet,
    validate_real_start_execution_contract_packet,
    validate_real_start_adapter_enablement_gate_packet,
    validate_real_start_adapter_disabled_packet,
    validate_reviewed_subprocess_start_execution_packet,
    validate_real_start_adapter_review_contract_packet,
    validate_reviewed_real_start_execution_contract_packet,
    validate_runtime_policy_enablement_review_packet,
    validate_subprocess_start_packet,
    validate_supervised_start_execution_review_packet,
    validate_supervised_launch_packet,
)


SCHEMA_VERSION = "atlas.voice_realtime.supervised_launch_execution.v1"
LAUNCH_EXECUTION_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.launch_execution_authorization.v1"
MANAGED_ENV_WRITE_EXECUTION_SCHEMA_VERSION = "atlas.voice_realtime.managed_env_write_execution.v1"
PRE_START_HEALTH_CHECKS_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.pre_start_health_checks_authorization.v1"
PRE_START_HEALTH_CHECKS_EXECUTION_SCHEMA_VERSION = "atlas.voice_realtime.pre_start_health_checks_execution.v1"
SUBPROCESS_START_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.subprocess_start_authorization.v1"
SUBPROCESS_START_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.subprocess_start_contract.v1"
REVIEWED_SUBPROCESS_START_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.reviewed_subprocess_start_authorization.v1"
REVIEWED_SUBPROCESS_START_EXECUTION_SCHEMA_VERSION = "atlas.voice_realtime.reviewed_subprocess_start_execution.v1"
REAL_START_ADAPTER_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.real_start_adapter_authorization.v1"
REAL_START_ADAPTER_DISABLED_SCHEMA_VERSION = "atlas.voice_realtime.real_start_adapter_disabled.v1"
REAL_START_ENABLEMENT_GATE_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.real_start_enablement_gate_authorization.v1"
REAL_START_ENABLEMENT_GATE_SCHEMA_VERSION = "atlas.voice_realtime.real_start_enablement_gate.v1"
RUNTIME_POLICY_ENABLEMENT_REVIEW_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.runtime_policy_enablement_review_authorization.v1"
RUNTIME_POLICY_ENABLEMENT_REVIEW_SCHEMA_VERSION = "atlas.voice_realtime.runtime_policy_enablement_review.v1"
REAL_START_ADAPTER_REVIEW_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.real_start_adapter_review_authorization.v1"
REAL_START_ADAPTER_REVIEW_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.real_start_adapter_review_contract.v1"
REVIEWED_REAL_START_EXECUTION_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.reviewed_real_start_execution_authorization.v1"
REVIEWED_REAL_START_EXECUTION_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.reviewed_real_start_execution_contract.v1"
FINAL_START_EXECUTOR_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.final_start_executor_authorization.v1"
FINAL_START_EXECUTOR_DISABLED_SCHEMA_VERSION = "atlas.voice_realtime.final_start_executor_disabled.v1"
FINAL_START_EXECUTOR_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.final_start_executor_enablement_authorization.v1"
FINAL_START_EXECUTOR_ENABLEMENT_GATE_SCHEMA_VERSION = "atlas.voice_realtime.final_start_executor_enablement_gate.v1"
SUPERVISED_START_EXECUTION_REVIEW_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.supervised_start_execution_review_authorization.v1"
SUPERVISED_START_EXECUTION_REVIEW_SCHEMA_VERSION = "atlas.voice_realtime.supervised_start_execution_review.v1"
REAL_START_EXECUTION_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.real_start_execution_authorization.v1"
REAL_START_EXECUTION_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.real_start_execution_contract.v1"
GUARDED_START_EXECUTOR_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_executor_authorization.v1"
GUARDED_START_EXECUTOR_DISABLED_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_executor_disabled.v1"
GUARDED_START_EXECUTOR_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_executor_enablement_authorization.v1"
GUARDED_START_EXECUTOR_ENABLEMENT_GATE_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_executor_enablement_gate.v1"
REVIEWED_GUARDED_START_EXECUTION_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.reviewed_guarded_start_execution_authorization.v1"
REVIEWED_GUARDED_START_EXECUTION_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.reviewed_guarded_start_execution_contract.v1"
GUARDED_START_DRY_RUN_PLAN_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_dry_run_plan.v1"
GUARDED_START_DRY_RUN_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_dry_run_contract.v1"
GUARDED_START_SIMULATION_PLAN_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_simulation_plan.v1"
GUARDED_START_SIMULATION_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_simulation_contract.v1"
GUARDED_START_RUNTIME_HANDOFF_PLAN_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_runtime_handoff_plan.v1"
GUARDED_START_RUNTIME_HANDOFF_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_runtime_handoff_contract.v1"
GUARDED_START_POLICY_PATCH_REVIEW_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_policy_patch_review_authorization.v1"
GUARDED_START_POLICY_PATCH_REVIEW_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_policy_patch_review_contract.v1"
GUARDED_START_HUMAN_REVIEW_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_human_review_authorization.v1"
GUARDED_START_HUMAN_REVIEW_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_human_review_contract.v1"
GUARDED_START_FINAL_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_final_enablement_authorization.v1"
GUARDED_START_FINAL_ENABLEMENT_GATE_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_final_enablement_gate.v1"
GUARDED_START_POLICY_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_policy_enablement_authorization.v1"
GUARDED_START_POLICY_ENABLEMENT_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_policy_enablement_contract.v1"
GUARDED_START_ACTIVATION_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_activation_authorization.v1"
GUARDED_START_ACTIVATION_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_activation_contract.v1"


def inspect_supervised_launch_execution(
    *,
    supervisor_execution: Mapping[str, Any],
    launch_authorization_contract: Mapping[str, Any],
    managed_env_write_execution: Mapping[str, Any] | None = None,
    launch_execution_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Evaluate the final pre-subprocess launch boundary without starting.

    This is intentionally one step after managed env file writing and one step
    before subprocess execution. It proves every prerequisite a future real
    launcher must consume while keeping daemon start disabled.
    """

    blueprint = supervisor_execution.get("process_adapter_blueprint", {})
    env_execution = managed_env_write_execution or {}
    launch_authorization = launch_execution_authorization or {}

    supervisor_ready = (
        supervisor_execution.get("schema_version") == "atlas.voice_realtime.daemon_supervisor_execution.v1"
        and supervisor_execution.get("status") == "ready_for_process_adapter_implementation"
        and supervisor_execution.get("process_launch_attempted") is False
        and supervisor_execution.get("daemon_started") is False
        and isinstance(blueprint, Mapping)
        and blueprint.get("schema_version") == "atlas.voice_realtime.daemon_process_adapter_blueprint.v1"
        and blueprint.get("launch_allowed") is False
    )
    launch_contract_ready = (
        launch_authorization_contract.get("schema_version") == "atlas.voice_realtime.launch_authorization_contract.v1"
        and launch_authorization_contract.get("status") == "authorized_for_implementation_not_launch"
        and launch_authorization_contract.get("launch_allowed") is False
        and launch_authorization_contract.get("process_launch_attempted") is False
        and launch_authorization_contract.get("daemon_started") is False
    )
    env_execution_ready = (
        env_execution.get("schema_version") == MANAGED_ENV_WRITE_EXECUTION_SCHEMA_VERSION
        and env_execution.get("status") == "written_placeholder_env"
        and env_execution.get("env_file_written") is True
        and env_execution.get("process_launch_attempted") is False
        and env_execution.get("daemon_started") is False
        and env_execution.get("secret_values_present_in_output") is False
        and isinstance(env_execution.get("content_sha256"), str)
        and env_execution.get("content_sha256") != ""
    )
    launch_execution_authorized = (
        launch_authorization.get("schema_version") == LAUNCH_EXECUTION_AUTHORIZATION_SCHEMA_VERSION
        and launch_authorization.get("status") == "approved_for_subprocess_implementation"
        and launch_authorization.get("process_launch_allowed") is False
        and launch_authorization.get("subprocess_implementation_allowed") is True
        and isinstance(launch_authorization.get("decision_receipt_id"), str)
        and launch_authorization.get("decision_receipt_id") != ""
    )
    ready_for_subprocess_implementation = (
        supervisor_ready
        and launch_contract_ready
        and env_execution_ready
        and launch_execution_authorized
    )

    return validate_supervised_launch_packet({
        "schema_version": SCHEMA_VERSION,
        "status": "ready_for_subprocess_implementation" if ready_for_subprocess_implementation else "blocked",
        "implementation_status": "execution_contract_no_subprocess_start",
        "supervisor_execution_ready": supervisor_ready,
        "launch_execution_implemented": True,
        "subprocess_launch_implemented": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "launch_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "pre_start_health_checks_execution_available": True,
        "pre_start_health_checks_executed": False,
        "managed_env_write_execution_schema_version": env_execution.get("schema_version"),
        "managed_env_file_written": env_execution.get("env_file_written") is True,
        "managed_env_content_sha256": env_execution.get("content_sha256") if env_execution_ready else None,
        "managed_env_target_path": "<managed-env-file-written>" if env_execution_ready else "<not-written>",
        "argv_redacted": _redacted_argv(blueprint if isinstance(blueprint, Mapping) else {}),
        "required_pre_start_checks": list(launch_authorization_contract.get("required_pre_start_checks", [])),
        "required_receipts": list(launch_authorization_contract.get("required_receipts", [])),
        "gates": {
            "supervisor_execution_ready": supervisor_ready,
            "launch_authorization_contract_ready": launch_contract_ready,
            "managed_env_write_execution_ready": env_execution_ready,
            "launch_execution_authorization_ready": launch_execution_authorized,
            "process_launch_disabled": True,
            "secrets_redacted": True,
            "subprocess_import_disabled": True,
            "pre_start_health_checks_execution_available": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
        },
        "forbidden_shortcuts": [
            "import_subprocess_from_launch_execution_contract",
            "start_without_launch_execution_decision_receipt",
            "start_without_pre_start_health_checks",
            "start_after_placeholder_env_without_supervisor_receipt",
            "call_provider_from_launch_execution",
            "log_managed_env_file_contents",
        ],
        "evidence_events": [
            "VOICE_DAEMON_SUPERVISED_LAUNCH_EVALUATED",
            "VOICE_DAEMON_SUBPROCESS_START_BLOCKED",
        ],
        "next_action": (
            "implement_subprocess_start_after_health_checks"
            if ready_for_subprocess_implementation
            else "fix_supervised_launch_execution_prerequisites"
        ),
    })


def execute_pre_start_health_checks(
    *,
    supervised_launch_execution: Mapping[str, Any],
    health_check_results: list[Mapping[str, Any]],
    health_check_authorization: Mapping[str, Any],
) -> Mapping[str, Any]:
    """Validate pre-start health checks without starting a subprocess."""

    launch_ready = (
        supervised_launch_execution.get("schema_version") == SCHEMA_VERSION
        and supervised_launch_execution.get("status") == "ready_for_subprocess_implementation"
        and supervised_launch_execution.get("process_launch_attempted") is False
        and supervised_launch_execution.get("daemon_started") is False
        and supervised_launch_execution.get("pre_start_health_checks_execution_available") is True
    )
    authorization_ready = (
        health_check_authorization.get("schema_version") == PRE_START_HEALTH_CHECKS_AUTHORIZATION_SCHEMA_VERSION
        and health_check_authorization.get("status") == "approved"
        and health_check_authorization.get("health_checks_allowed") is True
        and health_check_authorization.get("process_launch_allowed") is False
        and isinstance(health_check_authorization.get("decision_receipt_id"), str)
        and health_check_authorization.get("decision_receipt_id") != ""
    )
    required_checks = list(supervised_launch_execution.get("required_pre_start_checks", []))
    results_by_check = {
        str(result.get("check")): result
        for result in health_check_results
        if isinstance(result, Mapping)
    }
    missing_checks = [
        check for check in required_checks
        if check not in results_by_check
    ]
    invalid_checks = [
        check for check in required_checks
        if check in results_by_check and not _health_check_result_passed(results_by_check[check])
    ]
    checks_passed = missing_checks == [] and invalid_checks == []
    can_pass = launch_ready and authorization_ready and checks_passed

    return validate_pre_start_health_checks_packet({
        "schema_version": PRE_START_HEALTH_CHECKS_EXECUTION_SCHEMA_VERSION,
        "status": "passed_no_process_start" if can_pass else "blocked",
        "health_checks_executed": True,
        "pre_start_health_checks_executed": True,
        "required_pre_start_checks": required_checks,
        "observed_check_count": len(health_check_results),
        "missing_checks": missing_checks,
        "invalid_checks": invalid_checks,
        "process_launch_attempted": False,
        "daemon_started": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "provider_calls_made": False,
        "tool_calls_made": False,
        "raw_audio_touched": False,
        "gates": {
            "supervised_launch_execution_ready": launch_ready,
            "health_check_authorization_ready": authorization_ready,
            "required_checks_present": missing_checks == [],
            "required_checks_passed": invalid_checks == [],
            "process_launch_disabled": True,
            "provider_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "evidence_events": [
            "VOICE_DAEMON_PRE_START_HEALTH_CHECKS_EVALUATED",
            "VOICE_DAEMON_SUBPROCESS_START_BLOCKED",
        ],
        "next_action": (
            "implement_subprocess_start_contract_after_pre_start_checks"
            if can_pass
            else "fix_pre_start_health_check_prerequisites"
        ),
    })


def inspect_subprocess_start_contract(
    *,
    supervised_launch_execution: Mapping[str, Any],
    pre_start_health_checks_execution: Mapping[str, Any] | None = None,
    subprocess_start_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Inspect the reviewed subprocess start boundary without launching.

    The contract is the last static checkpoint before a future implementation is
    allowed to import subprocess. It can become "ready" only after the managed
    env write, launch execution receipt and pre-start checks are all proven by
    separate artifacts. Even when ready, this function still starts nothing.
    """

    health_checks = pre_start_health_checks_execution or {}
    authorization = subprocess_start_authorization or {}

    launch_ready = (
        supervised_launch_execution.get("schema_version") == SCHEMA_VERSION
        and supervised_launch_execution.get("status") == "ready_for_subprocess_implementation"
        and supervised_launch_execution.get("process_launch_attempted") is False
        and supervised_launch_execution.get("daemon_started") is False
        and supervised_launch_execution.get("subprocess_launch_implemented") is False
        and supervised_launch_execution.get("pre_start_health_checks_execution_available") is True
    )
    pre_start_checks_ready = (
        health_checks.get("schema_version") == PRE_START_HEALTH_CHECKS_EXECUTION_SCHEMA_VERSION
        and health_checks.get("status") == "passed_no_process_start"
        and health_checks.get("health_checks_executed") is True
        and health_checks.get("pre_start_health_checks_executed") is True
        and health_checks.get("process_launch_attempted") is False
        and health_checks.get("daemon_started") is False
        and health_checks.get("subprocess_module_imported") is False
        and health_checks.get("livekit_sdk_imported") is False
        and health_checks.get("provider_calls_made") is False
        and health_checks.get("tool_calls_made") is False
        and health_checks.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == SUBPROCESS_START_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_start_contract"
        and authorization.get("subprocess_contract_allowed") is True
        and authorization.get("process_launch_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_reviewed_start = launch_ready and pre_start_checks_ready and authorization_ready

    return validate_subprocess_start_packet({
        "schema_version": SUBPROCESS_START_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_reviewed_subprocess_start_implementation"
            if ready_for_reviewed_start
            else "blocked"
        ),
        "implementation_status": "start_contract_no_subprocess_import",
        "subprocess_start_contract_implemented": True,
        "subprocess_launch_implemented": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "launch_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "provider_calls_made": False,
        "tool_calls_made": False,
        "raw_audio_touched": False,
        "decision_receipt_id": authorization.get("decision_receipt_id") if authorization_ready else None,
        "pre_start_health_checks_execution_schema_version": health_checks.get("schema_version"),
        "pre_start_health_checks_executed": health_checks.get("pre_start_health_checks_executed") is True,
        "pre_start_health_checks_status": health_checks.get("status"),
        "argv_redacted": list(supervised_launch_execution.get("argv_redacted", [])),
        "env_file_ref": "<managed-env-file-written>",
        "required_runtime_guards": [
            "decision_receipt_bound_to_process",
            "health_checks_passed_same_receipt_chain",
            "stdout_stderr_sanitized",
            "pid_file_guarded",
            "startup_timeout_guard",
            "rollback_on_failed_start",
            "no_provider_calls_in_adapter",
            "raw_audio_not_persisted",
        ],
        "gates": {
            "supervised_launch_ready": launch_ready,
            "pre_start_health_checks_passed": pre_start_checks_ready,
            "subprocess_start_authorization_ready": authorization_ready,
            "process_launch_disabled": True,
            "secrets_redacted": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "import_subprocess_from_start_contract",
            "start_without_pre_start_health_checks_execution",
            "start_without_subprocess_start_decision_receipt",
            "start_if_authorization_allows_process_launch",
            "call_provider_from_subprocess_start_contract",
            "persist_raw_audio_from_start_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_SUBPROCESS_START_CONTRACT_EVALUATED",
            "VOICE_DAEMON_SUBPROCESS_START_BLOCKED",
        ],
        "next_action": (
            "implement_real_subprocess_start_after_final_review"
            if ready_for_reviewed_start
            else "fix_subprocess_start_contract_prerequisites"
        ),
    })


def inspect_reviewed_subprocess_start_execution(
    *,
    subprocess_start_contract: Mapping[str, Any],
    reviewed_start_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Inspect the final reviewed execution boundary without starting.

    This is the last fail-closed packet before a real process adapter may be
    implemented. It deliberately does not import subprocess, start LiveKit or
    touch audio/provider/tool paths. Its job is to make the future real start
    implementation consume an explicit contract instead of hidden assumptions.
    """

    authorization = reviewed_start_authorization or {}
    contract_ready = (
        subprocess_start_contract.get("schema_version") == SUBPROCESS_START_CONTRACT_SCHEMA_VERSION
        and subprocess_start_contract.get("status") == "ready_for_reviewed_subprocess_start_implementation"
        and subprocess_start_contract.get("subprocess_start_contract_implemented") is True
        and subprocess_start_contract.get("subprocess_launch_implemented") is False
        and subprocess_start_contract.get("process_launch_attempted") is False
        and subprocess_start_contract.get("daemon_started") is False
        and subprocess_start_contract.get("subprocess_module_imported") is False
        and subprocess_start_contract.get("livekit_sdk_imported") is False
        and subprocess_start_contract.get("provider_calls_made") is False
        and subprocess_start_contract.get("tool_calls_made") is False
        and subprocess_start_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == REVIEWED_SUBPROCESS_START_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_real_start_implementation_plan"
        and authorization.get("reviewed_execution_allowed") is True
        and authorization.get("real_process_start_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_real_start_implementation = contract_ready and authorization_ready

    return validate_reviewed_subprocess_start_execution_packet({
        "schema_version": REVIEWED_SUBPROCESS_START_EXECUTION_SCHEMA_VERSION,
        "status": (
            "ready_for_real_start_implementation"
            if ready_for_real_start_implementation
            else "blocked"
        ),
        "implementation_status": "reviewed_execution_plan_no_subprocess_import",
        "reviewed_subprocess_start_execution_implemented": True,
        "real_subprocess_start_implemented": False,
        "subprocess_launch_implemented": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "launch_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "provider_calls_made": False,
        "tool_calls_made": False,
        "raw_audio_touched": False,
        "decision_receipt_id": authorization.get("decision_receipt_id") if authorization_ready else None,
        "subprocess_start_contract_schema_version": subprocess_start_contract.get("schema_version"),
        "subprocess_start_contract_status": subprocess_start_contract.get("status"),
        "argv_redacted": list(subprocess_start_contract.get("argv_redacted", [])),
        "env_file_ref": subprocess_start_contract.get("env_file_ref", "<managed-env-file-written>"),
        "required_runtime_guards": list(subprocess_start_contract.get("required_runtime_guards", [])),
        "required_real_start_controls": [
            "fresh_decision_receipt_bound_to_current_envelope",
            "subprocess_import_localized_to_real_start_adapter",
            "stdout_stderr_sanitized_before_ledger",
            "pid_file_written_with_0600_permissions",
            "startup_timeout_enforced",
            "rollback_registered_before_start",
            "health_probe_after_start_before_ready",
            "no_provider_calls_inside_process_adapter",
            "raw_audio_not_persisted",
        ],
        "gates": {
            "subprocess_start_contract_ready": contract_ready,
            "review_authorization_ready": authorization_ready,
            "process_launch_disabled": True,
            "real_start_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
            "rollback_guard_required": True,
            "pid_file_guard_required": True,
            "stdout_stderr_sanitization_required": True,
        },
        "forbidden_shortcuts": [
            "import_subprocess_from_reviewed_execution_plan",
            "start_from_reviewed_execution_plan",
            "start_without_fresh_decision_receipt",
            "start_without_registered_rollback",
            "write_pid_file_without_permission_guard",
            "stream_stdout_stderr_to_ledger_unsanitized",
            "call_provider_from_process_adapter",
            "persist_raw_audio_from_process_adapter",
        ],
        "evidence_events": [
            "VOICE_DAEMON_REVIEWED_SUBPROCESS_START_EVALUATED",
            "VOICE_DAEMON_SUBPROCESS_START_BLOCKED",
        ],
        "next_action": (
            "implement_real_start_adapter_disabled_by_default"
            if ready_for_real_start_implementation
            else "fix_reviewed_subprocess_start_execution_prerequisites"
        ),
    })


def inspect_real_start_adapter_disabled_by_default(
    *,
    reviewed_subprocess_start_execution: Mapping[str, Any],
    real_start_adapter_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Describe the real start adapter while keeping it disabled.

    This packet is intentionally not a launcher. It proves the runtime has a
    governed adapter boundary and all mandatory controls for a future launcher,
    while keeping subprocess imports and daemon start impossible by contract.
    """

    authorization = real_start_adapter_authorization or {}
    reviewed_execution_ready = (
        reviewed_subprocess_start_execution.get("schema_version") == REVIEWED_SUBPROCESS_START_EXECUTION_SCHEMA_VERSION
        and reviewed_subprocess_start_execution.get("status") == "ready_for_real_start_implementation"
        and reviewed_subprocess_start_execution.get("reviewed_subprocess_start_execution_implemented") is True
        and reviewed_subprocess_start_execution.get("real_subprocess_start_implemented") is False
        and reviewed_subprocess_start_execution.get("process_launch_attempted") is False
        and reviewed_subprocess_start_execution.get("daemon_started") is False
        and reviewed_subprocess_start_execution.get("subprocess_module_imported") is False
        and reviewed_subprocess_start_execution.get("livekit_sdk_imported") is False
        and reviewed_subprocess_start_execution.get("provider_calls_made") is False
        and reviewed_subprocess_start_execution.get("tool_calls_made") is False
        and reviewed_subprocess_start_execution.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == REAL_START_ADAPTER_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_disabled_adapter_contract"
        and authorization.get("disabled_adapter_contract_allowed") is True
        and authorization.get("real_process_start_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("start_enabled") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_disabled = reviewed_execution_ready and authorization_ready

    return validate_real_start_adapter_disabled_packet({
        "schema_version": REAL_START_ADAPTER_DISABLED_SCHEMA_VERSION,
        "status": "ready_disabled_by_default" if ready_disabled else "blocked",
        "implementation_status": "real_start_adapter_contract_no_process_start",
        "real_start_adapter_contract_implemented": True,
        "real_start_adapter_enabled": False,
        "real_subprocess_start_implemented": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "launch_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "provider_calls_made": False,
        "tool_calls_made": False,
        "raw_audio_touched": False,
        "decision_receipt_id": authorization.get("decision_receipt_id") if authorization_ready else None,
        "reviewed_subprocess_start_execution_schema_version": reviewed_subprocess_start_execution.get("schema_version"),
        "reviewed_subprocess_start_execution_status": reviewed_subprocess_start_execution.get("status"),
        "argv_redacted": list(reviewed_subprocess_start_execution.get("argv_redacted", [])),
        "env_file_ref": reviewed_subprocess_start_execution.get("env_file_ref", "<managed-env-file-written>"),
        "disabled_by_default_controls": [
            "runtime_policy_must_enable_start_explicitly",
            "fresh_decision_receipt_required_at_start_time",
            "human_review_required_for_enablement",
            "rollback_registered_before_any_process_start",
            "pid_file_guard_required",
            "stdout_stderr_sanitization_required",
            "post_start_health_probe_required_before_ready",
            "process_adapter_forbidden_from_provider_or_tool_calls",
            "raw_audio_persistence_forbidden",
        ],
        "gates": {
            "reviewed_subprocess_start_execution_ready": reviewed_execution_ready,
            "real_start_adapter_authorization_ready": authorization_ready,
            "start_disabled_by_default": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
            "fresh_receipt_required": True,
            "human_review_required": True,
            "rollback_required": True,
        },
        "forbidden_shortcuts": [
            "enable_start_from_disabled_adapter_contract",
            "import_subprocess_from_disabled_adapter_contract",
            "start_without_runtime_policy_enablement",
            "start_without_fresh_decision_receipt",
            "start_without_registered_rollback",
            "call_provider_from_real_start_adapter",
            "persist_raw_audio_from_real_start_adapter",
        ],
        "evidence_events": [
            "VOICE_DAEMON_REAL_START_ADAPTER_DECLARED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_real_start_adapter_enablement_gate"
            if ready_disabled
            else "fix_real_start_adapter_disabled_prerequisites"
        ),
    })


def inspect_real_start_adapter_enablement_gate(
    *,
    real_start_adapter_disabled: Mapping[str, Any],
    enablement_gate_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Evaluate the policy enablement gate without enabling real start.

    This is the first gate after the disabled adapter contract. It proves that
    a future start enablement must be backed by policy patch, human review,
    rollback and a fresh receipt, while keeping subprocess imports and process
    launch disabled.
    """

    authorization = enablement_gate_authorization or {}
    disabled_adapter_ready = (
        real_start_adapter_disabled.get("schema_version") == REAL_START_ADAPTER_DISABLED_SCHEMA_VERSION
        and real_start_adapter_disabled.get("status") == "ready_disabled_by_default"
        and real_start_adapter_disabled.get("real_start_adapter_contract_implemented") is True
        and real_start_adapter_disabled.get("real_start_adapter_enabled") is False
        and real_start_adapter_disabled.get("real_subprocess_start_implemented") is False
        and real_start_adapter_disabled.get("process_launch_attempted") is False
        and real_start_adapter_disabled.get("daemon_started") is False
        and real_start_adapter_disabled.get("subprocess_module_imported") is False
        and real_start_adapter_disabled.get("livekit_sdk_imported") is False
        and real_start_adapter_disabled.get("provider_calls_made") is False
        and real_start_adapter_disabled.get("tool_calls_made") is False
        and real_start_adapter_disabled.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == REAL_START_ENABLEMENT_GATE_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_start_enablement_gate"
        and authorization.get("start_enablement_gate_allowed") is True
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("policy_patch_required") is True
        and authorization.get("human_review_required") is True
        and authorization.get("rollback_required") is True
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_policy_enablement_review = disabled_adapter_ready and authorization_ready

    return validate_real_start_adapter_enablement_gate_packet({
        "schema_version": REAL_START_ENABLEMENT_GATE_SCHEMA_VERSION,
        "status": (
            "ready_for_policy_enablement_review"
            if ready_for_policy_enablement_review
            else "blocked"
        ),
        "implementation_status": "enablement_gate_no_real_start",
        "real_start_enablement_gate_implemented": True,
        "real_start_adapter_enabled": False,
        "start_execution_allowed": False,
        "real_subprocess_start_implemented": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "launch_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "provider_calls_made": False,
        "tool_calls_made": False,
        "raw_audio_touched": False,
        "decision_receipt_id": authorization.get("decision_receipt_id") if authorization_ready else None,
        "real_start_adapter_disabled_schema_version": real_start_adapter_disabled.get("schema_version"),
        "real_start_adapter_disabled_status": real_start_adapter_disabled.get("status"),
        "policy_enablement_controls": [
            "runtime_policy_patch_required_before_start_enabled",
            "human_review_required_for_start_enablement",
            "fresh_decision_receipt_required_at_enablement_time",
            "rollback_plan_required_before_start_enabled",
            "enablement_review_must_reference_current_evidence_bundle",
            "start_execution_remains_disabled_until_real_start_adapter_review",
            "subprocess_import_remains_disabled_until_real_start_adapter_review",
            "provider_tool_and_raw_audio_paths_remain_forbidden",
        ],
        "gates": {
            "real_start_adapter_disabled_ready": disabled_adapter_ready,
            "enablement_gate_authorization_ready": authorization_ready,
            "policy_patch_required": True,
            "human_review_required": True,
            "rollback_required": True,
            "fresh_receipt_required": True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "enable_start_without_policy_patch",
            "enable_start_without_human_review",
            "enable_start_without_fresh_decision_receipt",
            "enable_start_without_registered_rollback",
            "import_subprocess_from_enablement_gate",
            "start_process_from_enablement_gate",
            "call_provider_from_enablement_gate",
            "persist_raw_audio_from_enablement_gate",
        ],
        "evidence_events": [
            "VOICE_DAEMON_REAL_START_ENABLEMENT_GATE_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_runtime_policy_enablement_review"
            if ready_for_policy_enablement_review
            else "fix_real_start_enablement_gate_prerequisites"
        ),
    })


def inspect_runtime_policy_enablement_review(
    *,
    real_start_enablement_gate: Mapping[str, Any],
    policy_review_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Evaluate policy-review readiness while keeping runtime start disabled."""

    authorization = policy_review_authorization or {}
    enablement_gate_ready = (
        real_start_enablement_gate.get("schema_version") == REAL_START_ENABLEMENT_GATE_SCHEMA_VERSION
        and real_start_enablement_gate.get("status") == "ready_for_policy_enablement_review"
        and real_start_enablement_gate.get("real_start_enablement_gate_implemented") is True
        and real_start_enablement_gate.get("real_start_adapter_enabled") is False
        and real_start_enablement_gate.get("start_execution_allowed") is False
        and real_start_enablement_gate.get("real_subprocess_start_implemented") is False
        and real_start_enablement_gate.get("process_launch_attempted") is False
        and real_start_enablement_gate.get("daemon_started") is False
        and real_start_enablement_gate.get("subprocess_module_imported") is False
        and real_start_enablement_gate.get("livekit_sdk_imported") is False
        and real_start_enablement_gate.get("provider_calls_made") is False
        and real_start_enablement_gate.get("tool_calls_made") is False
        and real_start_enablement_gate.get("raw_audio_touched") is False
    )
    reviewed_bundle_hash = authorization.get("reviewed_bundle_hash")
    authorization_ready = (
        authorization.get("schema_version") == RUNTIME_POLICY_ENABLEMENT_REVIEW_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_runtime_policy_enablement_review"
        and authorization.get("runtime_policy_review_allowed") is True
        and authorization.get("policy_patch_attached") is True
        and isinstance(reviewed_bundle_hash, str)
        and len(reviewed_bundle_hash) == 64
        and authorization.get("human_review_required") is True
        and authorization.get("rollback_required") is True
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_real_start_adapter_review = enablement_gate_ready and authorization_ready

    return validate_runtime_policy_enablement_review_packet({
        "schema_version": RUNTIME_POLICY_ENABLEMENT_REVIEW_SCHEMA_VERSION,
        "status": (
            "ready_for_real_start_adapter_review"
            if ready_for_real_start_adapter_review
            else "blocked"
        ),
        "implementation_status": "policy_enablement_review_no_start",
        "runtime_policy_enablement_review_implemented": True,
        "runtime_policy_start_enabled": False,
        "real_start_adapter_enabled": False,
        "start_execution_allowed": False,
        "real_subprocess_start_implemented": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "launch_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "provider_calls_made": False,
        "tool_calls_made": False,
        "raw_audio_touched": False,
        "decision_receipt_id": authorization.get("decision_receipt_id") if authorization_ready else None,
        "reviewed_bundle_hash": reviewed_bundle_hash if authorization_ready else None,
        "real_start_enablement_gate_schema_version": real_start_enablement_gate.get("schema_version"),
        "real_start_enablement_gate_status": real_start_enablement_gate.get("status"),
        "required_policy_review_controls": [
            "policy_patch_attached_to_current_evidence_bundle",
            "reviewed_bundle_hash_required",
            "human_review_required_for_policy_enablement",
            "rollback_plan_required_before_policy_enablement",
            "decision_receipt_required_for_policy_review",
            "start_execution_remains_disabled_after_policy_review",
            "real_start_adapter_review_required_after_policy_review",
        ],
        "gates": {
            "real_start_enablement_gate_ready": enablement_gate_ready,
            "policy_review_authorization_ready": authorization_ready,
            "policy_patch_attached": authorization.get("policy_patch_attached") is True,
            "reviewed_bundle_hash_required": isinstance(reviewed_bundle_hash, str) and len(reviewed_bundle_hash) == 64,
            "human_review_required": True,
            "rollback_required": True,
            "fresh_receipt_required": True,
            "runtime_policy_start_disabled": True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "enable_runtime_policy_without_reviewed_bundle_hash",
            "enable_runtime_policy_without_policy_patch",
            "enable_runtime_policy_without_human_review",
            "enable_runtime_policy_without_rollback",
            "start_process_from_policy_enablement_review",
            "import_subprocess_from_policy_enablement_review",
            "call_provider_from_policy_enablement_review",
            "persist_raw_audio_from_policy_enablement_review",
        ],
        "evidence_events": [
            "VOICE_DAEMON_RUNTIME_POLICY_ENABLEMENT_REVIEW_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_real_start_adapter_review_contract"
            if ready_for_real_start_adapter_review
            else "fix_runtime_policy_enablement_review_prerequisites"
        ),
    })


def inspect_real_start_adapter_review_contract(
    *,
    runtime_policy_enablement_review: Mapping[str, Any],
    real_start_review_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Define the reviewed real-start adapter contract without starting it."""

    authorization = real_start_review_authorization or {}
    policy_review_ready = (
        runtime_policy_enablement_review.get("schema_version") == RUNTIME_POLICY_ENABLEMENT_REVIEW_SCHEMA_VERSION
        and runtime_policy_enablement_review.get("status") == "ready_for_real_start_adapter_review"
        and runtime_policy_enablement_review.get("runtime_policy_enablement_review_implemented") is True
        and runtime_policy_enablement_review.get("runtime_policy_start_enabled") is False
        and runtime_policy_enablement_review.get("real_start_adapter_enabled") is False
        and runtime_policy_enablement_review.get("start_execution_allowed") is False
        and runtime_policy_enablement_review.get("real_subprocess_start_implemented") is False
        and runtime_policy_enablement_review.get("process_launch_attempted") is False
        and runtime_policy_enablement_review.get("daemon_started") is False
        and runtime_policy_enablement_review.get("subprocess_module_imported") is False
        and runtime_policy_enablement_review.get("livekit_sdk_imported") is False
        and runtime_policy_enablement_review.get("provider_calls_made") is False
        and runtime_policy_enablement_review.get("tool_calls_made") is False
        and runtime_policy_enablement_review.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == REAL_START_ADAPTER_REVIEW_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_real_start_adapter_review_contract"
        and authorization.get("real_start_adapter_review_allowed") is True
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("pid_file_guard_required") is True
        and authorization.get("startup_timeout_required") is True
        and authorization.get("post_start_health_probe_required") is True
        and authorization.get("stdout_stderr_sanitization_required") is True
        and authorization.get("rollback_required") is True
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_reviewed_real_start_execution = policy_review_ready and authorization_ready

    return validate_real_start_adapter_review_contract_packet({
        "schema_version": REAL_START_ADAPTER_REVIEW_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_reviewed_real_start_execution_contract"
            if ready_for_reviewed_real_start_execution
            else "blocked"
        ),
        "implementation_status": "real_start_review_contract_no_process_start",
        "real_start_adapter_review_contract_implemented": True,
        "runtime_policy_start_enabled": False,
        "real_start_adapter_enabled": False,
        "start_execution_allowed": False,
        "real_subprocess_start_implemented": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "launch_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "provider_calls_made": False,
        "tool_calls_made": False,
        "raw_audio_touched": False,
        "decision_receipt_id": authorization.get("decision_receipt_id") if authorization_ready else None,
        "runtime_policy_enablement_review_schema_version": runtime_policy_enablement_review.get("schema_version"),
        "runtime_policy_enablement_review_status": runtime_policy_enablement_review.get("status"),
        "reviewed_bundle_hash": runtime_policy_enablement_review.get("reviewed_bundle_hash") if policy_review_ready else None,
        "required_real_start_adapter_controls": [
            "subprocess_import_localized_to_real_start_adapter_only",
            "pid_file_written_with_0600_permissions",
            "startup_timeout_required_before_ready",
            "post_start_health_probe_required_before_ready",
            "stdout_stderr_sanitized_before_ledger",
            "rollback_registered_before_start_execution",
            "decision_receipt_bound_to_current_reviewed_bundle",
            "provider_tool_and_raw_audio_paths_remain_forbidden",
        ],
        "gates": {
            "runtime_policy_enablement_review_ready": policy_review_ready,
            "real_start_review_authorization_ready": authorization_ready,
            "pid_file_guard_required": True,
            "startup_timeout_required": True,
            "post_start_health_probe_required": True,
            "stdout_stderr_sanitization_required": True,
            "rollback_required": True,
            "fresh_receipt_required": True,
            "runtime_policy_start_disabled": True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "import_subprocess_from_real_start_review_contract",
            "start_process_from_real_start_review_contract",
            "enable_runtime_policy_from_real_start_review_contract",
            "skip_pid_file_guard",
            "skip_startup_timeout",
            "skip_post_start_health_probe",
            "stream_stdout_stderr_to_ledger_unsanitized",
            "call_provider_from_real_start_review_contract",
            "persist_raw_audio_from_real_start_review_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_REAL_START_ADAPTER_REVIEW_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_reviewed_real_start_execution_contract"
            if ready_for_reviewed_real_start_execution
            else "fix_real_start_adapter_review_contract_prerequisites"
        ),
    })


def inspect_reviewed_real_start_execution_contract(
    *,
    real_start_adapter_review_contract: Mapping[str, Any],
    reviewed_real_start_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Describe the final reviewed real-start execution contract, still inert."""

    authorization = reviewed_real_start_authorization or {}
    adapter_review_ready = (
        real_start_adapter_review_contract.get("schema_version") == REAL_START_ADAPTER_REVIEW_CONTRACT_SCHEMA_VERSION
        and real_start_adapter_review_contract.get("status") == "ready_for_reviewed_real_start_execution_contract"
        and real_start_adapter_review_contract.get("real_start_adapter_review_contract_implemented") is True
        and real_start_adapter_review_contract.get("runtime_policy_start_enabled") is False
        and real_start_adapter_review_contract.get("real_start_adapter_enabled") is False
        and real_start_adapter_review_contract.get("start_execution_allowed") is False
        and real_start_adapter_review_contract.get("real_subprocess_start_implemented") is False
        and real_start_adapter_review_contract.get("process_launch_attempted") is False
        and real_start_adapter_review_contract.get("daemon_started") is False
        and real_start_adapter_review_contract.get("subprocess_module_imported") is False
        and real_start_adapter_review_contract.get("livekit_sdk_imported") is False
        and real_start_adapter_review_contract.get("provider_calls_made") is False
        and real_start_adapter_review_contract.get("tool_calls_made") is False
        and real_start_adapter_review_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == REVIEWED_REAL_START_EXECUTION_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_reviewed_real_start_execution_contract"
        and authorization.get("reviewed_real_start_execution_allowed") is True
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("final_pre_start_receipt_required") is True
        and authorization.get("post_start_ready_event_required") is True
        and authorization.get("rollback_rehearsal_required") is True
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_start_execution_implementation = adapter_review_ready and authorization_ready

    return validate_reviewed_real_start_execution_contract_packet({
        "schema_version": REVIEWED_REAL_START_EXECUTION_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_start_execution_implementation"
            if ready_for_start_execution_implementation
            else "blocked"
        ),
        "implementation_status": "reviewed_real_start_execution_contract_no_process_start",
        "reviewed_real_start_execution_contract_implemented": True,
        "runtime_policy_start_enabled": False,
        "real_start_adapter_enabled": False,
        "start_execution_allowed": False,
        "real_subprocess_start_implemented": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "launch_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "provider_calls_made": False,
        "tool_calls_made": False,
        "raw_audio_touched": False,
        "decision_receipt_id": authorization.get("decision_receipt_id") if authorization_ready else None,
        "real_start_adapter_review_contract_schema_version": real_start_adapter_review_contract.get("schema_version"),
        "real_start_adapter_review_contract_status": real_start_adapter_review_contract.get("status"),
        "required_start_execution_controls": [
            "final_pre_start_decision_receipt_required",
            "single_start_attempt_per_receipt",
            "subprocess_import_allowed_only_inside_final_start_executor",
            "pid_file_guard_checked_before_ready",
            "post_start_ready_event_required_before_success",
            "rollback_rehearsal_required_before_start_executor",
            "provider_tool_and_raw_audio_paths_remain_forbidden",
        ],
        "gates": {
            "real_start_adapter_review_contract_ready": adapter_review_ready,
            "reviewed_real_start_authorization_ready": authorization_ready,
            "final_pre_start_receipt_required": True,
            "post_start_ready_event_required": True,
            "rollback_rehearsal_required": True,
            "runtime_policy_start_disabled": True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_reviewed_real_start_execution_contract",
            "import_subprocess_from_reviewed_real_start_execution_contract",
            "reuse_old_start_receipt",
            "start_twice_with_same_receipt",
            "mark_ready_without_post_start_event",
            "skip_rollback_rehearsal",
            "call_provider_from_reviewed_real_start_execution_contract",
            "persist_raw_audio_from_reviewed_real_start_execution_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_REVIEWED_REAL_START_EXECUTION_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_final_start_executor_disabled_by_default"
            if ready_for_start_execution_implementation
            else "fix_reviewed_real_start_execution_contract_prerequisites"
        ),
    })


def inspect_final_start_executor_disabled_by_default(
    *,
    reviewed_real_start_execution_contract: Mapping[str, Any],
    final_start_executor_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Declare the final start executor while keeping real start disabled."""

    authorization = final_start_executor_authorization or {}
    reviewed_contract_ready = (
        reviewed_real_start_execution_contract.get("schema_version") == REVIEWED_REAL_START_EXECUTION_CONTRACT_SCHEMA_VERSION
        and reviewed_real_start_execution_contract.get("status") == "ready_for_start_execution_implementation"
        and reviewed_real_start_execution_contract.get("reviewed_real_start_execution_contract_implemented") is True
        and reviewed_real_start_execution_contract.get("runtime_policy_start_enabled") is False
        and reviewed_real_start_execution_contract.get("real_start_adapter_enabled") is False
        and reviewed_real_start_execution_contract.get("start_execution_allowed") is False
        and reviewed_real_start_execution_contract.get("real_subprocess_start_implemented") is False
        and reviewed_real_start_execution_contract.get("process_launch_attempted") is False
        and reviewed_real_start_execution_contract.get("daemon_started") is False
        and reviewed_real_start_execution_contract.get("subprocess_module_imported") is False
        and reviewed_real_start_execution_contract.get("livekit_sdk_imported") is False
        and reviewed_real_start_execution_contract.get("provider_calls_made") is False
        and reviewed_real_start_execution_contract.get("tool_calls_made") is False
        and reviewed_real_start_execution_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == FINAL_START_EXECUTOR_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_final_start_executor_disabled_contract"
        and authorization.get("final_start_executor_contract_allowed") is True
        and authorization.get("start_enabled") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("single_start_per_receipt_required") is True
        and authorization.get("final_pre_start_receipt_required") is True
        and authorization.get("post_start_ready_event_required") is True
        and authorization.get("rollback_rehearsal_required") is True
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_disabled = reviewed_contract_ready and authorization_ready

    return validate_final_start_executor_disabled_packet({
        "schema_version": FINAL_START_EXECUTOR_DISABLED_SCHEMA_VERSION,
        "status": "ready_disabled_by_default" if ready_disabled else "blocked",
        "implementation_status": "final_start_executor_contract_no_process_start",
        "final_start_executor_contract_implemented": True,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": False,
        "real_start_adapter_enabled": False,
        "start_execution_allowed": False,
        "real_subprocess_start_implemented": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "launch_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "provider_calls_made": False,
        "tool_calls_made": False,
        "raw_audio_touched": False,
        "decision_receipt_id": authorization.get("decision_receipt_id") if authorization_ready else None,
        "reviewed_real_start_execution_contract_schema_version": reviewed_real_start_execution_contract.get("schema_version"),
        "reviewed_real_start_execution_contract_status": reviewed_real_start_execution_contract.get("status"),
        "required_final_start_controls": [
            "start_executor_disabled_by_default",
            "single_start_attempt_per_fresh_receipt",
            "subprocess_import_scoped_to_final_executor_when_enabled",
            "pid_file_guard_checked_before_ready",
            "post_start_ready_event_required_before_success",
            "rollback_rehearsal_required_before_enablement",
            "provider_tool_and_raw_audio_paths_remain_forbidden",
        ],
        "gates": {
            "reviewed_real_start_execution_contract_ready": reviewed_contract_ready,
            "final_start_executor_authorization_ready": authorization_ready,
            "start_disabled_by_default": True,
            "single_start_per_receipt_required": True,
            "final_pre_start_receipt_required": True,
            "post_start_ready_event_required": True,
            "rollback_rehearsal_required": True,
            "runtime_policy_start_disabled": True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "enable_final_start_executor_from_disabled_contract",
            "import_subprocess_from_disabled_final_start_executor",
            "start_process_from_disabled_final_start_executor",
            "start_without_final_pre_start_receipt",
            "start_without_single_receipt_guard",
            "mark_ready_without_post_start_ready_event",
            "skip_rollback_rehearsal",
            "call_provider_from_final_start_executor",
            "persist_raw_audio_from_final_start_executor",
        ],
        "evidence_events": [
            "VOICE_DAEMON_FINAL_START_EXECUTOR_DECLARED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_final_start_executor_enablement_gate"
            if ready_disabled
            else "fix_final_start_executor_disabled_prerequisites"
        ),
    })


def inspect_final_start_executor_enablement_gate(
    *,
    final_start_executor_disabled: Mapping[str, Any],
    final_start_executor_enablement_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Evaluate final executor enablement prerequisites without enabling it."""

    authorization = final_start_executor_enablement_authorization or {}
    disabled_executor_ready = (
        final_start_executor_disabled.get("schema_version") == FINAL_START_EXECUTOR_DISABLED_SCHEMA_VERSION
        and final_start_executor_disabled.get("status") == "ready_disabled_by_default"
        and final_start_executor_disabled.get("final_start_executor_contract_implemented") is True
        and final_start_executor_disabled.get("final_start_executor_enabled") is False
        and final_start_executor_disabled.get("runtime_policy_start_enabled") is False
        and final_start_executor_disabled.get("real_start_adapter_enabled") is False
        and final_start_executor_disabled.get("start_execution_allowed") is False
        and final_start_executor_disabled.get("real_subprocess_start_implemented") is False
        and final_start_executor_disabled.get("process_launch_attempted") is False
        and final_start_executor_disabled.get("daemon_started") is False
        and final_start_executor_disabled.get("subprocess_module_imported") is False
        and final_start_executor_disabled.get("livekit_sdk_imported") is False
        and final_start_executor_disabled.get("provider_calls_made") is False
        and final_start_executor_disabled.get("tool_calls_made") is False
        and final_start_executor_disabled.get("raw_audio_touched") is False
    )
    reviewed_bundle_hash = authorization.get("reviewed_bundle_hash")
    authorization_ready = (
        authorization.get("schema_version") == FINAL_START_EXECUTOR_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_final_start_executor_enablement_gate"
        and authorization.get("final_start_executor_enablement_gate_allowed") is True
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("final_pre_start_receipt_attached") is True
        and isinstance(reviewed_bundle_hash, str)
        and len(reviewed_bundle_hash) == 64
        and authorization.get("single_start_per_receipt_required") is True
        and authorization.get("post_start_ready_event_required") is True
        and authorization.get("rollback_rehearsal_passed") is True
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_supervised_start_execution_review = disabled_executor_ready and authorization_ready

    return validate_final_start_executor_enablement_gate_packet({
        "schema_version": FINAL_START_EXECUTOR_ENABLEMENT_GATE_SCHEMA_VERSION,
        "status": (
            "ready_for_supervised_start_execution_review"
            if ready_for_supervised_start_execution_review
            else "blocked"
        ),
        "implementation_status": "final_start_enablement_gate_no_process_start",
        "final_start_executor_enablement_gate_implemented": True,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": False,
        "real_start_adapter_enabled": False,
        "start_execution_allowed": False,
        "real_subprocess_start_implemented": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "launch_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "provider_calls_made": False,
        "tool_calls_made": False,
        "raw_audio_touched": False,
        "decision_receipt_id": authorization.get("decision_receipt_id") if authorization_ready else None,
        "reviewed_bundle_hash": reviewed_bundle_hash if authorization_ready else None,
        "final_start_executor_disabled_schema_version": final_start_executor_disabled.get("schema_version"),
        "final_start_executor_disabled_status": final_start_executor_disabled.get("status"),
        "required_enablement_controls": [
            "reviewed_bundle_hash_bound_to_current_evidence",
            "final_pre_start_receipt_attached",
            "single_start_attempt_per_fresh_receipt",
            "post_start_ready_event_required_before_success",
            "rollback_rehearsal_passed_before_enablement",
            "start_execution_remains_disabled_until_supervised_review",
            "provider_tool_and_raw_audio_paths_remain_forbidden",
        ],
        "gates": {
            "final_start_executor_disabled_ready": disabled_executor_ready,
            "final_start_executor_enablement_authorization_ready": authorization_ready,
            "final_pre_start_receipt_attached": authorization.get("final_pre_start_receipt_attached") is True,
            "reviewed_bundle_hash_required": isinstance(reviewed_bundle_hash, str) and len(reviewed_bundle_hash) == 64,
            "single_start_per_receipt_required": True,
            "post_start_ready_event_required": True,
            "rollback_rehearsal_passed": True,
            "runtime_policy_start_disabled": True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "enable_final_start_executor_without_reviewed_bundle_hash",
            "enable_final_start_executor_without_final_pre_start_receipt",
            "enable_final_start_executor_without_single_start_guard",
            "enable_final_start_executor_without_post_start_ready_event",
            "enable_final_start_executor_without_rollback_rehearsal",
            "import_subprocess_from_final_start_enablement_gate",
            "start_process_from_final_start_enablement_gate",
            "call_provider_from_final_start_enablement_gate",
            "persist_raw_audio_from_final_start_enablement_gate",
        ],
        "evidence_events": [
            "VOICE_DAEMON_FINAL_START_ENABLEMENT_GATE_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_supervised_start_execution_review"
            if ready_for_supervised_start_execution_review
            else "fix_final_start_executor_enablement_gate_prerequisites"
        ),
    })


def inspect_supervised_start_execution_review(
    *,
    final_start_executor_enablement_gate: Mapping[str, Any],
    supervised_start_review_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Review final start execution readiness without allowing start."""

    authorization = supervised_start_review_authorization or {}
    enablement_gate_ready = (
        final_start_executor_enablement_gate.get("schema_version") == FINAL_START_EXECUTOR_ENABLEMENT_GATE_SCHEMA_VERSION
        and final_start_executor_enablement_gate.get("status") == "ready_for_supervised_start_execution_review"
        and final_start_executor_enablement_gate.get("final_start_executor_enablement_gate_implemented") is True
        and final_start_executor_enablement_gate.get("final_start_executor_enabled") is False
        and final_start_executor_enablement_gate.get("runtime_policy_start_enabled") is False
        and final_start_executor_enablement_gate.get("real_start_adapter_enabled") is False
        and final_start_executor_enablement_gate.get("start_execution_allowed") is False
        and final_start_executor_enablement_gate.get("real_subprocess_start_implemented") is False
        and final_start_executor_enablement_gate.get("process_launch_attempted") is False
        and final_start_executor_enablement_gate.get("daemon_started") is False
        and final_start_executor_enablement_gate.get("subprocess_module_imported") is False
        and final_start_executor_enablement_gate.get("livekit_sdk_imported") is False
        and final_start_executor_enablement_gate.get("provider_calls_made") is False
        and final_start_executor_enablement_gate.get("tool_calls_made") is False
        and final_start_executor_enablement_gate.get("raw_audio_touched") is False
    )
    reviewed_bundle_hash = authorization.get("reviewed_bundle_hash")
    authorization_ready = (
        authorization.get("schema_version") == SUPERVISED_START_EXECUTION_REVIEW_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_supervised_start_execution_review"
        and authorization.get("supervised_start_execution_review_allowed") is True
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("technical_review_completed") is True
        and authorization.get("current_bundle_reviewed") is True
        and isinstance(reviewed_bundle_hash, str)
        and len(reviewed_bundle_hash) == 64
        and authorization.get("final_pre_start_receipt_required") is True
        and authorization.get("single_start_per_receipt_required") is True
        and authorization.get("post_start_ready_event_required") is True
        and authorization.get("rollback_rehearsal_required") is True
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_real_start_execution_contract = enablement_gate_ready and authorization_ready

    return validate_supervised_start_execution_review_packet({
        "schema_version": SUPERVISED_START_EXECUTION_REVIEW_SCHEMA_VERSION,
        "status": (
            "ready_for_real_start_execution_contract"
            if ready_for_real_start_execution_contract
            else "blocked"
        ),
        "implementation_status": "supervised_start_execution_review_no_process_start",
        "supervised_start_execution_review_implemented": True,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": False,
        "real_start_adapter_enabled": False,
        "start_execution_allowed": False,
        "real_subprocess_start_implemented": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "launch_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "provider_calls_made": False,
        "tool_calls_made": False,
        "raw_audio_touched": False,
        "decision_receipt_id": authorization.get("decision_receipt_id") if authorization_ready else None,
        "reviewed_bundle_hash": reviewed_bundle_hash if authorization_ready else None,
        "final_start_executor_enablement_gate_schema_version": final_start_executor_enablement_gate.get("schema_version"),
        "final_start_executor_enablement_gate_status": final_start_executor_enablement_gate.get("status"),
        "required_review_controls": [
            "technical_review_completed_for_current_bundle",
            "current_bundle_hash_bound_to_decision_receipt",
            "final_pre_start_receipt_required_at_execution_time",
            "single_start_attempt_per_receipt_required",
            "post_start_ready_event_required_before_success",
            "rollback_rehearsal_required_before_execution",
            "start_execution_remains_disabled_until_real_start_contract",
            "provider_tool_and_raw_audio_paths_remain_forbidden",
        ],
        "gates": {
            "final_start_executor_enablement_gate_ready": enablement_gate_ready,
            "supervised_start_review_authorization_ready": authorization_ready,
            "technical_review_required": True,
            "final_pre_start_receipt_required": True,
            "current_bundle_review_required": True,
            "reviewed_bundle_hash_required": isinstance(reviewed_bundle_hash, str) and len(reviewed_bundle_hash) == 64,
            "single_start_per_receipt_required": True,
            "post_start_ready_event_required": True,
            "rollback_rehearsal_required": True,
            "runtime_policy_start_disabled": True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "approve_start_review_without_current_bundle",
            "approve_start_review_without_technical_review",
            "approve_start_review_without_decision_receipt",
            "approve_start_review_without_rollback_rehearsal",
            "import_subprocess_from_supervised_start_review",
            "start_process_from_supervised_start_review",
            "call_provider_from_supervised_start_review",
            "persist_raw_audio_from_supervised_start_review",
        ],
        "evidence_events": [
            "VOICE_DAEMON_SUPERVISED_START_EXECUTION_REVIEWED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_real_start_execution_contract"
            if ready_for_real_start_execution_contract
            else "fix_supervised_start_execution_review_prerequisites"
        ),
    })


def inspect_real_start_execution_contract(
    *,
    supervised_start_execution_review: Mapping[str, Any],
    real_start_execution_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Declare the real start execution contract without implementing start."""

    authorization = real_start_execution_authorization or {}
    review_ready = (
        supervised_start_execution_review.get("schema_version") == SUPERVISED_START_EXECUTION_REVIEW_SCHEMA_VERSION
        and supervised_start_execution_review.get("status") == "ready_for_real_start_execution_contract"
        and supervised_start_execution_review.get("supervised_start_execution_review_implemented") is True
        and supervised_start_execution_review.get("final_start_executor_enabled") is False
        and supervised_start_execution_review.get("runtime_policy_start_enabled") is False
        and supervised_start_execution_review.get("real_start_adapter_enabled") is False
        and supervised_start_execution_review.get("start_execution_allowed") is False
        and supervised_start_execution_review.get("real_subprocess_start_implemented") is False
        and supervised_start_execution_review.get("process_launch_attempted") is False
        and supervised_start_execution_review.get("daemon_started") is False
        and supervised_start_execution_review.get("subprocess_module_imported") is False
        and supervised_start_execution_review.get("livekit_sdk_imported") is False
        and supervised_start_execution_review.get("provider_calls_made") is False
        and supervised_start_execution_review.get("tool_calls_made") is False
        and supervised_start_execution_review.get("raw_audio_touched") is False
    )
    reviewed_bundle_hash = authorization.get("reviewed_bundle_hash")
    authorization_ready = (
        authorization.get("schema_version") == REAL_START_EXECUTION_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_real_start_execution_contract"
        and authorization.get("real_start_execution_contract_allowed") is True
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("technical_review_completed") is True
        and authorization.get("current_bundle_reviewed") is True
        and isinstance(reviewed_bundle_hash, str)
        and len(reviewed_bundle_hash) == 64
        and authorization.get("final_pre_start_receipt_attached") is True
        and authorization.get("single_start_per_receipt_required") is True
        and authorization.get("post_start_ready_event_required") is True
        and authorization.get("rollback_rehearsal_passed") is True
        and authorization.get("pid_file_guard_required") is True
        and authorization.get("startup_timeout_required") is True
        and authorization.get("stdout_stderr_sanitization_required") is True
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_executor_implementation = review_ready and authorization_ready

    return validate_real_start_execution_contract_packet({
        "schema_version": REAL_START_EXECUTION_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_executor_implementation"
            if ready_for_guarded_start_executor_implementation
            else "blocked"
        ),
        "implementation_status": "real_start_execution_contract_no_process_start",
        "real_start_execution_contract_implemented": True,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": False,
        "real_start_adapter_enabled": False,
        "start_execution_allowed": False,
        "real_subprocess_start_implemented": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "launch_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "provider_calls_made": False,
        "tool_calls_made": False,
        "raw_audio_touched": False,
        "decision_receipt_id": authorization.get("decision_receipt_id") if authorization_ready else None,
        "reviewed_bundle_hash": reviewed_bundle_hash if authorization_ready else None,
        "supervised_start_execution_review_schema_version": supervised_start_execution_review.get("schema_version"),
        "supervised_start_execution_review_status": supervised_start_execution_review.get("status"),
        "required_execution_controls": [
            "guarded_subprocess_import_only_inside_executor",
            "single_start_attempt_per_fresh_receipt",
            "pid_file_guard_checked_before_start",
            "startup_timeout_enforced_before_ready",
            "stdout_stderr_sanitized_before_ledger",
            "post_start_ready_event_required_before_success",
            "rollback_rehearsal_passed_before_execution",
            "provider_tool_and_raw_audio_paths_remain_forbidden",
        ],
        "gates": {
            "supervised_start_execution_review_ready": review_ready,
            "real_start_execution_authorization_ready": authorization_ready,
            "technical_review_required": True,
            "current_bundle_review_required": True,
            "reviewed_bundle_hash_required": isinstance(reviewed_bundle_hash, str) and len(reviewed_bundle_hash) == 64,
            "final_pre_start_receipt_attached": authorization.get("final_pre_start_receipt_attached") is True,
            "single_start_per_receipt_required": True,
            "pid_file_guard_required": True,
            "startup_timeout_required": True,
            "stdout_stderr_sanitization_required": True,
            "post_start_ready_event_required": True,
            "rollback_rehearsal_passed": True,
            "runtime_policy_start_disabled": True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "implement_guarded_start_without_real_start_execution_contract",
            "approve_real_start_contract_without_current_bundle",
            "approve_real_start_contract_without_final_receipt",
            "approve_real_start_contract_without_pid_guard",
            "approve_real_start_contract_without_startup_timeout",
            "approve_real_start_contract_without_sanitized_streams",
            "import_subprocess_from_real_start_execution_contract",
            "start_process_from_real_start_execution_contract",
            "call_provider_from_real_start_execution_contract",
            "persist_raw_audio_from_real_start_execution_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_REAL_START_EXECUTION_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_executor_disabled_by_default"
            if ready_for_guarded_start_executor_implementation
            else "fix_real_start_execution_contract_prerequisites"
        ),
    })


def inspect_guarded_start_executor_disabled_by_default(
    *,
    real_start_execution_contract: Mapping[str, Any],
    guarded_start_executor_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Declare the guarded start executor shell while keeping it disabled."""

    authorization = guarded_start_executor_authorization or {}
    real_contract_ready = (
        real_start_execution_contract.get("schema_version") == REAL_START_EXECUTION_CONTRACT_SCHEMA_VERSION
        and real_start_execution_contract.get("status") == "ready_for_guarded_start_executor_implementation"
        and real_start_execution_contract.get("real_start_execution_contract_implemented") is True
        and real_start_execution_contract.get("guarded_start_executor_implemented") is False
        and real_start_execution_contract.get("final_start_executor_enabled") is False
        and real_start_execution_contract.get("runtime_policy_start_enabled") is False
        and real_start_execution_contract.get("real_start_adapter_enabled") is False
        and real_start_execution_contract.get("start_execution_allowed") is False
        and real_start_execution_contract.get("real_subprocess_start_implemented") is False
        and real_start_execution_contract.get("process_launch_attempted") is False
        and real_start_execution_contract.get("daemon_started") is False
        and real_start_execution_contract.get("subprocess_module_imported") is False
        and real_start_execution_contract.get("livekit_sdk_imported") is False
        and real_start_execution_contract.get("provider_calls_made") is False
        and real_start_execution_contract.get("tool_calls_made") is False
        and real_start_execution_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_EXECUTOR_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_executor_disabled_contract"
        and authorization.get("guarded_start_executor_contract_allowed") is True
        and authorization.get("guarded_start_enabled") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("pid_file_guard_required") is True
        and authorization.get("startup_timeout_required") is True
        and authorization.get("stdout_stderr_sanitization_required") is True
        and authorization.get("single_start_per_receipt_required") is True
        and authorization.get("post_start_ready_event_required") is True
        and authorization.get("rollback_rehearsal_required") is True
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_disabled = real_contract_ready and authorization_ready

    return validate_guarded_start_executor_disabled_packet({
        "schema_version": GUARDED_START_EXECUTOR_DISABLED_SCHEMA_VERSION,
        "status": "ready_disabled_by_default" if ready_disabled else "blocked",
        "implementation_status": "guarded_start_executor_disabled_no_process_start",
        "guarded_start_executor_contract_implemented": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": False,
        "real_start_adapter_enabled": False,
        "start_execution_allowed": False,
        "real_subprocess_start_implemented": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "launch_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "provider_calls_made": False,
        "tool_calls_made": False,
        "raw_audio_touched": False,
        "decision_receipt_id": authorization.get("decision_receipt_id") if authorization_ready else None,
        "real_start_execution_contract_schema_version": real_start_execution_contract.get("schema_version"),
        "real_start_execution_contract_status": real_start_execution_contract.get("status"),
        "required_disabled_controls": [
            "guarded_executor_disabled_by_default",
            "subprocess_import_confined_to_future_executor",
            "pid_file_guard_required_before_start",
            "startup_timeout_required_before_start",
            "stdout_stderr_sanitization_required_before_ledger",
            "single_start_attempt_per_receipt_required",
            "post_start_ready_event_required_before_success",
            "rollback_rehearsal_required_before_execution",
        ],
        "gates": {
            "real_start_execution_contract_ready": real_contract_ready,
            "guarded_start_executor_authorization_ready": authorization_ready,
            "guarded_executor_disabled_by_default": True,
            "pid_file_guard_required": True,
            "startup_timeout_required": True,
            "stdout_stderr_sanitization_required": True,
            "single_start_per_receipt_required": True,
            "post_start_ready_event_required": True,
            "rollback_rehearsal_required": True,
            "runtime_policy_start_disabled": True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "enable_guarded_start_executor_from_disabled_contract",
            "import_subprocess_from_guarded_start_disabled_contract",
            "start_process_from_guarded_start_disabled_contract",
            "call_provider_from_guarded_start_disabled_contract",
            "persist_raw_audio_from_guarded_start_disabled_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_EXECUTOR_DECLARED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_executor_enablement_gate"
            if ready_disabled
            else "fix_guarded_start_executor_disabled_prerequisites"
        ),
    })


def inspect_guarded_start_executor_enablement_gate(
    *,
    guarded_start_executor_disabled: Mapping[str, Any],
    guarded_start_executor_enablement_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Evaluate guarded executor enablement prerequisites without enabling it."""

    authorization = guarded_start_executor_enablement_authorization or {}
    disabled_executor_ready = (
        guarded_start_executor_disabled.get("schema_version") == GUARDED_START_EXECUTOR_DISABLED_SCHEMA_VERSION
        and guarded_start_executor_disabled.get("status") == "ready_disabled_by_default"
        and guarded_start_executor_disabled.get("guarded_start_executor_contract_implemented") is True
        and guarded_start_executor_disabled.get("guarded_start_executor_enabled") is False
        and guarded_start_executor_disabled.get("guarded_start_executor_implemented") is False
        and guarded_start_executor_disabled.get("final_start_executor_enabled") is False
        and guarded_start_executor_disabled.get("runtime_policy_start_enabled") is False
        and guarded_start_executor_disabled.get("real_start_adapter_enabled") is False
        and guarded_start_executor_disabled.get("start_execution_allowed") is False
        and guarded_start_executor_disabled.get("real_subprocess_start_implemented") is False
        and guarded_start_executor_disabled.get("process_launch_attempted") is False
        and guarded_start_executor_disabled.get("daemon_started") is False
        and guarded_start_executor_disabled.get("subprocess_module_imported") is False
        and guarded_start_executor_disabled.get("livekit_sdk_imported") is False
        and guarded_start_executor_disabled.get("provider_calls_made") is False
        and guarded_start_executor_disabled.get("tool_calls_made") is False
        and guarded_start_executor_disabled.get("raw_audio_touched") is False
    )
    reviewed_bundle_hash = authorization.get("reviewed_bundle_hash")
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_EXECUTOR_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_executor_enablement_gate"
        and authorization.get("guarded_start_executor_enablement_gate_allowed") is True
        and authorization.get("guarded_start_enabled") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(reviewed_bundle_hash, str)
        and len(reviewed_bundle_hash) == 64
        and authorization.get("final_pre_start_receipt_attached") is True
        and authorization.get("single_start_per_receipt_required") is True
        and authorization.get("post_start_ready_event_required") is True
        and authorization.get("rollback_rehearsal_passed") is True
        and authorization.get("pid_file_guard_required") is True
        and authorization.get("startup_timeout_required") is True
        and authorization.get("stdout_stderr_sanitization_required") is True
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_reviewed_guarded_start_execution = disabled_executor_ready and authorization_ready

    return validate_guarded_start_executor_enablement_gate_packet({
        "schema_version": GUARDED_START_EXECUTOR_ENABLEMENT_GATE_SCHEMA_VERSION,
        "status": (
            "ready_for_reviewed_guarded_start_execution"
            if ready_for_reviewed_guarded_start_execution
            else "blocked"
        ),
        "implementation_status": "guarded_start_executor_enablement_gate_no_process_start",
        "guarded_start_executor_enablement_gate_implemented": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": False,
        "real_start_adapter_enabled": False,
        "start_execution_allowed": False,
        "real_subprocess_start_implemented": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "launch_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "provider_calls_made": False,
        "tool_calls_made": False,
        "raw_audio_touched": False,
        "decision_receipt_id": authorization.get("decision_receipt_id") if authorization_ready else None,
        "reviewed_bundle_hash": reviewed_bundle_hash if authorization_ready else None,
        "guarded_start_executor_disabled_schema_version": guarded_start_executor_disabled.get("schema_version"),
        "guarded_start_executor_disabled_status": guarded_start_executor_disabled.get("status"),
        "required_enablement_controls": [
            "reviewed_bundle_hash_bound_to_current_evidence",
            "final_pre_start_receipt_attached",
            "single_start_attempt_per_fresh_receipt",
            "pid_file_guard_required_before_start",
            "startup_timeout_required_before_start",
            "stdout_stderr_sanitization_required_before_ledger",
            "post_start_ready_event_required_before_success",
            "rollback_rehearsal_passed_before_enablement",
            "guarded_executor_remains_disabled_until_reviewed_execution_contract",
        ],
        "gates": {
            "guarded_start_executor_disabled_ready": disabled_executor_ready,
            "guarded_start_executor_enablement_authorization_ready": authorization_ready,
            "reviewed_bundle_hash_required": isinstance(reviewed_bundle_hash, str) and len(reviewed_bundle_hash) == 64,
            "final_pre_start_receipt_attached": authorization.get("final_pre_start_receipt_attached") is True,
            "single_start_per_receipt_required": True,
            "pid_file_guard_required": True,
            "startup_timeout_required": True,
            "stdout_stderr_sanitization_required": True,
            "post_start_ready_event_required": True,
            "rollback_rehearsal_passed": True,
            "runtime_policy_start_disabled": True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "enable_guarded_start_executor_without_reviewed_bundle_hash",
            "enable_guarded_start_executor_without_final_pre_start_receipt",
            "enable_guarded_start_executor_without_single_start_guard",
            "enable_guarded_start_executor_without_post_start_ready_event",
            "enable_guarded_start_executor_without_rollback_rehearsal",
            "import_subprocess_from_guarded_start_enablement_gate",
            "start_process_from_guarded_start_enablement_gate",
            "call_provider_from_guarded_start_enablement_gate",
            "persist_raw_audio_from_guarded_start_enablement_gate",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_ENABLEMENT_GATE_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_reviewed_guarded_start_execution_contract"
            if ready_for_reviewed_guarded_start_execution
            else "fix_guarded_start_executor_enablement_gate_prerequisites"
        ),
    })


def inspect_reviewed_guarded_start_execution_contract(
    *,
    guarded_start_executor_enablement_gate: Mapping[str, Any],
    reviewed_guarded_start_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Declare the reviewed guarded execution contract without starting."""

    authorization = reviewed_guarded_start_authorization or {}
    enablement_gate_ready = (
        guarded_start_executor_enablement_gate.get("schema_version") == GUARDED_START_EXECUTOR_ENABLEMENT_GATE_SCHEMA_VERSION
        and guarded_start_executor_enablement_gate.get("status") == "ready_for_reviewed_guarded_start_execution"
        and guarded_start_executor_enablement_gate.get("guarded_start_executor_enablement_gate_implemented") is True
        and guarded_start_executor_enablement_gate.get("guarded_start_executor_enabled") is False
        and guarded_start_executor_enablement_gate.get("guarded_start_executor_implemented") is False
        and guarded_start_executor_enablement_gate.get("final_start_executor_enabled") is False
        and guarded_start_executor_enablement_gate.get("runtime_policy_start_enabled") is False
        and guarded_start_executor_enablement_gate.get("real_start_adapter_enabled") is False
        and guarded_start_executor_enablement_gate.get("start_execution_allowed") is False
        and guarded_start_executor_enablement_gate.get("real_subprocess_start_implemented") is False
        and guarded_start_executor_enablement_gate.get("process_launch_attempted") is False
        and guarded_start_executor_enablement_gate.get("daemon_started") is False
        and guarded_start_executor_enablement_gate.get("subprocess_module_imported") is False
        and guarded_start_executor_enablement_gate.get("livekit_sdk_imported") is False
        and guarded_start_executor_enablement_gate.get("provider_calls_made") is False
        and guarded_start_executor_enablement_gate.get("tool_calls_made") is False
        and guarded_start_executor_enablement_gate.get("raw_audio_touched") is False
    )
    reviewed_bundle_hash = authorization.get("reviewed_bundle_hash")
    authorization_ready = (
        authorization.get("schema_version") == REVIEWED_GUARDED_START_EXECUTION_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_reviewed_guarded_start_execution_contract"
        and authorization.get("reviewed_guarded_start_execution_allowed") is True
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("technical_review_completed") is True
        and authorization.get("current_bundle_reviewed") is True
        and isinstance(reviewed_bundle_hash, str)
        and len(reviewed_bundle_hash) == 64
        and authorization.get("final_pre_start_receipt_attached") is True
        and authorization.get("single_start_per_receipt_required") is True
        and authorization.get("post_start_ready_event_required") is True
        and authorization.get("rollback_rehearsal_passed") is True
        and authorization.get("pid_file_guard_required") is True
        and authorization.get("startup_timeout_required") is True
        and authorization.get("stdout_stderr_sanitization_required") is True
        and authorization.get("dry_run_execution_plan_attached") is True
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_dry_run_contract = enablement_gate_ready and authorization_ready

    return validate_reviewed_guarded_start_execution_contract_packet({
        "schema_version": REVIEWED_GUARDED_START_EXECUTION_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_dry_run_contract"
            if ready_for_guarded_start_dry_run_contract
            else "blocked"
        ),
        "implementation_status": "reviewed_guarded_start_execution_contract_no_process_start",
        "reviewed_guarded_start_execution_contract_implemented": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": False,
        "real_start_adapter_enabled": False,
        "start_execution_allowed": False,
        "real_subprocess_start_implemented": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "launch_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "provider_calls_made": False,
        "tool_calls_made": False,
        "raw_audio_touched": False,
        "decision_receipt_id": authorization.get("decision_receipt_id") if authorization_ready else None,
        "reviewed_bundle_hash": reviewed_bundle_hash if authorization_ready else None,
        "guarded_start_executor_enablement_gate_schema_version": guarded_start_executor_enablement_gate.get("schema_version"),
        "guarded_start_executor_enablement_gate_status": guarded_start_executor_enablement_gate.get("status"),
        "required_reviewed_execution_controls": [
            "current_bundle_reviewed_before_guarded_execution",
            "dry_run_execution_plan_attached_before_any_start",
            "final_pre_start_receipt_attached",
            "single_start_attempt_per_fresh_receipt",
            "pid_file_guard_required_before_start",
            "startup_timeout_required_before_start",
            "stdout_stderr_sanitization_required_before_ledger",
            "post_start_ready_event_required_before_success",
            "rollback_rehearsal_passed_before_execution",
        ],
        "gates": {
            "guarded_start_executor_enablement_gate_ready": enablement_gate_ready,
            "reviewed_guarded_start_execution_authorization_ready": authorization_ready,
            "technical_review_required": True,
            "current_bundle_review_required": True,
            "reviewed_bundle_hash_required": isinstance(reviewed_bundle_hash, str) and len(reviewed_bundle_hash) == 64,
            "dry_run_execution_plan_attached": authorization.get("dry_run_execution_plan_attached") is True,
            "final_pre_start_receipt_attached": authorization.get("final_pre_start_receipt_attached") is True,
            "single_start_per_receipt_required": True,
            "pid_file_guard_required": True,
            "startup_timeout_required": True,
            "stdout_stderr_sanitization_required": True,
            "post_start_ready_event_required": True,
            "rollback_rehearsal_passed": True,
            "runtime_policy_start_disabled": True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "implement_guarded_executor_without_reviewed_execution_contract",
            "approve_guarded_execution_without_dry_run_plan",
            "approve_guarded_execution_without_current_bundle",
            "approve_guarded_execution_without_final_receipt",
            "import_subprocess_from_reviewed_guarded_start_execution_contract",
            "start_process_from_reviewed_guarded_start_execution_contract",
            "call_provider_from_reviewed_guarded_start_execution_contract",
            "persist_raw_audio_from_reviewed_guarded_start_execution_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_REVIEWED_GUARDED_START_EXECUTION_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_dry_run_contract"
            if ready_for_guarded_start_dry_run_contract
            else "fix_reviewed_guarded_start_execution_contract_prerequisites"
        ),
    })


def inspect_guarded_start_dry_run_contract(
    *,
    reviewed_guarded_start_execution_contract: Mapping[str, Any],
    guarded_start_dry_run_plan: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Validate the guarded start dry-run path without importing or starting."""

    dry_run_plan = guarded_start_dry_run_plan or {}
    reviewed_contract_ready = (
        reviewed_guarded_start_execution_contract.get("schema_version") == REVIEWED_GUARDED_START_EXECUTION_CONTRACT_SCHEMA_VERSION
        and reviewed_guarded_start_execution_contract.get("status") == "ready_for_guarded_start_dry_run_contract"
        and reviewed_guarded_start_execution_contract.get("reviewed_guarded_start_execution_contract_implemented") is True
        and reviewed_guarded_start_execution_contract.get("guarded_start_executor_enabled") is False
        and reviewed_guarded_start_execution_contract.get("guarded_start_executor_implemented") is False
        and reviewed_guarded_start_execution_contract.get("start_execution_allowed") is False
        and reviewed_guarded_start_execution_contract.get("process_launch_attempted") is False
        and reviewed_guarded_start_execution_contract.get("daemon_started") is False
        and reviewed_guarded_start_execution_contract.get("subprocess_module_imported") is False
        and reviewed_guarded_start_execution_contract.get("provider_calls_made") is False
        and reviewed_guarded_start_execution_contract.get("tool_calls_made") is False
        and reviewed_guarded_start_execution_contract.get("raw_audio_touched") is False
    )
    timeout_ms = dry_run_plan.get("simulated_startup_timeout_ms")
    dry_run_plan_ready = (
        dry_run_plan.get("schema_version") == GUARDED_START_DRY_RUN_PLAN_SCHEMA_VERSION
        and dry_run_plan.get("status") == "approved_for_guarded_start_dry_run_contract"
        and dry_run_plan.get("dry_run_only") is True
        and dry_run_plan.get("start_execution_allowed") is False
        and dry_run_plan.get("process_launch_allowed") is False
        and dry_run_plan.get("subprocess_module_import_allowed") is False
        and dry_run_plan.get("simulated_pid_file_guard_passed") is True
        and isinstance(timeout_ms, int)
        and 0 < timeout_ms <= 30_000
        and dry_run_plan.get("stdout_stderr_sanitization_simulated") is True
        and dry_run_plan.get("post_start_ready_event_simulated") is True
        and dry_run_plan.get("rollback_rehearsal_reference_attached") is True
        and isinstance(dry_run_plan.get("decision_receipt_id"), str)
        and dry_run_plan.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_simulation = reviewed_contract_ready and dry_run_plan_ready

    return validate_guarded_start_dry_run_contract_packet({
        "schema_version": GUARDED_START_DRY_RUN_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_simulation"
            if ready_for_guarded_start_simulation
            else "blocked"
        ),
        "implementation_status": "guarded_start_dry_run_contract_no_process_start",
        "guarded_start_dry_run_contract_implemented": True,
        "dry_run_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": False,
        "real_start_adapter_enabled": False,
        "start_execution_allowed": False,
        "real_subprocess_start_implemented": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "launch_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "provider_calls_made": False,
        "tool_calls_made": False,
        "raw_audio_touched": False,
        "decision_receipt_id": dry_run_plan.get("decision_receipt_id") if dry_run_plan_ready else None,
        "reviewed_guarded_start_execution_contract_status": reviewed_guarded_start_execution_contract.get("status"),
        "simulated_startup_timeout_ms": timeout_ms if dry_run_plan_ready else None,
        "required_dry_run_controls": [
            "dry_run_only_before_any_start_execution",
            "pid_file_guard_simulated_before_process_boundary",
            "startup_timeout_simulated_before_process_boundary",
            "stdout_stderr_sanitization_simulated_before_ledger",
            "post_start_ready_event_simulated_before_success",
            "rollback_rehearsal_reference_attached",
        ],
        "gates": {
            "reviewed_guarded_start_execution_contract_ready": reviewed_contract_ready,
            "dry_run_plan_ready": dry_run_plan_ready,
            "dry_run_only_enforced": True,
            "simulated_pid_file_guard_passed": True,
            "simulated_startup_timeout_present": isinstance(timeout_ms, int) and 0 < timeout_ms <= 30_000,
            "stdout_stderr_sanitization_simulated": True,
            "post_start_ready_event_simulated": True,
            "rollback_rehearsal_reference_attached": True,
            "runtime_policy_start_disabled": True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "convert_dry_run_contract_into_real_start",
            "import_subprocess_from_guarded_start_dry_run_contract",
            "start_process_from_guarded_start_dry_run_contract",
            "call_provider_from_guarded_start_dry_run_contract",
            "persist_raw_audio_from_guarded_start_dry_run_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_DRY_RUN_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_simulation_contract"
            if ready_for_guarded_start_simulation
            else "fix_guarded_start_dry_run_contract_prerequisites"
        ),
    })


def inspect_guarded_start_simulation_contract(
    *,
    guarded_start_dry_run_contract: Mapping[str, Any],
    guarded_start_simulation_plan: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Simulate the guarded start lifecycle without importing or starting."""

    simulation_plan = guarded_start_simulation_plan or {}
    dry_run_contract_ready = (
        guarded_start_dry_run_contract.get("schema_version") == GUARDED_START_DRY_RUN_CONTRACT_SCHEMA_VERSION
        and guarded_start_dry_run_contract.get("status") == "ready_for_guarded_start_simulation"
        and guarded_start_dry_run_contract.get("guarded_start_dry_run_contract_implemented") is True
        and guarded_start_dry_run_contract.get("dry_run_only") is True
        and guarded_start_dry_run_contract.get("start_execution_allowed") is False
        and guarded_start_dry_run_contract.get("process_launch_attempted") is False
        and guarded_start_dry_run_contract.get("daemon_started") is False
        and guarded_start_dry_run_contract.get("subprocess_module_imported") is False
        and guarded_start_dry_run_contract.get("provider_calls_made") is False
        and guarded_start_dry_run_contract.get("tool_calls_made") is False
        and guarded_start_dry_run_contract.get("raw_audio_touched") is False
    )
    simulation_plan_ready = (
        simulation_plan.get("schema_version") == GUARDED_START_SIMULATION_PLAN_SCHEMA_VERSION
        and simulation_plan.get("status") == "approved_for_guarded_start_simulation_contract"
        and simulation_plan.get("simulation_only") is True
        and simulation_plan.get("dry_run_only") is True
        and simulation_plan.get("start_execution_allowed") is False
        and simulation_plan.get("process_launch_allowed") is False
        and simulation_plan.get("subprocess_module_import_allowed") is False
        and simulation_plan.get("synthetic_lifecycle_simulated") is True
        and simulation_plan.get("synthetic_ready_probe_passed") is True
        and simulation_plan.get("synthetic_exit_code") == 0
        and isinstance(simulation_plan.get("decision_receipt_id"), str)
        and simulation_plan.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_runtime_handoff = dry_run_contract_ready and simulation_plan_ready

    return validate_guarded_start_simulation_contract_packet({
        "schema_version": GUARDED_START_SIMULATION_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_runtime_handoff"
            if ready_for_guarded_start_runtime_handoff
            else "blocked"
        ),
        "implementation_status": "guarded_start_simulation_contract_no_process_start",
        "guarded_start_simulation_contract_implemented": True,
        "simulation_only": True,
        "dry_run_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": False,
        "real_start_adapter_enabled": False,
        "start_execution_allowed": False,
        "real_subprocess_start_implemented": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "launch_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "provider_calls_made": False,
        "tool_calls_made": False,
        "raw_audio_touched": False,
        "decision_receipt_id": simulation_plan.get("decision_receipt_id") if simulation_plan_ready else None,
        "guarded_start_dry_run_contract_status": guarded_start_dry_run_contract.get("status"),
        "synthetic_exit_code": simulation_plan.get("synthetic_exit_code") if simulation_plan_ready else None,
        "required_simulation_controls": [
            "simulation_only_before_runtime_handoff",
            "synthetic_lifecycle_before_real_daemon",
            "synthetic_ready_probe_before_real_daemon",
            "synthetic_exit_code_before_real_daemon",
        ],
        "gates": {
            "guarded_start_dry_run_contract_ready": dry_run_contract_ready,
            "guarded_start_simulation_plan_ready": simulation_plan_ready,
            "simulation_only_enforced": True,
            "synthetic_lifecycle_simulated": True,
            "synthetic_ready_probe_passed": True,
            "synthetic_exit_code_zero": simulation_plan.get("synthetic_exit_code") == 0,
            "runtime_policy_start_disabled": True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "convert_simulation_contract_into_real_start",
            "import_subprocess_from_guarded_start_simulation_contract",
            "start_process_from_guarded_start_simulation_contract",
            "call_provider_from_guarded_start_simulation_contract",
            "persist_raw_audio_from_guarded_start_simulation_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_SIMULATION_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_runtime_handoff_contract"
            if ready_for_guarded_start_runtime_handoff
            else "fix_guarded_start_simulation_contract_prerequisites"
        ),
    })


def inspect_guarded_start_runtime_handoff_contract(
    *,
    guarded_start_simulation_contract: Mapping[str, Any],
    guarded_start_runtime_handoff_plan: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Declare the runtime handoff contract without importing or starting."""

    handoff_plan = guarded_start_runtime_handoff_plan or {}
    simulation_contract_ready = (
        guarded_start_simulation_contract.get("schema_version") == GUARDED_START_SIMULATION_CONTRACT_SCHEMA_VERSION
        and guarded_start_simulation_contract.get("status") == "ready_for_guarded_start_runtime_handoff"
        and guarded_start_simulation_contract.get("guarded_start_simulation_contract_implemented") is True
        and guarded_start_simulation_contract.get("simulation_only") is True
        and guarded_start_simulation_contract.get("dry_run_only") is True
        and guarded_start_simulation_contract.get("start_execution_allowed") is False
        and guarded_start_simulation_contract.get("process_launch_attempted") is False
        and guarded_start_simulation_contract.get("daemon_started") is False
        and guarded_start_simulation_contract.get("subprocess_module_imported") is False
        and guarded_start_simulation_contract.get("provider_calls_made") is False
        and guarded_start_simulation_contract.get("tool_calls_made") is False
        and guarded_start_simulation_contract.get("raw_audio_touched") is False
    )
    handoff_plan_ready = (
        handoff_plan.get("schema_version") == GUARDED_START_RUNTIME_HANDOFF_PLAN_SCHEMA_VERSION
        and handoff_plan.get("status") == "approved_for_guarded_start_runtime_handoff_contract"
        and handoff_plan.get("runtime_handoff_contract_allowed") is True
        and handoff_plan.get("runtime_family") == "python_ai_data"
        and handoff_plan.get("start_execution_allowed") is False
        and handoff_plan.get("process_launch_allowed") is False
        and handoff_plan.get("subprocess_module_import_allowed") is False
        and handoff_plan.get("kernel_runtime_invocation_contract_attached") is True
        and handoff_plan.get("evidence_sink_attached") is True
        and handoff_plan.get("rollback_plan_attached") is True
        and handoff_plan.get("policy_patch_review_required") is True
        and handoff_plan.get("human_review_required") is True
        and isinstance(handoff_plan.get("decision_receipt_id"), str)
        and handoff_plan.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_policy_patch_review = simulation_contract_ready and handoff_plan_ready

    return validate_guarded_start_runtime_handoff_contract_packet({
        "schema_version": GUARDED_START_RUNTIME_HANDOFF_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_policy_patch_review"
            if ready_for_guarded_start_policy_patch_review
            else "blocked"
        ),
        "implementation_status": "guarded_start_runtime_handoff_contract_no_process_start",
        "guarded_start_runtime_handoff_contract_implemented": True,
        "runtime_handoff_contract_only": True,
        "runtime_family": handoff_plan.get("runtime_family") if handoff_plan_ready else None,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": False,
        "real_start_adapter_enabled": False,
        "start_execution_allowed": False,
        "real_subprocess_start_implemented": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "launch_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "provider_calls_made": False,
        "tool_calls_made": False,
        "raw_audio_touched": False,
        "decision_receipt_id": handoff_plan.get("decision_receipt_id") if handoff_plan_ready else None,
        "guarded_start_simulation_contract_status": guarded_start_simulation_contract.get("status"),
        "required_handoff_controls": [
            "kernel_runtime_invocation_contract_before_policy_patch",
            "decision_receipt_before_runtime_handoff",
            "evidence_sink_before_runtime_handoff",
            "rollback_plan_before_runtime_handoff",
            "policy_patch_review_before_any_start",
            "human_review_before_any_start",
        ],
        "gates": {
            "guarded_start_simulation_contract_ready": simulation_contract_ready,
            "handoff_plan_ready": handoff_plan_ready,
            "runtime_handoff_contract_only": True,
            "kernel_runtime_invocation_contract_required": True,
            "decision_receipt_required": True,
            "evidence_sink_required": True,
            "rollback_plan_required": True,
            "policy_patch_review_required": True,
            "human_review_required": True,
            "runtime_policy_start_disabled": True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "convert_runtime_handoff_contract_into_real_start",
            "import_subprocess_from_guarded_start_runtime_handoff_contract",
            "start_process_from_guarded_start_runtime_handoff_contract",
            "skip_policy_patch_review_from_guarded_start_runtime_handoff_contract",
            "call_provider_from_guarded_start_runtime_handoff_contract",
            "persist_raw_audio_from_guarded_start_runtime_handoff_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_RUNTIME_HANDOFF_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_policy_patch_review_contract"
            if ready_for_guarded_start_policy_patch_review
            else "fix_guarded_start_runtime_handoff_prerequisites"
        ),
    })


def inspect_guarded_start_policy_patch_review_contract(
    *,
    guarded_start_runtime_handoff_contract: Mapping[str, Any],
    policy_patch_review_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Review the policy patch prerequisites without enabling runtime start."""

    authorization = policy_patch_review_authorization or {}
    handoff_contract_ready = (
        guarded_start_runtime_handoff_contract.get("schema_version") == GUARDED_START_RUNTIME_HANDOFF_CONTRACT_SCHEMA_VERSION
        and guarded_start_runtime_handoff_contract.get("status") == "ready_for_guarded_start_policy_patch_review"
        and guarded_start_runtime_handoff_contract.get("guarded_start_runtime_handoff_contract_implemented") is True
        and guarded_start_runtime_handoff_contract.get("runtime_handoff_contract_only") is True
        and guarded_start_runtime_handoff_contract.get("runtime_family") == "python_ai_data"
        and guarded_start_runtime_handoff_contract.get("start_execution_allowed") is False
        and guarded_start_runtime_handoff_contract.get("process_launch_attempted") is False
        and guarded_start_runtime_handoff_contract.get("daemon_started") is False
        and guarded_start_runtime_handoff_contract.get("subprocess_module_imported") is False
        and guarded_start_runtime_handoff_contract.get("provider_calls_made") is False
        and guarded_start_runtime_handoff_contract.get("tool_calls_made") is False
        and guarded_start_runtime_handoff_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_POLICY_PATCH_REVIEW_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_policy_patch_review_contract"
        and authorization.get("policy_patch_review_contract_allowed") is True
        and authorization.get("runtime_policy_start_enabled") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("policy_patch_diff_attached") is True
        and authorization.get("policy_patch_dry_run_passed") is True
        and authorization.get("rollback_plan_attached") is True
        and authorization.get("human_review_required") is True
        and authorization.get("decision_receipt_required") is True
        and isinstance(authorization.get("reviewed_policy_patch_hash"), str)
        and len(str(authorization.get("reviewed_policy_patch_hash"))) == 64
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_human_review = handoff_contract_ready and authorization_ready

    return validate_guarded_start_policy_patch_review_contract_packet({
        "schema_version": GUARDED_START_POLICY_PATCH_REVIEW_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_human_review"
            if ready_for_guarded_start_human_review
            else "blocked"
        ),
        "implementation_status": "guarded_start_policy_patch_review_contract_no_policy_enablement",
        "guarded_start_policy_patch_review_contract_implemented": True,
        "policy_patch_review_only": True,
        "runtime_family": guarded_start_runtime_handoff_contract.get("runtime_family") if handoff_contract_ready else None,
        "reviewed_policy_patch_hash": authorization.get("reviewed_policy_patch_hash") if authorization_ready else None,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": False,
        "real_start_adapter_enabled": False,
        "start_execution_allowed": False,
        "real_subprocess_start_implemented": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "launch_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "provider_calls_made": False,
        "tool_calls_made": False,
        "raw_audio_touched": False,
        "decision_receipt_id": authorization.get("decision_receipt_id") if authorization_ready else None,
        "guarded_start_runtime_handoff_contract_status": guarded_start_runtime_handoff_contract.get("status"),
        "required_policy_patch_controls": [
            "policy_patch_diff_reviewed_before_enablement",
            "policy_patch_dry_run_before_enablement",
            "rollback_plan_before_policy_enablement",
            "decision_receipt_before_policy_enablement",
            "human_review_before_policy_enablement",
        ],
        "gates": {
            "guarded_start_runtime_handoff_contract_ready": handoff_contract_ready,
            "policy_patch_review_authorization_ready": authorization_ready,
            "policy_patch_review_only": True,
            "policy_patch_diff_attached": authorization.get("policy_patch_diff_attached") is True,
            "policy_patch_dry_run_passed": authorization.get("policy_patch_dry_run_passed") is True,
            "rollback_plan_required": True,
            "decision_receipt_required": True,
            "human_review_required": True,
            "runtime_policy_start_disabled": True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "enable_runtime_policy_from_policy_patch_review_contract",
            "start_process_from_guarded_start_policy_patch_review_contract",
            "skip_human_review_from_guarded_start_policy_patch_review_contract",
            "call_provider_from_guarded_start_policy_patch_review_contract",
            "persist_raw_audio_from_guarded_start_policy_patch_review_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_POLICY_PATCH_REVIEW_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_human_review_contract"
            if ready_for_guarded_start_human_review
            else "fix_guarded_start_policy_patch_review_prerequisites"
        ),
    })


def inspect_guarded_start_human_review_contract(
    *,
    guarded_start_policy_patch_review_contract: Mapping[str, Any],
    human_review_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Verify human review of the guarded start policy patch without enabling start."""

    authorization = human_review_authorization or {}
    policy_patch_contract_ready = (
        guarded_start_policy_patch_review_contract.get("schema_version") == GUARDED_START_POLICY_PATCH_REVIEW_CONTRACT_SCHEMA_VERSION
        and guarded_start_policy_patch_review_contract.get("status") == "ready_for_guarded_start_human_review"
        and guarded_start_policy_patch_review_contract.get("guarded_start_policy_patch_review_contract_implemented") is True
        and guarded_start_policy_patch_review_contract.get("policy_patch_review_only") is True
        and guarded_start_policy_patch_review_contract.get("runtime_policy_start_enabled") is False
        and guarded_start_policy_patch_review_contract.get("start_execution_allowed") is False
        and guarded_start_policy_patch_review_contract.get("process_launch_attempted") is False
        and guarded_start_policy_patch_review_contract.get("daemon_started") is False
        and guarded_start_policy_patch_review_contract.get("subprocess_module_imported") is False
        and guarded_start_policy_patch_review_contract.get("provider_calls_made") is False
        and guarded_start_policy_patch_review_contract.get("tool_calls_made") is False
        and guarded_start_policy_patch_review_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_HUMAN_REVIEW_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_human_review_contract"
        and authorization.get("human_review_contract_allowed") is True
        and authorization.get("human_review_completed") is True
        and authorization.get("operator_approved_policy_patch") is True
        and authorization.get("runtime_policy_start_enabled") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("rollback_plan_reviewed") is True
        and authorization.get("decision_receipt_required") is True
        and authorization.get("final_enablement_gate_required") is True
        and isinstance(authorization.get("reviewed_policy_patch_hash"), str)
        and len(str(authorization.get("reviewed_policy_patch_hash"))) == 64
        and authorization.get("reviewed_policy_patch_hash")
        == guarded_start_policy_patch_review_contract.get("reviewed_policy_patch_hash")
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_final_enablement_gate = policy_patch_contract_ready and authorization_ready

    return validate_guarded_start_human_review_contract_packet({
        "schema_version": GUARDED_START_HUMAN_REVIEW_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_final_enablement_gate"
            if ready_for_guarded_start_final_enablement_gate
            else "blocked"
        ),
        "implementation_status": "guarded_start_human_review_contract_no_policy_enablement",
        "guarded_start_human_review_contract_implemented": True,
        "human_review_contract_only": True,
        "runtime_family": guarded_start_policy_patch_review_contract.get("runtime_family") if policy_patch_contract_ready else None,
        "reviewed_policy_patch_hash": authorization.get("reviewed_policy_patch_hash") if authorization_ready else None,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": False,
        "real_start_adapter_enabled": False,
        "start_execution_allowed": False,
        "real_subprocess_start_implemented": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "launch_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "provider_calls_made": False,
        "tool_calls_made": False,
        "raw_audio_touched": False,
        "decision_receipt_id": authorization.get("decision_receipt_id") if authorization_ready else None,
        "guarded_start_policy_patch_review_contract_status": guarded_start_policy_patch_review_contract.get("status"),
        "required_human_review_controls": [
            "operator_review_before_policy_enablement",
            "policy_patch_hash_match_before_enablement",
            "rollback_plan_human_review_before_enablement",
            "decision_receipt_before_final_enablement",
            "final_enablement_gate_before_any_start",
        ],
        "gates": {
            "guarded_start_policy_patch_review_contract_ready": policy_patch_contract_ready,
            "human_review_authorization_ready": authorization_ready,
            "human_review_contract_only": True,
            "human_review_completed": authorization.get("human_review_completed") is True,
            "operator_approved_policy_patch": authorization.get("operator_approved_policy_patch") is True,
            "policy_patch_hash_matches": (
                authorization.get("reviewed_policy_patch_hash")
                == guarded_start_policy_patch_review_contract.get("reviewed_policy_patch_hash")
            ),
            "rollback_plan_reviewed": authorization.get("rollback_plan_reviewed") is True,
            "decision_receipt_required": True,
            "final_enablement_gate_required": True,
            "runtime_policy_start_disabled": True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "enable_runtime_policy_from_human_review_contract",
            "start_process_from_guarded_start_human_review_contract",
            "skip_final_enablement_gate_from_guarded_start_human_review_contract",
            "call_provider_from_guarded_start_human_review_contract",
            "persist_raw_audio_from_guarded_start_human_review_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_HUMAN_REVIEW_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_final_enablement_gate_contract"
            if ready_for_guarded_start_final_enablement_gate
            else "fix_guarded_start_human_review_prerequisites"
        ),
    })


def inspect_guarded_start_final_enablement_gate_contract(
    *,
    guarded_start_human_review_contract: Mapping[str, Any],
    final_enablement_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Declare the final enablement gate while keeping guarded start disabled."""

    authorization = final_enablement_authorization or {}
    human_review_contract_ready = (
        guarded_start_human_review_contract.get("schema_version") == GUARDED_START_HUMAN_REVIEW_CONTRACT_SCHEMA_VERSION
        and guarded_start_human_review_contract.get("status") == "ready_for_guarded_start_final_enablement_gate"
        and guarded_start_human_review_contract.get("guarded_start_human_review_contract_implemented") is True
        and guarded_start_human_review_contract.get("human_review_contract_only") is True
        and guarded_start_human_review_contract.get("runtime_policy_start_enabled") is False
        and guarded_start_human_review_contract.get("start_execution_allowed") is False
        and guarded_start_human_review_contract.get("process_launch_attempted") is False
        and guarded_start_human_review_contract.get("daemon_started") is False
        and guarded_start_human_review_contract.get("subprocess_module_imported") is False
        and guarded_start_human_review_contract.get("provider_calls_made") is False
        and guarded_start_human_review_contract.get("tool_calls_made") is False
        and guarded_start_human_review_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_FINAL_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_final_enablement_gate"
        and authorization.get("final_enablement_gate_allowed") is True
        and authorization.get("runtime_policy_start_enabled") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("human_review_contract_attached") is True
        and authorization.get("final_pre_start_receipt_attached") is True
        and authorization.get("single_start_per_receipt_required") is True
        and authorization.get("post_start_ready_event_required") is True
        and authorization.get("rollback_rehearsal_passed") is True
        and isinstance(authorization.get("reviewed_policy_patch_hash"), str)
        and len(str(authorization.get("reviewed_policy_patch_hash"))) == 64
        and authorization.get("reviewed_policy_patch_hash")
        == guarded_start_human_review_contract.get("reviewed_policy_patch_hash")
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_policy_enablement_contract = human_review_contract_ready and authorization_ready

    return validate_guarded_start_final_enablement_gate_contract_packet({
        "schema_version": GUARDED_START_FINAL_ENABLEMENT_GATE_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_policy_enablement_contract"
            if ready_for_guarded_start_policy_enablement_contract
            else "blocked"
        ),
        "implementation_status": "guarded_start_final_enablement_gate_no_policy_enablement",
        "guarded_start_final_enablement_gate_implemented": True,
        "final_enablement_gate_only": True,
        "runtime_family": guarded_start_human_review_contract.get("runtime_family") if human_review_contract_ready else None,
        "reviewed_policy_patch_hash": authorization.get("reviewed_policy_patch_hash") if authorization_ready else None,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": False,
        "real_start_adapter_enabled": False,
        "start_execution_allowed": False,
        "real_subprocess_start_implemented": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "launch_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "provider_calls_made": False,
        "tool_calls_made": False,
        "raw_audio_touched": False,
        "decision_receipt_id": authorization.get("decision_receipt_id") if authorization_ready else None,
        "guarded_start_human_review_contract_status": guarded_start_human_review_contract.get("status"),
        "required_final_enablement_controls": [
            "human_review_contract_before_final_gate",
            "final_pre_start_receipt_before_policy_enablement",
            "single_start_per_receipt_before_policy_enablement",
            "post_start_ready_event_contract_before_policy_enablement",
            "rollback_rehearsal_before_policy_enablement",
        ],
        "gates": {
            "guarded_start_human_review_contract_ready": human_review_contract_ready,
            "final_enablement_authorization_ready": authorization_ready,
            "final_enablement_gate_only": True,
            "human_review_contract_attached": authorization.get("human_review_contract_attached") is True,
            "final_pre_start_receipt_attached": authorization.get("final_pre_start_receipt_attached") is True,
            "policy_patch_hash_matches": (
                authorization.get("reviewed_policy_patch_hash")
                == guarded_start_human_review_contract.get("reviewed_policy_patch_hash")
            ),
            "single_start_per_receipt_required": True,
            "post_start_ready_event_required": True,
            "rollback_rehearsal_passed": authorization.get("rollback_rehearsal_passed") is True,
            "runtime_policy_start_disabled": True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "enable_runtime_policy_from_final_enablement_gate",
            "start_process_from_guarded_start_final_enablement_gate",
            "skip_policy_enablement_contract_from_guarded_start_final_enablement_gate",
            "call_provider_from_guarded_start_final_enablement_gate",
            "persist_raw_audio_from_guarded_start_final_enablement_gate",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_FINAL_ENABLEMENT_GATE_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_policy_enablement_contract"
            if ready_for_guarded_start_policy_enablement_contract
            else "fix_guarded_start_final_enablement_prerequisites"
        ),
    })


def inspect_guarded_start_policy_enablement_contract(
    *,
    guarded_start_final_enablement_gate: Mapping[str, Any],
    policy_enablement_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Apply the reviewed guarded-start policy switch without starting a daemon."""

    authorization = policy_enablement_authorization or {}
    final_gate_ready = (
        guarded_start_final_enablement_gate.get("schema_version") == GUARDED_START_FINAL_ENABLEMENT_GATE_SCHEMA_VERSION
        and guarded_start_final_enablement_gate.get("status") == "ready_for_guarded_start_policy_enablement_contract"
        and guarded_start_final_enablement_gate.get("guarded_start_final_enablement_gate_implemented") is True
        and guarded_start_final_enablement_gate.get("final_enablement_gate_only") is True
        and guarded_start_final_enablement_gate.get("runtime_policy_start_enabled") is False
        and guarded_start_final_enablement_gate.get("start_execution_allowed") is False
        and guarded_start_final_enablement_gate.get("process_launch_attempted") is False
        and guarded_start_final_enablement_gate.get("daemon_started") is False
        and guarded_start_final_enablement_gate.get("subprocess_module_imported") is False
        and guarded_start_final_enablement_gate.get("provider_calls_made") is False
        and guarded_start_final_enablement_gate.get("tool_calls_made") is False
        and guarded_start_final_enablement_gate.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_POLICY_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_policy_enablement_contract"
        and authorization.get("policy_enablement_contract_allowed") is True
        and authorization.get("runtime_policy_start_enabled") is True
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("final_enablement_gate_attached") is True
        and authorization.get("human_review_contract_attached") is True
        and authorization.get("rollback_plan_attached") is True
        and authorization.get("single_start_per_receipt_required") is True
        and authorization.get("post_start_ready_event_required") is True
        and authorization.get("policy_revoke_supported") is True
        and isinstance(authorization.get("reviewed_policy_patch_hash"), str)
        and len(str(authorization.get("reviewed_policy_patch_hash"))) == 64
        and authorization.get("reviewed_policy_patch_hash")
        == guarded_start_final_enablement_gate.get("reviewed_policy_patch_hash")
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_activation_contract = final_gate_ready and authorization_ready

    return validate_guarded_start_policy_enablement_contract_packet({
        "schema_version": GUARDED_START_POLICY_ENABLEMENT_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_activation_contract"
            if ready_for_guarded_start_activation_contract
            else "blocked"
        ),
        "implementation_status": "guarded_start_policy_enabled_without_process_start",
        "guarded_start_policy_enablement_contract_implemented": True,
        "policy_enablement_contract_only": True,
        "runtime_family": guarded_start_final_enablement_gate.get("runtime_family") if final_gate_ready else None,
        "reviewed_policy_patch_hash": authorization.get("reviewed_policy_patch_hash") if authorization_ready else None,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_activation_contract,
        "real_start_adapter_enabled": False,
        "start_execution_allowed": False,
        "real_subprocess_start_implemented": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "launch_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "provider_calls_made": False,
        "tool_calls_made": False,
        "raw_audio_touched": False,
        "decision_receipt_id": authorization.get("decision_receipt_id") if authorization_ready else None,
        "guarded_start_final_enablement_gate_status": guarded_start_final_enablement_gate.get("status"),
        "required_policy_enablement_controls": [
            "final_enablement_gate_before_policy_enablement",
            "policy_switch_without_process_start",
            "single_start_per_receipt_before_activation",
            "post_start_ready_event_before_activation",
            "policy_revoke_before_any_activation",
        ],
        "gates": {
            "guarded_start_final_enablement_gate_ready": final_gate_ready,
            "policy_enablement_authorization_ready": authorization_ready,
            "policy_enablement_contract_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_activation_contract,
            "final_enablement_gate_attached": authorization.get("final_enablement_gate_attached") is True,
            "human_review_contract_attached": authorization.get("human_review_contract_attached") is True,
            "policy_patch_hash_matches": (
                authorization.get("reviewed_policy_patch_hash")
                == guarded_start_final_enablement_gate.get("reviewed_policy_patch_hash")
            ),
            "rollback_plan_attached": authorization.get("rollback_plan_attached") is True,
            "single_start_per_receipt_required": True,
            "post_start_ready_event_required": True,
            "policy_revoke_supported": authorization.get("policy_revoke_supported") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_policy_enablement_contract",
            "enable_guarded_executor_from_policy_enablement_contract",
            "skip_activation_contract_from_guarded_start_policy_enablement_contract",
            "call_provider_from_guarded_start_policy_enablement_contract",
            "persist_raw_audio_from_guarded_start_policy_enablement_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_POLICY_ENABLEMENT_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_activation_contract"
            if ready_for_guarded_start_activation_contract
            else "fix_guarded_start_policy_enablement_prerequisites"
        ),
    })


def inspect_guarded_start_activation_contract(
    *,
    guarded_start_policy_enablement_contract: Mapping[str, Any],
    activation_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Attach the guarded activation contract without executing the start."""

    authorization = activation_authorization or {}
    policy_enablement_ready = (
        guarded_start_policy_enablement_contract.get("schema_version") == GUARDED_START_POLICY_ENABLEMENT_CONTRACT_SCHEMA_VERSION
        and guarded_start_policy_enablement_contract.get("status") == "ready_for_guarded_start_activation_contract"
        and guarded_start_policy_enablement_contract.get("guarded_start_policy_enablement_contract_implemented") is True
        and guarded_start_policy_enablement_contract.get("policy_enablement_contract_only") is True
        and guarded_start_policy_enablement_contract.get("runtime_policy_start_enabled") is True
        and guarded_start_policy_enablement_contract.get("guarded_start_executor_enabled") is False
        and guarded_start_policy_enablement_contract.get("start_execution_allowed") is False
        and guarded_start_policy_enablement_contract.get("process_launch_attempted") is False
        and guarded_start_policy_enablement_contract.get("daemon_started") is False
        and guarded_start_policy_enablement_contract.get("subprocess_module_imported") is False
        and guarded_start_policy_enablement_contract.get("provider_calls_made") is False
        and guarded_start_policy_enablement_contract.get("tool_calls_made") is False
        and guarded_start_policy_enablement_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_ACTIVATION_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_activation_contract"
        and authorization.get("activation_contract_allowed") is True
        and authorization.get("runtime_policy_start_enabled") is True
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("policy_enablement_contract_attached") is True
        and authorization.get("final_enablement_gate_attached") is True
        and authorization.get("human_review_contract_attached") is True
        and authorization.get("activation_window_declared") is True
        and authorization.get("operator_activation_review_required") is True
        and authorization.get("post_start_observability_required") is True
        and authorization.get("rollback_plan_attached") is True
        and authorization.get("policy_revoke_supported") is True
        and authorization.get("single_start_per_receipt_required") is True
        and isinstance(authorization.get("reviewed_policy_patch_hash"), str)
        and len(str(authorization.get("reviewed_policy_patch_hash"))) == 64
        and authorization.get("reviewed_policy_patch_hash")
        == guarded_start_policy_enablement_contract.get("reviewed_policy_patch_hash")
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_execution_attempt_contract = policy_enablement_ready and authorization_ready

    return validate_guarded_start_activation_contract_packet({
        "schema_version": GUARDED_START_ACTIVATION_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_execution_attempt_contract"
            if ready_for_guarded_start_execution_attempt_contract
            else "blocked"
        ),
        "implementation_status": "guarded_start_activation_contract_attached_without_process_start",
        "guarded_start_activation_contract_implemented": True,
        "activation_contract_only": True,
        "runtime_family": (
            guarded_start_policy_enablement_contract.get("runtime_family")
            if policy_enablement_ready
            else None
        ),
        "reviewed_policy_patch_hash": authorization.get("reviewed_policy_patch_hash") if authorization_ready else None,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_execution_attempt_contract,
        "real_start_adapter_enabled": False,
        "start_execution_allowed": False,
        "real_subprocess_start_implemented": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "launch_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_imported": False,
        "livekit_sdk_imported": False,
        "provider_calls_made": False,
        "tool_calls_made": False,
        "raw_audio_touched": False,
        "decision_receipt_id": authorization.get("decision_receipt_id") if authorization_ready else None,
        "guarded_start_policy_enablement_contract_status": guarded_start_policy_enablement_contract.get("status"),
        "required_activation_controls": [
            "policy_enablement_contract_before_activation",
            "activation_window_before_execution_attempt_contract",
            "operator_activation_review_before_execution_attempt_contract",
            "post_start_observability_before_execution_attempt_contract",
            "rollback_plan_before_execution_attempt_contract",
            "policy_revoke_before_execution_attempt_contract",
        ],
        "gates": {
            "guarded_start_policy_enablement_contract_ready": policy_enablement_ready,
            "activation_authorization_ready": authorization_ready,
            "activation_contract_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_execution_attempt_contract,
            "policy_enablement_contract_attached": authorization.get("policy_enablement_contract_attached") is True,
            "final_enablement_gate_attached": authorization.get("final_enablement_gate_attached") is True,
            "human_review_contract_attached": authorization.get("human_review_contract_attached") is True,
            "policy_patch_hash_matches": (
                authorization.get("reviewed_policy_patch_hash")
                == guarded_start_policy_enablement_contract.get("reviewed_policy_patch_hash")
            ),
            "activation_window_declared": authorization.get("activation_window_declared") is True,
            "operator_activation_review_required": authorization.get("operator_activation_review_required") is True,
            "post_start_observability_required": authorization.get("post_start_observability_required") is True,
            "rollback_plan_attached": authorization.get("rollback_plan_attached") is True,
            "policy_revoke_supported": authorization.get("policy_revoke_supported") is True,
            "single_start_per_receipt_required": True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_activation_contract",
            "enable_guarded_executor_from_activation_contract",
            "skip_execution_attempt_contract_from_guarded_start_activation_contract",
            "call_provider_from_guarded_start_activation_contract",
            "persist_raw_audio_from_guarded_start_activation_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_ACTIVATION_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_execution_attempt_contract"
            if ready_for_guarded_start_execution_attempt_contract
            else "fix_guarded_start_activation_prerequisites"
        ),
    })


def _redacted_argv(blueprint: Mapping[str, Any]) -> list[str]:
    argv = blueprint.get("argv_template", [])
    if not isinstance(argv, list):
        return []

    return [
        "<managed-env-file-written>" if value == "<managed-env-file>" else str(value)
        for value in argv
    ]


def _health_check_result_passed(result: Mapping[str, Any]) -> bool:
    return (
        result.get("status") == "passed"
        and result.get("passed") is True
        and result.get("starts_process") is False
        and result.get("provider_calls_made") is False
        and result.get("tool_calls_made") is False
        and result.get("raw_audio_touched") is False
    )
