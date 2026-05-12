from __future__ import annotations

from typing import Any, Mapping

from .managed_env_writer import (
    SCHEMA_VERSION as MANAGED_ENV_WRITER_SCHEMA_VERSION,
    inspect_managed_env_writer,
)
from .supervised_process_adapter_packet import validate_supervised_process_adapter_packet
from .supervised_launch_execution import (
    FINAL_START_EXECUTOR_DISABLED_SCHEMA_VERSION,
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
    CONTROLLED_LIVEKIT_SERVER_SUPERVISED_SMOKE_CONTRACT_SCHEMA_VERSION,
    CONTROLLED_LIVEKIT_SERVER_SUPERVISED_SMOKE_PLAN_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_START_GATE_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_PROCESS_RUNNER_START_GATE_SCHEMA_VERSION,
    GUARDED_START_RELEASE_CANDIDATE_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_RELEASE_CANDIDATE_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_DRY_RUN_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_DRY_RUN_PLAN_SCHEMA_VERSION,
    GUARDED_START_FINAL_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_FINAL_ENABLEMENT_GATE_SCHEMA_VERSION,
    GUARDED_START_HUMAN_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_HUMAN_REVIEW_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_POLICY_PATCH_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_POLICY_PATCH_REVIEW_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_POLICY_ENABLEMENT_AUTHORIZATION_SCHEMA_VERSION,
    GUARDED_START_POLICY_ENABLEMENT_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_RUNTIME_HANDOFF_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_RUNTIME_HANDOFF_PLAN_SCHEMA_VERSION,
    GUARDED_START_SIMULATION_CONTRACT_SCHEMA_VERSION,
    GUARDED_START_SIMULATION_PLAN_SCHEMA_VERSION,
    GUARDED_START_EXECUTOR_DISABLED_SCHEMA_VERSION,
    GUARDED_START_EXECUTOR_ENABLEMENT_GATE_SCHEMA_VERSION,
    REVIEWED_GUARDED_START_EXECUTION_CONTRACT_SCHEMA_VERSION,
    REAL_START_EXECUTION_CONTRACT_SCHEMA_VERSION,
    REAL_START_ADAPTER_DISABLED_SCHEMA_VERSION,
    REAL_START_ADAPTER_REVIEW_CONTRACT_SCHEMA_VERSION,
    REAL_START_ENABLEMENT_GATE_SCHEMA_VERSION,
    REVIEWED_REAL_START_EXECUTION_CONTRACT_SCHEMA_VERSION,
    REVIEWED_SUBPROCESS_START_EXECUTION_SCHEMA_VERSION,
    RUNTIME_POLICY_ENABLEMENT_REVIEW_SCHEMA_VERSION,
    SCHEMA_VERSION as SUPERVISED_LAUNCH_EXECUTION_SCHEMA_VERSION,
    SUPERVISED_START_EXECUTION_REVIEW_SCHEMA_VERSION,
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
    inspect_guarded_start_dry_run_contract,
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


SCHEMA_VERSION = "atlas.voice_realtime.supervised_process_adapter.v1"
MANAGED_ENV_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.managed_env_contract.v1"
LAUNCH_AUTHORIZATION_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.launch_authorization_contract.v1"
MANAGED_ENV_WRITER_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.managed_env_writer.v1"
SUPERVISED_LAUNCH_EXECUTION_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.supervised_launch_execution.v1"
SUBPROCESS_START_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.subprocess_start_contract.v1"


class AtlasVoiceSupervisedProcessAdapter:
    """Reviewed process-adapter shell for the future LiveKit daemon.

    This class deliberately does not import subprocess or LiveKit. It gives the
    Kernel a concrete adapter contract, health surface and rollback surface
    while keeping process launch disabled until the production review upgrades
    this boundary.
    """

    def inspect(self, supervisor_execution: Mapping[str, Any]) -> Mapping[str, Any]:
        blueprint = supervisor_execution.get("process_adapter_blueprint", {})
        ready = (
            supervisor_execution.get("status") == "ready_for_process_adapter_implementation"
            and isinstance(blueprint, Mapping)
            and blueprint.get("schema_version") == "atlas.voice_realtime.daemon_process_adapter_blueprint.v1"
            and blueprint.get("launch_allowed") is False
            and supervisor_execution.get("process_launch_attempted") is False
            and supervisor_execution.get("daemon_started") is False
        )

        managed_environment_contract = self.prepare_environment(supervisor_execution)
        launch_authorization_contract = self.authorize_launch(
            supervisor_execution=supervisor_execution,
            managed_environment_contract=managed_environment_contract,
        )
        managed_env_writer = inspect_managed_env_writer(
            managed_environment_contract=managed_environment_contract,
            launch_authorization_contract=launch_authorization_contract,
        )
        supervised_launch_execution = inspect_supervised_launch_execution(
            supervisor_execution=supervisor_execution,
            launch_authorization_contract=launch_authorization_contract,
        )
        subprocess_start_contract = inspect_subprocess_start_contract(
            supervised_launch_execution=supervised_launch_execution,
        )
        reviewed_subprocess_start_execution = inspect_reviewed_subprocess_start_execution(
            subprocess_start_contract=subprocess_start_contract,
        )
        real_start_adapter_disabled = inspect_real_start_adapter_disabled_by_default(
            reviewed_subprocess_start_execution=reviewed_subprocess_start_execution,
        )
        real_start_enablement_gate = inspect_real_start_adapter_enablement_gate(
            real_start_adapter_disabled=real_start_adapter_disabled,
        )
        runtime_policy_enablement_review = inspect_runtime_policy_enablement_review(
            real_start_enablement_gate=real_start_enablement_gate,
        )
        real_start_adapter_review_contract = inspect_real_start_adapter_review_contract(
            runtime_policy_enablement_review=runtime_policy_enablement_review,
        )
        reviewed_real_start_execution_contract = inspect_reviewed_real_start_execution_contract(
            real_start_adapter_review_contract=real_start_adapter_review_contract,
        )
        final_start_executor_disabled = inspect_final_start_executor_disabled_by_default(
            reviewed_real_start_execution_contract=reviewed_real_start_execution_contract,
        )
        final_start_executor_enablement_gate = inspect_final_start_executor_enablement_gate(
            final_start_executor_disabled=final_start_executor_disabled,
        )
        supervised_start_execution_review = inspect_supervised_start_execution_review(
            final_start_executor_enablement_gate=final_start_executor_enablement_gate,
        )
        real_start_execution_contract = inspect_real_start_execution_contract(
            supervised_start_execution_review=supervised_start_execution_review,
        )
        guarded_start_executor_disabled = inspect_guarded_start_executor_disabled_by_default(
            real_start_execution_contract=real_start_execution_contract,
        )
        guarded_start_executor_enablement_gate = inspect_guarded_start_executor_enablement_gate(
            guarded_start_executor_disabled=guarded_start_executor_disabled,
        )
        reviewed_guarded_start_execution_contract = inspect_reviewed_guarded_start_execution_contract(
            guarded_start_executor_enablement_gate=guarded_start_executor_enablement_gate,
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_dry_run",
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_simulation",
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_runtime_handoff",
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_policy_patch_review",
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_human_review",
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_final_enablement",
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_policy_enablement",
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_activation",
            },
        )
        guarded_start_execution_attempt_contract = inspect_guarded_start_execution_attempt_contract(
            guarded_start_activation_contract=guarded_start_activation_contract,
            execution_attempt_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_execution_attempt",
            },
        )
        guarded_start_execution_rehearsal_contract = inspect_guarded_start_execution_rehearsal_contract(
            guarded_start_execution_attempt_contract=guarded_start_execution_attempt_contract,
            execution_rehearsal_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_execution_rehearsal",
            },
        )
        guarded_start_observability_contract = inspect_guarded_start_observability_contract(
            guarded_start_execution_rehearsal_contract=guarded_start_execution_rehearsal_contract,
            observability_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_observability",
            },
        )
        guarded_start_release_candidate_contract = inspect_guarded_start_release_candidate_contract(
            guarded_start_observability_contract=guarded_start_observability_contract,
            release_candidate_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_release_candidate",
            },
        )
        guarded_start_operator_acceptance_contract = inspect_guarded_start_operator_acceptance_contract(
            guarded_start_release_candidate_contract=guarded_start_release_candidate_contract,
            operator_acceptance_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_operator_acceptance",
                "operator_acceptance_receipt_id": "operator_acceptance_receipt_process_adapter_1",
            },
        )
        guarded_start_final_start_receipt_contract = inspect_guarded_start_final_start_receipt_contract(
            guarded_start_operator_acceptance_contract=guarded_start_operator_acceptance_contract,
            final_start_receipt_authorization={
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
                "operator_acceptance_receipt_id": "operator_acceptance_receipt_process_adapter_1",
                "final_start_receipt_id": "final_start_receipt_process_adapter_1",
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_final_start_receipt",
            },
        )
        guarded_start_launch_window_contract = inspect_guarded_start_launch_window_contract(
            guarded_start_final_start_receipt_contract=guarded_start_final_start_receipt_contract,
            launch_window_authorization={
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
                "final_start_receipt_id": "final_start_receipt_process_adapter_1",
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_launch_window",
            },
        )
        guarded_start_pre_launch_guard_contract = inspect_guarded_start_pre_launch_guard_contract(
            guarded_start_launch_window_contract=guarded_start_launch_window_contract,
            pre_launch_guard_authorization={
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
                "final_start_receipt_id": "final_start_receipt_process_adapter_1",
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_pre_launch_guard",
            },
        )
        guarded_start_executor_runtime_contract = inspect_guarded_start_executor_runtime_contract(
            guarded_start_pre_launch_guard_contract=guarded_start_pre_launch_guard_contract,
            executor_runtime_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_executor_runtime",
            },
        )
        guarded_start_process_spawn_contract = inspect_guarded_start_process_spawn_contract(
            guarded_start_executor_runtime_contract=guarded_start_executor_runtime_contract,
            process_spawn_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_process_spawn",
            },
        )
        guarded_start_spawn_review_contract = inspect_guarded_start_spawn_review_contract(
            guarded_start_process_spawn_contract=guarded_start_process_spawn_contract,
            spawn_review_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_spawn_review",
            },
        )
        guarded_start_subprocess_import_contract = inspect_guarded_start_subprocess_import_contract(
            guarded_start_spawn_review_contract=guarded_start_spawn_review_contract,
            subprocess_import_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_subprocess_import",
            },
        )
        guarded_start_launch_invocation_contract = inspect_guarded_start_launch_invocation_contract(
            guarded_start_subprocess_import_contract=guarded_start_subprocess_import_contract,
            launch_invocation_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_launch_invocation",
            },
        )
        guarded_start_final_process_start_contract = inspect_guarded_start_final_process_start_contract(
            guarded_start_launch_invocation_contract=guarded_start_launch_invocation_contract,
            final_process_start_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_final_process_start",
            },
        )
        guarded_start_process_execution_review = inspect_guarded_start_process_execution_review(
            guarded_start_final_process_start_contract=guarded_start_final_process_start_contract,
            process_execution_review_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_process_execution_review",
            },
        )
        guarded_start_process_execution_packet = inspect_guarded_start_process_execution_packet(
            guarded_start_process_execution_review=guarded_start_process_execution_review,
            process_execution_packet_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_process_execution_packet",
            },
        )
        guarded_start_process_executor_stub = inspect_guarded_start_process_executor_stub(
            guarded_start_process_execution_packet=guarded_start_process_execution_packet,
            process_executor_stub_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_process_executor_stub",
            },
        )
        guarded_start_process_executor_review = inspect_guarded_start_process_executor_review(
            guarded_start_process_executor_stub=guarded_start_process_executor_stub,
            process_executor_review_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_process_executor_review",
            },
        )
        guarded_start_process_executor_contract = inspect_guarded_start_process_executor_contract(
            guarded_start_process_executor_review=guarded_start_process_executor_review,
            process_executor_contract_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_process_executor_contract",
            },
        )
        guarded_start_process_runtime_adapter = inspect_guarded_start_process_runtime_adapter(
            guarded_start_process_executor_contract=guarded_start_process_executor_contract,
            process_runtime_adapter_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_process_runtime_adapter",
            },
        )
        guarded_start_process_adapter_review = inspect_guarded_start_process_adapter_review(
            guarded_start_process_runtime_adapter=guarded_start_process_runtime_adapter,
            process_adapter_review_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_process_adapter_review",
            },
        )
        guarded_start_process_adapter_contract = inspect_guarded_start_process_adapter_contract(
            guarded_start_process_adapter_review=guarded_start_process_adapter_review,
            process_adapter_contract_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_process_adapter_contract",
            },
        )
        guarded_start_process_runner_contract = inspect_guarded_start_process_runner_contract(
            guarded_start_process_adapter_contract=guarded_start_process_adapter_contract,
            process_runner_contract_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_process_runner_contract",
            },
        )
        guarded_start_process_runner_review = inspect_guarded_start_process_runner_review(
            guarded_start_process_runner_contract=guarded_start_process_runner_contract,
            process_runner_review_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_process_runner_review",
            },
        )
        guarded_start_process_runner_packet = inspect_guarded_start_process_runner_packet(
            guarded_start_process_runner_review=guarded_start_process_runner_review,
            process_runner_packet_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_process_runner_packet",
            },
        )
        guarded_start_process_runner_execution_review = inspect_guarded_start_process_runner_execution_review(
            guarded_start_process_runner_packet=guarded_start_process_runner_packet,
            process_runner_execution_review_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_process_runner_execution_review",
            },
        )
        guarded_start_process_runner_execution_contract = inspect_guarded_start_process_runner_execution_contract(
            guarded_start_process_runner_execution_review=guarded_start_process_runner_execution_review,
            process_runner_execution_contract_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_process_runner_execution_contract",
            },
        )
        guarded_start_process_runner_start_gate = inspect_guarded_start_process_runner_start_gate(
            guarded_start_process_runner_execution_contract=guarded_start_process_runner_execution_contract,
            process_runner_start_gate_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_process_runner_start_gate",
            },
        )
        guarded_start_process_runner_final_review = inspect_guarded_start_process_runner_final_review(
            guarded_start_process_runner_start_gate=guarded_start_process_runner_start_gate,
            process_runner_final_review_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_process_runner_final_review",
                "final_review_receipt_id": "final_review_receipt_process_adapter_guarded_start_process_runner",
            },
        )
        guarded_start_process_runner_promotion_packet = inspect_guarded_start_process_runner_promotion_packet(
            guarded_start_process_runner_final_review=guarded_start_process_runner_final_review,
            process_runner_promotion_packet_authorization={
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
                "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_process_runner_promotion_packet",
                "promotion_packet_receipt_id": "promotion_packet_receipt_process_adapter_guarded_start_process_runner",
                "reviewed_bundle_hash": "n"*64,
            },
        )
        guarded_start_process_runner_operator_release_review = (
            inspect_guarded_start_process_runner_operator_release_review(
                guarded_start_process_runner_promotion_packet=guarded_start_process_runner_promotion_packet,
                operator_release_review_authorization={
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
                    "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_process_runner_operator_release_review",
                    "operator_release_review_receipt_id": "operator_release_review_receipt_process_adapter_guarded_start_process_runner",
                    "reviewed_bundle_hash": "o"*64,
                },
            )
        )
        guarded_start_process_runner_release_finalization = (
            inspect_guarded_start_process_runner_release_finalization(
                guarded_start_process_runner_operator_release_review=guarded_start_process_runner_operator_release_review,
                release_finalization_authorization={
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
                    "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_process_runner_release_finalization",
                    "final_operator_release_receipt_id": "final_operator_release_receipt_process_adapter_guarded_start_process_runner",
                    "release_bundle_hash": "p"*64,
                },
            )
        )
        guarded_start_process_runner_release_authorization = (
            inspect_guarded_start_process_runner_release_authorization(
                guarded_start_process_runner_release_finalization=guarded_start_process_runner_release_finalization,
                release_authorization={
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
                    "decision_receipt_id": "decision_receipt_process_adapter_guarded_start_process_runner_release_authorization",
                    "release_authorization_receipt_id": "release_authorization_receipt_process_adapter_guarded_start_process_runner",
                    "release_bundle_hash": "q"*64,
                },
            )
        )
        controlled_livekit_server_supervised_smoke_contract = (
            inspect_controlled_livekit_server_supervised_smoke_contract(
                guarded_start_process_runner_release_authorization=guarded_start_process_runner_release_authorization,
                controlled_smoke_plan={
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
                    "decision_receipt_id": "decision_receipt_process_adapter_controlled_livekit_server_smoke",
                    "controlled_smoke_receipt_id": "controlled_smoke_receipt_process_adapter_livekit_server",
                    "release_bundle_hash": "r"*64,
                },
            )
        )

        return validate_supervised_process_adapter_packet({
            "schema_version": SCHEMA_VERSION,
            "status": "ready_fail_closed" if ready else "blocked",
            "adapter_id": "livekit_agents_supervised_process_adapter",
            "implementation_status": "reviewed_shell_no_launch",
            "launch_allowed": False,
            "process_launch_attempted": False,
            "daemon_started": False,
            "subprocess_module_imported": False,
            "livekit_sdk_imported": False,
            "implemented_methods": [
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
                "authorize_launch",
                "execute_managed_env_write",
                "inspect_supervised_launch_execution",
                "execute_pre_start_health_checks",
                "inspect_subprocess_start_contract",
                "inspect_reviewed_subprocess_start_execution",
                "inspect_real_start_adapter_disabled_by_default",
                "inspect_real_start_adapter_enablement_gate",
                "inspect_runtime_policy_enablement_review",
                "inspect_real_start_adapter_review_contract",
                "inspect_reviewed_real_start_execution_contract",
                "inspect_final_start_executor_disabled_by_default",
                "inspect_final_start_executor_enablement_gate",
                "inspect_supervised_start_execution_review",
                "inspect_real_start_execution_contract",
                "inspect_guarded_start_executor_disabled_by_default",
                "inspect_guarded_start_executor_enablement_gate",
                "inspect_reviewed_guarded_start_execution_contract",
                "inspect_guarded_start_dry_run_contract",
                "inspect_guarded_start_simulation_contract",
                "inspect_guarded_start_runtime_handoff_contract",
                "inspect_guarded_start_policy_patch_review_contract",
                "inspect_guarded_start_human_review_contract",
                "inspect_guarded_start_final_enablement_gate_contract",
                "inspect_guarded_start_policy_enablement_contract",
                "inspect_guarded_start_activation_contract",
                "inspect_guarded_start_execution_attempt_contract",
                "inspect_guarded_start_execution_rehearsal_contract",
                "inspect_guarded_start_observability_contract",
                "inspect_guarded_start_release_candidate_contract",
                "inspect_guarded_start_operator_acceptance_contract",
                "inspect_guarded_start_final_start_receipt_contract",
                "inspect_guarded_start_launch_window_contract",
                "inspect_guarded_start_pre_launch_guard_contract",
                "inspect_guarded_start_executor_runtime_contract",
                "inspect_guarded_start_process_spawn_contract",
                "inspect_guarded_start_spawn_review_contract",
                "inspect_guarded_start_subprocess_import_contract",
                "inspect_guarded_start_launch_invocation_contract",
                "inspect_guarded_start_final_process_start_contract",
                "inspect_guarded_start_process_execution_review",
                "inspect_guarded_start_process_execution_packet",
                "inspect_guarded_start_process_executor_stub",
                "inspect_guarded_start_process_executor_review",
                "inspect_guarded_start_process_executor_contract",
                "inspect_guarded_start_process_runtime_adapter",
                "inspect_guarded_start_process_adapter_review",
                "inspect_guarded_start_process_adapter_contract",
                "inspect_guarded_start_process_runner_contract",
                "inspect_guarded_start_process_runner_review",
                "inspect_guarded_start_process_runner_packet",
                "inspect_guarded_start_process_runner_execution_review",
                "inspect_guarded_start_process_runner_execution_contract",
                "inspect_guarded_start_process_runner_start_gate",
                "inspect_guarded_start_process_runner_final_review",
                "inspect_guarded_start_process_runner_promotion_packet",
                "inspect_guarded_start_process_runner_operator_release_review",
                "inspect_guarded_start_process_runner_release_finalization",
                "inspect_guarded_start_process_runner_release_authorization",
                "inspect_controlled_livekit_server_supervised_smoke_contract",
            ],
            "gates": {
                "supervisor_execution_ready": ready,
                "blueprint_available": isinstance(blueprint, Mapping),
                "launch_disabled": True,
                "secrets_redacted": True,
                "managed_environment_contract_available": (
                    managed_environment_contract.get("schema_version") == MANAGED_ENV_CONTRACT_SCHEMA_VERSION
                    and managed_environment_contract.get("env_file_write_attempted") is False
                    and managed_environment_contract.get("secret_values_present_in_output") is False
                ),
                "launch_authorization_contract_available": (
                    launch_authorization_contract.get("schema_version") == LAUNCH_AUTHORIZATION_CONTRACT_SCHEMA_VERSION
                    and launch_authorization_contract.get("launch_allowed") is False
                    and launch_authorization_contract.get("process_launch_attempted") is False
                ),
                "launch_authorization_contract_ready": (
                    launch_authorization_contract.get("status") == "authorized_for_implementation_not_launch"
                ),
                "managed_env_writer_contract_available": (
                    managed_env_writer.get("schema_version") == MANAGED_ENV_WRITER_SCHEMA_VERSION
                    and managed_env_writer.get("writer_contract_implemented") is True
                    and managed_env_writer.get("write_execution_implemented") is False
                    and managed_env_writer.get("env_file_write_attempted") is False
                    and managed_env_writer.get("secret_values_present_in_output") is False
                ),
                "supervised_launch_execution_contract_available": (
                    supervised_launch_execution.get("schema_version") == SUPERVISED_LAUNCH_EXECUTION_SCHEMA_VERSION
                    and supervised_launch_execution.get("launch_execution_implemented") is True
                    and supervised_launch_execution.get("pre_start_health_checks_execution_available") is True
                    and supervised_launch_execution.get("pre_start_health_checks_executed") is False
                    and supervised_launch_execution.get("subprocess_launch_implemented") is False
                    and supervised_launch_execution.get("process_launch_attempted") is False
                    and supervised_launch_execution.get("daemon_started") is False
                ),
                "subprocess_start_contract_available": (
                    subprocess_start_contract.get("schema_version") == SUBPROCESS_START_CONTRACT_SCHEMA_VERSION
                    and subprocess_start_contract.get("subprocess_start_contract_implemented") is True
                    and subprocess_start_contract.get("subprocess_launch_implemented") is False
                    and subprocess_start_contract.get("process_launch_attempted") is False
                    and subprocess_start_contract.get("daemon_started") is False
                    and subprocess_start_contract.get("subprocess_module_imported") is False
                    and subprocess_start_contract.get("livekit_sdk_imported") is False
                ),
                "reviewed_subprocess_start_execution_available": (
                    reviewed_subprocess_start_execution.get("schema_version") == REVIEWED_SUBPROCESS_START_EXECUTION_SCHEMA_VERSION
                    and reviewed_subprocess_start_execution.get("reviewed_subprocess_start_execution_implemented") is True
                    and reviewed_subprocess_start_execution.get("real_subprocess_start_implemented") is False
                    and reviewed_subprocess_start_execution.get("process_launch_attempted") is False
                    and reviewed_subprocess_start_execution.get("daemon_started") is False
                    and reviewed_subprocess_start_execution.get("subprocess_module_imported") is False
                    and reviewed_subprocess_start_execution.get("livekit_sdk_imported") is False
                ),
                "real_start_adapter_disabled_available": (
                    real_start_adapter_disabled.get("schema_version") == REAL_START_ADAPTER_DISABLED_SCHEMA_VERSION
                    and real_start_adapter_disabled.get("real_start_adapter_contract_implemented") is True
                    and real_start_adapter_disabled.get("real_start_adapter_enabled") is False
                    and real_start_adapter_disabled.get("real_subprocess_start_implemented") is False
                    and real_start_adapter_disabled.get("process_launch_attempted") is False
                    and real_start_adapter_disabled.get("daemon_started") is False
                    and real_start_adapter_disabled.get("subprocess_module_imported") is False
                    and real_start_adapter_disabled.get("livekit_sdk_imported") is False
                ),
                "real_start_enablement_gate_available": (
                    real_start_enablement_gate.get("schema_version") == REAL_START_ENABLEMENT_GATE_SCHEMA_VERSION
                    and real_start_enablement_gate.get("real_start_enablement_gate_implemented") is True
                    and real_start_enablement_gate.get("real_start_adapter_enabled") is False
                    and real_start_enablement_gate.get("start_execution_allowed") is False
                    and real_start_enablement_gate.get("real_subprocess_start_implemented") is False
                    and real_start_enablement_gate.get("process_launch_attempted") is False
                    and real_start_enablement_gate.get("daemon_started") is False
                    and real_start_enablement_gate.get("subprocess_module_imported") is False
                    and real_start_enablement_gate.get("livekit_sdk_imported") is False
                ),
                "runtime_policy_enablement_review_available": (
                    runtime_policy_enablement_review.get("schema_version") == RUNTIME_POLICY_ENABLEMENT_REVIEW_SCHEMA_VERSION
                    and runtime_policy_enablement_review.get("runtime_policy_enablement_review_implemented") is True
                    and runtime_policy_enablement_review.get("runtime_policy_start_enabled") is False
                    and runtime_policy_enablement_review.get("real_start_adapter_enabled") is False
                    and runtime_policy_enablement_review.get("start_execution_allowed") is False
                    and runtime_policy_enablement_review.get("real_subprocess_start_implemented") is False
                    and runtime_policy_enablement_review.get("process_launch_attempted") is False
                    and runtime_policy_enablement_review.get("daemon_started") is False
                    and runtime_policy_enablement_review.get("subprocess_module_imported") is False
                    and runtime_policy_enablement_review.get("livekit_sdk_imported") is False
                ),
                "real_start_adapter_review_contract_available": (
                    real_start_adapter_review_contract.get("schema_version") == REAL_START_ADAPTER_REVIEW_CONTRACT_SCHEMA_VERSION
                    and real_start_adapter_review_contract.get("real_start_adapter_review_contract_implemented") is True
                    and real_start_adapter_review_contract.get("runtime_policy_start_enabled") is False
                    and real_start_adapter_review_contract.get("real_start_adapter_enabled") is False
                    and real_start_adapter_review_contract.get("start_execution_allowed") is False
                    and real_start_adapter_review_contract.get("real_subprocess_start_implemented") is False
                    and real_start_adapter_review_contract.get("process_launch_attempted") is False
                    and real_start_adapter_review_contract.get("daemon_started") is False
                    and real_start_adapter_review_contract.get("subprocess_module_imported") is False
                    and real_start_adapter_review_contract.get("livekit_sdk_imported") is False
                ),
                "reviewed_real_start_execution_contract_available": (
                    reviewed_real_start_execution_contract.get("schema_version") == REVIEWED_REAL_START_EXECUTION_CONTRACT_SCHEMA_VERSION
                    and reviewed_real_start_execution_contract.get("reviewed_real_start_execution_contract_implemented") is True
                    and reviewed_real_start_execution_contract.get("runtime_policy_start_enabled") is False
                    and reviewed_real_start_execution_contract.get("real_start_adapter_enabled") is False
                    and reviewed_real_start_execution_contract.get("start_execution_allowed") is False
                    and reviewed_real_start_execution_contract.get("real_subprocess_start_implemented") is False
                    and reviewed_real_start_execution_contract.get("process_launch_attempted") is False
                    and reviewed_real_start_execution_contract.get("daemon_started") is False
                    and reviewed_real_start_execution_contract.get("subprocess_module_imported") is False
                    and reviewed_real_start_execution_contract.get("livekit_sdk_imported") is False
                ),
                "final_start_executor_disabled_available": (
                    final_start_executor_disabled.get("schema_version") == FINAL_START_EXECUTOR_DISABLED_SCHEMA_VERSION
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
                ),
                "final_start_executor_enablement_gate_available": (
                    final_start_executor_enablement_gate.get("schema_version") == FINAL_START_EXECUTOR_ENABLEMENT_GATE_SCHEMA_VERSION
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
                ),
                "supervised_start_execution_review_available": (
                    supervised_start_execution_review.get("schema_version") == SUPERVISED_START_EXECUTION_REVIEW_SCHEMA_VERSION
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
                ),
                "real_start_execution_contract_available": (
                    real_start_execution_contract.get("schema_version") == REAL_START_EXECUTION_CONTRACT_SCHEMA_VERSION
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
                ),
                "guarded_start_executor_disabled_available": (
                    guarded_start_executor_disabled.get("schema_version") == GUARDED_START_EXECUTOR_DISABLED_SCHEMA_VERSION
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
                ),
                "guarded_start_executor_enablement_gate_available": (
                    guarded_start_executor_enablement_gate.get("schema_version") == GUARDED_START_EXECUTOR_ENABLEMENT_GATE_SCHEMA_VERSION
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
                ),
                "reviewed_guarded_start_execution_contract_available": (
                    reviewed_guarded_start_execution_contract.get("schema_version") == REVIEWED_GUARDED_START_EXECUTION_CONTRACT_SCHEMA_VERSION
                    and reviewed_guarded_start_execution_contract.get("reviewed_guarded_start_execution_contract_implemented") is True
                    and reviewed_guarded_start_execution_contract.get("guarded_start_executor_enabled") is False
                    and reviewed_guarded_start_execution_contract.get("guarded_start_executor_implemented") is False
                    and reviewed_guarded_start_execution_contract.get("final_start_executor_enabled") is False
                    and reviewed_guarded_start_execution_contract.get("runtime_policy_start_enabled") is False
                    and reviewed_guarded_start_execution_contract.get("real_start_adapter_enabled") is False
                    and reviewed_guarded_start_execution_contract.get("start_execution_allowed") is False
                    and reviewed_guarded_start_execution_contract.get("real_subprocess_start_implemented") is False
                    and reviewed_guarded_start_execution_contract.get("process_launch_attempted") is False
                    and reviewed_guarded_start_execution_contract.get("daemon_started") is False
                    and reviewed_guarded_start_execution_contract.get("subprocess_module_imported") is False
                    and reviewed_guarded_start_execution_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_dry_run_contract_available": (
                    guarded_start_dry_run_contract.get("schema_version") == GUARDED_START_DRY_RUN_CONTRACT_SCHEMA_VERSION
                    and guarded_start_dry_run_contract.get("guarded_start_dry_run_contract_implemented") is True
                    and guarded_start_dry_run_contract.get("dry_run_only") is True
                    and guarded_start_dry_run_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_dry_run_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_dry_run_contract.get("start_execution_allowed") is False
                    and guarded_start_dry_run_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_dry_run_contract.get("process_launch_attempted") is False
                    and guarded_start_dry_run_contract.get("daemon_started") is False
                    and guarded_start_dry_run_contract.get("subprocess_module_imported") is False
                    and guarded_start_dry_run_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_simulation_contract_available": (
                    guarded_start_simulation_contract.get("schema_version") == GUARDED_START_SIMULATION_CONTRACT_SCHEMA_VERSION
                    and guarded_start_simulation_contract.get("guarded_start_simulation_contract_implemented") is True
                    and guarded_start_simulation_contract.get("simulation_only") is True
                    and guarded_start_simulation_contract.get("dry_run_only") is True
                    and guarded_start_simulation_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_simulation_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_simulation_contract.get("start_execution_allowed") is False
                    and guarded_start_simulation_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_simulation_contract.get("process_launch_attempted") is False
                    and guarded_start_simulation_contract.get("daemon_started") is False
                    and guarded_start_simulation_contract.get("subprocess_module_imported") is False
                    and guarded_start_simulation_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_runtime_handoff_contract_available": (
                    guarded_start_runtime_handoff_contract.get("schema_version") == GUARDED_START_RUNTIME_HANDOFF_CONTRACT_SCHEMA_VERSION
                    and guarded_start_runtime_handoff_contract.get("guarded_start_runtime_handoff_contract_implemented") is True
                    and guarded_start_runtime_handoff_contract.get("runtime_handoff_contract_only") is True
                    and guarded_start_runtime_handoff_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_runtime_handoff_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_runtime_handoff_contract.get("start_execution_allowed") is False
                    and guarded_start_runtime_handoff_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_runtime_handoff_contract.get("process_launch_attempted") is False
                    and guarded_start_runtime_handoff_contract.get("daemon_started") is False
                    and guarded_start_runtime_handoff_contract.get("subprocess_module_imported") is False
                    and guarded_start_runtime_handoff_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_policy_patch_review_contract_available": (
                    guarded_start_policy_patch_review_contract.get("schema_version") == GUARDED_START_POLICY_PATCH_REVIEW_CONTRACT_SCHEMA_VERSION
                    and guarded_start_policy_patch_review_contract.get("guarded_start_policy_patch_review_contract_implemented") is True
                    and guarded_start_policy_patch_review_contract.get("policy_patch_review_only") is True
                    and guarded_start_policy_patch_review_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_policy_patch_review_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_policy_patch_review_contract.get("runtime_policy_start_enabled") is False
                    and guarded_start_policy_patch_review_contract.get("start_execution_allowed") is False
                    and guarded_start_policy_patch_review_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_policy_patch_review_contract.get("process_launch_attempted") is False
                    and guarded_start_policy_patch_review_contract.get("daemon_started") is False
                    and guarded_start_policy_patch_review_contract.get("subprocess_module_imported") is False
                    and guarded_start_policy_patch_review_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_human_review_contract_available": (
                    guarded_start_human_review_contract.get("schema_version") == GUARDED_START_HUMAN_REVIEW_CONTRACT_SCHEMA_VERSION
                    and guarded_start_human_review_contract.get("guarded_start_human_review_contract_implemented") is True
                    and guarded_start_human_review_contract.get("human_review_contract_only") is True
                    and guarded_start_human_review_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_human_review_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_human_review_contract.get("runtime_policy_start_enabled") is False
                    and guarded_start_human_review_contract.get("start_execution_allowed") is False
                    and guarded_start_human_review_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_human_review_contract.get("process_launch_attempted") is False
                    and guarded_start_human_review_contract.get("daemon_started") is False
                    and guarded_start_human_review_contract.get("subprocess_module_imported") is False
                    and guarded_start_human_review_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_final_enablement_gate_available": (
                    guarded_start_final_enablement_gate.get("schema_version") == GUARDED_START_FINAL_ENABLEMENT_GATE_SCHEMA_VERSION
                    and guarded_start_final_enablement_gate.get("guarded_start_final_enablement_gate_implemented") is True
                    and guarded_start_final_enablement_gate.get("final_enablement_gate_only") is True
                    and guarded_start_final_enablement_gate.get("guarded_start_executor_enabled") is False
                    and guarded_start_final_enablement_gate.get("guarded_start_executor_implemented") is False
                    and guarded_start_final_enablement_gate.get("runtime_policy_start_enabled") is False
                    and guarded_start_final_enablement_gate.get("start_execution_allowed") is False
                    and guarded_start_final_enablement_gate.get("real_subprocess_start_implemented") is False
                    and guarded_start_final_enablement_gate.get("process_launch_attempted") is False
                    and guarded_start_final_enablement_gate.get("daemon_started") is False
                    and guarded_start_final_enablement_gate.get("subprocess_module_imported") is False
                    and guarded_start_final_enablement_gate.get("livekit_sdk_imported") is False
                ),
                "guarded_start_policy_enablement_contract_available": (
                    guarded_start_policy_enablement_contract.get("schema_version") == GUARDED_START_POLICY_ENABLEMENT_CONTRACT_SCHEMA_VERSION
                    and guarded_start_policy_enablement_contract.get("guarded_start_policy_enablement_contract_implemented") is True
                    and guarded_start_policy_enablement_contract.get("policy_enablement_contract_only") is True
                    and guarded_start_policy_enablement_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_policy_enablement_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_policy_enablement_contract.get("start_execution_allowed") is False
                    and guarded_start_policy_enablement_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_policy_enablement_contract.get("process_launch_attempted") is False
                    and guarded_start_policy_enablement_contract.get("daemon_started") is False
                    and guarded_start_policy_enablement_contract.get("subprocess_module_imported") is False
                    and guarded_start_policy_enablement_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_activation_contract_available": (
                    guarded_start_activation_contract.get("schema_version") == GUARDED_START_ACTIVATION_CONTRACT_SCHEMA_VERSION
                    and guarded_start_activation_contract.get("guarded_start_activation_contract_implemented") is True
                    and guarded_start_activation_contract.get("activation_contract_only") is True
                    and guarded_start_activation_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_activation_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_activation_contract.get("start_execution_allowed") is False
                    and guarded_start_activation_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_activation_contract.get("process_launch_attempted") is False
                    and guarded_start_activation_contract.get("daemon_started") is False
                    and guarded_start_activation_contract.get("subprocess_module_imported") is False
                    and guarded_start_activation_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_execution_attempt_contract_available": (
                    guarded_start_execution_attempt_contract.get("schema_version") == GUARDED_START_EXECUTION_ATTEMPT_CONTRACT_SCHEMA_VERSION
                    and guarded_start_execution_attempt_contract.get("guarded_start_execution_attempt_contract_implemented") is True
                    and guarded_start_execution_attempt_contract.get("execution_attempt_contract_only") is True
                    and guarded_start_execution_attempt_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_execution_attempt_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_execution_attempt_contract.get("start_execution_allowed") is False
                    and guarded_start_execution_attempt_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_execution_attempt_contract.get("process_launch_attempted") is False
                    and guarded_start_execution_attempt_contract.get("daemon_started") is False
                    and guarded_start_execution_attempt_contract.get("subprocess_module_imported") is False
                    and guarded_start_execution_attempt_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_execution_rehearsal_contract_available": (
                    guarded_start_execution_rehearsal_contract.get("schema_version") == GUARDED_START_EXECUTION_REHEARSAL_CONTRACT_SCHEMA_VERSION
                    and guarded_start_execution_rehearsal_contract.get("guarded_start_execution_rehearsal_contract_implemented") is True
                    and guarded_start_execution_rehearsal_contract.get("execution_rehearsal_contract_only") is True
                    and guarded_start_execution_rehearsal_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_execution_rehearsal_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_execution_rehearsal_contract.get("start_execution_allowed") is False
                    and guarded_start_execution_rehearsal_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_execution_rehearsal_contract.get("process_launch_attempted") is False
                    and guarded_start_execution_rehearsal_contract.get("daemon_started") is False
                    and guarded_start_execution_rehearsal_contract.get("subprocess_module_imported") is False
                    and guarded_start_execution_rehearsal_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_observability_contract_available": (
                    guarded_start_observability_contract.get("schema_version") == GUARDED_START_OBSERVABILITY_CONTRACT_SCHEMA_VERSION
                    and guarded_start_observability_contract.get("guarded_start_observability_contract_implemented") is True
                    and guarded_start_observability_contract.get("observability_contract_only") is True
                    and guarded_start_observability_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_observability_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_observability_contract.get("start_execution_allowed") is False
                    and guarded_start_observability_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_observability_contract.get("process_launch_attempted") is False
                    and guarded_start_observability_contract.get("daemon_started") is False
                    and guarded_start_observability_contract.get("subprocess_module_imported") is False
                    and guarded_start_observability_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_release_candidate_contract_available": (
                    guarded_start_release_candidate_contract.get("schema_version") == GUARDED_START_RELEASE_CANDIDATE_CONTRACT_SCHEMA_VERSION
                    and guarded_start_release_candidate_contract.get("guarded_start_release_candidate_contract_implemented") is True
                    and guarded_start_release_candidate_contract.get("release_candidate_contract_only") is True
                    and guarded_start_release_candidate_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_release_candidate_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_release_candidate_contract.get("start_execution_allowed") is False
                    and guarded_start_release_candidate_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_release_candidate_contract.get("process_launch_attempted") is False
                    and guarded_start_release_candidate_contract.get("daemon_started") is False
                    and guarded_start_release_candidate_contract.get("subprocess_module_imported") is False
                    and guarded_start_release_candidate_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_operator_acceptance_contract_available": (
                    guarded_start_operator_acceptance_contract.get("schema_version") == GUARDED_START_OPERATOR_ACCEPTANCE_CONTRACT_SCHEMA_VERSION
                    and guarded_start_operator_acceptance_contract.get("guarded_start_operator_acceptance_contract_implemented") is True
                    and guarded_start_operator_acceptance_contract.get("operator_acceptance_contract_only") is True
                    and guarded_start_operator_acceptance_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_operator_acceptance_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_operator_acceptance_contract.get("start_execution_allowed") is False
                    and guarded_start_operator_acceptance_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_operator_acceptance_contract.get("process_launch_attempted") is False
                    and guarded_start_operator_acceptance_contract.get("daemon_started") is False
                    and guarded_start_operator_acceptance_contract.get("subprocess_module_imported") is False
                    and guarded_start_operator_acceptance_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_final_start_receipt_contract_available": (
                    guarded_start_final_start_receipt_contract.get("schema_version") == GUARDED_START_FINAL_START_RECEIPT_CONTRACT_SCHEMA_VERSION
                    and guarded_start_final_start_receipt_contract.get("guarded_start_final_start_receipt_contract_implemented") is True
                    and guarded_start_final_start_receipt_contract.get("final_start_receipt_contract_only") is True
                    and guarded_start_final_start_receipt_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_final_start_receipt_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_final_start_receipt_contract.get("start_execution_allowed") is False
                    and guarded_start_final_start_receipt_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_final_start_receipt_contract.get("process_launch_attempted") is False
                    and guarded_start_final_start_receipt_contract.get("daemon_started") is False
                    and guarded_start_final_start_receipt_contract.get("subprocess_module_imported") is False
                    and guarded_start_final_start_receipt_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_launch_window_contract_available": (
                    guarded_start_launch_window_contract.get("schema_version") == GUARDED_START_LAUNCH_WINDOW_CONTRACT_SCHEMA_VERSION
                    and guarded_start_launch_window_contract.get("guarded_start_launch_window_contract_implemented") is True
                    and guarded_start_launch_window_contract.get("launch_window_contract_only") is True
                    and guarded_start_launch_window_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_launch_window_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_launch_window_contract.get("start_execution_allowed") is False
                    and guarded_start_launch_window_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_launch_window_contract.get("process_launch_attempted") is False
                    and guarded_start_launch_window_contract.get("daemon_started") is False
                    and guarded_start_launch_window_contract.get("subprocess_module_imported") is False
                    and guarded_start_launch_window_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_pre_launch_guard_contract_available": (
                    guarded_start_pre_launch_guard_contract.get("schema_version") == GUARDED_START_PRE_LAUNCH_GUARD_CONTRACT_SCHEMA_VERSION
                    and guarded_start_pre_launch_guard_contract.get("guarded_start_pre_launch_guard_contract_implemented") is True
                    and guarded_start_pre_launch_guard_contract.get("pre_launch_guard_contract_only") is True
                    and guarded_start_pre_launch_guard_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_pre_launch_guard_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_pre_launch_guard_contract.get("start_execution_allowed") is False
                    and guarded_start_pre_launch_guard_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_pre_launch_guard_contract.get("process_launch_attempted") is False
                    and guarded_start_pre_launch_guard_contract.get("daemon_started") is False
                    and guarded_start_pre_launch_guard_contract.get("subprocess_module_imported") is False
                    and guarded_start_pre_launch_guard_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_executor_runtime_contract_available": (
                    guarded_start_executor_runtime_contract.get("schema_version") == GUARDED_START_EXECUTOR_RUNTIME_CONTRACT_SCHEMA_VERSION
                    and guarded_start_executor_runtime_contract.get("guarded_start_executor_runtime_contract_implemented") is True
                    and guarded_start_executor_runtime_contract.get("executor_runtime_contract_only") is True
                    and guarded_start_executor_runtime_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_executor_runtime_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_executor_runtime_contract.get("start_execution_allowed") is False
                    and guarded_start_executor_runtime_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_executor_runtime_contract.get("process_launch_attempted") is False
                    and guarded_start_executor_runtime_contract.get("daemon_started") is False
                    and guarded_start_executor_runtime_contract.get("subprocess_module_imported") is False
                    and guarded_start_executor_runtime_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_process_spawn_contract_available": (
                    guarded_start_process_spawn_contract.get("schema_version") == GUARDED_START_PROCESS_SPAWN_CONTRACT_SCHEMA_VERSION
                    and guarded_start_process_spawn_contract.get("guarded_start_process_spawn_contract_implemented") is True
                    and guarded_start_process_spawn_contract.get("process_spawn_contract_only") is True
                    and guarded_start_process_spawn_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_process_spawn_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_process_spawn_contract.get("start_execution_allowed") is False
                    and guarded_start_process_spawn_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_process_spawn_contract.get("process_launch_attempted") is False
                    and guarded_start_process_spawn_contract.get("daemon_started") is False
                    and guarded_start_process_spawn_contract.get("subprocess_module_imported") is False
                    and guarded_start_process_spawn_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_spawn_review_contract_available": (
                    guarded_start_spawn_review_contract.get("schema_version") == GUARDED_START_SPAWN_REVIEW_CONTRACT_SCHEMA_VERSION
                    and guarded_start_spawn_review_contract.get("guarded_start_spawn_review_contract_implemented") is True
                    and guarded_start_spawn_review_contract.get("spawn_review_contract_only") is True
                    and guarded_start_spawn_review_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_spawn_review_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_spawn_review_contract.get("start_execution_allowed") is False
                    and guarded_start_spawn_review_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_spawn_review_contract.get("process_launch_attempted") is False
                    and guarded_start_spawn_review_contract.get("daemon_started") is False
                    and guarded_start_spawn_review_contract.get("subprocess_module_imported") is False
                    and guarded_start_spawn_review_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_subprocess_import_contract_available": (
                    guarded_start_subprocess_import_contract.get("schema_version") == GUARDED_START_SUBPROCESS_IMPORT_CONTRACT_SCHEMA_VERSION
                    and guarded_start_subprocess_import_contract.get("guarded_start_subprocess_import_contract_implemented") is True
                    and guarded_start_subprocess_import_contract.get("subprocess_import_contract_only") is True
                    and guarded_start_subprocess_import_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_subprocess_import_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_subprocess_import_contract.get("start_execution_allowed") is False
                    and guarded_start_subprocess_import_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_subprocess_import_contract.get("process_launch_attempted") is False
                    and guarded_start_subprocess_import_contract.get("daemon_started") is False
                    and guarded_start_subprocess_import_contract.get("subprocess_module_imported") is False
                    and guarded_start_subprocess_import_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_launch_invocation_contract_available": (
                    guarded_start_launch_invocation_contract.get("schema_version") == GUARDED_START_LAUNCH_INVOCATION_CONTRACT_SCHEMA_VERSION
                    and guarded_start_launch_invocation_contract.get("guarded_start_launch_invocation_contract_implemented") is True
                    and guarded_start_launch_invocation_contract.get("launch_invocation_contract_only") is True
                    and guarded_start_launch_invocation_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_launch_invocation_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_launch_invocation_contract.get("start_execution_allowed") is False
                    and guarded_start_launch_invocation_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_launch_invocation_contract.get("process_launch_attempted") is False
                    and guarded_start_launch_invocation_contract.get("daemon_started") is False
                    and guarded_start_launch_invocation_contract.get("subprocess_module_imported") is False
                    and guarded_start_launch_invocation_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_final_process_start_contract_available": (
                    guarded_start_final_process_start_contract.get("schema_version") == GUARDED_START_FINAL_PROCESS_START_CONTRACT_SCHEMA_VERSION
                    and guarded_start_final_process_start_contract.get("guarded_start_final_process_start_contract_implemented") is True
                    and guarded_start_final_process_start_contract.get("final_process_start_contract_only") is True
                    and guarded_start_final_process_start_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_final_process_start_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_final_process_start_contract.get("start_execution_allowed") is False
                    and guarded_start_final_process_start_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_final_process_start_contract.get("process_launch_attempted") is False
                    and guarded_start_final_process_start_contract.get("daemon_started") is False
                    and guarded_start_final_process_start_contract.get("subprocess_module_imported") is False
                    and guarded_start_final_process_start_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_process_execution_review_available": (
                    guarded_start_process_execution_review.get("schema_version") == GUARDED_START_PROCESS_EXECUTION_REVIEW_SCHEMA_VERSION
                    and guarded_start_process_execution_review.get("guarded_start_process_execution_review_implemented") is True
                    and guarded_start_process_execution_review.get("process_execution_review_only") is True
                    and guarded_start_process_execution_review.get("guarded_start_executor_enabled") is False
                    and guarded_start_process_execution_review.get("guarded_start_executor_implemented") is False
                    and guarded_start_process_execution_review.get("start_execution_allowed") is False
                    and guarded_start_process_execution_review.get("real_subprocess_start_implemented") is False
                    and guarded_start_process_execution_review.get("process_launch_attempted") is False
                    and guarded_start_process_execution_review.get("daemon_started") is False
                    and guarded_start_process_execution_review.get("subprocess_module_imported") is False
                    and guarded_start_process_execution_review.get("livekit_sdk_imported") is False
                ),
                "guarded_start_process_execution_packet_available": (
                    guarded_start_process_execution_packet.get("schema_version") == GUARDED_START_PROCESS_EXECUTION_PACKET_SCHEMA_VERSION
                    and guarded_start_process_execution_packet.get("guarded_start_process_execution_packet_implemented") is True
                    and guarded_start_process_execution_packet.get("process_execution_packet_only") is True
                    and guarded_start_process_execution_packet.get("guarded_start_executor_enabled") is False
                    and guarded_start_process_execution_packet.get("guarded_start_executor_implemented") is False
                    and guarded_start_process_execution_packet.get("start_execution_allowed") is False
                    and guarded_start_process_execution_packet.get("real_subprocess_start_implemented") is False
                    and guarded_start_process_execution_packet.get("process_launch_attempted") is False
                    and guarded_start_process_execution_packet.get("daemon_started") is False
                    and guarded_start_process_execution_packet.get("subprocess_module_imported") is False
                    and guarded_start_process_execution_packet.get("livekit_sdk_imported") is False
                ),
                "guarded_start_process_executor_stub_available": (
                    guarded_start_process_executor_stub.get("schema_version") == GUARDED_START_PROCESS_EXECUTOR_STUB_SCHEMA_VERSION
                    and guarded_start_process_executor_stub.get("guarded_start_process_executor_stub_implemented") is True
                    and guarded_start_process_executor_stub.get("process_executor_stub_only") is True
                    and guarded_start_process_executor_stub.get("guarded_start_executor_enabled") is False
                    and guarded_start_process_executor_stub.get("guarded_start_executor_implemented") is False
                    and guarded_start_process_executor_stub.get("start_execution_allowed") is False
                    and guarded_start_process_executor_stub.get("real_subprocess_start_implemented") is False
                    and guarded_start_process_executor_stub.get("process_launch_attempted") is False
                    and guarded_start_process_executor_stub.get("daemon_started") is False
                    and guarded_start_process_executor_stub.get("subprocess_module_imported") is False
                    and guarded_start_process_executor_stub.get("livekit_sdk_imported") is False
                ),
                "guarded_start_process_executor_review_available": (
                    guarded_start_process_executor_review.get("schema_version") == GUARDED_START_PROCESS_EXECUTOR_REVIEW_SCHEMA_VERSION
                    and guarded_start_process_executor_review.get("guarded_start_process_executor_review_implemented") is True
                    and guarded_start_process_executor_review.get("process_executor_review_only") is True
                    and guarded_start_process_executor_review.get("guarded_start_executor_enabled") is False
                    and guarded_start_process_executor_review.get("guarded_start_executor_implemented") is False
                    and guarded_start_process_executor_review.get("start_execution_allowed") is False
                    and guarded_start_process_executor_review.get("real_subprocess_start_implemented") is False
                    and guarded_start_process_executor_review.get("process_launch_attempted") is False
                    and guarded_start_process_executor_review.get("daemon_started") is False
                    and guarded_start_process_executor_review.get("subprocess_module_imported") is False
                    and guarded_start_process_executor_review.get("livekit_sdk_imported") is False
                ),
                "guarded_start_process_executor_contract_available": (
                    guarded_start_process_executor_contract.get("schema_version") == GUARDED_START_PROCESS_EXECUTOR_CONTRACT_SCHEMA_VERSION
                    and guarded_start_process_executor_contract.get("guarded_start_process_executor_contract_implemented") is True
                    and guarded_start_process_executor_contract.get("process_executor_contract_only") is True
                    and guarded_start_process_executor_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_process_executor_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_process_executor_contract.get("start_execution_allowed") is False
                    and guarded_start_process_executor_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_process_executor_contract.get("process_launch_attempted") is False
                    and guarded_start_process_executor_contract.get("daemon_started") is False
                    and guarded_start_process_executor_contract.get("subprocess_module_imported") is False
                    and guarded_start_process_executor_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_process_runtime_adapter_available": (
                    guarded_start_process_runtime_adapter.get("schema_version") == GUARDED_START_PROCESS_RUNTIME_ADAPTER_SCHEMA_VERSION
                    and guarded_start_process_runtime_adapter.get("guarded_start_process_runtime_adapter_implemented") is True
                    and guarded_start_process_runtime_adapter.get("process_runtime_adapter_only") is True
                    and guarded_start_process_runtime_adapter.get("runtime_adapter_contract_only") is True
                    and guarded_start_process_runtime_adapter.get("guarded_start_executor_enabled") is False
                    and guarded_start_process_runtime_adapter.get("guarded_start_executor_implemented") is False
                    and guarded_start_process_runtime_adapter.get("start_execution_allowed") is False
                    and guarded_start_process_runtime_adapter.get("real_subprocess_start_implemented") is False
                    and guarded_start_process_runtime_adapter.get("process_launch_attempted") is False
                    and guarded_start_process_runtime_adapter.get("daemon_started") is False
                    and guarded_start_process_runtime_adapter.get("subprocess_module_imported") is False
                    and guarded_start_process_runtime_adapter.get("livekit_sdk_imported") is False
                ),
                "guarded_start_process_adapter_review_available": (
                    guarded_start_process_adapter_review.get("schema_version") == GUARDED_START_PROCESS_ADAPTER_REVIEW_SCHEMA_VERSION
                    and guarded_start_process_adapter_review.get("guarded_start_process_adapter_review_implemented") is True
                    and guarded_start_process_adapter_review.get("process_adapter_review_only") is True
                    and guarded_start_process_adapter_review.get("guarded_start_executor_enabled") is False
                    and guarded_start_process_adapter_review.get("guarded_start_executor_implemented") is False
                    and guarded_start_process_adapter_review.get("start_execution_allowed") is False
                    and guarded_start_process_adapter_review.get("real_subprocess_start_implemented") is False
                    and guarded_start_process_adapter_review.get("process_launch_attempted") is False
                    and guarded_start_process_adapter_review.get("daemon_started") is False
                    and guarded_start_process_adapter_review.get("subprocess_module_imported") is False
                    and guarded_start_process_adapter_review.get("livekit_sdk_imported") is False
                ),
                "guarded_start_process_adapter_contract_available": (
                    guarded_start_process_adapter_contract.get("schema_version") == GUARDED_START_PROCESS_ADAPTER_CONTRACT_SCHEMA_VERSION
                    and guarded_start_process_adapter_contract.get("guarded_start_process_adapter_contract_implemented") is True
                    and guarded_start_process_adapter_contract.get("process_adapter_contract_only") is True
                    and guarded_start_process_adapter_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_process_adapter_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_process_adapter_contract.get("start_execution_allowed") is False
                    and guarded_start_process_adapter_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_process_adapter_contract.get("process_launch_attempted") is False
                    and guarded_start_process_adapter_contract.get("daemon_started") is False
                    and guarded_start_process_adapter_contract.get("subprocess_module_imported") is False
                    and guarded_start_process_adapter_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_process_runner_contract_available": (
                    guarded_start_process_runner_contract.get("schema_version") == GUARDED_START_PROCESS_RUNNER_CONTRACT_SCHEMA_VERSION
                    and guarded_start_process_runner_contract.get("guarded_start_process_runner_contract_implemented") is True
                    and guarded_start_process_runner_contract.get("process_runner_contract_only") is True
                    and guarded_start_process_runner_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_process_runner_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_process_runner_contract.get("start_execution_allowed") is False
                    and guarded_start_process_runner_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_process_runner_contract.get("process_launch_attempted") is False
                    and guarded_start_process_runner_contract.get("daemon_started") is False
                    and guarded_start_process_runner_contract.get("subprocess_module_imported") is False
                    and guarded_start_process_runner_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_process_runner_review_available": (
                    guarded_start_process_runner_review.get("schema_version") == GUARDED_START_PROCESS_RUNNER_REVIEW_SCHEMA_VERSION
                    and guarded_start_process_runner_review.get("guarded_start_process_runner_review_implemented") is True
                    and guarded_start_process_runner_review.get("process_runner_review_only") is True
                    and guarded_start_process_runner_review.get("guarded_start_executor_enabled") is False
                    and guarded_start_process_runner_review.get("guarded_start_executor_implemented") is False
                    and guarded_start_process_runner_review.get("start_execution_allowed") is False
                    and guarded_start_process_runner_review.get("real_subprocess_start_implemented") is False
                    and guarded_start_process_runner_review.get("process_launch_attempted") is False
                    and guarded_start_process_runner_review.get("daemon_started") is False
                    and guarded_start_process_runner_review.get("subprocess_module_imported") is False
                    and guarded_start_process_runner_review.get("livekit_sdk_imported") is False
                ),
                "guarded_start_process_runner_packet_available": (
                    guarded_start_process_runner_packet.get("schema_version") == GUARDED_START_PROCESS_RUNNER_PACKET_SCHEMA_VERSION
                    and guarded_start_process_runner_packet.get("guarded_start_process_runner_packet_implemented") is True
                    and guarded_start_process_runner_packet.get("process_runner_packet_only") is True
                    and guarded_start_process_runner_packet.get("guarded_start_executor_enabled") is False
                    and guarded_start_process_runner_packet.get("guarded_start_executor_implemented") is False
                    and guarded_start_process_runner_packet.get("start_execution_allowed") is False
                    and guarded_start_process_runner_packet.get("real_subprocess_start_implemented") is False
                    and guarded_start_process_runner_packet.get("process_launch_attempted") is False
                    and guarded_start_process_runner_packet.get("daemon_started") is False
                    and guarded_start_process_runner_packet.get("subprocess_module_imported") is False
                    and guarded_start_process_runner_packet.get("livekit_sdk_imported") is False
                ),
                "guarded_start_process_runner_execution_review_available": (
                    guarded_start_process_runner_execution_review.get("schema_version") == GUARDED_START_PROCESS_RUNNER_EXECUTION_REVIEW_SCHEMA_VERSION
                    and guarded_start_process_runner_execution_review.get("guarded_start_process_runner_execution_review_implemented") is True
                    and guarded_start_process_runner_execution_review.get("process_runner_execution_review_only") is True
                    and guarded_start_process_runner_execution_review.get("guarded_start_executor_enabled") is False
                    and guarded_start_process_runner_execution_review.get("guarded_start_executor_implemented") is False
                    and guarded_start_process_runner_execution_review.get("start_execution_allowed") is False
                    and guarded_start_process_runner_execution_review.get("real_subprocess_start_implemented") is False
                    and guarded_start_process_runner_execution_review.get("process_launch_attempted") is False
                    and guarded_start_process_runner_execution_review.get("daemon_started") is False
                    and guarded_start_process_runner_execution_review.get("subprocess_module_imported") is False
                    and guarded_start_process_runner_execution_review.get("livekit_sdk_imported") is False
                ),
                "guarded_start_process_runner_execution_contract_available": (
                    guarded_start_process_runner_execution_contract.get("schema_version") == GUARDED_START_PROCESS_RUNNER_EXECUTION_CONTRACT_SCHEMA_VERSION
                    and guarded_start_process_runner_execution_contract.get("guarded_start_process_runner_execution_contract_implemented") is True
                    and guarded_start_process_runner_execution_contract.get("process_runner_execution_contract_only") is True
                    and guarded_start_process_runner_execution_contract.get("guarded_start_executor_enabled") is False
                    and guarded_start_process_runner_execution_contract.get("guarded_start_executor_implemented") is False
                    and guarded_start_process_runner_execution_contract.get("start_execution_allowed") is False
                    and guarded_start_process_runner_execution_contract.get("real_subprocess_start_implemented") is False
                    and guarded_start_process_runner_execution_contract.get("process_launch_attempted") is False
                    and guarded_start_process_runner_execution_contract.get("daemon_started") is False
                    and guarded_start_process_runner_execution_contract.get("subprocess_module_imported") is False
                    and guarded_start_process_runner_execution_contract.get("livekit_sdk_imported") is False
                ),
                "guarded_start_process_runner_start_gate_available": (
                    guarded_start_process_runner_start_gate.get("schema_version") == GUARDED_START_PROCESS_RUNNER_START_GATE_SCHEMA_VERSION
                    and guarded_start_process_runner_start_gate.get("guarded_start_process_runner_start_gate_implemented") is True
                    and guarded_start_process_runner_start_gate.get("process_runner_start_gate_only") is True
                    and guarded_start_process_runner_start_gate.get("guarded_start_executor_enabled") is False
                    and guarded_start_process_runner_start_gate.get("guarded_start_executor_implemented") is False
                    and guarded_start_process_runner_start_gate.get("start_execution_allowed") is False
                    and guarded_start_process_runner_start_gate.get("real_subprocess_start_implemented") is False
                    and guarded_start_process_runner_start_gate.get("process_launch_attempted") is False
                    and guarded_start_process_runner_start_gate.get("daemon_started") is False
                    and guarded_start_process_runner_start_gate.get("subprocess_module_imported") is False
                    and guarded_start_process_runner_start_gate.get("livekit_sdk_imported") is False
                ),
                "guarded_start_process_runner_final_review_available": (
                    guarded_start_process_runner_final_review.get("schema_version") == GUARDED_START_PROCESS_RUNNER_FINAL_REVIEW_SCHEMA_VERSION
                    and guarded_start_process_runner_final_review.get("guarded_start_process_runner_final_review_implemented") is True
                    and guarded_start_process_runner_final_review.get("process_runner_final_review_only") is True
                    and guarded_start_process_runner_final_review.get("guarded_start_executor_enabled") is False
                    and guarded_start_process_runner_final_review.get("guarded_start_executor_implemented") is False
                    and guarded_start_process_runner_final_review.get("start_execution_allowed") is False
                    and guarded_start_process_runner_final_review.get("real_subprocess_start_implemented") is False
                    and guarded_start_process_runner_final_review.get("process_launch_attempted") is False
                    and guarded_start_process_runner_final_review.get("daemon_started") is False
                    and guarded_start_process_runner_final_review.get("subprocess_module_imported") is False
                    and guarded_start_process_runner_final_review.get("livekit_sdk_imported") is False
                ),
                "guarded_start_process_runner_promotion_packet_available": (
                    guarded_start_process_runner_promotion_packet.get("schema_version") == GUARDED_START_PROCESS_RUNNER_PROMOTION_PACKET_SCHEMA_VERSION
                    and guarded_start_process_runner_promotion_packet.get("guarded_start_process_runner_promotion_packet_implemented") is True
                    and guarded_start_process_runner_promotion_packet.get("process_runner_promotion_packet_only") is True
                    and guarded_start_process_runner_promotion_packet.get("guarded_start_executor_enabled") is False
                    and guarded_start_process_runner_promotion_packet.get("guarded_start_executor_implemented") is False
                    and guarded_start_process_runner_promotion_packet.get("start_execution_allowed") is False
                    and guarded_start_process_runner_promotion_packet.get("real_subprocess_start_implemented") is False
                    and guarded_start_process_runner_promotion_packet.get("process_launch_attempted") is False
                    and guarded_start_process_runner_promotion_packet.get("daemon_started") is False
                    and guarded_start_process_runner_promotion_packet.get("subprocess_module_imported") is False
                    and guarded_start_process_runner_promotion_packet.get("livekit_sdk_imported") is False
                ),
                "guarded_start_process_runner_operator_release_review_available": (
                    guarded_start_process_runner_operator_release_review.get("schema_version") == GUARDED_START_PROCESS_RUNNER_OPERATOR_RELEASE_REVIEW_SCHEMA_VERSION
                    and guarded_start_process_runner_operator_release_review.get("guarded_start_process_runner_operator_release_review_implemented") is True
                    and guarded_start_process_runner_operator_release_review.get("process_runner_operator_release_review_only") is True
                    and guarded_start_process_runner_operator_release_review.get("guarded_start_executor_enabled") is False
                    and guarded_start_process_runner_operator_release_review.get("guarded_start_executor_implemented") is False
                    and guarded_start_process_runner_operator_release_review.get("start_execution_allowed") is False
                    and guarded_start_process_runner_operator_release_review.get("real_subprocess_start_implemented") is False
                    and guarded_start_process_runner_operator_release_review.get("process_launch_attempted") is False
                    and guarded_start_process_runner_operator_release_review.get("daemon_started") is False
                    and guarded_start_process_runner_operator_release_review.get("subprocess_module_imported") is False
                    and guarded_start_process_runner_operator_release_review.get("livekit_sdk_imported") is False
                ),
                "guarded_start_process_runner_release_finalization_available": (
                    guarded_start_process_runner_release_finalization.get("schema_version") == GUARDED_START_PROCESS_RUNNER_RELEASE_FINALIZATION_SCHEMA_VERSION
                    and guarded_start_process_runner_release_finalization.get("guarded_start_process_runner_release_finalization_implemented") is True
                    and guarded_start_process_runner_release_finalization.get("process_runner_release_finalization_only") is True
                    and guarded_start_process_runner_release_finalization.get("guarded_start_executor_enabled") is False
                    and guarded_start_process_runner_release_finalization.get("guarded_start_executor_implemented") is False
                    and guarded_start_process_runner_release_finalization.get("start_execution_allowed") is False
                    and guarded_start_process_runner_release_finalization.get("real_subprocess_start_implemented") is False
                    and guarded_start_process_runner_release_finalization.get("process_launch_attempted") is False
                    and guarded_start_process_runner_release_finalization.get("daemon_started") is False
                    and guarded_start_process_runner_release_finalization.get("subprocess_module_imported") is False
                    and guarded_start_process_runner_release_finalization.get("livekit_sdk_imported") is False
                ),
                "guarded_start_process_runner_release_authorization_available": (
                    guarded_start_process_runner_release_authorization.get("schema_version") == GUARDED_START_PROCESS_RUNNER_RELEASE_AUTHORIZATION_CONTRACT_SCHEMA_VERSION
                    and guarded_start_process_runner_release_authorization.get("guarded_start_process_runner_release_authorization_implemented") is True
                    and guarded_start_process_runner_release_authorization.get("process_runner_release_authorization_only") is True
                    and guarded_start_process_runner_release_authorization.get("guarded_start_executor_enabled") is False
                    and guarded_start_process_runner_release_authorization.get("guarded_start_executor_implemented") is False
                    and guarded_start_process_runner_release_authorization.get("start_execution_allowed") is False
                    and guarded_start_process_runner_release_authorization.get("real_subprocess_start_implemented") is False
                    and guarded_start_process_runner_release_authorization.get("process_launch_attempted") is False
                    and guarded_start_process_runner_release_authorization.get("daemon_started") is False
                    and guarded_start_process_runner_release_authorization.get("subprocess_module_imported") is False
                    and guarded_start_process_runner_release_authorization.get("livekit_sdk_imported") is False
                ),
                "controlled_livekit_server_supervised_smoke_contract_available": (
                    controlled_livekit_server_supervised_smoke_contract.get("schema_version") == CONTROLLED_LIVEKIT_SERVER_SUPERVISED_SMOKE_CONTRACT_SCHEMA_VERSION
                    and controlled_livekit_server_supervised_smoke_contract.get("controlled_livekit_server_supervised_smoke_contract_implemented") is True
                    and controlled_livekit_server_supervised_smoke_contract.get("controlled_smoke_only") is True
                    and controlled_livekit_server_supervised_smoke_contract.get("guarded_start_executor_enabled") is False
                    and controlled_livekit_server_supervised_smoke_contract.get("guarded_start_executor_implemented") is False
                    and controlled_livekit_server_supervised_smoke_contract.get("start_execution_allowed") is False
                    and controlled_livekit_server_supervised_smoke_contract.get("real_subprocess_start_implemented") is False
                    and controlled_livekit_server_supervised_smoke_contract.get("process_launch_attempted") is False
                    and controlled_livekit_server_supervised_smoke_contract.get("daemon_started") is False
                    and controlled_livekit_server_supervised_smoke_contract.get("subprocess_module_imported") is False
                    and controlled_livekit_server_supervised_smoke_contract.get("livekit_sdk_imported") is False
                ),
                "provider_calls_forbidden": True,
                "tool_calls_forbidden": True,
            },
            "managed_environment_contract": managed_environment_contract,
            "launch_authorization_contract": launch_authorization_contract,
            "managed_env_writer": managed_env_writer,
            "supervised_launch_execution": supervised_launch_execution,
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
            "guarded_start_execution_attempt_contract": guarded_start_execution_attempt_contract,
            "guarded_start_execution_rehearsal_contract": guarded_start_execution_rehearsal_contract,
            "guarded_start_observability_contract": guarded_start_observability_contract,
            "guarded_start_release_candidate_contract": guarded_start_release_candidate_contract,
            "guarded_start_operator_acceptance_contract": guarded_start_operator_acceptance_contract,
            "guarded_start_final_start_receipt_contract": guarded_start_final_start_receipt_contract,
            "guarded_start_launch_window_contract": guarded_start_launch_window_contract,
            "guarded_start_pre_launch_guard_contract": guarded_start_pre_launch_guard_contract,
            "guarded_start_executor_runtime_contract": guarded_start_executor_runtime_contract,
            "guarded_start_process_spawn_contract": guarded_start_process_spawn_contract,
            "guarded_start_spawn_review_contract": guarded_start_spawn_review_contract,
            "guarded_start_subprocess_import_contract": guarded_start_subprocess_import_contract,
            "guarded_start_launch_invocation_contract": guarded_start_launch_invocation_contract,
            "guarded_start_final_process_start_contract": guarded_start_final_process_start_contract,
            "guarded_start_process_execution_review": guarded_start_process_execution_review,
            "guarded_start_process_execution_packet": guarded_start_process_execution_packet,
            "guarded_start_process_executor_stub": guarded_start_process_executor_stub,
            "guarded_start_process_executor_review": guarded_start_process_executor_review,
            "guarded_start_process_executor_contract": guarded_start_process_executor_contract,
            "guarded_start_process_runtime_adapter": guarded_start_process_runtime_adapter,
            "guarded_start_process_adapter_review": guarded_start_process_adapter_review,
            "guarded_start_process_adapter_contract": guarded_start_process_adapter_contract,
            "guarded_start_process_runner_contract": guarded_start_process_runner_contract,
            "guarded_start_process_runner_review": guarded_start_process_runner_review,
            "guarded_start_process_runner_packet": guarded_start_process_runner_packet,
            "guarded_start_process_runner_execution_review": guarded_start_process_runner_execution_review,
            "guarded_start_process_runner_execution_contract": guarded_start_process_runner_execution_contract,
            "guarded_start_process_runner_start_gate": guarded_start_process_runner_start_gate,
            "guarded_start_process_runner_final_review": guarded_start_process_runner_final_review,
            "guarded_start_process_runner_promotion_packet": guarded_start_process_runner_promotion_packet,
            "guarded_start_process_runner_operator_release_review": guarded_start_process_runner_operator_release_review,
            "guarded_start_process_runner_release_finalization": guarded_start_process_runner_release_finalization,
            "guarded_start_process_runner_release_authorization": guarded_start_process_runner_release_authorization,
            "controlled_livekit_server_supervised_smoke_contract": controlled_livekit_server_supervised_smoke_contract,
            "health_snapshot": self.capture_health_snapshot(
                supervisor_execution=supervisor_execution,
                lifecycle_state="preflight" if ready else "planned",
            ),
            "start_attempt": self.start_worker_process(supervisor_execution),
            "rollback_plan": self.rollback("inspection_only"),
            "evidence_events": [
                "VOICE_DAEMON_PROCESS_ADAPTER_INSPECTED",
                "VOICE_DAEMON_MANAGED_ENV_CONTRACT_DECLARED",
                "VOICE_DAEMON_LAUNCH_AUTHORIZATION_DECLARED",
                "VOICE_DAEMON_MANAGED_ENV_WRITER_EVALUATED",
                "VOICE_DAEMON_SUPERVISED_LAUNCH_EVALUATED",
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
                "VOICE_DAEMON_GUARDED_START_EXECUTION_ATTEMPT_CONTRACT_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_EXECUTION_REHEARSAL_CONTRACT_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_OBSERVABILITY_CONTRACT_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_RELEASE_CANDIDATE_CONTRACT_EVALUATED",
                "VOICE_DAEMON_START_BLOCKED",
                "VOICE_DAEMON_GUARDED_START_PROCESS_SPAWN_CONTRACT_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_SPAWN_REVIEW_CONTRACT_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_SUBPROCESS_IMPORT_CONTRACT_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_LAUNCH_INVOCATION_CONTRACT_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_FINAL_PROCESS_START_CONTRACT_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_PROCESS_EXECUTION_REVIEWED",
                "VOICE_DAEMON_CONTROLLED_LIVEKIT_SERVER_SMOKE_CONTRACT_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_PROCESS_EXECUTION_PACKET_ATTACHED",
                "VOICE_DAEMON_GUARDED_START_PROCESS_EXECUTOR_STUB_DECLARED",
                "VOICE_DAEMON_GUARDED_START_PROCESS_EXECUTOR_REVIEWED",
                "VOICE_DAEMON_GUARDED_START_PROCESS_EXECUTOR_CONTRACT_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_PROCESS_RUNTIME_ADAPTER_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_PROCESS_ADAPTER_REVIEWED",
                "VOICE_DAEMON_GUARDED_START_PROCESS_ADAPTER_CONTRACT_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_CONTRACT_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_REVIEWED",
                "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_PACKET_ATTACHED",
                "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_EXECUTION_REVIEWED",
                "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_EXECUTION_CONTRACT_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_START_GATE_EVALUATED",
                "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_FINAL_REVIEWED",
                "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_PROMOTION_PACKET_ATTACHED",
                "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_OPERATOR_RELEASE_REVIEWED",
                "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_RELEASE_FINALIZED",
                "VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_RELEASE_AUTHORIZED",
                "VOICE_DAEMON_CONTROLLED_LIVEKIT_SERVER_SMOKE_REQUIRED",
            ],
            "next_action": "prepare_controlled_livekit_server_supervised_smoke_contract" if ready else "fix_supervised_process_adapter_prerequisites",
        })

    def authorize_launch(
        self,
        *,
        supervisor_execution: Mapping[str, Any],
        managed_environment_contract: Mapping[str, Any],
    ) -> Mapping[str, Any]:
        supervisor_ready = supervisor_execution.get("status") == "ready_for_process_adapter_implementation"
        managed_env_ready = (
            managed_environment_contract.get("schema_version") == MANAGED_ENV_CONTRACT_SCHEMA_VERSION
            and managed_environment_contract.get("env_file_write_attempted") is False
            and managed_environment_contract.get("secret_values_present_in_output") is False
        )
        implementation_ready = supervisor_ready and managed_env_ready

        return {
            "schema_version": LAUNCH_AUTHORIZATION_CONTRACT_SCHEMA_VERSION,
            "status": "authorized_for_implementation_not_launch" if implementation_ready else "blocked",
            "authorization_mode": "implementation_contract_only",
            "launch_allowed": False,
            "process_launch_attempted": False,
            "daemon_started": False,
            "decision_receipt_required": True,
            "managed_env_file_writer_required": True,
            "managed_env_file_writer_implemented": False,
            "managed_env_writer_contract_required": True,
            "subprocess_launch_implemented": False,
            "required_receipts": [
                "production_promotion_review_receipt",
                "daemon_implementation_review_receipt",
                "launch_execution_decision_receipt",
            ],
            "required_pre_start_checks": [
                "kernel_health",
                "livekit_room_connectivity",
                "callback_router_roundtrip",
                "kernel_event_normalizer_roundtrip",
                "turn_receipt_roundtrip",
            ],
            "gates": {
                "supervisor_execution_ready": supervisor_ready,
                "managed_environment_contract_ready": managed_env_ready,
                "env_file_not_written_by_contract": managed_environment_contract.get("env_file_write_attempted") is False,
                "secret_values_redacted": managed_environment_contract.get("secret_values_present_in_output") is False,
                "process_launch_disabled": True,
            },
            "forbidden_shortcuts": [
                "start_process_from_authorization_contract",
                "write_env_file_from_authorization_contract",
                "skip_managed_env_writer",
                "skip_launch_execution_decision_receipt",
                "call_provider_from_launch_authorization",
            ],
            "next_action": "implement_managed_env_writer_then_subprocess_supervisor" if implementation_ready else "fix_launch_authorization_prerequisites",
        }

    def prepare_environment(self, supervisor_execution: Mapping[str, Any]) -> Mapping[str, Any]:
        blueprint = supervisor_execution.get("process_adapter_blueprint", {})
        required_refs = []
        if isinstance(blueprint, Mapping):
            required_refs = list(blueprint.get("required_env_keys", []))

        secret_refs = [
            key for key in required_refs
            if any(marker in key for marker in ["TOKEN", "SECRET", "PRIVATE", "CREDENTIAL"])
        ]
        public_refs = [
            key for key in required_refs
            if key not in secret_refs
        ]

        return {
            "schema_version": MANAGED_ENV_CONTRACT_SCHEMA_VERSION,
            "status": "prepared_contract_only",
            "env_value_logging_allowed": False,
            "env_file_write_attempted": False,
            "env_file_written": False,
            "required_env_refs": required_refs,
            "required_public_env_refs": public_refs,
            "required_secret_env_refs": secret_refs,
            "env_manifest_template": {
                key: _env_placeholder(key) for key in required_refs
            },
            "secret_values_present_in_output": False,
            "managed_env_file_required": True,
            "managed_env_file_writer_implemented": False,
            "managed_env_file_path": "<managed-env-file>",
            "forbidden_shortcuts": [
                "write_env_file_in_contract_shell",
                "log_env_values",
                "inline_livekit_secret",
                "inline_atlas_token",
                "reuse_stale_env_file",
            ],
        }

    def start_worker_process(self, supervisor_execution: Mapping[str, Any]) -> Mapping[str, Any]:
        return {
            "status": "blocked_launch_not_implemented",
            "reason": "supervised_subprocess_launch_requires_separate_authorized_implementation",
            "process_launch_attempted": False,
            "daemon_started": False,
            "launch_allowed": False,
            "decision_receipt_required": True,
            "supervisor_execution_schema_version": supervisor_execution.get("schema_version"),
            "forbidden_shortcuts": [
                "import_subprocess_in_contract_shell",
                "start_process_without_decision_receipt",
                "start_process_without_health_checks",
                "log_env_values",
                "call_provider_from_adapter",
            ],
        }

    def stop_worker_process(self) -> Mapping[str, Any]:
        return {
            "status": "noop_not_started",
            "process_stop_attempted": False,
            "daemon_started": False,
            "graceful_timeout_seconds": 10,
            "kill_after_timeout_allowed": False,
        }

    def rollback(self, reason: str) -> Mapping[str, Any]:
        return {
            "status": "planned",
            "reason": reason,
            "actions": [
                "disable_livekit_worker_launch",
                "stop_livekit_worker_if_started",
                "revoke_livekit_session_leases",
                "revert_runtime_policy",
                "emit_voice_daemon_rollback_event",
            ],
            "automatic_execution_allowed": False,
        }

    def capture_health_snapshot(
        self,
        *,
        supervisor_execution: Mapping[str, Any],
        lifecycle_state: str,
    ) -> Mapping[str, Any]:
        ready = supervisor_execution.get("status") == "ready_for_process_adapter_implementation"

        return {
            "schema_version": "atlas.voice_realtime.supervised_process_adapter_health.v1",
            "status": "ready_fail_closed" if ready else "blocked",
            "lifecycle_state": lifecycle_state,
            "process_state": "not_started",
            "daemon_started": False,
            "process_launch_attempted": False,
            "checks": [
                self.verify_kernel_health(),
                self.verify_livekit_room_connectivity(),
                self.verify_callback_router_roundtrip(),
                self.verify_kernel_event_normalizer_roundtrip(),
                self.verify_turn_receipt_roundtrip(),
            ],
        }

    def verify_kernel_health(self) -> Mapping[str, Any]:
        return _planned_check("kernel_health")

    def verify_livekit_room_connectivity(self) -> Mapping[str, Any]:
        return _planned_check("livekit_room_connectivity")

    def verify_callback_router_roundtrip(self) -> Mapping[str, Any]:
        return _planned_check("callback_router_roundtrip")

    def verify_kernel_event_normalizer_roundtrip(self) -> Mapping[str, Any]:
        return _planned_check("kernel_event_normalizer_roundtrip")

    def verify_turn_receipt_roundtrip(self) -> Mapping[str, Any]:
        return _planned_check("turn_receipt_roundtrip")


def inspect_supervised_process_adapter(supervisor_execution: Mapping[str, Any]) -> Mapping[str, Any]:
    return AtlasVoiceSupervisedProcessAdapter().inspect(supervisor_execution)


def _planned_check(name: str) -> Mapping[str, Any]:
    return {
        "check": name,
        "status": "planned_contract_only",
        "passed": False,
        "starts_process": False,
    }


def _env_placeholder(key: str) -> str:
    if any(marker in key for marker in ["TOKEN", "SECRET", "PRIVATE", "CREDENTIAL"]):
        return f"<secret-ref:{key}>"

    return f"<env-ref:{key}>"
