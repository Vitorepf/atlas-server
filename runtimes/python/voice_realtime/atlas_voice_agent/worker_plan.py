from __future__ import annotations

from typing import Any, Mapping

from .contract import AtlasVoiceRuntimeContract
from .sdk_status import inspect_livekit_sdk


def build_livekit_worker_plan(
    contract: AtlasVoiceRuntimeContract,
    *,
    settings_loaded: bool = False,
    boundary_created: bool = False,
    mock_kernel: bool = False,
    callback_loop_wired: bool = False,
) -> Mapping[str, Any]:
    """Describe the real LiveKit worker activation path without starting it.

    This is the last fail-closed checkpoint before a long-running LiveKit
    Agents worker exists. It must stay import-light and must not call any SDK,
    provider, tool, memory writer or Kernel endpoint.
    """

    sdk = inspect_livekit_sdk(contract)
    sdk_ready = sdk.get("status") == "ready"

    return {
        "schema_version": "atlas.voice_realtime.worker_plan.v1",
        "status": "ready_to_wire_callbacks" if sdk_ready else "blocked_missing_sdk",
        "surface_id": "voice_realtime",
        "runtime_id": "livekit_agents_sdk",
        "runtime_family": "python_ai_data",
        "kernel_only": True,
        "mobile_first": True,
        "settings_loaded": settings_loaded,
        "boundary_created": boundary_created,
        "callback_loop_wired": callback_loop_wired,
        "mock_kernel": mock_kernel,
        "sdk_status": sdk,
        "entrypoint": {
            "module": "atlas_voice_agent.main",
            "factory": "create_atlas_voice_agent",
            "worker_adapter": "AtlasLiveKitWorker",
            "sdk_adapter": "LiveKitSdkAdapter",
            "callback_router": "LiveKitCallbackRouter",
            "session_handle": "LiveKitVoiceSession",
        },
        "activation": {
            "can_start_long_running_worker": sdk_ready and settings_loaded and boundary_created and callback_loop_wired and not mock_kernel,
            "requires_env_or_env_file": True,
            "requires_livekit_agents_sdk": True,
            "requires_kernel_bootstrap": True,
            "requires_kernel_token": True,
            "requires_kernel_boundary": True,
            "requires_callback_loop": True,
            "future_start_command_template": (
                "PYTHONPATH=runtimes/python/voice_realtime "
                "python3 -m atlas_voice_agent.main --env --start-worker"
            ),
        },
        "allowlists": {
            "client_surfaces": ["mobile", "mac_edge"],
            "transports": ["mobile_push_to_talk", "livekit_webrtc"],
            "runtimes": ["livekit_agents_sdk"],
            "privacy_classes": ["p1_public", "p2_internal", "p3_audio", "p4_secret"],
        },
        "guardrails": {
            "kernel_decides": True,
            "decision_receipt_required_per_turn": True,
            "direct_provider_call_allowed": False,
            "direct_tool_execution_allowed": False,
            "memory_write_allowed": False,
            "raw_audio_persistence_allowed": False,
            "raw_transcript_persistence_allowed": False,
            "access_token_log_allowed": False,
        },
        "next_action": _next_action(sdk_ready, settings_loaded, boundary_created, callback_loop_wired, mock_kernel),
    }


def _next_action(
    sdk_ready: bool,
    settings_loaded: bool,
    boundary_created: bool,
    callback_loop_wired: bool,
    mock_kernel: bool,
) -> str:
    if not sdk_ready:
        return "install_livekit_agents_sdk"
    if not settings_loaded or not boundary_created:
        return "load_runtime_settings_and_kernel_boundary"
    if not callback_loop_wired:
        return "wire_real_sdk_callback_loop"
    if mock_kernel:
        return "use_real_kernel_not_mock"

    return "start_worker"
