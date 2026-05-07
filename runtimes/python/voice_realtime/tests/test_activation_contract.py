from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

from atlas_voice_agent.activation_contract import build_activation_contract
from atlas_voice_agent.contract import AtlasVoiceRuntimeContract

from test_contract import manifest


def write_manifest() -> Path:
    handle = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
    json.dump(manifest(), handle)
    handle.close()

    return Path(handle.name)


def env(path: Path) -> dict[str, str]:
    return {
        "ATLAS_BASE_URL": "http://atlas.test",
        "ATLAS_TOKEN": "atlas-token",
        "LIVEKIT_URL": "http://livekit.test",
        "LIVEKIT_API_KEY": "livekit-key",
        "LIVEKIT_API_SECRET": "livekit-secret",
        "ATLAS_VOICE_BOOTSTRAP": str(path),
    }


class AtlasVoiceActivationContractTest(unittest.TestCase):
    def test_activation_contract_blocks_until_all_startup_gates_are_ready(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = build_activation_contract(contract, env=env(write_manifest()))

        self.assertEqual("atlas.voice_realtime.activation_contract.v1", payload["schema_version"])
        self.assertIn(payload["status"], ["blocked", "ready_to_start_worker"])
        self.assertTrue(payload["kernel_only"])
        self.assertTrue(payload["mobile_first"])
        self.assertIn("atlas:ai:voice preflight --require-sdk --json", payload["required_sequence"])
        self.assertIn("sdk_callback_direct_to_provider", payload["forbidden_shortcuts"])
        self.assertIn("start_worker_before_callback_loop_wired", payload["forbidden_shortcuts"])
        self.assertFalse(payload["gates"]["settings_loaded"])
        self.assertFalse(payload["gates"]["boundary_created"])
        self.assertFalse(payload["gates"]["callback_loop_wired"])
        self.assertIn(payload["next_action"], [
            "install_livekit_agents_sdk",
            "load_runtime_settings_and_kernel_boundary",
            "wire_real_sdk_callback_loop",
        ])

    def test_activation_contract_never_allows_mock_kernel(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = build_activation_contract(
            contract,
            env=env(write_manifest()),
            settings_loaded=True,
            boundary_created=True,
            mock_kernel=True,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertFalse(payload["gates"]["real_kernel_required"])
        self.assertIn(payload["next_action"], [
            "install_livekit_agents_sdk",
            "wire_real_sdk_callback_loop",
            "use_real_kernel_not_mock",
        ])


if __name__ == "__main__":
    unittest.main()
