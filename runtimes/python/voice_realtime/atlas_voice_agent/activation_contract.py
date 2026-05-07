from __future__ import annotations

from pathlib import Path
from typing import Any, Mapping

from .contract import AtlasVoiceRuntimeContract
from .preflight import run_runtime_preflight
from .worker_plan import build_livekit_worker_plan


def build_activation_contract(
    contract: AtlasVoiceRuntimeContract,
    *,
    env_file: Path | None = None,
    env: Mapping[str, str] | None = None,
    settings_loaded: bool = False,
    boundary_created: bool = False,
    mock_kernel: bool = False,
    callback_loop_wired: bool = False,
) -> Mapping[str, Any]:
    """Publish the full activation gate for the real LiveKit worker."""

    preflight = run_runtime_preflight(env_file=env_file, env=env, require_sdk=True)
    worker_plan = build_livekit_worker_plan(
        contract,
        settings_loaded=settings_loaded,
        boundary_created=boundary_created,
        mock_kernel=mock_kernel,
        callback_loop_wired=callback_loop_wired,
    )
    sdk_status = preflight.get("sdk_status")
    sdk_ready = isinstance(sdk_status, Mapping) and sdk_status.get("status") == "ready"
    gates = {
        "preflight_ready": preflight.get("status") == "ready",
        "sdk_ready": sdk_ready,
        "settings_loaded": settings_loaded,
        "boundary_created": boundary_created,
        "callback_loop_wired": callback_loop_wired,
        "real_kernel_required": not mock_kernel,
        "worker_plan_allows_start": bool(worker_plan.get("activation", {}).get("can_start_long_running_worker")),
        "kernel_only_guardrails": bool(worker_plan.get("guardrails", {}).get("kernel_decides"))
        and worker_plan.get("guardrails", {}).get("direct_provider_call_allowed") is False
        and worker_plan.get("guardrails", {}).get("direct_tool_execution_allowed") is False,
    }
    status = "ready_to_start_worker" if all(gates.values()) else "blocked"

    return {
        "schema_version": "atlas.voice_realtime.activation_contract.v1",
        "status": status,
        "surface_id": "voice_realtime",
        "runtime_id": "livekit_agents_sdk",
        "kernel_only": True,
        "mobile_first": True,
        "gates": gates,
        "preflight": preflight,
        "worker_plan": worker_plan,
        "required_sequence": [
            "atlas:ai:voice preflight --require-sdk --json",
            "atlas:ai:voice sdk-check --json",
            "atlas:ai:voice worker-plan --json",
            "atlas:ai:voice worker-start-check --json",
        ],
        "forbidden_shortcuts": [
            "start_worker_without_preflight",
            "start_worker_against_mock_kernel",
            "sdk_callback_direct_to_provider",
            "sdk_callback_direct_to_tool",
            "start_worker_before_callback_loop_wired",
            "persist_raw_audio_or_token",
        ],
        "next_action": _next_action(gates, preflight, worker_plan),
    }


def _next_action(
    gates: Mapping[str, bool],
    preflight: Mapping[str, Any],
    worker_plan: Mapping[str, Any],
) -> str:
    if not gates["preflight_ready"]:
        return str(preflight.get("next_action") or "fix_runtime_environment")
    if not gates["settings_loaded"] or not gates["boundary_created"]:
        return "load_runtime_settings_and_kernel_boundary"
    if not gates["callback_loop_wired"]:
        return "wire_real_sdk_callback_loop"
    if not gates["real_kernel_required"]:
        return "use_real_kernel_not_mock"
    if not gates["worker_plan_allows_start"]:
        return str(worker_plan.get("next_action") or "fix_worker_plan")
    if not gates["kernel_only_guardrails"]:
        return "fix_kernel_only_guardrails"

    return "start_worker"
