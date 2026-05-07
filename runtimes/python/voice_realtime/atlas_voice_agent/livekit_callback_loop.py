from __future__ import annotations

from typing import Any, Mapping

from .callback_contract import REQUIRED_CALLBACK_METHODS, REQUIRED_CALLBACK_PAYLOAD_SCHEMAS
from .livekit_callback_router import LiveKitCallbackRouter
from .livekit_sdk_adapter import LiveKitSdkAdapter


def inspect_callback_loop_contract(
    *,
    production_sdk_loop_wired: bool = False,
) -> Mapping[str, Any]:
    """Inspect the callback translation layer before any daemon can start.

    The translation layer can be complete while the production LiveKit SDK
    listener is still disabled. Worker startup must stay blocked until both are
    true, but this check lets the Kernel and docs prove the mapping exists.
    """

    router_callbacks = LiveKitCallbackRouter.supported_callbacks()
    missing_router_callbacks = sorted(set(REQUIRED_CALLBACK_METHODS) - set(router_callbacks))
    extra_router_callbacks = sorted(set(router_callbacks) - set(REQUIRED_CALLBACK_METHODS))
    adapter_methods = {
        callback: _callable_name(LiveKitSdkAdapter, method)
        for callback, method in REQUIRED_CALLBACK_METHODS.items()
    }
    missing_adapter_methods = sorted(
        callback
        for callback, method_name in adapter_methods.items()
        if method_name is None
    )
    missing_payload_schemas = sorted(set(REQUIRED_CALLBACK_METHODS) - set(REQUIRED_CALLBACK_PAYLOAD_SCHEMAS))
    payload_schema_without_callback = sorted(set(REQUIRED_CALLBACK_PAYLOAD_SCHEMAS) - set(REQUIRED_CALLBACK_METHODS))
    invalid_payload_schemas = [
        callback
        for callback, schema in REQUIRED_CALLBACK_PAYLOAD_SCHEMAS.items()
        if not schema.get("required") or not schema.get("prohibited")
    ]
    translation_layer_ready = (
        not missing_router_callbacks
        and not extra_router_callbacks
        and not missing_adapter_methods
        and not missing_payload_schemas
        and not payload_schema_without_callback
        and not invalid_payload_schemas
    )

    return {
        "schema_version": "atlas.voice_realtime.callback_loop_contract.v1",
        "status": "ready" if translation_layer_ready else "blocked",
        "surface_id": "voice_realtime",
        "runtime_id": "livekit_agents_sdk",
        "kernel_only": True,
        "mobile_first": True,
        "translation_layer_ready": translation_layer_ready,
        "production_sdk_loop_wired": production_sdk_loop_wired,
        "worker_start_callback_loop_wired": translation_layer_ready and production_sdk_loop_wired,
        "required_callbacks": router_callbacks,
        "required_callback_methods": REQUIRED_CALLBACK_METHODS,
        "required_callback_payload_schemas": REQUIRED_CALLBACK_PAYLOAD_SCHEMAS,
        "adapter_methods": adapter_methods,
        "missing_router_callbacks": missing_router_callbacks,
        "extra_router_callbacks": extra_router_callbacks,
        "missing_adapter_methods": missing_adapter_methods,
        "missing_payload_schemas": missing_payload_schemas,
        "payload_schema_without_callback": payload_schema_without_callback,
        "invalid_payload_schemas": invalid_payload_schemas,
        "next_action": _next_action(translation_layer_ready, production_sdk_loop_wired),
        "guardrails": {
            "direct_provider_call_allowed": False,
            "direct_tool_execution_allowed": False,
            "raw_audio_persistence_allowed": False,
            "raw_transcript_persistence_allowed": False,
            "access_token_log_allowed": False,
        },
    }


def _callable_name(cls: type[LiveKitSdkAdapter], method: str) -> str | None:
    candidate = getattr(cls, method, None)
    if not callable(candidate):
        return None

    return method


def _next_action(translation_layer_ready: bool, production_sdk_loop_wired: bool) -> str:
    if not translation_layer_ready:
        return "fix_callback_translation_layer"
    if not production_sdk_loop_wired:
        return "wire_real_livekit_agents_sdk_loop"

    return "run_worker_start_check"
