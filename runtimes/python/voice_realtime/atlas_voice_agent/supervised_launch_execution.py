from __future__ import annotations

from typing import Any, Mapping

from .supervised_launch_packet import (
    validate_final_start_executor_disabled_packet,
    validate_final_start_executor_enablement_gate_packet,
    validate_guarded_start_final_enablement_gate_contract_packet,
    validate_guarded_start_human_review_contract_packet,
    validate_guarded_start_policy_enablement_contract_packet,
    validate_guarded_start_activation_contract_packet,
    validate_guarded_start_execution_attempt_contract_packet,
    validate_guarded_start_execution_rehearsal_contract_packet,
    validate_guarded_start_final_start_receipt_contract_packet,
    validate_guarded_start_launch_window_contract_packet,
    validate_guarded_start_pre_launch_guard_contract_packet,
    validate_guarded_start_executor_runtime_contract_packet,
    validate_guarded_start_process_spawn_contract_packet,
    validate_guarded_start_spawn_review_contract_packet,
    validate_guarded_start_subprocess_import_contract_packet,
    validate_guarded_start_launch_invocation_contract_packet,
    validate_guarded_start_final_process_start_contract_packet,
    validate_guarded_start_process_execution_review_packet,
    validate_guarded_start_process_execution_packet_packet,
    validate_guarded_start_process_executor_stub_packet,
    validate_guarded_start_process_executor_review_packet,
    validate_guarded_start_process_executor_contract_packet,
    validate_guarded_start_process_runtime_adapter_packet,
    validate_guarded_start_process_adapter_review_packet,
    validate_guarded_start_process_adapter_contract_packet,
    validate_guarded_start_process_runner_contract_packet,
    validate_guarded_start_process_runner_execution_contract_packet,
    validate_guarded_start_process_runner_execution_review_packet,
    validate_guarded_start_process_runner_packet_packet,
    validate_guarded_start_process_runner_final_review_packet,
    validate_guarded_start_process_runner_operator_release_review_packet,
    validate_guarded_start_process_runner_release_authorization_packet,
    validate_guarded_start_process_runner_release_finalization_packet,
    validate_controlled_livekit_server_supervised_smoke_contract_packet,
    validate_guarded_start_process_runner_promotion_packet_packet,
    validate_guarded_start_process_runner_review_packet,
    validate_guarded_start_process_runner_start_gate_packet,
    validate_guarded_start_observability_contract_packet,
    validate_guarded_start_operator_acceptance_contract_packet,
    validate_guarded_start_release_candidate_contract_packet,
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
GUARDED_START_EXECUTION_ATTEMPT_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_execution_attempt_authorization.v1"
GUARDED_START_EXECUTION_ATTEMPT_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_execution_attempt_contract.v1"
GUARDED_START_EXECUTION_REHEARSAL_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_execution_rehearsal_authorization.v1"
GUARDED_START_EXECUTION_REHEARSAL_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_execution_rehearsal_contract.v1"
GUARDED_START_OBSERVABILITY_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_observability_authorization.v1"
GUARDED_START_OBSERVABILITY_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_observability_contract.v1"
GUARDED_START_RELEASE_CANDIDATE_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_release_candidate_authorization.v1"
GUARDED_START_RELEASE_CANDIDATE_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_release_candidate_contract.v1"
GUARDED_START_OPERATOR_ACCEPTANCE_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_operator_acceptance_authorization.v1"
GUARDED_START_OPERATOR_ACCEPTANCE_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_operator_acceptance_contract.v1"
GUARDED_START_FINAL_START_RECEIPT_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_final_start_receipt_authorization.v1"
GUARDED_START_FINAL_START_RECEIPT_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_final_start_receipt_contract.v1"
GUARDED_START_LAUNCH_WINDOW_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_launch_window_authorization.v1"
GUARDED_START_LAUNCH_WINDOW_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_launch_window_contract.v1"
GUARDED_START_PRE_LAUNCH_GUARD_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_pre_launch_guard_authorization.v1"
GUARDED_START_PRE_LAUNCH_GUARD_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_pre_launch_guard_contract.v1"
GUARDED_START_EXECUTOR_RUNTIME_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_executor_runtime_authorization.v1"
GUARDED_START_EXECUTOR_RUNTIME_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_executor_runtime_contract.v1"
GUARDED_START_PROCESS_SPAWN_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_spawn_authorization.v1"
GUARDED_START_PROCESS_SPAWN_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_spawn_contract.v1"
GUARDED_START_SPAWN_REVIEW_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_spawn_review_authorization.v1"
GUARDED_START_SPAWN_REVIEW_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_spawn_review_contract.v1"
GUARDED_START_SUBPROCESS_IMPORT_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_subprocess_import_authorization.v1"
GUARDED_START_SUBPROCESS_IMPORT_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_subprocess_import_contract.v1"
GUARDED_START_LAUNCH_INVOCATION_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_launch_invocation_authorization.v1"
GUARDED_START_LAUNCH_INVOCATION_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_launch_invocation_contract.v1"
GUARDED_START_FINAL_PROCESS_START_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_final_process_start_authorization.v1"
GUARDED_START_FINAL_PROCESS_START_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_final_process_start_contract.v1"
GUARDED_START_PROCESS_EXECUTION_REVIEW_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_execution_review_authorization.v1"
GUARDED_START_PROCESS_EXECUTION_REVIEW_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_execution_review.v1"
GUARDED_START_PROCESS_EXECUTION_PACKET_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_execution_packet_authorization.v1"
GUARDED_START_PROCESS_EXECUTION_PACKET_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_execution_packet.v1"
GUARDED_START_PROCESS_EXECUTOR_STUB_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_executor_stub_authorization.v1"
GUARDED_START_PROCESS_EXECUTOR_STUB_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_executor_stub.v1"
GUARDED_START_PROCESS_EXECUTOR_REVIEW_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_executor_review_authorization.v1"
GUARDED_START_PROCESS_EXECUTOR_REVIEW_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_executor_review.v1"
GUARDED_START_PROCESS_EXECUTOR_CONTRACT_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_executor_contract_authorization.v1"
GUARDED_START_PROCESS_EXECUTOR_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_executor_contract.v1"
GUARDED_START_PROCESS_RUNTIME_ADAPTER_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runtime_adapter_authorization.v1"
GUARDED_START_PROCESS_RUNTIME_ADAPTER_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runtime_adapter.v1"
GUARDED_START_PROCESS_ADAPTER_REVIEW_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_adapter_review_authorization.v1"
GUARDED_START_PROCESS_ADAPTER_REVIEW_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_adapter_review.v1"
GUARDED_START_PROCESS_ADAPTER_CONTRACT_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_adapter_contract_authorization.v1"
GUARDED_START_PROCESS_ADAPTER_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_adapter_contract.v1"
GUARDED_START_PROCESS_RUNNER_CONTRACT_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runner_contract_authorization.v1"
GUARDED_START_PROCESS_RUNNER_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runner_contract.v1"
GUARDED_START_PROCESS_RUNNER_REVIEW_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runner_review_authorization.v1"
GUARDED_START_PROCESS_RUNNER_REVIEW_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runner_review.v1"
GUARDED_START_PROCESS_RUNNER_PACKET_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runner_packet_authorization.v1"
GUARDED_START_PROCESS_RUNNER_PACKET_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runner_packet.v1"
GUARDED_START_PROCESS_RUNNER_EXECUTION_REVIEW_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runner_execution_review_authorization.v1"
GUARDED_START_PROCESS_RUNNER_EXECUTION_REVIEW_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runner_execution_review.v1"
GUARDED_START_PROCESS_RUNNER_EXECUTION_CONTRACT_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runner_execution_contract_authorization.v1"
GUARDED_START_PROCESS_RUNNER_EXECUTION_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runner_execution_contract.v1"
GUARDED_START_PROCESS_RUNNER_START_GATE_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runner_start_gate_authorization.v1"
GUARDED_START_PROCESS_RUNNER_START_GATE_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runner_start_gate.v1"
GUARDED_START_PROCESS_RUNNER_FINAL_REVIEW_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runner_final_review_authorization.v1"
GUARDED_START_PROCESS_RUNNER_FINAL_REVIEW_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runner_final_review.v1"
GUARDED_START_PROCESS_RUNNER_PROMOTION_PACKET_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runner_promotion_packet_authorization.v1"
GUARDED_START_PROCESS_RUNNER_PROMOTION_PACKET_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runner_promotion_packet.v1"
GUARDED_START_PROCESS_RUNNER_OPERATOR_RELEASE_REVIEW_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runner_operator_release_review_authorization.v1"
GUARDED_START_PROCESS_RUNNER_OPERATOR_RELEASE_REVIEW_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runner_operator_release_review.v1"
GUARDED_START_PROCESS_RUNNER_RELEASE_FINALIZATION_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runner_release_finalization_authorization.v1"
GUARDED_START_PROCESS_RUNNER_RELEASE_FINALIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runner_release_finalization.v1"
GUARDED_START_PROCESS_RUNNER_RELEASE_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runner_release_authorization.v1"
GUARDED_START_PROCESS_RUNNER_RELEASE_AUTHORIZATION_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.guarded_start_process_runner_release_authorization_contract.v1"
CONTROLLED_LIVEKIT_SERVER_SUPERVISED_SMOKE_PLAN_SCHEMA_VERSION = "atlas.voice_realtime.controlled_livekit_server_supervised_smoke_plan.v1"
CONTROLLED_LIVEKIT_SERVER_SUPERVISED_SMOKE_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.controlled_livekit_server_supervised_smoke_contract.v1"


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


def inspect_guarded_start_execution_attempt_contract(
    *,
    guarded_start_activation_contract: Mapping[str, Any],
    execution_attempt_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Declare the guarded execution-attempt boundary without launching."""

    authorization = execution_attempt_authorization or {}
    activation_contract_ready = (
        guarded_start_activation_contract.get("schema_version") == GUARDED_START_ACTIVATION_CONTRACT_SCHEMA_VERSION
        and guarded_start_activation_contract.get("status") == "ready_for_guarded_start_execution_attempt_contract"
        and guarded_start_activation_contract.get("guarded_start_activation_contract_implemented") is True
        and guarded_start_activation_contract.get("activation_contract_only") is True
        and guarded_start_activation_contract.get("runtime_policy_start_enabled") is True
        and guarded_start_activation_contract.get("guarded_start_executor_enabled") is False
        and guarded_start_activation_contract.get("start_execution_allowed") is False
        and guarded_start_activation_contract.get("process_launch_attempted") is False
        and guarded_start_activation_contract.get("daemon_started") is False
        and guarded_start_activation_contract.get("subprocess_module_imported") is False
        and guarded_start_activation_contract.get("provider_calls_made") is False
        and guarded_start_activation_contract.get("tool_calls_made") is False
        and guarded_start_activation_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_EXECUTION_ATTEMPT_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_execution_attempt_contract"
        and authorization.get("execution_attempt_contract_allowed") is True
        and authorization.get("runtime_policy_start_enabled") is True
        and authorization.get("guarded_start_executor_enabled") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("activation_contract_attached") is True
        and authorization.get("policy_enablement_contract_attached") is True
        and authorization.get("operator_activation_review_required") is True
        and authorization.get("post_start_observability_required") is True
        and authorization.get("pid_guard_required") is True
        and authorization.get("startup_timeout_required") is True
        and authorization.get("stdout_stderr_sanitization_required") is True
        and authorization.get("ready_event_required") is True
        and authorization.get("rollback_plan_attached") is True
        and authorization.get("policy_revoke_supported") is True
        and authorization.get("single_start_per_receipt_required") is True
        and authorization.get("dry_run_rehearsal_attached") is True
        and isinstance(authorization.get("reviewed_policy_patch_hash"), str)
        and len(str(authorization.get("reviewed_policy_patch_hash"))) == 64
        and authorization.get("reviewed_policy_patch_hash")
        == guarded_start_activation_contract.get("reviewed_policy_patch_hash")
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_execution_rehearsal_contract = activation_contract_ready and authorization_ready

    return validate_guarded_start_execution_attempt_contract_packet({
        "schema_version": GUARDED_START_EXECUTION_ATTEMPT_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_execution_rehearsal_contract"
            if ready_for_guarded_start_execution_rehearsal_contract
            else "blocked"
        ),
        "implementation_status": "guarded_start_execution_attempt_contract_attached_without_process_start",
        "guarded_start_execution_attempt_contract_implemented": True,
        "execution_attempt_contract_only": True,
        "runtime_family": guarded_start_activation_contract.get("runtime_family") if activation_contract_ready else None,
        "reviewed_policy_patch_hash": authorization.get("reviewed_policy_patch_hash") if authorization_ready else None,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_execution_rehearsal_contract,
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
        "guarded_start_activation_contract_status": guarded_start_activation_contract.get("status"),
        "required_execution_attempt_controls": [
            "activation_contract_before_execution_attempt_contract",
            "pid_guard_before_execution_attempt_rehearsal",
            "startup_timeout_before_execution_attempt_rehearsal",
            "stdout_stderr_sanitization_before_execution_attempt_rehearsal",
            "ready_event_before_execution_attempt_rehearsal",
            "dry_run_rehearsal_before_any_process_launch",
        ],
        "gates": {
            "guarded_start_activation_contract_ready": activation_contract_ready,
            "execution_attempt_authorization_ready": authorization_ready,
            "execution_attempt_contract_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_execution_rehearsal_contract,
            "activation_contract_attached": authorization.get("activation_contract_attached") is True,
            "policy_enablement_contract_attached": authorization.get("policy_enablement_contract_attached") is True,
            "policy_patch_hash_matches": (
                authorization.get("reviewed_policy_patch_hash")
                == guarded_start_activation_contract.get("reviewed_policy_patch_hash")
            ),
            "operator_activation_review_required": authorization.get("operator_activation_review_required") is True,
            "post_start_observability_required": authorization.get("post_start_observability_required") is True,
            "pid_guard_required": authorization.get("pid_guard_required") is True,
            "startup_timeout_required": authorization.get("startup_timeout_required") is True,
            "stdout_stderr_sanitization_required": authorization.get("stdout_stderr_sanitization_required") is True,
            "ready_event_required": authorization.get("ready_event_required") is True,
            "rollback_plan_attached": authorization.get("rollback_plan_attached") is True,
            "policy_revoke_supported": authorization.get("policy_revoke_supported") is True,
            "single_start_per_receipt_required": True,
            "dry_run_rehearsal_attached": authorization.get("dry_run_rehearsal_attached") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_execution_attempt_contract",
            "enable_guarded_executor_from_execution_attempt_contract",
            "skip_execution_rehearsal_contract_from_execution_attempt_contract",
            "call_provider_from_guarded_start_execution_attempt_contract",
            "persist_raw_audio_from_guarded_start_execution_attempt_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_EXECUTION_ATTEMPT_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_execution_rehearsal_contract"
            if ready_for_guarded_start_execution_rehearsal_contract
            else "fix_guarded_start_execution_attempt_prerequisites"
        ),
    })


def inspect_guarded_start_execution_rehearsal_contract(
    *,
    guarded_start_execution_attempt_contract: Mapping[str, Any],
    execution_rehearsal_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Rehearse guarded start controls without enabling the executor."""

    authorization = execution_rehearsal_authorization or {}
    execution_attempt_ready = (
        guarded_start_execution_attempt_contract.get("schema_version") == GUARDED_START_EXECUTION_ATTEMPT_CONTRACT_SCHEMA_VERSION
        and guarded_start_execution_attempt_contract.get("status") == "ready_for_guarded_start_execution_rehearsal_contract"
        and guarded_start_execution_attempt_contract.get("guarded_start_execution_attempt_contract_implemented") is True
        and guarded_start_execution_attempt_contract.get("execution_attempt_contract_only") is True
        and guarded_start_execution_attempt_contract.get("runtime_policy_start_enabled") is True
        and guarded_start_execution_attempt_contract.get("guarded_start_executor_enabled") is False
        and guarded_start_execution_attempt_contract.get("start_execution_allowed") is False
        and guarded_start_execution_attempt_contract.get("process_launch_attempted") is False
        and guarded_start_execution_attempt_contract.get("daemon_started") is False
        and guarded_start_execution_attempt_contract.get("subprocess_module_imported") is False
        and guarded_start_execution_attempt_contract.get("provider_calls_made") is False
        and guarded_start_execution_attempt_contract.get("tool_calls_made") is False
        and guarded_start_execution_attempt_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_EXECUTION_REHEARSAL_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_execution_rehearsal_contract"
        and authorization.get("execution_rehearsal_contract_allowed") is True
        and authorization.get("runtime_policy_start_enabled") is True
        and authorization.get("guarded_start_executor_enabled") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("execution_attempt_contract_attached") is True
        and authorization.get("pid_guard_rehearsed") is True
        and authorization.get("startup_timeout_rehearsed") is True
        and authorization.get("stdout_stderr_sanitization_rehearsed") is True
        and authorization.get("ready_event_rehearsed") is True
        and authorization.get("rollback_rehearsed") is True
        and authorization.get("policy_revoke_supported") is True
        and authorization.get("single_start_per_receipt_required") is True
        and authorization.get("dry_run_rehearsal_only") is True
        and isinstance(authorization.get("reviewed_policy_patch_hash"), str)
        and len(str(authorization.get("reviewed_policy_patch_hash"))) == 64
        and authorization.get("reviewed_policy_patch_hash")
        == guarded_start_execution_attempt_contract.get("reviewed_policy_patch_hash")
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_observability_contract = execution_attempt_ready and authorization_ready

    return validate_guarded_start_execution_rehearsal_contract_packet({
        "schema_version": GUARDED_START_EXECUTION_REHEARSAL_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_observability_contract"
            if ready_for_guarded_start_observability_contract
            else "blocked"
        ),
        "implementation_status": "guarded_start_execution_rehearsal_contract_attached_without_process_start",
        "guarded_start_execution_rehearsal_contract_implemented": True,
        "execution_rehearsal_contract_only": True,
        "runtime_family": guarded_start_execution_attempt_contract.get("runtime_family") if execution_attempt_ready else None,
        "reviewed_policy_patch_hash": authorization.get("reviewed_policy_patch_hash") if authorization_ready else None,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_observability_contract,
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
        "guarded_start_execution_attempt_contract_status": guarded_start_execution_attempt_contract.get("status"),
        "required_rehearsal_controls": [
            "execution_attempt_contract_before_rehearsal",
            "pid_guard_rehearsal",
            "startup_timeout_rehearsal",
            "stdout_stderr_sanitization_rehearsal",
            "ready_event_rehearsal",
            "rollback_rehearsal",
        ],
        "gates": {
            "guarded_start_execution_attempt_contract_ready": execution_attempt_ready,
            "execution_rehearsal_authorization_ready": authorization_ready,
            "execution_rehearsal_contract_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_observability_contract,
            "execution_attempt_contract_attached": authorization.get("execution_attempt_contract_attached") is True,
            "policy_patch_hash_matches": (
                authorization.get("reviewed_policy_patch_hash")
                == guarded_start_execution_attempt_contract.get("reviewed_policy_patch_hash")
            ),
            "pid_guard_rehearsed": authorization.get("pid_guard_rehearsed") is True,
            "startup_timeout_rehearsed": authorization.get("startup_timeout_rehearsed") is True,
            "stdout_stderr_sanitization_rehearsed": authorization.get("stdout_stderr_sanitization_rehearsed") is True,
            "ready_event_rehearsed": authorization.get("ready_event_rehearsed") is True,
            "rollback_rehearsed": authorization.get("rollback_rehearsed") is True,
            "policy_revoke_supported": authorization.get("policy_revoke_supported") is True,
            "single_start_per_receipt_required": True,
            "dry_run_rehearsal_only": authorization.get("dry_run_rehearsal_only") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_execution_rehearsal_contract",
            "enable_guarded_executor_from_execution_rehearsal_contract",
            "skip_observability_contract_from_execution_rehearsal_contract",
            "call_provider_from_guarded_start_execution_rehearsal_contract",
            "persist_raw_audio_from_guarded_start_execution_rehearsal_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_EXECUTION_REHEARSAL_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_observability_contract"
            if ready_for_guarded_start_observability_contract
            else "fix_guarded_start_execution_rehearsal_prerequisites"
        ),
    })


def inspect_guarded_start_observability_contract(
    *,
    guarded_start_execution_rehearsal_contract: Mapping[str, Any],
    observability_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Declare post-start observability requirements without starting."""

    authorization = observability_authorization or {}
    rehearsal_ready = (
        guarded_start_execution_rehearsal_contract.get("schema_version") == GUARDED_START_EXECUTION_REHEARSAL_CONTRACT_SCHEMA_VERSION
        and guarded_start_execution_rehearsal_contract.get("status") == "ready_for_guarded_start_observability_contract"
        and guarded_start_execution_rehearsal_contract.get("guarded_start_execution_rehearsal_contract_implemented") is True
        and guarded_start_execution_rehearsal_contract.get("execution_rehearsal_contract_only") is True
        and guarded_start_execution_rehearsal_contract.get("runtime_policy_start_enabled") is True
        and guarded_start_execution_rehearsal_contract.get("guarded_start_executor_enabled") is False
        and guarded_start_execution_rehearsal_contract.get("start_execution_allowed") is False
        and guarded_start_execution_rehearsal_contract.get("process_launch_attempted") is False
        and guarded_start_execution_rehearsal_contract.get("daemon_started") is False
        and guarded_start_execution_rehearsal_contract.get("subprocess_module_imported") is False
        and guarded_start_execution_rehearsal_contract.get("provider_calls_made") is False
        and guarded_start_execution_rehearsal_contract.get("tool_calls_made") is False
        and guarded_start_execution_rehearsal_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_OBSERVABILITY_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_observability_contract"
        and authorization.get("observability_contract_allowed") is True
        and authorization.get("runtime_policy_start_enabled") is True
        and authorization.get("guarded_start_executor_enabled") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("execution_rehearsal_contract_attached") is True
        and authorization.get("ready_event_required") is True
        and authorization.get("health_snapshot_required") is True
        and authorization.get("stderr_stdout_sanitized_required") is True
        and authorization.get("latency_slo_metrics_required") is True
        and authorization.get("rollback_telemetry_required") is True
        and authorization.get("evidence_sink_required") is True
        and authorization.get("policy_revoke_supported") is True
        and authorization.get("single_start_per_receipt_required") is True
        and isinstance(authorization.get("reviewed_policy_patch_hash"), str)
        and len(str(authorization.get("reviewed_policy_patch_hash"))) == 64
        and authorization.get("reviewed_policy_patch_hash")
        == guarded_start_execution_rehearsal_contract.get("reviewed_policy_patch_hash")
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_release_candidate_contract = rehearsal_ready and authorization_ready

    return validate_guarded_start_observability_contract_packet({
        "schema_version": GUARDED_START_OBSERVABILITY_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_release_candidate_contract"
            if ready_for_guarded_start_release_candidate_contract
            else "blocked"
        ),
        "implementation_status": "guarded_start_observability_contract_attached_without_process_start",
        "guarded_start_observability_contract_implemented": True,
        "observability_contract_only": True,
        "runtime_family": guarded_start_execution_rehearsal_contract.get("runtime_family") if rehearsal_ready else None,
        "reviewed_policy_patch_hash": authorization.get("reviewed_policy_patch_hash") if authorization_ready else None,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_release_candidate_contract,
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
        "guarded_start_execution_rehearsal_contract_status": guarded_start_execution_rehearsal_contract.get("status"),
        "required_observability_controls": [
            "execution_rehearsal_contract_before_observability",
            "ready_event_required_before_launch",
            "health_snapshot_required_before_launch",
            "stdout_stderr_sanitization_required_before_launch",
            "latency_slo_metrics_required_before_launch",
            "rollback_telemetry_required_before_launch",
            "evidence_sink_required_before_launch",
        ],
        "gates": {
            "guarded_start_execution_rehearsal_contract_ready": rehearsal_ready,
            "observability_authorization_ready": authorization_ready,
            "observability_contract_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_release_candidate_contract,
            "execution_rehearsal_contract_attached": authorization.get("execution_rehearsal_contract_attached") is True,
            "policy_patch_hash_matches": (
                authorization.get("reviewed_policy_patch_hash")
                == guarded_start_execution_rehearsal_contract.get("reviewed_policy_patch_hash")
            ),
            "ready_event_required": authorization.get("ready_event_required") is True,
            "health_snapshot_required": authorization.get("health_snapshot_required") is True,
            "stderr_stdout_sanitized_required": authorization.get("stderr_stdout_sanitized_required") is True,
            "latency_slo_metrics_required": authorization.get("latency_slo_metrics_required") is True,
            "rollback_telemetry_required": authorization.get("rollback_telemetry_required") is True,
            "evidence_sink_required": authorization.get("evidence_sink_required") is True,
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
            "start_process_from_guarded_start_observability_contract",
            "enable_guarded_executor_from_observability_contract",
            "skip_release_candidate_contract_from_observability_contract",
            "call_provider_from_guarded_start_observability_contract",
            "persist_raw_audio_from_guarded_start_observability_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_OBSERVABILITY_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_release_candidate_contract"
            if ready_for_guarded_start_release_candidate_contract
            else "fix_guarded_start_observability_prerequisites"
        ),
    })


def inspect_guarded_start_release_candidate_contract(
    *,
    guarded_start_observability_contract: Mapping[str, Any],
    release_candidate_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Freeze the release-candidate packet without enabling start."""

    authorization = release_candidate_authorization or {}
    observability_ready = (
        guarded_start_observability_contract.get("schema_version") == GUARDED_START_OBSERVABILITY_CONTRACT_SCHEMA_VERSION
        and guarded_start_observability_contract.get("status") == "ready_for_guarded_start_release_candidate_contract"
        and guarded_start_observability_contract.get("guarded_start_observability_contract_implemented") is True
        and guarded_start_observability_contract.get("observability_contract_only") is True
        and guarded_start_observability_contract.get("runtime_policy_start_enabled") is True
        and guarded_start_observability_contract.get("guarded_start_executor_enabled") is False
        and guarded_start_observability_contract.get("start_execution_allowed") is False
        and guarded_start_observability_contract.get("process_launch_attempted") is False
        and guarded_start_observability_contract.get("daemon_started") is False
        and guarded_start_observability_contract.get("subprocess_module_imported") is False
        and guarded_start_observability_contract.get("provider_calls_made") is False
        and guarded_start_observability_contract.get("tool_calls_made") is False
        and guarded_start_observability_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_RELEASE_CANDIDATE_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_release_candidate_contract"
        and authorization.get("release_candidate_contract_allowed") is True
        and authorization.get("runtime_policy_start_enabled") is True
        and authorization.get("guarded_start_executor_enabled") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("observability_contract_attached") is True
        and authorization.get("bundle_hash_attached") is True
        and authorization.get("evidence_manifest_attached") is True
        and authorization.get("rollback_plan_attached") is True
        and authorization.get("operator_review_required") is True
        and authorization.get("final_start_receipt_required") is True
        and authorization.get("policy_revoke_supported") is True
        and authorization.get("single_start_per_receipt_required") is True
        and isinstance(authorization.get("release_candidate_bundle_hash"), str)
        and len(str(authorization.get("release_candidate_bundle_hash"))) == 64
        and isinstance(authorization.get("reviewed_policy_patch_hash"), str)
        and len(str(authorization.get("reviewed_policy_patch_hash"))) == 64
        and authorization.get("reviewed_policy_patch_hash")
        == guarded_start_observability_contract.get("reviewed_policy_patch_hash")
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_operator_acceptance_contract = observability_ready and authorization_ready

    return validate_guarded_start_release_candidate_contract_packet({
        "schema_version": GUARDED_START_RELEASE_CANDIDATE_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_operator_acceptance_contract"
            if ready_for_guarded_start_operator_acceptance_contract
            else "blocked"
        ),
        "implementation_status": "guarded_start_release_candidate_contract_attached_without_process_start",
        "guarded_start_release_candidate_contract_implemented": True,
        "release_candidate_contract_only": True,
        "runtime_family": guarded_start_observability_contract.get("runtime_family") if observability_ready else None,
        "reviewed_policy_patch_hash": authorization.get("reviewed_policy_patch_hash") if authorization_ready else None,
        "release_candidate_bundle_hash": authorization.get("release_candidate_bundle_hash") if authorization_ready else None,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_operator_acceptance_contract,
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
        "guarded_start_observability_contract_status": guarded_start_observability_contract.get("status"),
        "required_release_candidate_controls": [
            "observability_contract_before_release_candidate",
            "bundle_hash_before_operator_acceptance",
            "evidence_manifest_before_operator_acceptance",
            "rollback_plan_before_operator_acceptance",
            "final_start_receipt_before_start",
        ],
        "gates": {
            "guarded_start_observability_contract_ready": observability_ready,
            "release_candidate_authorization_ready": authorization_ready,
            "release_candidate_contract_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_operator_acceptance_contract,
            "observability_contract_attached": authorization.get("observability_contract_attached") is True,
            "policy_patch_hash_matches": (
                authorization.get("reviewed_policy_patch_hash")
                == guarded_start_observability_contract.get("reviewed_policy_patch_hash")
            ),
            "bundle_hash_attached": authorization.get("bundle_hash_attached") is True,
            "evidence_manifest_attached": authorization.get("evidence_manifest_attached") is True,
            "rollback_plan_attached": authorization.get("rollback_plan_attached") is True,
            "operator_review_required": authorization.get("operator_review_required") is True,
            "final_start_receipt_required": authorization.get("final_start_receipt_required") is True,
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
            "start_process_from_guarded_start_release_candidate_contract",
            "enable_guarded_executor_from_release_candidate_contract",
            "skip_operator_acceptance_contract_from_release_candidate_contract",
            "call_provider_from_guarded_start_release_candidate_contract",
            "persist_raw_audio_from_guarded_start_release_candidate_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_RELEASE_CANDIDATE_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_operator_acceptance_contract"
            if ready_for_guarded_start_operator_acceptance_contract
            else "fix_guarded_start_release_candidate_prerequisites"
        ),
    })


def inspect_guarded_start_operator_acceptance_contract(
    *,
    guarded_start_release_candidate_contract: Mapping[str, Any],
    operator_acceptance_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Attach explicit operator acceptance without enabling the start adapter."""

    authorization = operator_acceptance_authorization or {}
    release_candidate_ready = (
        guarded_start_release_candidate_contract.get("schema_version") == GUARDED_START_RELEASE_CANDIDATE_CONTRACT_SCHEMA_VERSION
        and guarded_start_release_candidate_contract.get("status") == "ready_for_guarded_start_operator_acceptance_contract"
        and guarded_start_release_candidate_contract.get("guarded_start_release_candidate_contract_implemented") is True
        and guarded_start_release_candidate_contract.get("release_candidate_contract_only") is True
        and guarded_start_release_candidate_contract.get("runtime_policy_start_enabled") is True
        and guarded_start_release_candidate_contract.get("guarded_start_executor_enabled") is False
        and guarded_start_release_candidate_contract.get("start_execution_allowed") is False
        and guarded_start_release_candidate_contract.get("process_launch_attempted") is False
        and guarded_start_release_candidate_contract.get("daemon_started") is False
        and guarded_start_release_candidate_contract.get("subprocess_module_imported") is False
        and guarded_start_release_candidate_contract.get("provider_calls_made") is False
        and guarded_start_release_candidate_contract.get("tool_calls_made") is False
        and guarded_start_release_candidate_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_OPERATOR_ACCEPTANCE_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_operator_acceptance_contract"
        and authorization.get("operator_acceptance_contract_allowed") is True
        and authorization.get("runtime_policy_start_enabled") is True
        and authorization.get("guarded_start_executor_enabled") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("operator_review_completed") is True
        and authorization.get("operator_acceptance_explicit") is True
        and authorization.get("release_candidate_contract_attached") is True
        and authorization.get("evidence_manifest_reviewed") is True
        and authorization.get("rollback_plan_reviewed") is True
        and authorization.get("final_start_receipt_required") is True
        and authorization.get("policy_revoke_supported") is True
        and authorization.get("single_start_per_receipt_required") is True
        and isinstance(authorization.get("release_candidate_bundle_hash"), str)
        and authorization.get("release_candidate_bundle_hash")
        == guarded_start_release_candidate_contract.get("release_candidate_bundle_hash")
        and isinstance(authorization.get("reviewed_policy_patch_hash"), str)
        and authorization.get("reviewed_policy_patch_hash")
        == guarded_start_release_candidate_contract.get("reviewed_policy_patch_hash")
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
        and isinstance(authorization.get("operator_acceptance_receipt_id"), str)
        and authorization.get("operator_acceptance_receipt_id") != ""
    )
    ready_for_guarded_start_final_start_receipt_contract = release_candidate_ready and authorization_ready

    return validate_guarded_start_operator_acceptance_contract_packet({
        "schema_version": GUARDED_START_OPERATOR_ACCEPTANCE_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_final_start_receipt_contract"
            if ready_for_guarded_start_final_start_receipt_contract
            else "blocked"
        ),
        "implementation_status": "guarded_start_operator_acceptance_contract_attached_without_process_start",
        "guarded_start_operator_acceptance_contract_implemented": True,
        "operator_acceptance_contract_only": True,
        "runtime_family": guarded_start_release_candidate_contract.get("runtime_family") if release_candidate_ready else None,
        "reviewed_policy_patch_hash": authorization.get("reviewed_policy_patch_hash") if authorization_ready else None,
        "release_candidate_bundle_hash": authorization.get("release_candidate_bundle_hash") if authorization_ready else None,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_final_start_receipt_contract,
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
        "operator_acceptance_receipt_id": authorization.get("operator_acceptance_receipt_id") if authorization_ready else None,
        "guarded_start_release_candidate_contract_status": guarded_start_release_candidate_contract.get("status"),
        "required_operator_acceptance_controls": [
            "release_candidate_contract_before_operator_acceptance",
            "explicit_operator_acceptance_before_final_receipt",
            "evidence_manifest_review_before_final_receipt",
            "rollback_plan_review_before_final_receipt",
            "final_start_receipt_before_start",
        ],
        "gates": {
            "guarded_start_release_candidate_contract_ready": release_candidate_ready,
            "operator_acceptance_authorization_ready": authorization_ready,
            "operator_acceptance_contract_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_final_start_receipt_contract,
            "operator_review_completed": authorization.get("operator_review_completed") is True,
            "operator_acceptance_explicit": authorization.get("operator_acceptance_explicit") is True,
            "release_candidate_contract_attached": authorization.get("release_candidate_contract_attached") is True,
            "evidence_manifest_reviewed": authorization.get("evidence_manifest_reviewed") is True,
            "rollback_plan_reviewed": authorization.get("rollback_plan_reviewed") is True,
            "bundle_hash_matches": (
                authorization.get("release_candidate_bundle_hash")
                == guarded_start_release_candidate_contract.get("release_candidate_bundle_hash")
            ),
            "policy_patch_hash_matches": (
                authorization.get("reviewed_policy_patch_hash")
                == guarded_start_release_candidate_contract.get("reviewed_policy_patch_hash")
            ),
            "final_start_receipt_required": authorization.get("final_start_receipt_required") is True,
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
            "start_process_from_guarded_start_operator_acceptance_contract",
            "enable_guarded_executor_from_operator_acceptance_contract",
            "skip_final_start_receipt_contract_from_operator_acceptance_contract",
            "call_provider_from_guarded_start_operator_acceptance_contract",
            "persist_raw_audio_from_guarded_start_operator_acceptance_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_OPERATOR_ACCEPTANCE_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_final_start_receipt_contract"
            if ready_for_guarded_start_final_start_receipt_contract
            else "fix_guarded_start_operator_acceptance_prerequisites"
        ),
    })


def inspect_guarded_start_final_start_receipt_contract(
    *,
    guarded_start_operator_acceptance_contract: Mapping[str, Any],
    final_start_receipt_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Bind the final start receipt without executing the start."""

    authorization = final_start_receipt_authorization or {}
    operator_acceptance_ready = (
        guarded_start_operator_acceptance_contract.get("schema_version") == GUARDED_START_OPERATOR_ACCEPTANCE_CONTRACT_SCHEMA_VERSION
        and guarded_start_operator_acceptance_contract.get("status") == "ready_for_guarded_start_final_start_receipt_contract"
        and guarded_start_operator_acceptance_contract.get("guarded_start_operator_acceptance_contract_implemented") is True
        and guarded_start_operator_acceptance_contract.get("operator_acceptance_contract_only") is True
        and guarded_start_operator_acceptance_contract.get("runtime_policy_start_enabled") is True
        and guarded_start_operator_acceptance_contract.get("guarded_start_executor_enabled") is False
        and guarded_start_operator_acceptance_contract.get("start_execution_allowed") is False
        and guarded_start_operator_acceptance_contract.get("process_launch_attempted") is False
        and guarded_start_operator_acceptance_contract.get("daemon_started") is False
        and guarded_start_operator_acceptance_contract.get("subprocess_module_imported") is False
        and guarded_start_operator_acceptance_contract.get("provider_calls_made") is False
        and guarded_start_operator_acceptance_contract.get("tool_calls_made") is False
        and guarded_start_operator_acceptance_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_FINAL_START_RECEIPT_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_final_start_receipt_contract"
        and authorization.get("final_start_receipt_contract_allowed") is True
        and authorization.get("runtime_policy_start_enabled") is True
        and authorization.get("guarded_start_executor_enabled") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("operator_acceptance_contract_attached") is True
        and authorization.get("final_start_receipt_attached") is True
        and authorization.get("receipt_fresh") is True
        and authorization.get("single_start_per_receipt_required") is True
        and authorization.get("ready_event_required") is True
        and authorization.get("rollback_plan_reviewed") is True
        and authorization.get("policy_revoke_supported") is True
        and isinstance(authorization.get("release_candidate_bundle_hash"), str)
        and authorization.get("release_candidate_bundle_hash")
        == guarded_start_operator_acceptance_contract.get("release_candidate_bundle_hash")
        and isinstance(authorization.get("reviewed_policy_patch_hash"), str)
        and authorization.get("reviewed_policy_patch_hash")
        == guarded_start_operator_acceptance_contract.get("reviewed_policy_patch_hash")
        and isinstance(authorization.get("operator_acceptance_receipt_id"), str)
        and authorization.get("operator_acceptance_receipt_id")
        == guarded_start_operator_acceptance_contract.get("operator_acceptance_receipt_id")
        and isinstance(authorization.get("final_start_receipt_id"), str)
        and authorization.get("final_start_receipt_id") != ""
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_launch_window_contract = operator_acceptance_ready and authorization_ready

    return validate_guarded_start_final_start_receipt_contract_packet({
        "schema_version": GUARDED_START_FINAL_START_RECEIPT_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_launch_window_contract"
            if ready_for_guarded_start_launch_window_contract
            else "blocked"
        ),
        "implementation_status": "guarded_start_final_start_receipt_contract_attached_without_process_start",
        "guarded_start_final_start_receipt_contract_implemented": True,
        "final_start_receipt_contract_only": True,
        "runtime_family": guarded_start_operator_acceptance_contract.get("runtime_family") if operator_acceptance_ready else None,
        "reviewed_policy_patch_hash": authorization.get("reviewed_policy_patch_hash") if authorization_ready else None,
        "release_candidate_bundle_hash": authorization.get("release_candidate_bundle_hash") if authorization_ready else None,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_launch_window_contract,
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
        "operator_acceptance_receipt_id": authorization.get("operator_acceptance_receipt_id") if authorization_ready else None,
        "final_start_receipt_id": authorization.get("final_start_receipt_id") if authorization_ready else None,
        "guarded_start_operator_acceptance_contract_status": guarded_start_operator_acceptance_contract.get("status"),
        "required_final_start_receipt_controls": [
            "operator_acceptance_contract_before_final_start_receipt",
            "fresh_final_start_receipt_before_launch_window",
            "single_start_per_receipt_before_launch_window",
            "ready_event_required_before_launch_window",
            "rollback_plan_review_before_launch_window",
        ],
        "gates": {
            "guarded_start_operator_acceptance_contract_ready": operator_acceptance_ready,
            "final_start_receipt_authorization_ready": authorization_ready,
            "final_start_receipt_contract_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_launch_window_contract,
            "operator_acceptance_contract_attached": authorization.get("operator_acceptance_contract_attached") is True,
            "final_start_receipt_attached": authorization.get("final_start_receipt_attached") is True,
            "receipt_fresh": authorization.get("receipt_fresh") is True,
            "single_start_per_receipt_required": True,
            "ready_event_required": authorization.get("ready_event_required") is True,
            "rollback_plan_reviewed": authorization.get("rollback_plan_reviewed") is True,
            "policy_revoke_supported": authorization.get("policy_revoke_supported") is True,
            "bundle_hash_matches": (
                authorization.get("release_candidate_bundle_hash")
                == guarded_start_operator_acceptance_contract.get("release_candidate_bundle_hash")
            ),
            "policy_patch_hash_matches": (
                authorization.get("reviewed_policy_patch_hash")
                == guarded_start_operator_acceptance_contract.get("reviewed_policy_patch_hash")
            ),
            "operator_acceptance_receipt_matches": (
                authorization.get("operator_acceptance_receipt_id")
                == guarded_start_operator_acceptance_contract.get("operator_acceptance_receipt_id")
            ),
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_final_start_receipt_contract",
            "enable_guarded_executor_from_final_start_receipt_contract",
            "skip_launch_window_contract_from_final_start_receipt_contract",
            "call_provider_from_guarded_start_final_start_receipt_contract",
            "persist_raw_audio_from_guarded_start_final_start_receipt_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_FINAL_START_RECEIPT_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_launch_window_contract"
            if ready_for_guarded_start_launch_window_contract
            else "fix_guarded_start_final_start_receipt_prerequisites"
        ),
    })


def inspect_guarded_start_launch_window_contract(
    *,
    guarded_start_final_start_receipt_contract: Mapping[str, Any],
    launch_window_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Declare the bounded launch window without opening the start adapter."""

    authorization = launch_window_authorization or {}
    final_receipt_ready = (
        guarded_start_final_start_receipt_contract.get("schema_version") == GUARDED_START_FINAL_START_RECEIPT_CONTRACT_SCHEMA_VERSION
        and guarded_start_final_start_receipt_contract.get("status") == "ready_for_guarded_start_launch_window_contract"
        and guarded_start_final_start_receipt_contract.get("guarded_start_final_start_receipt_contract_implemented") is True
        and guarded_start_final_start_receipt_contract.get("final_start_receipt_contract_only") is True
        and guarded_start_final_start_receipt_contract.get("runtime_policy_start_enabled") is True
        and guarded_start_final_start_receipt_contract.get("guarded_start_executor_enabled") is False
        and guarded_start_final_start_receipt_contract.get("start_execution_allowed") is False
        and guarded_start_final_start_receipt_contract.get("process_launch_attempted") is False
        and guarded_start_final_start_receipt_contract.get("daemon_started") is False
        and guarded_start_final_start_receipt_contract.get("subprocess_module_imported") is False
        and guarded_start_final_start_receipt_contract.get("provider_calls_made") is False
        and guarded_start_final_start_receipt_contract.get("tool_calls_made") is False
        and guarded_start_final_start_receipt_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_LAUNCH_WINDOW_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_launch_window_contract"
        and authorization.get("launch_window_contract_allowed") is True
        and authorization.get("runtime_policy_start_enabled") is True
        and authorization.get("guarded_start_executor_enabled") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("final_start_receipt_contract_attached") is True
        and authorization.get("launch_window_declared") is True
        and authorization.get("operator_present") is True
        and authorization.get("receipts_fresh") is True
        and authorization.get("single_start_per_receipt_required") is True
        and authorization.get("observability_armed") is True
        and authorization.get("rollback_armed") is True
        and authorization.get("policy_revoke_supported") is True
        and isinstance(authorization.get("release_candidate_bundle_hash"), str)
        and authorization.get("release_candidate_bundle_hash")
        == guarded_start_final_start_receipt_contract.get("release_candidate_bundle_hash")
        and isinstance(authorization.get("reviewed_policy_patch_hash"), str)
        and authorization.get("reviewed_policy_patch_hash")
        == guarded_start_final_start_receipt_contract.get("reviewed_policy_patch_hash")
        and isinstance(authorization.get("final_start_receipt_id"), str)
        and authorization.get("final_start_receipt_id")
        == guarded_start_final_start_receipt_contract.get("final_start_receipt_id")
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_pre_launch_guard_contract = final_receipt_ready and authorization_ready

    return validate_guarded_start_launch_window_contract_packet({
        "schema_version": GUARDED_START_LAUNCH_WINDOW_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_pre_launch_guard_contract"
            if ready_for_guarded_start_pre_launch_guard_contract
            else "blocked"
        ),
        "implementation_status": "guarded_start_launch_window_contract_attached_without_process_start",
        "guarded_start_launch_window_contract_implemented": True,
        "launch_window_contract_only": True,
        "runtime_family": guarded_start_final_start_receipt_contract.get("runtime_family") if final_receipt_ready else None,
        "reviewed_policy_patch_hash": authorization.get("reviewed_policy_patch_hash") if authorization_ready else None,
        "release_candidate_bundle_hash": authorization.get("release_candidate_bundle_hash") if authorization_ready else None,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_pre_launch_guard_contract,
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
        "final_start_receipt_id": authorization.get("final_start_receipt_id") if authorization_ready else None,
        "guarded_start_final_start_receipt_contract_status": guarded_start_final_start_receipt_contract.get("status"),
        "required_launch_window_controls": [
            "final_start_receipt_contract_before_launch_window",
            "declared_launch_window_before_pre_launch_guard",
            "operator_presence_before_pre_launch_guard",
            "fresh_receipts_before_pre_launch_guard",
            "observability_armed_before_pre_launch_guard",
            "rollback_armed_before_pre_launch_guard",
        ],
        "gates": {
            "guarded_start_final_start_receipt_contract_ready": final_receipt_ready,
            "launch_window_authorization_ready": authorization_ready,
            "launch_window_contract_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_pre_launch_guard_contract,
            "final_start_receipt_contract_attached": authorization.get("final_start_receipt_contract_attached") is True,
            "launch_window_declared": authorization.get("launch_window_declared") is True,
            "operator_present": authorization.get("operator_present") is True,
            "receipts_fresh": authorization.get("receipts_fresh") is True,
            "single_start_per_receipt_required": True,
            "observability_armed": authorization.get("observability_armed") is True,
            "rollback_armed": authorization.get("rollback_armed") is True,
            "policy_revoke_supported": authorization.get("policy_revoke_supported") is True,
            "bundle_hash_matches": (
                authorization.get("release_candidate_bundle_hash")
                == guarded_start_final_start_receipt_contract.get("release_candidate_bundle_hash")
            ),
            "policy_patch_hash_matches": (
                authorization.get("reviewed_policy_patch_hash")
                == guarded_start_final_start_receipt_contract.get("reviewed_policy_patch_hash")
            ),
            "final_start_receipt_matches": (
                authorization.get("final_start_receipt_id")
                == guarded_start_final_start_receipt_contract.get("final_start_receipt_id")
            ),
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_launch_window_contract",
            "enable_guarded_executor_from_launch_window_contract",
            "skip_pre_launch_guard_contract_from_launch_window_contract",
            "call_provider_from_guarded_start_launch_window_contract",
            "persist_raw_audio_from_guarded_start_launch_window_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_LAUNCH_WINDOW_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_pre_launch_guard_contract"
            if ready_for_guarded_start_pre_launch_guard_contract
            else "fix_guarded_start_launch_window_prerequisites"
        ),
    })


def inspect_guarded_start_pre_launch_guard_contract(
    *,
    guarded_start_launch_window_contract: Mapping[str, Any],
    pre_launch_guard_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Evaluate the final pre-launch guard without opening the adapter."""

    authorization = pre_launch_guard_authorization or {}
    launch_window_ready = (
        guarded_start_launch_window_contract.get("schema_version") == GUARDED_START_LAUNCH_WINDOW_CONTRACT_SCHEMA_VERSION
        and guarded_start_launch_window_contract.get("status") == "ready_for_guarded_start_pre_launch_guard_contract"
        and guarded_start_launch_window_contract.get("guarded_start_launch_window_contract_implemented") is True
        and guarded_start_launch_window_contract.get("launch_window_contract_only") is True
        and guarded_start_launch_window_contract.get("runtime_policy_start_enabled") is True
        and guarded_start_launch_window_contract.get("guarded_start_executor_enabled") is False
        and guarded_start_launch_window_contract.get("start_execution_allowed") is False
        and guarded_start_launch_window_contract.get("process_launch_attempted") is False
        and guarded_start_launch_window_contract.get("daemon_started") is False
        and guarded_start_launch_window_contract.get("subprocess_module_imported") is False
        and guarded_start_launch_window_contract.get("provider_calls_made") is False
        and guarded_start_launch_window_contract.get("tool_calls_made") is False
        and guarded_start_launch_window_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_PRE_LAUNCH_GUARD_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_pre_launch_guard_contract"
        and authorization.get("pre_launch_guard_contract_allowed") is True
        and authorization.get("runtime_policy_start_enabled") is True
        and authorization.get("guarded_start_executor_enabled") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("launch_window_contract_attached") is True
        and authorization.get("kernel_health_fresh") is True
        and authorization.get("token_lease_fresh") is True
        and authorization.get("callback_router_fresh") is True
        and authorization.get("observability_armed") is True
        and authorization.get("rollback_armed") is True
        and authorization.get("policy_revoke_supported") is True
        and authorization.get("operator_present") is True
        and isinstance(authorization.get("final_start_receipt_id"), str)
        and authorization.get("final_start_receipt_id")
        == guarded_start_launch_window_contract.get("final_start_receipt_id")
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_executor_runtime_contract = launch_window_ready and authorization_ready

    return validate_guarded_start_pre_launch_guard_contract_packet({
        "schema_version": GUARDED_START_PRE_LAUNCH_GUARD_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_executor_runtime_contract"
            if ready_for_guarded_start_executor_runtime_contract
            else "blocked"
        ),
        "implementation_status": "guarded_start_pre_launch_guard_contract_attached_without_process_start",
        "guarded_start_pre_launch_guard_contract_implemented": True,
        "pre_launch_guard_contract_only": True,
        "runtime_family": guarded_start_launch_window_contract.get("runtime_family") if launch_window_ready else None,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_executor_runtime_contract,
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
        "final_start_receipt_id": authorization.get("final_start_receipt_id") if authorization_ready else None,
        "guarded_start_launch_window_contract_status": guarded_start_launch_window_contract.get("status"),
        "required_pre_launch_guard_controls": [
            "launch_window_contract_before_pre_launch_guard",
            "fresh_kernel_health_before_executor_runtime",
            "fresh_token_lease_before_executor_runtime",
            "fresh_callback_router_before_executor_runtime",
            "observability_and_rollback_armed_before_executor_runtime",
        ],
        "gates": {
            "guarded_start_launch_window_contract_ready": launch_window_ready,
            "pre_launch_guard_authorization_ready": authorization_ready,
            "pre_launch_guard_contract_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_executor_runtime_contract,
            "launch_window_contract_attached": authorization.get("launch_window_contract_attached") is True,
            "kernel_health_fresh": authorization.get("kernel_health_fresh") is True,
            "token_lease_fresh": authorization.get("token_lease_fresh") is True,
            "callback_router_fresh": authorization.get("callback_router_fresh") is True,
            "observability_armed": authorization.get("observability_armed") is True,
            "rollback_armed": authorization.get("rollback_armed") is True,
            "policy_revoke_supported": authorization.get("policy_revoke_supported") is True,
            "operator_present": authorization.get("operator_present") is True,
            "final_start_receipt_matches": (
                authorization.get("final_start_receipt_id")
                == guarded_start_launch_window_contract.get("final_start_receipt_id")
            ),
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_pre_launch_guard_contract",
            "enable_guarded_executor_from_pre_launch_guard_contract",
            "skip_executor_runtime_contract_from_pre_launch_guard_contract",
            "call_provider_from_guarded_start_pre_launch_guard_contract",
            "persist_raw_audio_from_guarded_start_pre_launch_guard_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_PRE_LAUNCH_GUARD_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_executor_runtime_contract"
            if ready_for_guarded_start_executor_runtime_contract
            else "fix_guarded_start_pre_launch_guard_prerequisites"
        ),
    })


def inspect_guarded_start_executor_runtime_contract(
    *,
    guarded_start_pre_launch_guard_contract: Mapping[str, Any],
    executor_runtime_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Declare the guarded executor runtime without spawning a process."""

    authorization = executor_runtime_authorization or {}
    pre_launch_ready = (
        guarded_start_pre_launch_guard_contract.get("schema_version") == GUARDED_START_PRE_LAUNCH_GUARD_CONTRACT_SCHEMA_VERSION
        and guarded_start_pre_launch_guard_contract.get("status") == "ready_for_guarded_start_executor_runtime_contract"
        and guarded_start_pre_launch_guard_contract.get("guarded_start_pre_launch_guard_contract_implemented") is True
        and guarded_start_pre_launch_guard_contract.get("pre_launch_guard_contract_only") is True
        and guarded_start_pre_launch_guard_contract.get("runtime_policy_start_enabled") is True
        and guarded_start_pre_launch_guard_contract.get("guarded_start_executor_enabled") is False
        and guarded_start_pre_launch_guard_contract.get("start_execution_allowed") is False
        and guarded_start_pre_launch_guard_contract.get("process_launch_attempted") is False
        and guarded_start_pre_launch_guard_contract.get("daemon_started") is False
        and guarded_start_pre_launch_guard_contract.get("subprocess_module_imported") is False
        and guarded_start_pre_launch_guard_contract.get("provider_calls_made") is False
        and guarded_start_pre_launch_guard_contract.get("tool_calls_made") is False
        and guarded_start_pre_launch_guard_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_EXECUTOR_RUNTIME_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_executor_runtime_contract"
        and authorization.get("executor_runtime_contract_allowed") is True
        and authorization.get("runtime_policy_start_enabled") is True
        and authorization.get("guarded_start_executor_enabled") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and authorization.get("pre_launch_guard_contract_attached") is True
        and authorization.get("runtime_family") == "python_ai_data"
        and authorization.get("env_contract_attached") is True
        and authorization.get("argv_redacted") is True
        and authorization.get("pid_guard_configured") is True
        and authorization.get("stdout_stderr_sanitized") is True
        and authorization.get("ready_event_required") is True
        and authorization.get("rollback_armed") is True
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_process_spawn_contract = pre_launch_ready and authorization_ready

    return validate_guarded_start_executor_runtime_contract_packet({
        "schema_version": GUARDED_START_EXECUTOR_RUNTIME_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_process_spawn_contract"
            if ready_for_guarded_start_process_spawn_contract
            else "blocked"
        ),
        "implementation_status": "guarded_start_executor_runtime_contract_attached_without_process_start",
        "guarded_start_executor_runtime_contract_implemented": True,
        "executor_runtime_contract_only": True,
        "runtime_family": authorization.get("runtime_family") if authorization_ready else None,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_process_spawn_contract,
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
        "guarded_start_pre_launch_guard_contract_status": guarded_start_pre_launch_guard_contract.get("status"),
        "required_executor_runtime_controls": [
            "pre_launch_guard_before_executor_runtime",
            "python_ai_data_runtime_before_process_spawn",
            "managed_env_contract_before_process_spawn",
            "redacted_argv_before_process_spawn",
            "pid_guard_and_sanitized_streams_before_process_spawn",
        ],
        "gates": {
            "guarded_start_pre_launch_guard_contract_ready": pre_launch_ready,
            "executor_runtime_authorization_ready": authorization_ready,
            "executor_runtime_contract_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_process_spawn_contract,
            "pre_launch_guard_contract_attached": authorization.get("pre_launch_guard_contract_attached") is True,
            "runtime_family_allowed": authorization.get("runtime_family") == "python_ai_data",
            "env_contract_attached": authorization.get("env_contract_attached") is True,
            "argv_redacted": authorization.get("argv_redacted") is True,
            "pid_guard_configured": authorization.get("pid_guard_configured") is True,
            "stdout_stderr_sanitized": authorization.get("stdout_stderr_sanitized") is True,
            "ready_event_required": authorization.get("ready_event_required") is True,
            "rollback_armed": authorization.get("rollback_armed") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_executor_runtime_contract",
            "enable_guarded_executor_from_executor_runtime_contract",
            "skip_process_spawn_contract_from_executor_runtime_contract",
            "call_provider_from_guarded_start_executor_runtime_contract",
            "persist_raw_audio_from_guarded_start_executor_runtime_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_EXECUTOR_RUNTIME_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_process_spawn_contract"
            if ready_for_guarded_start_process_spawn_contract
            else "fix_guarded_start_executor_runtime_prerequisites"
        ),
    })


def inspect_guarded_start_process_spawn_contract(
    *,
    guarded_start_executor_runtime_contract: Mapping[str, Any],
    process_spawn_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Declare the guarded process-spawn boundary without importing subprocess."""

    authorization = process_spawn_authorization or {}
    executor_runtime_ready = (
        guarded_start_executor_runtime_contract.get("schema_version") == GUARDED_START_EXECUTOR_RUNTIME_CONTRACT_SCHEMA_VERSION
        and guarded_start_executor_runtime_contract.get("status") == "ready_for_guarded_start_process_spawn_contract"
        and guarded_start_executor_runtime_contract.get("guarded_start_executor_runtime_contract_implemented") is True
        and guarded_start_executor_runtime_contract.get("executor_runtime_contract_only") is True
        and guarded_start_executor_runtime_contract.get("runtime_policy_start_enabled") is True
        and guarded_start_executor_runtime_contract.get("runtime_family") == "python_ai_data"
        and guarded_start_executor_runtime_contract.get("guarded_start_executor_enabled") is False
        and guarded_start_executor_runtime_contract.get("start_execution_allowed") is False
        and guarded_start_executor_runtime_contract.get("process_launch_attempted") is False
        and guarded_start_executor_runtime_contract.get("daemon_started") is False
        and guarded_start_executor_runtime_contract.get("subprocess_module_imported") is False
        and guarded_start_executor_runtime_contract.get("livekit_sdk_imported") is False
        and guarded_start_executor_runtime_contract.get("provider_calls_made") is False
        and guarded_start_executor_runtime_contract.get("tool_calls_made") is False
        and guarded_start_executor_runtime_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_PROCESS_SPAWN_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_process_spawn_contract"
        and authorization.get("process_spawn_contract_allowed") is True
        and authorization.get("executor_runtime_contract_attached") is True
        and authorization.get("runtime_family") == "python_ai_data"
        and authorization.get("env_contract_attached") is True
        and authorization.get("argv_redacted") is True
        and authorization.get("cwd_confined") is True
        and authorization.get("pid_guard_configured") is True
        and authorization.get("startup_timeout_configured") is True
        and authorization.get("stdout_stderr_sanitized") is True
        and authorization.get("ready_event_required") is True
        and authorization.get("rollback_armed") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_spawn_review_contract = executor_runtime_ready and authorization_ready

    return validate_guarded_start_process_spawn_contract_packet({
        "schema_version": GUARDED_START_PROCESS_SPAWN_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_spawn_review_contract"
            if ready_for_guarded_start_spawn_review_contract
            else "blocked"
        ),
        "implementation_status": "guarded_start_process_spawn_contract_declared_without_subprocess_import",
        "guarded_start_process_spawn_contract_implemented": True,
        "process_spawn_contract_only": True,
        "runtime_family": authorization.get("runtime_family") if authorization_ready else None,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_spawn_review_contract,
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
        "guarded_start_executor_runtime_contract_status": guarded_start_executor_runtime_contract.get("status"),
        "required_process_spawn_controls": [
            "executor_runtime_contract_before_process_spawn",
            "confined_cwd_before_process_spawn",
            "redacted_argv_before_process_spawn",
            "startup_timeout_before_process_spawn",
            "ready_event_before_process_spawn",
            "rollback_before_process_spawn",
        ],
        "gates": {
            "guarded_start_executor_runtime_contract_ready": executor_runtime_ready,
            "process_spawn_authorization_ready": authorization_ready,
            "process_spawn_contract_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_spawn_review_contract,
            "executor_runtime_contract_attached": authorization.get("executor_runtime_contract_attached") is True,
            "runtime_family_allowed": authorization.get("runtime_family") == "python_ai_data",
            "env_contract_attached": authorization.get("env_contract_attached") is True,
            "argv_redacted": authorization.get("argv_redacted") is True,
            "cwd_confined": authorization.get("cwd_confined") is True,
            "pid_guard_configured": authorization.get("pid_guard_configured") is True,
            "startup_timeout_configured": authorization.get("startup_timeout_configured") is True,
            "stdout_stderr_sanitized": authorization.get("stdout_stderr_sanitized") is True,
            "ready_event_required": authorization.get("ready_event_required") is True,
            "rollback_armed": authorization.get("rollback_armed") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "import_subprocess_from_guarded_start_process_spawn_contract",
            "start_process_from_guarded_start_process_spawn_contract",
            "enable_guarded_executor_from_process_spawn_contract",
            "skip_spawn_review_contract_from_process_spawn_contract",
            "call_provider_from_guarded_start_process_spawn_contract",
            "persist_raw_audio_from_guarded_start_process_spawn_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_PROCESS_SPAWN_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_spawn_review_contract"
            if ready_for_guarded_start_spawn_review_contract
            else "fix_guarded_start_process_spawn_prerequisites"
        ),
    })


def inspect_guarded_start_spawn_review_contract(
    *,
    guarded_start_process_spawn_contract: Mapping[str, Any],
    spawn_review_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Review the guarded process-spawn contract without authorizing launch."""

    authorization = spawn_review_authorization or {}
    process_spawn_ready = (
        guarded_start_process_spawn_contract.get("schema_version") == GUARDED_START_PROCESS_SPAWN_CONTRACT_SCHEMA_VERSION
        and guarded_start_process_spawn_contract.get("status") == "ready_for_guarded_start_spawn_review_contract"
        and guarded_start_process_spawn_contract.get("guarded_start_process_spawn_contract_implemented") is True
        and guarded_start_process_spawn_contract.get("process_spawn_contract_only") is True
        and guarded_start_process_spawn_contract.get("runtime_policy_start_enabled") is True
        and guarded_start_process_spawn_contract.get("runtime_family") == "python_ai_data"
        and guarded_start_process_spawn_contract.get("guarded_start_executor_enabled") is False
        and guarded_start_process_spawn_contract.get("start_execution_allowed") is False
        and guarded_start_process_spawn_contract.get("process_launch_attempted") is False
        and guarded_start_process_spawn_contract.get("daemon_started") is False
        and guarded_start_process_spawn_contract.get("subprocess_module_imported") is False
        and guarded_start_process_spawn_contract.get("livekit_sdk_imported") is False
        and guarded_start_process_spawn_contract.get("provider_calls_made") is False
        and guarded_start_process_spawn_contract.get("tool_calls_made") is False
        and guarded_start_process_spawn_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_SPAWN_REVIEW_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_spawn_review_contract"
        and authorization.get("spawn_review_contract_allowed") is True
        and authorization.get("process_spawn_contract_attached") is True
        and authorization.get("technical_review_completed") is True
        and authorization.get("bundle_hash_reviewed") is True
        and authorization.get("cwd_confined_reviewed") is True
        and authorization.get("argv_redaction_reviewed") is True
        and authorization.get("timeout_reviewed") is True
        and authorization.get("ready_event_reviewed") is True
        and authorization.get("rollback_reviewed") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("reviewed_bundle_hash"), str)
        and len(str(authorization.get("reviewed_bundle_hash"))) == 64
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_subprocess_import_contract = process_spawn_ready and authorization_ready

    return validate_guarded_start_spawn_review_contract_packet({
        "schema_version": GUARDED_START_SPAWN_REVIEW_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_subprocess_import_contract"
            if ready_for_guarded_start_subprocess_import_contract
            else "blocked"
        ),
        "implementation_status": "guarded_start_spawn_review_contract_attached_without_launch_authority",
        "guarded_start_spawn_review_contract_implemented": True,
        "spawn_review_contract_only": True,
        "reviewed_bundle_hash": authorization.get("reviewed_bundle_hash") if authorization_ready else None,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_subprocess_import_contract,
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
        "guarded_start_process_spawn_contract_status": guarded_start_process_spawn_contract.get("status"),
        "required_spawn_review_controls": [
            "process_spawn_contract_before_spawn_review",
            "technical_review_before_subprocess_import",
            "bundle_hash_review_before_subprocess_import",
            "cwd_and_argv_review_before_subprocess_import",
            "timeout_ready_event_and_rollback_review_before_subprocess_import",
        ],
        "gates": {
            "guarded_start_process_spawn_contract_ready": process_spawn_ready,
            "spawn_review_authorization_ready": authorization_ready,
            "spawn_review_contract_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_subprocess_import_contract,
            "process_spawn_contract_attached": authorization.get("process_spawn_contract_attached") is True,
            "technical_review_completed": authorization.get("technical_review_completed") is True,
            "bundle_hash_reviewed": authorization.get("bundle_hash_reviewed") is True,
            "cwd_confined_reviewed": authorization.get("cwd_confined_reviewed") is True,
            "argv_redaction_reviewed": authorization.get("argv_redaction_reviewed") is True,
            "timeout_reviewed": authorization.get("timeout_reviewed") is True,
            "ready_event_reviewed": authorization.get("ready_event_reviewed") is True,
            "rollback_reviewed": authorization.get("rollback_reviewed") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "import_subprocess_from_guarded_start_spawn_review_contract",
            "start_process_from_guarded_start_spawn_review_contract",
            "skip_subprocess_import_contract_from_spawn_review_contract",
            "call_provider_from_guarded_start_spawn_review_contract",
            "persist_raw_audio_from_guarded_start_spawn_review_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_SPAWN_REVIEW_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_subprocess_import_contract"
            if ready_for_guarded_start_subprocess_import_contract
            else "fix_guarded_start_spawn_review_prerequisites"
        ),
    })


def inspect_guarded_start_subprocess_import_contract(
    *,
    guarded_start_spawn_review_contract: Mapping[str, Any],
    subprocess_import_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Declare the guarded subprocess import boundary without importing it."""

    authorization = subprocess_import_authorization or {}
    spawn_review_ready = (
        guarded_start_spawn_review_contract.get("schema_version") == GUARDED_START_SPAWN_REVIEW_CONTRACT_SCHEMA_VERSION
        and guarded_start_spawn_review_contract.get("status") == "ready_for_guarded_start_subprocess_import_contract"
        and guarded_start_spawn_review_contract.get("guarded_start_spawn_review_contract_implemented") is True
        and guarded_start_spawn_review_contract.get("spawn_review_contract_only") is True
        and guarded_start_spawn_review_contract.get("runtime_policy_start_enabled") is True
        and guarded_start_spawn_review_contract.get("guarded_start_executor_enabled") is False
        and guarded_start_spawn_review_contract.get("start_execution_allowed") is False
        and guarded_start_spawn_review_contract.get("process_launch_attempted") is False
        and guarded_start_spawn_review_contract.get("daemon_started") is False
        and guarded_start_spawn_review_contract.get("subprocess_module_imported") is False
        and guarded_start_spawn_review_contract.get("livekit_sdk_imported") is False
        and guarded_start_spawn_review_contract.get("provider_calls_made") is False
        and guarded_start_spawn_review_contract.get("tool_calls_made") is False
        and guarded_start_spawn_review_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_SUBPROCESS_IMPORT_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_subprocess_import_contract"
        and authorization.get("subprocess_import_contract_allowed") is True
        and authorization.get("spawn_review_contract_attached") is True
        and authorization.get("localized_import_boundary_declared") is True
        and authorization.get("no_top_level_subprocess_import") is True
        and authorization.get("executor_only_import_required") is True
        and authorization.get("import_audit_event_required") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_launch_invocation_contract = spawn_review_ready and authorization_ready

    return validate_guarded_start_subprocess_import_contract_packet({
        "schema_version": GUARDED_START_SUBPROCESS_IMPORT_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_launch_invocation_contract"
            if ready_for_guarded_start_launch_invocation_contract
            else "blocked"
        ),
        "implementation_status": "guarded_start_subprocess_import_contract_declared_without_import",
        "guarded_start_subprocess_import_contract_implemented": True,
        "subprocess_import_contract_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_launch_invocation_contract,
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
        "guarded_start_spawn_review_contract_status": guarded_start_spawn_review_contract.get("status"),
        "required_subprocess_import_controls": [
            "spawn_review_contract_before_subprocess_import_contract",
            "localized_import_boundary_before_subprocess_import",
            "no_top_level_subprocess_import_before_subprocess_import",
            "executor_only_import_before_launch_invocation",
            "import_audit_event_before_launch_invocation_contract",
        ],
        "gates": {
            "guarded_start_spawn_review_contract_ready": spawn_review_ready,
            "subprocess_import_authorization_ready": authorization_ready,
            "subprocess_import_contract_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_launch_invocation_contract,
            "spawn_review_contract_attached": authorization.get("spawn_review_contract_attached") is True,
            "localized_import_boundary_declared": authorization.get("localized_import_boundary_declared") is True,
            "no_top_level_subprocess_import": authorization.get("no_top_level_subprocess_import") is True,
            "executor_only_import_required": authorization.get("executor_only_import_required") is True,
            "import_audit_event_required": authorization.get("import_audit_event_required") is True,
            "process_launch_disabled": True,
            "start_execution_disabled": True,
            "subprocess_import_not_executed": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "import_subprocess_from_guarded_start_subprocess_import_contract",
            "start_process_from_guarded_start_subprocess_import_contract",
            "skip_launch_invocation_contract_from_subprocess_import_contract",
            "call_provider_from_guarded_start_subprocess_import_contract",
            "persist_raw_audio_from_guarded_start_subprocess_import_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_SUBPROCESS_IMPORT_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_launch_invocation_contract"
            if ready_for_guarded_start_launch_invocation_contract
            else "fix_guarded_start_subprocess_import_prerequisites"
        ),
    })


def inspect_guarded_start_launch_invocation_contract(
    *,
    guarded_start_subprocess_import_contract: Mapping[str, Any],
    launch_invocation_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Declare the guarded launch invocation boundary without launching."""

    authorization = launch_invocation_authorization or {}
    subprocess_import_ready = (
        guarded_start_subprocess_import_contract.get("schema_version") == GUARDED_START_SUBPROCESS_IMPORT_CONTRACT_SCHEMA_VERSION
        and guarded_start_subprocess_import_contract.get("status") == "ready_for_guarded_start_launch_invocation_contract"
        and guarded_start_subprocess_import_contract.get("guarded_start_subprocess_import_contract_implemented") is True
        and guarded_start_subprocess_import_contract.get("subprocess_import_contract_only") is True
        and guarded_start_subprocess_import_contract.get("runtime_policy_start_enabled") is True
        and guarded_start_subprocess_import_contract.get("guarded_start_executor_enabled") is False
        and guarded_start_subprocess_import_contract.get("start_execution_allowed") is False
        and guarded_start_subprocess_import_contract.get("process_launch_attempted") is False
        and guarded_start_subprocess_import_contract.get("daemon_started") is False
        and guarded_start_subprocess_import_contract.get("subprocess_module_imported") is False
        and guarded_start_subprocess_import_contract.get("livekit_sdk_imported") is False
        and guarded_start_subprocess_import_contract.get("provider_calls_made") is False
        and guarded_start_subprocess_import_contract.get("tool_calls_made") is False
        and guarded_start_subprocess_import_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_LAUNCH_INVOCATION_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_launch_invocation_contract"
        and authorization.get("launch_invocation_contract_allowed") is True
        and authorization.get("subprocess_import_contract_attached") is True
        and authorization.get("command_template_reviewed") is True
        and authorization.get("argv_redacted") is True
        and authorization.get("env_redacted") is True
        and authorization.get("cwd_confined") is True
        and authorization.get("pid_guard_required") is True
        and authorization.get("startup_timeout_required") is True
        and authorization.get("ready_event_required") is True
        and authorization.get("rollback_required") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_final_process_start_contract = subprocess_import_ready and authorization_ready

    return validate_guarded_start_launch_invocation_contract_packet({
        "schema_version": GUARDED_START_LAUNCH_INVOCATION_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_final_process_start_contract"
            if ready_for_guarded_start_final_process_start_contract
            else "blocked"
        ),
        "implementation_status": "guarded_start_launch_invocation_contract_declared_without_launch",
        "guarded_start_launch_invocation_contract_implemented": True,
        "launch_invocation_contract_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_final_process_start_contract,
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
        "guarded_start_subprocess_import_contract_status": guarded_start_subprocess_import_contract.get("status"),
        "required_launch_invocation_controls": [
            "subprocess_import_contract_before_launch_invocation",
            "command_template_review_before_launch_invocation",
            "argv_env_redaction_before_launch_invocation",
            "cwd_confinement_before_launch_invocation",
            "pid_timeout_ready_rollback_before_launch_invocation",
        ],
        "gates": {
            "guarded_start_subprocess_import_contract_ready": subprocess_import_ready,
            "launch_invocation_authorization_ready": authorization_ready,
            "launch_invocation_contract_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_final_process_start_contract,
            "subprocess_import_contract_attached": authorization.get("subprocess_import_contract_attached") is True,
            "command_template_reviewed": authorization.get("command_template_reviewed") is True,
            "argv_redacted": authorization.get("argv_redacted") is True,
            "env_redacted": authorization.get("env_redacted") is True,
            "cwd_confined": authorization.get("cwd_confined") is True,
            "pid_guard_required": authorization.get("pid_guard_required") is True,
            "startup_timeout_required": authorization.get("startup_timeout_required") is True,
            "ready_event_required": authorization.get("ready_event_required") is True,
            "rollback_required": authorization.get("rollback_required") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_launch_invocation_contract",
            "import_subprocess_from_launch_invocation_contract",
            "skip_final_process_start_contract_from_launch_invocation_contract",
            "call_provider_from_guarded_start_launch_invocation_contract",
            "persist_raw_audio_from_guarded_start_launch_invocation_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_LAUNCH_INVOCATION_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_final_process_start_contract"
            if ready_for_guarded_start_final_process_start_contract
            else "fix_guarded_start_launch_invocation_prerequisites"
        ),
    })


def inspect_guarded_start_final_process_start_contract(
    *,
    guarded_start_launch_invocation_contract: Mapping[str, Any],
    final_process_start_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Declare the final guarded process-start boundary without starting."""

    authorization = final_process_start_authorization or {}
    launch_invocation_ready = (
        guarded_start_launch_invocation_contract.get("schema_version") == GUARDED_START_LAUNCH_INVOCATION_CONTRACT_SCHEMA_VERSION
        and guarded_start_launch_invocation_contract.get("status") == "ready_for_guarded_start_final_process_start_contract"
        and guarded_start_launch_invocation_contract.get("guarded_start_launch_invocation_contract_implemented") is True
        and guarded_start_launch_invocation_contract.get("launch_invocation_contract_only") is True
        and guarded_start_launch_invocation_contract.get("runtime_policy_start_enabled") is True
        and guarded_start_launch_invocation_contract.get("guarded_start_executor_enabled") is False
        and guarded_start_launch_invocation_contract.get("guarded_start_executor_implemented") is False
        and guarded_start_launch_invocation_contract.get("final_start_executor_enabled") is False
        and guarded_start_launch_invocation_contract.get("real_start_adapter_enabled") is False
        and guarded_start_launch_invocation_contract.get("start_execution_allowed") is False
        and guarded_start_launch_invocation_contract.get("real_subprocess_start_implemented") is False
        and guarded_start_launch_invocation_contract.get("process_launch_attempted") is False
        and guarded_start_launch_invocation_contract.get("daemon_started") is False
        and guarded_start_launch_invocation_contract.get("process_launch_allowed") is False
        and guarded_start_launch_invocation_contract.get("subprocess_module_imported") is False
        and guarded_start_launch_invocation_contract.get("livekit_sdk_imported") is False
        and guarded_start_launch_invocation_contract.get("provider_calls_made") is False
        and guarded_start_launch_invocation_contract.get("tool_calls_made") is False
        and guarded_start_launch_invocation_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_FINAL_PROCESS_START_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_final_process_start_contract"
        and authorization.get("final_process_start_contract_allowed") is True
        and authorization.get("launch_invocation_contract_attached") is True
        and authorization.get("decision_receipt_fresh") is True
        and authorization.get("single_start_per_receipt_required") is True
        and authorization.get("ready_event_required") is True
        and authorization.get("pid_guard_required") is True
        and authorization.get("startup_timeout_required") is True
        and authorization.get("stdout_stderr_sanitized") is True
        and authorization.get("rollback_required") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_process_execution_review = launch_invocation_ready and authorization_ready

    return validate_guarded_start_final_process_start_contract_packet({
        "schema_version": GUARDED_START_FINAL_PROCESS_START_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_process_execution_review"
            if ready_for_guarded_start_process_execution_review
            else "blocked"
        ),
        "implementation_status": "guarded_start_final_process_start_contract_declared_without_process_start",
        "guarded_start_final_process_start_contract_implemented": True,
        "final_process_start_contract_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_process_execution_review,
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
        "guarded_start_launch_invocation_contract_status": guarded_start_launch_invocation_contract.get("status"),
        "required_final_process_start_controls": [
            "launch_invocation_contract_before_final_process_start",
            "fresh_decision_receipt_before_final_process_start",
            "single_start_per_receipt_before_final_process_start",
            "pid_timeout_ready_event_before_final_process_start",
            "sanitized_streams_before_final_process_start",
            "rollback_before_final_process_start",
        ],
        "gates": {
            "guarded_start_launch_invocation_contract_ready": launch_invocation_ready,
            "final_process_start_authorization_ready": authorization_ready,
            "final_process_start_contract_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_process_execution_review,
            "launch_invocation_contract_attached": authorization.get("launch_invocation_contract_attached") is True,
            "decision_receipt_fresh": authorization.get("decision_receipt_fresh") is True,
            "single_start_per_receipt_required": authorization.get("single_start_per_receipt_required") is True,
            "ready_event_required": authorization.get("ready_event_required") is True,
            "pid_guard_required": authorization.get("pid_guard_required") is True,
            "startup_timeout_required": authorization.get("startup_timeout_required") is True,
            "stdout_stderr_sanitized": authorization.get("stdout_stderr_sanitized") is True,
            "rollback_required": authorization.get("rollback_required") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_final_process_start_contract",
            "import_subprocess_from_final_process_start_contract",
            "skip_guarded_start_process_execution_review",
            "call_provider_from_guarded_start_final_process_start_contract",
            "persist_raw_audio_from_guarded_start_final_process_start_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_FINAL_PROCESS_START_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_process_execution_review"
            if ready_for_guarded_start_process_execution_review
            else "fix_guarded_start_final_process_start_prerequisites"
        ),
    })


def inspect_guarded_start_process_execution_review(
    *,
    guarded_start_final_process_start_contract: Mapping[str, Any],
    process_execution_review_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Review the final process execution controls without launching."""

    authorization = process_execution_review_authorization or {}
    final_process_start_ready = (
        guarded_start_final_process_start_contract.get("schema_version")
        == GUARDED_START_FINAL_PROCESS_START_CONTRACT_SCHEMA_VERSION
        and guarded_start_final_process_start_contract.get("status")
        == "ready_for_guarded_start_process_execution_review"
        and guarded_start_final_process_start_contract.get(
            "guarded_start_final_process_start_contract_implemented"
        ) is True
        and guarded_start_final_process_start_contract.get("final_process_start_contract_only") is True
        and guarded_start_final_process_start_contract.get("runtime_policy_start_enabled") is True
        and guarded_start_final_process_start_contract.get("guarded_start_executor_enabled") is False
        and guarded_start_final_process_start_contract.get("guarded_start_executor_implemented") is False
        and guarded_start_final_process_start_contract.get("final_start_executor_enabled") is False
        and guarded_start_final_process_start_contract.get("real_start_adapter_enabled") is False
        and guarded_start_final_process_start_contract.get("start_execution_allowed") is False
        and guarded_start_final_process_start_contract.get("real_subprocess_start_implemented") is False
        and guarded_start_final_process_start_contract.get("process_launch_attempted") is False
        and guarded_start_final_process_start_contract.get("daemon_started") is False
        and guarded_start_final_process_start_contract.get("process_launch_allowed") is False
        and guarded_start_final_process_start_contract.get("subprocess_module_imported") is False
        and guarded_start_final_process_start_contract.get("livekit_sdk_imported") is False
        and guarded_start_final_process_start_contract.get("provider_calls_made") is False
        and guarded_start_final_process_start_contract.get("tool_calls_made") is False
        and guarded_start_final_process_start_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_PROCESS_EXECUTION_REVIEW_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_process_execution_review"
        and authorization.get("process_execution_review_allowed") is True
        and authorization.get("final_process_start_contract_attached") is True
        and authorization.get("technical_review_completed") is True
        and authorization.get("receipt_bound_to_final_start") is True
        and authorization.get("pid_guard_reviewed") is True
        and authorization.get("startup_timeout_reviewed") is True
        and authorization.get("ready_event_reviewed") is True
        and authorization.get("stdout_stderr_sanitization_reviewed") is True
        and authorization.get("rollback_reviewed") is True
        and authorization.get("observability_reviewed") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_process_execution_packet = final_process_start_ready and authorization_ready

    return validate_guarded_start_process_execution_review_packet({
        "schema_version": GUARDED_START_PROCESS_EXECUTION_REVIEW_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_process_execution_packet"
            if ready_for_guarded_start_process_execution_packet
            else "blocked"
        ),
        "implementation_status": "guarded_start_process_execution_review_attached_without_process_start",
        "guarded_start_process_execution_review_implemented": True,
        "process_execution_review_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_process_execution_packet,
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
        "guarded_start_final_process_start_contract_status": guarded_start_final_process_start_contract.get("status"),
        "required_process_execution_review_controls": [
            "final_process_start_contract_before_process_execution_packet",
            "technical_review_before_process_execution_packet",
            "receipt_binding_before_process_execution_packet",
            "pid_timeout_ready_event_review_before_process_execution_packet",
            "stream_sanitization_review_before_process_execution_packet",
            "rollback_and_observability_review_before_process_execution_packet",
        ],
        "gates": {
            "guarded_start_final_process_start_contract_ready": final_process_start_ready,
            "process_execution_review_authorization_ready": authorization_ready,
            "process_execution_review_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_process_execution_packet,
            "final_process_start_contract_attached": authorization.get("final_process_start_contract_attached") is True,
            "technical_review_completed": authorization.get("technical_review_completed") is True,
            "receipt_bound_to_final_start": authorization.get("receipt_bound_to_final_start") is True,
            "pid_guard_reviewed": authorization.get("pid_guard_reviewed") is True,
            "startup_timeout_reviewed": authorization.get("startup_timeout_reviewed") is True,
            "ready_event_reviewed": authorization.get("ready_event_reviewed") is True,
            "stdout_stderr_sanitization_reviewed": (
                authorization.get("stdout_stderr_sanitization_reviewed") is True
            ),
            "rollback_reviewed": authorization.get("rollback_reviewed") is True,
            "observability_reviewed": authorization.get("observability_reviewed") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_process_execution_review",
            "import_subprocess_from_process_execution_review",
            "skip_process_execution_packet_contract",
            "call_provider_from_guarded_start_process_execution_review",
            "persist_raw_audio_from_guarded_start_process_execution_review",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_PROCESS_EXECUTION_REVIEWED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_process_execution_packet"
            if ready_for_guarded_start_process_execution_packet
            else "fix_guarded_start_process_execution_review_prerequisites"
        ),
    })


def inspect_guarded_start_process_execution_packet(
    *,
    guarded_start_process_execution_review: Mapping[str, Any],
    process_execution_packet_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Assemble the immutable process execution packet without launching."""

    authorization = process_execution_packet_authorization or {}
    process_execution_review_ready = (
        guarded_start_process_execution_review.get("schema_version")
        == GUARDED_START_PROCESS_EXECUTION_REVIEW_SCHEMA_VERSION
        and guarded_start_process_execution_review.get("status")
        == "ready_for_guarded_start_process_execution_packet"
        and guarded_start_process_execution_review.get(
            "guarded_start_process_execution_review_implemented"
        ) is True
        and guarded_start_process_execution_review.get("process_execution_review_only") is True
        and guarded_start_process_execution_review.get("runtime_policy_start_enabled") is True
        and guarded_start_process_execution_review.get("guarded_start_executor_enabled") is False
        and guarded_start_process_execution_review.get("guarded_start_executor_implemented") is False
        and guarded_start_process_execution_review.get("final_start_executor_enabled") is False
        and guarded_start_process_execution_review.get("real_start_adapter_enabled") is False
        and guarded_start_process_execution_review.get("start_execution_allowed") is False
        and guarded_start_process_execution_review.get("real_subprocess_start_implemented") is False
        and guarded_start_process_execution_review.get("process_launch_attempted") is False
        and guarded_start_process_execution_review.get("daemon_started") is False
        and guarded_start_process_execution_review.get("process_launch_allowed") is False
        and guarded_start_process_execution_review.get("subprocess_module_imported") is False
        and guarded_start_process_execution_review.get("livekit_sdk_imported") is False
        and guarded_start_process_execution_review.get("provider_calls_made") is False
        and guarded_start_process_execution_review.get("tool_calls_made") is False
        and guarded_start_process_execution_review.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_PROCESS_EXECUTION_PACKET_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_process_execution_packet"
        and authorization.get("process_execution_packet_allowed") is True
        and authorization.get("process_execution_review_attached") is True
        and authorization.get("receipt_bound_to_execution_packet") is True
        and authorization.get("argv_redacted") is True
        and authorization.get("env_redacted") is True
        and authorization.get("cwd_confined") is True
        and authorization.get("pid_guard_attached") is True
        and authorization.get("startup_timeout_attached") is True
        and authorization.get("ready_event_attached") is True
        and authorization.get("stdout_stderr_sanitizers_attached") is True
        and authorization.get("rollback_attached") is True
        and authorization.get("observability_attached") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_process_executor_stub = process_execution_review_ready and authorization_ready

    return validate_guarded_start_process_execution_packet_packet({
        "schema_version": GUARDED_START_PROCESS_EXECUTION_PACKET_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_process_executor_stub"
            if ready_for_guarded_start_process_executor_stub
            else "blocked"
        ),
        "implementation_status": "guarded_start_process_execution_packet_attached_without_process_start",
        "guarded_start_process_execution_packet_implemented": True,
        "process_execution_packet_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_process_executor_stub,
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
        "guarded_start_process_execution_review_status": guarded_start_process_execution_review.get("status"),
        "required_process_execution_packet_controls": [
            "process_execution_review_before_packet",
            "receipt_binding_before_executor_stub",
            "argv_env_cwd_redaction_before_executor_stub",
            "pid_timeout_ready_event_before_executor_stub",
            "stream_sanitizers_before_executor_stub",
            "rollback_and_observability_before_executor_stub",
        ],
        "gates": {
            "guarded_start_process_execution_review_ready": process_execution_review_ready,
            "process_execution_packet_authorization_ready": authorization_ready,
            "process_execution_packet_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_process_executor_stub,
            "process_execution_review_attached": authorization.get("process_execution_review_attached") is True,
            "receipt_bound_to_execution_packet": authorization.get("receipt_bound_to_execution_packet") is True,
            "argv_redacted": authorization.get("argv_redacted") is True,
            "env_redacted": authorization.get("env_redacted") is True,
            "cwd_confined": authorization.get("cwd_confined") is True,
            "pid_guard_attached": authorization.get("pid_guard_attached") is True,
            "startup_timeout_attached": authorization.get("startup_timeout_attached") is True,
            "ready_event_attached": authorization.get("ready_event_attached") is True,
            "stdout_stderr_sanitizers_attached": (
                authorization.get("stdout_stderr_sanitizers_attached") is True
            ),
            "rollback_attached": authorization.get("rollback_attached") is True,
            "observability_attached": authorization.get("observability_attached") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_process_execution_packet",
            "import_subprocess_from_process_execution_packet",
            "skip_guarded_start_process_executor_stub",
            "call_provider_from_guarded_start_process_execution_packet",
            "persist_raw_audio_from_guarded_start_process_execution_packet",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_PROCESS_EXECUTION_PACKET_ATTACHED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_process_executor_stub"
            if ready_for_guarded_start_process_executor_stub
            else "fix_guarded_start_process_execution_packet_prerequisites"
        ),
    })


def inspect_guarded_start_process_executor_stub(
    *,
    guarded_start_process_execution_packet: Mapping[str, Any],
    process_executor_stub_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Declare the future executor boundary while keeping start disabled."""

    authorization = process_executor_stub_authorization or {}
    process_execution_packet_ready = (
        guarded_start_process_execution_packet.get("schema_version")
        == GUARDED_START_PROCESS_EXECUTION_PACKET_SCHEMA_VERSION
        and guarded_start_process_execution_packet.get("status")
        == "ready_for_guarded_start_process_executor_stub"
        and guarded_start_process_execution_packet.get(
            "guarded_start_process_execution_packet_implemented"
        ) is True
        and guarded_start_process_execution_packet.get("process_execution_packet_only") is True
        and guarded_start_process_execution_packet.get("runtime_policy_start_enabled") is True
        and guarded_start_process_execution_packet.get("guarded_start_executor_enabled") is False
        and guarded_start_process_execution_packet.get("guarded_start_executor_implemented") is False
        and guarded_start_process_execution_packet.get("final_start_executor_enabled") is False
        and guarded_start_process_execution_packet.get("real_start_adapter_enabled") is False
        and guarded_start_process_execution_packet.get("start_execution_allowed") is False
        and guarded_start_process_execution_packet.get("real_subprocess_start_implemented") is False
        and guarded_start_process_execution_packet.get("process_launch_attempted") is False
        and guarded_start_process_execution_packet.get("daemon_started") is False
        and guarded_start_process_execution_packet.get("process_launch_allowed") is False
        and guarded_start_process_execution_packet.get("subprocess_module_imported") is False
        and guarded_start_process_execution_packet.get("livekit_sdk_imported") is False
        and guarded_start_process_execution_packet.get("provider_calls_made") is False
        and guarded_start_process_execution_packet.get("tool_calls_made") is False
        and guarded_start_process_execution_packet.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_PROCESS_EXECUTOR_STUB_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_process_executor_stub"
        and authorization.get("process_executor_stub_allowed") is True
        and authorization.get("process_execution_packet_attached") is True
        and authorization.get("executor_stub_only") is True
        and authorization.get("localized_subprocess_import_required") is True
        and authorization.get("pid_guard_required") is True
        and authorization.get("startup_timeout_required") is True
        and authorization.get("ready_event_required") is True
        and authorization.get("stdout_stderr_sanitizers_required") is True
        and authorization.get("rollback_required") is True
        and authorization.get("observability_required") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_process_executor_review = process_execution_packet_ready and authorization_ready

    return validate_guarded_start_process_executor_stub_packet({
        "schema_version": GUARDED_START_PROCESS_EXECUTOR_STUB_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_process_executor_review"
            if ready_for_guarded_start_process_executor_review
            else "blocked"
        ),
        "implementation_status": "guarded_start_process_executor_stub_declared_without_process_start",
        "guarded_start_process_executor_stub_implemented": True,
        "process_executor_stub_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_process_executor_review,
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
        "guarded_start_process_execution_packet_status": guarded_start_process_execution_packet.get("status"),
        "required_process_executor_stub_controls": [
            "process_execution_packet_before_executor_stub",
            "localized_subprocess_import_before_real_executor",
            "pid_timeout_ready_event_before_real_executor",
            "stream_sanitizers_before_real_executor",
            "rollback_and_observability_before_real_executor",
        ],
        "gates": {
            "guarded_start_process_execution_packet_ready": process_execution_packet_ready,
            "process_executor_stub_authorization_ready": authorization_ready,
            "process_executor_stub_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_process_executor_review,
            "process_execution_packet_attached": authorization.get("process_execution_packet_attached") is True,
            "localized_subprocess_import_required": (
                authorization.get("localized_subprocess_import_required") is True
            ),
            "pid_guard_required": authorization.get("pid_guard_required") is True,
            "startup_timeout_required": authorization.get("startup_timeout_required") is True,
            "ready_event_required": authorization.get("ready_event_required") is True,
            "stdout_stderr_sanitizers_required": (
                authorization.get("stdout_stderr_sanitizers_required") is True
            ),
            "rollback_required": authorization.get("rollback_required") is True,
            "observability_required": authorization.get("observability_required") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_process_executor_stub",
            "import_subprocess_at_module_level",
            "skip_guarded_start_process_executor_review",
            "call_provider_from_guarded_start_process_executor_stub",
            "persist_raw_audio_from_guarded_start_process_executor_stub",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_PROCESS_EXECUTOR_STUB_DECLARED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_process_executor_review"
            if ready_for_guarded_start_process_executor_review
            else "fix_guarded_start_process_executor_stub_prerequisites"
        ),
    })


def inspect_guarded_start_process_executor_review(
    *,
    guarded_start_process_executor_stub: Mapping[str, Any],
    process_executor_review_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Review the future executor stub while keeping subprocess/start disabled."""

    authorization = process_executor_review_authorization or {}
    process_executor_stub_ready = (
        guarded_start_process_executor_stub.get("schema_version")
        == GUARDED_START_PROCESS_EXECUTOR_STUB_SCHEMA_VERSION
        and guarded_start_process_executor_stub.get("status")
        == "ready_for_guarded_start_process_executor_review"
        and guarded_start_process_executor_stub.get("guarded_start_process_executor_stub_implemented") is True
        and guarded_start_process_executor_stub.get("process_executor_stub_only") is True
        and guarded_start_process_executor_stub.get("runtime_policy_start_enabled") is True
        and guarded_start_process_executor_stub.get("guarded_start_executor_enabled") is False
        and guarded_start_process_executor_stub.get("guarded_start_executor_implemented") is False
        and guarded_start_process_executor_stub.get("final_start_executor_enabled") is False
        and guarded_start_process_executor_stub.get("real_start_adapter_enabled") is False
        and guarded_start_process_executor_stub.get("start_execution_allowed") is False
        and guarded_start_process_executor_stub.get("real_subprocess_start_implemented") is False
        and guarded_start_process_executor_stub.get("process_launch_attempted") is False
        and guarded_start_process_executor_stub.get("daemon_started") is False
        and guarded_start_process_executor_stub.get("process_launch_allowed") is False
        and guarded_start_process_executor_stub.get("subprocess_module_imported") is False
        and guarded_start_process_executor_stub.get("livekit_sdk_imported") is False
        and guarded_start_process_executor_stub.get("provider_calls_made") is False
        and guarded_start_process_executor_stub.get("tool_calls_made") is False
        and guarded_start_process_executor_stub.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_PROCESS_EXECUTOR_REVIEW_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_process_executor_review"
        and authorization.get("process_executor_review_allowed") is True
        and authorization.get("process_executor_stub_attached") is True
        and authorization.get("technical_review_completed") is True
        and authorization.get("localized_subprocess_import_reviewed") is True
        and authorization.get("pid_guard_reviewed") is True
        and authorization.get("startup_timeout_reviewed") is True
        and authorization.get("ready_event_reviewed") is True
        and authorization.get("stdout_stderr_sanitization_reviewed") is True
        and authorization.get("rollback_reviewed") is True
        and authorization.get("observability_reviewed") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_process_executor_contract = process_executor_stub_ready and authorization_ready

    return validate_guarded_start_process_executor_review_packet({
        "schema_version": GUARDED_START_PROCESS_EXECUTOR_REVIEW_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_process_executor_contract"
            if ready_for_guarded_start_process_executor_contract
            else "blocked"
        ),
        "implementation_status": "guarded_start_process_executor_review_attached_without_process_start",
        "guarded_start_process_executor_review_implemented": True,
        "process_executor_review_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_process_executor_contract,
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
        "guarded_start_process_executor_stub_status": guarded_start_process_executor_stub.get("status"),
        "required_process_executor_review_controls": [
            "process_executor_stub_before_executor_contract",
            "technical_review_before_executor_contract",
            "localized_subprocess_import_review_before_executor_contract",
            "pid_timeout_ready_event_review_before_executor_contract",
            "stream_sanitization_review_before_executor_contract",
            "rollback_and_observability_review_before_executor_contract",
        ],
        "gates": {
            "guarded_start_process_executor_stub_ready": process_executor_stub_ready,
            "process_executor_review_authorization_ready": authorization_ready,
            "process_executor_review_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_process_executor_contract,
            "process_executor_stub_attached": authorization.get("process_executor_stub_attached") is True,
            "technical_review_completed": authorization.get("technical_review_completed") is True,
            "localized_subprocess_import_reviewed": (
                authorization.get("localized_subprocess_import_reviewed") is True
            ),
            "pid_guard_reviewed": authorization.get("pid_guard_reviewed") is True,
            "startup_timeout_reviewed": authorization.get("startup_timeout_reviewed") is True,
            "ready_event_reviewed": authorization.get("ready_event_reviewed") is True,
            "stdout_stderr_sanitization_reviewed": (
                authorization.get("stdout_stderr_sanitization_reviewed") is True
            ),
            "rollback_reviewed": authorization.get("rollback_reviewed") is True,
            "observability_reviewed": authorization.get("observability_reviewed") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_process_executor_review",
            "import_subprocess_from_process_executor_review",
            "skip_guarded_start_process_executor_contract",
            "call_provider_from_guarded_start_process_executor_review",
            "persist_raw_audio_from_guarded_start_process_executor_review",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_PROCESS_EXECUTOR_REVIEWED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_process_executor_contract"
            if ready_for_guarded_start_process_executor_contract
            else "fix_guarded_start_process_executor_review_prerequisites"
        ),
    })


def inspect_guarded_start_process_executor_contract(
    *,
    guarded_start_process_executor_review: Mapping[str, Any],
    process_executor_contract_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Define the guarded process executor contract without importing subprocess."""

    authorization = process_executor_contract_authorization or {}
    process_executor_review_ready = (
        guarded_start_process_executor_review.get("schema_version")
        == GUARDED_START_PROCESS_EXECUTOR_REVIEW_SCHEMA_VERSION
        and guarded_start_process_executor_review.get("status")
        == "ready_for_guarded_start_process_executor_contract"
        and guarded_start_process_executor_review.get("guarded_start_process_executor_review_implemented") is True
        and guarded_start_process_executor_review.get("process_executor_review_only") is True
        and guarded_start_process_executor_review.get("runtime_policy_start_enabled") is True
        and guarded_start_process_executor_review.get("guarded_start_executor_enabled") is False
        and guarded_start_process_executor_review.get("guarded_start_executor_implemented") is False
        and guarded_start_process_executor_review.get("final_start_executor_enabled") is False
        and guarded_start_process_executor_review.get("real_start_adapter_enabled") is False
        and guarded_start_process_executor_review.get("start_execution_allowed") is False
        and guarded_start_process_executor_review.get("real_subprocess_start_implemented") is False
        and guarded_start_process_executor_review.get("process_launch_attempted") is False
        and guarded_start_process_executor_review.get("daemon_started") is False
        and guarded_start_process_executor_review.get("process_launch_allowed") is False
        and guarded_start_process_executor_review.get("subprocess_module_imported") is False
        and guarded_start_process_executor_review.get("livekit_sdk_imported") is False
        and guarded_start_process_executor_review.get("provider_calls_made") is False
        and guarded_start_process_executor_review.get("tool_calls_made") is False
        and guarded_start_process_executor_review.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_PROCESS_EXECUTOR_CONTRACT_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_process_executor_contract"
        and authorization.get("process_executor_contract_allowed") is True
        and authorization.get("process_executor_review_attached") is True
        and authorization.get("localized_subprocess_import_contract_required") is True
        and authorization.get("pid_guard_contract_required") is True
        and authorization.get("startup_timeout_contract_required") is True
        and authorization.get("ready_event_contract_required") is True
        and authorization.get("stdout_stderr_sanitization_contract_required") is True
        and authorization.get("rollback_contract_required") is True
        and authorization.get("observability_contract_required") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_process_runtime_adapter = process_executor_review_ready and authorization_ready

    return validate_guarded_start_process_executor_contract_packet({
        "schema_version": GUARDED_START_PROCESS_EXECUTOR_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_process_runtime_adapter"
            if ready_for_guarded_start_process_runtime_adapter
            else "blocked"
        ),
        "implementation_status": "guarded_start_process_executor_contract_declared_without_process_start",
        "guarded_start_process_executor_contract_implemented": True,
        "process_executor_contract_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_process_runtime_adapter,
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
        "guarded_start_process_executor_review_status": guarded_start_process_executor_review.get("status"),
        "required_process_executor_contract_controls": [
            "process_executor_review_before_executor_contract",
            "localized_subprocess_import_contract_before_runtime_adapter",
            "pid_timeout_ready_event_contract_before_runtime_adapter",
            "stream_sanitization_contract_before_runtime_adapter",
            "rollback_and_observability_contract_before_runtime_adapter",
        ],
        "gates": {
            "guarded_start_process_executor_review_ready": process_executor_review_ready,
            "process_executor_contract_authorization_ready": authorization_ready,
            "process_executor_contract_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_process_runtime_adapter,
            "process_executor_review_attached": authorization.get("process_executor_review_attached") is True,
            "localized_subprocess_import_contract_required": (
                authorization.get("localized_subprocess_import_contract_required") is True
            ),
            "pid_guard_contract_required": authorization.get("pid_guard_contract_required") is True,
            "startup_timeout_contract_required": authorization.get("startup_timeout_contract_required") is True,
            "ready_event_contract_required": authorization.get("ready_event_contract_required") is True,
            "stdout_stderr_sanitization_contract_required": (
                authorization.get("stdout_stderr_sanitization_contract_required") is True
            ),
            "rollback_contract_required": authorization.get("rollback_contract_required") is True,
            "observability_contract_required": authorization.get("observability_contract_required") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_process_executor_contract",
            "import_subprocess_from_process_executor_contract",
            "skip_guarded_start_process_runtime_adapter",
            "call_provider_from_guarded_start_process_executor_contract",
            "persist_raw_audio_from_guarded_start_process_executor_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_PROCESS_EXECUTOR_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_process_runtime_adapter"
            if ready_for_guarded_start_process_runtime_adapter
            else "fix_guarded_start_process_executor_contract_prerequisites"
        ),
    })


def inspect_guarded_start_process_runtime_adapter(
    *,
    guarded_start_process_executor_contract: Mapping[str, Any],
    process_runtime_adapter_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Declare the guarded runtime adapter without importing subprocess or starting it."""

    authorization = process_runtime_adapter_authorization or {}
    process_executor_contract_ready = (
        guarded_start_process_executor_contract.get("schema_version")
        == GUARDED_START_PROCESS_EXECUTOR_CONTRACT_SCHEMA_VERSION
        and guarded_start_process_executor_contract.get("status")
        == "ready_for_guarded_start_process_runtime_adapter"
        and guarded_start_process_executor_contract.get("guarded_start_process_executor_contract_implemented") is True
        and guarded_start_process_executor_contract.get("process_executor_contract_only") is True
        and guarded_start_process_executor_contract.get("runtime_policy_start_enabled") is True
        and guarded_start_process_executor_contract.get("guarded_start_executor_enabled") is False
        and guarded_start_process_executor_contract.get("guarded_start_executor_implemented") is False
        and guarded_start_process_executor_contract.get("final_start_executor_enabled") is False
        and guarded_start_process_executor_contract.get("real_start_adapter_enabled") is False
        and guarded_start_process_executor_contract.get("start_execution_allowed") is False
        and guarded_start_process_executor_contract.get("real_subprocess_start_implemented") is False
        and guarded_start_process_executor_contract.get("process_launch_attempted") is False
        and guarded_start_process_executor_contract.get("daemon_started") is False
        and guarded_start_process_executor_contract.get("process_launch_allowed") is False
        and guarded_start_process_executor_contract.get("subprocess_module_imported") is False
        and guarded_start_process_executor_contract.get("livekit_sdk_imported") is False
        and guarded_start_process_executor_contract.get("provider_calls_made") is False
        and guarded_start_process_executor_contract.get("tool_calls_made") is False
        and guarded_start_process_executor_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_PROCESS_RUNTIME_ADAPTER_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_process_runtime_adapter"
        and authorization.get("process_runtime_adapter_allowed") is True
        and authorization.get("process_executor_contract_attached") is True
        and authorization.get("runtime_adapter_contract_only") is True
        and authorization.get("localized_subprocess_import_boundary_required") is True
        and authorization.get("pid_guard_adapter_required") is True
        and authorization.get("startup_timeout_adapter_required") is True
        and authorization.get("ready_event_adapter_required") is True
        and authorization.get("stdout_stderr_sanitizers_required") is True
        and authorization.get("rollback_adapter_required") is True
        and authorization.get("observability_adapter_required") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_process_adapter_review = process_executor_contract_ready and authorization_ready

    return validate_guarded_start_process_runtime_adapter_packet({
        "schema_version": GUARDED_START_PROCESS_RUNTIME_ADAPTER_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_process_adapter_review"
            if ready_for_guarded_start_process_adapter_review
            else "blocked"
        ),
        "implementation_status": "guarded_start_process_runtime_adapter_declared_without_process_start",
        "guarded_start_process_runtime_adapter_implemented": True,
        "process_runtime_adapter_only": True,
        "runtime_adapter_contract_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_process_adapter_review,
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
        "guarded_start_process_executor_contract_status": guarded_start_process_executor_contract.get("status"),
        "required_process_runtime_adapter_controls": [
            "process_executor_contract_before_runtime_adapter",
            "runtime_adapter_contract_only_before_review",
            "localized_subprocess_import_boundary_before_review",
            "pid_timeout_ready_event_adapter_before_review",
            "stream_sanitization_adapter_before_review",
            "rollback_and_observability_adapter_before_review",
        ],
        "gates": {
            "guarded_start_process_executor_contract_ready": process_executor_contract_ready,
            "process_runtime_adapter_authorization_ready": authorization_ready,
            "process_runtime_adapter_only": True,
            "runtime_adapter_contract_only": authorization.get("runtime_adapter_contract_only") is True,
            "runtime_policy_start_enabled": ready_for_guarded_start_process_adapter_review,
            "process_executor_contract_attached": authorization.get("process_executor_contract_attached") is True,
            "localized_subprocess_import_boundary_required": (
                authorization.get("localized_subprocess_import_boundary_required") is True
            ),
            "pid_guard_adapter_required": authorization.get("pid_guard_adapter_required") is True,
            "startup_timeout_adapter_required": authorization.get("startup_timeout_adapter_required") is True,
            "ready_event_adapter_required": authorization.get("ready_event_adapter_required") is True,
            "stdout_stderr_sanitizers_required": authorization.get("stdout_stderr_sanitizers_required") is True,
            "rollback_adapter_required": authorization.get("rollback_adapter_required") is True,
            "observability_adapter_required": authorization.get("observability_adapter_required") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_process_runtime_adapter",
            "import_subprocess_from_process_runtime_adapter",
            "skip_guarded_start_process_adapter_review",
            "call_provider_from_guarded_start_process_runtime_adapter",
            "persist_raw_audio_from_guarded_start_process_runtime_adapter",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_PROCESS_RUNTIME_ADAPTER_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_process_adapter_review"
            if ready_for_guarded_start_process_adapter_review
            else "fix_guarded_start_process_runtime_adapter_prerequisites"
        ),
    })


def inspect_guarded_start_process_adapter_review(
    *,
    guarded_start_process_runtime_adapter: Mapping[str, Any],
    process_adapter_review_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Review the guarded runtime adapter while keeping process start disabled."""

    authorization = process_adapter_review_authorization or {}
    process_runtime_adapter_ready = (
        guarded_start_process_runtime_adapter.get("schema_version")
        == GUARDED_START_PROCESS_RUNTIME_ADAPTER_SCHEMA_VERSION
        and guarded_start_process_runtime_adapter.get("status")
        == "ready_for_guarded_start_process_adapter_review"
        and guarded_start_process_runtime_adapter.get("guarded_start_process_runtime_adapter_implemented") is True
        and guarded_start_process_runtime_adapter.get("process_runtime_adapter_only") is True
        and guarded_start_process_runtime_adapter.get("runtime_adapter_contract_only") is True
        and guarded_start_process_runtime_adapter.get("runtime_policy_start_enabled") is True
        and guarded_start_process_runtime_adapter.get("guarded_start_executor_enabled") is False
        and guarded_start_process_runtime_adapter.get("guarded_start_executor_implemented") is False
        and guarded_start_process_runtime_adapter.get("final_start_executor_enabled") is False
        and guarded_start_process_runtime_adapter.get("real_start_adapter_enabled") is False
        and guarded_start_process_runtime_adapter.get("start_execution_allowed") is False
        and guarded_start_process_runtime_adapter.get("real_subprocess_start_implemented") is False
        and guarded_start_process_runtime_adapter.get("process_launch_attempted") is False
        and guarded_start_process_runtime_adapter.get("daemon_started") is False
        and guarded_start_process_runtime_adapter.get("process_launch_allowed") is False
        and guarded_start_process_runtime_adapter.get("subprocess_module_imported") is False
        and guarded_start_process_runtime_adapter.get("livekit_sdk_imported") is False
        and guarded_start_process_runtime_adapter.get("provider_calls_made") is False
        and guarded_start_process_runtime_adapter.get("tool_calls_made") is False
        and guarded_start_process_runtime_adapter.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_PROCESS_ADAPTER_REVIEW_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_process_adapter_review"
        and authorization.get("process_adapter_review_allowed") is True
        and authorization.get("process_runtime_adapter_attached") is True
        and authorization.get("technical_review_completed") is True
        and authorization.get("runtime_adapter_contract_reviewed") is True
        and authorization.get("localized_subprocess_import_boundary_reviewed") is True
        and authorization.get("pid_guard_adapter_reviewed") is True
        and authorization.get("startup_timeout_adapter_reviewed") is True
        and authorization.get("ready_event_adapter_reviewed") is True
        and authorization.get("stdout_stderr_sanitizers_reviewed") is True
        and authorization.get("rollback_adapter_reviewed") is True
        and authorization.get("observability_adapter_reviewed") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_process_adapter_contract = process_runtime_adapter_ready and authorization_ready

    return validate_guarded_start_process_adapter_review_packet({
        "schema_version": GUARDED_START_PROCESS_ADAPTER_REVIEW_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_process_adapter_contract"
            if ready_for_guarded_start_process_adapter_contract
            else "blocked"
        ),
        "implementation_status": "guarded_start_process_adapter_review_completed_without_process_start",
        "guarded_start_process_adapter_review_implemented": True,
        "process_adapter_review_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_process_adapter_contract,
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
        "guarded_start_process_runtime_adapter_status": guarded_start_process_runtime_adapter.get("status"),
        "required_process_adapter_review_controls": [
            "process_runtime_adapter_before_review",
            "technical_review_before_adapter_contract",
            "runtime_adapter_contract_review_before_adapter_contract",
            "localized_subprocess_import_boundary_review_before_adapter_contract",
            "pid_timeout_ready_event_adapter_review_before_adapter_contract",
            "stream_sanitization_adapter_review_before_adapter_contract",
            "rollback_and_observability_adapter_review_before_adapter_contract",
        ],
        "gates": {
            "guarded_start_process_runtime_adapter_ready": process_runtime_adapter_ready,
            "process_adapter_review_authorization_ready": authorization_ready,
            "process_adapter_review_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_process_adapter_contract,
            "process_runtime_adapter_attached": authorization.get("process_runtime_adapter_attached") is True,
            "technical_review_completed": authorization.get("technical_review_completed") is True,
            "runtime_adapter_contract_reviewed": authorization.get("runtime_adapter_contract_reviewed") is True,
            "localized_subprocess_import_boundary_reviewed": (
                authorization.get("localized_subprocess_import_boundary_reviewed") is True
            ),
            "pid_guard_adapter_reviewed": authorization.get("pid_guard_adapter_reviewed") is True,
            "startup_timeout_adapter_reviewed": authorization.get("startup_timeout_adapter_reviewed") is True,
            "ready_event_adapter_reviewed": authorization.get("ready_event_adapter_reviewed") is True,
            "stdout_stderr_sanitizers_reviewed": authorization.get("stdout_stderr_sanitizers_reviewed") is True,
            "rollback_adapter_reviewed": authorization.get("rollback_adapter_reviewed") is True,
            "observability_adapter_reviewed": authorization.get("observability_adapter_reviewed") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_process_adapter_review",
            "import_subprocess_from_process_adapter_review",
            "skip_guarded_start_process_adapter_contract",
            "call_provider_from_guarded_start_process_adapter_review",
            "persist_raw_audio_from_guarded_start_process_adapter_review",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_PROCESS_ADAPTER_REVIEWED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_process_adapter_contract"
            if ready_for_guarded_start_process_adapter_contract
            else "fix_guarded_start_process_adapter_review_prerequisites"
        ),
    })


def inspect_guarded_start_process_adapter_contract(
    *,
    guarded_start_process_adapter_review: Mapping[str, Any],
    process_adapter_contract_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Declare the guarded process adapter contract without importing or starting."""

    authorization = process_adapter_contract_authorization or {}
    process_adapter_review_ready = (
        guarded_start_process_adapter_review.get("schema_version")
        == GUARDED_START_PROCESS_ADAPTER_REVIEW_SCHEMA_VERSION
        and guarded_start_process_adapter_review.get("status")
        == "ready_for_guarded_start_process_adapter_contract"
        and guarded_start_process_adapter_review.get("guarded_start_process_adapter_review_implemented") is True
        and guarded_start_process_adapter_review.get("process_adapter_review_only") is True
        and guarded_start_process_adapter_review.get("runtime_policy_start_enabled") is True
        and guarded_start_process_adapter_review.get("guarded_start_executor_enabled") is False
        and guarded_start_process_adapter_review.get("guarded_start_executor_implemented") is False
        and guarded_start_process_adapter_review.get("final_start_executor_enabled") is False
        and guarded_start_process_adapter_review.get("real_start_adapter_enabled") is False
        and guarded_start_process_adapter_review.get("start_execution_allowed") is False
        and guarded_start_process_adapter_review.get("real_subprocess_start_implemented") is False
        and guarded_start_process_adapter_review.get("process_launch_attempted") is False
        and guarded_start_process_adapter_review.get("daemon_started") is False
        and guarded_start_process_adapter_review.get("process_launch_allowed") is False
        and guarded_start_process_adapter_review.get("subprocess_module_imported") is False
        and guarded_start_process_adapter_review.get("livekit_sdk_imported") is False
        and guarded_start_process_adapter_review.get("provider_calls_made") is False
        and guarded_start_process_adapter_review.get("tool_calls_made") is False
        and guarded_start_process_adapter_review.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_PROCESS_ADAPTER_CONTRACT_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_process_adapter_contract"
        and authorization.get("process_adapter_contract_allowed") is True
        and authorization.get("process_adapter_review_attached") is True
        and authorization.get("runtime_adapter_contract_required") is True
        and authorization.get("localized_subprocess_import_boundary_required") is True
        and authorization.get("pid_guard_adapter_contract_required") is True
        and authorization.get("startup_timeout_adapter_contract_required") is True
        and authorization.get("ready_event_adapter_contract_required") is True
        and authorization.get("stdout_stderr_sanitizers_contract_required") is True
        and authorization.get("rollback_adapter_contract_required") is True
        and authorization.get("observability_adapter_contract_required") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_process_runner_contract = process_adapter_review_ready and authorization_ready

    return validate_guarded_start_process_adapter_contract_packet({
        "schema_version": GUARDED_START_PROCESS_ADAPTER_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_process_runner_contract"
            if ready_for_guarded_start_process_runner_contract
            else "blocked"
        ),
        "implementation_status": "guarded_start_process_adapter_contract_declared_without_process_start",
        "guarded_start_process_adapter_contract_implemented": True,
        "process_adapter_contract_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_process_runner_contract,
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
        "guarded_start_process_adapter_review_status": guarded_start_process_adapter_review.get("status"),
        "required_process_adapter_contract_controls": [
            "process_adapter_review_before_contract",
            "runtime_adapter_contract_before_runner_contract",
            "localized_subprocess_import_boundary_before_runner_contract",
            "pid_timeout_ready_event_adapter_contract_before_runner_contract",
            "stream_sanitization_adapter_contract_before_runner_contract",
            "rollback_and_observability_adapter_contract_before_runner_contract",
        ],
        "gates": {
            "guarded_start_process_adapter_review_ready": process_adapter_review_ready,
            "process_adapter_contract_authorization_ready": authorization_ready,
            "process_adapter_contract_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_process_runner_contract,
            "process_adapter_review_attached": authorization.get("process_adapter_review_attached") is True,
            "runtime_adapter_contract_required": authorization.get("runtime_adapter_contract_required") is True,
            "localized_subprocess_import_boundary_required": (
                authorization.get("localized_subprocess_import_boundary_required") is True
            ),
            "pid_guard_adapter_contract_required": authorization.get("pid_guard_adapter_contract_required") is True,
            "startup_timeout_adapter_contract_required": (
                authorization.get("startup_timeout_adapter_contract_required") is True
            ),
            "ready_event_adapter_contract_required": authorization.get("ready_event_adapter_contract_required") is True,
            "stdout_stderr_sanitizers_contract_required": (
                authorization.get("stdout_stderr_sanitizers_contract_required") is True
            ),
            "rollback_adapter_contract_required": authorization.get("rollback_adapter_contract_required") is True,
            "observability_adapter_contract_required": authorization.get("observability_adapter_contract_required") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_process_adapter_contract",
            "import_subprocess_from_process_adapter_contract",
            "skip_guarded_start_process_runner_contract",
            "call_provider_from_guarded_start_process_adapter_contract",
            "persist_raw_audio_from_guarded_start_process_adapter_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_PROCESS_ADAPTER_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_process_runner_contract"
            if ready_for_guarded_start_process_runner_contract
            else "fix_guarded_start_process_adapter_contract_prerequisites"
        ),
    })


def inspect_guarded_start_process_runner_contract(
    *,
    guarded_start_process_adapter_contract: Mapping[str, Any],
    process_runner_contract_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Declare the runner contract while keeping execution impossible."""

    authorization = process_runner_contract_authorization or {}
    process_adapter_contract_ready = (
        guarded_start_process_adapter_contract.get("schema_version")
        == GUARDED_START_PROCESS_ADAPTER_CONTRACT_SCHEMA_VERSION
        and guarded_start_process_adapter_contract.get("status")
        == "ready_for_guarded_start_process_runner_contract"
        and guarded_start_process_adapter_contract.get("guarded_start_process_adapter_contract_implemented") is True
        and guarded_start_process_adapter_contract.get("process_adapter_contract_only") is True
        and guarded_start_process_adapter_contract.get("runtime_policy_start_enabled") is True
        and guarded_start_process_adapter_contract.get("guarded_start_executor_enabled") is False
        and guarded_start_process_adapter_contract.get("guarded_start_executor_implemented") is False
        and guarded_start_process_adapter_contract.get("final_start_executor_enabled") is False
        and guarded_start_process_adapter_contract.get("real_start_adapter_enabled") is False
        and guarded_start_process_adapter_contract.get("start_execution_allowed") is False
        and guarded_start_process_adapter_contract.get("real_subprocess_start_implemented") is False
        and guarded_start_process_adapter_contract.get("process_launch_attempted") is False
        and guarded_start_process_adapter_contract.get("daemon_started") is False
        and guarded_start_process_adapter_contract.get("process_launch_allowed") is False
        and guarded_start_process_adapter_contract.get("subprocess_module_imported") is False
        and guarded_start_process_adapter_contract.get("livekit_sdk_imported") is False
        and guarded_start_process_adapter_contract.get("provider_calls_made") is False
        and guarded_start_process_adapter_contract.get("tool_calls_made") is False
        and guarded_start_process_adapter_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_PROCESS_RUNNER_CONTRACT_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_process_runner_contract"
        and authorization.get("process_runner_contract_allowed") is True
        and authorization.get("process_adapter_contract_attached") is True
        and authorization.get("runner_contract_only") is True
        and authorization.get("single_start_receipt_required") is True
        and authorization.get("pid_guard_runner_required") is True
        and authorization.get("startup_timeout_runner_required") is True
        and authorization.get("ready_event_runner_required") is True
        and authorization.get("stdout_stderr_sanitizers_runner_required") is True
        and authorization.get("rollback_runner_required") is True
        and authorization.get("observability_runner_required") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_process_runner_review = process_adapter_contract_ready and authorization_ready

    return validate_guarded_start_process_runner_contract_packet({
        "schema_version": GUARDED_START_PROCESS_RUNNER_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_process_runner_review"
            if ready_for_guarded_start_process_runner_review
            else "blocked"
        ),
        "implementation_status": "guarded_start_process_runner_contract_declared_without_process_start",
        "guarded_start_process_runner_contract_implemented": True,
        "process_runner_contract_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_process_runner_review,
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
        "guarded_start_process_adapter_contract_status": guarded_start_process_adapter_contract.get("status"),
        "required_process_runner_contract_controls": [
            "process_adapter_contract_before_runner_contract",
            "single_start_receipt_before_runner_review",
            "pid_timeout_ready_event_runner_before_runner_review",
            "stream_sanitization_runner_before_runner_review",
            "rollback_and_observability_runner_before_runner_review",
        ],
        "gates": {
            "guarded_start_process_adapter_contract_ready": process_adapter_contract_ready,
            "process_runner_contract_authorization_ready": authorization_ready,
            "process_runner_contract_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_process_runner_review,
            "process_adapter_contract_attached": authorization.get("process_adapter_contract_attached") is True,
            "runner_contract_only": authorization.get("runner_contract_only") is True,
            "single_start_receipt_required": authorization.get("single_start_receipt_required") is True,
            "pid_guard_runner_required": authorization.get("pid_guard_runner_required") is True,
            "startup_timeout_runner_required": authorization.get("startup_timeout_runner_required") is True,
            "ready_event_runner_required": authorization.get("ready_event_runner_required") is True,
            "stdout_stderr_sanitizers_runner_required": (
                authorization.get("stdout_stderr_sanitizers_runner_required") is True
            ),
            "rollback_runner_required": authorization.get("rollback_runner_required") is True,
            "observability_runner_required": authorization.get("observability_runner_required") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_process_runner_contract",
            "import_subprocess_from_process_runner_contract",
            "skip_guarded_start_process_runner_review",
            "call_provider_from_guarded_start_process_runner_contract",
            "persist_raw_audio_from_guarded_start_process_runner_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_process_runner_review"
            if ready_for_guarded_start_process_runner_review
            else "fix_guarded_start_process_runner_contract_prerequisites"
        ),
    })


def inspect_guarded_start_process_runner_review(
    *,
    guarded_start_process_runner_contract: Mapping[str, Any],
    process_runner_review_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Review the runner contract controls without importing or starting."""

    authorization = process_runner_review_authorization or {}
    process_runner_contract_ready = (
        guarded_start_process_runner_contract.get("schema_version")
        == GUARDED_START_PROCESS_RUNNER_CONTRACT_SCHEMA_VERSION
        and guarded_start_process_runner_contract.get("status")
        == "ready_for_guarded_start_process_runner_review"
        and guarded_start_process_runner_contract.get("guarded_start_process_runner_contract_implemented") is True
        and guarded_start_process_runner_contract.get("process_runner_contract_only") is True
        and guarded_start_process_runner_contract.get("runtime_policy_start_enabled") is True
        and guarded_start_process_runner_contract.get("guarded_start_executor_enabled") is False
        and guarded_start_process_runner_contract.get("guarded_start_executor_implemented") is False
        and guarded_start_process_runner_contract.get("final_start_executor_enabled") is False
        and guarded_start_process_runner_contract.get("real_start_adapter_enabled") is False
        and guarded_start_process_runner_contract.get("start_execution_allowed") is False
        and guarded_start_process_runner_contract.get("real_subprocess_start_implemented") is False
        and guarded_start_process_runner_contract.get("process_launch_attempted") is False
        and guarded_start_process_runner_contract.get("daemon_started") is False
        and guarded_start_process_runner_contract.get("process_launch_allowed") is False
        and guarded_start_process_runner_contract.get("subprocess_module_imported") is False
        and guarded_start_process_runner_contract.get("livekit_sdk_imported") is False
        and guarded_start_process_runner_contract.get("provider_calls_made") is False
        and guarded_start_process_runner_contract.get("tool_calls_made") is False
        and guarded_start_process_runner_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_PROCESS_RUNNER_REVIEW_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_process_runner_review"
        and authorization.get("process_runner_review_allowed") is True
        and authorization.get("process_runner_contract_attached") is True
        and authorization.get("technical_review_completed") is True
        and authorization.get("single_start_receipt_reviewed") is True
        and authorization.get("pid_guard_runner_reviewed") is True
        and authorization.get("startup_timeout_runner_reviewed") is True
        and authorization.get("ready_event_runner_reviewed") is True
        and authorization.get("stdout_stderr_sanitizers_runner_reviewed") is True
        and authorization.get("rollback_runner_reviewed") is True
        and authorization.get("observability_runner_reviewed") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_process_runner_packet = process_runner_contract_ready and authorization_ready

    return validate_guarded_start_process_runner_review_packet({
        "schema_version": GUARDED_START_PROCESS_RUNNER_REVIEW_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_process_runner_packet"
            if ready_for_guarded_start_process_runner_packet
            else "blocked"
        ),
        "implementation_status": "guarded_start_process_runner_review_completed_without_process_start",
        "guarded_start_process_runner_review_implemented": True,
        "process_runner_review_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_process_runner_packet,
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
        "guarded_start_process_runner_contract_status": guarded_start_process_runner_contract.get("status"),
        "reviewed_process_runner_controls": [
            "process_runner_contract_before_runner_review",
            "single_start_receipt_reviewed_before_runner_packet",
            "pid_timeout_ready_event_runner_reviewed_before_runner_packet",
            "stream_sanitization_runner_reviewed_before_runner_packet",
            "rollback_and_observability_runner_reviewed_before_runner_packet",
        ],
        "gates": {
            "guarded_start_process_runner_contract_ready": process_runner_contract_ready,
            "process_runner_review_authorization_ready": authorization_ready,
            "process_runner_review_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_process_runner_packet,
            "process_runner_contract_attached": authorization.get("process_runner_contract_attached") is True,
            "technical_review_completed": authorization.get("technical_review_completed") is True,
            "single_start_receipt_reviewed": authorization.get("single_start_receipt_reviewed") is True,
            "pid_guard_runner_reviewed": authorization.get("pid_guard_runner_reviewed") is True,
            "startup_timeout_runner_reviewed": authorization.get("startup_timeout_runner_reviewed") is True,
            "ready_event_runner_reviewed": authorization.get("ready_event_runner_reviewed") is True,
            "stdout_stderr_sanitizers_runner_reviewed": (
                authorization.get("stdout_stderr_sanitizers_runner_reviewed") is True
            ),
            "rollback_runner_reviewed": authorization.get("rollback_runner_reviewed") is True,
            "observability_runner_reviewed": authorization.get("observability_runner_reviewed") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_process_runner_review",
            "import_subprocess_from_process_runner_review",
            "skip_guarded_start_process_runner_packet",
            "call_provider_from_guarded_start_process_runner_review",
            "persist_raw_audio_from_guarded_start_process_runner_review",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_REVIEWED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_process_runner_packet"
            if ready_for_guarded_start_process_runner_packet
            else "fix_guarded_start_process_runner_review_prerequisites"
        ),
    })


def inspect_guarded_start_process_runner_packet(
    *,
    guarded_start_process_runner_review: Mapping[str, Any],
    process_runner_packet_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Attach the runner packet controls without importing or starting."""

    authorization = process_runner_packet_authorization or {}
    process_runner_review_ready = (
        guarded_start_process_runner_review.get("schema_version")
        == GUARDED_START_PROCESS_RUNNER_REVIEW_SCHEMA_VERSION
        and guarded_start_process_runner_review.get("status")
        == "ready_for_guarded_start_process_runner_packet"
        and guarded_start_process_runner_review.get("guarded_start_process_runner_review_implemented") is True
        and guarded_start_process_runner_review.get("process_runner_review_only") is True
        and guarded_start_process_runner_review.get("runtime_policy_start_enabled") is True
        and guarded_start_process_runner_review.get("guarded_start_executor_enabled") is False
        and guarded_start_process_runner_review.get("guarded_start_executor_implemented") is False
        and guarded_start_process_runner_review.get("final_start_executor_enabled") is False
        and guarded_start_process_runner_review.get("real_start_adapter_enabled") is False
        and guarded_start_process_runner_review.get("start_execution_allowed") is False
        and guarded_start_process_runner_review.get("real_subprocess_start_implemented") is False
        and guarded_start_process_runner_review.get("process_launch_attempted") is False
        and guarded_start_process_runner_review.get("daemon_started") is False
        and guarded_start_process_runner_review.get("process_launch_allowed") is False
        and guarded_start_process_runner_review.get("subprocess_module_imported") is False
        and guarded_start_process_runner_review.get("livekit_sdk_imported") is False
        and guarded_start_process_runner_review.get("provider_calls_made") is False
        and guarded_start_process_runner_review.get("tool_calls_made") is False
        and guarded_start_process_runner_review.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_PROCESS_RUNNER_PACKET_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_process_runner_packet"
        and authorization.get("process_runner_packet_allowed") is True
        and authorization.get("process_runner_review_attached") is True
        and authorization.get("runner_packet_only") is True
        and authorization.get("decision_receipt_attached") is True
        and authorization.get("argv_env_cwd_redacted") is True
        and authorization.get("pid_guard_attached") is True
        and authorization.get("startup_timeout_attached") is True
        and authorization.get("ready_event_attached") is True
        and authorization.get("stdout_stderr_sanitizers_attached") is True
        and authorization.get("rollback_attached") is True
        and authorization.get("observability_attached") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_process_runner_execution_review = (
        process_runner_review_ready and authorization_ready
    )

    return validate_guarded_start_process_runner_packet_packet({
        "schema_version": GUARDED_START_PROCESS_RUNNER_PACKET_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_process_runner_execution_review"
            if ready_for_guarded_start_process_runner_execution_review
            else "blocked"
        ),
        "implementation_status": "guarded_start_process_runner_packet_attached_without_process_start",
        "guarded_start_process_runner_packet_implemented": True,
        "process_runner_packet_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_process_runner_execution_review,
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
        "guarded_start_process_runner_review_status": guarded_start_process_runner_review.get("status"),
        "attached_process_runner_packet_controls": [
            "process_runner_review_before_runner_packet",
            "decision_receipt_bound_to_runner_packet",
            "argv_env_cwd_redacted_before_runner_execution_review",
            "pid_timeout_ready_event_attached_before_runner_execution_review",
            "stream_sanitization_rollback_observability_attached_before_runner_execution_review",
        ],
        "gates": {
            "guarded_start_process_runner_review_ready": process_runner_review_ready,
            "process_runner_packet_authorization_ready": authorization_ready,
            "process_runner_packet_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_process_runner_execution_review,
            "process_runner_review_attached": authorization.get("process_runner_review_attached") is True,
            "runner_packet_only": authorization.get("runner_packet_only") is True,
            "decision_receipt_attached": authorization.get("decision_receipt_attached") is True,
            "argv_env_cwd_redacted": authorization.get("argv_env_cwd_redacted") is True,
            "pid_guard_attached": authorization.get("pid_guard_attached") is True,
            "startup_timeout_attached": authorization.get("startup_timeout_attached") is True,
            "ready_event_attached": authorization.get("ready_event_attached") is True,
            "stdout_stderr_sanitizers_attached": authorization.get("stdout_stderr_sanitizers_attached") is True,
            "rollback_attached": authorization.get("rollback_attached") is True,
            "observability_attached": authorization.get("observability_attached") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_process_runner_packet",
            "import_subprocess_from_process_runner_packet",
            "skip_guarded_start_process_runner_execution_review",
            "call_provider_from_guarded_start_process_runner_packet",
            "persist_raw_audio_from_guarded_start_process_runner_packet",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_PACKET_ATTACHED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_process_runner_execution_review"
            if ready_for_guarded_start_process_runner_execution_review
            else "fix_guarded_start_process_runner_packet_prerequisites"
        ),
    })


def inspect_guarded_start_process_runner_execution_review(
    *,
    guarded_start_process_runner_packet: Mapping[str, Any],
    process_runner_execution_review_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Review the runner execution packet without importing or starting."""

    authorization = process_runner_execution_review_authorization or {}
    process_runner_packet_ready = (
        guarded_start_process_runner_packet.get("schema_version")
        == GUARDED_START_PROCESS_RUNNER_PACKET_SCHEMA_VERSION
        and guarded_start_process_runner_packet.get("status")
        == "ready_for_guarded_start_process_runner_execution_review"
        and guarded_start_process_runner_packet.get("guarded_start_process_runner_packet_implemented") is True
        and guarded_start_process_runner_packet.get("process_runner_packet_only") is True
        and guarded_start_process_runner_packet.get("runtime_policy_start_enabled") is True
        and guarded_start_process_runner_packet.get("guarded_start_executor_enabled") is False
        and guarded_start_process_runner_packet.get("guarded_start_executor_implemented") is False
        and guarded_start_process_runner_packet.get("final_start_executor_enabled") is False
        and guarded_start_process_runner_packet.get("real_start_adapter_enabled") is False
        and guarded_start_process_runner_packet.get("start_execution_allowed") is False
        and guarded_start_process_runner_packet.get("real_subprocess_start_implemented") is False
        and guarded_start_process_runner_packet.get("process_launch_attempted") is False
        and guarded_start_process_runner_packet.get("daemon_started") is False
        and guarded_start_process_runner_packet.get("process_launch_allowed") is False
        and guarded_start_process_runner_packet.get("subprocess_module_imported") is False
        and guarded_start_process_runner_packet.get("livekit_sdk_imported") is False
        and guarded_start_process_runner_packet.get("provider_calls_made") is False
        and guarded_start_process_runner_packet.get("tool_calls_made") is False
        and guarded_start_process_runner_packet.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_PROCESS_RUNNER_EXECUTION_REVIEW_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_process_runner_execution_review"
        and authorization.get("process_runner_execution_review_allowed") is True
        and authorization.get("process_runner_packet_attached") is True
        and authorization.get("technical_review_completed") is True
        and authorization.get("decision_receipt_reviewed") is True
        and authorization.get("argv_env_cwd_reviewed") is True
        and authorization.get("pid_guard_reviewed") is True
        and authorization.get("startup_timeout_reviewed") is True
        and authorization.get("ready_event_reviewed") is True
        and authorization.get("stdout_stderr_sanitizers_reviewed") is True
        and authorization.get("rollback_reviewed") is True
        and authorization.get("observability_reviewed") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_process_runner_execution_contract = (
        process_runner_packet_ready and authorization_ready
    )

    return validate_guarded_start_process_runner_execution_review_packet({
        "schema_version": GUARDED_START_PROCESS_RUNNER_EXECUTION_REVIEW_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_process_runner_execution_contract"
            if ready_for_guarded_start_process_runner_execution_contract
            else "blocked"
        ),
        "implementation_status": "guarded_start_process_runner_execution_review_completed_without_process_start",
        "guarded_start_process_runner_execution_review_implemented": True,
        "process_runner_execution_review_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_process_runner_execution_contract,
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
        "guarded_start_process_runner_packet_status": guarded_start_process_runner_packet.get("status"),
        "reviewed_process_runner_execution_controls": [
            "process_runner_packet_before_execution_review",
            "decision_receipt_reviewed_before_execution_contract",
            "argv_env_cwd_reviewed_before_execution_contract",
            "pid_timeout_ready_event_reviewed_before_execution_contract",
            "stream_sanitization_rollback_observability_reviewed_before_execution_contract",
        ],
        "gates": {
            "guarded_start_process_runner_packet_ready": process_runner_packet_ready,
            "process_runner_execution_review_authorization_ready": authorization_ready,
            "process_runner_execution_review_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_process_runner_execution_contract,
            "process_runner_packet_attached": authorization.get("process_runner_packet_attached") is True,
            "technical_review_completed": authorization.get("technical_review_completed") is True,
            "decision_receipt_reviewed": authorization.get("decision_receipt_reviewed") is True,
            "argv_env_cwd_reviewed": authorization.get("argv_env_cwd_reviewed") is True,
            "pid_guard_reviewed": authorization.get("pid_guard_reviewed") is True,
            "startup_timeout_reviewed": authorization.get("startup_timeout_reviewed") is True,
            "ready_event_reviewed": authorization.get("ready_event_reviewed") is True,
            "stdout_stderr_sanitizers_reviewed": authorization.get("stdout_stderr_sanitizers_reviewed") is True,
            "rollback_reviewed": authorization.get("rollback_reviewed") is True,
            "observability_reviewed": authorization.get("observability_reviewed") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_process_runner_execution_review",
            "import_subprocess_from_process_runner_execution_review",
            "skip_guarded_start_process_runner_execution_contract",
            "call_provider_from_guarded_start_process_runner_execution_review",
            "persist_raw_audio_from_guarded_start_process_runner_execution_review",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_EXECUTION_REVIEWED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_process_runner_execution_contract"
            if ready_for_guarded_start_process_runner_execution_contract
            else "fix_guarded_start_process_runner_execution_review_prerequisites"
        ),
    })


def inspect_guarded_start_process_runner_execution_contract(
    *,
    guarded_start_process_runner_execution_review: Mapping[str, Any],
    process_runner_execution_contract_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Bind the reviewed runner packet into an execution contract without starting."""

    authorization = process_runner_execution_contract_authorization or {}
    execution_review_ready = (
        guarded_start_process_runner_execution_review.get("schema_version")
        == GUARDED_START_PROCESS_RUNNER_EXECUTION_REVIEW_SCHEMA_VERSION
        and guarded_start_process_runner_execution_review.get("status")
        == "ready_for_guarded_start_process_runner_execution_contract"
        and guarded_start_process_runner_execution_review.get(
            "guarded_start_process_runner_execution_review_implemented"
        ) is True
        and guarded_start_process_runner_execution_review.get("process_runner_execution_review_only") is True
        and guarded_start_process_runner_execution_review.get("runtime_policy_start_enabled") is True
        and guarded_start_process_runner_execution_review.get("guarded_start_executor_enabled") is False
        and guarded_start_process_runner_execution_review.get("guarded_start_executor_implemented") is False
        and guarded_start_process_runner_execution_review.get("final_start_executor_enabled") is False
        and guarded_start_process_runner_execution_review.get("real_start_adapter_enabled") is False
        and guarded_start_process_runner_execution_review.get("start_execution_allowed") is False
        and guarded_start_process_runner_execution_review.get("real_subprocess_start_implemented") is False
        and guarded_start_process_runner_execution_review.get("process_launch_attempted") is False
        and guarded_start_process_runner_execution_review.get("daemon_started") is False
        and guarded_start_process_runner_execution_review.get("process_launch_allowed") is False
        and guarded_start_process_runner_execution_review.get("subprocess_module_imported") is False
        and guarded_start_process_runner_execution_review.get("livekit_sdk_imported") is False
        and guarded_start_process_runner_execution_review.get("provider_calls_made") is False
        and guarded_start_process_runner_execution_review.get("tool_calls_made") is False
        and guarded_start_process_runner_execution_review.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version")
        == GUARDED_START_PROCESS_RUNNER_EXECUTION_CONTRACT_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_process_runner_execution_contract"
        and authorization.get("process_runner_execution_contract_allowed") is True
        and authorization.get("process_runner_execution_review_attached") is True
        and authorization.get("execution_contract_only") is True
        and authorization.get("decision_receipt_bound") is True
        and authorization.get("argv_env_cwd_bound") is True
        and authorization.get("pid_guard_bound") is True
        and authorization.get("startup_timeout_bound") is True
        and authorization.get("ready_event_bound") is True
        and authorization.get("stdout_stderr_sanitizers_bound") is True
        and authorization.get("rollback_bound") is True
        and authorization.get("observability_bound") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_process_runner_start_gate = execution_review_ready and authorization_ready

    return validate_guarded_start_process_runner_execution_contract_packet({
        "schema_version": GUARDED_START_PROCESS_RUNNER_EXECUTION_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_process_runner_start_gate"
            if ready_for_guarded_start_process_runner_start_gate
            else "blocked"
        ),
        "implementation_status": "guarded_start_process_runner_execution_contract_bound_without_process_start",
        "guarded_start_process_runner_execution_contract_implemented": True,
        "process_runner_execution_contract_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_process_runner_start_gate,
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
        "guarded_start_process_runner_execution_review_status": (
            guarded_start_process_runner_execution_review.get("status")
        ),
        "bound_process_runner_execution_controls": [
            "process_runner_execution_review_before_execution_contract",
            "decision_receipt_bound_before_start_gate",
            "argv_env_cwd_bound_before_start_gate",
            "pid_timeout_ready_event_bound_before_start_gate",
            "stream_sanitization_rollback_observability_bound_before_start_gate",
        ],
        "gates": {
            "guarded_start_process_runner_execution_review_ready": execution_review_ready,
            "process_runner_execution_contract_authorization_ready": authorization_ready,
            "process_runner_execution_contract_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_process_runner_start_gate,
            "process_runner_execution_review_attached": (
                authorization.get("process_runner_execution_review_attached") is True
            ),
            "execution_contract_only": authorization.get("execution_contract_only") is True,
            "decision_receipt_bound": authorization.get("decision_receipt_bound") is True,
            "argv_env_cwd_bound": authorization.get("argv_env_cwd_bound") is True,
            "pid_guard_bound": authorization.get("pid_guard_bound") is True,
            "startup_timeout_bound": authorization.get("startup_timeout_bound") is True,
            "ready_event_bound": authorization.get("ready_event_bound") is True,
            "stdout_stderr_sanitizers_bound": authorization.get("stdout_stderr_sanitizers_bound") is True,
            "rollback_bound": authorization.get("rollback_bound") is True,
            "observability_bound": authorization.get("observability_bound") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_process_runner_execution_contract",
            "import_subprocess_from_process_runner_execution_contract",
            "skip_guarded_start_process_runner_start_gate",
            "call_provider_from_guarded_start_process_runner_execution_contract",
            "persist_raw_audio_from_guarded_start_process_runner_execution_contract",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_EXECUTION_CONTRACT_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_process_runner_start_gate"
            if ready_for_guarded_start_process_runner_start_gate
            else "fix_guarded_start_process_runner_execution_contract_prerequisites"
        ),
    })


def inspect_guarded_start_process_runner_start_gate(
    *,
    guarded_start_process_runner_execution_contract: Mapping[str, Any],
    process_runner_start_gate_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Evaluate the runner start gate while still blocking process launch."""

    authorization = process_runner_start_gate_authorization or {}
    execution_contract_ready = (
        guarded_start_process_runner_execution_contract.get("schema_version")
        == GUARDED_START_PROCESS_RUNNER_EXECUTION_CONTRACT_SCHEMA_VERSION
        and guarded_start_process_runner_execution_contract.get("status")
        == "ready_for_guarded_start_process_runner_start_gate"
        and guarded_start_process_runner_execution_contract.get(
            "guarded_start_process_runner_execution_contract_implemented"
        ) is True
        and guarded_start_process_runner_execution_contract.get("process_runner_execution_contract_only") is True
        and guarded_start_process_runner_execution_contract.get("runtime_policy_start_enabled") is True
        and guarded_start_process_runner_execution_contract.get("guarded_start_executor_enabled") is False
        and guarded_start_process_runner_execution_contract.get("guarded_start_executor_implemented") is False
        and guarded_start_process_runner_execution_contract.get("final_start_executor_enabled") is False
        and guarded_start_process_runner_execution_contract.get("real_start_adapter_enabled") is False
        and guarded_start_process_runner_execution_contract.get("start_execution_allowed") is False
        and guarded_start_process_runner_execution_contract.get("real_subprocess_start_implemented") is False
        and guarded_start_process_runner_execution_contract.get("process_launch_attempted") is False
        and guarded_start_process_runner_execution_contract.get("daemon_started") is False
        and guarded_start_process_runner_execution_contract.get("process_launch_allowed") is False
        and guarded_start_process_runner_execution_contract.get("subprocess_module_imported") is False
        and guarded_start_process_runner_execution_contract.get("livekit_sdk_imported") is False
        and guarded_start_process_runner_execution_contract.get("provider_calls_made") is False
        and guarded_start_process_runner_execution_contract.get("tool_calls_made") is False
        and guarded_start_process_runner_execution_contract.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_PROCESS_RUNNER_START_GATE_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_process_runner_start_gate"
        and authorization.get("process_runner_start_gate_allowed") is True
        and authorization.get("process_runner_execution_contract_attached") is True
        and authorization.get("start_gate_only") is True
        and authorization.get("final_receipt_required") is True
        and authorization.get("single_start_required") is True
        and authorization.get("pid_guard_required") is True
        and authorization.get("startup_timeout_required") is True
        and authorization.get("ready_event_required") is True
        and authorization.get("stdout_stderr_sanitizers_required") is True
        and authorization.get("rollback_required") is True
        and authorization.get("observability_required") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
    )
    ready_for_guarded_start_process_runner_final_review = execution_contract_ready and authorization_ready

    return validate_guarded_start_process_runner_start_gate_packet({
        "schema_version": GUARDED_START_PROCESS_RUNNER_START_GATE_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_process_runner_final_review"
            if ready_for_guarded_start_process_runner_final_review
            else "blocked"
        ),
        "implementation_status": "guarded_start_process_runner_start_gate_evaluated_without_process_start",
        "guarded_start_process_runner_start_gate_implemented": True,
        "process_runner_start_gate_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_process_runner_final_review,
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
        "guarded_start_process_runner_execution_contract_status": (
            guarded_start_process_runner_execution_contract.get("status")
        ),
        "required_process_runner_start_gate_controls": [
            "process_runner_execution_contract_before_start_gate",
            "final_receipt_required_before_final_review",
            "single_start_required_before_final_review",
            "pid_timeout_ready_event_required_before_final_review",
            "stream_sanitization_rollback_observability_required_before_final_review",
        ],
        "gates": {
            "guarded_start_process_runner_execution_contract_ready": execution_contract_ready,
            "process_runner_start_gate_authorization_ready": authorization_ready,
            "process_runner_start_gate_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_process_runner_final_review,
            "process_runner_execution_contract_attached": (
                authorization.get("process_runner_execution_contract_attached") is True
            ),
            "start_gate_only": authorization.get("start_gate_only") is True,
            "final_receipt_required": authorization.get("final_receipt_required") is True,
            "single_start_required": authorization.get("single_start_required") is True,
            "pid_guard_required": authorization.get("pid_guard_required") is True,
            "startup_timeout_required": authorization.get("startup_timeout_required") is True,
            "ready_event_required": authorization.get("ready_event_required") is True,
            "stdout_stderr_sanitizers_required": authorization.get("stdout_stderr_sanitizers_required") is True,
            "rollback_required": authorization.get("rollback_required") is True,
            "observability_required": authorization.get("observability_required") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_process_runner_start_gate",
            "import_subprocess_from_process_runner_start_gate",
            "skip_guarded_start_process_runner_final_review",
            "call_provider_from_guarded_start_process_runner_start_gate",
            "persist_raw_audio_from_guarded_start_process_runner_start_gate",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_START_GATE_EVALUATED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_process_runner_final_review"
            if ready_for_guarded_start_process_runner_final_review
            else "fix_guarded_start_process_runner_start_gate_prerequisites"
        ),
    })


def inspect_guarded_start_process_runner_final_review(
    *,
    guarded_start_process_runner_start_gate: Mapping[str, Any],
    process_runner_final_review_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Review the final guarded process runner packet without launching it."""

    authorization = process_runner_final_review_authorization or {}
    start_gate_ready = (
        guarded_start_process_runner_start_gate.get("schema_version")
        == GUARDED_START_PROCESS_RUNNER_START_GATE_SCHEMA_VERSION
        and guarded_start_process_runner_start_gate.get("status")
        == "ready_for_guarded_start_process_runner_final_review"
        and guarded_start_process_runner_start_gate.get(
            "guarded_start_process_runner_start_gate_implemented"
        ) is True
        and guarded_start_process_runner_start_gate.get("process_runner_start_gate_only") is True
        and guarded_start_process_runner_start_gate.get("runtime_policy_start_enabled") is True
        and guarded_start_process_runner_start_gate.get("guarded_start_executor_enabled") is False
        and guarded_start_process_runner_start_gate.get("guarded_start_executor_implemented") is False
        and guarded_start_process_runner_start_gate.get("final_start_executor_enabled") is False
        and guarded_start_process_runner_start_gate.get("real_start_adapter_enabled") is False
        and guarded_start_process_runner_start_gate.get("start_execution_allowed") is False
        and guarded_start_process_runner_start_gate.get("real_subprocess_start_implemented") is False
        and guarded_start_process_runner_start_gate.get("process_launch_attempted") is False
        and guarded_start_process_runner_start_gate.get("daemon_started") is False
        and guarded_start_process_runner_start_gate.get("process_launch_allowed") is False
        and guarded_start_process_runner_start_gate.get("subprocess_module_imported") is False
        and guarded_start_process_runner_start_gate.get("livekit_sdk_imported") is False
        and guarded_start_process_runner_start_gate.get("provider_calls_made") is False
        and guarded_start_process_runner_start_gate.get("tool_calls_made") is False
        and guarded_start_process_runner_start_gate.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_PROCESS_RUNNER_FINAL_REVIEW_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_process_runner_final_review"
        and authorization.get("process_runner_final_review_allowed") is True
        and authorization.get("process_runner_start_gate_attached") is True
        and authorization.get("final_review_only") is True
        and authorization.get("final_receipt_attached") is True
        and authorization.get("single_start_verified") is True
        and authorization.get("pid_guard_reviewed") is True
        and authorization.get("startup_timeout_reviewed") is True
        and authorization.get("ready_event_reviewed") is True
        and authorization.get("stdout_stderr_sanitizers_reviewed") is True
        and authorization.get("rollback_reviewed") is True
        and authorization.get("observability_reviewed") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
        and isinstance(authorization.get("final_review_receipt_id"), str)
        and authorization.get("final_review_receipt_id") != ""
    )
    ready_for_guarded_start_process_runner_promotion_packet = start_gate_ready and authorization_ready

    return validate_guarded_start_process_runner_final_review_packet({
        "schema_version": GUARDED_START_PROCESS_RUNNER_FINAL_REVIEW_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_process_runner_promotion_packet"
            if ready_for_guarded_start_process_runner_promotion_packet
            else "blocked"
        ),
        "implementation_status": "guarded_start_process_runner_final_review_without_process_start",
        "guarded_start_process_runner_final_review_implemented": True,
        "process_runner_final_review_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_process_runner_promotion_packet,
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
        "final_review_receipt_id": authorization.get("final_review_receipt_id") if authorization_ready else None,
        "guarded_start_process_runner_start_gate_status": guarded_start_process_runner_start_gate.get("status"),
        "required_process_runner_final_review_controls": [
            "process_runner_start_gate_before_final_review",
            "final_review_receipt_before_promotion_packet",
            "single_start_verified_before_promotion_packet",
            "pid_timeout_ready_event_reviewed_before_promotion_packet",
            "stream_sanitization_rollback_observability_reviewed_before_promotion_packet",
        ],
        "gates": {
            "guarded_start_process_runner_start_gate_ready": start_gate_ready,
            "process_runner_final_review_authorization_ready": authorization_ready,
            "process_runner_final_review_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_process_runner_promotion_packet,
            "process_runner_start_gate_attached": authorization.get("process_runner_start_gate_attached") is True,
            "final_review_only": authorization.get("final_review_only") is True,
            "final_receipt_attached": authorization.get("final_receipt_attached") is True,
            "single_start_verified": authorization.get("single_start_verified") is True,
            "pid_guard_reviewed": authorization.get("pid_guard_reviewed") is True,
            "startup_timeout_reviewed": authorization.get("startup_timeout_reviewed") is True,
            "ready_event_reviewed": authorization.get("ready_event_reviewed") is True,
            "stdout_stderr_sanitizers_reviewed": authorization.get("stdout_stderr_sanitizers_reviewed") is True,
            "rollback_reviewed": authorization.get("rollback_reviewed") is True,
            "observability_reviewed": authorization.get("observability_reviewed") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_process_runner_final_review",
            "import_subprocess_from_process_runner_final_review",
            "skip_guarded_start_process_runner_promotion_packet",
            "call_provider_from_guarded_start_process_runner_final_review",
            "persist_raw_audio_from_guarded_start_process_runner_final_review",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_FINAL_REVIEWED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_process_runner_promotion_packet"
            if ready_for_guarded_start_process_runner_promotion_packet
            else "fix_guarded_start_process_runner_final_review_prerequisites"
        ),
    })


def inspect_guarded_start_process_runner_promotion_packet(
    *,
    guarded_start_process_runner_final_review: Mapping[str, Any],
    process_runner_promotion_packet_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Attach the final promotion packet while still blocking process launch."""

    authorization = process_runner_promotion_packet_authorization or {}
    final_review_ready = (
        guarded_start_process_runner_final_review.get("schema_version")
        == GUARDED_START_PROCESS_RUNNER_FINAL_REVIEW_SCHEMA_VERSION
        and guarded_start_process_runner_final_review.get("status")
        == "ready_for_guarded_start_process_runner_promotion_packet"
        and guarded_start_process_runner_final_review.get(
            "guarded_start_process_runner_final_review_implemented"
        ) is True
        and guarded_start_process_runner_final_review.get("process_runner_final_review_only") is True
        and guarded_start_process_runner_final_review.get("runtime_policy_start_enabled") is True
        and guarded_start_process_runner_final_review.get("guarded_start_executor_enabled") is False
        and guarded_start_process_runner_final_review.get("guarded_start_executor_implemented") is False
        and guarded_start_process_runner_final_review.get("final_start_executor_enabled") is False
        and guarded_start_process_runner_final_review.get("real_start_adapter_enabled") is False
        and guarded_start_process_runner_final_review.get("start_execution_allowed") is False
        and guarded_start_process_runner_final_review.get("real_subprocess_start_implemented") is False
        and guarded_start_process_runner_final_review.get("process_launch_attempted") is False
        and guarded_start_process_runner_final_review.get("daemon_started") is False
        and guarded_start_process_runner_final_review.get("process_launch_allowed") is False
        and guarded_start_process_runner_final_review.get("subprocess_module_imported") is False
        and guarded_start_process_runner_final_review.get("livekit_sdk_imported") is False
        and guarded_start_process_runner_final_review.get("provider_calls_made") is False
        and guarded_start_process_runner_final_review.get("tool_calls_made") is False
        and guarded_start_process_runner_final_review.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version") == GUARDED_START_PROCESS_RUNNER_PROMOTION_PACKET_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_process_runner_promotion_packet"
        and authorization.get("process_runner_promotion_packet_allowed") is True
        and authorization.get("process_runner_final_review_attached") is True
        and authorization.get("promotion_packet_only") is True
        and authorization.get("final_review_receipt_attached") is True
        and authorization.get("bundle_hash_attached") is True
        and authorization.get("evidence_manifest_attached") is True
        and authorization.get("rollback_plan_attached") is True
        and authorization.get("operator_release_review_required") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
        and isinstance(authorization.get("promotion_packet_receipt_id"), str)
        and authorization.get("promotion_packet_receipt_id") != ""
        and isinstance(authorization.get("reviewed_bundle_hash"), str)
        and len(authorization.get("reviewed_bundle_hash")) == 64
    )
    ready_for_guarded_start_process_runner_operator_release = final_review_ready and authorization_ready

    return validate_guarded_start_process_runner_promotion_packet_packet({
        "schema_version": GUARDED_START_PROCESS_RUNNER_PROMOTION_PACKET_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_process_runner_operator_release"
            if ready_for_guarded_start_process_runner_operator_release
            else "blocked"
        ),
        "implementation_status": "guarded_start_process_runner_promotion_packet_without_process_start",
        "guarded_start_process_runner_promotion_packet_implemented": True,
        "process_runner_promotion_packet_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_process_runner_operator_release,
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
        "promotion_packet_receipt_id": (
            authorization.get("promotion_packet_receipt_id")
            if authorization_ready
            else None
        ),
        "reviewed_bundle_hash": authorization.get("reviewed_bundle_hash") if authorization_ready else None,
        "guarded_start_process_runner_final_review_status": (
            guarded_start_process_runner_final_review.get("status")
        ),
        "required_process_runner_promotion_packet_controls": [
            "process_runner_final_review_before_promotion_packet",
            "promotion_packet_receipt_before_operator_release",
            "bundle_hash_before_operator_release",
            "evidence_manifest_before_operator_release",
            "rollback_plan_before_operator_release",
            "operator_release_review_before_any_start",
        ],
        "gates": {
            "guarded_start_process_runner_final_review_ready": final_review_ready,
            "process_runner_promotion_packet_authorization_ready": authorization_ready,
            "process_runner_promotion_packet_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_process_runner_operator_release,
            "process_runner_final_review_attached": (
                authorization.get("process_runner_final_review_attached") is True
            ),
            "promotion_packet_only": authorization.get("promotion_packet_only") is True,
            "final_review_receipt_attached": authorization.get("final_review_receipt_attached") is True,
            "bundle_hash_attached": authorization.get("bundle_hash_attached") is True,
            "evidence_manifest_attached": authorization.get("evidence_manifest_attached") is True,
            "rollback_plan_attached": authorization.get("rollback_plan_attached") is True,
            "operator_release_review_required": (
                authorization.get("operator_release_review_required") is True
            ),
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_process_runner_promotion_packet",
            "import_subprocess_from_process_runner_promotion_packet",
            "skip_operator_release_review",
            "call_provider_from_guarded_start_process_runner_promotion_packet",
            "persist_raw_audio_from_guarded_start_process_runner_promotion_packet",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_PROMOTION_PACKET_ATTACHED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_process_runner_operator_release_review"
            if ready_for_guarded_start_process_runner_operator_release
            else "fix_guarded_start_process_runner_promotion_packet_prerequisites"
        ),
    })


def inspect_guarded_start_process_runner_operator_release_review(
    *,
    guarded_start_process_runner_promotion_packet: Mapping[str, Any],
    operator_release_review_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Attach operator release review while still blocking process launch."""

    authorization = operator_release_review_authorization or {}
    promotion_packet_ready = (
        guarded_start_process_runner_promotion_packet.get("schema_version")
        == GUARDED_START_PROCESS_RUNNER_PROMOTION_PACKET_SCHEMA_VERSION
        and guarded_start_process_runner_promotion_packet.get("status")
        == "ready_for_guarded_start_process_runner_operator_release"
        and guarded_start_process_runner_promotion_packet.get(
            "guarded_start_process_runner_promotion_packet_implemented"
        ) is True
        and guarded_start_process_runner_promotion_packet.get("process_runner_promotion_packet_only") is True
        and guarded_start_process_runner_promotion_packet.get("runtime_policy_start_enabled") is True
        and guarded_start_process_runner_promotion_packet.get("guarded_start_executor_enabled") is False
        and guarded_start_process_runner_promotion_packet.get("guarded_start_executor_implemented") is False
        and guarded_start_process_runner_promotion_packet.get("final_start_executor_enabled") is False
        and guarded_start_process_runner_promotion_packet.get("real_start_adapter_enabled") is False
        and guarded_start_process_runner_promotion_packet.get("start_execution_allowed") is False
        and guarded_start_process_runner_promotion_packet.get("real_subprocess_start_implemented") is False
        and guarded_start_process_runner_promotion_packet.get("process_launch_attempted") is False
        and guarded_start_process_runner_promotion_packet.get("daemon_started") is False
        and guarded_start_process_runner_promotion_packet.get("process_launch_allowed") is False
        and guarded_start_process_runner_promotion_packet.get("subprocess_module_imported") is False
        and guarded_start_process_runner_promotion_packet.get("livekit_sdk_imported") is False
        and guarded_start_process_runner_promotion_packet.get("provider_calls_made") is False
        and guarded_start_process_runner_promotion_packet.get("tool_calls_made") is False
        and guarded_start_process_runner_promotion_packet.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version")
        == GUARDED_START_PROCESS_RUNNER_OPERATOR_RELEASE_REVIEW_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_process_runner_operator_release_review"
        and authorization.get("operator_release_review_allowed") is True
        and authorization.get("process_runner_promotion_packet_attached") is True
        and authorization.get("operator_release_review_only") is True
        and authorization.get("operator_review_completed") is True
        and authorization.get("release_candidate_owner_attached") is True
        and authorization.get("promotion_packet_receipt_attached") is True
        and authorization.get("bundle_hash_confirmed") is True
        and authorization.get("evidence_manifest_reviewed") is True
        and authorization.get("rollback_plan_reviewed") is True
        and authorization.get("final_operator_release_required") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
        and isinstance(authorization.get("operator_release_review_receipt_id"), str)
        and authorization.get("operator_release_review_receipt_id") != ""
        and isinstance(authorization.get("reviewed_bundle_hash"), str)
        and len(authorization.get("reviewed_bundle_hash")) == 64
    )
    ready_for_guarded_start_process_runner_release_finalization = (
        promotion_packet_ready and authorization_ready
    )

    return validate_guarded_start_process_runner_operator_release_review_packet({
        "schema_version": GUARDED_START_PROCESS_RUNNER_OPERATOR_RELEASE_REVIEW_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_process_runner_release_finalization"
            if ready_for_guarded_start_process_runner_release_finalization
            else "blocked"
        ),
        "implementation_status": "guarded_start_process_runner_operator_release_review_without_process_start",
        "guarded_start_process_runner_operator_release_review_implemented": True,
        "process_runner_operator_release_review_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_process_runner_release_finalization,
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
        "operator_release_review_receipt_id": (
            authorization.get("operator_release_review_receipt_id")
            if authorization_ready
            else None
        ),
        "reviewed_bundle_hash": authorization.get("reviewed_bundle_hash") if authorization_ready else None,
        "guarded_start_process_runner_promotion_packet_status": (
            guarded_start_process_runner_promotion_packet.get("status")
        ),
        "required_process_runner_operator_release_review_controls": [
            "promotion_packet_before_operator_release_review",
            "operator_review_completed_before_release_finalization",
            "bundle_hash_confirmed_before_release_finalization",
            "evidence_manifest_reviewed_before_release_finalization",
            "rollback_plan_reviewed_before_release_finalization",
            "final_operator_release_before_any_start",
        ],
        "gates": {
            "guarded_start_process_runner_promotion_packet_ready": promotion_packet_ready,
            "operator_release_review_authorization_ready": authorization_ready,
            "process_runner_operator_release_review_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_process_runner_release_finalization,
            "process_runner_promotion_packet_attached": (
                authorization.get("process_runner_promotion_packet_attached") is True
            ),
            "operator_release_review_only": authorization.get("operator_release_review_only") is True,
            "operator_review_completed": authorization.get("operator_review_completed") is True,
            "release_candidate_owner_attached": (
                authorization.get("release_candidate_owner_attached") is True
            ),
            "promotion_packet_receipt_attached": (
                authorization.get("promotion_packet_receipt_attached") is True
            ),
            "bundle_hash_confirmed": authorization.get("bundle_hash_confirmed") is True,
            "evidence_manifest_reviewed": authorization.get("evidence_manifest_reviewed") is True,
            "rollback_plan_reviewed": authorization.get("rollback_plan_reviewed") is True,
            "final_operator_release_required": (
                authorization.get("final_operator_release_required") is True
            ),
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_process_runner_operator_release_review",
            "import_subprocess_from_operator_release_review",
            "skip_final_operator_release",
            "call_provider_from_guarded_start_process_runner_operator_release_review",
            "persist_raw_audio_from_guarded_start_process_runner_operator_release_review",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_OPERATOR_RELEASE_REVIEWED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_process_runner_release_finalization"
            if ready_for_guarded_start_process_runner_release_finalization
            else "fix_guarded_start_process_runner_operator_release_review_prerequisites"
        ),
    })


def inspect_guarded_start_process_runner_release_finalization(
    *,
    guarded_start_process_runner_operator_release_review: Mapping[str, Any],
    release_finalization_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Finalize the operator release packet without authorizing launch."""

    authorization = release_finalization_authorization or {}
    operator_release_review_ready = (
        guarded_start_process_runner_operator_release_review.get("schema_version")
        == GUARDED_START_PROCESS_RUNNER_OPERATOR_RELEASE_REVIEW_SCHEMA_VERSION
        and guarded_start_process_runner_operator_release_review.get("status")
        == "ready_for_guarded_start_process_runner_release_finalization"
        and guarded_start_process_runner_operator_release_review.get(
            "guarded_start_process_runner_operator_release_review_implemented"
        ) is True
        and guarded_start_process_runner_operator_release_review.get(
            "process_runner_operator_release_review_only"
        ) is True
        and guarded_start_process_runner_operator_release_review.get("runtime_policy_start_enabled") is True
        and guarded_start_process_runner_operator_release_review.get("guarded_start_executor_enabled") is False
        and guarded_start_process_runner_operator_release_review.get("guarded_start_executor_implemented") is False
        and guarded_start_process_runner_operator_release_review.get("final_start_executor_enabled") is False
        and guarded_start_process_runner_operator_release_review.get("real_start_adapter_enabled") is False
        and guarded_start_process_runner_operator_release_review.get("start_execution_allowed") is False
        and guarded_start_process_runner_operator_release_review.get("real_subprocess_start_implemented") is False
        and guarded_start_process_runner_operator_release_review.get("process_launch_attempted") is False
        and guarded_start_process_runner_operator_release_review.get("daemon_started") is False
        and guarded_start_process_runner_operator_release_review.get("process_launch_allowed") is False
        and guarded_start_process_runner_operator_release_review.get("subprocess_module_imported") is False
        and guarded_start_process_runner_operator_release_review.get("livekit_sdk_imported") is False
        and guarded_start_process_runner_operator_release_review.get("provider_calls_made") is False
        and guarded_start_process_runner_operator_release_review.get("tool_calls_made") is False
        and guarded_start_process_runner_operator_release_review.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version")
        == GUARDED_START_PROCESS_RUNNER_RELEASE_FINALIZATION_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_process_runner_release_finalization"
        and authorization.get("release_finalization_allowed") is True
        and authorization.get("operator_release_review_attached") is True
        and authorization.get("release_finalization_only") is True
        and authorization.get("final_operator_release_receipt_attached") is True
        and authorization.get("single_start_bound") is True
        and authorization.get("release_window_attached") is True
        and authorization.get("revoke_plan_attached") is True
        and authorization.get("post_release_review_required") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
        and isinstance(authorization.get("final_operator_release_receipt_id"), str)
        and authorization.get("final_operator_release_receipt_id") != ""
        and isinstance(authorization.get("release_bundle_hash"), str)
        and len(authorization.get("release_bundle_hash")) == 64
    )
    ready_for_guarded_start_process_runner_release_authorization = (
        operator_release_review_ready and authorization_ready
    )

    return validate_guarded_start_process_runner_release_finalization_packet({
        "schema_version": GUARDED_START_PROCESS_RUNNER_RELEASE_FINALIZATION_SCHEMA_VERSION,
        "status": (
            "ready_for_guarded_start_process_runner_release_authorization"
            if ready_for_guarded_start_process_runner_release_authorization
            else "blocked"
        ),
        "implementation_status": "guarded_start_process_runner_release_finalization_without_process_start",
        "guarded_start_process_runner_release_finalization_implemented": True,
        "process_runner_release_finalization_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_guarded_start_process_runner_release_authorization,
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
        "final_operator_release_receipt_id": (
            authorization.get("final_operator_release_receipt_id")
            if authorization_ready
            else None
        ),
        "release_bundle_hash": authorization.get("release_bundle_hash") if authorization_ready else None,
        "guarded_start_process_runner_operator_release_review_status": (
            guarded_start_process_runner_operator_release_review.get("status")
        ),
        "required_process_runner_release_finalization_controls": [
            "operator_release_review_before_release_finalization",
            "final_operator_release_receipt_before_release_authorization",
            "single_start_binding_before_release_authorization",
            "release_window_before_release_authorization",
            "revoke_plan_before_release_authorization",
            "post_release_review_before_any_start",
        ],
        "gates": {
            "guarded_start_process_runner_operator_release_review_ready": operator_release_review_ready,
            "release_finalization_authorization_ready": authorization_ready,
            "process_runner_release_finalization_only": True,
            "runtime_policy_start_enabled": ready_for_guarded_start_process_runner_release_authorization,
            "operator_release_review_attached": authorization.get("operator_release_review_attached") is True,
            "release_finalization_only": authorization.get("release_finalization_only") is True,
            "final_operator_release_receipt_attached": (
                authorization.get("final_operator_release_receipt_attached") is True
            ),
            "single_start_bound": authorization.get("single_start_bound") is True,
            "release_window_attached": authorization.get("release_window_attached") is True,
            "revoke_plan_attached": authorization.get("revoke_plan_attached") is True,
            "post_release_review_required": authorization.get("post_release_review_required") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_process_runner_release_finalization",
            "import_subprocess_from_release_finalization",
            "skip_release_authorization",
            "call_provider_from_guarded_start_process_runner_release_finalization",
            "persist_raw_audio_from_guarded_start_process_runner_release_finalization",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_RELEASE_FINALIZED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "implement_guarded_start_process_runner_release_authorization"
            if ready_for_guarded_start_process_runner_release_authorization
            else "fix_guarded_start_process_runner_release_finalization_prerequisites"
        ),
    })


def inspect_guarded_start_process_runner_release_authorization(
    *,
    guarded_start_process_runner_release_finalization: Mapping[str, Any],
    release_authorization: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Authorize the reviewed release boundary without authorizing process launch."""

    authorization = release_authorization or {}
    release_finalization_ready = (
        guarded_start_process_runner_release_finalization.get("schema_version")
        == GUARDED_START_PROCESS_RUNNER_RELEASE_FINALIZATION_SCHEMA_VERSION
        and guarded_start_process_runner_release_finalization.get("status")
        == "ready_for_guarded_start_process_runner_release_authorization"
        and guarded_start_process_runner_release_finalization.get(
            "guarded_start_process_runner_release_finalization_implemented"
        ) is True
        and guarded_start_process_runner_release_finalization.get("process_runner_release_finalization_only") is True
        and guarded_start_process_runner_release_finalization.get("runtime_policy_start_enabled") is True
        and guarded_start_process_runner_release_finalization.get("guarded_start_executor_enabled") is False
        and guarded_start_process_runner_release_finalization.get("guarded_start_executor_implemented") is False
        and guarded_start_process_runner_release_finalization.get("final_start_executor_enabled") is False
        and guarded_start_process_runner_release_finalization.get("real_start_adapter_enabled") is False
        and guarded_start_process_runner_release_finalization.get("start_execution_allowed") is False
        and guarded_start_process_runner_release_finalization.get("real_subprocess_start_implemented") is False
        and guarded_start_process_runner_release_finalization.get("process_launch_attempted") is False
        and guarded_start_process_runner_release_finalization.get("daemon_started") is False
        and guarded_start_process_runner_release_finalization.get("process_launch_allowed") is False
        and guarded_start_process_runner_release_finalization.get("subprocess_module_imported") is False
        and guarded_start_process_runner_release_finalization.get("livekit_sdk_imported") is False
        and guarded_start_process_runner_release_finalization.get("provider_calls_made") is False
        and guarded_start_process_runner_release_finalization.get("tool_calls_made") is False
        and guarded_start_process_runner_release_finalization.get("raw_audio_touched") is False
    )
    authorization_ready = (
        authorization.get("schema_version")
        == GUARDED_START_PROCESS_RUNNER_RELEASE_AUTHORIZATION_SCHEMA_VERSION
        and authorization.get("status") == "approved_for_guarded_start_process_runner_release_authorization"
        and authorization.get("release_authorization_allowed") is True
        and authorization.get("release_finalization_attached") is True
        and authorization.get("release_authorization_only") is True
        and authorization.get("operator_final_release_attached") is True
        and authorization.get("single_start_bound") is True
        and authorization.get("release_window_validated") is True
        and authorization.get("revoke_plan_validated") is True
        and authorization.get("controlled_livekit_server_smoke_required") is True
        and authorization.get("worker_supervision_required") is True
        and authorization.get("post_release_review_required") is True
        and authorization.get("process_launch_allowed") is False
        and authorization.get("start_execution_allowed") is False
        and authorization.get("subprocess_module_import_allowed") is False
        and isinstance(authorization.get("decision_receipt_id"), str)
        and authorization.get("decision_receipt_id") != ""
        and isinstance(authorization.get("release_authorization_receipt_id"), str)
        and authorization.get("release_authorization_receipt_id") != ""
        and isinstance(authorization.get("release_bundle_hash"), str)
        and len(authorization.get("release_bundle_hash")) == 64
    )
    ready_for_controlled_livekit_server_smoke = release_finalization_ready and authorization_ready

    return validate_guarded_start_process_runner_release_authorization_packet({
        "schema_version": GUARDED_START_PROCESS_RUNNER_RELEASE_AUTHORIZATION_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_controlled_livekit_server_supervised_smoke"
            if ready_for_controlled_livekit_server_smoke
            else "blocked"
        ),
        "implementation_status": "guarded_start_process_runner_release_authorization_without_process_start",
        "guarded_start_process_runner_release_authorization_implemented": True,
        "process_runner_release_authorization_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_controlled_livekit_server_smoke,
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
        "release_authorization_receipt_id": (
            authorization.get("release_authorization_receipt_id")
            if authorization_ready
            else None
        ),
        "release_bundle_hash": authorization.get("release_bundle_hash") if authorization_ready else None,
        "guarded_start_process_runner_release_finalization_status": (
            guarded_start_process_runner_release_finalization.get("status")
        ),
        "required_process_runner_release_authorization_controls": [
            "release_finalization_before_release_authorization",
            "final_operator_release_before_release_authorization",
            "single_start_binding_before_controlled_smoke",
            "release_window_before_controlled_smoke",
            "revoke_plan_before_controlled_smoke",
            "controlled_livekit_server_smoke_before_any_daemon_start",
            "worker_supervision_before_any_daemon_start",
            "post_release_review_before_any_start",
        ],
        "gates": {
            "guarded_start_process_runner_release_finalization_ready": release_finalization_ready,
            "release_authorization_ready": authorization_ready,
            "process_runner_release_authorization_only": True,
            "runtime_policy_start_enabled": ready_for_controlled_livekit_server_smoke,
            "release_finalization_attached": authorization.get("release_finalization_attached") is True,
            "operator_final_release_attached": authorization.get("operator_final_release_attached") is True,
            "single_start_bound": authorization.get("single_start_bound") is True,
            "release_window_validated": authorization.get("release_window_validated") is True,
            "revoke_plan_validated": authorization.get("revoke_plan_validated") is True,
            "controlled_livekit_server_smoke_required": (
                authorization.get("controlled_livekit_server_smoke_required") is True
            ),
            "worker_supervision_required": authorization.get("worker_supervision_required") is True,
            "post_release_review_required": authorization.get("post_release_review_required") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_process_from_guarded_start_process_runner_release_authorization",
            "import_subprocess_from_release_authorization",
            "skip_controlled_livekit_server_smoke",
            "call_provider_from_guarded_start_process_runner_release_authorization",
            "persist_raw_audio_from_guarded_start_process_runner_release_authorization",
        ],
        "evidence_events": [
            "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_RELEASE_AUTHORIZED",
            "VOICE_DAEMON_CONTROLLED_LIVEKIT_SERVER_SMOKE_REQUIRED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "prepare_controlled_livekit_server_supervised_smoke_contract"
            if ready_for_controlled_livekit_server_smoke
            else "fix_guarded_start_process_runner_release_authorization_prerequisites"
        ),
    })


def inspect_controlled_livekit_server_supervised_smoke_contract(
    *,
    guarded_start_process_runner_release_authorization: Mapping[str, Any],
    controlled_smoke_plan: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Prepare the first LiveKit-server smoke boundary without launching it."""

    plan = controlled_smoke_plan or {}
    release_authorization_ready = (
        guarded_start_process_runner_release_authorization.get("schema_version")
        == GUARDED_START_PROCESS_RUNNER_RELEASE_AUTHORIZATION_CONTRACT_SCHEMA_VERSION
        and guarded_start_process_runner_release_authorization.get("status")
        == "ready_for_controlled_livekit_server_supervised_smoke"
        and guarded_start_process_runner_release_authorization.get(
            "guarded_start_process_runner_release_authorization_implemented"
        ) is True
        and guarded_start_process_runner_release_authorization.get("process_runner_release_authorization_only") is True
        and guarded_start_process_runner_release_authorization.get("runtime_policy_start_enabled") is True
        and guarded_start_process_runner_release_authorization.get("guarded_start_executor_enabled") is False
        and guarded_start_process_runner_release_authorization.get("guarded_start_executor_implemented") is False
        and guarded_start_process_runner_release_authorization.get("final_start_executor_enabled") is False
        and guarded_start_process_runner_release_authorization.get("real_start_adapter_enabled") is False
        and guarded_start_process_runner_release_authorization.get("start_execution_allowed") is False
        and guarded_start_process_runner_release_authorization.get("real_subprocess_start_implemented") is False
        and guarded_start_process_runner_release_authorization.get("process_launch_attempted") is False
        and guarded_start_process_runner_release_authorization.get("daemon_started") is False
        and guarded_start_process_runner_release_authorization.get("process_launch_allowed") is False
        and guarded_start_process_runner_release_authorization.get("subprocess_module_imported") is False
        and guarded_start_process_runner_release_authorization.get("livekit_sdk_imported") is False
        and guarded_start_process_runner_release_authorization.get("provider_calls_made") is False
        and guarded_start_process_runner_release_authorization.get("tool_calls_made") is False
        and guarded_start_process_runner_release_authorization.get("raw_audio_touched") is False
    )
    plan_ready = (
        plan.get("schema_version") == CONTROLLED_LIVEKIT_SERVER_SUPERVISED_SMOKE_PLAN_SCHEMA_VERSION
        and plan.get("status") == "approved_for_controlled_livekit_server_supervised_smoke_contract"
        and plan.get("controlled_smoke_contract_allowed") is True
        and plan.get("release_authorization_attached") is True
        and plan.get("controlled_smoke_only") is True
        and plan.get("local_livekit_server_configured") is True
        and plan.get("livekit_health_probe_defined") is True
        and plan.get("ephemeral_room_required") is True
        and plan.get("token_issuer_smoke_passed") is True
        and plan.get("worker_supervision_attached") is True
        and plan.get("stdout_stderr_sanitizers_required") is True
        and plan.get("post_smoke_cleanup_required") is True
        and plan.get("secrets_redacted") is True
        and plan.get("process_launch_allowed") is False
        and plan.get("start_execution_allowed") is False
        and plan.get("subprocess_module_import_allowed") is False
        and isinstance(plan.get("decision_receipt_id"), str)
        and plan.get("decision_receipt_id") != ""
        and isinstance(plan.get("controlled_smoke_receipt_id"), str)
        and plan.get("controlled_smoke_receipt_id") != ""
        and isinstance(plan.get("release_bundle_hash"), str)
        and len(plan.get("release_bundle_hash")) == 64
    )
    ready_for_supervised_voice_worker_handshake = release_authorization_ready and plan_ready

    return validate_controlled_livekit_server_supervised_smoke_contract_packet({
        "schema_version": CONTROLLED_LIVEKIT_SERVER_SUPERVISED_SMOKE_CONTRACT_SCHEMA_VERSION,
        "status": (
            "ready_for_supervised_voice_worker_handshake_smoke"
            if ready_for_supervised_voice_worker_handshake
            else "blocked"
        ),
        "implementation_status": "controlled_livekit_server_supervised_smoke_contract_without_process_start",
        "controlled_livekit_server_supervised_smoke_contract_implemented": True,
        "controlled_smoke_only": True,
        "guarded_start_executor_enabled": False,
        "guarded_start_executor_implemented": False,
        "final_start_executor_enabled": False,
        "runtime_policy_start_enabled": ready_for_supervised_voice_worker_handshake,
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
        "decision_receipt_id": plan.get("decision_receipt_id") if plan_ready else None,
        "controlled_smoke_receipt_id": plan.get("controlled_smoke_receipt_id") if plan_ready else None,
        "release_bundle_hash": plan.get("release_bundle_hash") if plan_ready else None,
        "guarded_start_process_runner_release_authorization_status": (
            guarded_start_process_runner_release_authorization.get("status")
        ),
        "required_controlled_smoke_controls": [
            "release_authorization_before_controlled_smoke",
            "local_livekit_server_config_before_smoke",
            "token_issuer_smoke_before_controlled_server_smoke",
            "ephemeral_room_for_controlled_smoke",
            "worker_supervision_before_worker_handshake",
            "stdout_stderr_sanitized_for_any_future_process",
            "post_smoke_cleanup_before_next_gate",
        ],
        "gates": {
            "guarded_start_process_runner_release_authorization_ready": release_authorization_ready,
            "controlled_smoke_plan_ready": plan_ready,
            "controlled_smoke_only": True,
            "runtime_policy_start_enabled": ready_for_supervised_voice_worker_handshake,
            "local_livekit_server_configured": plan.get("local_livekit_server_configured") is True,
            "livekit_health_probe_defined": plan.get("livekit_health_probe_defined") is True,
            "ephemeral_room_required": plan.get("ephemeral_room_required") is True,
            "token_issuer_smoke_passed": plan.get("token_issuer_smoke_passed") is True,
            "worker_supervision_attached": plan.get("worker_supervision_attached") is True,
            "stdout_stderr_sanitizers_required": plan.get("stdout_stderr_sanitizers_required") is True,
            "post_smoke_cleanup_required": plan.get("post_smoke_cleanup_required") is True,
            "secrets_redacted": plan.get("secrets_redacted") is True,
            "start_execution_disabled": True,
            "process_launch_disabled": True,
            "subprocess_import_disabled": True,
            "provider_calls_forbidden": True,
            "tool_calls_forbidden": True,
            "raw_audio_forbidden": True,
        },
        "forbidden_shortcuts": [
            "start_livekit_server_from_controlled_smoke_contract",
            "import_subprocess_from_controlled_smoke_contract",
            "call_provider_from_controlled_smoke_contract",
            "persist_raw_audio_from_controlled_smoke_contract",
            "skip_worker_supervision_after_controlled_smoke",
        ],
        "evidence_events": [
            "VOICE_DAEMON_CONTROLLED_LIVEKIT_SERVER_SMOKE_CONTRACT_EVALUATED",
            "VOICE_DAEMON_SUPERVISED_WORKER_HANDSHAKE_REQUIRED",
            "VOICE_DAEMON_START_BLOCKED",
        ],
        "next_action": (
            "prepare_supervised_voice_worker_handshake_smoke_contract"
            if ready_for_supervised_voice_worker_handshake
            else "fix_controlled_livekit_server_supervised_smoke_prerequisites"
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
