from __future__ import annotations

import unittest
from pathlib import Path
from tempfile import TemporaryDirectory

from atlas_voice_agent.daemon_supervisor import evaluate_daemon_supervisor
from atlas_voice_agent.managed_env_writer import (
    execute_managed_env_write,
    inspect_managed_env_writer,
)
from atlas_voice_agent.supervised_launch_execution import (
    FINAL_START_EXECUTOR_AUTHORIZATION_SCHEMA_VERSION,
    FINAL_START_EXECUTOR_DISABLED_SCHEMA_VERSION,
    FINAL_START_EXECUTOR_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION,
    FINAL_START_EXECUTOR_ENABLEMENT_GATE_SCHEMA_VERSION,
    GUARDED_START_ACTIVATION_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_ACTIVATION_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_EXECUTION_ATTEMPT_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_EXECUTION_ATTEMPT_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_EXECUTION_REHEARSAL_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_EXECUTION_REHEARSAL_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_OBSERVABILITY_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_OBSERVABILITY_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_OPERATOR_ACCEPTANCE_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_OPERATOR_ACCEPTANCE_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_FINAL_START_RECEIPT_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_FINAL_START_RECEIPT_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_LAUNCH_WINDOW_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_LAUNCH_WINDOW_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_PRE_LAUNCH_GUARD_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_PRE_LAUNCH_GUARD_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_EXECUTOR_RUNTIME_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_EXECUTOR_RUNTIME_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_PROCESS_SPAWN_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_PROCESS_SPAWN_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_SPAWN_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_SPAWN_REVIEW_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_SUBPROCESS_IMPORT_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_SUBPROCESS_IMPORT_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_LAUNCH_INVOCATION_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_LAUNCH_INVOCATION_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_FINAL_PROCESS_START_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_FINAL_PROCESS_START_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_PROCESS_EXECUTION_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_PROCESS_EXECUTION_REVIEW_SCHEMA_VERSION,
    GUARDED_START_PROCESS_EXECUTION_PACKET_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_PROCESS_EXECUTION_PACKET_SCHEMA_VERSION,
    GUARDED_START_PROCESS_EXECUTOR_STUB_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_PROCESS_EXECUTOR_STUB_SCHEMA_VERSION,
    GUARDED_START_PROCESS_EXECUTOR_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_PROCESS_EXECUTOR_REVIEW_SCHEMA_VERSION,
    GUARDED_START_PROCESS_EXECUTOR_CONTRACT_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_PROCESS_EXECUTOR_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNTIME_ADAPTER_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNTIME_ADAPTER_SCHEMA_VERSION,
    GUARDED_START_PROCESS_ADAPTER_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_PROCESS_ADAPTER_REVIEW_SCHEMA_VERSION,
    GUARDED_START_PROCESS_ADAPTER_CONTRACT_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_PROCESS_ADAPTER_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_CONTRACT_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_EXECUTION_CONTRACT_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_EXECUTION_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_EXECUTION_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_EXECUTION_REVIEW_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_PACKET_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_PACKET_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_REVIEW_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_FINAL_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_FINAL_REVIEW_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_OPERATOR_RELEASE_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_OPERATOR_RELEASE_REVIEW_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_PROMOTION_PACKET_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_PROMOTION_PACKET_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_RELEASE_AUTHORIZATION_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_RELEASE_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_RELEASE_FINALIZATION_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_RELEASE_FINALIZATION_SCHEMA_VERSION,
    CONTROLLED_LIVEKIT_SERVER_SUPERVISED_SMOKE_PLAN_SCHEMA_VERSION,
    CONTROLLED_LIVEKIT_SERVER_SUPERVISED_SMOKE_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_START_GATE_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_START_GATE_SCHEMA_VERSION,
    GUARDED_START_RELEASE_CANDIDATE_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_RELEASE_CANDIDATE_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_EXECUTOR_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_DRY_RUN_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_DRY_RUN_PLAN_SCHEMA_VERSION,
    GUARDED_START_FINAL_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_FINAL_ENABLEMENT_GATE_SCHEMA_VERSION,
    GUARDED_START_HUMAN_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_HUMAN_REVIEW_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_POLICY_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_POLICY_ENABLEMENT_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_RUNTIME_HANDOFF_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_RUNTIME_HANDOFF_PLAN_SCHEMA_VERSION,
    GUARDED_START_POLICY_PATCH_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_POLICY_PATCH_REVIEW_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_SIMULATION_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_SIMULATION_PLAN_SCHEMA_VERSION,
    GUARDED_START_EXECUTOR_DISABLED_SCHEMA_VERSION,
    GUARDED_START_EXECUTOR_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_EXECUTOR_ENABLEMENT_GATE_SCHEMA_VERSION,
    LAUNCH_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
    PRE_START_HEALTH_CHECKS_AUTHORIZATION_SCHEMA_VERSION,
    REAL_START_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
    REAL_START_EXECUTION_CONTRACT_SCHEMA_VERSION,
    REAL_START_ADAPTER_AUTHORIZATION_SCHEMA_VERSION,
    REAL_START_ADAPTER_DISABLED_SCHEMA_VERSION,
    REAL_START_ADAPTER_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
    REAL_START_ADAPTER_REVIEW_CONTRACT_SCHEMA_VERSION,
    REAL_START_ENABLEMENT_GATE_AUTHORIZATION_SCHEMA_VERSION,
    REAL_START_ENABLEMENT_GATE_SCHEMA_VERSION,
    REVIEWED_REAL_START_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
    REVIEWED_REAL_START_EXECUTION_CONTRACT_SCHEMA_VERSION,
    REVIEWED_GUARDED_START_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
    REVIEWED_GUARDED_START_EXECUTION_CONTRACT_SCHEMA_VERSION,
    REVIEWED_SUBPROCESS_START_AUTHORIZATION_SCHEMA_VERSION,
    REVIEWED_SUBPROCESS_START_EXECUTION_SCHEMA_VERSION,
    RUNTIME_POLICY_ENABLEMENT_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
    RUNTIME_POLICY_ENABLEMENT_REVIEW_SCHEMA_VERSION,
    SCHEMA_VERSION,
    SUBPROCESS_START_AUTHORIZATION_SCHEMA_VERSION,
    SUBPROCESS_START_CONTRACT_SCHEMA_VERSION,
    SUPERVISED_START_EXECUTION_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
    SUPERVISED_START_EXECUTION_REVIEW_SCHEMA_VERSION,
    execute_pre_start_health_checks,
    inspect_real_start_adapter_enablement_gate,
    inspect_real_start_adapter_disabled_by_default,
    inspect_real_start_adapter_review_contract,
    inspect_final_start_executor_disabled_by_default,
    inspect_final_start_executor_enablement_gate,
    inspect_guarded_start_activation_contract,
    inspect_guarded_start_execution_attempt_contract,
    inspect_guarded_start_execution_rehearsal_contract,
    inspect_guarded_start_observability_contract,
    inspect_guarded_start_operator_acceptance_contract,
    inspect_guarded_start_final_start_receipt_contract,
    inspect_guarded_start_launch_window_contract,
    inspect_guarded_start_pre_launch_guard_contract,
    inspect_guarded_start_executor_runtime_contract,
    inspect_guarded_start_process_spawn_contract,
    inspect_guarded_start_spawn_review_contract,
    inspect_guarded_start_subprocess_import_contract,
    inspect_guarded_start_launch_invocation_contract,
    inspect_guarded_start_final_process_start_contract,
    inspect_guarded_start_process_execution_review,
    inspect_guarded_start_process_execution_packet,
    inspect_guarded_start_process_executor_stub,
    inspect_guarded_start_process_executor_review,
    inspect_guarded_start_process_executor_contract,
    inspect_guarded_start_process_runtime_adapter,
    inspect_guarded_start_process_adapter_review,
    inspect_guarded_start_process_adapter_contract,
    inspect_guarded_start_process_runner_contract,
    inspect_guarded_start_process_runner_execution_contract,
    inspect_guarded_start_process_runner_execution_review,
    inspect_guarded_start_process_runner_packet,
    inspect_guarded_start_process_runner_review,
    inspect_guarded_start_process_runner_final_review,
    inspect_guarded_start_process_runner_operator_release_review,
    inspect_guarded_start_process_runner_promotion_packet,
    inspect_guarded_start_process_runner_release_authorization,
    inspect_guarded_start_process_runner_release_finalization,
    inspect_controlled_livekit_server_supervised_smoke_contract,
    inspect_guarded_start_process_runner_start_gate,
    inspect_guarded_start_release_candidate_contract,
    inspect_guarded_start_executor_disabled_by_default,
    inspect_guarded_start_executor_enablement_gate,
    inspect_guarded_start_dry_run_contract,
    inspect_guarded_start_final_enablement_gate_contract,
    inspect_guarded_start_human_review_contract,
    inspect_guarded_start_policy_enablement_contract,
    inspect_guarded_start_policy_patch_review_contract,
    inspect_guarded_start_runtime_handoff_contract,
    inspect_guarded_start_simulation_contract,
    inspect_reviewed_guarded_start_execution_contract,
    inspect_real_start_execution_contract,
    inspect_supervised_start_execution_review,
    inspect_reviewed_real_start_execution_contract,
    inspect_reviewed_subprocess_start_execution,
    inspect_runtime_policy_enablement_review,
    inspect_supervised_launch_execution,
    inspect_subprocess_start_contract,
)
from atlas_voice_agent.pre_start_health_checks_smoke import build_pre_start_health_checks_smoke
from test_daemon_supervisor import ready_worker_start
from test_managed_env_writer import launch_authorization_contract, managed_environment_contract


def valid_launch_execution_authorization() -> dict[str, object]:
    return {
        "schema_version": LAUNCH_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_subprocess_implementation",
        "subprocess_implementation_allowed": True,
        "process_launch_allowed": False,
        "decision_receipt_id": "decision_receipt_launch_execution_1",
    }


def valid_health_check_authorization() -> dict[str, object]:
    return {
        "schema_version": PRE_START_HEALTH_CHECKS_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved",
        "health_checks_allowed": True,
        "process_launch_allowed": False,
        "decision_receipt_id": "decision_receipt_pre_start_health_1",
    }


def valid_subprocess_start_authorization() -> dict[str, object]:
    return {
        "schema_version": SUBPROCESS_START_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_start_contract",
        "subprocess_contract_allowed": True,
        "process_launch_allowed": False,
        "decision_receipt_id": "decision_receipt_subprocess_start_contract_1",
    }


def valid_reviewed_subprocess_start_authorization() -> dict[str, object]:
    return {
        "schema_version": REVIEWED_SUBPROCESS_START_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_real_start_implementation_plan",
        "reviewed_execution_allowed": True,
        "real_process_start_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_reviewed_subprocess_start_1",
    }


def valid_real_start_adapter_authorization() -> dict[str, object]:
    return {
        "schema_version": REAL_START_ADAPTER_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_disabled_adapter_contract",
        "disabled_adapter_contract_allowed": True,
        "real_process_start_allowed": False,
        "subprocess_module_import_allowed": False,
        "start_enabled": False,
        "decision_receipt_id": "decision_receipt_real_start_adapter_disabled_1",
    }


def valid_real_start_enablement_gate_authorization() -> dict[str, object]:
    return {
        "schema_version": REAL_START_ENABLEMENT_GATE_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_start_enablement_gate",
        "start_enablement_gate_allowed": True,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "policy_patch_required": True,
        "human_review_required": True,
        "rollback_required": True,
        "decision_receipt_id": "decision_receipt_real_start_enablement_gate_1",
    }


def valid_runtime_policy_enablement_review_authorization() -> dict[str, object]:
    return {
        "schema_version": RUNTIME_POLICY_ENABLEMENT_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_runtime_policy_enablement_review",
        "runtime_policy_review_allowed": True,
        "policy_patch_attached": True,
        "reviewed_bundle_hash": "d"*64,
        "human_review_required": True,
        "rollback_required": True,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_runtime_policy_enablement_review_1",
    }


def valid_real_start_adapter_review_authorization() -> dict[str, object]:
    return {
        "schema_version": REAL_START_ADAPTER_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_real_start_adapter_review_contract",
        "real_start_adapter_review_allowed": True,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "pid_file_guard_required": True,
        "startup_timeout_required": True,
        "post_start_health_probe_required": True,
        "stdout_stderr_sanitization_required": True,
        "rollback_required": True,
        "decision_receipt_id": "decision_receipt_real_start_adapter_review_1",
    }


def valid_reviewed_real_start_execution_authorization() -> dict[str, object]:
    return {
        "schema_version": REVIEWED_REAL_START_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_reviewed_real_start_execution_contract",
        "reviewed_real_start_execution_allowed": True,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "final_pre_start_receipt_required": True,
        "post_start_ready_event_required": True,
        "rollback_rehearsal_required": True,
        "decision_receipt_id": "decision_receipt_reviewed_real_start_execution_1",
    }


def valid_final_start_executor_authorization() -> dict[str, object]:
    return {
        "schema_version": FINAL_START_EXECUTOR_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_final_start_executor_disabled_contract",
        "final_start_executor_contract_allowed": True,
        "start_enabled": False,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "single_start_per_receipt_required": True,
        "final_pre_start_receipt_required": True,
        "post_start_ready_event_required": True,
        "rollback_rehearsal_required": True,
        "decision_receipt_id": "decision_receipt_final_start_executor_disabled_1",
    }


def valid_final_start_executor_enablement_authorization() -> dict[str, object]:
    return {
        "schema_version": FINAL_START_EXECUTOR_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_final_start_executor_enablement_gate",
        "final_start_executor_enablement_gate_allowed": True,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "final_pre_start_receipt_attached": True,
        "reviewed_bundle_hash": "e"*64,
        "single_start_per_receipt_required": True,
        "post_start_ready_event_required": True,
        "rollback_rehearsal_passed": True,
        "decision_receipt_id": "decision_receipt_final_start_executor_enablement_1",
    }


def valid_supervised_start_execution_review_authorization() -> dict[str, object]:
    return {
        "schema_version": SUPERVISED_START_EXECUTION_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_supervised_start_execution_review",
        "supervised_start_execution_review_allowed": True,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "technical_review_completed": True,
        "current_bundle_reviewed": True,
        "reviewed_bundle_hash": "f"*64,
        "final_pre_start_receipt_required": True,
        "single_start_per_receipt_required": True,
        "post_start_ready_event_required": True,
        "rollback_rehearsal_required": True,
        "decision_receipt_id": "decision_receipt_supervised_start_execution_review_1",
    }


def valid_real_start_execution_authorization() -> dict[str, object]:
    return {
        "schema_version": REAL_START_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_real_start_execution_contract",
        "real_start_execution_contract_allowed": True,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "technical_review_completed": True,
        "current_bundle_reviewed": True,
        "reviewed_bundle_hash": "g"*64,
        "final_pre_start_receipt_attached": True,
        "single_start_per_receipt_required": True,
        "post_start_ready_event_required": True,
        "rollback_rehearsal_passed": True,
        "pid_file_guard_required": True,
        "startup_timeout_required": True,
        "stdout_stderr_sanitization_required": True,
        "decision_receipt_id": "decision_receipt_real_start_execution_contract_1",
    }


def valid_guarded_start_executor_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_EXECUTOR_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_executor_disabled_contract",
        "guarded_start_executor_contract_allowed": True,
        "guarded_start_enabled": False,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "pid_file_guard_required": True,
        "startup_timeout_required": True,
        "stdout_stderr_sanitization_required": True,
        "single_start_per_receipt_required": True,
        "post_start_ready_event_required": True,
        "rollback_rehearsal_required": True,
        "decision_receipt_id": "decision_receipt_guarded_start_executor_disabled_1",
    }


def valid_guarded_start_executor_enablement_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_EXECUTOR_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_executor_enablement_gate",
        "guarded_start_executor_enablement_gate_allowed": True,
        "guarded_start_enabled": False,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "reviewed_bundle_hash": "h"*64,
        "final_pre_start_receipt_attached": True,
        "single_start_per_receipt_required": True,
        "post_start_ready_event_required": True,
        "rollback_rehearsal_passed": True,
        "pid_file_guard_required": True,
        "startup_timeout_required": True,
        "stdout_stderr_sanitization_required": True,
        "decision_receipt_id": "decision_receipt_guarded_start_executor_enablement_1",
    }


def valid_reviewed_guarded_start_execution_authorization() -> dict[str, object]:
    return {
        "schema_version": REVIEWED_GUARDED_START_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_reviewed_guarded_start_execution_contract",
        "reviewed_guarded_start_execution_allowed": True,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "technical_review_completed": True,
        "current_bundle_reviewed": True,
        "reviewed_bundle_hash": "i"*64,
        "final_pre_start_receipt_attached": True,
        "single_start_per_receipt_required": True,
        "post_start_ready_event_required": True,
        "rollback_rehearsal_passed": True,
        "pid_file_guard_required": True,
        "startup_timeout_required": True,
        "stdout_stderr_sanitization_required": True,
        "dry_run_execution_plan_attached": True,
        "decision_receipt_id": "decision_receipt_reviewed_guarded_start_execution_1",
    }


def valid_guarded_start_dry_run_plan() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_DRY_RUN_PLAN_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_dry_run_contract",
        "dry_run_only": True,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "simulated_pid_file_guard_passed": True,
        "simulated_startup_timeout_ms": 5000,
        "stdout_stderr_sanitization_simulated": True,
        "post_start_ready_event_simulated": True,
        "rollback_rehearsal_reference_attached": True,
        "decision_receipt_id": "decision_receipt_guarded_start_dry_run_1",
    }


def valid_guarded_start_simulation_plan() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_SIMULATION_PLAN_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_simulation_contract",
        "simulation_only": True,
        "dry_run_only": True,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "synthetic_lifecycle_simulated": True,
        "synthetic_ready_probe_passed": True,
        "synthetic_exit_code": 0,
        "decision_receipt_id": "decision_receipt_guarded_start_simulation_1",
    }


def valid_guarded_start_runtime_handoff_plan() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_RUNTIME_HANDOFF_PLAN_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_runtime_handoff_contract",
        "runtime_handoff_contract_allowed": True,
        "runtime_family": "python_ai_data",
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "kernel_runtime_invocation_contract_attached": True,
        "evidence_sink_attached": True,
        "rollback_plan_attached": True,
        "policy_patch_review_required": True,
        "human_review_required": True,
        "decision_receipt_id": "decision_receipt_guarded_start_runtime_handoff_1",
    }


def valid_guarded_start_policy_patch_review_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_POLICY_PATCH_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_policy_patch_review_contract",
        "policy_patch_review_contract_allowed": True,
        "runtime_policy_start_enabled": False,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "policy_patch_diff_attached": True,
        "policy_patch_dry_run_passed": True,
        "rollback_plan_attached": True,
        "human_review_required": True,
        "decision_receipt_required": True,
        "reviewed_policy_patch_hash": "j"*64,
        "decision_receipt_id": "decision_receipt_guarded_start_policy_patch_review_1",
    }


def valid_guarded_start_human_review_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_HUMAN_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_human_review_contract",
        "human_review_contract_allowed": True,
        "human_review_completed": True,
        "operator_approved_policy_patch": True,
        "runtime_policy_start_enabled": False,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "rollback_plan_reviewed": True,
        "decision_receipt_required": True,
        "final_enablement_gate_required": True,
        "reviewed_policy_patch_hash": "j"*64,
        "decision_receipt_id": "decision_receipt_guarded_start_human_review_1",
    }


def valid_guarded_start_final_enablement_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_FINAL_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_final_enablement_gate",
        "final_enablement_gate_allowed": True,
        "runtime_policy_start_enabled": False,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "human_review_contract_attached": True,
        "final_pre_start_receipt_attached": True,
        "single_start_per_receipt_required": True,
        "post_start_ready_event_required": True,
        "rollback_rehearsal_passed": True,
        "reviewed_policy_patch_hash": "j"*64,
        "decision_receipt_id": "decision_receipt_guarded_start_final_enablement_1",
    }


def valid_guarded_start_policy_enablement_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_POLICY_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_policy_enablement_contract",
        "policy_enablement_contract_allowed": True,
        "runtime_policy_start_enabled": True,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "final_enablement_gate_attached": True,
        "human_review_contract_attached": True,
        "rollback_plan_attached": True,
        "single_start_per_receipt_required": True,
        "post_start_ready_event_required": True,
        "policy_revoke_supported": True,
        "reviewed_policy_patch_hash": "j"*64,
        "decision_receipt_id": "decision_receipt_guarded_start_policy_enablement_1",
    }


def valid_guarded_start_activation_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_ACTIVATION_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_activation_contract",
        "activation_contract_allowed": True,
        "runtime_policy_start_enabled": True,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "policy_enablement_contract_attached": True,
        "final_enablement_gate_attached": True,
        "human_review_contract_attached": True,
        "activation_window_declared": True,
        "operator_activation_review_required": True,
        "post_start_observability_required": True,
        "rollback_plan_attached": True,
        "policy_revoke_supported": True,
        "single_start_per_receipt_required": True,
        "reviewed_policy_patch_hash": "j"*64,
        "decision_receipt_id": "decision_receipt_guarded_start_activation_1",
    }


def valid_guarded_start_execution_attempt_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_EXECUTION_ATTEMPT_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_execution_attempt_contract",
        "execution_attempt_contract_allowed": True,
        "runtime_policy_start_enabled": True,
        "guarded_start_executor_enabled": False,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "activation_contract_attached": True,
        "policy_enablement_contract_attached": True,
        "operator_activation_review_required": True,
        "post_start_observability_required": True,
        "pid_guard_required": True,
        "startup_timeout_required": True,
        "stdout_stderr_sanitization_required": True,
        "ready_event_required": True,
        "rollback_plan_attached": True,
        "policy_revoke_supported": True,
        "single_start_per_receipt_required": True,
        "dry_run_rehearsal_attached": True,
        "reviewed_policy_patch_hash": "j"*64,
        "decision_receipt_id": "decision_receipt_guarded_start_execution_attempt_1",
    }


def valid_guarded_start_execution_rehearsal_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_EXECUTION_REHEARSAL_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_execution_rehearsal_contract",
        "execution_rehearsal_contract_allowed": True,
        "runtime_policy_start_enabled": True,
        "guarded_start_executor_enabled": False,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "execution_attempt_contract_attached": True,
        "pid_guard_rehearsed": True,
        "startup_timeout_rehearsed": True,
        "stdout_stderr_sanitization_rehearsed": True,
        "ready_event_rehearsed": True,
        "rollback_rehearsed": True,
        "policy_revoke_supported": True,
        "single_start_per_receipt_required": True,
        "dry_run_rehearsal_only": True,
        "reviewed_policy_patch_hash": "j"*64,
        "decision_receipt_id": "decision_receipt_guarded_start_execution_rehearsal_1",
    }


def valid_guarded_start_observability_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_OBSERVABILITY_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_observability_contract",
        "observability_contract_allowed": True,
        "runtime_policy_start_enabled": True,
        "guarded_start_executor_enabled": False,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "execution_rehearsal_contract_attached": True,
        "ready_event_required": True,
        "health_snapshot_required": True,
        "stderr_stdout_sanitized_required": True,
        "latency_slo_metrics_required": True,
        "rollback_telemetry_required": True,
        "evidence_sink_required": True,
        "policy_revoke_supported": True,
        "single_start_per_receipt_required": True,
        "reviewed_policy_patch_hash": "j"*64,
        "decision_receipt_id": "decision_receipt_guarded_start_observability_1",
    }


def valid_guarded_start_release_candidate_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_RELEASE_CANDIDATE_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_release_candidate_contract",
        "release_candidate_contract_allowed": True,
        "runtime_policy_start_enabled": True,
        "guarded_start_executor_enabled": False,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "observability_contract_attached": True,
        "bundle_hash_attached": True,
        "evidence_manifest_attached": True,
        "rollback_plan_attached": True,
        "operator_review_required": True,
        "final_start_receipt_required": True,
        "policy_revoke_supported": True,
        "single_start_per_receipt_required": True,
        "release_candidate_bundle_hash": "k"*64,
        "reviewed_policy_patch_hash": "j"*64,
        "decision_receipt_id": "decision_receipt_guarded_start_release_candidate_1",
    }


def valid_guarded_start_operator_acceptance_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_OPERATOR_ACCEPTANCE_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_operator_acceptance_contract",
        "operator_acceptance_contract_allowed": True,
        "runtime_policy_start_enabled": True,
        "guarded_start_executor_enabled": False,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "operator_review_completed": True,
        "operator_acceptance_explicit": True,
        "release_candidate_contract_attached": True,
        "evidence_manifest_reviewed": True,
        "rollback_plan_reviewed": True,
        "final_start_receipt_required": True,
        "policy_revoke_supported": True,
        "single_start_per_receipt_required": True,
        "release_candidate_bundle_hash": "k"*64,
        "reviewed_policy_patch_hash": "j"*64,
        "decision_receipt_id": "decision_receipt_guarded_start_operator_acceptance_1",
        "operator_acceptance_receipt_id": "operator_acceptance_receipt_1",
    }


def valid_guarded_start_final_start_receipt_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_FINAL_START_RECEIPT_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_final_start_receipt_contract",
        "final_start_receipt_contract_allowed": True,
        "runtime_policy_start_enabled": True,
        "guarded_start_executor_enabled": False,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "operator_acceptance_contract_attached": True,
        "final_start_receipt_attached": True,
        "receipt_fresh": True,
        "single_start_per_receipt_required": True,
        "ready_event_required": True,
        "rollback_plan_reviewed": True,
        "policy_revoke_supported": True,
        "release_candidate_bundle_hash": "k"*64,
        "reviewed_policy_patch_hash": "j"*64,
        "operator_acceptance_receipt_id": "operator_acceptance_receipt_1",
        "final_start_receipt_id": "final_start_receipt_1",
        "decision_receipt_id": "decision_receipt_guarded_start_final_start_receipt_1",
    }


def valid_guarded_start_launch_window_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_LAUNCH_WINDOW_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_launch_window_contract",
        "launch_window_contract_allowed": True,
        "runtime_policy_start_enabled": True,
        "guarded_start_executor_enabled": False,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "final_start_receipt_contract_attached": True,
        "launch_window_declared": True,
        "operator_present": True,
        "receipts_fresh": True,
        "single_start_per_receipt_required": True,
        "observability_armed": True,
        "rollback_armed": True,
        "policy_revoke_supported": True,
        "release_candidate_bundle_hash": "k"*64,
        "reviewed_policy_patch_hash": "j"*64,
        "final_start_receipt_id": "final_start_receipt_1",
        "decision_receipt_id": "decision_receipt_guarded_start_launch_window_1",
    }


def valid_guarded_start_pre_launch_guard_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_PRE_LAUNCH_GUARD_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_pre_launch_guard_contract",
        "pre_launch_guard_contract_allowed": True,
        "runtime_policy_start_enabled": True,
        "guarded_start_executor_enabled": False,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "launch_window_contract_attached": True,
        "kernel_health_fresh": True,
        "token_lease_fresh": True,
        "callback_router_fresh": True,
        "observability_armed": True,
        "rollback_armed": True,
        "policy_revoke_supported": True,
        "operator_present": True,
        "final_start_receipt_id": "final_start_receipt_1",
        "decision_receipt_id": "decision_receipt_guarded_start_pre_launch_guard_1",
    }


def valid_guarded_start_executor_runtime_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_EXECUTOR_RUNTIME_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_executor_runtime_contract",
        "executor_runtime_contract_allowed": True,
        "runtime_policy_start_enabled": True,
        "guarded_start_executor_enabled": False,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "pre_launch_guard_contract_attached": True,
        "runtime_family": "python_ai_data",
        "env_contract_attached": True,
        "argv_redacted": True,
        "pid_guard_configured": True,
        "stdout_stderr_sanitized": True,
        "ready_event_required": True,
        "rollback_armed": True,
        "decision_receipt_id": "decision_receipt_guarded_start_executor_runtime_1",
    }


def valid_guarded_start_process_spawn_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_PROCESS_SPAWN_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_process_spawn_contract",
        "process_spawn_contract_allowed": True,
        "executor_runtime_contract_attached": True,
        "runtime_family": "python_ai_data",
        "env_contract_attached": True,
        "argv_redacted": True,
        "cwd_confined": True,
        "pid_guard_configured": True,
        "startup_timeout_configured": True,
        "stdout_stderr_sanitized": True,
        "ready_event_required": True,
        "rollback_armed": True,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_guarded_start_process_spawn_1",
    }


def valid_guarded_start_spawn_review_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_SPAWN_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_spawn_review_contract",
        "spawn_review_contract_allowed": True,
        "process_spawn_contract_attached": True,
        "technical_review_completed": True,
        "bundle_hash_reviewed": True,
        "cwd_confined_reviewed": True,
        "argv_redaction_reviewed": True,
        "timeout_reviewed": True,
        "ready_event_reviewed": True,
        "rollback_reviewed": True,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "reviewed_bundle_hash": "m"*64,
        "decision_receipt_id": "decision_receipt_guarded_start_spawn_review_1",
    }


def valid_guarded_start_subprocess_import_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_SUBPROCESS_IMPORT_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_subprocess_import_contract",
        "subprocess_import_contract_allowed": True,
        "spawn_review_contract_attached": True,
        "localized_import_boundary_declared": True,
        "no_top_level_subprocess_import": True,
        "executor_only_import_required": True,
        "import_audit_event_required": True,
        "process_launch_allowed": False,
        "start_execution_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_guarded_start_subprocess_import_1",
    }


def valid_guarded_start_launch_invocation_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_LAUNCH_INVOCATION_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_launch_invocation_contract",
        "launch_invocation_contract_allowed": True,
        "subprocess_import_contract_attached": True,
        "command_template_reviewed": True,
        "argv_redacted": True,
        "env_redacted": True,
        "cwd_confined": True,
        "pid_guard_required": True,
        "startup_timeout_required": True,
        "ready_event_required": True,
        "rollback_required": True,
        "process_launch_allowed": False,
        "start_execution_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_guarded_start_launch_invocation_1",
    }


def valid_guarded_start_final_process_start_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_FINAL_PROCESS_START_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_final_process_start_contract",
        "final_process_start_contract_allowed": True,
        "launch_invocation_contract_attached": True,
        "decision_receipt_fresh": True,
        "single_start_per_receipt_required": True,
        "ready_event_required": True,
        "pid_guard_required": True,
        "startup_timeout_required": True,
        "stdout_stderr_sanitized": True,
        "rollback_required": True,
        "process_launch_allowed": False,
        "start_execution_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_guarded_start_final_process_start_1",
    }


def valid_guarded_start_process_execution_review_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_PROCESS_EXECUTION_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_process_execution_review",
        "process_execution_review_allowed": True,
        "final_process_start_contract_attached": True,
        "technical_review_completed": True,
        "receipt_bound_to_final_start": True,
        "pid_guard_reviewed": True,
        "startup_timeout_reviewed": True,
        "ready_event_reviewed": True,
        "stdout_stderr_sanitization_reviewed": True,
        "rollback_reviewed": True,
        "observability_reviewed": True,
        "process_launch_allowed": False,
        "start_execution_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_guarded_start_process_execution_review_1",
    }


def valid_guarded_start_process_execution_packet_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_PROCESS_EXECUTION_PACKET_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_process_execution_packet",
        "process_execution_packet_allowed": True,
        "process_execution_review_attached": True,
        "receipt_bound_to_execution_packet": True,
        "argv_redacted": True,
        "env_redacted": True,
        "cwd_confined": True,
        "pid_guard_attached": True,
        "startup_timeout_attached": True,
        "ready_event_attached": True,
        "stdout_stderr_sanitizers_attached": True,
        "rollback_attached": True,
        "observability_attached": True,
        "process_launch_allowed": False,
        "start_execution_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_guarded_start_process_execution_packet_1",
    }


def valid_guarded_start_process_executor_stub_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_PROCESS_EXECUTOR_STUB_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_process_executor_stub",
        "process_executor_stub_allowed": True,
        "process_execution_packet_attached": True,
        "executor_stub_only": True,
        "localized_subprocess_import_required": True,
        "pid_guard_required": True,
        "startup_timeout_required": True,
        "ready_event_required": True,
        "stdout_stderr_sanitizers_required": True,
        "rollback_required": True,
        "observability_required": True,
        "process_launch_allowed": False,
        "start_execution_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_guarded_start_process_executor_stub_1",
    }


def valid_guarded_start_process_executor_review_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_PROCESS_EXECUTOR_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_process_executor_review",
        "process_executor_review_allowed": True,
        "process_executor_stub_attached": True,
        "technical_review_completed": True,
        "localized_subprocess_import_reviewed": True,
        "pid_guard_reviewed": True,
        "startup_timeout_reviewed": True,
        "ready_event_reviewed": True,
        "stdout_stderr_sanitization_reviewed": True,
        "rollback_reviewed": True,
        "observability_reviewed": True,
        "process_launch_allowed": False,
        "start_execution_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_guarded_start_process_executor_review_1",
    }


def valid_guarded_start_process_executor_contract_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_PROCESS_EXECUTOR_CONTRACT_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_process_executor_contract",
        "process_executor_contract_allowed": True,
        "process_executor_review_attached": True,
        "localized_subprocess_import_contract_required": True,
        "pid_guard_contract_required": True,
        "startup_timeout_contract_required": True,
        "ready_event_contract_required": True,
        "stdout_stderr_sanitization_contract_required": True,
        "rollback_contract_required": True,
        "observability_contract_required": True,
        "process_launch_allowed": False,
        "start_execution_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_guarded_start_process_executor_contract_1",
    }


def valid_guarded_start_process_runtime_adapter_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_PROCESS_RUNTIME_ADAPTER_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_process_runtime_adapter",
        "process_runtime_adapter_allowed": True,
        "process_executor_contract_attached": True,
        "runtime_adapter_contract_only": True,
        "localized_subprocess_import_boundary_required": True,
        "pid_guard_adapter_required": True,
        "startup_timeout_adapter_required": True,
        "ready_event_adapter_required": True,
        "stdout_stderr_sanitizers_required": True,
        "rollback_adapter_required": True,
        "observability_adapter_required": True,
        "process_launch_allowed": False,
        "start_execution_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_guarded_start_process_runtime_adapter_1",
    }


def valid_guarded_start_process_adapter_review_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_PROCESS_ADAPTER_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_process_adapter_review",
        "process_adapter_review_allowed": True,
        "process_runtime_adapter_attached": True,
        "technical_review_completed": True,
        "runtime_adapter_contract_reviewed": True,
        "localized_subprocess_import_boundary_reviewed": True,
        "pid_guard_adapter_reviewed": True,
        "startup_timeout_adapter_reviewed": True,
        "ready_event_adapter_reviewed": True,
        "stdout_stderr_sanitizers_reviewed": True,
        "rollback_adapter_reviewed": True,
        "observability_adapter_reviewed": True,
        "process_launch_allowed": False,
        "start_execution_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_guarded_start_process_adapter_review_1",
    }


def valid_guarded_start_process_adapter_contract_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_PROCESS_ADAPTER_CONTRACT_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_process_adapter_contract",
        "process_adapter_contract_allowed": True,
        "process_adapter_review_attached": True,
        "runtime_adapter_contract_required": True,
        "localized_subprocess_import_boundary_required": True,
        "pid_guard_adapter_contract_required": True,
        "startup_timeout_adapter_contract_required": True,
        "ready_event_adapter_contract_required": True,
        "stdout_stderr_sanitizers_contract_required": True,
        "rollback_adapter_contract_required": True,
        "observability_adapter_contract_required": True,
        "process_launch_allowed": False,
        "start_execution_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_guarded_start_process_adapter_contract_1",
    }


def valid_guarded_start_process_runner_contract_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_PROCESS_RUNNER_CONTRACT_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_process_runner_contract",
        "process_runner_contract_allowed": True,
        "process_adapter_contract_attached": True,
        "runner_contract_only": True,
        "single_start_receipt_required": True,
        "pid_guard_runner_required": True,
        "startup_timeout_runner_required": True,
        "ready_event_runner_required": True,
        "stdout_stderr_sanitizers_runner_required": True,
        "rollback_runner_required": True,
        "observability_runner_required": True,
        "process_launch_allowed": False,
        "start_execution_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_guarded_start_process_runner_contract_1",
    }


def valid_guarded_start_process_runner_review_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_PROCESS_RUNNER_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_process_runner_review",
        "process_runner_review_allowed": True,
        "process_runner_contract_attached": True,
        "technical_review_completed": True,
        "single_start_receipt_reviewed": True,
        "pid_guard_runner_reviewed": True,
        "startup_timeout_runner_reviewed": True,
        "ready_event_runner_reviewed": True,
        "stdout_stderr_sanitizers_runner_reviewed": True,
        "rollback_runner_reviewed": True,
        "observability_runner_reviewed": True,
        "process_launch_allowed": False,
        "start_execution_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_guarded_start_process_runner_review_1",
    }


def valid_guarded_start_process_runner_packet_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_PROCESS_RUNNER_PACKET_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_process_runner_packet",
        "process_runner_packet_allowed": True,
        "process_runner_review_attached": True,
        "runner_packet_only": True,
        "decision_receipt_attached": True,
        "argv_env_cwd_redacted": True,
        "pid_guard_attached": True,
        "startup_timeout_attached": True,
        "ready_event_attached": True,
        "stdout_stderr_sanitizers_attached": True,
        "rollback_attached": True,
        "observability_attached": True,
        "process_launch_allowed": False,
        "start_execution_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_guarded_start_process_runner_packet_1",
    }


def valid_guarded_start_process_runner_execution_review_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_PROCESS_RUNNER_EXECUTION_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_process_runner_execution_review",
        "process_runner_execution_review_allowed": True,
        "process_runner_packet_attached": True,
        "technical_review_completed": True,
        "decision_receipt_reviewed": True,
        "argv_env_cwd_reviewed": True,
        "pid_guard_reviewed": True,
        "startup_timeout_reviewed": True,
        "ready_event_reviewed": True,
        "stdout_stderr_sanitizers_reviewed": True,
        "rollback_reviewed": True,
        "observability_reviewed": True,
        "process_launch_allowed": False,
        "start_execution_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_guarded_start_process_runner_execution_review_1",
    }


def valid_guarded_start_process_runner_execution_contract_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_PROCESS_RUNNER_EXECUTION_CONTRACT_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_process_runner_execution_contract",
        "process_runner_execution_contract_allowed": True,
        "process_runner_execution_review_attached": True,
        "execution_contract_only": True,
        "decision_receipt_bound": True,
        "argv_env_cwd_bound": True,
        "pid_guard_bound": True,
        "startup_timeout_bound": True,
        "ready_event_bound": True,
        "stdout_stderr_sanitizers_bound": True,
        "rollback_bound": True,
        "observability_bound": True,
        "process_launch_allowed": False,
        "start_execution_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_guarded_start_process_runner_execution_contract_1",
    }


def valid_guarded_start_process_runner_start_gate_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_PROCESS_RUNNER_START_GATE_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_process_runner_start_gate",
        "process_runner_start_gate_allowed": True,
        "process_runner_execution_contract_attached": True,
        "start_gate_only": True,
        "final_receipt_required": True,
        "single_start_required": True,
        "pid_guard_required": True,
        "startup_timeout_required": True,
        "ready_event_required": True,
        "stdout_stderr_sanitizers_required": True,
        "rollback_required": True,
        "observability_required": True,
        "process_launch_allowed": False,
        "start_execution_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_guarded_start_process_runner_start_gate_1",
    }


def valid_guarded_start_process_runner_final_review_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_PROCESS_RUNNER_FINAL_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_process_runner_final_review",
        "process_runner_final_review_allowed": True,
        "process_runner_start_gate_attached": True,
        "final_review_only": True,
        "final_receipt_attached": True,
        "single_start_verified": True,
        "pid_guard_reviewed": True,
        "startup_timeout_reviewed": True,
        "ready_event_reviewed": True,
        "stdout_stderr_sanitizers_reviewed": True,
        "rollback_reviewed": True,
        "observability_reviewed": True,
        "process_launch_allowed": False,
        "start_execution_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_guarded_start_process_runner_final_review_1",
        "final_review_receipt_id": "final_review_receipt_guarded_start_process_runner_1",
    }


def valid_guarded_start_process_runner_promotion_packet_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_PROCESS_RUNNER_PROMOTION_PACKET_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_process_runner_promotion_packet",
        "process_runner_promotion_packet_allowed": True,
        "process_runner_final_review_attached": True,
        "promotion_packet_only": True,
        "final_review_receipt_attached": True,
        "bundle_hash_attached": True,
        "evidence_manifest_attached": True,
        "rollback_plan_attached": True,
        "operator_release_review_required": True,
        "process_launch_allowed": False,
        "start_execution_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_guarded_start_process_runner_promotion_packet_1",
        "promotion_packet_receipt_id": "promotion_packet_receipt_guarded_start_process_runner_1",
        "reviewed_bundle_hash": "n"*64,
    }


def valid_guarded_start_process_runner_operator_release_review_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_PROCESS_RUNNER_OPERATOR_RELEASE_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_process_runner_operator_release_review",
        "operator_release_review_allowed": True,
        "process_runner_promotion_packet_attached": True,
        "operator_release_review_only": True,
        "operator_review_completed": True,
        "release_candidate_owner_attached": True,
        "promotion_packet_receipt_attached": True,
        "bundle_hash_confirmed": True,
        "evidence_manifest_reviewed": True,
        "rollback_plan_reviewed": True,
        "final_operator_release_required": True,
        "process_launch_allowed": False,
        "start_execution_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_guarded_start_process_runner_operator_release_review_1",
        "operator_release_review_receipt_id": "operator_release_review_receipt_guarded_start_process_runner_1",
        "reviewed_bundle_hash": "o"*64,
    }


def valid_guarded_start_process_runner_release_finalization_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_PROCESS_RUNNER_RELEASE_FINALIZATION_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_process_runner_release_finalization",
        "release_finalization_allowed": True,
        "operator_release_review_attached": True,
        "release_finalization_only": True,
        "final_operator_release_receipt_attached": True,
        "single_start_bound": True,
        "release_window_attached": True,
        "revoke_plan_attached": True,
        "post_release_review_required": True,
        "process_launch_allowed": False,
        "start_execution_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_guarded_start_process_runner_release_finalization_1",
        "final_operator_release_receipt_id": "final_operator_release_receipt_guarded_start_process_runner_1",
        "release_bundle_hash": "p"*64,
    }


def valid_guarded_start_process_runner_release_authorization() -> dict[str, object]:
    return {
        "schema_version": GUARDED_START_PROCESS_RUNNER_RELEASE_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_guarded_start_process_runner_release_authorization",
        "release_authorization_allowed": True,
        "release_finalization_attached": True,
        "release_authorization_only": True,
        "operator_final_release_attached": True,
        "single_start_bound": True,
        "release_window_validated": True,
        "revoke_plan_validated": True,
        "controlled_livekit_server_smoke_required": True,
        "worker_supervision_required": True,
        "post_release_review_required": True,
        "process_launch_allowed": False,
        "start_execution_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_guarded_start_process_runner_release_authorization_1",
        "release_authorization_receipt_id": "release_authorization_receipt_guarded_start_process_runner_1",
        "release_bundle_hash": "q"*64,
    }


def valid_controlled_livekit_server_supervised_smoke_plan() -> dict[str, object]:
    return {
        "schema_version": CONTROLLED_LIVEKIT_SERVER_SUPERVISED_SMOKE_PLAN_SCHEMA_VERSION,
        "status": "approved_for_controlled_livekit_server_supervised_smoke_contract",
        "controlled_smoke_contract_allowed": True,
        "release_authorization_attached": True,
        "controlled_smoke_only": True,
        "local_livekit_server_configured": True,
        "livekit_health_probe_defined": True,
        "ephemeral_room_required": True,
        "token_issuer_smoke_passed": True,
        "worker_supervision_attached": True,
        "stdout_stderr_sanitizers_required": True,
        "post_smoke_cleanup_required": True,
        "secrets_redacted": True,
        "process_launch_allowed": False,
        "start_execution_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_controlled_livekit_server_supervised_smoke_1",
        "controlled_smoke_receipt_id": "controlled_smoke_receipt_livekit_server_1",
        "release_bundle_hash": "r"*64,
    }


def full_launch_authorization_contract() -> dict[str, object]:
    contract = launch_authorization_contract()
    contract["required_pre_start_checks"] = [
        "kernel_health",
        "livekit_room_connectivity",
        "callback_router_roundtrip",
        "kernel_event_normalizer_roundtrip",
        "turn_receipt_roundtrip",
    ]
    contract["required_receipts"] = [
        "production_promotion_review_receipt",
        "daemon_implementation_review_receipt",
        "launch_execution_decision_receipt",
    ]

    return contract


def passed_health_checks(required_checks: list[str]) -> list[dict[str, object]]:
    return [
        {
            "check": check,
            "status": "passed",
            "passed": True,
            "starts_process": False,
            "provider_calls_made": False,
            "tool_calls_made": False,
            "raw_audio_touched": False,
        }
        for check in required_checks
    ]


def ready_launch_execution() -> dict[str, object]:
    supervisor_execution = evaluate_daemon_supervisor(ready_worker_start())
    writer = inspect_managed_env_writer(
        managed_environment_contract=managed_environment_contract(),
        launch_authorization_contract=launch_authorization_contract(),
    )

    with TemporaryDirectory() as directory:
        env_write = execute_managed_env_write(
            managed_env_writer=writer,
            target_path=Path(directory) / "atlas-voice-worker.env",
            write_authorization={
                "schema_version": "atlas.voice_realtime.managed_env_write_authorization.v1",
                "status": "approved",
                "write_allowed": True,
                "process_launch_allowed": False,
                "decision_receipt_id": "decision_receipt_env_write_1",
            },
        )

    return dict(inspect_supervised_launch_execution(
        supervisor_execution=supervisor_execution,
        launch_authorization_contract=full_launch_authorization_contract(),
        managed_env_write_execution=env_write,
        launch_execution_authorization=valid_launch_execution_authorization(),
    ))


def passed_pre_start_health_checks_execution() -> dict[str, object]:
    launch_execution = ready_launch_execution()

    return dict(execute_pre_start_health_checks(
        supervised_launch_execution=launch_execution,
        health_check_results=passed_health_checks(list(launch_execution["required_pre_start_checks"])),
        health_check_authorization=valid_health_check_authorization(),
    ))


def ready_subprocess_start_contract() -> dict[str, object]:
    launch_execution = ready_launch_execution()
    health_checks_execution = execute_pre_start_health_checks(
        supervised_launch_execution=launch_execution,
        health_check_results=passed_health_checks(list(launch_execution["required_pre_start_checks"])),
        health_check_authorization=valid_health_check_authorization(),
    )

    return dict(inspect_subprocess_start_contract(
        supervised_launch_execution=launch_execution,
        pre_start_health_checks_execution=health_checks_execution,
        subprocess_start_authorization=valid_subprocess_start_authorization(),
    ))


def ready_reviewed_subprocess_start_execution() -> dict[str, object]:
    return dict(inspect_reviewed_subprocess_start_execution(
        subprocess_start_contract=ready_subprocess_start_contract(),
        reviewed_start_authorization=valid_reviewed_subprocess_start_authorization(),
    ))


def ready_real_start_adapter_disabled() -> dict[str, object]:
    return dict(inspect_real_start_adapter_disabled_by_default(
        reviewed_subprocess_start_execution=ready_reviewed_subprocess_start_execution(),
        real_start_adapter_authorization=valid_real_start_adapter_authorization(),
    ))


def ready_real_start_enablement_gate() -> dict[str, object]:
    return dict(inspect_real_start_adapter_enablement_gate(
        real_start_adapter_disabled=ready_real_start_adapter_disabled(),
        enablement_gate_authorization=valid_real_start_enablement_gate_authorization(),
    ))


def ready_runtime_policy_enablement_review() -> dict[str, object]:
    return dict(inspect_runtime_policy_enablement_review(
        real_start_enablement_gate=ready_real_start_enablement_gate(),
        policy_review_authorization=valid_runtime_policy_enablement_review_authorization(),
    ))


def ready_real_start_adapter_review_contract() -> dict[str, object]:
    return dict(inspect_real_start_adapter_review_contract(
        runtime_policy_enablement_review=ready_runtime_policy_enablement_review(),
        real_start_review_authorization=valid_real_start_adapter_review_authorization(),
    ))


def ready_reviewed_real_start_execution_contract() -> dict[str, object]:
    return dict(inspect_reviewed_real_start_execution_contract(
        real_start_adapter_review_contract=ready_real_start_adapter_review_contract(),
        reviewed_real_start_authorization=valid_reviewed_real_start_execution_authorization(),
    ))


def ready_final_start_executor_disabled() -> dict[str, object]:
    return dict(inspect_final_start_executor_disabled_by_default(
        reviewed_real_start_execution_contract=ready_reviewed_real_start_execution_contract(),
        final_start_executor_authorization=valid_final_start_executor_authorization(),
    ))


def ready_final_start_executor_enablement_gate() -> dict[str, object]:
    return dict(inspect_final_start_executor_enablement_gate(
        final_start_executor_disabled=ready_final_start_executor_disabled(),
        final_start_executor_enablement_authorization=valid_final_start_executor_enablement_authorization(),
    ))


def ready_supervised_start_execution_review() -> dict[str, object]:
    return dict(inspect_supervised_start_execution_review(
        final_start_executor_enablement_gate=ready_final_start_executor_enablement_gate(),
        supervised_start_review_authorization=valid_supervised_start_execution_review_authorization(),
    ))


def ready_real_start_execution_contract() -> dict[str, object]:
    return dict(inspect_real_start_execution_contract(
        supervised_start_execution_review=ready_supervised_start_execution_review(),
        real_start_execution_authorization=valid_real_start_execution_authorization(),
    ))


def ready_guarded_start_executor_disabled() -> dict[str, object]:
    return dict(inspect_guarded_start_executor_disabled_by_default(
        real_start_execution_contract=ready_real_start_execution_contract(),
        guarded_start_executor_authorization=valid_guarded_start_executor_authorization(),
    ))


def ready_guarded_start_executor_enablement_gate() -> dict[str, object]:
    return dict(inspect_guarded_start_executor_enablement_gate(
        guarded_start_executor_disabled=ready_guarded_start_executor_disabled(),
        guarded_start_executor_enablement_authorization=valid_guarded_start_executor_enablement_authorization(),
    ))


def ready_reviewed_guarded_start_execution_contract() -> dict[str, object]:
    return dict(inspect_reviewed_guarded_start_execution_contract(
        guarded_start_executor_enablement_gate=ready_guarded_start_executor_enablement_gate(),
        reviewed_guarded_start_authorization=valid_reviewed_guarded_start_execution_authorization(),
    ))


def ready_guarded_start_dry_run_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_dry_run_contract(
        reviewed_guarded_start_execution_contract=ready_reviewed_guarded_start_execution_contract(),
        guarded_start_dry_run_plan=valid_guarded_start_dry_run_plan(),
    ))


def ready_guarded_start_simulation_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_simulation_contract(
        guarded_start_dry_run_contract=ready_guarded_start_dry_run_contract(),
        guarded_start_simulation_plan=valid_guarded_start_simulation_plan(),
    ))


def ready_guarded_start_runtime_handoff_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_runtime_handoff_contract(
        guarded_start_simulation_contract=ready_guarded_start_simulation_contract(),
        guarded_start_runtime_handoff_plan=valid_guarded_start_runtime_handoff_plan(),
    ))


def ready_guarded_start_policy_patch_review_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_policy_patch_review_contract(
        guarded_start_runtime_handoff_contract=ready_guarded_start_runtime_handoff_contract(),
        policy_patch_review_authorization=valid_guarded_start_policy_patch_review_authorization(),
    ))


def ready_guarded_start_human_review_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_human_review_contract(
        guarded_start_policy_patch_review_contract=ready_guarded_start_policy_patch_review_contract(),
        human_review_authorization=valid_guarded_start_human_review_authorization(),
    ))


def ready_guarded_start_final_enablement_gate_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_final_enablement_gate_contract(
        guarded_start_human_review_contract=ready_guarded_start_human_review_contract(),
        final_enablement_authorization=valid_guarded_start_final_enablement_authorization(),
    ))


def ready_guarded_start_policy_enablement_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_policy_enablement_contract(
        guarded_start_final_enablement_gate=ready_guarded_start_final_enablement_gate_contract(),
        policy_enablement_authorization=valid_guarded_start_policy_enablement_authorization(),
    ))


def ready_guarded_start_activation_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_activation_contract(
        guarded_start_policy_enablement_contract=ready_guarded_start_policy_enablement_contract(),
        activation_authorization=valid_guarded_start_activation_authorization(),
    ))


def ready_guarded_start_execution_attempt_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_execution_attempt_contract(
        guarded_start_activation_contract=ready_guarded_start_activation_contract(),
        execution_attempt_authorization=valid_guarded_start_execution_attempt_authorization(),
    ))


def ready_guarded_start_execution_rehearsal_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_execution_rehearsal_contract(
        guarded_start_execution_attempt_contract=ready_guarded_start_execution_attempt_contract(),
        execution_rehearsal_authorization=valid_guarded_start_execution_rehearsal_authorization(),
    ))


def ready_guarded_start_observability_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_observability_contract(
        guarded_start_execution_rehearsal_contract=ready_guarded_start_execution_rehearsal_contract(),
        observability_authorization=valid_guarded_start_observability_authorization(),
    ))


def ready_guarded_start_release_candidate_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_release_candidate_contract(
        guarded_start_observability_contract=ready_guarded_start_observability_contract(),
        release_candidate_authorization=valid_guarded_start_release_candidate_authorization(),
    ))


def ready_guarded_start_operator_acceptance_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_operator_acceptance_contract(
        guarded_start_release_candidate_contract=ready_guarded_start_release_candidate_contract(),
        operator_acceptance_authorization=valid_guarded_start_operator_acceptance_authorization(),
    ))


def ready_guarded_start_final_start_receipt_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_final_start_receipt_contract(
        guarded_start_operator_acceptance_contract=ready_guarded_start_operator_acceptance_contract(),
        final_start_receipt_authorization=valid_guarded_start_final_start_receipt_authorization(),
    ))


def ready_guarded_start_launch_window_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_launch_window_contract(
        guarded_start_final_start_receipt_contract=ready_guarded_start_final_start_receipt_contract(),
        launch_window_authorization=valid_guarded_start_launch_window_authorization(),
    ))


def ready_guarded_start_pre_launch_guard_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_pre_launch_guard_contract(
        guarded_start_launch_window_contract=ready_guarded_start_launch_window_contract(),
        pre_launch_guard_authorization=valid_guarded_start_pre_launch_guard_authorization(),
    ))


def ready_guarded_start_executor_runtime_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_executor_runtime_contract(
        guarded_start_pre_launch_guard_contract=ready_guarded_start_pre_launch_guard_contract(),
        executor_runtime_authorization=valid_guarded_start_executor_runtime_authorization(),
    ))


def ready_guarded_start_process_spawn_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_process_spawn_contract(
        guarded_start_executor_runtime_contract=ready_guarded_start_executor_runtime_contract(),
        process_spawn_authorization=valid_guarded_start_process_spawn_authorization(),
    ))


def ready_guarded_start_spawn_review_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_spawn_review_contract(
        guarded_start_process_spawn_contract=ready_guarded_start_process_spawn_contract(),
        spawn_review_authorization=valid_guarded_start_spawn_review_authorization(),
    ))


def ready_guarded_start_subprocess_import_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_subprocess_import_contract(
        guarded_start_spawn_review_contract=ready_guarded_start_spawn_review_contract(),
        subprocess_import_authorization=valid_guarded_start_subprocess_import_authorization(),
    ))


def ready_guarded_start_launch_invocation_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_launch_invocation_contract(
        guarded_start_subprocess_import_contract=ready_guarded_start_subprocess_import_contract(),
        launch_invocation_authorization=valid_guarded_start_launch_invocation_authorization(),
    ))


def ready_guarded_start_final_process_start_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_final_process_start_contract(
        guarded_start_launch_invocation_contract=ready_guarded_start_launch_invocation_contract(),
        final_process_start_authorization=valid_guarded_start_final_process_start_authorization(),
    ))


def ready_guarded_start_process_execution_review() -> dict[str, object]:
    return dict(inspect_guarded_start_process_execution_review(
        guarded_start_final_process_start_contract=ready_guarded_start_final_process_start_contract(),
        process_execution_review_authorization=valid_guarded_start_process_execution_review_authorization(),
    ))


def ready_guarded_start_process_execution_packet() -> dict[str, object]:
    return dict(inspect_guarded_start_process_execution_packet(
        guarded_start_process_execution_review=ready_guarded_start_process_execution_review(),
        process_execution_packet_authorization=valid_guarded_start_process_execution_packet_authorization(),
    ))


def ready_guarded_start_process_executor_stub() -> dict[str, object]:
    return dict(inspect_guarded_start_process_executor_stub(
        guarded_start_process_execution_packet=ready_guarded_start_process_execution_packet(),
        process_executor_stub_authorization=valid_guarded_start_process_executor_stub_authorization(),
    ))


def ready_guarded_start_process_executor_review() -> dict[str, object]:
    return dict(inspect_guarded_start_process_executor_review(
        guarded_start_process_executor_stub=ready_guarded_start_process_executor_stub(),
        process_executor_review_authorization=valid_guarded_start_process_executor_review_authorization(),
    ))


def ready_guarded_start_process_executor_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_process_executor_contract(
        guarded_start_process_executor_review=ready_guarded_start_process_executor_review(),
        process_executor_contract_authorization=valid_guarded_start_process_executor_contract_authorization(),
    ))


def ready_guarded_start_process_runtime_adapter() -> dict[str, object]:
    return dict(inspect_guarded_start_process_runtime_adapter(
        guarded_start_process_executor_contract=ready_guarded_start_process_executor_contract(),
        process_runtime_adapter_authorization=valid_guarded_start_process_runtime_adapter_authorization(),
    ))


def ready_guarded_start_process_adapter_review() -> dict[str, object]:
    return dict(inspect_guarded_start_process_adapter_review(
        guarded_start_process_runtime_adapter=ready_guarded_start_process_runtime_adapter(),
        process_adapter_review_authorization=valid_guarded_start_process_adapter_review_authorization(),
    ))


def ready_guarded_start_process_adapter_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_process_adapter_contract(
        guarded_start_process_adapter_review=ready_guarded_start_process_adapter_review(),
        process_adapter_contract_authorization=valid_guarded_start_process_adapter_contract_authorization(),
    ))


def ready_guarded_start_process_runner_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_process_runner_contract(
        guarded_start_process_adapter_contract=ready_guarded_start_process_adapter_contract(),
        process_runner_contract_authorization=valid_guarded_start_process_runner_contract_authorization(),
    ))


def ready_guarded_start_process_runner_review() -> dict[str, object]:
    return dict(inspect_guarded_start_process_runner_review(
        guarded_start_process_runner_contract=ready_guarded_start_process_runner_contract(),
        process_runner_review_authorization=valid_guarded_start_process_runner_review_authorization(),
    ))


def ready_guarded_start_process_runner_packet() -> dict[str, object]:
    return dict(inspect_guarded_start_process_runner_packet(
        guarded_start_process_runner_review=ready_guarded_start_process_runner_review(),
        process_runner_packet_authorization=valid_guarded_start_process_runner_packet_authorization(),
    ))


def ready_guarded_start_process_runner_execution_review() -> dict[str, object]:
    return dict(inspect_guarded_start_process_runner_execution_review(
        guarded_start_process_runner_packet=ready_guarded_start_process_runner_packet(),
        process_runner_execution_review_authorization=valid_guarded_start_process_runner_execution_review_authorization(),
    ))


def ready_guarded_start_process_runner_execution_contract() -> dict[str, object]:
    return dict(inspect_guarded_start_process_runner_execution_contract(
        guarded_start_process_runner_execution_review=ready_guarded_start_process_runner_execution_review(),
        process_runner_execution_contract_authorization=(
            valid_guarded_start_process_runner_execution_contract_authorization()
        ),
    ))


def ready_guarded_start_process_runner_start_gate() -> dict[str, object]:
    return dict(inspect_guarded_start_process_runner_start_gate(
        guarded_start_process_runner_execution_contract=ready_guarded_start_process_runner_execution_contract(),
        process_runner_start_gate_authorization=valid_guarded_start_process_runner_start_gate_authorization(),
    ))


def ready_guarded_start_process_runner_final_review() -> dict[str, object]:
    return dict(inspect_guarded_start_process_runner_final_review(
        guarded_start_process_runner_start_gate=ready_guarded_start_process_runner_start_gate(),
        process_runner_final_review_authorization=valid_guarded_start_process_runner_final_review_authorization(),
    ))


def ready_guarded_start_process_runner_promotion_packet() -> dict[str, object]:
    return dict(inspect_guarded_start_process_runner_promotion_packet(
        guarded_start_process_runner_final_review=ready_guarded_start_process_runner_final_review(),
        process_runner_promotion_packet_authorization=(
            valid_guarded_start_process_runner_promotion_packet_authorization()
        ),
    ))


def ready_guarded_start_process_runner_operator_release_review() -> dict[str, object]:
    return dict(inspect_guarded_start_process_runner_operator_release_review(
        guarded_start_process_runner_promotion_packet=ready_guarded_start_process_runner_promotion_packet(),
        operator_release_review_authorization=(
            valid_guarded_start_process_runner_operator_release_review_authorization()
        ),
    ))


def ready_guarded_start_process_runner_release_finalization() -> dict[str, object]:
    return dict(inspect_guarded_start_process_runner_release_finalization(
        guarded_start_process_runner_operator_release_review=ready_guarded_start_process_runner_operator_release_review(),
        release_finalization_authorization=(
            valid_guarded_start_process_runner_release_finalization_authorization()
        ),
    ))


def ready_guarded_start_process_runner_release_authorization() -> dict[str, object]:
    return dict(inspect_guarded_start_process_runner_release_authorization(
        guarded_start_process_runner_release_finalization=ready_guarded_start_process_runner_release_finalization(),
        release_authorization=valid_guarded_start_process_runner_release_authorization(),
    ))


def ready_controlled_livekit_server_supervised_smoke_contract() -> dict[str, object]:
    return dict(inspect_controlled_livekit_server_supervised_smoke_contract(
        guarded_start_process_runner_release_authorization=ready_guarded_start_process_runner_release_authorization(),
        controlled_smoke_plan=valid_controlled_livekit_server_supervised_smoke_plan(),
    ))


class SupervisedLaunchExecutionTest(unittest.TestCase):
    def test_launch_execution_contract_is_available_but_blocked_without_written_env(self) -> None:
        supervisor_execution = evaluate_daemon_supervisor(ready_worker_start())
        payload = inspect_supervised_launch_execution(
            supervisor_execution=supervisor_execution,
            launch_authorization_contract=launch_authorization_contract(),
        )

        self.assertEqual(SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("atlas.voice_realtime.supervised_launch_execution.v1", payload["schema_version"])
        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["launch_execution_implemented"])
        self.assertFalse(payload["subprocess_launch_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertTrue(payload["pre_start_health_checks_execution_available"])
        self.assertFalse(payload["pre_start_health_checks_executed"])
        self.assertTrue(payload["gates"]["supervisor_execution_ready"])
        self.assertTrue(payload["gates"]["launch_authorization_contract_ready"])
        self.assertFalse(payload["gates"]["managed_env_write_execution_ready"])
        self.assertFalse(payload["gates"]["launch_execution_authorization_ready"])
        self.assertTrue(payload["gates"]["process_launch_disabled"])
        self.assertEqual("<not-written>", payload["managed_env_target_path"])
        self.assertIn("VOICE_DAEMON_SUPERVISED_LAUNCH_EVALUATED", payload["evidence_events"])
        self.assertIn("import_subprocess_from_launch_execution_contract", payload["forbidden_shortcuts"])
        self.assertEqual("fix_supervised_launch_execution_prerequisites", payload["next_action"])
        self.assertEqual(
            "atlas.voice_realtime.launch_execution_authorization.v1",
            LAUNCH_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
        )

    def test_launch_execution_becomes_ready_after_written_env_and_authorization_without_starting(self) -> None:
        payload = ready_launch_execution()

        self.assertEqual("ready_for_subprocess_implementation", payload["status"])
        self.assertEqual("atlas.voice_realtime.managed_env_write_execution.v1", payload["managed_env_write_execution_schema_version"])
        self.assertTrue(payload["gates"]["managed_env_write_execution_ready"])
        self.assertTrue(payload["gates"]["launch_execution_authorization_ready"])
        self.assertFalse(payload["subprocess_launch_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertEqual("<managed-env-file-written>", payload["managed_env_target_path"])
        self.assertIsInstance(payload["managed_env_content_sha256"], str)
        self.assertIn("<managed-env-file-written>", payload["argv_redacted"])
        self.assertEqual("implement_subprocess_start_after_health_checks", payload["next_action"])

    def test_launch_execution_blocks_if_authorization_would_allow_process_launch(self) -> None:
        supervisor_execution = evaluate_daemon_supervisor(ready_worker_start())
        payload = inspect_supervised_launch_execution(
            supervisor_execution=supervisor_execution,
            launch_authorization_contract=full_launch_authorization_contract(),
            launch_execution_authorization={
                **valid_launch_execution_authorization(),
                "process_launch_allowed": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertFalse(payload["gates"]["launch_execution_authorization_ready"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_supervised_launch_execution_prerequisites", payload["next_action"])

    def test_pre_start_health_checks_pass_without_starting_process(self) -> None:
        launch_execution = ready_launch_execution()
        payload = execute_pre_start_health_checks(
            supervised_launch_execution=launch_execution,
            health_check_results=passed_health_checks(list(launch_execution["required_pre_start_checks"])),
            health_check_authorization=valid_health_check_authorization(),
        )

        self.assertEqual("atlas.voice_realtime.pre_start_health_checks_execution.v1", payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.pre_start_health_checks_authorization.v1",
            PRE_START_HEALTH_CHECKS_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("passed_no_process_start", payload["status"])
        self.assertTrue(payload["pre_start_health_checks_executed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertFalse(payload["provider_calls_made"])
        self.assertFalse(payload["raw_audio_touched"])
        self.assertTrue(payload["gates"]["required_checks_passed"])
        self.assertIn("VOICE_DAEMON_PRE_START_HEALTH_CHECKS_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_subprocess_start_contract_after_pre_start_checks", payload["next_action"])

    def test_pre_start_health_checks_block_missing_or_unsafe_check(self) -> None:
        launch_execution = ready_launch_execution()
        required_checks = list(launch_execution["required_pre_start_checks"])
        unsafe_results = passed_health_checks(required_checks[:-1])
        unsafe_results[0]["provider_calls_made"] = True

        payload = execute_pre_start_health_checks(
            supervised_launch_execution=launch_execution,
            health_check_results=unsafe_results,
            health_check_authorization=valid_health_check_authorization(),
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["missing_checks"])
        self.assertTrue(payload["invalid_checks"])
        self.assertFalse(payload["gates"]["required_checks_present"])
        self.assertFalse(payload["gates"]["required_checks_passed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_pre_start_health_check_prerequisites", payload["next_action"])

    def test_subprocess_start_contract_blocks_without_pre_start_health_checks(self) -> None:
        payload = inspect_subprocess_start_contract(
            supervised_launch_execution=ready_launch_execution(),
            subprocess_start_authorization=valid_subprocess_start_authorization(),
        )

        self.assertEqual(SUBPROCESS_START_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.subprocess_start_contract.v1",
            SUBPROCESS_START_CONTRACT_SCHEMA_VERSION,
        )
        self.assertEqual(
            "atlas.voice_realtime.subprocess_start_authorization.v1",
            SUBPROCESS_START_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["subprocess_start_contract_implemented"])
        self.assertFalse(payload["subprocess_launch_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertFalse(payload["provider_calls_made"])
        self.assertFalse(payload["raw_audio_touched"])
        self.assertTrue(payload["gates"]["supervised_launch_ready"])
        self.assertFalse(payload["gates"]["pre_start_health_checks_passed"])
        self.assertTrue(payload["gates"]["subprocess_start_authorization_ready"])
        self.assertIn("VOICE_DAEMON_SUBPROCESS_START_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("fix_subprocess_start_contract_prerequisites", payload["next_action"])

    def test_subprocess_start_contract_becomes_ready_without_importing_or_starting(self) -> None:
        launch_execution = ready_launch_execution()
        health_checks_execution = execute_pre_start_health_checks(
            supervised_launch_execution=launch_execution,
            health_check_results=passed_health_checks(list(launch_execution["required_pre_start_checks"])),
            health_check_authorization=valid_health_check_authorization(),
        )

        payload = inspect_subprocess_start_contract(
            supervised_launch_execution=launch_execution,
            pre_start_health_checks_execution=health_checks_execution,
            subprocess_start_authorization=valid_subprocess_start_authorization(),
        )

        self.assertEqual("ready_for_reviewed_subprocess_start_implementation", payload["status"])
        self.assertTrue(payload["subprocess_start_contract_implemented"])
        self.assertFalse(payload["subprocess_launch_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertFalse(payload["provider_calls_made"])
        self.assertFalse(payload["tool_calls_made"])
        self.assertFalse(payload["raw_audio_touched"])
        self.assertTrue(payload["pre_start_health_checks_executed"])
        self.assertEqual("passed_no_process_start", payload["pre_start_health_checks_status"])
        self.assertEqual("decision_receipt_subprocess_start_contract_1", payload["decision_receipt_id"])
        self.assertEqual("<managed-env-file-written>", payload["env_file_ref"])
        self.assertIn("startup_timeout_guard", payload["required_runtime_guards"])
        self.assertIn("<managed-env-file-written>", payload["argv_redacted"])
        self.assertTrue(payload["gates"]["supervised_launch_ready"])
        self.assertTrue(payload["gates"]["pre_start_health_checks_passed"])
        self.assertTrue(payload["gates"]["subprocess_start_authorization_ready"])
        self.assertTrue(payload["gates"]["process_launch_disabled"])
        self.assertEqual("implement_real_subprocess_start_after_final_review", payload["next_action"])

    def test_subprocess_start_contract_blocks_if_authorization_would_allow_process_launch(self) -> None:
        payload = inspect_subprocess_start_contract(
            supervised_launch_execution=ready_launch_execution(),
            pre_start_health_checks_execution=passed_pre_start_health_checks_execution(),
            subprocess_start_authorization={
                **valid_subprocess_start_authorization(),
                "process_launch_allowed": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertFalse(payload["gates"]["subprocess_start_authorization_ready"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertEqual("fix_subprocess_start_contract_prerequisites", payload["next_action"])

    def test_reviewed_subprocess_start_execution_becomes_ready_without_importing_or_starting(self) -> None:
        payload = inspect_reviewed_subprocess_start_execution(
            subprocess_start_contract=ready_subprocess_start_contract(),
            reviewed_start_authorization=valid_reviewed_subprocess_start_authorization(),
        )

        self.assertEqual(REVIEWED_SUBPROCESS_START_EXECUTION_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.reviewed_subprocess_start_authorization.v1",
            REVIEWED_SUBPROCESS_START_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_real_start_implementation", payload["status"])
        self.assertTrue(payload["reviewed_subprocess_start_execution_implemented"])
        self.assertFalse(payload["real_subprocess_start_implemented"])
        self.assertFalse(payload["subprocess_launch_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertFalse(payload["provider_calls_made"])
        self.assertFalse(payload["tool_calls_made"])
        self.assertFalse(payload["raw_audio_touched"])
        self.assertEqual("decision_receipt_reviewed_subprocess_start_1", payload["decision_receipt_id"])
        self.assertIn("startup_timeout_enforced", payload["required_real_start_controls"])
        self.assertIn("startup_timeout_guard", payload["required_runtime_guards"])
        self.assertTrue(payload["gates"]["subprocess_start_contract_ready"])
        self.assertTrue(payload["gates"]["review_authorization_ready"])
        self.assertTrue(payload["gates"]["real_start_disabled"])
        self.assertTrue(payload["gates"]["subprocess_import_disabled"])
        self.assertIn("VOICE_DAEMON_REVIEWED_SUBPROCESS_START_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_real_start_adapter_disabled_by_default", payload["next_action"])

    def test_reviewed_subprocess_start_execution_blocks_if_authorization_would_allow_real_start(self) -> None:
        payload = inspect_reviewed_subprocess_start_execution(
            subprocess_start_contract=ready_subprocess_start_contract(),
            reviewed_start_authorization={
                **valid_reviewed_subprocess_start_authorization(),
                "real_process_start_allowed": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["subprocess_start_contract_ready"])
        self.assertFalse(payload["gates"]["review_authorization_ready"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertEqual("fix_reviewed_subprocess_start_execution_prerequisites", payload["next_action"])

    def test_real_start_adapter_contract_is_ready_but_disabled_by_default(self) -> None:
        payload = inspect_real_start_adapter_disabled_by_default(
            reviewed_subprocess_start_execution=ready_reviewed_subprocess_start_execution(),
            real_start_adapter_authorization=valid_real_start_adapter_authorization(),
        )

        self.assertEqual(REAL_START_ADAPTER_DISABLED_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.real_start_adapter_authorization.v1",
            REAL_START_ADAPTER_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_disabled_by_default", payload["status"])
        self.assertTrue(payload["real_start_adapter_contract_implemented"])
        self.assertFalse(payload["real_start_adapter_enabled"])
        self.assertFalse(payload["real_subprocess_start_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertFalse(payload["provider_calls_made"])
        self.assertFalse(payload["tool_calls_made"])
        self.assertFalse(payload["raw_audio_touched"])
        self.assertTrue(payload["gates"]["reviewed_subprocess_start_execution_ready"])
        self.assertTrue(payload["gates"]["real_start_adapter_authorization_ready"])
        self.assertTrue(payload["gates"]["start_disabled_by_default"])
        self.assertIn("runtime_policy_must_enable_start_explicitly", payload["disabled_by_default_controls"])
        self.assertIn("VOICE_DAEMON_REAL_START_ADAPTER_DECLARED", payload["evidence_events"])
        self.assertEqual("implement_real_start_adapter_enablement_gate", payload["next_action"])

    def test_real_start_adapter_contract_blocks_if_authorization_enables_start(self) -> None:
        payload = inspect_real_start_adapter_disabled_by_default(
            reviewed_subprocess_start_execution=ready_reviewed_subprocess_start_execution(),
            real_start_adapter_authorization={
                **valid_real_start_adapter_authorization(),
                "start_enabled": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["reviewed_subprocess_start_execution_ready"])
        self.assertFalse(payload["gates"]["real_start_adapter_authorization_ready"])
        self.assertFalse(payload["real_start_adapter_enabled"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_real_start_adapter_disabled_prerequisites", payload["next_action"])

    def test_real_start_enablement_gate_is_ready_without_enabling_or_starting(self) -> None:
        payload = inspect_real_start_adapter_enablement_gate(
            real_start_adapter_disabled=ready_real_start_adapter_disabled(),
            enablement_gate_authorization=valid_real_start_enablement_gate_authorization(),
        )

        self.assertEqual(REAL_START_ENABLEMENT_GATE_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.real_start_enablement_gate_authorization.v1",
            REAL_START_ENABLEMENT_GATE_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_policy_enablement_review", payload["status"])
        self.assertTrue(payload["real_start_enablement_gate_implemented"])
        self.assertFalse(payload["real_start_adapter_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["real_subprocess_start_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertFalse(payload["provider_calls_made"])
        self.assertFalse(payload["tool_calls_made"])
        self.assertFalse(payload["raw_audio_touched"])
        self.assertTrue(payload["gates"]["real_start_adapter_disabled_ready"])
        self.assertTrue(payload["gates"]["enablement_gate_authorization_ready"])
        self.assertTrue(payload["gates"]["policy_patch_required"])
        self.assertTrue(payload["gates"]["human_review_required"])
        self.assertTrue(payload["gates"]["rollback_required"])
        self.assertTrue(payload["gates"]["start_execution_disabled"])
        self.assertIn("runtime_policy_patch_required_before_start_enabled", payload["policy_enablement_controls"])
        self.assertIn("VOICE_DAEMON_REAL_START_ENABLEMENT_GATE_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_runtime_policy_enablement_review", payload["next_action"])

    def test_real_start_enablement_gate_blocks_if_authorization_allows_start(self) -> None:
        payload = inspect_real_start_adapter_enablement_gate(
            real_start_adapter_disabled=ready_real_start_adapter_disabled(),
            enablement_gate_authorization={
                **valid_real_start_enablement_gate_authorization(),
                "start_execution_allowed": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["real_start_adapter_disabled_ready"])
        self.assertFalse(payload["gates"]["enablement_gate_authorization_ready"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_real_start_enablement_gate_prerequisites", payload["next_action"])

    def test_runtime_policy_enablement_review_is_ready_without_enabling_start(self) -> None:
        payload = inspect_runtime_policy_enablement_review(
            real_start_enablement_gate=ready_real_start_enablement_gate(),
            policy_review_authorization=valid_runtime_policy_enablement_review_authorization(),
        )

        self.assertEqual(RUNTIME_POLICY_ENABLEMENT_REVIEW_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.runtime_policy_enablement_review_authorization.v1",
            RUNTIME_POLICY_ENABLEMENT_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_real_start_adapter_review", payload["status"])
        self.assertTrue(payload["runtime_policy_enablement_review_implemented"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["real_start_adapter_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["real_subprocess_start_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertFalse(payload["provider_calls_made"])
        self.assertFalse(payload["tool_calls_made"])
        self.assertFalse(payload["raw_audio_touched"])
        self.assertTrue(payload["gates"]["real_start_enablement_gate_ready"])
        self.assertTrue(payload["gates"]["policy_review_authorization_ready"])
        self.assertTrue(payload["gates"]["policy_patch_attached"])
        self.assertTrue(payload["gates"]["reviewed_bundle_hash_required"])
        self.assertTrue(payload["gates"]["runtime_policy_start_disabled"])
        self.assertIn("policy_patch_attached_to_current_evidence_bundle", payload["required_policy_review_controls"])
        self.assertIn("VOICE_DAEMON_RUNTIME_POLICY_ENABLEMENT_REVIEW_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_real_start_adapter_review_contract", payload["next_action"])

    def test_runtime_policy_enablement_review_blocks_without_bundle_hash(self) -> None:
        payload = inspect_runtime_policy_enablement_review(
            real_start_enablement_gate=ready_real_start_enablement_gate(),
            policy_review_authorization={
                **valid_runtime_policy_enablement_review_authorization(),
                "reviewed_bundle_hash": "too-short",
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["real_start_enablement_gate_ready"])
        self.assertFalse(payload["gates"]["policy_review_authorization_ready"])
        self.assertFalse(payload["gates"]["reviewed_bundle_hash_required"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_runtime_policy_enablement_review_prerequisites", payload["next_action"])

    def test_real_start_adapter_review_contract_is_ready_without_starting(self) -> None:
        payload = inspect_real_start_adapter_review_contract(
            runtime_policy_enablement_review=ready_runtime_policy_enablement_review(),
            real_start_review_authorization=valid_real_start_adapter_review_authorization(),
        )

        self.assertEqual(REAL_START_ADAPTER_REVIEW_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.real_start_adapter_review_authorization.v1",
            REAL_START_ADAPTER_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_reviewed_real_start_execution_contract", payload["status"])
        self.assertTrue(payload["real_start_adapter_review_contract_implemented"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["real_start_adapter_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["real_subprocess_start_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertFalse(payload["provider_calls_made"])
        self.assertFalse(payload["tool_calls_made"])
        self.assertFalse(payload["raw_audio_touched"])
        self.assertTrue(payload["gates"]["runtime_policy_enablement_review_ready"])
        self.assertTrue(payload["gates"]["real_start_review_authorization_ready"])
        self.assertTrue(payload["gates"]["pid_file_guard_required"])
        self.assertTrue(payload["gates"]["startup_timeout_required"])
        self.assertTrue(payload["gates"]["post_start_health_probe_required"])
        self.assertTrue(payload["gates"]["stdout_stderr_sanitization_required"])
        self.assertIn("pid_file_written_with_0600_permissions", payload["required_real_start_adapter_controls"])
        self.assertIn("VOICE_DAEMON_REAL_START_ADAPTER_REVIEW_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_reviewed_real_start_execution_contract", payload["next_action"])

    def test_real_start_adapter_review_contract_blocks_if_authorization_allows_subprocess_import(self) -> None:
        payload = inspect_real_start_adapter_review_contract(
            runtime_policy_enablement_review=ready_runtime_policy_enablement_review(),
            real_start_review_authorization={
                **valid_real_start_adapter_review_authorization(),
                "subprocess_module_import_allowed": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["runtime_policy_enablement_review_ready"])
        self.assertFalse(payload["gates"]["real_start_review_authorization_ready"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_real_start_adapter_review_contract_prerequisites", payload["next_action"])

    def test_reviewed_real_start_execution_contract_is_ready_without_starting(self) -> None:
        payload = inspect_reviewed_real_start_execution_contract(
            real_start_adapter_review_contract=ready_real_start_adapter_review_contract(),
            reviewed_real_start_authorization=valid_reviewed_real_start_execution_authorization(),
        )

        self.assertEqual(REVIEWED_REAL_START_EXECUTION_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.reviewed_real_start_execution_authorization.v1",
            REVIEWED_REAL_START_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_start_execution_implementation", payload["status"])
        self.assertTrue(payload["reviewed_real_start_execution_contract_implemented"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["real_start_adapter_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["real_subprocess_start_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertTrue(payload["gates"]["real_start_adapter_review_contract_ready"])
        self.assertTrue(payload["gates"]["reviewed_real_start_authorization_ready"])
        self.assertTrue(payload["gates"]["final_pre_start_receipt_required"])
        self.assertTrue(payload["gates"]["post_start_ready_event_required"])
        self.assertTrue(payload["gates"]["rollback_rehearsal_required"])
        self.assertIn("single_start_attempt_per_receipt", payload["required_start_execution_controls"])
        self.assertIn("VOICE_DAEMON_REVIEWED_REAL_START_EXECUTION_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_final_start_executor_disabled_by_default", payload["next_action"])

    def test_reviewed_real_start_execution_contract_blocks_if_authorization_allows_start(self) -> None:
        payload = inspect_reviewed_real_start_execution_contract(
            real_start_adapter_review_contract=ready_real_start_adapter_review_contract(),
            reviewed_real_start_authorization={
                **valid_reviewed_real_start_execution_authorization(),
                "start_execution_allowed": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["real_start_adapter_review_contract_ready"])
        self.assertFalse(payload["gates"]["reviewed_real_start_authorization_ready"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_reviewed_real_start_execution_contract_prerequisites", payload["next_action"])

    def test_final_start_executor_is_ready_but_disabled_by_default(self) -> None:
        payload = inspect_final_start_executor_disabled_by_default(
            reviewed_real_start_execution_contract=ready_reviewed_real_start_execution_contract(),
            final_start_executor_authorization=valid_final_start_executor_authorization(),
        )

        self.assertEqual(FINAL_START_EXECUTOR_DISABLED_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.final_start_executor_authorization.v1",
            FINAL_START_EXECUTOR_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_disabled_by_default", payload["status"])
        self.assertTrue(payload["final_start_executor_contract_implemented"])
        self.assertFalse(payload["final_start_executor_enabled"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["real_start_adapter_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["real_subprocess_start_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertTrue(payload["gates"]["reviewed_real_start_execution_contract_ready"])
        self.assertTrue(payload["gates"]["final_start_executor_authorization_ready"])
        self.assertTrue(payload["gates"]["start_disabled_by_default"])
        self.assertTrue(payload["gates"]["single_start_per_receipt_required"])
        self.assertIn("single_start_attempt_per_fresh_receipt", payload["required_final_start_controls"])
        self.assertIn("VOICE_DAEMON_FINAL_START_EXECUTOR_DECLARED", payload["evidence_events"])
        self.assertEqual("implement_final_start_executor_enablement_gate", payload["next_action"])

    def test_final_start_executor_blocks_if_authorization_enables_start(self) -> None:
        payload = inspect_final_start_executor_disabled_by_default(
            reviewed_real_start_execution_contract=ready_reviewed_real_start_execution_contract(),
            final_start_executor_authorization={
                **valid_final_start_executor_authorization(),
                "start_enabled": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["reviewed_real_start_execution_contract_ready"])
        self.assertFalse(payload["gates"]["final_start_executor_authorization_ready"])
        self.assertFalse(payload["final_start_executor_enabled"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_final_start_executor_disabled_prerequisites", payload["next_action"])

    def test_final_start_executor_enablement_gate_is_ready_without_enabling_start(self) -> None:
        payload = inspect_final_start_executor_enablement_gate(
            final_start_executor_disabled=ready_final_start_executor_disabled(),
            final_start_executor_enablement_authorization=valid_final_start_executor_enablement_authorization(),
        )

        self.assertEqual(FINAL_START_EXECUTOR_ENABLEMENT_GATE_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.final_start_executor_enablement_authorization.v1",
            FINAL_START_EXECUTOR_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_supervised_start_execution_review", payload["status"])
        self.assertTrue(payload["final_start_executor_enablement_gate_implemented"])
        self.assertFalse(payload["final_start_executor_enabled"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["real_start_adapter_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["real_subprocess_start_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertTrue(payload["gates"]["final_start_executor_disabled_ready"])
        self.assertTrue(payload["gates"]["final_start_executor_enablement_authorization_ready"])
        self.assertTrue(payload["gates"]["reviewed_bundle_hash_required"])
        self.assertTrue(payload["gates"]["rollback_rehearsal_passed"])
        self.assertIn("reviewed_bundle_hash_bound_to_current_evidence", payload["required_enablement_controls"])
        self.assertIn("VOICE_DAEMON_FINAL_START_ENABLEMENT_GATE_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_supervised_start_execution_review", payload["next_action"])

    def test_final_start_executor_enablement_gate_blocks_without_bundle_hash(self) -> None:
        payload = inspect_final_start_executor_enablement_gate(
            final_start_executor_disabled=ready_final_start_executor_disabled(),
            final_start_executor_enablement_authorization={
                **valid_final_start_executor_enablement_authorization(),
                "reviewed_bundle_hash": "too-short",
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["final_start_executor_disabled_ready"])
        self.assertFalse(payload["gates"]["final_start_executor_enablement_authorization_ready"])
        self.assertFalse(payload["gates"]["reviewed_bundle_hash_required"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_final_start_executor_enablement_gate_prerequisites", payload["next_action"])

    def test_supervised_start_execution_review_is_ready_without_enabling_start(self) -> None:
        payload = inspect_supervised_start_execution_review(
            final_start_executor_enablement_gate=ready_final_start_executor_enablement_gate(),
            supervised_start_review_authorization=valid_supervised_start_execution_review_authorization(),
        )

        self.assertEqual(SUPERVISED_START_EXECUTION_REVIEW_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.supervised_start_execution_review_authorization.v1",
            SUPERVISED_START_EXECUTION_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_real_start_execution_contract", payload["status"])
        self.assertTrue(payload["supervised_start_execution_review_implemented"])
        self.assertFalse(payload["final_start_executor_enabled"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["real_start_adapter_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["real_subprocess_start_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertTrue(payload["gates"]["final_start_executor_enablement_gate_ready"])
        self.assertTrue(payload["gates"]["supervised_start_review_authorization_ready"])
        self.assertTrue(payload["gates"]["technical_review_required"])
        self.assertTrue(payload["gates"]["current_bundle_review_required"])
        self.assertIn("technical_review_completed_for_current_bundle", payload["required_review_controls"])
        self.assertIn("VOICE_DAEMON_SUPERVISED_START_EXECUTION_REVIEWED", payload["evidence_events"])
        self.assertEqual("implement_real_start_execution_contract", payload["next_action"])

    def test_supervised_start_execution_review_blocks_if_authorization_allows_start(self) -> None:
        payload = inspect_supervised_start_execution_review(
            final_start_executor_enablement_gate=ready_final_start_executor_enablement_gate(),
            supervised_start_review_authorization={
                **valid_supervised_start_execution_review_authorization(),
                "start_execution_allowed": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["final_start_executor_enablement_gate_ready"])
        self.assertFalse(payload["gates"]["supervised_start_review_authorization_ready"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_supervised_start_execution_review_prerequisites", payload["next_action"])

    def test_real_start_execution_contract_is_ready_without_starting_or_importing(self) -> None:
        payload = inspect_real_start_execution_contract(
            supervised_start_execution_review=ready_supervised_start_execution_review(),
            real_start_execution_authorization=valid_real_start_execution_authorization(),
        )

        self.assertEqual(REAL_START_EXECUTION_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.real_start_execution_authorization.v1",
            REAL_START_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_executor_implementation", payload["status"])
        self.assertTrue(payload["real_start_execution_contract_implemented"])
        self.assertFalse(payload["guarded_start_executor_implemented"])
        self.assertFalse(payload["final_start_executor_enabled"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["real_start_adapter_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["real_subprocess_start_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertTrue(payload["gates"]["supervised_start_execution_review_ready"])
        self.assertTrue(payload["gates"]["real_start_execution_authorization_ready"])
        self.assertTrue(payload["gates"]["pid_file_guard_required"])
        self.assertTrue(payload["gates"]["startup_timeout_required"])
        self.assertTrue(payload["gates"]["stdout_stderr_sanitization_required"])
        self.assertIn("guarded_subprocess_import_only_inside_executor", payload["required_execution_controls"])
        self.assertIn("VOICE_DAEMON_REAL_START_EXECUTION_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_executor_disabled_by_default", payload["next_action"])

    def test_real_start_execution_contract_blocks_if_authorization_allows_process_launch(self) -> None:
        payload = inspect_real_start_execution_contract(
            supervised_start_execution_review=ready_supervised_start_execution_review(),
            real_start_execution_authorization={
                **valid_real_start_execution_authorization(),
                "process_launch_allowed": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["supervised_start_execution_review_ready"])
        self.assertFalse(payload["gates"]["real_start_execution_authorization_ready"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_real_start_execution_contract_prerequisites", payload["next_action"])

    def test_real_start_execution_contract_blocks_without_bundle_hash(self) -> None:
        payload = inspect_real_start_execution_contract(
            supervised_start_execution_review=ready_supervised_start_execution_review(),
            real_start_execution_authorization={
                **valid_real_start_execution_authorization(),
                "reviewed_bundle_hash": "too-short",
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertFalse(payload["gates"]["real_start_execution_authorization_ready"])
        self.assertFalse(payload["gates"]["reviewed_bundle_hash_required"])
        self.assertFalse(payload["daemon_started"])
        self.assertEqual("fix_real_start_execution_contract_prerequisites", payload["next_action"])

    def test_guarded_start_executor_disabled_is_ready_without_starting(self) -> None:
        payload = inspect_guarded_start_executor_disabled_by_default(
            real_start_execution_contract=ready_real_start_execution_contract(),
            guarded_start_executor_authorization=valid_guarded_start_executor_authorization(),
        )

        self.assertEqual(GUARDED_START_EXECUTOR_DISABLED_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_executor_authorization.v1",
            GUARDED_START_EXECUTOR_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_disabled_by_default", payload["status"])
        self.assertTrue(payload["guarded_start_executor_contract_implemented"])
        self.assertFalse(payload["guarded_start_executor_enabled"])
        self.assertFalse(payload["guarded_start_executor_implemented"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertTrue(payload["gates"]["real_start_execution_contract_ready"])
        self.assertTrue(payload["gates"]["guarded_start_executor_authorization_ready"])
        self.assertTrue(payload["gates"]["guarded_executor_disabled_by_default"])
        self.assertIn("guarded_executor_disabled_by_default", payload["required_disabled_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_EXECUTOR_DECLARED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_executor_enablement_gate", payload["next_action"])

    def test_guarded_start_executor_disabled_blocks_if_authorization_enables_start(self) -> None:
        payload = inspect_guarded_start_executor_disabled_by_default(
            real_start_execution_contract=ready_real_start_execution_contract(),
            guarded_start_executor_authorization={
                **valid_guarded_start_executor_authorization(),
                "guarded_start_enabled": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["real_start_execution_contract_ready"])
        self.assertFalse(payload["gates"]["guarded_start_executor_authorization_ready"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_guarded_start_executor_disabled_prerequisites", payload["next_action"])

    def test_guarded_start_executor_enablement_gate_is_ready_without_enabling_start(self) -> None:
        payload = inspect_guarded_start_executor_enablement_gate(
            guarded_start_executor_disabled=ready_guarded_start_executor_disabled(),
            guarded_start_executor_enablement_authorization=valid_guarded_start_executor_enablement_authorization(),
        )

        self.assertEqual(GUARDED_START_EXECUTOR_ENABLEMENT_GATE_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_executor_enablement_authorization.v1",
            GUARDED_START_EXECUTOR_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_reviewed_guarded_start_execution", payload["status"])
        self.assertTrue(payload["guarded_start_executor_enablement_gate_implemented"])
        self.assertFalse(payload["guarded_start_executor_enabled"])
        self.assertFalse(payload["guarded_start_executor_implemented"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertTrue(payload["gates"]["guarded_start_executor_disabled_ready"])
        self.assertTrue(payload["gates"]["guarded_start_executor_enablement_authorization_ready"])
        self.assertTrue(payload["gates"]["reviewed_bundle_hash_required"])
        self.assertIn("reviewed_bundle_hash_bound_to_current_evidence", payload["required_enablement_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_ENABLEMENT_GATE_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_reviewed_guarded_start_execution_contract", payload["next_action"])

    def test_guarded_start_executor_enablement_gate_blocks_if_authorization_allows_start(self) -> None:
        payload = inspect_guarded_start_executor_enablement_gate(
            guarded_start_executor_disabled=ready_guarded_start_executor_disabled(),
            guarded_start_executor_enablement_authorization={
                **valid_guarded_start_executor_enablement_authorization(),
                "start_execution_allowed": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_executor_disabled_ready"])
        self.assertFalse(payload["gates"]["guarded_start_executor_enablement_authorization_ready"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_guarded_start_executor_enablement_gate_prerequisites", payload["next_action"])

    def test_reviewed_guarded_start_execution_contract_is_ready_without_starting(self) -> None:
        payload = inspect_reviewed_guarded_start_execution_contract(
            guarded_start_executor_enablement_gate=ready_guarded_start_executor_enablement_gate(),
            reviewed_guarded_start_authorization=valid_reviewed_guarded_start_execution_authorization(),
        )

        self.assertEqual(REVIEWED_GUARDED_START_EXECUTION_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.reviewed_guarded_start_execution_authorization.v1",
            REVIEWED_GUARDED_START_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_dry_run_contract", payload["status"])
        self.assertTrue(payload["reviewed_guarded_start_execution_contract_implemented"])
        self.assertFalse(payload["guarded_start_executor_enabled"])
        self.assertFalse(payload["guarded_start_executor_implemented"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertTrue(payload["gates"]["guarded_start_executor_enablement_gate_ready"])
        self.assertTrue(payload["gates"]["reviewed_guarded_start_execution_authorization_ready"])
        self.assertTrue(payload["gates"]["dry_run_execution_plan_attached"])
        self.assertIn("dry_run_execution_plan_attached_before_any_start", payload["required_reviewed_execution_controls"])
        self.assertIn("VOICE_DAEMON_REVIEWED_GUARDED_START_EXECUTION_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_dry_run_contract", payload["next_action"])

    def test_reviewed_guarded_start_execution_contract_blocks_without_dry_run_plan(self) -> None:
        payload = inspect_reviewed_guarded_start_execution_contract(
            guarded_start_executor_enablement_gate=ready_guarded_start_executor_enablement_gate(),
            reviewed_guarded_start_authorization={
                **valid_reviewed_guarded_start_execution_authorization(),
                "dry_run_execution_plan_attached": False,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_executor_enablement_gate_ready"])
        self.assertFalse(payload["gates"]["reviewed_guarded_start_execution_authorization_ready"])
        self.assertFalse(payload["gates"]["dry_run_execution_plan_attached"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_reviewed_guarded_start_execution_contract_prerequisites", payload["next_action"])

    def test_guarded_start_dry_run_contract_is_ready_without_starting(self) -> None:
        payload = inspect_guarded_start_dry_run_contract(
            reviewed_guarded_start_execution_contract=ready_reviewed_guarded_start_execution_contract(),
            guarded_start_dry_run_plan=valid_guarded_start_dry_run_plan(),
        )

        self.assertEqual(GUARDED_START_DRY_RUN_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("atlas.voice_realtime.guarded_start_dry_run_plan.v1", GUARDED_START_DRY_RUN_PLAN_SCHEMA_VERSION)
        self.assertEqual("ready_for_guarded_start_simulation", payload["status"])
        self.assertTrue(payload["guarded_start_dry_run_contract_implemented"])
        self.assertTrue(payload["dry_run_only"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertTrue(payload["gates"]["reviewed_guarded_start_execution_contract_ready"])
        self.assertTrue(payload["gates"]["dry_run_plan_ready"])
        self.assertTrue(payload["gates"]["dry_run_only_enforced"])
        self.assertIn("pid_file_guard_simulated_before_process_boundary", payload["required_dry_run_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_DRY_RUN_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_simulation_contract", payload["next_action"])

    def test_guarded_start_dry_run_contract_blocks_when_plan_allows_start(self) -> None:
        payload = inspect_guarded_start_dry_run_contract(
            reviewed_guarded_start_execution_contract=ready_reviewed_guarded_start_execution_contract(),
            guarded_start_dry_run_plan={
                **valid_guarded_start_dry_run_plan(),
                "dry_run_only": False,
                "start_execution_allowed": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["reviewed_guarded_start_execution_contract_ready"])
        self.assertFalse(payload["gates"]["dry_run_plan_ready"])
        self.assertTrue(payload["gates"]["dry_run_only_enforced"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_guarded_start_dry_run_contract_prerequisites", payload["next_action"])

    def test_guarded_start_simulation_contract_is_ready_without_starting(self) -> None:
        payload = inspect_guarded_start_simulation_contract(
            guarded_start_dry_run_contract=ready_guarded_start_dry_run_contract(),
            guarded_start_simulation_plan=valid_guarded_start_simulation_plan(),
        )

        self.assertEqual(GUARDED_START_SIMULATION_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("atlas.voice_realtime.guarded_start_simulation_plan.v1", GUARDED_START_SIMULATION_PLAN_SCHEMA_VERSION)
        self.assertEqual("ready_for_guarded_start_runtime_handoff", payload["status"])
        self.assertTrue(payload["guarded_start_simulation_contract_implemented"])
        self.assertTrue(payload["simulation_only"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertTrue(payload["gates"]["guarded_start_dry_run_contract_ready"])
        self.assertTrue(payload["gates"]["guarded_start_simulation_plan_ready"])
        self.assertTrue(payload["gates"]["simulation_only_enforced"])
        self.assertIn("synthetic_ready_probe_before_real_daemon", payload["required_simulation_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_SIMULATION_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_runtime_handoff_contract", payload["next_action"])

    def test_guarded_start_simulation_contract_blocks_when_plan_allows_start(self) -> None:
        payload = inspect_guarded_start_simulation_contract(
            guarded_start_dry_run_contract=ready_guarded_start_dry_run_contract(),
            guarded_start_simulation_plan={
                **valid_guarded_start_simulation_plan(),
                "simulation_only": False,
                "start_execution_allowed": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_dry_run_contract_ready"])
        self.assertFalse(payload["gates"]["guarded_start_simulation_plan_ready"])
        self.assertTrue(payload["gates"]["simulation_only_enforced"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_guarded_start_simulation_contract_prerequisites", payload["next_action"])

    def test_guarded_start_runtime_handoff_contract_is_ready_without_starting(self) -> None:
        payload = inspect_guarded_start_runtime_handoff_contract(
            guarded_start_simulation_contract=ready_guarded_start_simulation_contract(),
            guarded_start_runtime_handoff_plan=valid_guarded_start_runtime_handoff_plan(),
        )

        self.assertEqual(GUARDED_START_RUNTIME_HANDOFF_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_runtime_handoff_plan.v1",
            GUARDED_START_RUNTIME_HANDOFF_PLAN_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_policy_patch_review", payload["status"])
        self.assertTrue(payload["guarded_start_runtime_handoff_contract_implemented"])
        self.assertTrue(payload["runtime_handoff_contract_only"])
        self.assertEqual("python_ai_data", payload["runtime_family"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertTrue(payload["gates"]["guarded_start_simulation_contract_ready"])
        self.assertTrue(payload["gates"]["handoff_plan_ready"])
        self.assertTrue(payload["gates"]["policy_patch_review_required"])
        self.assertIn("kernel_runtime_invocation_contract_before_policy_patch", payload["required_handoff_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_RUNTIME_HANDOFF_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_policy_patch_review_contract", payload["next_action"])

    def test_guarded_start_runtime_handoff_contract_blocks_when_plan_allows_start(self) -> None:
        payload = inspect_guarded_start_runtime_handoff_contract(
            guarded_start_simulation_contract=ready_guarded_start_simulation_contract(),
            guarded_start_runtime_handoff_plan={
                **valid_guarded_start_runtime_handoff_plan(),
                "start_execution_allowed": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_simulation_contract_ready"])
        self.assertFalse(payload["gates"]["handoff_plan_ready"])
        self.assertTrue(payload["gates"]["runtime_handoff_contract_only"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_guarded_start_runtime_handoff_prerequisites", payload["next_action"])

    def test_guarded_start_policy_patch_review_contract_is_ready_without_enabling_policy(self) -> None:
        payload = inspect_guarded_start_policy_patch_review_contract(
            guarded_start_runtime_handoff_contract=ready_guarded_start_runtime_handoff_contract(),
            policy_patch_review_authorization=valid_guarded_start_policy_patch_review_authorization(),
        )

        self.assertEqual(GUARDED_START_POLICY_PATCH_REVIEW_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_policy_patch_review_authorization.v1",
            GUARDED_START_POLICY_PATCH_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_human_review", payload["status"])
        self.assertTrue(payload["guarded_start_policy_patch_review_contract_implemented"])
        self.assertTrue(payload["policy_patch_review_only"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_runtime_handoff_contract_ready"])
        self.assertTrue(payload["gates"]["policy_patch_review_authorization_ready"])
        self.assertTrue(payload["gates"]["policy_patch_dry_run_passed"])
        self.assertIn("policy_patch_diff_reviewed_before_enablement", payload["required_policy_patch_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_POLICY_PATCH_REVIEW_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_human_review_contract", payload["next_action"])

    def test_guarded_start_policy_patch_review_contract_blocks_when_policy_would_enable_start(self) -> None:
        payload = inspect_guarded_start_policy_patch_review_contract(
            guarded_start_runtime_handoff_contract=ready_guarded_start_runtime_handoff_contract(),
            policy_patch_review_authorization={
                **valid_guarded_start_policy_patch_review_authorization(),
                "runtime_policy_start_enabled": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_runtime_handoff_contract_ready"])
        self.assertFalse(payload["gates"]["policy_patch_review_authorization_ready"])
        self.assertTrue(payload["gates"]["policy_patch_review_only"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_policy_patch_review_prerequisites", payload["next_action"])

    def test_guarded_start_human_review_contract_is_ready_without_enabling_start(self) -> None:
        payload = inspect_guarded_start_human_review_contract(
            guarded_start_policy_patch_review_contract=ready_guarded_start_policy_patch_review_contract(),
            human_review_authorization=valid_guarded_start_human_review_authorization(),
        )

        self.assertEqual(GUARDED_START_HUMAN_REVIEW_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_human_review_authorization.v1",
            GUARDED_START_HUMAN_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_final_enablement_gate", payload["status"])
        self.assertTrue(payload["guarded_start_human_review_contract_implemented"])
        self.assertTrue(payload["human_review_contract_only"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_policy_patch_review_contract_ready"])
        self.assertTrue(payload["gates"]["human_review_authorization_ready"])
        self.assertTrue(payload["gates"]["policy_patch_hash_matches"])
        self.assertIn("final_enablement_gate_before_any_start", payload["required_human_review_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_HUMAN_REVIEW_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_final_enablement_gate_contract", payload["next_action"])

    def test_guarded_start_human_review_contract_blocks_on_hash_mismatch(self) -> None:
        payload = inspect_guarded_start_human_review_contract(
            guarded_start_policy_patch_review_contract=ready_guarded_start_policy_patch_review_contract(),
            human_review_authorization={
                **valid_guarded_start_human_review_authorization(),
                "reviewed_policy_patch_hash": "k"*64,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_policy_patch_review_contract_ready"])
        self.assertFalse(payload["gates"]["human_review_authorization_ready"])
        self.assertFalse(payload["gates"]["policy_patch_hash_matches"])
        self.assertTrue(payload["gates"]["human_review_contract_only"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertEqual("fix_guarded_start_human_review_prerequisites", payload["next_action"])

    def test_guarded_start_final_enablement_gate_contract_is_ready_without_enabling_policy(self) -> None:
        payload = inspect_guarded_start_final_enablement_gate_contract(
            guarded_start_human_review_contract=ready_guarded_start_human_review_contract(),
            final_enablement_authorization=valid_guarded_start_final_enablement_authorization(),
        )

        self.assertEqual(GUARDED_START_FINAL_ENABLEMENT_GATE_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_final_enablement_authorization.v1",
            GUARDED_START_FINAL_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_policy_enablement_contract", payload["status"])
        self.assertTrue(payload["guarded_start_final_enablement_gate_implemented"])
        self.assertTrue(payload["final_enablement_gate_only"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_human_review_contract_ready"])
        self.assertTrue(payload["gates"]["final_enablement_authorization_ready"])
        self.assertTrue(payload["gates"]["policy_patch_hash_matches"])
        self.assertIn("single_start_per_receipt_before_policy_enablement", payload["required_final_enablement_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_FINAL_ENABLEMENT_GATE_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_policy_enablement_contract", payload["next_action"])

    def test_guarded_start_final_enablement_gate_contract_blocks_when_policy_is_enabled(self) -> None:
        payload = inspect_guarded_start_final_enablement_gate_contract(
            guarded_start_human_review_contract=ready_guarded_start_human_review_contract(),
            final_enablement_authorization={
                **valid_guarded_start_final_enablement_authorization(),
                "runtime_policy_start_enabled": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_human_review_contract_ready"])
        self.assertFalse(payload["gates"]["final_enablement_authorization_ready"])
        self.assertTrue(payload["gates"]["final_enablement_gate_only"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_final_enablement_prerequisites", payload["next_action"])

    def test_guarded_start_policy_enablement_contract_enables_policy_without_starting(self) -> None:
        payload = inspect_guarded_start_policy_enablement_contract(
            guarded_start_final_enablement_gate=ready_guarded_start_final_enablement_gate_contract(),
            policy_enablement_authorization=valid_guarded_start_policy_enablement_authorization(),
        )

        self.assertEqual(GUARDED_START_POLICY_ENABLEMENT_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_policy_enablement_authorization.v1",
            GUARDED_START_POLICY_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_activation_contract", payload["status"])
        self.assertTrue(payload["guarded_start_policy_enablement_contract_implemented"])
        self.assertTrue(payload["policy_enablement_contract_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_executor_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_final_enablement_gate_ready"])
        self.assertTrue(payload["gates"]["policy_enablement_authorization_ready"])
        self.assertTrue(payload["gates"]["policy_revoke_supported"])
        self.assertIn("policy_switch_without_process_start", payload["required_policy_enablement_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_POLICY_ENABLEMENT_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_activation_contract", payload["next_action"])

    def test_guarded_start_policy_enablement_contract_blocks_when_start_is_allowed(self) -> None:
        payload = inspect_guarded_start_policy_enablement_contract(
            guarded_start_final_enablement_gate=ready_guarded_start_final_enablement_gate_contract(),
            policy_enablement_authorization={
                **valid_guarded_start_policy_enablement_authorization(),
                "start_execution_allowed": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_final_enablement_gate_ready"])
        self.assertFalse(payload["gates"]["policy_enablement_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertEqual("fix_guarded_start_policy_enablement_prerequisites", payload["next_action"])

    def test_guarded_start_activation_contract_attaches_without_starting(self) -> None:
        payload = inspect_guarded_start_activation_contract(
            guarded_start_policy_enablement_contract=ready_guarded_start_policy_enablement_contract(),
            activation_authorization=valid_guarded_start_activation_authorization(),
        )

        self.assertEqual(GUARDED_START_ACTIVATION_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_activation_authorization.v1",
            GUARDED_START_ACTIVATION_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_execution_attempt_contract", payload["status"])
        self.assertTrue(payload["guarded_start_activation_contract_implemented"])
        self.assertTrue(payload["activation_contract_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_executor_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_policy_enablement_contract_ready"])
        self.assertTrue(payload["gates"]["activation_authorization_ready"])
        self.assertTrue(payload["gates"]["operator_activation_review_required"])
        self.assertIn("activation_window_before_execution_attempt_contract", payload["required_activation_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_ACTIVATION_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_execution_attempt_contract", payload["next_action"])

    def test_guarded_start_activation_contract_blocks_when_process_launch_is_allowed(self) -> None:
        payload = inspect_guarded_start_activation_contract(
            guarded_start_policy_enablement_contract=ready_guarded_start_policy_enablement_contract(),
            activation_authorization={
                **valid_guarded_start_activation_authorization(),
                "process_launch_allowed": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_policy_enablement_contract_ready"])
        self.assertFalse(payload["gates"]["activation_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertEqual("fix_guarded_start_activation_prerequisites", payload["next_action"])

    def test_guarded_start_execution_attempt_contract_attaches_without_starting(self) -> None:
        payload = inspect_guarded_start_execution_attempt_contract(
            guarded_start_activation_contract=ready_guarded_start_activation_contract(),
            execution_attempt_authorization=valid_guarded_start_execution_attempt_authorization(),
        )

        self.assertEqual(GUARDED_START_EXECUTION_ATTEMPT_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_execution_attempt_authorization.v1",
            GUARDED_START_EXECUTION_ATTEMPT_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_execution_rehearsal_contract", payload["status"])
        self.assertTrue(payload["guarded_start_execution_attempt_contract_implemented"])
        self.assertTrue(payload["execution_attempt_contract_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_executor_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_activation_contract_ready"])
        self.assertTrue(payload["gates"]["execution_attempt_authorization_ready"])
        self.assertTrue(payload["gates"]["pid_guard_required"])
        self.assertIn("dry_run_rehearsal_before_any_process_launch", payload["required_execution_attempt_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_EXECUTION_ATTEMPT_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_execution_rehearsal_contract", payload["next_action"])

    def test_guarded_start_execution_attempt_contract_blocks_when_subprocess_import_is_allowed(self) -> None:
        payload = inspect_guarded_start_execution_attempt_contract(
            guarded_start_activation_contract=ready_guarded_start_activation_contract(),
            execution_attempt_authorization={
                **valid_guarded_start_execution_attempt_authorization(),
                "subprocess_module_import_allowed": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_activation_contract_ready"])
        self.assertFalse(payload["gates"]["execution_attempt_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertEqual("fix_guarded_start_execution_attempt_prerequisites", payload["next_action"])

    def test_guarded_start_execution_rehearsal_contract_attaches_without_starting(self) -> None:
        payload = inspect_guarded_start_execution_rehearsal_contract(
            guarded_start_execution_attempt_contract=ready_guarded_start_execution_attempt_contract(),
            execution_rehearsal_authorization=valid_guarded_start_execution_rehearsal_authorization(),
        )

        self.assertEqual(GUARDED_START_EXECUTION_REHEARSAL_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_execution_rehearsal_authorization.v1",
            GUARDED_START_EXECUTION_REHEARSAL_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_observability_contract", payload["status"])
        self.assertTrue(payload["guarded_start_execution_rehearsal_contract_implemented"])
        self.assertTrue(payload["execution_rehearsal_contract_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_executor_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_execution_attempt_contract_ready"])
        self.assertTrue(payload["gates"]["execution_rehearsal_authorization_ready"])
        self.assertTrue(payload["gates"]["pid_guard_rehearsed"])
        self.assertIn("ready_event_rehearsal", payload["required_rehearsal_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_EXECUTION_REHEARSAL_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_observability_contract", payload["next_action"])

    def test_guarded_start_execution_rehearsal_contract_blocks_when_start_is_allowed(self) -> None:
        payload = inspect_guarded_start_execution_rehearsal_contract(
            guarded_start_execution_attempt_contract=ready_guarded_start_execution_attempt_contract(),
            execution_rehearsal_authorization={
                **valid_guarded_start_execution_rehearsal_authorization(),
                "start_execution_allowed": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_execution_attempt_contract_ready"])
        self.assertFalse(payload["gates"]["execution_rehearsal_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertEqual("fix_guarded_start_execution_rehearsal_prerequisites", payload["next_action"])

    def test_guarded_start_observability_contract_attaches_without_starting(self) -> None:
        payload = inspect_guarded_start_observability_contract(
            guarded_start_execution_rehearsal_contract=ready_guarded_start_execution_rehearsal_contract(),
            observability_authorization=valid_guarded_start_observability_authorization(),
        )

        self.assertEqual(GUARDED_START_OBSERVABILITY_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_observability_authorization.v1",
            GUARDED_START_OBSERVABILITY_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_release_candidate_contract", payload["status"])
        self.assertTrue(payload["guarded_start_observability_contract_implemented"])
        self.assertTrue(payload["observability_contract_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_executor_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_execution_rehearsal_contract_ready"])
        self.assertTrue(payload["gates"]["observability_authorization_ready"])
        self.assertTrue(payload["gates"]["latency_slo_metrics_required"])
        self.assertIn("evidence_sink_required_before_launch", payload["required_observability_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_OBSERVABILITY_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_release_candidate_contract", payload["next_action"])

    def test_guarded_start_observability_contract_blocks_when_process_launch_is_allowed(self) -> None:
        payload = inspect_guarded_start_observability_contract(
            guarded_start_execution_rehearsal_contract=ready_guarded_start_execution_rehearsal_contract(),
            observability_authorization={
                **valid_guarded_start_observability_authorization(),
                "process_launch_allowed": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_execution_rehearsal_contract_ready"])
        self.assertFalse(payload["gates"]["observability_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertEqual("fix_guarded_start_observability_prerequisites", payload["next_action"])

    def test_guarded_start_release_candidate_contract_attaches_without_starting(self) -> None:
        payload = inspect_guarded_start_release_candidate_contract(
            guarded_start_observability_contract=ready_guarded_start_observability_contract(),
            release_candidate_authorization=valid_guarded_start_release_candidate_authorization(),
        )

        self.assertEqual(GUARDED_START_RELEASE_CANDIDATE_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_release_candidate_authorization.v1",
            GUARDED_START_RELEASE_CANDIDATE_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_operator_acceptance_contract", payload["status"])
        self.assertTrue(payload["guarded_start_release_candidate_contract_implemented"])
        self.assertTrue(payload["release_candidate_contract_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_executor_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_observability_contract_ready"])
        self.assertTrue(payload["gates"]["release_candidate_authorization_ready"])
        self.assertTrue(payload["gates"]["bundle_hash_attached"])
        self.assertIn("final_start_receipt_before_start", payload["required_release_candidate_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_RELEASE_CANDIDATE_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_operator_acceptance_contract", payload["next_action"])

    def test_guarded_start_release_candidate_contract_blocks_without_bundle_hash(self) -> None:
        authorization = valid_guarded_start_release_candidate_authorization()
        authorization["release_candidate_bundle_hash"] = ""
        payload = inspect_guarded_start_release_candidate_contract(
            guarded_start_observability_contract=ready_guarded_start_observability_contract(),
            release_candidate_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_observability_contract_ready"])
        self.assertFalse(payload["gates"]["release_candidate_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertEqual("fix_guarded_start_release_candidate_prerequisites", payload["next_action"])

    def test_guarded_start_operator_acceptance_contract_attaches_without_starting(self) -> None:
        payload = inspect_guarded_start_operator_acceptance_contract(
            guarded_start_release_candidate_contract=ready_guarded_start_release_candidate_contract(),
            operator_acceptance_authorization=valid_guarded_start_operator_acceptance_authorization(),
        )

        self.assertEqual(GUARDED_START_OPERATOR_ACCEPTANCE_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_operator_acceptance_authorization.v1",
            GUARDED_START_OPERATOR_ACCEPTANCE_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_final_start_receipt_contract", payload["status"])
        self.assertTrue(payload["guarded_start_operator_acceptance_contract_implemented"])
        self.assertTrue(payload["operator_acceptance_contract_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_executor_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_release_candidate_contract_ready"])
        self.assertTrue(payload["gates"]["operator_acceptance_authorization_ready"])
        self.assertTrue(payload["gates"]["operator_acceptance_explicit"])
        self.assertIn("final_start_receipt_before_start", payload["required_operator_acceptance_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_OPERATOR_ACCEPTANCE_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_final_start_receipt_contract", payload["next_action"])

    def test_guarded_start_operator_acceptance_contract_blocks_without_decision_receipt(self) -> None:
        authorization = valid_guarded_start_operator_acceptance_authorization()
        authorization["decision_receipt_id"] = ""
        payload = inspect_guarded_start_operator_acceptance_contract(
            guarded_start_release_candidate_contract=ready_guarded_start_release_candidate_contract(),
            operator_acceptance_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_release_candidate_contract_ready"])
        self.assertFalse(payload["gates"]["operator_acceptance_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertEqual("fix_guarded_start_operator_acceptance_prerequisites", payload["next_action"])

    def test_guarded_start_final_start_receipt_contract_attaches_without_starting(self) -> None:
        payload = inspect_guarded_start_final_start_receipt_contract(
            guarded_start_operator_acceptance_contract=ready_guarded_start_operator_acceptance_contract(),
            final_start_receipt_authorization=valid_guarded_start_final_start_receipt_authorization(),
        )

        self.assertEqual(GUARDED_START_FINAL_START_RECEIPT_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_final_start_receipt_authorization.v1",
            GUARDED_START_FINAL_START_RECEIPT_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_launch_window_contract", payload["status"])
        self.assertTrue(payload["guarded_start_final_start_receipt_contract_implemented"])
        self.assertTrue(payload["final_start_receipt_contract_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_executor_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_operator_acceptance_contract_ready"])
        self.assertTrue(payload["gates"]["final_start_receipt_authorization_ready"])
        self.assertTrue(payload["gates"]["receipt_fresh"])
        self.assertIn("fresh_final_start_receipt_before_launch_window", payload["required_final_start_receipt_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_FINAL_START_RECEIPT_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_launch_window_contract", payload["next_action"])

    def test_guarded_start_final_start_receipt_contract_blocks_without_receipt(self) -> None:
        authorization = valid_guarded_start_final_start_receipt_authorization()
        authorization["final_start_receipt_id"] = ""
        payload = inspect_guarded_start_final_start_receipt_contract(
            guarded_start_operator_acceptance_contract=ready_guarded_start_operator_acceptance_contract(),
            final_start_receipt_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_operator_acceptance_contract_ready"])
        self.assertFalse(payload["gates"]["final_start_receipt_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertEqual("fix_guarded_start_final_start_receipt_prerequisites", payload["next_action"])

    def test_guarded_start_launch_window_contract_attaches_without_starting(self) -> None:
        payload = inspect_guarded_start_launch_window_contract(
            guarded_start_final_start_receipt_contract=ready_guarded_start_final_start_receipt_contract(),
            launch_window_authorization=valid_guarded_start_launch_window_authorization(),
        )

        self.assertEqual(GUARDED_START_LAUNCH_WINDOW_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_launch_window_authorization.v1",
            GUARDED_START_LAUNCH_WINDOW_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_pre_launch_guard_contract", payload["status"])
        self.assertTrue(payload["guarded_start_launch_window_contract_implemented"])
        self.assertTrue(payload["launch_window_contract_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_executor_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_final_start_receipt_contract_ready"])
        self.assertTrue(payload["gates"]["launch_window_authorization_ready"])
        self.assertTrue(payload["gates"]["operator_present"])
        self.assertTrue(payload["gates"]["observability_armed"])
        self.assertIn("declared_launch_window_before_pre_launch_guard", payload["required_launch_window_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_LAUNCH_WINDOW_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_pre_launch_guard_contract", payload["next_action"])

    def test_guarded_start_launch_window_contract_blocks_when_authorization_is_not_approved(self) -> None:
        authorization = valid_guarded_start_launch_window_authorization()
        authorization["status"] = "pending_review"
        payload = inspect_guarded_start_launch_window_contract(
            guarded_start_final_start_receipt_contract=ready_guarded_start_final_start_receipt_contract(),
            launch_window_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_final_start_receipt_contract_ready"])
        self.assertFalse(payload["gates"]["launch_window_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertEqual("fix_guarded_start_launch_window_prerequisites", payload["next_action"])

    def test_guarded_start_pre_launch_guard_contract_attaches_without_starting(self) -> None:
        payload = inspect_guarded_start_pre_launch_guard_contract(
            guarded_start_launch_window_contract=ready_guarded_start_launch_window_contract(),
            pre_launch_guard_authorization=valid_guarded_start_pre_launch_guard_authorization(),
        )

        self.assertEqual(GUARDED_START_PRE_LAUNCH_GUARD_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_pre_launch_guard_authorization.v1",
            GUARDED_START_PRE_LAUNCH_GUARD_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_executor_runtime_contract", payload["status"])
        self.assertTrue(payload["guarded_start_pre_launch_guard_contract_implemented"])
        self.assertTrue(payload["pre_launch_guard_contract_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_executor_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_launch_window_contract_ready"])
        self.assertTrue(payload["gates"]["pre_launch_guard_authorization_ready"])
        self.assertTrue(payload["gates"]["kernel_health_fresh"])
        self.assertTrue(payload["gates"]["token_lease_fresh"])
        self.assertTrue(payload["gates"]["callback_router_fresh"])
        self.assertIn("fresh_kernel_health_before_executor_runtime", payload["required_pre_launch_guard_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_PRE_LAUNCH_GUARD_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_executor_runtime_contract", payload["next_action"])

    def test_guarded_start_pre_launch_guard_contract_blocks_when_authorization_is_not_approved(self) -> None:
        authorization = valid_guarded_start_pre_launch_guard_authorization()
        authorization["status"] = "pending_review"
        payload = inspect_guarded_start_pre_launch_guard_contract(
            guarded_start_launch_window_contract=ready_guarded_start_launch_window_contract(),
            pre_launch_guard_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_launch_window_contract_ready"])
        self.assertFalse(payload["gates"]["pre_launch_guard_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertEqual("fix_guarded_start_pre_launch_guard_prerequisites", payload["next_action"])

    def test_guarded_start_executor_runtime_contract_attaches_without_starting(self) -> None:
        payload = inspect_guarded_start_executor_runtime_contract(
            guarded_start_pre_launch_guard_contract=ready_guarded_start_pre_launch_guard_contract(),
            executor_runtime_authorization=valid_guarded_start_executor_runtime_authorization(),
        )

        self.assertEqual(GUARDED_START_EXECUTOR_RUNTIME_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_executor_runtime_authorization.v1",
            GUARDED_START_EXECUTOR_RUNTIME_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_process_spawn_contract", payload["status"])
        self.assertTrue(payload["guarded_start_executor_runtime_contract_implemented"])
        self.assertTrue(payload["executor_runtime_contract_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_executor_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_pre_launch_guard_contract_ready"])
        self.assertTrue(payload["gates"]["executor_runtime_authorization_ready"])
        self.assertTrue(payload["gates"]["runtime_family_allowed"])
        self.assertTrue(payload["gates"]["env_contract_attached"])
        self.assertTrue(payload["gates"]["pid_guard_configured"])
        self.assertIn("python_ai_data_runtime_before_process_spawn", payload["required_executor_runtime_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_EXECUTOR_RUNTIME_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_process_spawn_contract", payload["next_action"])

    def test_guarded_start_executor_runtime_contract_blocks_when_authorization_is_not_approved(self) -> None:
        authorization = valid_guarded_start_executor_runtime_authorization()
        authorization["status"] = "pending_review"
        payload = inspect_guarded_start_executor_runtime_contract(
            guarded_start_pre_launch_guard_contract=ready_guarded_start_pre_launch_guard_contract(),
            executor_runtime_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_pre_launch_guard_contract_ready"])
        self.assertFalse(payload["gates"]["executor_runtime_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertEqual("fix_guarded_start_executor_runtime_prerequisites", payload["next_action"])

    def test_guarded_start_process_spawn_contract_declares_spawn_without_starting(self) -> None:
        payload = inspect_guarded_start_process_spawn_contract(
            guarded_start_executor_runtime_contract=ready_guarded_start_executor_runtime_contract(),
            process_spawn_authorization=valid_guarded_start_process_spawn_authorization(),
        )

        self.assertEqual(GUARDED_START_PROCESS_SPAWN_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_process_spawn_authorization.v1",
            GUARDED_START_PROCESS_SPAWN_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_spawn_review_contract", payload["status"])
        self.assertTrue(payload["guarded_start_process_spawn_contract_implemented"])
        self.assertTrue(payload["process_spawn_contract_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_executor_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertTrue(payload["gates"]["guarded_start_executor_runtime_contract_ready"])
        self.assertTrue(payload["gates"]["process_spawn_authorization_ready"])
        self.assertTrue(payload["gates"]["cwd_confined"])
        self.assertTrue(payload["gates"]["startup_timeout_configured"])
        self.assertIn("confined_cwd_before_process_spawn", payload["required_process_spawn_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_PROCESS_SPAWN_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_spawn_review_contract", payload["next_action"])

    def test_guarded_start_process_spawn_contract_blocks_when_authorization_allows_process_launch(self) -> None:
        authorization = valid_guarded_start_process_spawn_authorization()
        authorization["process_launch_allowed"] = True
        payload = inspect_guarded_start_process_spawn_contract(
            guarded_start_executor_runtime_contract=ready_guarded_start_executor_runtime_contract(),
            process_spawn_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_executor_runtime_contract_ready"])
        self.assertFalse(payload["gates"]["process_spawn_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertEqual("fix_guarded_start_process_spawn_prerequisites", payload["next_action"])

    def test_guarded_start_spawn_review_contract_reviews_spawn_without_importing_subprocess(self) -> None:
        payload = inspect_guarded_start_spawn_review_contract(
            guarded_start_process_spawn_contract=ready_guarded_start_process_spawn_contract(),
            spawn_review_authorization=valid_guarded_start_spawn_review_authorization(),
        )

        self.assertEqual(GUARDED_START_SPAWN_REVIEW_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_spawn_review_authorization.v1",
            GUARDED_START_SPAWN_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_subprocess_import_contract", payload["status"])
        self.assertTrue(payload["guarded_start_spawn_review_contract_implemented"])
        self.assertTrue(payload["spawn_review_contract_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_spawn_contract_ready"])
        self.assertTrue(payload["gates"]["technical_review_completed"])
        self.assertTrue(payload["gates"]["bundle_hash_reviewed"])
        self.assertIn("technical_review_before_subprocess_import", payload["required_spawn_review_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_SPAWN_REVIEW_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_subprocess_import_contract", payload["next_action"])

    def test_guarded_start_spawn_review_contract_blocks_when_bundle_hash_is_missing(self) -> None:
        authorization = valid_guarded_start_spawn_review_authorization()
        authorization["reviewed_bundle_hash"] = ""
        payload = inspect_guarded_start_spawn_review_contract(
            guarded_start_process_spawn_contract=ready_guarded_start_process_spawn_contract(),
            spawn_review_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_process_spawn_contract_ready"])
        self.assertFalse(payload["gates"]["spawn_review_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_spawn_review_prerequisites", payload["next_action"])

    def test_guarded_start_subprocess_import_contract_declares_boundary_without_importing(self) -> None:
        payload = inspect_guarded_start_subprocess_import_contract(
            guarded_start_spawn_review_contract=ready_guarded_start_spawn_review_contract(),
            subprocess_import_authorization=valid_guarded_start_subprocess_import_authorization(),
        )

        self.assertEqual(GUARDED_START_SUBPROCESS_IMPORT_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_subprocess_import_authorization.v1",
            GUARDED_START_SUBPROCESS_IMPORT_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_launch_invocation_contract", payload["status"])
        self.assertTrue(payload["guarded_start_subprocess_import_contract_implemented"])
        self.assertTrue(payload["subprocess_import_contract_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_spawn_review_contract_ready"])
        self.assertTrue(payload["gates"]["subprocess_import_authorization_ready"])
        self.assertTrue(payload["gates"]["executor_only_import_required"])
        self.assertIn("executor_only_import_before_launch_invocation", payload["required_subprocess_import_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_SUBPROCESS_IMPORT_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_launch_invocation_contract", payload["next_action"])

    def test_guarded_start_subprocess_import_contract_blocks_when_import_is_allowed(self) -> None:
        authorization = valid_guarded_start_subprocess_import_authorization()
        authorization["subprocess_module_import_allowed"] = True
        payload = inspect_guarded_start_subprocess_import_contract(
            guarded_start_spawn_review_contract=ready_guarded_start_spawn_review_contract(),
            subprocess_import_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_spawn_review_contract_ready"])
        self.assertFalse(payload["gates"]["subprocess_import_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_subprocess_import_prerequisites", payload["next_action"])

    def test_guarded_start_launch_invocation_contract_declares_invocation_without_launching(self) -> None:
        payload = inspect_guarded_start_launch_invocation_contract(
            guarded_start_subprocess_import_contract=ready_guarded_start_subprocess_import_contract(),
            launch_invocation_authorization=valid_guarded_start_launch_invocation_authorization(),
        )

        self.assertEqual(GUARDED_START_LAUNCH_INVOCATION_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_launch_invocation_authorization.v1",
            GUARDED_START_LAUNCH_INVOCATION_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_final_process_start_contract", payload["status"])
        self.assertTrue(payload["guarded_start_launch_invocation_contract_implemented"])
        self.assertTrue(payload["launch_invocation_contract_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_subprocess_import_contract_ready"])
        self.assertTrue(payload["gates"]["command_template_reviewed"])
        self.assertTrue(payload["gates"]["env_redacted"])
        self.assertIn("command_template_review_before_launch_invocation", payload["required_launch_invocation_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_LAUNCH_INVOCATION_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_final_process_start_contract", payload["next_action"])

    def test_guarded_start_launch_invocation_contract_blocks_when_start_is_allowed(self) -> None:
        authorization = valid_guarded_start_launch_invocation_authorization()
        authorization["start_execution_allowed"] = True
        payload = inspect_guarded_start_launch_invocation_contract(
            guarded_start_subprocess_import_contract=ready_guarded_start_subprocess_import_contract(),
            launch_invocation_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_subprocess_import_contract_ready"])
        self.assertFalse(payload["gates"]["launch_invocation_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_launch_invocation_prerequisites", payload["next_action"])

    def test_guarded_start_final_process_start_contract_declares_boundary_without_starting(self) -> None:
        payload = inspect_guarded_start_final_process_start_contract(
            guarded_start_launch_invocation_contract=ready_guarded_start_launch_invocation_contract(),
            final_process_start_authorization=valid_guarded_start_final_process_start_authorization(),
        )

        self.assertEqual(GUARDED_START_FINAL_PROCESS_START_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_final_process_start_authorization.v1",
            GUARDED_START_FINAL_PROCESS_START_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_process_execution_review", payload["status"])
        self.assertTrue(payload["guarded_start_final_process_start_contract_implemented"])
        self.assertTrue(payload["final_process_start_contract_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_allowed"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_launch_invocation_contract_ready"])
        self.assertTrue(payload["gates"]["decision_receipt_fresh"])
        self.assertTrue(payload["gates"]["single_start_per_receipt_required"])
        self.assertIn("fresh_decision_receipt_before_final_process_start", payload["required_final_process_start_controls"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_FINAL_PROCESS_START_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_process_execution_review", payload["next_action"])

    def test_guarded_start_final_process_start_contract_blocks_when_process_launch_is_allowed(self) -> None:
        authorization = valid_guarded_start_final_process_start_authorization()
        authorization["process_launch_allowed"] = True
        payload = inspect_guarded_start_final_process_start_contract(
            guarded_start_launch_invocation_contract=ready_guarded_start_launch_invocation_contract(),
            final_process_start_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_launch_invocation_contract_ready"])
        self.assertFalse(payload["gates"]["final_process_start_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_final_process_start_prerequisites", payload["next_action"])

    def test_guarded_start_process_execution_review_declares_boundary_without_starting(self) -> None:
        payload = inspect_guarded_start_process_execution_review(
            guarded_start_final_process_start_contract=ready_guarded_start_final_process_start_contract(),
            process_execution_review_authorization=valid_guarded_start_process_execution_review_authorization(),
        )

        self.assertEqual(GUARDED_START_PROCESS_EXECUTION_REVIEW_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_process_execution_review_authorization.v1",
            GUARDED_START_PROCESS_EXECUTION_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_process_execution_packet", payload["status"])
        self.assertTrue(payload["guarded_start_process_execution_review_implemented"])
        self.assertTrue(payload["process_execution_review_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_allowed"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_final_process_start_contract_ready"])
        self.assertTrue(payload["gates"]["technical_review_completed"])
        self.assertTrue(payload["gates"]["receipt_bound_to_final_start"])
        self.assertIn(
            "rollback_and_observability_review_before_process_execution_packet",
            payload["required_process_execution_review_controls"],
        )
        self.assertIn("VOICE_DAEMON_GUARDED_START_PROCESS_EXECUTION_REVIEWED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_process_execution_packet", payload["next_action"])

    def test_guarded_start_process_execution_review_blocks_when_start_is_allowed(self) -> None:
        authorization = valid_guarded_start_process_execution_review_authorization()
        authorization["start_execution_allowed"] = True
        payload = inspect_guarded_start_process_execution_review(
            guarded_start_final_process_start_contract=ready_guarded_start_final_process_start_contract(),
            process_execution_review_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_final_process_start_contract_ready"])
        self.assertFalse(payload["gates"]["process_execution_review_authorization_ready"])

    def test_guarded_start_process_execution_packet_declares_boundary_without_starting(self) -> None:
        payload = inspect_guarded_start_process_execution_packet(
            guarded_start_process_execution_review=ready_guarded_start_process_execution_review(),
            process_execution_packet_authorization=valid_guarded_start_process_execution_packet_authorization(),
        )

        self.assertEqual(GUARDED_START_PROCESS_EXECUTION_PACKET_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_process_execution_packet_authorization.v1",
            GUARDED_START_PROCESS_EXECUTION_PACKET_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_process_executor_stub", payload["status"])
        self.assertTrue(payload["guarded_start_process_execution_packet_implemented"])
        self.assertTrue(payload["process_execution_packet_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_allowed"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_execution_review_ready"])
        self.assertTrue(payload["gates"]["receipt_bound_to_execution_packet"])
        self.assertTrue(payload["gates"]["argv_redacted"])
        self.assertTrue(payload["gates"]["env_redacted"])
        self.assertIn(
            "rollback_and_observability_before_executor_stub",
            payload["required_process_execution_packet_controls"],
        )
        self.assertIn("VOICE_DAEMON_GUARDED_START_PROCESS_EXECUTION_PACKET_ATTACHED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_process_executor_stub", payload["next_action"])

    def test_guarded_start_process_execution_packet_blocks_when_process_launch_is_allowed(self) -> None:
        authorization = valid_guarded_start_process_execution_packet_authorization()
        authorization["process_launch_allowed"] = True
        payload = inspect_guarded_start_process_execution_packet(
            guarded_start_process_execution_review=ready_guarded_start_process_execution_review(),
            process_execution_packet_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_process_execution_review_ready"])
        self.assertFalse(payload["gates"]["process_execution_packet_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_process_execution_packet_prerequisites", payload["next_action"])

    def test_guarded_start_process_executor_stub_declares_boundary_without_starting(self) -> None:
        payload = inspect_guarded_start_process_executor_stub(
            guarded_start_process_execution_packet=ready_guarded_start_process_execution_packet(),
            process_executor_stub_authorization=valid_guarded_start_process_executor_stub_authorization(),
        )

        self.assertEqual(GUARDED_START_PROCESS_EXECUTOR_STUB_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_process_executor_stub_authorization.v1",
            GUARDED_START_PROCESS_EXECUTOR_STUB_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_guarded_start_process_executor_review", payload["status"])
        self.assertTrue(payload["guarded_start_process_executor_stub_implemented"])
        self.assertTrue(payload["process_executor_stub_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_allowed"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_execution_packet_ready"])
        self.assertTrue(payload["gates"]["localized_subprocess_import_required"])
        self.assertTrue(payload["gates"]["pid_guard_required"])
        self.assertIn(
            "rollback_and_observability_before_real_executor",
            payload["required_process_executor_stub_controls"],
        )
        self.assertIn("VOICE_DAEMON_GUARDED_START_PROCESS_EXECUTOR_STUB_DECLARED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_process_executor_review", payload["next_action"])

    def test_guarded_start_process_executor_stub_blocks_when_subprocess_import_is_allowed(self) -> None:
        authorization = valid_guarded_start_process_executor_stub_authorization()
        authorization["subprocess_module_import_allowed"] = True
        payload = inspect_guarded_start_process_executor_stub(
            guarded_start_process_execution_packet=ready_guarded_start_process_execution_packet(),
            process_executor_stub_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_process_execution_packet_ready"])
        self.assertFalse(payload["gates"]["process_executor_stub_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_process_executor_stub_prerequisites", payload["next_action"])

    def test_guarded_start_process_executor_review_is_review_only_without_process_start(self) -> None:
        payload = inspect_guarded_start_process_executor_review(
            guarded_start_process_executor_stub=ready_guarded_start_process_executor_stub(),
            process_executor_review_authorization=valid_guarded_start_process_executor_review_authorization(),
        )

        self.assertEqual(GUARDED_START_PROCESS_EXECUTOR_REVIEW_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("ready_for_guarded_start_process_executor_contract", payload["status"])
        self.assertTrue(payload["process_executor_review_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_allowed"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_executor_stub_ready"])
        self.assertTrue(payload["gates"]["localized_subprocess_import_reviewed"])
        self.assertTrue(payload["gates"]["pid_guard_reviewed"])
        self.assertIn(
            "rollback_and_observability_review_before_executor_contract",
            payload["required_process_executor_review_controls"],
        )
        self.assertIn("VOICE_DAEMON_GUARDED_START_PROCESS_EXECUTOR_REVIEWED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_process_executor_contract", payload["next_action"])

    def test_guarded_start_process_executor_review_blocks_when_subprocess_import_is_allowed(self) -> None:
        authorization = valid_guarded_start_process_executor_review_authorization()
        authorization["subprocess_module_import_allowed"] = True
        payload = inspect_guarded_start_process_executor_review(
            guarded_start_process_executor_stub=ready_guarded_start_process_executor_stub(),
            process_executor_review_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_process_executor_stub_ready"])
        self.assertFalse(payload["gates"]["process_executor_review_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_process_executor_review_prerequisites", payload["next_action"])

    def test_guarded_start_process_executor_contract_is_contract_only_without_process_start(self) -> None:
        payload = inspect_guarded_start_process_executor_contract(
            guarded_start_process_executor_review=ready_guarded_start_process_executor_review(),
            process_executor_contract_authorization=valid_guarded_start_process_executor_contract_authorization(),
        )

        self.assertEqual(GUARDED_START_PROCESS_EXECUTOR_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("ready_for_guarded_start_process_runtime_adapter", payload["status"])
        self.assertTrue(payload["process_executor_contract_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_allowed"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_executor_review_ready"])
        self.assertTrue(payload["gates"]["localized_subprocess_import_contract_required"])
        self.assertTrue(payload["gates"]["pid_guard_contract_required"])
        self.assertIn(
            "rollback_and_observability_contract_before_runtime_adapter",
            payload["required_process_executor_contract_controls"],
        )
        self.assertIn("VOICE_DAEMON_GUARDED_START_PROCESS_EXECUTOR_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_process_runtime_adapter", payload["next_action"])

    def test_guarded_start_process_executor_contract_blocks_when_subprocess_import_is_allowed(self) -> None:
        authorization = valid_guarded_start_process_executor_contract_authorization()
        authorization["subprocess_module_import_allowed"] = True
        payload = inspect_guarded_start_process_executor_contract(
            guarded_start_process_executor_review=ready_guarded_start_process_executor_review(),
            process_executor_contract_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_process_executor_review_ready"])
        self.assertFalse(payload["gates"]["process_executor_contract_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_process_executor_contract_prerequisites", payload["next_action"])

    def test_guarded_start_process_runtime_adapter_is_contract_only_without_process_start(self) -> None:
        payload = inspect_guarded_start_process_runtime_adapter(
            guarded_start_process_executor_contract=ready_guarded_start_process_executor_contract(),
            process_runtime_adapter_authorization=valid_guarded_start_process_runtime_adapter_authorization(),
        )

        self.assertEqual(GUARDED_START_PROCESS_RUNTIME_ADAPTER_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("ready_for_guarded_start_process_adapter_review", payload["status"])
        self.assertTrue(payload["process_runtime_adapter_only"])
        self.assertTrue(payload["runtime_adapter_contract_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_allowed"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_executor_contract_ready"])
        self.assertTrue(payload["gates"]["localized_subprocess_import_boundary_required"])
        self.assertTrue(payload["gates"]["pid_guard_adapter_required"])
        self.assertIn(
            "rollback_and_observability_adapter_before_review",
            payload["required_process_runtime_adapter_controls"],
        )
        self.assertIn("VOICE_DAEMON_GUARDED_START_PROCESS_RUNTIME_ADAPTER_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_process_adapter_review", payload["next_action"])

    def test_guarded_start_process_runtime_adapter_blocks_when_start_is_allowed(self) -> None:
        authorization = valid_guarded_start_process_runtime_adapter_authorization()
        authorization["start_execution_allowed"] = True
        payload = inspect_guarded_start_process_runtime_adapter(
            guarded_start_process_executor_contract=ready_guarded_start_process_executor_contract(),
            process_runtime_adapter_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_process_executor_contract_ready"])
        self.assertFalse(payload["gates"]["process_runtime_adapter_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_process_runtime_adapter_prerequisites", payload["next_action"])

    def test_guarded_start_process_adapter_review_is_review_only_without_process_start(self) -> None:
        payload = inspect_guarded_start_process_adapter_review(
            guarded_start_process_runtime_adapter=ready_guarded_start_process_runtime_adapter(),
            process_adapter_review_authorization=valid_guarded_start_process_adapter_review_authorization(),
        )

        self.assertEqual(GUARDED_START_PROCESS_ADAPTER_REVIEW_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("ready_for_guarded_start_process_adapter_contract", payload["status"])
        self.assertTrue(payload["process_adapter_review_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_allowed"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runtime_adapter_ready"])
        self.assertTrue(payload["gates"]["technical_review_completed"])
        self.assertTrue(payload["gates"]["localized_subprocess_import_boundary_reviewed"])
        self.assertTrue(payload["gates"]["pid_guard_adapter_reviewed"])
        self.assertIn(
            "rollback_and_observability_adapter_review_before_adapter_contract",
            payload["required_process_adapter_review_controls"],
        )
        self.assertIn("VOICE_DAEMON_GUARDED_START_PROCESS_ADAPTER_REVIEWED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_process_adapter_contract", payload["next_action"])

    def test_guarded_start_process_adapter_review_blocks_when_subprocess_import_is_allowed(self) -> None:
        authorization = valid_guarded_start_process_adapter_review_authorization()
        authorization["subprocess_module_import_allowed"] = True
        payload = inspect_guarded_start_process_adapter_review(
            guarded_start_process_runtime_adapter=ready_guarded_start_process_runtime_adapter(),
            process_adapter_review_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_process_runtime_adapter_ready"])
        self.assertFalse(payload["gates"]["process_adapter_review_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_process_adapter_review_prerequisites", payload["next_action"])

    def test_guarded_start_process_adapter_contract_is_contract_only_without_process_start(self) -> None:
        payload = inspect_guarded_start_process_adapter_contract(
            guarded_start_process_adapter_review=ready_guarded_start_process_adapter_review(),
            process_adapter_contract_authorization=valid_guarded_start_process_adapter_contract_authorization(),
        )

        self.assertEqual(GUARDED_START_PROCESS_ADAPTER_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("ready_for_guarded_start_process_runner_contract", payload["status"])
        self.assertTrue(payload["process_adapter_contract_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_allowed"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_adapter_review_ready"])
        self.assertTrue(payload["gates"]["runtime_adapter_contract_required"])
        self.assertTrue(payload["gates"]["localized_subprocess_import_boundary_required"])
        self.assertTrue(payload["gates"]["pid_guard_adapter_contract_required"])
        self.assertIn(
            "rollback_and_observability_adapter_contract_before_runner_contract",
            payload["required_process_adapter_contract_controls"],
        )
        self.assertIn("VOICE_DAEMON_GUARDED_START_PROCESS_ADAPTER_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_process_runner_contract", payload["next_action"])

    def test_guarded_start_process_adapter_contract_blocks_when_start_is_allowed(self) -> None:
        authorization = valid_guarded_start_process_adapter_contract_authorization()
        authorization["start_execution_allowed"] = True
        payload = inspect_guarded_start_process_adapter_contract(
            guarded_start_process_adapter_review=ready_guarded_start_process_adapter_review(),
            process_adapter_contract_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_process_adapter_review_ready"])
        self.assertFalse(payload["gates"]["process_adapter_contract_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_process_adapter_contract_prerequisites", payload["next_action"])

    def test_guarded_start_process_runner_contract_is_contract_only_without_process_start(self) -> None:
        payload = inspect_guarded_start_process_runner_contract(
            guarded_start_process_adapter_contract=ready_guarded_start_process_adapter_contract(),
            process_runner_contract_authorization=valid_guarded_start_process_runner_contract_authorization(),
        )

        self.assertEqual(GUARDED_START_PROCESS_RUNNER_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("ready_for_guarded_start_process_runner_review", payload["status"])
        self.assertTrue(payload["process_runner_contract_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_allowed"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_adapter_contract_ready"])
        self.assertTrue(payload["gates"]["single_start_receipt_required"])
        self.assertTrue(payload["gates"]["pid_guard_runner_required"])
        self.assertTrue(payload["gates"]["stdout_stderr_sanitizers_runner_required"])
        self.assertIn(
            "rollback_and_observability_runner_before_runner_review",
            payload["required_process_runner_contract_controls"],
        )
        self.assertIn("VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_process_runner_review", payload["next_action"])

    def test_guarded_start_process_runner_contract_blocks_when_subprocess_import_is_allowed(self) -> None:
        authorization = valid_guarded_start_process_runner_contract_authorization()
        authorization["subprocess_module_import_allowed"] = True
        payload = inspect_guarded_start_process_runner_contract(
            guarded_start_process_adapter_contract=ready_guarded_start_process_adapter_contract(),
            process_runner_contract_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_process_adapter_contract_ready"])
        self.assertFalse(payload["gates"]["process_runner_contract_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_process_runner_contract_prerequisites", payload["next_action"])

    def test_guarded_start_process_runner_review_is_review_only_without_process_start(self) -> None:
        payload = inspect_guarded_start_process_runner_review(
            guarded_start_process_runner_contract=ready_guarded_start_process_runner_contract(),
            process_runner_review_authorization=valid_guarded_start_process_runner_review_authorization(),
        )

        self.assertEqual(GUARDED_START_PROCESS_RUNNER_REVIEW_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("ready_for_guarded_start_process_runner_packet", payload["status"])
        self.assertTrue(payload["process_runner_review_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_allowed"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_contract_ready"])
        self.assertTrue(payload["gates"]["technical_review_completed"])
        self.assertTrue(payload["gates"]["single_start_receipt_reviewed"])
        self.assertTrue(payload["gates"]["pid_guard_runner_reviewed"])
        self.assertTrue(payload["gates"]["stdout_stderr_sanitizers_runner_reviewed"])
        self.assertIn(
            "rollback_and_observability_runner_reviewed_before_runner_packet",
            payload["reviewed_process_runner_controls"],
        )
        self.assertIn("VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_REVIEWED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_process_runner_packet", payload["next_action"])

    def test_guarded_start_process_runner_review_blocks_when_start_is_allowed(self) -> None:
        authorization = valid_guarded_start_process_runner_review_authorization()
        authorization["start_execution_allowed"] = True
        payload = inspect_guarded_start_process_runner_review(
            guarded_start_process_runner_contract=ready_guarded_start_process_runner_contract(),
            process_runner_review_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_contract_ready"])
        self.assertFalse(payload["gates"]["process_runner_review_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_process_runner_review_prerequisites", payload["next_action"])

    def test_guarded_start_process_runner_packet_is_packet_only_without_process_start(self) -> None:
        payload = inspect_guarded_start_process_runner_packet(
            guarded_start_process_runner_review=ready_guarded_start_process_runner_review(),
            process_runner_packet_authorization=valid_guarded_start_process_runner_packet_authorization(),
        )

        self.assertEqual(GUARDED_START_PROCESS_RUNNER_PACKET_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("ready_for_guarded_start_process_runner_execution_review", payload["status"])
        self.assertTrue(payload["process_runner_packet_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_allowed"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_review_ready"])
        self.assertTrue(payload["gates"]["decision_receipt_attached"])
        self.assertTrue(payload["gates"]["argv_env_cwd_redacted"])
        self.assertTrue(payload["gates"]["pid_guard_attached"])
        self.assertTrue(payload["gates"]["stdout_stderr_sanitizers_attached"])
        self.assertIn(
            "stream_sanitization_rollback_observability_attached_before_runner_execution_review",
            payload["attached_process_runner_packet_controls"],
        )
        self.assertIn("VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_PACKET_ATTACHED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_process_runner_execution_review", payload["next_action"])

    def test_guarded_start_process_runner_packet_blocks_when_launch_is_allowed(self) -> None:
        authorization = valid_guarded_start_process_runner_packet_authorization()
        authorization["process_launch_allowed"] = True
        payload = inspect_guarded_start_process_runner_packet(
            guarded_start_process_runner_review=ready_guarded_start_process_runner_review(),
            process_runner_packet_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_review_ready"])
        self.assertFalse(payload["gates"]["process_runner_packet_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_process_runner_packet_prerequisites", payload["next_action"])

    def test_guarded_start_process_runner_execution_review_is_review_only_without_process_start(self) -> None:
        payload = inspect_guarded_start_process_runner_execution_review(
            guarded_start_process_runner_packet=ready_guarded_start_process_runner_packet(),
            process_runner_execution_review_authorization=valid_guarded_start_process_runner_execution_review_authorization(),
        )

        self.assertEqual(GUARDED_START_PROCESS_RUNNER_EXECUTION_REVIEW_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("ready_for_guarded_start_process_runner_execution_contract", payload["status"])
        self.assertTrue(payload["process_runner_execution_review_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_allowed"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_packet_ready"])
        self.assertTrue(payload["gates"]["decision_receipt_reviewed"])
        self.assertTrue(payload["gates"]["argv_env_cwd_reviewed"])
        self.assertTrue(payload["gates"]["pid_guard_reviewed"])
        self.assertTrue(payload["gates"]["stdout_stderr_sanitizers_reviewed"])
        self.assertIn(
            "stream_sanitization_rollback_observability_reviewed_before_execution_contract",
            payload["reviewed_process_runner_execution_controls"],
        )
        self.assertIn("VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_EXECUTION_REVIEWED", payload["evidence_events"])
        self.assertEqual("implement_guarded_start_process_runner_execution_contract", payload["next_action"])

    def test_guarded_start_process_runner_execution_review_blocks_when_subprocess_import_is_allowed(self) -> None:
        authorization = valid_guarded_start_process_runner_execution_review_authorization()
        authorization["subprocess_module_import_allowed"] = True
        payload = inspect_guarded_start_process_runner_execution_review(
            guarded_start_process_runner_packet=ready_guarded_start_process_runner_packet(),
            process_runner_execution_review_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_packet_ready"])
        self.assertFalse(payload["gates"]["process_runner_execution_review_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_process_runner_execution_review_prerequisites", payload["next_action"])

    def test_guarded_start_process_runner_execution_contract_is_contract_only_without_process_start(self) -> None:
        payload = inspect_guarded_start_process_runner_execution_contract(
            guarded_start_process_runner_execution_review=ready_guarded_start_process_runner_execution_review(),
            process_runner_execution_contract_authorization=(
                valid_guarded_start_process_runner_execution_contract_authorization()
            ),
        )

        self.assertEqual(GUARDED_START_PROCESS_RUNNER_EXECUTION_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("ready_for_guarded_start_process_runner_start_gate", payload["status"])
        self.assertTrue(payload["process_runner_execution_contract_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_allowed"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_execution_review_ready"])
        self.assertTrue(payload["gates"]["decision_receipt_bound"])
        self.assertTrue(payload["gates"]["argv_env_cwd_bound"])
        self.assertTrue(payload["gates"]["pid_guard_bound"])
        self.assertTrue(payload["gates"]["stdout_stderr_sanitizers_bound"])
        self.assertIn(
            "stream_sanitization_rollback_observability_bound_before_start_gate",
            payload["bound_process_runner_execution_controls"],
        )
        self.assertIn(
            "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_EXECUTION_CONTRACT_EVALUATED",
            payload["evidence_events"],
        )
        self.assertEqual("implement_guarded_start_process_runner_start_gate", payload["next_action"])

    def test_guarded_start_process_runner_execution_contract_blocks_when_launch_is_allowed(self) -> None:
        authorization = valid_guarded_start_process_runner_execution_contract_authorization()
        authorization["process_launch_allowed"] = True
        payload = inspect_guarded_start_process_runner_execution_contract(
            guarded_start_process_runner_execution_review=ready_guarded_start_process_runner_execution_review(),
            process_runner_execution_contract_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_execution_review_ready"])
        self.assertFalse(payload["gates"]["process_runner_execution_contract_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_process_runner_execution_contract_prerequisites", payload["next_action"])

    def test_guarded_start_process_runner_start_gate_is_gate_only_without_process_start(self) -> None:
        payload = inspect_guarded_start_process_runner_start_gate(
            guarded_start_process_runner_execution_contract=ready_guarded_start_process_runner_execution_contract(),
            process_runner_start_gate_authorization=valid_guarded_start_process_runner_start_gate_authorization(),
        )

        self.assertEqual(GUARDED_START_PROCESS_RUNNER_START_GATE_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("ready_for_guarded_start_process_runner_final_review", payload["status"])
        self.assertTrue(payload["process_runner_start_gate_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_allowed"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_execution_contract_ready"])
        self.assertTrue(payload["gates"]["final_receipt_required"])
        self.assertTrue(payload["gates"]["single_start_required"])
        self.assertTrue(payload["gates"]["pid_guard_required"])
        self.assertTrue(payload["gates"]["stdout_stderr_sanitizers_required"])
        self.assertIn(
            "stream_sanitization_rollback_observability_required_before_final_review",
            payload["required_process_runner_start_gate_controls"],
        )
        self.assertIn(
            "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_START_GATE_EVALUATED",
            payload["evidence_events"],
        )
        self.assertEqual("implement_guarded_start_process_runner_final_review", payload["next_action"])

    def test_guarded_start_process_runner_start_gate_blocks_when_start_execution_is_allowed(self) -> None:
        authorization = valid_guarded_start_process_runner_start_gate_authorization()
        authorization["start_execution_allowed"] = True
        payload = inspect_guarded_start_process_runner_start_gate(
            guarded_start_process_runner_execution_contract=ready_guarded_start_process_runner_execution_contract(),
            process_runner_start_gate_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_execution_contract_ready"])
        self.assertFalse(payload["gates"]["process_runner_start_gate_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_process_runner_start_gate_prerequisites", payload["next_action"])

    def test_guarded_start_process_runner_final_review_is_review_only_without_process_start(self) -> None:
        payload = inspect_guarded_start_process_runner_final_review(
            guarded_start_process_runner_start_gate=ready_guarded_start_process_runner_start_gate(),
            process_runner_final_review_authorization=valid_guarded_start_process_runner_final_review_authorization(),
        )

        self.assertEqual(GUARDED_START_PROCESS_RUNNER_FINAL_REVIEW_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("ready_for_guarded_start_process_runner_promotion_packet", payload["status"])
        self.assertTrue(payload["process_runner_final_review_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_allowed"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_start_gate_ready"])
        self.assertTrue(payload["gates"]["final_receipt_attached"])
        self.assertTrue(payload["gates"]["single_start_verified"])
        self.assertTrue(payload["gates"]["pid_guard_reviewed"])
        self.assertTrue(payload["gates"]["stdout_stderr_sanitizers_reviewed"])
        self.assertIn(
            "stream_sanitization_rollback_observability_reviewed_before_promotion_packet",
            payload["required_process_runner_final_review_controls"],
        )
        self.assertIn(
            "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_FINAL_REVIEWED",
            payload["evidence_events"],
        )
        self.assertEqual("implement_guarded_start_process_runner_promotion_packet", payload["next_action"])

    def test_guarded_start_process_runner_final_review_blocks_when_launch_is_allowed(self) -> None:
        authorization = valid_guarded_start_process_runner_final_review_authorization()
        authorization["process_launch_allowed"] = True
        payload = inspect_guarded_start_process_runner_final_review(
            guarded_start_process_runner_start_gate=ready_guarded_start_process_runner_start_gate(),
            process_runner_final_review_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_start_gate_ready"])
        self.assertFalse(payload["gates"]["process_runner_final_review_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_process_runner_final_review_prerequisites", payload["next_action"])

    def test_guarded_start_process_runner_promotion_packet_is_packet_only_without_process_start(self) -> None:
        payload = inspect_guarded_start_process_runner_promotion_packet(
            guarded_start_process_runner_final_review=ready_guarded_start_process_runner_final_review(),
            process_runner_promotion_packet_authorization=(
                valid_guarded_start_process_runner_promotion_packet_authorization()
            ),
        )

        self.assertEqual(GUARDED_START_PROCESS_RUNNER_PROMOTION_PACKET_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("ready_for_guarded_start_process_runner_operator_release", payload["status"])
        self.assertTrue(payload["process_runner_promotion_packet_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_allowed"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_final_review_ready"])
        self.assertTrue(payload["gates"]["final_review_receipt_attached"])
        self.assertTrue(payload["gates"]["bundle_hash_attached"])
        self.assertTrue(payload["gates"]["evidence_manifest_attached"])
        self.assertTrue(payload["gates"]["operator_release_review_required"])
        self.assertIn(
            "operator_release_review_before_any_start",
            payload["required_process_runner_promotion_packet_controls"],
        )
        self.assertIn(
            "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_PROMOTION_PACKET_ATTACHED",
            payload["evidence_events"],
        )
        self.assertEqual("implement_guarded_start_process_runner_operator_release_review", payload["next_action"])

    def test_guarded_start_process_runner_promotion_packet_blocks_when_start_is_allowed(self) -> None:
        authorization = valid_guarded_start_process_runner_promotion_packet_authorization()
        authorization["start_execution_allowed"] = True
        payload = inspect_guarded_start_process_runner_promotion_packet(
            guarded_start_process_runner_final_review=ready_guarded_start_process_runner_final_review(),
            process_runner_promotion_packet_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_final_review_ready"])
        self.assertFalse(payload["gates"]["process_runner_promotion_packet_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_process_runner_promotion_packet_prerequisites", payload["next_action"])

    def test_guarded_start_process_runner_operator_release_review_is_review_only_without_process_start(self) -> None:
        payload = inspect_guarded_start_process_runner_operator_release_review(
            guarded_start_process_runner_promotion_packet=ready_guarded_start_process_runner_promotion_packet(),
            operator_release_review_authorization=(
                valid_guarded_start_process_runner_operator_release_review_authorization()
            ),
        )

        self.assertEqual(GUARDED_START_PROCESS_RUNNER_OPERATOR_RELEASE_REVIEW_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("ready_for_guarded_start_process_runner_release_finalization", payload["status"])
        self.assertTrue(payload["process_runner_operator_release_review_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_allowed"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_promotion_packet_ready"])
        self.assertTrue(payload["gates"]["operator_review_completed"])
        self.assertTrue(payload["gates"]["bundle_hash_confirmed"])
        self.assertTrue(payload["gates"]["evidence_manifest_reviewed"])
        self.assertTrue(payload["gates"]["rollback_plan_reviewed"])
        self.assertIn(
            "final_operator_release_before_any_start",
            payload["required_process_runner_operator_release_review_controls"],
        )
        self.assertIn(
            "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_OPERATOR_RELEASE_REVIEWED",
            payload["evidence_events"],
        )
        self.assertEqual("implement_guarded_start_process_runner_release_finalization", payload["next_action"])

    def test_guarded_start_process_runner_operator_release_review_blocks_when_start_is_allowed(self) -> None:
        authorization = valid_guarded_start_process_runner_operator_release_review_authorization()
        authorization["start_execution_allowed"] = True
        payload = inspect_guarded_start_process_runner_operator_release_review(
            guarded_start_process_runner_promotion_packet=ready_guarded_start_process_runner_promotion_packet(),
            operator_release_review_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_promotion_packet_ready"])
        self.assertFalse(payload["gates"]["operator_release_review_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_process_runner_operator_release_review_prerequisites", payload["next_action"])

    def test_guarded_start_process_runner_release_finalization_is_finalization_only_without_process_start(self) -> None:
        payload = inspect_guarded_start_process_runner_release_finalization(
            guarded_start_process_runner_operator_release_review=ready_guarded_start_process_runner_operator_release_review(),
            release_finalization_authorization=(
                valid_guarded_start_process_runner_release_finalization_authorization()
            ),
        )

        self.assertEqual(GUARDED_START_PROCESS_RUNNER_RELEASE_FINALIZATION_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("ready_for_guarded_start_process_runner_release_authorization", payload["status"])
        self.assertTrue(payload["process_runner_release_finalization_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_allowed"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_operator_release_review_ready"])
        self.assertTrue(payload["gates"]["final_operator_release_receipt_attached"])
        self.assertTrue(payload["gates"]["single_start_bound"])
        self.assertTrue(payload["gates"]["release_window_attached"])
        self.assertTrue(payload["gates"]["revoke_plan_attached"])
        self.assertIn(
            "post_release_review_before_any_start",
            payload["required_process_runner_release_finalization_controls"],
        )
        self.assertIn(
            "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_RELEASE_FINALIZED",
            payload["evidence_events"],
        )
        self.assertEqual("implement_guarded_start_process_runner_release_authorization", payload["next_action"])

    def test_guarded_start_process_runner_release_finalization_blocks_when_start_is_allowed(self) -> None:
        authorization = valid_guarded_start_process_runner_release_finalization_authorization()
        authorization["start_execution_allowed"] = True
        payload = inspect_guarded_start_process_runner_release_finalization(
            guarded_start_process_runner_operator_release_review=ready_guarded_start_process_runner_operator_release_review(),
            release_finalization_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_operator_release_review_ready"])
        self.assertFalse(payload["gates"]["release_finalization_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_process_runner_release_finalization_prerequisites", payload["next_action"])

    def test_guarded_start_process_runner_release_authorization_requires_controlled_smoke_without_start(self) -> None:
        payload = inspect_guarded_start_process_runner_release_authorization(
            guarded_start_process_runner_release_finalization=ready_guarded_start_process_runner_release_finalization(),
            release_authorization=valid_guarded_start_process_runner_release_authorization(),
        )

        self.assertEqual(GUARDED_START_PROCESS_RUNNER_RELEASE_AUTHORIZATION_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("ready_for_controlled_livekit_server_supervised_smoke", payload["status"])
        self.assertTrue(payload["process_runner_release_authorization_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_allowed"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_release_finalization_ready"])
        self.assertTrue(payload["gates"]["operator_final_release_attached"])
        self.assertTrue(payload["gates"]["controlled_livekit_server_smoke_required"])
        self.assertTrue(payload["gates"]["worker_supervision_required"])
        self.assertIn(
            "controlled_livekit_server_smoke_before_any_daemon_start",
            payload["required_process_runner_release_authorization_controls"],
        )
        self.assertIn(
            "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_RELEASE_AUTHORIZED",
            payload["evidence_events"],
        )
        self.assertEqual("prepare_controlled_livekit_server_supervised_smoke_contract", payload["next_action"])

    def test_guarded_start_process_runner_release_authorization_blocks_when_start_is_allowed(self) -> None:
        authorization = valid_guarded_start_process_runner_release_authorization()
        authorization["start_execution_allowed"] = True
        payload = inspect_guarded_start_process_runner_release_authorization(
            guarded_start_process_runner_release_finalization=ready_guarded_start_process_runner_release_finalization(),
            release_authorization=authorization,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_release_finalization_ready"])
        self.assertFalse(payload["gates"]["release_authorization_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_guarded_start_process_runner_release_authorization_prerequisites", payload["next_action"])

    def test_controlled_livekit_server_supervised_smoke_contract_requires_worker_handshake_without_start(self) -> None:
        payload = inspect_controlled_livekit_server_supervised_smoke_contract(
            guarded_start_process_runner_release_authorization=ready_guarded_start_process_runner_release_authorization(),
            controlled_smoke_plan=valid_controlled_livekit_server_supervised_smoke_plan(),
        )

        self.assertEqual(CONTROLLED_LIVEKIT_SERVER_SUPERVISED_SMOKE_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("ready_for_supervised_voice_worker_handshake_smoke", payload["status"])
        self.assertTrue(payload["controlled_smoke_only"])
        self.assertTrue(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_allowed"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_release_authorization_ready"])
        self.assertTrue(payload["gates"]["local_livekit_server_configured"])
        self.assertTrue(payload["gates"]["token_issuer_smoke_passed"])
        self.assertTrue(payload["gates"]["worker_supervision_attached"])
        self.assertIn(
            "worker_supervision_before_worker_handshake",
            payload["required_controlled_smoke_controls"],
        )
        self.assertIn(
            "VOICE_DAEMON_CONTROLLED_LIVEKIT_SERVER_SMOKE_CONTRACT_EVALUATED",
            payload["evidence_events"],
        )
        self.assertEqual("prepare_supervised_voice_worker_handshake_smoke_contract", payload["next_action"])

    def test_controlled_livekit_server_supervised_smoke_contract_blocks_when_start_is_allowed(self) -> None:
        plan = valid_controlled_livekit_server_supervised_smoke_plan()
        plan["start_execution_allowed"] = True
        payload = inspect_controlled_livekit_server_supervised_smoke_contract(
            guarded_start_process_runner_release_authorization=ready_guarded_start_process_runner_release_authorization(),
            controlled_smoke_plan=plan,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_release_authorization_ready"])
        self.assertFalse(payload["gates"]["controlled_smoke_plan_ready"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertEqual("fix_controlled_livekit_server_supervised_smoke_prerequisites", payload["next_action"])

    def test_pre_start_health_checks_smoke_passes_without_process_start_or_secret_path_leak(self) -> None:
        payload = build_pre_start_health_checks_smoke()

        self.assertEqual("atlas.voice_realtime.pre_start_health_checks_smoke.v1", payload["schema_version"])
        self.assertEqual("passed_no_process_start", payload["status"])
        self.assertTrue(payload["smoke_only"])
        self.assertEqual("not_proven_by_smoke", payload["production_readiness"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertFalse(payload["provider_calls_made"])
        self.assertFalse(payload["tool_calls_made"])
        self.assertFalse(payload["raw_audio_touched"])
        self.assertEqual("<temporary-managed-env-file>", payload["managed_env_write_execution"]["target_path"])
        self.assertTrue(payload["temporary_env_file_removed_after_smoke"])
        self.assertTrue(payload["gates"]["managed_env_placeholder_written"])
        self.assertTrue(payload["gates"]["managed_env_target_redacted"])
        self.assertTrue(payload["gates"]["pre_start_health_checks_passed"])
        self.assertTrue(payload["gates"]["subprocess_start_contract_ready"])
        self.assertTrue(payload["gates"]["reviewed_subprocess_start_execution_ready"])
        self.assertEqual("passed_no_process_start", payload["pre_start_health_checks"]["status"])
        self.assertEqual(
            "ready_for_reviewed_subprocess_start_implementation",
            payload["subprocess_start_contract"]["status"],
        )
        self.assertEqual(
            "ready_for_real_start_implementation",
            payload["reviewed_subprocess_start_execution"]["status"],
        )
        self.assertTrue(payload["gates"]["real_start_adapter_disabled_ready"])
        self.assertTrue(payload["gates"]["real_start_enablement_gate_ready"])
        self.assertTrue(payload["gates"]["runtime_policy_enablement_review_ready"])
        self.assertTrue(payload["gates"]["real_start_adapter_review_contract_ready"])
        self.assertTrue(payload["gates"]["reviewed_real_start_execution_contract_ready"])
        self.assertTrue(payload["gates"]["final_start_executor_disabled_ready"])
        self.assertTrue(payload["gates"]["final_start_executor_enablement_gate_ready"])
        self.assertTrue(payload["gates"]["supervised_start_execution_review_ready"])
        self.assertTrue(payload["gates"]["real_start_execution_contract_ready"])
        self.assertTrue(payload["gates"]["guarded_start_executor_disabled_ready"])
        self.assertTrue(payload["gates"]["guarded_start_executor_enablement_gate_ready"])
        self.assertTrue(payload["gates"]["reviewed_guarded_start_execution_contract_ready"])
        self.assertTrue(payload["gates"]["guarded_start_dry_run_contract_ready"])
        self.assertTrue(payload["gates"]["guarded_start_simulation_contract_ready"])
        self.assertTrue(payload["gates"]["guarded_start_runtime_handoff_contract_ready"])
        self.assertTrue(payload["gates"]["guarded_start_policy_patch_review_contract_ready"])
        self.assertTrue(payload["gates"]["guarded_start_human_review_contract_ready"])
        self.assertTrue(payload["gates"]["guarded_start_final_enablement_gate_ready"])
        self.assertTrue(payload["gates"]["guarded_start_policy_enablement_contract_ready"])
        self.assertTrue(payload["gates"]["guarded_start_activation_contract_ready"])
        self.assertEqual(
            "ready_disabled_by_default",
            payload["real_start_adapter_disabled"]["status"],
        )
        self.assertEqual(
            "ready_for_policy_enablement_review",
            payload["real_start_enablement_gate"]["status"],
        )
        self.assertEqual(
            "ready_for_real_start_adapter_review",
            payload["runtime_policy_enablement_review"]["status"],
        )
        self.assertEqual(
            "ready_for_reviewed_real_start_execution_contract",
            payload["real_start_adapter_review_contract"]["status"],
        )
        self.assertEqual(
            "ready_for_start_execution_implementation",
            payload["reviewed_real_start_execution_contract"]["status"],
        )
        self.assertEqual(
            "ready_disabled_by_default",
            payload["final_start_executor_disabled"]["status"],
        )
        self.assertEqual(
            "ready_for_supervised_start_execution_review",
            payload["final_start_executor_enablement_gate"]["status"],
        )
        self.assertEqual(
            "ready_for_real_start_execution_contract",
            payload["supervised_start_execution_review"]["status"],
        )
        self.assertEqual(
            "ready_for_guarded_start_executor_implementation",
            payload["real_start_execution_contract"]["status"],
        )
        self.assertEqual(
            "ready_disabled_by_default",
            payload["guarded_start_executor_disabled"]["status"],
        )
        self.assertEqual(
            "ready_for_reviewed_guarded_start_execution",
            payload["guarded_start_executor_enablement_gate"]["status"],
        )
        self.assertEqual(
            "ready_for_guarded_start_dry_run_contract",
            payload["reviewed_guarded_start_execution_contract"]["status"],
        )
        self.assertEqual(
            "ready_for_guarded_start_simulation",
            payload["guarded_start_dry_run_contract"]["status"],
        )
        self.assertEqual(
            "ready_for_guarded_start_runtime_handoff",
            payload["guarded_start_simulation_contract"]["status"],
        )
        self.assertEqual(
            "ready_for_guarded_start_policy_patch_review",
            payload["guarded_start_runtime_handoff_contract"]["status"],
        )
        self.assertEqual(
            "ready_for_guarded_start_human_review",
            payload["guarded_start_policy_patch_review_contract"]["status"],
        )
        self.assertEqual(
            "ready_for_guarded_start_final_enablement_gate",
            payload["guarded_start_human_review_contract"]["status"],
        )
        self.assertEqual(
            "ready_for_guarded_start_policy_enablement_contract",
            payload["guarded_start_final_enablement_gate"]["status"],
        )
        self.assertEqual(
            "ready_for_guarded_start_activation_contract",
            payload["guarded_start_policy_enablement_contract"]["status"],
        )
        self.assertEqual(
            "ready_for_guarded_start_execution_attempt_contract",
            payload["guarded_start_activation_contract"]["status"],
        )
        self.assertTrue(payload["guarded_start_policy_enablement_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_policy_enablement_contract"]["start_execution_allowed"])
        self.assertFalse(payload["guarded_start_policy_enablement_contract"]["daemon_started"])
        self.assertTrue(payload["guarded_start_activation_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_activation_contract"]["start_execution_allowed"])
        self.assertFalse(payload["guarded_start_activation_contract"]["daemon_started"])
        self.assertIn("VOICE_DAEMON_PRE_START_HEALTH_CHECKS_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_REVIEWED_SUBPROCESS_START_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_REAL_START_ADAPTER_DECLARED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_REAL_START_ENABLEMENT_GATE_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_RUNTIME_POLICY_ENABLEMENT_REVIEW_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_REAL_START_ADAPTER_REVIEW_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_REVIEWED_REAL_START_EXECUTION_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_FINAL_START_EXECUTOR_DECLARED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_FINAL_START_ENABLEMENT_GATE_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_SUPERVISED_START_EXECUTION_REVIEWED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_REAL_START_EXECUTION_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_EXECUTOR_DECLARED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_ENABLEMENT_GATE_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_REVIEWED_GUARDED_START_EXECUTION_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_DRY_RUN_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_SIMULATION_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_RUNTIME_HANDOFF_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_POLICY_PATCH_REVIEW_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_HUMAN_REVIEW_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_FINAL_ENABLEMENT_GATE_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_POLICY_ENABLEMENT_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_ACTIVATION_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertTrue(payload["gates"]["guarded_start_final_process_start_contract_ready"])
        self.assertEqual(
            "ready_for_guarded_start_process_execution_review",
            payload["guarded_start_final_process_start_contract"]["status"],
        )
        self.assertTrue(payload["guarded_start_final_process_start_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_final_process_start_contract"]["start_execution_allowed"])
        self.assertFalse(payload["guarded_start_final_process_start_contract"]["process_launch_allowed"])
        self.assertFalse(payload["guarded_start_final_process_start_contract"]["daemon_started"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_FINAL_PROCESS_START_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertTrue(payload["gates"]["guarded_start_process_execution_review_ready"])
        self.assertEqual(
            "ready_for_guarded_start_process_execution_packet",
            payload["guarded_start_process_execution_review"]["status"],
        )
        self.assertTrue(payload["guarded_start_process_execution_review"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_process_execution_review"]["start_execution_allowed"])
        self.assertFalse(payload["guarded_start_process_execution_review"]["process_launch_allowed"])
        self.assertFalse(payload["guarded_start_process_execution_review"]["daemon_started"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_PROCESS_EXECUTION_REVIEWED", payload["evidence_events"])
        self.assertTrue(payload["gates"]["guarded_start_process_execution_packet_ready"])
        self.assertTrue(payload["gates"]["guarded_start_process_executor_stub_ready"])
        self.assertTrue(payload["gates"]["guarded_start_process_executor_review_ready"])
        self.assertTrue(payload["gates"]["guarded_start_process_executor_contract_ready"])
        self.assertEqual(
            "ready_for_guarded_start_process_executor_contract",
            payload["guarded_start_process_executor_review"]["status"],
        )
        self.assertTrue(payload["guarded_start_process_executor_review"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_process_executor_review"]["start_execution_allowed"])
        self.assertFalse(payload["guarded_start_process_executor_review"]["process_launch_allowed"])
        self.assertFalse(payload["guarded_start_process_executor_review"]["daemon_started"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_PROCESS_EXECUTOR_REVIEWED", payload["evidence_events"])
        self.assertEqual(
            "ready_for_guarded_start_process_runtime_adapter",
            payload["guarded_start_process_executor_contract"]["status"],
        )
        self.assertTrue(payload["guarded_start_process_executor_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_process_executor_contract"]["start_execution_allowed"])
        self.assertFalse(payload["guarded_start_process_executor_contract"]["process_launch_allowed"])
        self.assertFalse(payload["guarded_start_process_executor_contract"]["daemon_started"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_PROCESS_EXECUTOR_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertTrue(payload["gates"]["guarded_start_process_runtime_adapter_ready"])
        self.assertEqual(
            "ready_for_guarded_start_process_adapter_review",
            payload["guarded_start_process_runtime_adapter"]["status"],
        )
        self.assertTrue(payload["guarded_start_process_runtime_adapter"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_process_runtime_adapter"]["start_execution_allowed"])
        self.assertFalse(payload["guarded_start_process_runtime_adapter"]["process_launch_allowed"])
        self.assertFalse(payload["guarded_start_process_runtime_adapter"]["daemon_started"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_PROCESS_RUNTIME_ADAPTER_EVALUATED", payload["evidence_events"])
        self.assertTrue(payload["gates"]["guarded_start_process_adapter_review_ready"])
        self.assertEqual(
            "ready_for_guarded_start_process_adapter_contract",
            payload["guarded_start_process_adapter_review"]["status"],
        )
        self.assertTrue(payload["guarded_start_process_adapter_review"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_process_adapter_review"]["start_execution_allowed"])
        self.assertFalse(payload["guarded_start_process_adapter_review"]["process_launch_allowed"])
        self.assertFalse(payload["guarded_start_process_adapter_review"]["daemon_started"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_PROCESS_ADAPTER_REVIEWED", payload["evidence_events"])


if __name__ == "__main__":
    unittest.main()
