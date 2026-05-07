from __future__ import annotations

from pathlib import Path
from typing import Any, Mapping

from .activation_contract import build_activation_contract
from .contract import AtlasVoiceRuntimeContract
from .livekit_production_loop import build_production_loop_plan
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
    )
    production_loop_plan = build_production_loop_plan(
        contract,
        settings_loaded=settings_loaded,
        boundary_created=boundary_created,
        production_sdk_loop_wired=production_sdk_loop_wired,
    )
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
    else:
        status = "blocked_unimplemented_start"
        reason = "All gates passed, but daemon startup remains disabled until production loop implementation is committed."

    return {
        "schema_version": "atlas.voice_realtime.worker_start.v1",
        "status": status,
        "surface_id": "voice_realtime",
        "runtime_id": "livekit_agents_sdk",
        "kernel_only": True,
        "mobile_first": True,
        "callback_loop_wired": callback_loop_wired,
        "production_sdk_loop_wired": production_sdk_loop_wired,
        "started": False,
        "reason": reason,
        "worker_plan": plan,
        "activation_contract": activation_contract,
        "production_loop_plan": production_loop_plan,
        "sdk_wiring_contract": production_loop_plan.get("sdk_wiring_contract"),
        "activation_next_action": activation_contract.get("next_action"),
        "guardrails": {
            "direct_provider_call_allowed": False,
            "direct_tool_execution_allowed": False,
            "raw_audio_persistence_allowed": False,
            "access_token_log_allowed": False,
        },
    }
