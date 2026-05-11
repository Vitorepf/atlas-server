from __future__ import annotations

from typing import Any, Mapping

from .daemon_supervisor_packet import validate_daemon_supervisor_packet
from .supervised_process_adapter import inspect_supervised_process_adapter


SCHEMA_VERSION = "atlas.voice_realtime.daemon_supervisor_execution.v1"
PROCESS_ADAPTER_BLUEPRINT_SCHEMA_VERSION = "atlas.voice_realtime.daemon_process_adapter_blueprint.v1"
SUPERVISED_PROCESS_ADAPTER_SCHEMA_VERSION = "atlas.voice_realtime.supervised_process_adapter.v1"


class AtlasVoiceDaemonSupervisor:
    """Fail-closed supervisor boundary for the future LiveKit daemon.

    The supervisor is the single place where a real worker process may be
    launched later. In the current AP-687 state it only evaluates the reviewed
    preflight and returns an execution contract; no subprocess is started here.
    """

    def evaluate(self, worker_start: Mapping[str, Any]) -> Mapping[str, Any]:
        supervised_start_plan = worker_start.get("supervised_start_plan")
        supervisor_preflight = (
            supervised_start_plan.get("supervisor_preflight")
            if isinstance(supervised_start_plan, Mapping)
            else None
        )
        preflight_ready = (
            isinstance(supervisor_preflight, Mapping)
            and supervisor_preflight.get("status") == "ready_for_supervisor_execution_implementation"
            and supervisor_preflight.get("process_launch_attempted") is False
            and supervisor_preflight.get("daemon_started") is False
        )
        receipts_ready = (
            worker_start.get("production_promotion_review_valid") is True
            and worker_start.get("daemon_implementation_review_valid") is True
        )
        supervisor_contract = (
            supervised_start_plan.get("supervisor_contract")
            if isinstance(supervised_start_plan, Mapping)
            else {}
        )
        required_methods = (
            supervisor_preflight.get("required_supervisor_methods", [])
            if isinstance(supervisor_preflight, Mapping)
            else []
        )
        launch_adapter_ready = False
        ready_for_future_adapter = preflight_ready and receipts_ready and worker_start.get("status") == "blocked_unimplemented_start"
        process_adapter_blueprint = _process_adapter_blueprint(ready_for_future_adapter=ready_for_future_adapter)

        payload = {
            "schema_version": SCHEMA_VERSION,
            "status": "ready_for_process_adapter_implementation" if ready_for_future_adapter else "blocked",
            "surface_id": "voice_realtime",
            "runtime_id": "livekit_agents_sdk",
            "kernel_only": True,
            "mobile_first": True,
            "execution_mode": "supervised_contract_only",
            "process_launch_attempted": False,
            "daemon_started": False,
            "process_adapter_implemented": launch_adapter_ready,
            "process_adapter_blueprint": process_adapter_blueprint,
            "start_allowed": False,
            "current_lifecycle_state": "preflight" if ready_for_future_adapter else "planned",
            "next_lifecycle_state": "starting" if ready_for_future_adapter else "preflight",
            "preflight_ready": preflight_ready,
            "receipts_ready": receipts_ready,
            "gates": {
                "worker_status_ready_for_supervisor": worker_start.get("status") == "blocked_unimplemented_start",
                "production_promotion_review_valid": worker_start.get("production_promotion_review_valid") is True,
                "daemon_implementation_review_valid": worker_start.get("daemon_implementation_review_valid") is True,
                "supervisor_preflight_ready": preflight_ready,
                "process_adapter_blueprint_available": process_adapter_blueprint.get("available") is True,
                "process_adapter_implemented": launch_adapter_ready,
                "process_launch_disabled": True,
            },
            "implemented_safe_methods": [
                "prepare_environment",
                "verify_kernel_health",
                "verify_livekit_room_connectivity",
                "verify_callback_router_roundtrip",
                "verify_kernel_event_normalizer_roundtrip",
                "verify_turn_receipt_roundtrip",
                "capture_health_snapshot",
                "rollback",
            ],
            "missing_process_methods": [
                method
                for method in required_methods
                if method in ["start_worker_process", "stop_worker_process"]
            ],
            "health_checks": [
                {
                    "check": check,
                    "status": "supervisor_preflight_ready" if preflight_ready else "blocked",
                    "starts_process": False,
                }
                for check in supervisor_contract.get("required_health_checks", [])
            ] if isinstance(supervisor_contract, Mapping) else [],
            "evidence_events": [
                "VOICE_DAEMON_SUPERVISOR_EVALUATED",
                "VOICE_DAEMON_PROCESS_ADAPTER_BLUEPRINTED",
                "VOICE_DAEMON_START_BLOCKED",
            ],
            "guardrails": {
                "direct_provider_call_allowed": False,
                "direct_tool_execution_allowed": False,
                "raw_audio_persistence_allowed": False,
                "access_token_log_allowed": False,
                "start_without_decision_receipt_allowed": False,
                "start_without_supervisor_allowed": False,
                "process_launch_allowed_by_this_contract": False,
            },
            "next_action": "implement_reviewed_process_adapter" if ready_for_future_adapter else "fix_supervisor_execution_prerequisites",
        }
        payload["supervised_process_adapter"] = inspect_supervised_process_adapter(payload)

        return validate_daemon_supervisor_packet(payload)


def evaluate_daemon_supervisor(worker_start: Mapping[str, Any]) -> Mapping[str, Any]:
    return AtlasVoiceDaemonSupervisor().evaluate(worker_start)


def _process_adapter_blueprint(*, ready_for_future_adapter: bool) -> Mapping[str, Any]:
    return {
        "schema_version": PROCESS_ADAPTER_BLUEPRINT_SCHEMA_VERSION,
        "available": True,
        "status": "ready_for_reviewed_adapter_runtime" if ready_for_future_adapter else "blocked_by_supervisor_prerequisites",
        "adapter_id": "livekit_agents_supervised_process_adapter",
        "launch_strategy": "supervised_subprocess",
        "launch_allowed": False,
        "process_launch_attempted": False,
        "daemon_started": False,
        "argv_template": [
            "python3",
            "-m",
            "atlas_voice_agent.main",
            "--env-file",
            "<managed-env-file>",
            "--callback-loop-wired",
            "--production-sdk-loop-wired",
            "--start-worker",
        ],
        "required_env_keys": [
            "ATLAS_BASE_URL",
            "ATLAS_TOKEN",
            "ATLAS_VOICE_BOOTSTRAP",
            "LIVEKIT_URL",
            "LIVEKIT_PUBLIC_CREDENTIAL_REF",
            "LIVEKIT_PRIVATE_CREDENTIAL_REF",
        ],
        "secret_safe_output_policy": {
            "log_argv": True,
            "log_env_values": False,
            "log_access_tokens": False,
            "log_livekit_secret": False,
            "log_raw_audio": False,
        },
        "supervision_requirements": [
            "decision_receipt_hash_bound_to_launch",
            "production_promotion_review_receipt_valid",
            "daemon_implementation_review_receipt_valid",
            "kernel_health_verified_before_start",
            "livekit_room_connectivity_verified_before_start",
            "callback_router_roundtrip_verified_before_start",
            "kernel_event_normalizer_roundtrip_verified_before_start",
            "turn_receipt_roundtrip_verified_before_start",
            "health_snapshot_after_start",
            "rollback_on_failed_start",
        ],
        "forbidden_shortcuts": [
            "shell_out_without_supervisor",
            "start_without_decision_receipt",
            "reuse_stale_env_file",
            "log_env_file_contents",
            "persist_raw_audio",
            "call_provider_from_process_adapter",
        ],
        "next_action": "implement_process_adapter_runtime_with_tests" if ready_for_future_adapter else "satisfy_supervisor_prerequisites",
    }
