from __future__ import annotations

from pathlib import Path
from tempfile import TemporaryDirectory
from typing import Any, Mapping

from .daemon_supervisor import evaluate_daemon_supervisor
from .managed_env_writer import execute_managed_env_write
from .supervised_launch_execution import (
    LAUNCH_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
    PRE_START_HEALTH_CHECKS_AUTHORIZATION_SCHEMA_VERSION,
    SUBPROCESS_START_AUTHORIZATION_SCHEMA_VERSION,
    execute_pre_start_health_checks,
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

        payload = {
            "schema_version": SCHEMA_VERSION,
            "status": _status(pre_start_health_checks, subprocess_start_contract),
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
            "gates": {
                "managed_env_placeholder_written": env_write.get("env_file_written") is True,
                "managed_env_target_redacted": True,
                "supervised_launch_ready": launch_execution.get("status") == "ready_for_subprocess_implementation",
                "pre_start_health_checks_passed": pre_start_health_checks.get("status") == "passed_no_process_start",
                "subprocess_start_contract_ready": subprocess_start_contract.get("status") == "ready_for_reviewed_subprocess_start_implementation",
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
) -> str:
    if (
        pre_start_health_checks.get("status") == "passed_no_process_start"
        and subprocess_start_contract.get("status") == "ready_for_reviewed_subprocess_start_implementation"
    ):
        return "passed_no_process_start"

    return "blocked"
