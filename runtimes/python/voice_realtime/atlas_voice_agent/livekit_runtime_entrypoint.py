from __future__ import annotations

from pathlib import Path
from typing import Any, Mapping

from .activation_contract import build_activation_contract
from .contract import AtlasVoiceRuntimeContract
from .livekit_production_loop import build_production_loop_plan
from .production_promotion_review import validate_production_promotion_review
from .worker_plan import build_livekit_worker_plan


def start_livekit_agents_worker(
    contract: AtlasVoiceRuntimeContract,
    *,
    env_file: Path | None = None,
    env: Mapping[str, str] | None = None,
    settings_loaded: bool = False,
    boundary_created: bool = False,
    mock_kernel: bool = False,
    callback_loop_wired: bool = False,
    production_sdk_loop_wired: bool = False,
    production_promotion_approved: bool = False,
    production_promotion_review: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Fail-closed entrypoint for the future long-running LiveKit worker.

    This function intentionally does not start a daemon until the optional SDK,
    environment, Kernel boundary and callback wiring are all proven. It gives
    operators a stable command surface now, while preventing ad hoc workers.
    """

    plan = build_livekit_worker_plan(
        contract,
        settings_loaded=settings_loaded,
        boundary_created=boundary_created,
        mock_kernel=mock_kernel,
        callback_loop_wired=callback_loop_wired,
    )
    activation_contract = build_activation_contract(
        contract,
        env_file=env_file,
        env=env,
        settings_loaded=settings_loaded,
        boundary_created=boundary_created,
        mock_kernel=mock_kernel,
        callback_loop_wired=callback_loop_wired,
        production_sdk_loop_wired=production_sdk_loop_wired,
    )
    production_loop_plan = build_production_loop_plan(
        contract,
        settings_loaded=settings_loaded,
        boundary_created=boundary_created,
        production_sdk_loop_wired=production_sdk_loop_wired,
    )
    review_check = validate_production_promotion_review(production_promotion_review)
    review_approved = review_check.get("valid") is True
    sdk_status = str(plan.get("sdk_status", {}).get("status") or "unknown")
    can_start = bool(plan.get("activation", {}).get("can_start_long_running_worker"))

    if sdk_status != "ready":
        status = "blocked_missing_sdk"
        reason = "LiveKit Agents SDK is not installed or not importable."
    elif mock_kernel:
        status = "blocked_mock_kernel"
        reason = "Long-running voice workers must call the real Laravel Kernel."
    elif not settings_loaded or not boundary_created:
        status = "blocked_missing_runtime_settings"
        reason = "Worker start requires --env or --env-file so Kernel auth and LiveKit settings are loaded."
    elif not callback_loop_wired:
        status = "blocked_unwired_sdk_callbacks"
        reason = "LiveKit Agents SDK callback loop is not wired to LiveKitCallbackRouter yet."
    elif not production_sdk_loop_wired:
        status = "blocked_unwired_production_loop"
        reason = "Production LiveKit Agents SDK loop is not wired through the governed callback router yet."
    elif not can_start:
        status = "blocked_by_activation_gate"
        reason = "Worker plan activation gate did not allow long-running startup."
    elif activation_contract.get("status") != "ready_to_start_worker":
        status = "blocked_by_activation_contract"
        reason = "Activation contract did not allow long-running startup."
    elif not review_approved:
        status = "blocked_pending_human_review"
        reason = "Machine gates passed, but production promotion still requires a valid human review receipt, Decision Receipt and rollback plan."
    else:
        status = "blocked_unimplemented_start"
        reason = "Human review receipt is valid, but daemon startup remains disabled until production loop implementation is committed."

    return {
        "schema_version": "atlas.voice_realtime.worker_start.v1",
        "status": status,
        "surface_id": "voice_realtime",
        "runtime_id": "livekit_agents_sdk",
        "kernel_only": True,
        "mobile_first": True,
        "callback_loop_wired": callback_loop_wired,
        "production_sdk_loop_wired": production_sdk_loop_wired,
        "production_promotion_approved": production_promotion_approved,
        "production_promotion_review_valid": review_approved,
        "started": False,
        "reason": reason,
        "worker_plan": plan,
        "activation_contract": activation_contract,
        "production_loop_plan": production_loop_plan,
        "sdk_wiring_contract": production_loop_plan.get("sdk_wiring_contract"),
        "activation_next_action": activation_contract.get("next_action"),
        "production_promotion": {
            "required": True,
            "gate_schema_version": "atlas.voice_realtime.production_promotion_gate.v1",
            "human_review_required": True,
            "decision_receipt_required": True,
            "rollback_plan_required": True,
            "human_review_approved": review_approved,
            "review_receipt_valid": review_approved,
            "boolean_approval_is_sufficient": False,
            "declared_approved_without_receipt": production_promotion_approved and not review_approved,
            "review_check": review_check,
            "auto_promotion_allowed": False,
            "next_action": "run_runtime_certify_with_require_sdk_then_submit_human_review",
        },
        "guardrails": {
            "direct_provider_call_allowed": False,
            "direct_tool_execution_allowed": False,
            "raw_audio_persistence_allowed": False,
            "access_token_log_allowed": False,
            "worker_start_without_production_promotion_allowed": False,
        },
    }
