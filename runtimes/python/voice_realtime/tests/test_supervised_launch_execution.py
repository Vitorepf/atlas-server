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


if __name__ == "__main__":
    unittest.main()
