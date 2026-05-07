from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any, Mapping

from .agent_runtime import AtlasVoiceAgentRuntime
from .contract import AtlasVoiceRuntimeContract
from .kernel_client import PostJson
from .livekit_boundary import LiveKitAgentBoundary
from .settings import AtlasVoiceRuntimeSettings


def load_manifest(path: Path) -> Mapping[str, Any]:
    with path.open("r", encoding="utf-8") as handle:
        manifest = json.load(handle)

    if not isinstance(manifest, Mapping):
        raise ValueError("bootstrap manifest must be a JSON object")

    return manifest


def create_atlas_voice_agent(manifest: Mapping[str, Any]) -> AtlasVoiceRuntimeContract:
    """Factory named in the Kernel bootstrap manifest.

    LiveKit Agents SDK will call this factory later. For now it validates the
    Kernel contract and returns the typed runtime contract.
    """

    return AtlasVoiceRuntimeContract.from_manifest(manifest)


def create_atlas_voice_agent_from_settings(
    settings: AtlasVoiceRuntimeSettings,
    post_json: PostJson | None = None,
) -> LiveKitAgentBoundary:
    """Build the governed boundary that a LiveKit worker should call."""

    client = settings.build_kernel_client(post_json=post_json)

    return LiveKitAgentBoundary(AtlasVoiceAgentRuntime(client))


def main() -> int:
    parser = argparse.ArgumentParser(description="Atlas Voice Realtime runtime scaffold")
    parser.add_argument("--bootstrap", help="Path to Kernel bootstrap manifest JSON")
    parser.add_argument("--env", action="store_true", help="Load settings from ATLAS_* and LIVEKIT_* environment variables")
    parser.add_argument("--env-file", help="Load settings from a dotenv-style file, with process env overriding file values")
    parser.add_argument("--check", action="store_true", help="Validate manifest and exit")
    args = parser.parse_args()

    settings = AtlasVoiceRuntimeSettings.from_env_file(Path(args.env_file)) if args.env_file else None
    if settings is None and args.env:
        settings = AtlasVoiceRuntimeSettings.from_env()
    boundary_created = False
    if settings is not None:
        boundary = create_atlas_voice_agent_from_settings(settings)
        contract = boundary.runtime.client.contract
        boundary_created = True
    else:
        if not args.bootstrap:
            parser.error("--bootstrap is required unless --env is used")
        contract = create_atlas_voice_agent(load_manifest(Path(args.bootstrap)))

    if args.check:
        print(json.dumps({
            "status": "ready",
            "schema_version": "atlas.voice_realtime.runtime_check.v1",
            "turn_url": contract.turn_url,
            "kernel_only": True,
            "settings_loaded": settings is not None,
            "boundary_created": boundary_created,
        }, indent=2))

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
