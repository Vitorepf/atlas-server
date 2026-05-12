from __future__ import annotations

from pathlib import Path
from typing import Any, Mapping

from .contract import AtlasVoiceRuntimeContract
from .livekit_callback_loop import inspect_callback_loop_contract
from .livekit_runtime_entrypoint import start_livekit_agents_worker
from .livekit_production_loop import build_production_loop_plan
from .daemon_supervisor import evaluate_daemon_supervisor
from .product_loop_packet import validate_product_loop_check


SCHEMA_VERSION = "atlas.voice_realtime.product_loop_check.v1"


def build_product_loop_check(
    contract: AtlasVoiceRuntimeContract,
    *,
    env_file: Path | None = None,
    env: Mapping[str, str] | None = None,
    settings_loaded: bool = False,
    boundary_created: bool = False,
    mock_kernel: bool = False,
    callback_loop_wired: bool = False,
    production_sdk_loop_wired: bool = False,
    production_promotion_review: Mapping[str, Any] | None = None,
    production_promotion_review_bundle: Mapping[str, Any] | None = None,
    daemon_implementation_review: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Aggregate the governed product-loop readiness without starting a daemon."""

    callback_loop = inspect_callback_loop_contract(production_sdk_loop_wired=production_sdk_loop_wired)
    production_loop = build_production_loop_plan(
        contract,
        settings_loaded=settings_loaded,
        boundary_created=boundary_created,
        production_sdk_loop_wired=production_sdk_loop_wired,
    )
    worker_start = start_livekit_agents_worker(
        contract,
        env_file=env_file,
        env=env,
        settings_loaded=settings_loaded,
        boundary_created=boundary_created,
        mock_kernel=mock_kernel,
        callback_loop_wired=callback_loop_wired,
        production_sdk_loop_wired=production_sdk_loop_wired,
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
        production_loop.get("sdk_wiring_contract", {}).get("complete_handler_registry") is True
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
    reviewed_subprocess_start_execution_available = (
        subprocess_start_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_subprocess_start_execution", {}).get("schema_version")
        == "atlas.voice_realtime.reviewed_subprocess_start_execution.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_subprocess_start_execution", {}).get("reviewed_subprocess_start_execution_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_subprocess_start_execution", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_subprocess_start_execution", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_subprocess_start_execution", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_subprocess_start_execution", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_subprocess_start_execution", {}).get("livekit_sdk_imported") is False
    )
    real_start_adapter_disabled_available = (
        reviewed_subprocess_start_execution_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_adapter_disabled", {}).get("schema_version")
        == "atlas.voice_realtime.real_start_adapter_disabled.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_adapter_disabled", {}).get("real_start_adapter_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_adapter_disabled", {}).get("real_start_adapter_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_adapter_disabled", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_adapter_disabled", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_adapter_disabled", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_adapter_disabled", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_adapter_disabled", {}).get("livekit_sdk_imported") is False
    )
    real_start_enablement_gate_available = (
        real_start_adapter_disabled_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_enablement_gate", {}).get("schema_version")
        == "atlas.voice_realtime.real_start_enablement_gate.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_enablement_gate", {}).get("real_start_enablement_gate_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_enablement_gate", {}).get("real_start_adapter_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_enablement_gate", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_enablement_gate", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_enablement_gate", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_enablement_gate", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_enablement_gate", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_enablement_gate", {}).get("livekit_sdk_imported") is False
    )
    runtime_policy_enablement_review_available = (
        real_start_enablement_gate_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("runtime_policy_enablement_review", {}).get("schema_version")
        == "atlas.voice_realtime.runtime_policy_enablement_review.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("runtime_policy_enablement_review", {}).get("runtime_policy_enablement_review_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("runtime_policy_enablement_review", {}).get("runtime_policy_start_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("runtime_policy_enablement_review", {}).get("real_start_adapter_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("runtime_policy_enablement_review", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("runtime_policy_enablement_review", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("runtime_policy_enablement_review", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("runtime_policy_enablement_review", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("runtime_policy_enablement_review", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("runtime_policy_enablement_review", {}).get("livekit_sdk_imported") is False
    )
    real_start_adapter_review_contract_available = (
        runtime_policy_enablement_review_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_adapter_review_contract", {}).get("schema_version")
        == "atlas.voice_realtime.real_start_adapter_review_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_adapter_review_contract", {}).get("real_start_adapter_review_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_adapter_review_contract", {}).get("runtime_policy_start_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_adapter_review_contract", {}).get("real_start_adapter_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_adapter_review_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_adapter_review_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_adapter_review_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_adapter_review_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_adapter_review_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_adapter_review_contract", {}).get("livekit_sdk_imported") is False
    )
    reviewed_real_start_execution_contract_available = (
        real_start_adapter_review_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_real_start_execution_contract", {}).get("schema_version")
        == "atlas.voice_realtime.reviewed_real_start_execution_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_real_start_execution_contract", {}).get("reviewed_real_start_execution_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_real_start_execution_contract", {}).get("runtime_policy_start_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_real_start_execution_contract", {}).get("real_start_adapter_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_real_start_execution_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_real_start_execution_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_real_start_execution_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_real_start_execution_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_real_start_execution_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_real_start_execution_contract", {}).get("livekit_sdk_imported") is False
    )
    final_start_executor_disabled_available = (
        reviewed_real_start_execution_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("final_start_executor_disabled", {}).get("schema_version")
        == "atlas.voice_realtime.final_start_executor_disabled.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("final_start_executor_disabled", {}).get("final_start_executor_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("final_start_executor_disabled", {}).get("final_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("final_start_executor_disabled", {}).get("runtime_policy_start_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("final_start_executor_disabled", {}).get("real_start_adapter_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("final_start_executor_disabled", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("final_start_executor_disabled", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("final_start_executor_disabled", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("final_start_executor_disabled", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("final_start_executor_disabled", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("final_start_executor_disabled", {}).get("livekit_sdk_imported") is False
    )
    final_start_executor_enablement_gate_available = (
        final_start_executor_disabled_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("final_start_executor_enablement_gate", {}).get("schema_version")
        == "atlas.voice_realtime.final_start_executor_enablement_gate.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("final_start_executor_enablement_gate", {}).get("final_start_executor_enablement_gate_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("final_start_executor_enablement_gate", {}).get("final_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("final_start_executor_enablement_gate", {}).get("runtime_policy_start_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("final_start_executor_enablement_gate", {}).get("real_start_adapter_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("final_start_executor_enablement_gate", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("final_start_executor_enablement_gate", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("final_start_executor_enablement_gate", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("final_start_executor_enablement_gate", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("final_start_executor_enablement_gate", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("final_start_executor_enablement_gate", {}).get("livekit_sdk_imported") is False
    )
    supervised_start_execution_review_available = (
        final_start_executor_enablement_gate_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("supervised_start_execution_review", {}).get("schema_version")
        == "atlas.voice_realtime.supervised_start_execution_review.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("supervised_start_execution_review", {}).get("supervised_start_execution_review_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("supervised_start_execution_review", {}).get("final_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("supervised_start_execution_review", {}).get("runtime_policy_start_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("supervised_start_execution_review", {}).get("real_start_adapter_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("supervised_start_execution_review", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("supervised_start_execution_review", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("supervised_start_execution_review", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("supervised_start_execution_review", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("supervised_start_execution_review", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("supervised_start_execution_review", {}).get("livekit_sdk_imported") is False
    )
    real_start_execution_contract_available = (
        supervised_start_execution_review_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_execution_contract", {}).get("schema_version")
        == "atlas.voice_realtime.real_start_execution_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_execution_contract", {}).get("real_start_execution_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_execution_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_execution_contract", {}).get("final_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_execution_contract", {}).get("runtime_policy_start_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_execution_contract", {}).get("real_start_adapter_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_execution_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_execution_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_execution_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_execution_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_execution_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("real_start_execution_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_executor_disabled_available = (
        real_start_execution_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_disabled", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_executor_disabled.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_disabled", {}).get("guarded_start_executor_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_disabled", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_disabled", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_disabled", {}).get("final_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_disabled", {}).get("runtime_policy_start_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_disabled", {}).get("real_start_adapter_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_disabled", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_disabled", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_disabled", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_disabled", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_disabled", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_disabled", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_executor_enablement_gate_available = (
        guarded_start_executor_disabled_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_enablement_gate", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_executor_enablement_gate.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_enablement_gate", {}).get("guarded_start_executor_enablement_gate_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_enablement_gate", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_enablement_gate", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_enablement_gate", {}).get("final_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_enablement_gate", {}).get("runtime_policy_start_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_enablement_gate", {}).get("real_start_adapter_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_enablement_gate", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_enablement_gate", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_enablement_gate", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_enablement_gate", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_enablement_gate", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_enablement_gate", {}).get("livekit_sdk_imported") is False
    )
    reviewed_guarded_start_execution_contract_available = (
        guarded_start_executor_enablement_gate_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_guarded_start_execution_contract", {}).get("schema_version")
        == "atlas.voice_realtime.reviewed_guarded_start_execution_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_guarded_start_execution_contract", {}).get("reviewed_guarded_start_execution_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_guarded_start_execution_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_guarded_start_execution_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_guarded_start_execution_contract", {}).get("final_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_guarded_start_execution_contract", {}).get("runtime_policy_start_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_guarded_start_execution_contract", {}).get("real_start_adapter_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_guarded_start_execution_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_guarded_start_execution_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_guarded_start_execution_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_guarded_start_execution_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_guarded_start_execution_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("reviewed_guarded_start_execution_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_dry_run_contract_available = (
        reviewed_guarded_start_execution_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_dry_run_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_dry_run_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_dry_run_contract", {}).get("guarded_start_dry_run_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_dry_run_contract", {}).get("dry_run_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_dry_run_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_dry_run_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_dry_run_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_dry_run_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_dry_run_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_dry_run_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_dry_run_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_dry_run_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_simulation_contract_available = (
        guarded_start_dry_run_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_simulation_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_simulation_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_simulation_contract", {}).get("guarded_start_simulation_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_simulation_contract", {}).get("simulation_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_simulation_contract", {}).get("dry_run_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_simulation_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_simulation_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_simulation_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_simulation_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_simulation_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_simulation_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_simulation_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_simulation_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_runtime_handoff_contract_available = (
        guarded_start_simulation_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_runtime_handoff_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_runtime_handoff_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_runtime_handoff_contract", {}).get("guarded_start_runtime_handoff_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_runtime_handoff_contract", {}).get("runtime_handoff_contract_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_runtime_handoff_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_runtime_handoff_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_runtime_handoff_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_runtime_handoff_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_runtime_handoff_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_runtime_handoff_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_runtime_handoff_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_runtime_handoff_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_policy_patch_review_contract_available = (
        guarded_start_runtime_handoff_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_policy_patch_review_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_policy_patch_review_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_policy_patch_review_contract", {}).get("guarded_start_policy_patch_review_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_policy_patch_review_contract", {}).get("policy_patch_review_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_policy_patch_review_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_policy_patch_review_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_policy_patch_review_contract", {}).get("runtime_policy_start_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_policy_patch_review_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_policy_patch_review_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_policy_patch_review_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_policy_patch_review_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_policy_patch_review_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_policy_patch_review_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_human_review_contract_available = (
        guarded_start_policy_patch_review_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_human_review_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_human_review_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_human_review_contract", {}).get("guarded_start_human_review_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_human_review_contract", {}).get("human_review_contract_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_human_review_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_human_review_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_human_review_contract", {}).get("runtime_policy_start_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_human_review_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_human_review_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_human_review_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_human_review_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_human_review_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_human_review_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_final_enablement_gate_available = (
        guarded_start_human_review_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_enablement_gate", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_final_enablement_gate.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_enablement_gate", {}).get("guarded_start_final_enablement_gate_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_enablement_gate", {}).get("final_enablement_gate_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_enablement_gate", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_enablement_gate", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_enablement_gate", {}).get("runtime_policy_start_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_enablement_gate", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_enablement_gate", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_enablement_gate", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_enablement_gate", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_enablement_gate", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_enablement_gate", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_policy_enablement_contract_available = (
        guarded_start_final_enablement_gate_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_policy_enablement_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_policy_enablement_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_policy_enablement_contract", {}).get("guarded_start_policy_enablement_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_policy_enablement_contract", {}).get("policy_enablement_contract_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_policy_enablement_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_policy_enablement_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_policy_enablement_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_policy_enablement_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_policy_enablement_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_policy_enablement_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_policy_enablement_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_policy_enablement_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_activation_contract_available = (
        guarded_start_policy_enablement_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_activation_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_activation_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_activation_contract", {}).get("guarded_start_activation_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_activation_contract", {}).get("activation_contract_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_activation_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_activation_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_activation_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_activation_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_activation_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_activation_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_activation_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_activation_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_execution_attempt_contract_available = (
        guarded_start_activation_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_execution_attempt_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_execution_attempt_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_execution_attempt_contract", {}).get("guarded_start_execution_attempt_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_execution_attempt_contract", {}).get("execution_attempt_contract_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_execution_attempt_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_execution_attempt_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_execution_attempt_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_execution_attempt_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_execution_attempt_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_execution_attempt_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_execution_attempt_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_execution_attempt_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_execution_rehearsal_contract_available = (
        guarded_start_execution_attempt_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_execution_rehearsal_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_execution_rehearsal_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_execution_rehearsal_contract", {}).get("guarded_start_execution_rehearsal_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_execution_rehearsal_contract", {}).get("execution_rehearsal_contract_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_execution_rehearsal_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_execution_rehearsal_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_execution_rehearsal_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_execution_rehearsal_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_execution_rehearsal_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_execution_rehearsal_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_execution_rehearsal_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_execution_rehearsal_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_observability_contract_available = (
        guarded_start_execution_rehearsal_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_observability_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_observability_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_observability_contract", {}).get("guarded_start_observability_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_observability_contract", {}).get("observability_contract_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_observability_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_observability_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_observability_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_observability_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_observability_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_observability_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_observability_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_observability_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_release_candidate_contract_available = (
        guarded_start_observability_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_release_candidate_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_release_candidate_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_release_candidate_contract", {}).get("guarded_start_release_candidate_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_release_candidate_contract", {}).get("release_candidate_contract_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_release_candidate_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_release_candidate_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_release_candidate_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_release_candidate_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_release_candidate_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_release_candidate_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_release_candidate_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_release_candidate_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_operator_acceptance_contract_available = (
        guarded_start_release_candidate_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_operator_acceptance_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_operator_acceptance_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_operator_acceptance_contract", {}).get("guarded_start_operator_acceptance_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_operator_acceptance_contract", {}).get("operator_acceptance_contract_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_operator_acceptance_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_operator_acceptance_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_operator_acceptance_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_operator_acceptance_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_operator_acceptance_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_operator_acceptance_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_operator_acceptance_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_operator_acceptance_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_final_start_receipt_contract_available = (
        guarded_start_operator_acceptance_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_start_receipt_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_final_start_receipt_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_start_receipt_contract", {}).get("guarded_start_final_start_receipt_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_start_receipt_contract", {}).get("final_start_receipt_contract_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_start_receipt_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_start_receipt_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_start_receipt_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_start_receipt_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_start_receipt_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_start_receipt_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_start_receipt_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_start_receipt_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_launch_window_contract_available = (
        guarded_start_final_start_receipt_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_launch_window_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_launch_window_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_launch_window_contract", {}).get("guarded_start_launch_window_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_launch_window_contract", {}).get("launch_window_contract_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_launch_window_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_launch_window_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_launch_window_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_launch_window_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_launch_window_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_launch_window_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_launch_window_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_launch_window_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_pre_launch_guard_contract_available = (
        guarded_start_launch_window_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_pre_launch_guard_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_pre_launch_guard_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_pre_launch_guard_contract", {}).get("guarded_start_pre_launch_guard_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_pre_launch_guard_contract", {}).get("pre_launch_guard_contract_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_pre_launch_guard_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_pre_launch_guard_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_pre_launch_guard_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_pre_launch_guard_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_pre_launch_guard_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_pre_launch_guard_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_pre_launch_guard_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_pre_launch_guard_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_executor_runtime_contract_available = (
        guarded_start_pre_launch_guard_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_runtime_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_executor_runtime_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_runtime_contract", {}).get("guarded_start_executor_runtime_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_runtime_contract", {}).get("executor_runtime_contract_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_runtime_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_runtime_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_runtime_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_runtime_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_runtime_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_runtime_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_runtime_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_executor_runtime_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_process_spawn_contract_available = (
        guarded_start_executor_runtime_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_spawn_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_process_spawn_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_spawn_contract", {}).get("guarded_start_process_spawn_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_spawn_contract", {}).get("process_spawn_contract_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_spawn_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_spawn_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_spawn_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_spawn_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_spawn_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_spawn_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_spawn_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_spawn_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_spawn_review_contract_available = (
        guarded_start_process_spawn_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_spawn_review_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_spawn_review_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_spawn_review_contract", {}).get("guarded_start_spawn_review_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_spawn_review_contract", {}).get("spawn_review_contract_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_spawn_review_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_spawn_review_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_spawn_review_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_spawn_review_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_spawn_review_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_spawn_review_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_spawn_review_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_spawn_review_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_subprocess_import_contract_available = (
        guarded_start_spawn_review_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_subprocess_import_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_subprocess_import_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_subprocess_import_contract", {}).get("guarded_start_subprocess_import_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_subprocess_import_contract", {}).get("subprocess_import_contract_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_subprocess_import_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_subprocess_import_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_subprocess_import_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_subprocess_import_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_subprocess_import_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_subprocess_import_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_subprocess_import_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_subprocess_import_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_launch_invocation_contract_available = (
        guarded_start_subprocess_import_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_launch_invocation_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_launch_invocation_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_launch_invocation_contract", {}).get("guarded_start_launch_invocation_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_launch_invocation_contract", {}).get("launch_invocation_contract_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_launch_invocation_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_launch_invocation_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_launch_invocation_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_launch_invocation_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_launch_invocation_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_launch_invocation_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_launch_invocation_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_launch_invocation_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_final_process_start_contract_available = (
        guarded_start_launch_invocation_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_process_start_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_final_process_start_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_process_start_contract", {}).get("guarded_start_final_process_start_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_process_start_contract", {}).get("final_process_start_contract_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_process_start_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_process_start_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_process_start_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_process_start_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_process_start_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_process_start_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_process_start_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_final_process_start_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_process_execution_review_available = (
        guarded_start_final_process_start_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_execution_review", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_process_execution_review.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_execution_review", {}).get("guarded_start_process_execution_review_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_execution_review", {}).get("process_execution_review_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_execution_review", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_execution_review", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_execution_review", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_execution_review", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_execution_review", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_execution_review", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_execution_review", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_execution_review", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_process_execution_packet_available = (
        guarded_start_process_execution_review_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_execution_packet", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_process_execution_packet.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_execution_packet", {}).get("guarded_start_process_execution_packet_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_execution_packet", {}).get("process_execution_packet_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_execution_packet", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_execution_packet", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_execution_packet", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_execution_packet", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_execution_packet", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_execution_packet", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_execution_packet", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_execution_packet", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_process_executor_stub_available = (
        guarded_start_process_execution_packet_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_stub", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_process_executor_stub.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_stub", {}).get("guarded_start_process_executor_stub_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_stub", {}).get("process_executor_stub_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_stub", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_stub", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_stub", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_stub", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_stub", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_stub", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_stub", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_stub", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_process_executor_review_available = (
        guarded_start_process_executor_stub_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_review", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_process_executor_review.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_review", {}).get("guarded_start_process_executor_review_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_review", {}).get("process_executor_review_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_review", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_review", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_review", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_review", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_review", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_review", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_review", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_review", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_process_executor_contract_available = (
        guarded_start_process_executor_review_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_process_executor_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_contract", {}).get("guarded_start_process_executor_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_contract", {}).get("process_executor_contract_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_executor_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_process_runtime_adapter_available = (
        guarded_start_process_executor_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runtime_adapter", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_process_runtime_adapter.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runtime_adapter", {}).get("guarded_start_process_runtime_adapter_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runtime_adapter", {}).get("process_runtime_adapter_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runtime_adapter", {}).get("runtime_adapter_contract_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runtime_adapter", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runtime_adapter", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runtime_adapter", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runtime_adapter", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runtime_adapter", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runtime_adapter", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runtime_adapter", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runtime_adapter", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_process_adapter_review_available = (
        guarded_start_process_runtime_adapter_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_adapter_review", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_process_adapter_review.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_adapter_review", {}).get("guarded_start_process_adapter_review_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_adapter_review", {}).get("process_adapter_review_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_adapter_review", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_adapter_review", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_adapter_review", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_adapter_review", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_adapter_review", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_adapter_review", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_adapter_review", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_adapter_review", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_process_adapter_contract_available = (
        guarded_start_process_adapter_review_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_adapter_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_process_adapter_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_adapter_contract", {}).get("guarded_start_process_adapter_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_adapter_contract", {}).get("process_adapter_contract_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_adapter_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_adapter_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_adapter_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_adapter_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_adapter_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_adapter_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_adapter_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_adapter_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_process_runner_contract_available = (
        guarded_start_process_adapter_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_process_runner_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_contract", {}).get("guarded_start_process_runner_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_contract", {}).get("process_runner_contract_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_process_runner_review_available = (
        guarded_start_process_runner_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_review", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_process_runner_review.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_review", {}).get("guarded_start_process_runner_review_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_review", {}).get("process_runner_review_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_review", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_review", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_review", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_review", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_review", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_review", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_review", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_review", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_process_runner_packet_available = (
        guarded_start_process_runner_review_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_packet", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_process_runner_packet.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_packet", {}).get("guarded_start_process_runner_packet_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_packet", {}).get("process_runner_packet_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_packet", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_packet", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_packet", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_packet", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_packet", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_packet", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_packet", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_packet", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_process_runner_execution_review_available = (
        guarded_start_process_runner_packet_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_execution_review", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_process_runner_execution_review.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_execution_review", {}).get("guarded_start_process_runner_execution_review_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_execution_review", {}).get("process_runner_execution_review_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_execution_review", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_execution_review", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_execution_review", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_execution_review", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_execution_review", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_execution_review", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_execution_review", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_execution_review", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_process_runner_execution_contract_available = (
        guarded_start_process_runner_execution_review_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_execution_contract", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_process_runner_execution_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_execution_contract", {}).get("guarded_start_process_runner_execution_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_execution_contract", {}).get("process_runner_execution_contract_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_execution_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_execution_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_execution_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_execution_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_execution_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_execution_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_execution_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_execution_contract", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_process_runner_start_gate_available = (
        guarded_start_process_runner_execution_contract_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_start_gate", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_process_runner_start_gate.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_start_gate", {}).get("guarded_start_process_runner_start_gate_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_start_gate", {}).get("process_runner_start_gate_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_start_gate", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_start_gate", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_start_gate", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_start_gate", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_start_gate", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_start_gate", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_start_gate", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_start_gate", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_process_runner_final_review_available = (
        guarded_start_process_runner_start_gate_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_final_review", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_process_runner_final_review.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_final_review", {}).get("guarded_start_process_runner_final_review_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_final_review", {}).get("process_runner_final_review_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_final_review", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_final_review", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_final_review", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_final_review", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_final_review", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_final_review", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_final_review", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_final_review", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_process_runner_promotion_packet_available = (
        guarded_start_process_runner_final_review_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_promotion_packet", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_process_runner_promotion_packet.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_promotion_packet", {}).get("guarded_start_process_runner_promotion_packet_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_promotion_packet", {}).get("process_runner_promotion_packet_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_promotion_packet", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_promotion_packet", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_promotion_packet", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_promotion_packet", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_promotion_packet", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_promotion_packet", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_promotion_packet", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_promotion_packet", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_process_runner_operator_release_review_available = (
        guarded_start_process_runner_promotion_packet_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_operator_release_review", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_process_runner_operator_release_review.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_operator_release_review", {}).get("guarded_start_process_runner_operator_release_review_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_operator_release_review", {}).get("process_runner_operator_release_review_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_operator_release_review", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_operator_release_review", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_operator_release_review", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_operator_release_review", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_operator_release_review", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_operator_release_review", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_operator_release_review", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_operator_release_review", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_process_runner_release_finalization_available = (
        guarded_start_process_runner_operator_release_review_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_release_finalization", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_process_runner_release_finalization.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_release_finalization", {}).get("guarded_start_process_runner_release_finalization_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_release_finalization", {}).get("process_runner_release_finalization_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_release_finalization", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_release_finalization", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_release_finalization", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_release_finalization", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_release_finalization", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_release_finalization", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_release_finalization", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_release_finalization", {}).get("livekit_sdk_imported") is False
    )
    guarded_start_process_runner_release_authorization_available = (
        guarded_start_process_runner_release_finalization_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_release_authorization", {}).get("schema_version")
        == "atlas.voice_realtime.guarded_start_process_runner_release_authorization_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_release_authorization", {}).get("guarded_start_process_runner_release_authorization_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_release_authorization", {}).get("process_runner_release_authorization_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_release_authorization", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_release_authorization", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_release_authorization", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_release_authorization", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_release_authorization", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_release_authorization", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_release_authorization", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("guarded_start_process_runner_release_authorization", {}).get("livekit_sdk_imported") is False
    )
    controlled_livekit_server_supervised_smoke_contract_available = (
        guarded_start_process_runner_release_authorization_available
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("controlled_livekit_server_supervised_smoke_contract", {}).get("schema_version")
        == "atlas.voice_realtime.controlled_livekit_server_supervised_smoke_contract.v1"
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("controlled_livekit_server_supervised_smoke_contract", {}).get("controlled_livekit_server_supervised_smoke_contract_implemented") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("controlled_livekit_server_supervised_smoke_contract", {}).get("controlled_smoke_only") is True
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("controlled_livekit_server_supervised_smoke_contract", {}).get("guarded_start_executor_enabled") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("controlled_livekit_server_supervised_smoke_contract", {}).get("guarded_start_executor_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("controlled_livekit_server_supervised_smoke_contract", {}).get("start_execution_allowed") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("controlled_livekit_server_supervised_smoke_contract", {}).get("real_subprocess_start_implemented") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("controlled_livekit_server_supervised_smoke_contract", {}).get("process_launch_attempted") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("controlled_livekit_server_supervised_smoke_contract", {}).get("daemon_started") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("controlled_livekit_server_supervised_smoke_contract", {}).get("subprocess_module_imported") is False
        and daemon_supervisor_execution.get("supervised_process_adapter", {}).get("controlled_livekit_server_supervised_smoke_contract", {}).get("livekit_sdk_imported") is False
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
        and reviewed_subprocess_start_execution_available
        and real_start_adapter_disabled_available
        and real_start_enablement_gate_available
        and runtime_policy_enablement_review_available
        and real_start_adapter_review_contract_available
        and reviewed_real_start_execution_contract_available
        and final_start_executor_disabled_available
        and final_start_executor_enablement_gate_available
        and supervised_start_execution_review_available
        and real_start_execution_contract_available
        and guarded_start_executor_disabled_available
        and guarded_start_executor_enablement_gate_available
        and reviewed_guarded_start_execution_contract_available
        and guarded_start_dry_run_contract_available
        and guarded_start_simulation_contract_available
        and guarded_start_runtime_handoff_contract_available
        and guarded_start_policy_patch_review_contract_available
        and guarded_start_human_review_contract_available
        and guarded_start_final_enablement_gate_available
        and guarded_start_policy_enablement_contract_available
        and guarded_start_activation_contract_available
        and guarded_start_execution_attempt_contract_available
        and guarded_start_execution_rehearsal_contract_available
        and guarded_start_observability_contract_available
        and guarded_start_release_candidate_contract_available
        and guarded_start_operator_acceptance_contract_available
        and guarded_start_final_start_receipt_contract_available
        and guarded_start_launch_window_contract_available
        and guarded_start_pre_launch_guard_contract_available
        and guarded_start_executor_runtime_contract_available
        and guarded_start_process_spawn_contract_available
        and guarded_start_spawn_review_contract_available
        and guarded_start_subprocess_import_contract_available
        and guarded_start_launch_invocation_contract_available
        and guarded_start_final_process_start_contract_available
        and guarded_start_process_execution_review_available
        and guarded_start_process_execution_packet_available
        and guarded_start_process_executor_stub_available
        and guarded_start_process_executor_review_available
        and guarded_start_process_executor_contract_available
        and guarded_start_process_runtime_adapter_available
        and guarded_start_process_adapter_review_available
        and guarded_start_process_adapter_contract_available
        and guarded_start_process_runner_contract_available
        and guarded_start_process_runner_review_available
        and guarded_start_process_runner_packet_available
        and guarded_start_process_runner_execution_review_available
        and guarded_start_process_runner_execution_contract_available
        and guarded_start_process_runner_start_gate_available
        and guarded_start_process_runner_final_review_available
        and guarded_start_process_runner_promotion_packet_available
        and guarded_start_process_runner_operator_release_review_available
        and guarded_start_process_runner_release_finalization_available
        and guarded_start_process_runner_release_authorization_available
        and controlled_livekit_server_supervised_smoke_contract_available
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

    payload = {
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
            "reviewed_subprocess_start_execution_available": reviewed_subprocess_start_execution_available,
            "real_start_adapter_disabled_available": real_start_adapter_disabled_available,
            "real_start_enablement_gate_available": real_start_enablement_gate_available,
            "runtime_policy_enablement_review_available": runtime_policy_enablement_review_available,
            "real_start_adapter_review_contract_available": real_start_adapter_review_contract_available,
            "reviewed_real_start_execution_contract_available": reviewed_real_start_execution_contract_available,
            "final_start_executor_disabled_available": final_start_executor_disabled_available,
            "final_start_executor_enablement_gate_available": final_start_executor_enablement_gate_available,
            "supervised_start_execution_review_available": supervised_start_execution_review_available,
            "real_start_execution_contract_available": real_start_execution_contract_available,
            "guarded_start_executor_disabled_available": guarded_start_executor_disabled_available,
            "guarded_start_executor_enablement_gate_available": guarded_start_executor_enablement_gate_available,
            "reviewed_guarded_start_execution_contract_available": reviewed_guarded_start_execution_contract_available,
            "guarded_start_dry_run_contract_available": guarded_start_dry_run_contract_available,
            "guarded_start_simulation_contract_available": guarded_start_simulation_contract_available,
            "guarded_start_runtime_handoff_contract_available": guarded_start_runtime_handoff_contract_available,
            "guarded_start_policy_patch_review_contract_available": guarded_start_policy_patch_review_contract_available,
            "guarded_start_human_review_contract_available": guarded_start_human_review_contract_available,
            "guarded_start_final_enablement_gate_available": guarded_start_final_enablement_gate_available,
            "guarded_start_policy_enablement_contract_available": guarded_start_policy_enablement_contract_available,
            "guarded_start_activation_contract_available": guarded_start_activation_contract_available,
            "guarded_start_execution_attempt_contract_available": guarded_start_execution_attempt_contract_available,
            "guarded_start_execution_rehearsal_contract_available": guarded_start_execution_rehearsal_contract_available,
            "guarded_start_observability_contract_available": guarded_start_observability_contract_available,
            "guarded_start_release_candidate_contract_available": guarded_start_release_candidate_contract_available,
            "guarded_start_operator_acceptance_contract_available": guarded_start_operator_acceptance_contract_available,
            "guarded_start_final_start_receipt_contract_available": guarded_start_final_start_receipt_contract_available,
            "guarded_start_launch_window_contract_available": guarded_start_launch_window_contract_available,
            "guarded_start_pre_launch_guard_contract_available": guarded_start_pre_launch_guard_contract_available,
            "guarded_start_executor_runtime_contract_available": guarded_start_executor_runtime_contract_available,
            "guarded_start_process_spawn_contract_available": guarded_start_process_spawn_contract_available,
            "guarded_start_spawn_review_contract_available": guarded_start_spawn_review_contract_available,
            "guarded_start_subprocess_import_contract_available": guarded_start_subprocess_import_contract_available,
            "guarded_start_launch_invocation_contract_available": guarded_start_launch_invocation_contract_available,
            "guarded_start_final_process_start_contract_available": guarded_start_final_process_start_contract_available,
            "guarded_start_process_execution_review_available": guarded_start_process_execution_review_available,
            "guarded_start_process_execution_packet_available": guarded_start_process_execution_packet_available,
            "guarded_start_process_executor_stub_available": guarded_start_process_executor_stub_available,
            "guarded_start_process_executor_review_available": guarded_start_process_executor_review_available,
            "guarded_start_process_executor_contract_available": guarded_start_process_executor_contract_available,
            "guarded_start_process_runtime_adapter_available": guarded_start_process_runtime_adapter_available,
            "guarded_start_process_adapter_review_available": guarded_start_process_adapter_review_available,
            "guarded_start_process_adapter_contract_available": guarded_start_process_adapter_contract_available,
            "guarded_start_process_runner_contract_available": guarded_start_process_runner_contract_available,
            "guarded_start_process_runner_review_available": guarded_start_process_runner_review_available,
            "guarded_start_process_runner_packet_available": guarded_start_process_runner_packet_available,
            "guarded_start_process_runner_execution_review_available": guarded_start_process_runner_execution_review_available,
            "guarded_start_process_runner_execution_contract_available": guarded_start_process_runner_execution_contract_available,
            "guarded_start_process_runner_start_gate_available": guarded_start_process_runner_start_gate_available,
            "guarded_start_process_runner_final_review_available": guarded_start_process_runner_final_review_available,
            "guarded_start_process_runner_promotion_packet_available": guarded_start_process_runner_promotion_packet_available,
            "guarded_start_process_runner_operator_release_review_available": guarded_start_process_runner_operator_release_review_available,
            "guarded_start_process_runner_release_finalization_available": guarded_start_process_runner_release_finalization_available,
            "guarded_start_process_runner_release_authorization_available": guarded_start_process_runner_release_authorization_available,
            "controlled_livekit_server_supervised_smoke_contract_available": controlled_livekit_server_supervised_smoke_contract_available,
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

    return validate_product_loop_check(payload)


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
