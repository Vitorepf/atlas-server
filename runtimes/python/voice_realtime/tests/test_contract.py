from __future__ import annotations

import copy
import unittest

from atlas_voice_agent.callback_contract import REQUIRED_CALLBACK_PAYLOAD_SCHEMAS
from atlas_voice_agent.contract import AtlasVoiceRuntimeContract, ContractViolation


def manifest() -> dict:
    return {
        "schema_version": "atlas.voice_realtime.runtime_bootstrap.v1",
        "status": "ready",
        "surface_id": "voice_realtime",
        "runtime_id": "livekit_agents_sdk",
        "runtime_family": "python_ai_data",
        "entrypoint": {
            "kind": "livekit_agents_sdk",
            "module": "atlas_voice_agent.main",
            "factory": "create_atlas_voice_agent",
        },
        "kernel": {
            "base_url": "http://atlas.test",
            "contract_url": "http://atlas.test/ai/voice/runtime/contract?runtime=livekit_agents_sdk",
            "session_start_url": "http://atlas.test/ai/voice/session/start",
            "session_end_url": "http://atlas.test/ai/voice/session/end",
            "readiness_url": "http://atlas.test/ai/voice/readiness",
            "rivals_url": "http://atlas.test/ai/voice/rivals",
            "wake_word_url": "http://atlas.test/ai/voice/wake-word",
            "mobile_wake_word_url": "http://atlas.test/v1/mobile/ai/voice/wake-word",
            "turn_url": "http://atlas.test/ai/voice/turn",
            "callbacks": {
                "turn_synthesized": "http://atlas.test/ai/voice/turn/synthesized",
                "turn_played": "http://atlas.test/ai/voice/turn/played",
                "turn_interrupted": "http://atlas.test/ai/voice/turn/interrupted",
                "runtime_failed": "http://atlas.test/ai/voice/runtime/failed",
                "provider_health_degraded": "http://atlas.test/ai/voice/provider/health-degraded",
            },
        },
        "required_env": [
            "ATLAS_BASE_URL",
            "ATLAS_TOKEN",
            "LIVEKIT_URL",
            "LIVEKIT_API_KEY",
            "LIVEKIT_API_SECRET",
            "ATLAS_VOICE_BOOTSTRAP",
        ],
        "forbidden_capabilities": [
            "direct_llm_provider_call",
            "direct_tool_execution",
            "memory_write",
            "policy_override",
            "raw_audio_persistence",
            "raw_transcript_persistence",
        ],
        "default_providers": {
            "llm": "atlas_kernel_only",
        },
        "session_lease": {
            "schema_version": "atlas.voice.session_lease.v1",
            "default_mode": "mobile_push_to_talk",
            "ttl_seconds": 900,
            "token_status": "not_issued_scaffold",
            "room_prefix": "atlas-voice-",
            "livekit_url": "http://livekit.test",
            "kernel_decision_required_per_turn": True,
        },
        "persistence_contract": {
            "raw_audio": False,
            "raw_transcript": False,
            "raw_response_text": False,
        },
        "callback_payload_schemas": REQUIRED_CALLBACK_PAYLOAD_SCHEMAS,
        "auth_contract": {
            "internal_api": {
                "middleware": "atlas.token",
            },
        },
        "runtime_invocation_contract": {
            "schema_version": "atlas.runtime_invocation_contract.v1",
            "kernel_first": True,
            "selected_runtime_family": "python_ai_data",
            "runtime_id": "livekit_agents_sdk",
            "required_fields": [
                "envelope_id",
                "decision_receipt_hash",
                "runtime_family",
                "capability",
                "mode",
                "limits",
                "privacy_class",
                "evidence_sink",
            ],
            "forbidden_runtime_authority": [
                "choose_provider_or_model",
                "choose_domain_or_flow",
                "mutate_policy",
                "write_memory_directly",
                "bypass_evidence_ledger",
                "create_parallel_context_store",
            ],
        },
        "allowlists": {
            "client_surfaces": ["mobile", "mac_edge"],
            "transports": ["mobile_push_to_talk", "livekit_webrtc"],
            "runtimes": ["livekit_agents_sdk"],
            "privacy_classes": ["p1_public", "p2_internal", "p3_audio", "p4_secret"],
        },
        "contract_hash": "abc123",
    }


class AtlasVoiceRuntimeContractTest(unittest.TestCase):
    def test_accepts_kernel_bootstrap_manifest(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())

        self.assertEqual("http://atlas.test/ai/voice/session/start", contract.session_start_url)
        self.assertEqual("http://atlas.test/ai/voice/session/end", contract.session_end_url)
        self.assertEqual("http://atlas.test/ai/voice/readiness", contract.readiness_url)
        self.assertEqual("http://atlas.test/ai/voice/rivals", contract.rivals_url)
        self.assertEqual("http://atlas.test/ai/voice/turn", contract.turn_url)
        self.assertEqual("atlas-voice-", contract.room_prefix)
        self.assertEqual("http://livekit.test", contract.livekit_url)
        self.assertEqual("http://atlas.test/ai/voice/turn/synthesized", contract.synthesized_url)
        self.assertEqual("http://atlas.test/ai/voice/turn/interrupted", contract.interrupted_url)
        self.assertEqual("http://atlas.test/ai/voice/provider/health-degraded", contract.provider_health_degraded_url)

    def test_accepts_bootstrap_contract_that_issues_token_only_at_session_start(self) -> None:
        payload = copy.deepcopy(manifest())
        payload["session_lease"]["token_status"] = "issued_when_session_starts"

        contract = AtlasVoiceRuntimeContract.from_manifest(payload)

        self.assertEqual("atlas-voice-", contract.room_prefix)

    def test_rejects_direct_provider_authority(self) -> None:
        payload = copy.deepcopy(manifest())
        payload["default_providers"]["llm"] = "openai_direct"

        with self.assertRaises(ContractViolation):
            AtlasVoiceRuntimeContract.from_manifest(payload)

    def test_rejects_missing_forbidden_capability(self) -> None:
        payload = copy.deepcopy(manifest())
        payload["forbidden_capabilities"].remove("direct_tool_execution")

        with self.assertRaises(ContractViolation):
            AtlasVoiceRuntimeContract.from_manifest(payload)

    def test_rejects_runtime_token_issuer_pretending_ready(self) -> None:
        payload = copy.deepcopy(manifest())
        payload["session_lease"]["token_status"] = "issued"

        with self.assertRaises(ContractViolation):
            AtlasVoiceRuntimeContract.from_manifest(payload)

    def test_rejects_room_prefix_outside_atlas_voice_namespace(self) -> None:
        payload = copy.deepcopy(manifest())
        payload["session_lease"]["room_prefix"] = "rogue-voice-"

        with self.assertRaises(ContractViolation):
            AtlasVoiceRuntimeContract.from_manifest(payload)

    def test_rejects_raw_audio_persistence(self) -> None:
        payload = copy.deepcopy(manifest())
        payload["persistence_contract"]["raw_audio"] = True

        with self.assertRaises(ContractViolation):
            AtlasVoiceRuntimeContract.from_manifest(payload)

    def test_rejects_drifted_callback_payload_schema(self) -> None:
        payload = copy.deepcopy(manifest())
        payload["callback_payload_schemas"]["transcript_final"]["required"] = ["session_id", "turn_id"]

        with self.assertRaises(ContractViolation):
            AtlasVoiceRuntimeContract.from_manifest(payload)

    def test_rejects_manifest_without_kernel_allowlists(self) -> None:
        payload = copy.deepcopy(manifest())
        payload["allowlists"]["runtimes"] = ["livekit_agents_sdk", "rogue_runtime"]

        with self.assertRaises(ContractViolation):
            AtlasVoiceRuntimeContract.from_manifest(payload)

    def test_rejects_kernel_url_with_control_characters(self) -> None:
        payload = copy.deepcopy(manifest())
        payload["kernel"]["turn_url"] = "http://atlas.test/ai/voice/turn\nLIVEKIT_API_SECRET=injected"

        with self.assertRaises(ContractViolation):
            AtlasVoiceRuntimeContract.from_manifest(payload)

    def test_rejects_kernel_url_without_host(self) -> None:
        payload = copy.deepcopy(manifest())
        payload["kernel"]["readiness_url"] = "https:///ai/voice/readiness"

        with self.assertRaises(ContractViolation):
            AtlasVoiceRuntimeContract.from_manifest(payload)

    def test_rejects_manifest_without_runtime_invocation_contract(self) -> None:
        payload = copy.deepcopy(manifest())
        del payload["runtime_invocation_contract"]

        with self.assertRaises(ContractViolation):
            AtlasVoiceRuntimeContract.from_manifest(payload)

    def test_rejects_runtime_invocation_contract_without_decision_receipt_hash(self) -> None:
        payload = copy.deepcopy(manifest())
        payload["runtime_invocation_contract"]["required_fields"].remove("decision_receipt_hash")

        with self.assertRaises(ContractViolation):
            AtlasVoiceRuntimeContract.from_manifest(payload)

    def test_rejects_runtime_invocation_contract_that_can_choose_provider(self) -> None:
        payload = copy.deepcopy(manifest())
        payload["runtime_invocation_contract"]["forbidden_runtime_authority"].remove("choose_provider_or_model")

        with self.assertRaises(ContractViolation):
            AtlasVoiceRuntimeContract.from_manifest(payload)


if __name__ == "__main__":
    unittest.main()
