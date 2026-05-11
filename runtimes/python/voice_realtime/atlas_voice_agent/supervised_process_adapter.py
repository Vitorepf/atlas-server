from __future__ import annotations

from typing import Any, Mapping

from .managed_env_writer import (
    SCHEMA_VERSION as MANAGED_ENV_WRITER_SCHEMA_VERSION,
    inspect_managed_env_writer,
)
from .supervised_process_adapter_packet import validate_supervised_process_adapter_packet
from .supervised_launch_execution import (
    REAL_START_ADAPTER_DISABLED_SCHEMA_VERSION,
    REAL_START_ADAPTER_REVIEW_CONTRACT_SCHEMA_VERSION,
    REAL_START_ENABLEMENT_GATE_SCHEMA_VERSION,
    REVIEWED_REAL_START_EXECUTION_CONTRACT_SCHEMA_VERSION,
    REVIEWED_SUBPROCESS_START_EXECUTION_SCHEMA_VERSION,
    RUNTIME_POLICY_ENABLEMENT_REVIEW_SCHEMA_VERSION,
    SCHEMA_VERSION as SUPERVISED_LAUNCH_EXECUTION_SCHEMA_VERSION,
    inspect_real_start_adapter_enablement_gate,
    inspect_real_start_adapter_disabled_by_default,
    inspect_real_start_adapter_review_contract,
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
                "VOICE_DAEMON_START_BLOCKED",
            ],
            "next_action": "implement_subprocess_start_contract_prerequisites" if ready else "fix_supervised_process_adapter_prerequisites",
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
