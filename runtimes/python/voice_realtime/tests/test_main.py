from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

from atlas_voice_agent.livekit_boundary import LiveKitAgentBoundary
from atlas_voice_agent.main import create_atlas_voice_agent, create_atlas_voice_agent_from_settings
from atlas_voice_agent.settings import AtlasVoiceRuntimeSettings

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


def write_env_file(bootstrap_path: Path) -> Path:
    handle = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
    handle.write("\n".join(f"{key}={value}" for key, value in env(bootstrap_path).items()))
    handle.close()

    return Path(handle.name)


class AtlasVoiceMainEntrypointTest(unittest.TestCase):
    def test_manifest_factory_preserves_contract_check_mode(self) -> None:
        contract = create_atlas_voice_agent(manifest())

        self.assertEqual("http://atlas.test/ai/voice/turn", contract.turn_url)

    def test_settings_factory_builds_livekit_boundary_without_direct_provider_authority(self) -> None:
        path = write_manifest()
        settings = AtlasVoiceRuntimeSettings.from_env(env(path))
        boundary = create_atlas_voice_agent_from_settings(
            settings,
            post_json=lambda url, payload: {
                "status": "turn_accepted_scaffold",
                "turn": {"decision_receipt": {"receipt_id": "receipt_1"}},
            },
        )

        self.assertIsInstance(boundary, LiveKitAgentBoundary)
        self.assertEqual("http://atlas.test/ai/voice/turn", boundary.runtime.client.contract.turn_url)

    def test_settings_factory_accepts_env_file_loaded_settings(self) -> None:
        path = write_manifest()
        settings = AtlasVoiceRuntimeSettings.from_env_file(
            path=write_env_file(path),
            env={},
        )
        boundary = create_atlas_voice_agent_from_settings(
            settings,
            post_json=lambda url, payload: {
                "status": "turn_accepted_scaffold",
                "turn": {"decision_receipt": {"receipt_id": "receipt_1"}},
            },
        )

        self.assertIsInstance(boundary, LiveKitAgentBoundary)


if __name__ == "__main__":
    unittest.main()
