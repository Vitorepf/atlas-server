from __future__ import annotations

import copy
import unittest

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
        "persistence_contract": {
            "raw_audio": False,
            "raw_transcript": False,
            "raw_response_text": False,
        },
        "auth_contract": {
            "internal_api": {
                "middleware": "atlas.token",
            },
        },
        "contract_hash": "abc123",
    }


class AtlasVoiceRuntimeContractTest(unittest.TestCase):
    def test_accepts_kernel_bootstrap_manifest(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())

        self.assertEqual("http://atlas.test/ai/voice/turn", contract.turn_url)
        self.assertEqual("http://atlas.test/ai/voice/turn/synthesized", contract.synthesized_url)
        self.assertEqual("http://atlas.test/ai/voice/turn/interrupted", contract.interrupted_url)
        self.assertEqual("http://atlas.test/ai/voice/provider/health-degraded", contract.provider_health_degraded_url)

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

    def test_rejects_raw_audio_persistence(self) -> None:
        payload = copy.deepcopy(manifest())
        payload["persistence_contract"]["raw_audio"] = True

        with self.assertRaises(ContractViolation):
            AtlasVoiceRuntimeContract.from_manifest(payload)


if __name__ == "__main__":
    unittest.main()
