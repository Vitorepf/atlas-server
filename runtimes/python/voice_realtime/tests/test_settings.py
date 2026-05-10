from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

from atlas_voice_agent.settings import AtlasVoiceRuntimeSettings, SettingsError

from test_contract import manifest


def env(path: Path) -> dict[str, str]:
    return {
        "ATLAS_BASE_URL": "http://atlas.test",
        "ATLAS_TOKEN": "atlas-token",
        "LIVEKIT_URL": "http://livekit.test",
        "LIVEKIT_API_KEY": "livekit-key",
        "LIVEKIT_API_SECRET": "livekit-secret",
        "ATLAS_VOICE_BOOTSTRAP": str(path),
    }


class AtlasVoiceRuntimeSettingsTest(unittest.TestCase):
    def write_manifest(self, payload: dict | None = None) -> Path:
        handle = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
        json.dump(payload or manifest(), handle)
        handle.close()

        return Path(handle.name)

    def test_loads_settings_from_environment_and_builds_client(self) -> None:
        path = self.write_manifest()
        settings = AtlasVoiceRuntimeSettings.from_env(env(path))
        client = settings.build_kernel_client(post_json=lambda url, payload: {"url": url, "payload": dict(payload)})

        self.assertEqual("http://atlas.test", settings.atlas_base_url)
        self.assertEqual("http://livekit.test", settings.livekit_url)
        self.assertEqual("http://atlas.test/ai/voice/turn", client.contract.turn_url)

    def test_rejects_missing_required_env(self) -> None:
        payload = env(self.write_manifest())
        payload.pop("ATLAS_TOKEN")

        with self.assertRaises(SettingsError):
            AtlasVoiceRuntimeSettings.from_env(payload)

    def test_rejects_invalid_urls(self) -> None:
        payload = env(self.write_manifest())
        payload["LIVEKIT_URL"] = "localhost:7880"

        with self.assertRaises(SettingsError):
            AtlasVoiceRuntimeSettings.from_env(payload)

    def test_rejects_urls_with_control_characters(self) -> None:
        payload = env(self.write_manifest())
        payload["ATLAS_BASE_URL"] = "http://atlas.test\nLIVEKIT_API_SECRET=injected"

        with self.assertRaises(SettingsError):
            AtlasVoiceRuntimeSettings.from_env(payload)

    def test_rejects_room_prefix_outside_atlas_voice_namespace(self) -> None:
        payload = env(self.write_manifest())
        payload["ATLAS_VOICE_ROOM_PREFIX"] = "random-room-"

        with self.assertRaises(SettingsError):
            AtlasVoiceRuntimeSettings.from_env(payload)

    def test_rejects_base_url_mismatch_with_manifest(self) -> None:
        path = self.write_manifest()
        payload = env(path)
        payload["ATLAS_BASE_URL"] = "http://wrong.test"
        settings = AtlasVoiceRuntimeSettings.from_env(payload)

        with self.assertRaises(SettingsError):
            settings.load_contract()

    def test_loads_dotenv_file_with_process_env_overrides(self) -> None:
        bootstrap = self.write_manifest()
        handle = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
        handle.write("\n".join([
            "ATLAS_BASE_URL=http://wrong.test",
            "ATLAS_TOKEN=file-token",
            "LIVEKIT_URL=http://livekit.test",
            "LIVEKIT_API_KEY=livekit-key",
            "LIVEKIT_API_SECRET=livekit-secret",
            f"ATLAS_VOICE_BOOTSTRAP={bootstrap}",
            "ATLAS_VOICE_ROOM_PREFIX=atlas-voice-from-file-",
        ]))
        handle.close()

        settings = AtlasVoiceRuntimeSettings.from_env_file(
            Path(handle.name),
            env={"ATLAS_BASE_URL": "http://atlas.test"},
        )

        self.assertEqual("http://atlas.test", settings.atlas_base_url)
        self.assertEqual("atlas-voice-from-file-", settings.room_prefix)
        self.assertEqual("http://atlas.test/ai/voice/turn", settings.load_contract().turn_url)

    def test_rejects_invalid_dotenv_file_line(self) -> None:
        handle = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
        handle.write("ATLAS_BASE_URL http://atlas.test\n")
        handle.close()

        with self.assertRaises(SettingsError):
            AtlasVoiceRuntimeSettings.from_env_file(Path(handle.name), env={})


if __name__ == "__main__":
    unittest.main()
