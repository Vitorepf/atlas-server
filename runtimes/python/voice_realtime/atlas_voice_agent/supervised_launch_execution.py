from __future__ import annotations

from typing import Any, Mapping

from .supervised_launch_packet import (
    validate_pre_start_health_checks_packet,
    validate_real_start_adapter_enablement_gate_packet,
    validate_real_start_adapter_disabled_packet,
    validate_reviewed_subprocess_start_execution_packet,
    validate_real_start_adapter_review_contract_packet,
    validate_reviewed_real_start_execution_contract_packet,
    validate_runtime_policy_enablement_review_packet,
    validate_subprocess_start_packet,
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
