from __future__ import annotations

from typing import Any, Mapping

from .kernel_client import AtlasKernelClient
from .kernel_event_normalizer import KernelRuntimeEventNormalizerGuard, kernel_normalizer_contract_report
from .livekit_sdk_event_bridge import LiveKitSdkEventBridge
from .livekit_sdk_handlers import LiveKitSdkHandlerRegistry
from .production_loop_smoke_packet import validate_production_loop_smoke
from .livekit_worker import LiveKitWorkerResult
from .turn_payload import UnsafeVoicePayload


class LiveKitProductionLoopRunner:
    """Run production-shaped SDK events through the governed bridge.

    This is not a daemon and does not import LiveKit. It proves the exact path
    that the future SDK loop must use: raw SDK primitive mapping -> handler
    registry -> bridge -> callback router -> worker -> Kernel.
    """

    def __init__(self, bridge: LiveKitSdkEventBridge) -> None:
        self.bridge = bridge
        self.handler_registry = LiveKitSdkHandlerRegistry(
            bridge.router,
            normalizer=KernelRuntimeEventNormalizerGuard(self._kernel_client()),
        )

    def run_sdk_events(self, events: list[Mapping[str, Any]]) -> Mapping[str, Any]:
        if not events:
            raise ValueError("production loop SDK events cannot be empty")

        normalizer_report = KernelRuntimeEventNormalizerGuard(self._kernel_client()).assert_sequence_valid(events)

        results: list[LiveKitWorkerResult] = []
        for event in events:
            event_kind = str(event.get("event_kind") or "").strip()
            if event_kind == "":
                raise UnsafeVoicePayload("event_kind is required")
            results.append(self.handler_registry.route(event_kind, event))

        active_session_count = self._active_session_count()
        if active_session_count not in (0, None):
            raise UnsafeVoicePayload("production loop smoke must end with all sessions closed")

        bridge_contract = LiveKitSdkEventBridge.contract()
        handler_registry_contract = LiveKitSdkHandlerRegistry.contract()

        return validate_production_loop_smoke({
            "schema_version": "atlas.voice_realtime.production_loop_smoke.v1",
            "status": "production_loop_smoke_completed",
            "kernel_only": True,
            "mobile_first": True,
            "daemon_started": False,
            "sdk_imported": False,
            "event_count": len(events),
            "result_count": len(results),
            "active_session_count": active_session_count,
            "bridge_contract": bridge_contract,
            "bridge_contract_report": _bridge_contract_report(bridge_contract),
            "handler_registry_contract": handler_registry_contract,
            "handler_registry_contract_report": _handler_registry_contract_report(handler_registry_contract, bridge_contract),
            "kernel_normalizer_contract_report": normalizer_report,
            "worker_return_contract": _worker_return_contract_report(results),
            "results": [result.log_payload() for result in results],
            "guardrails": {
                "direct_provider_call_allowed": False,
                "direct_tool_execution_allowed": False,
                "raw_audio_persistence_allowed": False,
                "access_token_log_allowed": False,
            },
        })

    def _active_session_count(self) -> int | None:
        worker = self.bridge.router.adapter.worker
        counter = getattr(worker, "active_session_count", None)
        if not callable(counter):
            return None

        return int(counter())

    def _kernel_client(self) -> AtlasKernelClient:
        client = self.bridge.router.adapter.worker.boundary.runtime.client
        if not isinstance(client, AtlasKernelClient):
            raise UnsafeVoicePayload("production loop requires AtlasKernelClient")

        return client


_normalizer_contract_report = kernel_normalizer_contract_report


def _worker_return_contract_report(results: list[LiveKitWorkerResult]) -> Mapping[str, Any]:
    required_keys = {
        "schema_version",
        "envelope_id",
        "decision_receipt_hash",
        "artifacts",
        "metrics",
        "evidence_refs",
        "errors",
    }
    checked = 0
    missing: list[Mapping[str, Any]] = []

    for index, result in enumerate(results):
        if result.event_kind == "session_started":
            continue

        checked += 1
        missing_keys = sorted(required_keys - set(result.payload.keys()))
        schema_valid = result.payload.get("schema_version") == "atlas.voice_realtime.worker_return.v1"
        if missing_keys or not schema_valid:
            missing.append({
                "index": index,
                "event_kind": result.event_kind,
                "missing_keys": missing_keys,
                "schema_valid": schema_valid,
            })

    return {
        "schema_version": "atlas.voice_realtime.worker_return_contract_report.v1",
        "status": "valid" if not missing else "invalid",
        "checked_result_count": checked,
        "invalid_result_count": len(missing),
        "required_keys": sorted(required_keys),
        "invalid_results": missing,
    }


def _handler_registry_contract_report(
    contract: Mapping[str, Any],
    bridge_contract: Mapping[str, Any],
) -> Mapping[str, Any]:
    event_kinds = set(bridge_contract.get("supported_event_kinds") or [])
    handler_names = set(contract.get("handler_names") or [])
    missing_handlers = sorted(
        event_kind
        for event_kind in event_kinds
        if f"handle_{event_kind}" not in handler_names
    )
    guardrails = contract.get("guardrails") if isinstance(contract.get("guardrails"), Mapping) else {}
    invalid_guardrails = sorted(
        key
        for key in [
            "direct_provider_call_allowed",
            "direct_tool_execution_allowed",
            "memory_write_allowed",
            "policy_mutation_allowed",
            "raw_audio_persistence_allowed",
            "access_token_log_allowed",
        ]
        if guardrails.get(key) is not False
    )
    if guardrails.get("kernel_event_normalizer_required_for_real_loop") is not True:
        invalid_guardrails.append("kernel_event_normalizer_required_for_real_loop")
        invalid_guardrails.sort()

    schema_valid = contract.get("schema_version") == "atlas.voice_realtime.sdk_handler_registry.v1"
    sdk_import_safe = contract.get("sdk_import_required_for_contract") is False

    return {
        "schema_version": "atlas.voice_realtime.handler_registry_contract_report.v1",
        "status": "valid" if schema_valid and sdk_import_safe and not missing_handlers and not invalid_guardrails else "invalid",
        "handler_schema_valid": schema_valid,
        "sdk_import_safe": sdk_import_safe,
        "supported_event_count": len(event_kinds),
        "handler_count": len(handler_names),
        "missing_handlers": missing_handlers,
        "invalid_guardrails": invalid_guardrails,
    }


def _bridge_contract_report(contract: Mapping[str, Any]) -> Mapping[str, Any]:
    required_guardrails = {
        "direct_provider_call_allowed": False,
        "direct_tool_execution_allowed": False,
        "raw_audio_persistence_allowed": False,
        "access_token_log_allowed": False,
    }
    required_callbacks = {
        "participant_joined",
        "transcript_final",
        "wake_word_detected",
        "tts_synthesized",
        "audio_played",
        "barge_in",
        "runtime_failed",
        "provider_health_degraded",
        "participant_left",
    }
    guardrails = contract.get("guardrails") if isinstance(contract.get("guardrails"), Mapping) else {}
    callbacks = set(contract.get("supported_callbacks") or [])
    missing_callbacks = sorted(required_callbacks - callbacks)
    invalid_guardrails = sorted(
        key
        for key, expected in required_guardrails.items()
        if guardrails.get(key) is not expected
    )
    schema_valid = contract.get("schema_version") == "atlas.voice_realtime.sdk_event_bridge.v1"

    return {
        "schema_version": "atlas.voice_realtime.bridge_contract_report.v1",
        "status": "valid" if schema_valid and not missing_callbacks and not invalid_guardrails else "invalid",
        "bridge_schema_valid": schema_valid,
        "supported_callback_count": len(callbacks),
        "missing_callbacks": missing_callbacks,
        "invalid_guardrails": invalid_guardrails,
        "required_guardrails": required_guardrails,
    }
