from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

from atlas_voice_agent.preflight import run_runtime_preflight

from test_contract import manifest


def write_manifest(payload: dict | None = None) -> Path:
    handle = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
    json.dump(payload or manifest(), handle)
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


class AtlasVoiceRuntimePreflightTest(unittest.TestCase):
    def test_preflight_accepts_valid_environment_without_starting_worker(self) -> None:
        payload = run_runtime_preflight(env=env(write_manifest()))

        self.assertIn(payload["status"], ["ready", "blocked"])
        self.assertEqual("atlas.voice_realtime.runtime_preflight.v1", payload["schema_version"])
        self.assertTrue(payload["kernel_only"])
        self.assertTrue(payload["settings_loaded"])
        self.assertTrue(payload["contract_loaded"])
        self.assertFalse(payload["guardrails"]["direct_provider_call_allowed"])
        self.assertFalse(payload["guardrails"]["raw_audio_persistence_allowed"])
        self.assertIn(payload["next_action"], [
            "optional_install_livekit_agents_sdk",
            "install_livekit_agents_sdk",
            "start_worker_check",
        ])

    def test_preflight_blocks_when_required_environment_is_missing(self) -> None:
        payload = env(write_manifest())
        payload.pop("ATLAS_TOKEN")
        result = run_runtime_preflight(env=payload)

        self.assertEqual("blocked", result["status"])
        self.assertFalse(result["settings_loaded"])
        self.assertFalse(result["contract_loaded"])
        self.assertEqual("fix_runtime_environment", result["next_action"])
        self.assertTrue(result["errors"])

    def test_preflight_can_require_optional_livekit_sdk(self) -> None:
        payload = run_runtime_preflight(env=env(write_manifest()), require_sdk=True)

        self.assertEqual("atlas.voice_realtime.runtime_preflight.v1", payload["schema_version"])
        if payload["sdk_status"]["status"] == "ready":
            self.assertEqual("ready", payload["status"])
        else:
            self.assertEqual("blocked", payload["status"])
            self.assertEqual("install_livekit_agents_sdk", payload["next_action"])


if __name__ == "__main__":
    unittest.main()
