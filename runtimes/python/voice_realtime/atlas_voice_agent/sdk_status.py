from __future__ import annotations

import importlib.util
import json
import sys
from pathlib import Path
from typing import Any, Mapping

from .contract import AtlasVoiceRuntimeContract


def load_dependency_manifest(path: Path | None = None) -> Mapping[str, Any]:
    manifest_path = path or Path(__file__).resolve().parents[1] / "runtime-dependencies.json"
    with manifest_path.open("r", encoding="utf-8") as handle:
        payload = json.load(handle)

    if not isinstance(payload, Mapping):
        raise ValueError("runtime dependency manifest must be a JSON object")

    return payload


def inspect_livekit_sdk(contract: AtlasVoiceRuntimeContract) -> Mapping[str, Any]:
    """Report optional LiveKit SDK availability without importing SDK code."""

    dependency_manifest = load_dependency_manifest()
    livekit_available = importlib.util.find_spec("livekit") is not None
    agents_available = importlib.util.find_spec("livekit.agents") is not None if livekit_available else False

    return {
        "schema_version": "atlas.voice_realtime.sdk_check.v1",
        "status": "ready" if agents_available else "missing_optional_dependency",
        "runtime_id": "livekit_agents_sdk",
        "runtime_family": "python_ai_data",
        "kernel_only": True,
        "surface_id": "voice_realtime",
        "python_version": sys.version.split()[0],
        "dependency_manifest": {
            "schema_version": dependency_manifest.get("schema_version"),
            "status": dependency_manifest.get("status"),
            "core_third_party_dependencies": dependency_manifest.get("core", {}).get("third_party_dependencies", []),
            "optional_livekit_packages": dependency_manifest.get("optional_livekit", {}).get("packages", []),
            "install_command": dependency_manifest.get("optional_livekit", {}).get("install_command"),
            "activation_gate": dependency_manifest.get("optional_livekit", {}).get("activation_gate"),
        },
        "packages": {
            "livekit": livekit_available,
            "livekit.agents": agents_available,
        },
        "contract": {
            "session_start_url": contract.session_start_url,
            "turn_url": contract.turn_url,
            "wake_word_url": contract.wake_word_url,
            "runtime_requires_decision_receipt": True,
            "raw_audio_persistence_allowed": False,
        },
        "next_action": "install_livekit_agents_sdk" if not agents_available else "wire_real_sdk_callbacks",
    }
