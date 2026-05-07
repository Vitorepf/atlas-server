from __future__ import annotations

from typing import Any, Mapping

from .livekit_callback_router import LiveKitCallbackRouter
from .livekit_sdk_event_bridge import LiveKitSdkEventBridge


def build_livekit_sdk_wiring_contract(*, production_sdk_loop_wired: bool = False) -> Mapping[str, Any]:
    """Describe the only allowed wiring shape for the real LiveKit SDK loop."""

    callbacks = LiveKitCallbackRouter.supported_callbacks()
    event_bridge = LiveKitSdkEventBridge.contract()
    missing_callbacks = [
        callback
        for callback in callbacks
        if callback not in event_bridge["event_to_callback"].values()
    ]
    complete = missing_callbacks == []

    return {
        "schema_version": "atlas.voice_realtime.sdk_wiring_contract.v1",
        "status": "wired" if production_sdk_loop_wired and complete else "pending",
        "runtime_id": "livekit_agents_sdk",
        "surface_id": "voice_realtime",
        "kernel_only": True,
        "mobile_first": True,
        "production_sdk_loop_wired": production_sdk_loop_wired,
        "complete_callback_coverage": complete,
        "missing_callbacks": missing_callbacks,
        "required_components": {
            "settings": "AtlasVoiceRuntimeSettings",
            "kernel_boundary": "LiveKitAgentBoundary",
            "worker": "AtlasLiveKitWorker",
            "sdk_adapter": "LiveKitSdkAdapter",
            "sdk_event_bridge": "LiveKitSdkEventBridge",
            "callback_router": "LiveKitCallbackRouter",
        },
        "required_handlers": [
            {
                "sdk_event_kind": event_kind,
                "callback_kind": callback_kind,
                "required_keys": event_bridge["required_keys"][callback_kind],
                "allowed_payload_keys": event_bridge["allowed_payload_keys"][callback_kind],
            }
            for event_kind, callback_kind in event_bridge["event_to_callback"].items()
        ],
        "forbidden_keys": event_bridge["forbidden_keys"],
        "guardrails": {
            "direct_provider_call_allowed": False,
            "direct_tool_execution_allowed": False,
            "memory_write_allowed": False,
            "raw_audio_persistence_allowed": False,
            "raw_transcript_persistence_allowed": False,
            "access_token_log_allowed": False,
            "sdk_import_required_for_contract": False,
        },
        "next_action": "run_worker_start_check" if production_sdk_loop_wired and complete else "wire_sdk_handlers_to_event_bridge",
    }
