from __future__ import annotations

from typing import Any, Mapping

from .livekit_callback_router import LiveKitCallbackRouter
from .livekit_sdk_event_bridge import LiveKitSdkEventBridge
from .livekit_sdk_handlers import build_livekit_sdk_handler_contract


def build_livekit_sdk_wiring_contract(*, production_sdk_loop_wired: bool = False) -> Mapping[str, Any]:
    """Describe the only allowed wiring shape for the real LiveKit SDK loop."""

    callbacks = LiveKitCallbackRouter.supported_callbacks()
    event_bridge = LiveKitSdkEventBridge.contract()
    handler_contract = build_livekit_sdk_handler_contract()
    missing_callbacks = [
        callback
        for callback in callbacks
        if callback not in event_bridge["event_to_callback"].values()
    ]
    missing_handler_event_kinds = [
        event_kind
        for event_kind in event_bridge["supported_event_kinds"]
        if f"handle_{event_kind}" not in handler_contract["handler_names"]
    ]
    complete = missing_callbacks == [] and missing_handler_event_kinds == []

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
        "complete_handler_registry": missing_handler_event_kinds == [],
        "missing_handler_event_kinds": missing_handler_event_kinds,
        "handler_registry_contract": handler_contract,
        "required_components": {
            "settings": "AtlasVoiceRuntimeSettings",
            "kernel_boundary": "LiveKitAgentBoundary",
            "worker": "AtlasLiveKitWorker",
            "sdk_adapter": "LiveKitSdkAdapter",
            "kernel_event_normalizer": "KernelRuntimeEventNormalizerGuard",
            "sdk_event_bridge": "LiveKitSdkEventBridge",
            "sdk_handler_registry": "LiveKitSdkHandlerRegistry",
            "callback_router": "LiveKitCallbackRouter",
        },
        "required_handlers": [
            {
                "sdk_event_kind": event_kind,
                "callback_kind": callback_kind,
                "required_keys": event_bridge["required_keys"][callback_kind],
                "allowed_payload_keys": event_bridge["allowed_payload_keys"][callback_kind],
                "handler_blueprint": _handler_blueprint(event_kind, callback_kind),
            }
            for event_kind, callback_kind in event_bridge["event_to_callback"].items()
        ],
        "wiring_invariants": [
            "extract_primitive_sdk_fields_only",
            "never_forward_sdk_objects_or_tokens",
            "validate_every_sdk_event_through_kernel_normalizer",
            "call_LiveKitSdkEventBridge_to_callback_event",
            "route_through_LiveKitCallbackRouter",
            "route_all_livekit_sdk_handlers_through_registry",
            "return_LiveKitWorkerResult_log_payload_only",
            "never_call_provider_tool_memory_or_policy_from_sdk_handler",
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
            "kernel_event_normalizer_required_for_real_loop": True,
        },
        "next_action": _next_action(
            production_sdk_loop_wired=production_sdk_loop_wired,
            missing_callbacks=missing_callbacks,
            missing_handler_event_kinds=missing_handler_event_kinds,
        ),
    }


def _handler_blueprint(event_kind: str, callback_kind: str) -> Mapping[str, Any]:
    return {
        "name": f"handle_{event_kind}",
        "input": "LiveKit SDK object or primitive callback payload",
        "normalized_event_kind": event_kind,
        "callback_kind": callback_kind,
        "required_path": [
            "extract primitive fields",
            "KernelRuntimeEventNormalizerGuard.assert_event_valid",
            "LiveKitSdkEventBridge.to_callback_event",
            "LiveKitCallbackRouter.route",
            "AtlasLiveKitWorker.handle_event/start_session",
            "Atlas Kernel contract endpoint",
        ],
        "forbidden_path": [
            "provider SDK call",
            "tool execution",
            "memory write",
            "raw audio persistence",
            "token logging",
        ],
    }


def _next_action(
    *,
    production_sdk_loop_wired: bool,
    missing_callbacks: list[str],
    missing_handler_event_kinds: list[str],
) -> str:
    if missing_callbacks:
        return "fix_sdk_event_bridge_callback_coverage"
    if missing_handler_event_kinds:
        return "fix_sdk_handler_registry"
    if not production_sdk_loop_wired:
        return "wire_sdk_handlers_to_event_bridge"

    return "run_worker_start_check"
