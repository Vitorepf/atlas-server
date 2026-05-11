from __future__ import annotations

from pathlib import Path
from tempfile import TemporaryDirectory
from typing import Any, Mapping

from .daemon_supervisor import evaluate_daemon_supervisor
from .managed_env_writer import execute_managed_env_write
from .supervised_launch_execution import (
    LAUNCH_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
    FINAL_START_EXECUTOR_AUTHORIZATION_SCHEMA_VERSION,
    FINAL_START_EXECUTOR_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_EXECUTOR_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_DRY_RUN_PLAN_SCHEMA_VERSION,
    GUARDED_START_ACTIVATION_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_FINAL_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_HUMAN_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_POLICY_PATCH_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_POLICY_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_RUNTIME_HANDOFF_PLAN_SCHEMA_VERSION,
    GUARDED_START_SIMULATION_PLAN_SCHEMA_VERSION,
    GUARDED_START_EXECUTOR_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION,
    PRE_START_HEALTH_CHECKS_AUTHORIZATION_SCHEMA_VERSION,
    REAL_START_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
    REAL_START_ADAPTER_AUTHORIZATION_SCHEMA_VERSION,
    REAL_START_ADAPTER_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
    REAL_START_ENABLEMENT_GATE_AUTHORIZATION_SCHEMA_VERSION,
    REVIEWED_REAL_START_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
    REVIEWED_GUARDED_START_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
    REVIEWED_SUBPROCESS_START_AUTHORIZATION_SCHEMA_VERSION,
    RUNTIME_POLICY_ENABLEMENT_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
    SUPERVISED_START_EXECUTION_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
    SUBPROCESS_START_AUTHORIZATION_SCHEMA_VERSION,
    execute_pre_start_health_checks,
    inspect_real_start_adapter_enablement_gate,
    inspect_real_start_adapter_disabled_by_default,
    inspect_real_start_adapter_review_contract,
    inspect_final_start_executor_disabled_by_default,
    inspect_final_start_executor_enablement_gate,
    inspect_guarded_start_dry_run_contract,
    inspect_guarded_start_activation_contract,
    inspect_guarded_start_final_enablement_gate_contract,
    inspect_guarded_start_human_review_contract,
    inspect_guarded_start_policy_enablement_contract,
    inspect_guarded_start_policy_patch_review_contract,
    inspect_guarded_start_runtime_handoff_contract,
    inspect_guarded_start_simulation_contract,
    inspect_guarded_start_executor_disabled_by_default,
    inspect_guarded_start_executor_enablement_gate,
    inspect_reviewed_guarded_start_execution_contract,
    inspect_real_start_execution_contract,
    inspect_supervised_start_execution_review,
    inspect_reviewed_subprocess_start_execution,
    inspect_reviewed_real_start_execution_contract,
    inspect_runtime_policy_enablement_review,
    inspect_supervised_launch_execution,
    inspect_subprocess_start_contract,
)
from .supervised_start_plan import build_supervised_start_plan


SCHEMA_VERSION = "atlas.voice_realtime.pre_start_health_checks_smoke.v1"


def build_pre_start_health_checks_smoke() -> Mapping[str, Any]:
    """Exercise the pre-start health chain without starting a daemon.

    This smoke is intentionally production-shaped but not a production proof. It
    creates only a temporary placeholder env file, validates the managed launch
    execution, runs synthetic safe health checks, evaluates the subprocess start
    contract and deletes the temporary directory before returning.
    """

    worker_start = _ready_worker_start()
    supervisor_execution = evaluate_daemon_supervisor(worker_start)
    process_adapter = supervisor_execution.get("supervised_process_adapter", {})
    env_target_path: Path | None = None

    with TemporaryDirectory() as directory:
        env_target_path = Path(directory) / "atlas-voice-pre-start-health-smoke.env"
        env_write = execute_managed_env_write(
            managed_env_writer=process_adapter.get("managed_env_writer", {}),
            target_path=env_target_path,
            write_authorization={
                "schema_version": "atlas.voice_realtime.managed_env_write_authorization.v1",
                "status": "approved",
                "write_allowed": True,
                "process_launch_allowed": False,
                "decision_receipt_id": "decision_receipt_pre_start_smoke_env_write",
            },
        )
        launch_execution = inspect_supervised_launch_execution(
            supervisor_execution=supervisor_execution,
            launch_authorization_contract=process_adapter.get("launch_authorization_contract", {}),
            managed_env_write_execution=env_write,
            launch_execution_authorization={
                "schema_version": LAUNCH_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
                "status": "approved_for_subprocess_implementation",
                "subprocess_implementation_allowed": True,
                "process_launch_allowed": False,
                "decision_receipt_id": "decision_receipt_pre_start_smoke_launch_execution",
            },
        )
        pre_start_health_checks = execute_pre_start_health_checks(
            supervised_launch_execution=launch_execution,
            health_check_results=_passed_health_checks(
                list(launch_execution.get("required_pre_start_checks", []))
            ),
            health_check_authorization={
                "schema_version": PRE_START_HEALTH_CHECKS_AUTHORIZATION_SCHEMA_VERSION,
                "status": "approved",
                "health_checks_allowed": True,
                "process_launch_allowed": False,
                "decision_receipt_id": "decision_receipt_pre_start_smoke_health_checks",
            },
        )
        subprocess_start_contract = inspect_subprocess_start_contract(
            supervised_launch_execution=launch_execution,
            pre_start_health_checks_execution=pre_start_health_checks,
            subprocess_start_authorization={
                "schema_version": SUBPROCESS_START_AUTHORIZATION_SCHEMA_VERSION,
                "status": "approved_for_start_contract",
                "subprocess_contract_allowed": True,
                "process_launch_allowed": False,
                "decision_receipt_id": "decision_receipt_pre_start_smoke_start_contract",
            },
        )
        reviewed_subprocess_start_execution = inspect_reviewed_subprocess_start_execution(
            subprocess_start_contract=subprocess_start_contract,
            reviewed_start_authorization={
                "schema_version": REVIEWED_SUBPROCESS_START_AUTHORIZATION_SCHEMA_VERSION,
                "status": "approved_for_real_start_implementation_plan",
                "reviewed_execution_allowed": True,
                "real_process_start_allowed": False,
                "subprocess_module_import_allowed": False,
                "decision_receipt_id": "decision_receipt_pre_start_smoke_reviewed_start",
            },
        )
        real_start_adapter_disabled = inspect_real_start_adapter_disabled_by_default(
            reviewed_subprocess_start_execution=reviewed_subprocess_start_execution,
            real_start_adapter_authorization={
                "schema_version": REAL_START_ADAPTER_AUTHORIZATION_SCHEMA_VERSION,
                "status": "approved_for_disabled_adapter_contract",
                "disabled_adapter_contract_allowed": True,
                "real_process_start_allowed": False,
                "subprocess_module_import_allowed": False,
                "start_enabled": False,
                "decision_receipt_id": "decision_receipt_pre_start_smoke_real_start_adapter",
            },
        )
        real_start_enablement_gate = inspect_real_start_adapter_enablement_gate(
            real_start_adapter_disabled=real_start_adapter_disabled,
            enablement_gate_authorization={
                "schema_version": REAL_START_ENABLEMENT_GATE_AUTHORIZATION_SCHEMA_VERSION,
                "status": "approved_for_start_enablement_gate",
                "start_enablement_gate_allowed": True,
                "start_execution_allowed": False,
                "process_launch_allowed": False,
                "subprocess_module_import_allowed": False,
                "policy_patch_required": True,
                "human_review_required": True,
                "rollback_required": True,
                "decision_receipt_id": "decision_receipt_pre_start_smoke_real_start_enablement_gate",
            },
        )
        runtime_policy_enablement_review = inspect_runtime_policy_enablement_review(
            real_start_enablement_gate=real_start_enablement_gate,
            policy_review_authorization={
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
                "decision_receipt_id": "decision_receipt_pre_start_smoke_runtime_policy_review",
            },
        )
        real_start_adapter_review_contract = inspect_real_start_adapter_review_contract(
            runtime_policy_enablement_review=runtime_policy_enablement_review,
            real_start_review_authorization={
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
                "decision_receipt_id": "decision_receipt_pre_start_smoke_real_start_adapter_review",
            },
        )
        reviewed_real_start_execution_contract = inspect_reviewed_real_start_execution_contract(
            real_start_adapter_review_contract=real_start_adapter_review_contract,
            reviewed_real_start_authorization={
                "schema_version": REVIEWED_REAL_START_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
                "status": "approved_for_reviewed_real_start_execution_contract",
                "reviewed_real_start_execution_allowed": True,
                "start_execution_allowed": False,
                "process_launch_allowed": False,
                "subprocess_module_import_allowed": False,
                "final_pre_start_receipt_required": True,
                "post_start_ready_event_required": True,
                "rollback_rehearsal_required": True,
                "decision_receipt_id": "decision_receipt_pre_start_smoke_reviewed_real_start_execution",
            },
        )
        final_start_executor_disabled = inspect_final_start_executor_disabled_by_default(
            reviewed_real_start_execution_contract=reviewed_real_start_execution_contract,
            final_start_executor_authorization={
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
                "decision_receipt_id": "decision_receipt_pre_start_smoke_final_start_executor",
            },
        )
        final_start_executor_enablement_gate = inspect_final_start_executor_enablement_gate(
            final_start_executor_disabled=final_start_executor_disabled,
            final_start_executor_enablement_authorization={
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
                "decision_receipt_id": "decision_receipt_pre_start_smoke_final_start_enablement",
            },
        )
        supervised_start_execution_review = inspect_supervised_start_execution_review(
            final_start_executor_enablement_gate=final_start_executor_enablement_gate,
            supervised_start_review_authorization={
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
                "decision_receipt_id": "decision_receipt_pre_start_smoke_supervised_start_review",
            },
        )
        real_start_execution_contract = inspect_real_start_execution_contract(
            supervised_start_execution_review=supervised_start_execution_review,
            real_start_execution_authorization={
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
                "decision_receipt_id": "decision_receipt_pre_start_smoke_real_start_execution_contract",
            },
        )
        guarded_start_executor_disabled = inspect_guarded_start_executor_disabled_by_default(
            real_start_execution_contract=real_start_execution_contract,
            guarded_start_executor_authorization={
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
                "decision_receipt_id": "decision_receipt_pre_start_smoke_guarded_start_disabled",
            },
        )
        guarded_start_executor_enablement_gate = inspect_guarded_start_executor_enablement_gate(
            guarded_start_executor_disabled=guarded_start_executor_disabled,
            guarded_start_executor_enablement_authorization={
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
                "decision_receipt_id": "decision_receipt_pre_start_smoke_guarded_start_enablement",
            },
        )
        reviewed_guarded_start_execution_contract = inspect_reviewed_guarded_start_execution_contract(
            guarded_start_executor_enablement_gate=guarded_start_executor_enablement_gate,
            reviewed_guarded_start_authorization={
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
                "decision_receipt_id": "decision_receipt_pre_start_smoke_reviewed_guarded_start_execution",
            },
        )
        guarded_start_dry_run_contract = inspect_guarded_start_dry_run_contract(
            reviewed_guarded_start_execution_contract=reviewed_guarded_start_execution_contract,
            guarded_start_dry_run_plan={
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
                "decision_receipt_id": "decision_receipt_pre_start_smoke_guarded_start_dry_run",
            },
        )
        guarded_start_simulation_contract = inspect_guarded_start_simulation_contract(
            guarded_start_dry_run_contract=guarded_start_dry_run_contract,
            guarded_start_simulation_plan={
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
                "decision_receipt_id": "decision_receipt_pre_start_smoke_guarded_start_simulation",
            },
        )
        guarded_start_runtime_handoff_contract = inspect_guarded_start_runtime_handoff_contract(
            guarded_start_simulation_contract=guarded_start_simulation_contract,
            guarded_start_runtime_handoff_plan={
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
                "decision_receipt_id": "decision_receipt_pre_start_smoke_guarded_start_runtime_handoff",
            },
        )
        guarded_start_policy_patch_review_contract = inspect_guarded_start_policy_patch_review_contract(
            guarded_start_runtime_handoff_contract=guarded_start_runtime_handoff_contract,
            policy_patch_review_authorization={
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
                "decision_receipt_id": "decision_receipt_pre_start_smoke_guarded_start_policy_patch_review",
            },
        )
        guarded_start_human_review_contract = inspect_guarded_start_human_review_contract(
            guarded_start_policy_patch_review_contract=guarded_start_policy_patch_review_contract,
            human_review_authorization={
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
                "decision_receipt_id": "decision_receipt_pre_start_smoke_guarded_start_human_review",
            },
        )
        guarded_start_final_enablement_gate = inspect_guarded_start_final_enablement_gate_contract(
            guarded_start_human_review_contract=guarded_start_human_review_contract,
            final_enablement_authorization={
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
                "decision_receipt_id": "decision_receipt_pre_start_smoke_guarded_start_final_enablement",
            },
        )
        guarded_start_policy_enablement_contract = inspect_guarded_start_policy_enablement_contract(
            guarded_start_final_enablement_gate=guarded_start_final_enablement_gate,
            policy_enablement_authorization={
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
                "decision_receipt_id": "decision_receipt_pre_start_smoke_guarded_start_policy_enablement",
            },
        )
        guarded_start_activation_contract = inspect_guarded_start_activation_contract(
            guarded_start_policy_enablement_contract=guarded_start_policy_enablement_contract,
            activation_authorization={
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
                "decision_receipt_id": "decision_receipt_pre_start_smoke_guarded_start_activation",
            },
        )

        payload = {
            "schema_version": SCHEMA_VERSION,
            "status": _status(
                pre_start_health_checks,
                subprocess_start_contract,
                reviewed_subprocess_start_execution,
                real_start_adapter_disabled,
                real_start_enablement_gate,
                runtime_policy_enablement_review,
                real_start_adapter_review_contract,
                reviewed_real_start_execution_contract,
                final_start_executor_disabled,
                final_start_executor_enablement_gate,
                supervised_start_execution_review,
                real_start_execution_contract,
                guarded_start_executor_disabled,
                guarded_start_executor_enablement_gate,
                reviewed_guarded_start_execution_contract,
                guarded_start_dry_run_contract,
                guarded_start_simulation_contract,
                guarded_start_runtime_handoff_contract,
                guarded_start_policy_patch_review_contract,
                guarded_start_human_review_contract,
                guarded_start_final_enablement_gate,
                guarded_start_policy_enablement_contract,
                guarded_start_activation_contract,
            ),
            "surface_id": "voice_realtime",
            "runtime_id": "livekit_agents_sdk",
            "kernel_only": True,
            "mobile_first": True,
            "smoke_only": True,
            "production_readiness": "not_proven_by_smoke",
            "process_launch_attempted": False,
            "daemon_started": False,
            "subprocess_module_imported": False,
            "livekit_sdk_imported": False,
            "provider_calls_made": False,
            "tool_calls_made": False,
            "raw_audio_touched": False,
            "managed_env_write_execution": _redact_env_write(env_write),
            "supervised_launch_execution": launch_execution,
            "pre_start_health_checks": pre_start_health_checks,
            "subprocess_start_contract": subprocess_start_contract,
            "reviewed_subprocess_start_execution": reviewed_subprocess_start_execution,
            "real_start_adapter_disabled": real_start_adapter_disabled,
            "real_start_enablement_gate": real_start_enablement_gate,
            "runtime_policy_enablement_review": runtime_policy_enablement_review,
            "real_start_adapter_review_contract": real_start_adapter_review_contract,
            "reviewed_real_start_execution_contract": reviewed_real_start_execution_contract,
            "final_start_executor_disabled": final_start_executor_disabled,
            "final_start_executor_enablement_gate": final_start_executor_enablement_gate,
            "supervised_start_execution_review": supervised_start_execution_review,
            "real_start_execution_contract": real_start_execution_contract,
            "guarded_start_executor_disabled": guarded_start_executor_disabled,
            "guarded_start_executor_enablement_gate": guarded_start_executor_enablement_gate,
            "reviewed_guarded_start_execution_contract": reviewed_guarded_start_execution_contract,
            "guarded_start_dry_run_contract": guarded_start_dry_run_contract,
            "guarded_start_simulation_contract": guarded_start_simulation_contract,
            "guarded_start_runtime_handoff_contract": guarded_start_runtime_handoff_contract,
            "guarded_start_policy_patch_review_contract": guarded_start_policy_patch_review_contract,
            "guarded_start_human_review_contract": guarded_start_human_review_contract,
            "guarded_start_final_enablement_gate": guarded_start_final_enablement_gate,
            "guarded_start_policy_enablement_contract": guarded_start_policy_enablement_contract,
            "guarded_start_activation_contract": guarded_start_activation_contract,
            "gates": {
                "managed_env_placeholder_written": env_write.get("env_file_written") is True,
                "managed_env_target_redacted": True,
                "supervised_launch_ready": launch_execution.get("status") == "ready_for_subprocess_implementation",
                "pre_start_health_checks_passed": pre_start_health_checks.get("status") == "passed_no_process_start",
                "subprocess_start_contract_ready": subprocess_start_contract.get("status") == "ready_for_reviewed_subprocess_start_implementation",
                "reviewed_subprocess_start_execution_ready": reviewed_subprocess_start_execution.get("status") == "ready_for_real_start_implementation",
                "real_start_adapter_disabled_ready": real_start_adapter_disabled.get("status") == "ready_disabled_by_default",
                "real_start_enablement_gate_ready": real_start_enablement_gate.get("status") == "ready_for_policy_enablement_review",
                "runtime_policy_enablement_review_ready": runtime_policy_enablement_review.get("status") == "ready_for_real_start_adapter_review",
                "real_start_adapter_review_contract_ready": real_start_adapter_review_contract.get("status") == "ready_for_reviewed_real_start_execution_contract",
                "reviewed_real_start_execution_contract_ready": reviewed_real_start_execution_contract.get("status") == "ready_for_start_execution_implementation",
                "final_start_executor_disabled_ready": final_start_executor_disabled.get("status") == "ready_disabled_by_default",
                "final_start_executor_enablement_gate_ready": final_start_executor_enablement_gate.get("status") == "ready_for_supervised_start_execution_review",
                "supervised_start_execution_review_ready": supervised_start_execution_review.get("status") == "ready_for_real_start_execution_contract",
                "real_start_execution_contract_ready": real_start_execution_contract.get("status") == "ready_for_guarded_start_executor_implementation",
                "guarded_start_executor_disabled_ready": guarded_start_executor_disabled.get("status") == "ready_disabled_by_default",
                "guarded_start_executor_enablement_gate_ready": guarded_start_executor_enablement_gate.get("status") == "ready_for_reviewed_guarded_start_execution",
                "reviewed_guarded_start_execution_contract_ready": reviewed_guarded_start_execution_contract.get("status") == "ready_for_guarded_start_dry_run_contract",
                "guarded_start_dry_run_contract_ready": guarded_start_dry_run_contract.get("status") == "ready_for_guarded_start_simulation",
                "guarded_start_simulation_contract_ready": guarded_start_simulation_contract.get("status") == "ready_for_guarded_start_runtime_handoff",
                "guarded_start_runtime_handoff_contract_ready": guarded_start_runtime_handoff_contract.get("status") == "ready_for_guarded_start_policy_patch_review",
                "guarded_start_policy_patch_review_contract_ready": guarded_start_policy_patch_review_contract.get("status") == "ready_for_guarded_start_human_review",
                "guarded_start_human_review_contract_ready": guarded_start_human_review_contract.get("status") == "ready_for_guarded_start_final_enablement_gate",
                "guarded_start_final_enablement_gate_ready": guarded_start_final_enablement_gate.get("status") == "ready_for_guarded_start_policy_enablement_contract",
                "guarded_start_policy_enablement_contract_ready": guarded_start_policy_enablement_contract.get("status") == "ready_for_guarded_start_activation_contract",
                "guarded_start_activation_contract_ready": guarded_start_activation_contract.get("status") == "ready_for_guarded_start_execution_attempt_contract",
                "process_launch_disabled": True,
                "provider_calls_forbidden": True,
                "tool_calls_forbidden": True,
                "raw_audio_forbidden": True,
            },
            "evidence_events": [
                "VOICE_DAEMON_MANAGED_ENV_WRITE_EXECUTED",
                "VOICE_DAEMON_SUPERVISED_LAUNCH_EVALUATED",
                "VOICE_DAEMON_PRE_START_HEALTH_CHECKS_EVALUATED",
                "VOICE_DAEMON_SUBPROCESS_START_CONTRACT_EVALUATED",
                "VOICE_DAEMON_REVIEWED_SUBPROCESS_START_EVALUATED",
                "VOICE_DAEMON_REAL_START_ADAPTER_DECLARED",
                "VOICE_DAEMON_REAL_START_ENABLEMENT_GATE_EVALUATED",
                "VOICE_DAEMON_RUNTIME_POLICY_ENABLEMENT_REVIEW_EVALUATED",
                "VOICE_DAEMON_REAL_START_ADAPTER_REVIEW_CONTRACT_EVALUATED",
                "VOICE_DAEMON_REVIEWED_REAL_START_EXECUTION_CONTRACT_EVALUATED",
                "VOICE_DAEMON_FINAL_START_EXECUTOR_DECLARED",
                "VOICE_DAEMON_FINAL_START_ENABLEMENT_GATE_EVALUATED",
                "VOICE_DAEMON_SUPERVISED_START_EXECUTION_REVIEWED",
                "VOICE_DAEMON_REAL_START_EXECUTION_CONTRACT_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_EXECUTOR_DECLARED",
                "VOICE_DAEMON_GUARDED_START_ENABLEMENT_GATE_EVALUATED",
                "VOICE_DAEMON_REVIEWED_GUARDED_START_EXECUTION_CONTRACT_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_DRY_RUN_CONTRACT_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_SIMULATION_CONTRACT_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_RUNTIME_HANDOFF_CONTRACT_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_POLICY_PATCH_REVIEW_CONTRACT_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_HUMAN_REVIEW_CONTRACT_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_FINAL_ENABLEMENT_GATE_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_POLICY_ENABLEMENT_CONTRACT_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_ACTIVATION_CONTRACT_EVALUATED",
                "VOICE_DAEMON_SUBPROCESS_START_BLOCKED",
            ],
        }

    payload["temporary_env_file_removed_after_smoke"] = env_target_path is not None and not env_target_path.exists()
    payload["next_action"] = (
        "submit_production_livekit_env_and_human_review_before_real_start"
        if payload["status"] == "passed_no_process_start"
        else "fix_pre_start_health_checks_smoke"
    )

    return payload


def _ready_worker_start() -> Mapping[str, Any]:
    supervised_start_plan = build_supervised_start_plan(
        worker_start_status="blocked_unimplemented_start",
        production_review_valid=True,
        daemon_implementation_review_valid=True,
        activation_contract={
            "schema_version": "atlas.voice_realtime.activation_contract.v1",
            "status": "ready_to_start_worker",
        },
        production_loop_plan={
            "schema_version": "atlas.voice_realtime.production_loop_plan.v1",
            "production_sdk_loop_wired": True,
            "sdk_wiring_contract": {
                "schema_version": "atlas.voice_realtime.sdk_wiring_contract.v1",
                "guardrails": {
                    "kernel_event_normalizer_required_for_real_loop": True,
                },
                "required_components": {
                    "kernel_event_normalizer": "KernelRuntimeEventNormalizerGuard",
                },
            },
        },
    )

    return {
        "schema_version": "atlas.voice_realtime.worker_start.v1",
        "status": "blocked_unimplemented_start",
        "surface_id": "voice_realtime",
        "runtime_id": "livekit_agents_sdk",
        "production_promotion_review_valid": True,
        "daemon_implementation_review_valid": True,
        "started": False,
        "supervised_start_plan": supervised_start_plan,
    }


def _passed_health_checks(required_checks: list[str]) -> list[Mapping[str, Any]]:
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


def _redact_env_write(env_write: Mapping[str, Any]) -> Mapping[str, Any]:
    payload = dict(env_write)
    if payload.get("env_file_written") is True:
        payload["target_path"] = "<temporary-managed-env-file>"

    return payload


def _status(
    pre_start_health_checks: Mapping[str, Any],
    subprocess_start_contract: Mapping[str, Any],
    reviewed_subprocess_start_execution: Mapping[str, Any] | None = None,
    real_start_adapter_disabled: Mapping[str, Any] | None = None,
    real_start_enablement_gate: Mapping[str, Any] | None = None,
    runtime_policy_enablement_review: Mapping[str, Any] | None = None,
    real_start_adapter_review_contract: Mapping[str, Any] | None = None,
    reviewed_real_start_execution_contract: Mapping[str, Any] | None = None,
    final_start_executor_disabled: Mapping[str, Any] | None = None,
    final_start_executor_enablement_gate: Mapping[str, Any] | None = None,
    supervised_start_execution_review: Mapping[str, Any] | None = None,
    real_start_execution_contract: Mapping[str, Any] | None = None,
    guarded_start_executor_disabled: Mapping[str, Any] | None = None,
    guarded_start_executor_enablement_gate: Mapping[str, Any] | None = None,
    reviewed_guarded_start_execution_contract: Mapping[str, Any] | None = None,
    guarded_start_dry_run_contract: Mapping[str, Any] | None = None,
    guarded_start_simulation_contract: Mapping[str, Any] | None = None,
    guarded_start_runtime_handoff_contract: Mapping[str, Any] | None = None,
    guarded_start_policy_patch_review_contract: Mapping[str, Any] | None = None,
    guarded_start_human_review_contract: Mapping[str, Any] | None = None,
    guarded_start_final_enablement_gate: Mapping[str, Any] | None = None,
    guarded_start_policy_enablement_contract: Mapping[str, Any] | None = None,
    guarded_start_activation_contract: Mapping[str, Any] | None = None,
) -> str:
    if (
        pre_start_health_checks.get("status") == "passed_no_process_start"
        and subprocess_start_contract.get("status") == "ready_for_reviewed_subprocess_start_implementation"
        and (
            reviewed_subprocess_start_execution is None
            or reviewed_subprocess_start_execution.get("status") == "ready_for_real_start_implementation"
        )
        and (
            real_start_adapter_disabled is None
            or real_start_adapter_disabled.get("status") == "ready_disabled_by_default"
        )
        and (
            real_start_enablement_gate is None
            or real_start_enablement_gate.get("status") == "ready_for_policy_enablement_review"
        )
        and (
            runtime_policy_enablement_review is None
            or runtime_policy_enablement_review.get("status") == "ready_for_real_start_adapter_review"
        )
        and (
            real_start_adapter_review_contract is None
            or real_start_adapter_review_contract.get("status") == "ready_for_reviewed_real_start_execution_contract"
        )
        and (
            reviewed_real_start_execution_contract is None
            or reviewed_real_start_execution_contract.get("status") == "ready_for_start_execution_implementation"
        )
        and (
            final_start_executor_disabled is None
            or final_start_executor_disabled.get("status") == "ready_disabled_by_default"
        )
        and (
            final_start_executor_enablement_gate is None
            or final_start_executor_enablement_gate.get("status") == "ready_for_supervised_start_execution_review"
        )
        and (
            supervised_start_execution_review is None
            or supervised_start_execution_review.get("status") == "ready_for_real_start_execution_contract"
        )
        and (
            real_start_execution_contract is None
            or real_start_execution_contract.get("status") == "ready_for_guarded_start_executor_implementation"
        )
        and (
            guarded_start_executor_disabled is None
            or guarded_start_executor_disabled.get("status") == "ready_disabled_by_default"
        )
        and (
            guarded_start_executor_enablement_gate is None
            or guarded_start_executor_enablement_gate.get("status") == "ready_for_reviewed_guarded_start_execution"
        )
        and (
            reviewed_guarded_start_execution_contract is None
            or reviewed_guarded_start_execution_contract.get("status") == "ready_for_guarded_start_dry_run_contract"
        )
        and (
            guarded_start_dry_run_contract is None
            or guarded_start_dry_run_contract.get("status") == "ready_for_guarded_start_simulation"
        )
        and (
            guarded_start_simulation_contract is None
            or guarded_start_simulation_contract.get("status") == "ready_for_guarded_start_runtime_handoff"
        )
        and (
            guarded_start_runtime_handoff_contract is None
            or guarded_start_runtime_handoff_contract.get("status") == "ready_for_guarded_start_policy_patch_review"
        )
        and (
            guarded_start_policy_patch_review_contract is None
            or guarded_start_policy_patch_review_contract.get("status") == "ready_for_guarded_start_human_review"
        )
        and (
            guarded_start_human_review_contract is None
            or guarded_start_human_review_contract.get("status") == "ready_for_guarded_start_final_enablement_gate"
        )
        and (
            guarded_start_final_enablement_gate is None
            or guarded_start_final_enablement_gate.get("status") == "ready_for_guarded_start_policy_enablement_contract"
        )
        and (
            guarded_start_policy_enablement_contract is None
            or guarded_start_policy_enablement_contract.get("status") == "ready_for_guarded_start_activation_contract"
        )
        and (
            guarded_start_activation_contract is None
            or guarded_start_activation_contract.get("status") == "ready_for_guarded_start_execution_attempt_contract"
        )
    ):
        return "passed_no_process_start"

    return "blocked"
