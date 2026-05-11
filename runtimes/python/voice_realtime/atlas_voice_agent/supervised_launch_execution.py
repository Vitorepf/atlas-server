from __future__ import annotations

from typing import Any, Mapping


SCHEMA_VERSION = "atlas.voice_realtime.supervised_launch_execution.v1"
LAUNCH_EXECUTION_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.launch_execution_authorization.v1"
MANAGED_ENV_WRITE_EXECUTION_SCHEMA_VERSION = "atlas.voice_realtime.managed_env_write_execution.v1"
PRE_START_HEALTH_CHECKS_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.pre_start_health_checks_authorization.v1"
PRE_START_HEALTH_CHECKS_EXECUTION_SCHEMA_VERSION = "atlas.voice_realtime.pre_start_health_checks_execution.v1"
SUBPROCESS_START_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.subprocess_start_authorization.v1"
SUBPROCESS_START_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.subprocess_start_contract.v1"


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

    return {
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
    }


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

    return {
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
    }


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

    return {
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
    }


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
