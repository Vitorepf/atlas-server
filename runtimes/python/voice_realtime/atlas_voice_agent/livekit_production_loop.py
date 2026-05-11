from __future__ import annotations

from typing import Any, Mapping

from .contract import AtlasVoiceRuntimeContract
from .livekit_callback_loop import inspect_callback_loop_contract
from .livekit_callback_router import LiveKitCallbackRouter
from .livekit_sdk_event_bridge import LiveKitSdkEventBridge
from .livekit_sdk_wiring_contract import build_livekit_sdk_wiring_contract
from .sdk_status import inspect_livekit_sdk


def build_production_loop_plan(
    contract: AtlasVoiceRuntimeContract,
    *,
    settings_loaded: bool = False,
    boundary_created: bool = False,
    production_sdk_loop_wired: bool = False,
) -> Mapping[str, Any]:
    """Plan the real LiveKit Agents SDK loop without starting it."""

    sdk = inspect_livekit_sdk(contract)
    callback_loop = inspect_callback_loop_contract(
        production_sdk_loop_wired=production_sdk_loop_wired,
    )
    sdk_wiring = build_livekit_sdk_wiring_contract(
        production_sdk_loop_wired=production_sdk_loop_wired,
    )
    sdk_ready = sdk.get("status") == "ready"
    translation_ready = bool(callback_loop.get("translation_layer_ready"))
    wiring_coverage_ready = bool(sdk_wiring.get("complete_callback_coverage"))
    can_wire = sdk_ready and settings_loaded and boundary_created and translation_ready and wiring_coverage_ready

    return {
        "schema_version": "atlas.voice_realtime.production_loop_plan.v1",
        "status": "ready_to_wire" if can_wire else "blocked",
        "surface_id": "voice_realtime",
        "runtime_id": "livekit_agents_sdk",
        "kernel_only": True,
        "mobile_first": True,
        "settings_loaded": settings_loaded,
        "boundary_created": boundary_created,
        "production_sdk_loop_wired": production_sdk_loop_wired,
        "worker_start_callback_loop_wired": bool(callback_loop.get("worker_start_callback_loop_wired")),
        "sdk_status": sdk,
        "callback_loop_contract": callback_loop,
        "sdk_event_bridge_contract": LiveKitSdkEventBridge.contract(),
        "sdk_wiring_contract": sdk_wiring,
        "required_loop_hooks": LiveKitCallbackRouter.supported_callbacks(),
        "implementation_sequence": [
            "install_livekit_agents_sdk",
            "load_runtime_settings",
            "create_real_kernel_boundary",
            "connect_livekit_room_without_logging_token",
            "normalize_raw_sdk_objects_through_LiveKitSdkEventBridge",
            "route_all_sdk_callbacks_through_LiveKitCallbackRouter",
            "run_callback_sequence_smoke",
            "run_worker_start_check",
        ],
        "guardrails": {
            "direct_provider_call_allowed": False,
            "direct_tool_execution_allowed": False,
            "memory_write_allowed": False,
            "raw_audio_persistence_allowed": False,
            "raw_transcript_persistence_allowed": False,
            "access_token_log_allowed": False,
            "worker_start_allowed_by_this_plan": False,
        },
        "next_action": _next_action(sdk, sdk_ready, settings_loaded, boundary_created, translation_ready, wiring_coverage_ready, production_sdk_loop_wired),
    }


def _next_action(
    sdk_status: Mapping[str, Any],
    sdk_ready: bool,
    settings_loaded: bool,
    boundary_created: bool,
    translation_ready: bool,
    wiring_coverage_ready: bool,
    production_sdk_loop_wired: bool,
) -> str:
    if not sdk_ready:
        return str(sdk_status.get("next_action") or "install_livekit_agents_sdk")
    if not settings_loaded:
        return "load_runtime_settings"
    if not boundary_created:
        return "create_real_kernel_boundary"
    if not translation_ready:
        return "fix_callback_translation_layer"
    if not wiring_coverage_ready:
        return "fix_sdk_wiring_contract"
    if not production_sdk_loop_wired:
        return "wire_real_livekit_agents_sdk_loop"

    return "run_worker_start_check"
