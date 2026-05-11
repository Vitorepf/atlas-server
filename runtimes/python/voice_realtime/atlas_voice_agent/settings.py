from __future__ import annotations

import json
import os
from dataclasses import dataclass
from pathlib import Path
from typing import Mapping
from urllib.parse import urlparse

from .contract import AtlasVoiceRuntimeContract
from .kernel_client import AtlasKernelClient, GetJson, PostJson


class SettingsError(RuntimeError):
    """Raised when runtime environment is incomplete or unsafe."""


@dataclass(frozen=True)
class AtlasVoiceRuntimeSettings:
    atlas_base_url: str
    atlas_token: str
    livekit_url: str
    livekit_api_key: str
    livekit_api_secret: str
    bootstrap_path: Path
    stt_provider: str = "configurable"
    tts_provider: str = "configurable"
    local_fallback: str = "disabled"
    room_prefix: str = "atlas-voice-"

    REQUIRED_ENV = {
        "ATLAS_BASE_URL",
        "ATLAS_TOKEN",
        "LIVEKIT_URL",
        "LIVEKIT_API_KEY",
        "LIVEKIT_API_SECRET",
        "ATLAS_VOICE_BOOTSTRAP",
    }

    @classmethod
    def from_env(cls, env: Mapping[str, str] | None = None) -> "AtlasVoiceRuntimeSettings":
        env = env or os.environ
        missing = sorted(key for key in cls.REQUIRED_ENV if not str(env.get(key, "")).strip())
        if missing:
            raise SettingsError(f"missing required environment variables: {missing}")

        return cls(
            atlas_base_url=cls._url(env["ATLAS_BASE_URL"], "ATLAS_BASE_URL"),
            atlas_token=cls._secret(env["ATLAS_TOKEN"], "ATLAS_TOKEN"),
            livekit_url=cls._url(env["LIVEKIT_URL"], "LIVEKIT_URL"),
            livekit_api_key=cls._secret(env["LIVEKIT_API_KEY"], "LIVEKIT_API_KEY"),
            livekit_api_secret=cls._secret(env["LIVEKIT_API_SECRET"], "LIVEKIT_API_SECRET"),
            bootstrap_path=Path(env["ATLAS_VOICE_BOOTSTRAP"]),
            stt_provider=str(env.get("ATLAS_VOICE_STT_PROVIDER") or "configurable"),
            tts_provider=str(env.get("ATLAS_VOICE_TTS_PROVIDER") or "configurable"),
            local_fallback=str(env.get("ATLAS_VOICE_LOCAL_FALLBACK") or "disabled"),
            room_prefix=cls._room_prefix(str(env.get("ATLAS_VOICE_ROOM_PREFIX") or "atlas-voice-")),
        )

    @classmethod
    def from_env_file(
        cls,
        path: Path,
        env: Mapping[str, str] | None = None,
    ) -> "AtlasVoiceRuntimeSettings":
        file_env = cls._read_env_file(path)
        merged = dict(file_env)
        merged.update(dict(env or os.environ))

        return cls.from_env(merged)

    def load_contract(self) -> AtlasVoiceRuntimeContract:
        with self.bootstrap_path.open("r", encoding="utf-8") as handle:
            manifest = json.load(handle)
        if not isinstance(manifest, Mapping):
            raise SettingsError("bootstrap manifest must be a JSON object")
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest)
        manifest_base_url = str(manifest.get("kernel", {}).get("base_url") or "").rstrip("/")
        if manifest_base_url and manifest_base_url != self.atlas_base_url.rstrip("/"):
            raise SettingsError("ATLAS_BASE_URL does not match bootstrap manifest kernel.base_url")

        return contract

    def build_kernel_client(
        self,
        post_json: PostJson | None = None,
        get_json: GetJson | None = None,
    ) -> AtlasKernelClient:
        return AtlasKernelClient(
            contract=self.load_contract(),
            atlas_token=self.atlas_token,
            post_json=post_json,
            get_json=get_json,
        )

    @staticmethod
    def _url(value: str, key: str) -> str:
        value = value.strip().rstrip("/")
        if any(ord(char) < 32 or ord(char) == 127 for char in value):
            raise SettingsError(f"{key} cannot contain control characters")

        parsed = urlparse(value)
        if parsed.scheme not in {"http", "https"} or not parsed.netloc:
            raise SettingsError(f"{key} must be an absolute http(s) URL")

        return value

    @staticmethod
    def _room_prefix(value: str) -> str:
        value = value.strip()
        if value == "" or any(ord(char) < 32 or ord(char) == 127 for char in value):
            raise SettingsError("ATLAS_VOICE_ROOM_PREFIX cannot be empty or contain control characters")
        if not value.startswith("atlas-voice-"):
            raise SettingsError("ATLAS_VOICE_ROOM_PREFIX must start with atlas-voice-")

        return value

    @staticmethod
    def _secret(value: str, key: str) -> str:
        value = value.strip()
        if value == "":
            raise SettingsError(f"{key} cannot be empty")

        return value

    @staticmethod
    def _read_env_file(path: Path) -> dict[str, str]:
        if not path.exists():
            raise SettingsError(f"env file not found: {path}")

        parsed: dict[str, str] = {}
        with path.open("r", encoding="utf-8") as handle:
            for line_number, raw_line in enumerate(handle, start=1):
                line = raw_line.strip()
                if line == "" or line.startswith("#"):
                    continue
                if "=" not in line:
                    raise SettingsError(f"invalid env file line {line_number}: missing '='")

                key, value = line.split("=", 1)
                key = key.strip()
                value = value.strip().strip('"').strip("'")
                if key == "":
                    raise SettingsError(f"invalid env file line {line_number}: empty key")

                parsed[key] = value

        return parsed
