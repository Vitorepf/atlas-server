from __future__ import annotations

from pathlib import Path
from typing import Any, Mapping

from .contract import ContractViolation
from .sdk_status import inspect_livekit_sdk
from .settings import AtlasVoiceRuntimeSettings, SettingsError


def run_runtime_preflight(
    *,
    env_file: Path | None = None,
    env: Mapping[str, str] | None = None,
    require_sdk: bool = False,
) -> Mapping[str, Any]:
    """Validate runtime startup inputs without starting a LiveKit worker."""

    errors: list[str] = []
    warnings: list[str] = []
    settings: AtlasVoiceRuntimeSettings | None = None
    contract = None

    try:
        settings = AtlasVoiceRuntimeSettings.from_env_file(env_file, env=env) if env_file else AtlasVoiceRuntimeSettings.from_env(env)
        contract = settings.load_contract()
    except (SettingsError, ContractViolation, ValueError) as exc:
        errors.append(str(exc))

    sdk = inspect_livekit_sdk(contract) if contract is not None else None
    if require_sdk and sdk is not None and sdk.get("status") != "ready":
        errors.append("LiveKit Agents SDK is required for this preflight but is not ready")
    if sdk is not None and sdk.get("status") != "ready":
        warnings.append(str(sdk.get("next_action") or "optional LiveKit Agents SDK is not ready"))

    status = "ready" if not errors and (not require_sdk or (sdk or {}).get("status") == "ready") else "blocked"

    return {
        "schema_version": "atlas.voice_realtime.runtime_preflight.v1",
        "status": status,
        "surface_id": "voice_realtime",
        "runtime_id": "livekit_agents_sdk",
        "kernel_only": True,
        "mobile_first": True,
        "settings_loaded": settings is not None,
        "contract_loaded": contract is not None,
        "require_sdk": require_sdk,
        "sdk_status": sdk,
        "errors": errors,
        "warnings": warnings,
        "guardrails": {
            "kernel_decides": True,
            "decision_receipt_required_per_turn": True,
            "direct_provider_call_allowed": False,
            "direct_tool_execution_allowed": False,
            "raw_audio_persistence_allowed": False,
            "raw_transcript_persistence_allowed": False,
            "access_token_log_allowed": False,
        },
        "next_action": _next_action(errors, warnings, require_sdk),
    }


def _next_action(errors: list[str], warnings: list[str], require_sdk: bool) -> str:
    sdk_next_action = _sdk_next_action_from_messages(errors + warnings)
    if errors:
        if any("LiveKit Agents SDK" in error for error in errors):
            return sdk_next_action or "install_livekit_agents_sdk"

        return "fix_runtime_environment"
    if warnings and require_sdk:
        return sdk_next_action or "install_livekit_agents_sdk"
    if warnings:
        return sdk_next_action or "optional_install_livekit_agents_sdk"

    return "start_worker_check"


def _sdk_next_action_from_messages(messages: list[str]) -> str | None:
    for message in messages:
        if "upgrade_python_runtime_for_livekit_agents_sdk" in message:
            return "upgrade_python_runtime_for_livekit_agents_sdk"
        if "upgrade_livekit_agents_sdk" in message:
            return "upgrade_livekit_agents_sdk"
        if "install_livekit_agents_sdk" in message:
            return "install_livekit_agents_sdk"

    return None
