from __future__ import annotations

from typing import Any, Mapping

from .kernel_client import AtlasKernelClient
from .turn_payload import UnsafeVoicePayload


class KernelRuntimeEventNormalizerGuard:
    """Kernel-owned preflight for runtime/SDK event shapes.

    The Python runtime may extract primitive fields from LiveKit callbacks, but
    the Kernel owns canonical event normalization. This guard is the reusable
    boundary for both smoke tests and the future real SDK loop.
    """

    def __init__(self, client: AtlasKernelClient) -> None:
        self.client = client

    def assert_event_valid(self, event: Mapping[str, Any]) -> Mapping[str, Any]:
        report = kernel_normalizer_contract_report(self.client.normalize_runtime_event(event))
        if report["status"] != "valid":
            raise UnsafeVoicePayload("Kernel runtime event normalizer rejected SDK event")

        return report

    def assert_sequence_valid(self, events: list[Mapping[str, Any]]) -> Mapping[str, Any]:
        report = kernel_normalizer_contract_report(self.client.normalize_runtime_event_sequence(events))
        if report["status"] != "valid":
            raise UnsafeVoicePayload("Kernel runtime event normalizer rejected SDK event sequence")

        return report

    @classmethod
    def contract(cls) -> dict[str, Any]:
        return {
            "schema_version": "atlas.voice_realtime.kernel_normalizer_guard.v1",
            "status": "ready",
            "kernel_only": True,
            "mobile_first": True,
            "execution_enabled": False,
            "provider_execution_enabled": False,
            "raw_audio_persistence_allowed": False,
            "normalizes_through": [
                "AtlasKernelClient.normalize_runtime_event",
                "AtlasKernelClient.normalize_runtime_event_sequence",
            ],
            "failure_mode": "fail_closed_before_handler_registry",
        }


def kernel_normalizer_contract_report(result: Mapping[str, Any]) -> Mapping[str, Any]:
    schema_valid = result.get("schema_version") == "atlas.voice_realtime.runtime_event_normalizer.v1"
    status = str(result.get("status") or "")
    valid = result.get("valid") is True
    guardrails = result.get("contract", {}).get("guardrails", {}) if isinstance(result.get("contract"), Mapping) else {}
    invalid_guardrails = sorted(
        key
        for key in [
            "runtime_execution_enabled",
            "provider_execution_enabled",
            "raw_audio_persistence_allowed",
            "secret_persistence_allowed",
        ]
        if guardrails.get(key) is not False
    )

    return {
        "schema_version": "atlas.voice_realtime.kernel_normalizer_contract_report.v1",
        "status": "valid" if schema_valid and valid and not invalid_guardrails else "invalid",
        "normalizer_schema_valid": schema_valid,
        "normalizer_status": status,
        "normalizer_valid": valid,
        "event_count": int(result.get("event_count") or 0),
        "invalid_guardrails": invalid_guardrails,
        "errors": list(result.get("errors") or []),
    }
