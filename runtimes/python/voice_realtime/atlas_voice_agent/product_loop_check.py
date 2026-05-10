from __future__ import annotations

from pathlib import Path
from typing import Any, Mapping

from .contract import AtlasVoiceRuntimeContract
from .livekit_callback_loop import inspect_callback_loop_contract
from .livekit_runtime_entrypoint import start_livekit_agents_worker
from .livekit_production_loop import build_production_loop_plan


def build_product_loop_check(
    contract: AtlasVoiceRuntimeContract,
    *,
    env_file: Path | None = None,
    env: Mapping[str, str] | None = None,
    settings_loaded: bool = False,
    boundary_created: bool = False,
    mock_kernel: bool = False,
    production_promotion_review: Mapping[str, Any] | None = None,
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
    machine_ready = (
        worker_start.get("status") in ["blocked_pending_human_review", "blocked_unimplemented_start"]
        and sdk_probe_import_safe
        and sdk_handler_blueprint_available
        and sdk_kernel_normalizer_required
    )
    review_receipt_valid = worker_start.get("production_promotion", {}).get("review_receipt_valid") is True

    return {
        "schema_version": "atlas.voice_realtime.product_loop_check.v1",
        "status": _status(machine_ready, review_receipt_valid),
        "surface_id": "voice_realtime",
        "runtime_id": "livekit_agents_sdk",
        "kernel_only": True,
        "mobile_first": True,
        "daemon_started": False,
        "callback_loop": callback_loop,
        "production_loop_plan": production_loop,
        "worker_start": worker_start,
        "gates": {
            "callback_loop_wired": bool(callback_loop.get("worker_start_callback_loop_wired")),
            "production_sdk_loop_wired": bool(production_loop.get("production_sdk_loop_wired")),
            "worker_start_still_blocked": worker_start.get("started") is False,
            "production_promotion_blocked": worker_start.get("production_promotion", {}).get("auto_promotion_allowed") is False,
            "sdk_probe_import_safe": sdk_probe_import_safe,
            "sdk_handler_blueprint_available": sdk_handler_blueprint_available,
            "sdk_kernel_normalizer_required": sdk_kernel_normalizer_required,
            "production_review_receipt_valid": review_receipt_valid,
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


def _status(machine_ready: bool, review_receipt_valid: bool) -> str:
    if not machine_ready:
        return "blocked"
    if not review_receipt_valid:
        return "ready_for_human_review"

    return "ready_for_daemon_implementation_review"


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
    if status == "blocked_unimplemented_start":
        return "submit_daemon_implementation_review"

    return "fix_voice_product_loop_gates"
