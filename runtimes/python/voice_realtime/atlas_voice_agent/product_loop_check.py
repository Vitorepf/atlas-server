from __future__ import annotations

from pathlib import Path
from typing import Any, Mapping

from .contract import AtlasVoiceRuntimeContract
from .livekit_callback_loop import inspect_callback_loop_contract
from .livekit_runtime_entrypoint import start_livekit_agents_worker
from .livekit_production_loop import build_production_loop_plan
from .daemon_supervisor import evaluate_daemon_supervisor


SCHEMA_VERSION = "atlas.voice_realtime.product_loop_check.v1"


def build_product_loop_check(
    contract: AtlasVoiceRuntimeContract,
    *,
    env_file: Path | None = None,
    env: Mapping[str, str] | None = None,
    settings_loaded: bool = False,
    boundary_created: bool = False,
    mock_kernel: bool = False,
    production_promotion_review: Mapping[str, Any] | None = None,
    production_promotion_review_bundle: Mapping[str, Any] | None = None,
    daemon_implementation_review: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Aggregate the governed product-loop readiness without starting a daemon."""

    callback_loop = inspect_callback_loop_contract(production_sdk_loop_wired=True)
    production_loop = build_production_loop_plan(
        contract,
        settings_loaded=settings_loaded,
        boundary_created=boundary_created,
        production_sdk_loop_wired=True,
    )
    worker_start = start_livekit_agents_worker(
        contract,
        env_file=env_file,
        env=env,
        settings_loaded=settings_loaded,
        boundary_created=boundary_created,
        mock_kernel=mock_kernel,
        callback_loop_wired=True,
        production_sdk_loop_wired=True,
        production_promotion_review=production_promotion_review,
        production_promotion_review_bundle=production_promotion_review_bundle,
        daemon_implementation_review=daemon_implementation_review,
    )

    sdk_status = worker_start.get("worker_plan", {}).get("sdk_status", {})
    sdk_probe_import_safe = (
        sdk_status.get("sdk_imported") is False
        and sdk_status.get("import_probe_only") is True
    )
    sdk_handler_blueprint_available = (
        str(production_loop.get("sdk_wiring_contract", {}).get("status") or "") == "wired"
        and production_loop.get("sdk_wiring_contract", {}).get("complete_handler_registry") is True
        and isinstance(production_loop.get("sdk_wiring_contract", {}).get("handler_registry_contract"), Mapping)
        and production_loop.get("sdk_wiring_contract", {}).get("wiring_invariants") != []
        and all(
            isinstance(handler, Mapping) and isinstance(handler.get("handler_blueprint"), Mapping)
            for handler in production_loop.get("sdk_wiring_contract", {}).get("required_handlers", [])
        )
    )
    sdk_kernel_normalizer_required = (
        production_loop.get("sdk_wiring_contract", {}).get("guardrails", {}).get("kernel_event_normalizer_required_for_real_loop") is True
        and production_loop.get("sdk_wiring_contract", {}).get("required_components", {}).get("kernel_event_normalizer") == "KernelRuntimeEventNormalizerGuard"
        and "validate_every_sdk_event_through_kernel_normalizer" in (production_loop.get("sdk_wiring_contract", {}).get("wiring_invariants") or [])
        and all(
            isinstance(handler, Mapping)
            and "KernelRuntimeEventNormalizerGuard.assert_event_valid" in (
                handler.get("handler_blueprint", {}).get("required_path") or []
            )
            for handler in production_loop.get("sdk_wiring_contract", {}).get("required_handlers", [])
        )
    )
    supervised_start_plan = worker_start.get("supervised_start_plan")
    daemon_supervisor_preflight_available = isinstance(
        supervised_start_plan, Mapping
    ) and isinstance(supervised_start_plan.get("supervisor_preflight"), Mapping)
    daemon_supervisor_execution = evaluate_daemon_supervisor(worker_start)
    daemon_supervisor_execution_available = (
        daemon_supervisor_execution.get("schema_version")
        == "atlas.voice_realtime.daemon_supervisor_execution.v1"
        and daemon_supervisor_execution.get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("daemon_started") is False
    )
    daemon_process_adapter_blueprint_available = (
        daemon_supervisor_execution_available
        and daemon_supervisor_execution.get("process_adapter_blueprint", {}).get("schema_version")
        == "atlas.voice_realtime.daemon_process_adapter_blueprint.v1"
        and daemon_supervisor_execution.get("process_adapter_blueprint", {}).get("launch_allowed") is False
        and daemon_supervisor_execution.get("process_adapter_blueprint", {}).get("process_launch_attempted") is False
    )
    supervised_process_adapter_available = (
        daemon_process_adapter_blueprint_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("schema_version")
        == "atlas.voice_realtime.supervised_process_adapter.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("launch_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("daemon_started") is False
    )
    managed_env_contract_available = (
        supervised_process_adapter_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("managed_environment_contract", {}).get("schema_version")
        == "atlas.voice_realtime.managed_env_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("managed_environment_contract", {}).get("env_file_write_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("managed_environment_contract", {}).get("secret_values_present_in_output") is False
    )
    launch_authorization_contract_available = (
        managed_env_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("launch_authorization_contract", {}).get("schema_version")
        == "atlas.voice_realtime.launch_authorization_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("launch_authorization_contract", {}).get("launch_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("launch_authorization_contract", {}).get("process_launch_attempted") is False
    )
    launch_authorization_contract_ready = (
        launch_authorization_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("launch_authorization_contract", {}).get("status")
        == "authorized_for_implementation_not_launch"
    )
    managed_env_writer_contract_available = (
        launch_authorization_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("managed_env_writer", {}).get("schema_version")
        == "atlas.voice_realtime.managed_env_writer.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("managed_env_writer", {}).get("writer_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("managed_env_writer", {}).get("write_execution_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("managed_env_writer", {}).get("env_file_write_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("managed_env_writer", {}).get("secret_values_present_in_output") is False
    )
    supervised_launch_execution_contract_available = (
        managed_env_writer_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("supervised_launch_execution", {}).get("schema_version")
        == "atlas.voice_realtime.supervised_launch_execution.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("supervised_launch_execution", {}).get("launch_execution_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("supervised_launch_execution", {}).get("pre_start_health_checks_execution_available") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("supervised_launch_execution", {}).get("pre_start_health_checks_executed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("supervised_launch_execution", {}).get("subprocess_launch_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("supervised_launch_execution", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("supervised_launch_execution", {}).get("daemon_started") is False
    )
    subprocess_start_contract_available = (
        supervised_launch_execution_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("subprocess_start_contract", {}).get("schema_version")
        == "atlas.voice_realtime.subprocess_start_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("subprocess_start_contract", {}).get("subprocess_start_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("subprocess_start_contract", {}).get("subprocess_launch_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("subprocess_start_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("subprocess_start_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("subprocess_start_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("subprocess_start_contract", {}).get("livekit_sdk_imported") is False
    )
    machine_ready = (
        worker_start.get("status") in [
            "blocked_pending_human_review",
            "blocked_pending_daemon_implementation_review",
            "blocked_unimplemented_start",
        ]
        and sdk_probe_import_safe
        and sdk_handler_blueprint_available
        and sdk_kernel_normalizer_required
        and daemon_supervisor_preflight_available
        and daemon_supervisor_execution_available
        and daemon_process_adapter_blueprint_available
        and supervised_process_adapter_available
        and managed_env_contract_available
        and launch_authorization_contract_available
        and managed_env_writer_contract_available
        and supervised_launch_execution_contract_available
        and subprocess_start_contract_available
    )
    review_receipt_valid = worker_start.get("production_promotion", {}).get("review_receipt_valid") is True
    daemon_review_receipt_valid = worker_start.get("daemon_implementation", {}).get("review_receipt_valid") is True
    review_check = worker_start.get("production_promotion", {}).get("review_check")
    review_expected_bundle = review_check.get("expected_bundle") if isinstance(review_check, Mapping) else None
    review_bound_to_expected_bundle = (
        review_receipt_valid
        and isinstance(review_expected_bundle, Mapping)
        and isinstance(review_expected_bundle.get("bundle_hash"), str)
    )

    return {
        "schema_version": SCHEMA_VERSION,
        "status": _status(machine_ready, review_receipt_valid, daemon_review_receipt_valid),
        "surface_id": "voice_realtime",
        "runtime_id": "livekit_agents_sdk",
        "kernel_only": True,
        "mobile_first": True,
        "daemon_started": False,
        "callback_loop": callback_loop,
        "production_loop_plan": production_loop,
        "worker_start": worker_start,
        "supervised_start_plan": supervised_start_plan,
        "daemon_supervisor_execution": daemon_supervisor_execution,
        "gates": {
            "callback_loop_wired": bool(callback_loop.get("worker_start_callback_loop_wired")),
            "production_sdk_loop_wired": bool(production_loop.get("production_sdk_loop_wired")),
            "worker_start_still_blocked": worker_start.get("started") is False,
            "production_promotion_blocked": worker_start.get("production_promotion", {}).get("auto_promotion_allowed") is False,
            "sdk_probe_import_safe": sdk_probe_import_safe,
            "sdk_handler_blueprint_available": sdk_handler_blueprint_available,
            "sdk_kernel_normalizer_required": sdk_kernel_normalizer_required,
            "production_review_receipt_valid": review_receipt_valid,
            "production_review_bound_to_expected_bundle": review_bound_to_expected_bundle,
            "production_review_expected_bundle_validated": review_bound_to_expected_bundle,
            "daemon_implementation_review_valid": daemon_review_receipt_valid,
            "supervised_start_plan_available": isinstance(supervised_start_plan, Mapping),
            "daemon_supervisor_contract_available": isinstance(supervised_start_plan, Mapping) and isinstance(supervised_start_plan.get("supervisor_contract"), Mapping),
            "daemon_supervisor_health_snapshot_available": isinstance(supervised_start_plan, Mapping) and isinstance(supervised_start_plan.get("supervisor_health_snapshot"), Mapping),
            "daemon_supervisor_preflight_available": daemon_supervisor_preflight_available,
            "daemon_supervisor_execution_available": daemon_supervisor_execution_available,
            "daemon_supervisor_process_launch_disabled": daemon_supervisor_execution.get("gates", {}).get("process_launch_disabled") is True,
            "daemon_process_adapter_blueprint_available": daemon_process_adapter_blueprint_available,
            "supervised_process_adapter_available": supervised_process_adapter_available,
            "managed_env_contract_available": managed_env_contract_available,
            "launch_authorization_contract_available": launch_authorization_contract_available,
            "launch_authorization_contract_ready": launch_authorization_contract_ready,
            "managed_env_writer_contract_available": managed_env_writer_contract_available,
            "supervised_launch_execution_contract_available": supervised_launch_execution_contract_available,
            "subprocess_start_contract_available": subprocess_start_contract_available,
            "boolean_approval_is_sufficient": worker_start.get("production_promotion", {}).get("boolean_approval_is_sufficient") is True,
            "direct_provider_forbidden": worker_start.get("guardrails", {}).get("direct_provider_call_allowed") is False,
            "raw_audio_forbidden": worker_start.get("guardrails", {}).get("raw_audio_persistence_allowed") is False,
        },
        "next_action": _next_action(
            worker_start,
            sdk_probe_import_safe,
            sdk_handler_blueprint_available,
            sdk_kernel_normalizer_required,
        ),
        "guardrails": {
            "direct_provider_call_allowed": False,
            "direct_tool_execution_allowed": False,
            "raw_audio_persistence_allowed": False,
            "access_token_log_allowed": False,
            "auto_promotion_allowed": False,
        },
}


def _status(machine_ready: bool, review_receipt_valid: bool, daemon_review_receipt_valid: bool) -> str:
    if not machine_ready:
        return "blocked"
    if not review_receipt_valid:
        return "ready_for_human_review"
    if not daemon_review_receipt_valid:
        return "ready_for_daemon_implementation_review"

    return "ready_for_supervised_start_implementation"


def _next_action(
    worker_start: Mapping[str, Any],
    sdk_probe_import_safe: bool,
    sdk_handler_blueprint_available: bool,
    sdk_kernel_normalizer_required: bool,
) -> str:
    if not sdk_probe_import_safe:
        return "fix_sdk_probe_contract"
    if not sdk_handler_blueprint_available:
        return "fix_sdk_handler_blueprint_contract"
    if not sdk_kernel_normalizer_required:
        return "fix_sdk_kernel_normalizer_contract"

    status = str(worker_start.get("status") or "")
    if status == "blocked_missing_sdk":
        worker_plan = worker_start.get("worker_plan")
        sdk_status = worker_plan.get("sdk_status") if isinstance(worker_plan, Mapping) else None
        if isinstance(sdk_status, Mapping):
            return str(sdk_status.get("next_action") or "install_livekit_agents_sdk")

        return "install_livekit_agents_sdk"
    if status == "blocked_missing_runtime_settings":
        return "load_runtime_settings_and_kernel_boundary"
    if status == "blocked_mock_kernel":
        return "use_real_kernel_not_mock"
    if status == "blocked_by_activation_gate":
        return str(worker_start.get("activation_next_action") or "fix_activation_gate")
    if status == "blocked_by_activation_contract":
        return str(worker_start.get("activation_next_action") or "fix_activation_contract")
    if status == "blocked_pending_human_review":
        return "submit_voice_production_promotion_for_human_review"
    if status == "blocked_pending_daemon_implementation_review":
        return "submit_daemon_implementation_review"
    if status == "blocked_unimplemented_start":
        return "implement_supervised_daemon_start"

    return "fix_voice_product_loop_gates"
