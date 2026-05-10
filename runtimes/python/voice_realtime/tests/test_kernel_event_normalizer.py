from __future__ import annotations

import unittest
from typing import Any, Mapping

from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.kernel_client import AtlasKernelClient
from atlas_voice_agent.kernel_event_normalizer import (
    KernelRuntimeEventNormalizerGuard,
    kernel_normalizer_contract_report,
)
from atlas_voice_agent.turn_payload import UnsafeVoicePayload

from test_contract import manifest


class NormalizerTransport:
    def __init__(self, *, valid: bool = True, guardrails: Mapping[str, Any] | None = None) -> None:
        self.valid = valid
        self.guardrails = guardrails or {
            "runtime_execution_enabled": False,
            "provider_execution_enabled": False,
            "raw_audio_persistence_allowed": False,
            "secret_persistence_allowed": False,
        }
        self.calls: list[tuple[str, Mapping[str, Any]]] = []

    def __call__(self, url: str, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        self.calls.append((url, dict(payload)))

        return {
            "schema_version": "atlas.voice_realtime.runtime_event_normalizer.v1",
            "status": "normalized_sequence" if self.valid else "invalid_sequence",
            "valid": self.valid,
            "event_count": len(payload.get("events") or [payload.get("event")]),
            "errors": [] if self.valid else ["event_0:payload_session_id_required"],
            "contract": {"guardrails": self.guardrails},
        }


def guard(transport: NormalizerTransport) -> KernelRuntimeEventNormalizerGuard:
    contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
    client = AtlasKernelClient(contract=contract, atlas_token="token", post_json=transport)

    return KernelRuntimeEventNormalizerGuard(client)


class KernelRuntimeEventNormalizerGuardTest(unittest.TestCase):
    def test_contract_declares_kernel_only_fail_closed_boundary(self) -> None:
        payload = KernelRuntimeEventNormalizerGuard.contract()

        self.assertEqual("atlas.voice_realtime.kernel_normalizer_guard.v1", payload["schema_version"])
        self.assertTrue(payload["kernel_only"])
        self.assertFalse(payload["execution_enabled"])
        self.assertFalse(payload["provider_execution_enabled"])
        self.assertEqual("fail_closed_before_handler_registry", payload["failure_mode"])

    def test_assert_sequence_valid_uses_kernel_sequence_normalizer(self) -> None:
        transport = NormalizerTransport()
        payload = guard(transport).assert_sequence_valid([
            {"event_kind": "room_connected", "session_id": "voice_session"},
        ])

        self.assertEqual("valid", payload["status"])
        self.assertEqual("http://atlas.test/ai/voice/runtime/events/normalize-sequence", transport.calls[0][0])

    def test_assert_event_valid_uses_kernel_event_normalizer(self) -> None:
        transport = NormalizerTransport()
        payload = guard(transport).assert_event_valid({
            "event_kind": "room_connected",
            "session_id": "voice_session",
        })

        self.assertEqual("valid", payload["status"])
        self.assertEqual("http://atlas.test/ai/voice/runtime/events/normalize", transport.calls[0][0])

    def test_guard_fails_closed_for_invalid_kernel_result_or_guardrail_drift(self) -> None:
        with self.assertRaises(UnsafeVoicePayload):
            guard(NormalizerTransport(valid=False)).assert_sequence_valid([
                {"event_kind": "room_connected"},
            ])

        with self.assertRaises(UnsafeVoicePayload):
            guard(NormalizerTransport(guardrails={
                "runtime_execution_enabled": True,
                "provider_execution_enabled": False,
                "raw_audio_persistence_allowed": False,
                "secret_persistence_allowed": False,
            })).assert_sequence_valid([
                {"event_kind": "room_connected", "session_id": "voice_session"},
            ])

    def test_report_fails_closed_for_missing_schema_or_invalid_guardrail(self) -> None:
        payload = kernel_normalizer_contract_report({
            "schema_version": "wrong",
            "status": "normalized_sequence",
            "valid": True,
            "contract": {
                "guardrails": {
                    "runtime_execution_enabled": False,
                    "provider_execution_enabled": True,
                    "raw_audio_persistence_allowed": False,
                    "secret_persistence_allowed": False,
                },
            },
        })

        self.assertEqual("invalid", payload["status"])
        self.assertFalse(payload["normalizer_schema_valid"])
        self.assertEqual(["provider_execution_enabled"], payload["invalid_guardrails"])


if __name__ == "__main__":
    unittest.main()
