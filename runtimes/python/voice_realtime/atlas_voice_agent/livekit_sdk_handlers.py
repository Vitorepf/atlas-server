from __future__ import annotations

from typing import Any, Callable, Mapping

from .kernel_event_normalizer import KernelRuntimeEventNormalizerGuard
from .livekit_callback_router import LiveKitCallbackRouter
from .livekit_sdk_event_bridge import LiveKitSdkEventBridge
from .livekit_worker import LiveKitWorkerResult

SdkHandler = Callable[[Mapping[str, Any]], LiveKitWorkerResult]


class LiveKitSdkHandlerRegistry:
    """Kernel-only handler registry for the real LiveKit Agents SDK loop.

    Real SDK callbacks should bind to these handlers instead of calling
    providers, tools, memory, policy, or worker methods directly. The handler is
    allowed to extract primitive SDK fields, add the canonical event kind, and
    route through `LiveKitSdkEventBridge` + `LiveKitCallbackRouter`.
    """

    def __init__(self, router: LiveKitCallbackRouter, normalizer: KernelRuntimeEventNormalizerGuard | None = None) -> None:
        self.router = router
        self.normalizer = normalizer

    def handlers(self) -> dict[str, SdkHandler]:
        return {
            event_kind: self._handler_for(event_kind)
            for event_kind in LiveKitSdkEventBridge.EVENT_TO_CALLBACK.keys()
        }

    def route(self, event_kind: str, event: Mapping[str, Any]) -> LiveKitWorkerResult:
        handler = self.handlers().get(event_kind)
        if handler is None:
            if self.normalizer is not None:
                self.normalizer.assert_event_valid({
                    **dict(event),
                    "event_kind": event_kind,
                })

            return self.router.route(LiveKitSdkEventBridge.to_callback_event({
                **dict(event),
                "event_kind": event_kind,
            }))

        return handler(event)

    def _handler_for(self, event_kind: str) -> SdkHandler:
        def handler(event: Mapping[str, Any]) -> LiveKitWorkerResult:
            sdk_event = dict(event)
            sdk_event["event_kind"] = event_kind
            if self.normalizer is not None:
                self.normalizer.assert_event_valid(sdk_event)

            return self.router.route(LiveKitSdkEventBridge.to_callback_event(sdk_event))

        handler.__name__ = f"handle_{event_kind}"

        return handler

    @classmethod
    def contract(cls) -> dict[str, Any]:
        event_kinds = list(LiveKitSdkEventBridge.EVENT_TO_CALLBACK.keys())

        return {
            "schema_version": "atlas.voice_realtime.sdk_handler_registry.v1",
            "status": "ready",
            "runtime_id": "livekit_agents_sdk",
            "surface_id": "voice_realtime",
            "kernel_only": True,
            "mobile_first": True,
            "sdk_import_required_for_contract": False,
            "handler_count": len(event_kinds),
            "supported_event_kinds": event_kinds,
            "handler_names": [f"handle_{event_kind}" for event_kind in event_kinds],
            "required_path": [
                "LiveKitSdkHandlerRegistry.handlers",
                "KernelRuntimeEventNormalizerGuard.assert_event_valid",
                "LiveKitSdkEventBridge.to_callback_event",
                "LiveKitCallbackRouter.route",
                "AtlasLiveKitWorker",
                "Atlas Kernel contract endpoint",
            ],
            "forbidden_path": [
                "provider SDK call",
                "tool execution",
                "memory write",
                "policy mutation",
                "raw audio persistence",
                "token logging",
            ],
            "guardrails": {
                "direct_provider_call_allowed": False,
                "direct_tool_execution_allowed": False,
                "memory_write_allowed": False,
                "policy_mutation_allowed": False,
                "raw_audio_persistence_allowed": False,
                "access_token_log_allowed": False,
                "kernel_event_normalizer_required_for_real_loop": True,
            },
        }


def build_livekit_sdk_handler_contract() -> Mapping[str, Any]:
    return LiveKitSdkHandlerRegistry.contract()
