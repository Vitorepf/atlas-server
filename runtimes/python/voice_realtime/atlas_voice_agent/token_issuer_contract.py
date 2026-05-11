from __future__ import annotations

from typing import Any, Mapping


PLAN_SCHEMA_VERSION = "atlas.voice_realtime.livekit_token_issuer_config_plan.v1"
SMOKE_SCHEMA_VERSION = "atlas.voice_realtime.livekit_token_issuer_smoke.v1"
READINESS_SCHEMA_VERSION = "atlas.voice_realtime.livekit_token_issuer_readiness.v1"


class TokenIssuerContractViolation(RuntimeError):
    """Raised when the Kernel token issuer contract is unsafe for runtime use."""


def validate_token_issuer_plan(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _expect(payload, "schema_version", PLAN_SCHEMA_VERSION)
    _expect(payload, "surface_id", "voice_realtime")
    _expect(payload, "runtime_id", "livekit_agents_sdk")
    _expect(payload, "mobile_first", True)
    _expect(payload, "kernel_only", True)
    _expect(payload, "readiness.schema_version", READINESS_SCHEMA_VERSION)
    _expect(payload, "readiness.secrets_exposed", False)
    _expect(payload, "security_contract.secrets_exposed", False)
    _expect(payload, "security_contract.writes_env_file", False)
    _expect(payload, "security_contract.starts_daemon", False)
    _expect(payload, "security_contract.issues_token_during_plan", False)
    _expect(payload, "security_contract.raw_audio_persistence_allowed", False)
    _expect(payload, "security_contract.direct_provider_call_allowed", False)
    _expect(payload, "security_contract.direct_tool_execution_allowed", False)

    required_env = payload.get("required_env")
    if not isinstance(required_env, list) or len(required_env) < 5:
        raise TokenIssuerContractViolation("required_env must list the LiveKit token issuer environment contract")

    env_names = {
        str(item.get("name"))
        for item in required_env
        if isinstance(item, Mapping) and isinstance(item.get("name"), str)
    }
    required_names = {
        "ATLAS_VOICE_LIVEKIT_TOKEN_ISSUER_ENABLED",
        "LIVEKIT_URL",
        "LIVEKIT_API_KEY",
        "LIVEKIT_API_SECRET",
        "ATLAS_VOICE_LIVEKIT_TOKEN_TTL_SECONDS",
    }
    missing = sorted(required_names - env_names)
    if missing:
        raise TokenIssuerContractViolation(f"required_env missing names: {missing}")

    template = payload.get("redacted_env_template")
    if not isinstance(template, list) or not template:
        raise TokenIssuerContractViolation("redacted_env_template is required")

    for line in template:
        if not isinstance(line, str):
            raise TokenIssuerContractViolation("redacted_env_template entries must be strings")
        if _looks_like_unredacted_secret(line):
            raise TokenIssuerContractViolation("redacted_env_template appears to contain an unredacted secret")

    template_hash = payload.get("redacted_env_template_hash")
    if not _is_lowercase_sha256(template_hash):
        raise TokenIssuerContractViolation("redacted_env_template_hash must be lowercase sha256")

    return payload


def validate_token_issuer_smoke(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _expect(payload, "schema_version", SMOKE_SCHEMA_VERSION)
    _expect(payload, "surface_id", "voice_realtime")
    _expect(payload, "runtime_id", "livekit_agents_sdk")
    _expect(payload, "mobile_first", True)
    _expect(payload, "kernel_only", True)
    _expect(payload, "readiness.schema_version", READINESS_SCHEMA_VERSION)
    _expect(payload, "readiness.secrets_exposed", False)
    _expect(payload, "access_token_exposed", False)
    _expect(payload, "security_contract.secrets_exposed", False)
    _expect(payload, "security_contract.access_token_exposed", False)
    _expect(payload, "security_contract.writes_env_file", False)
    _expect(payload, "security_contract.starts_daemon", False)
    _expect(payload, "security_contract.raw_audio_persistence_allowed", False)
    _expect(payload, "security_contract.direct_provider_call_allowed", False)
    _expect(payload, "security_contract.direct_tool_execution_allowed", False)
    _expect(payload, "security_contract.ephemeral_config_persists_after_command", False)

    if _contains_forbidden_runtime_secret(payload):
        raise TokenIssuerContractViolation("token issuer smoke must not expose access_token or secret values")

    lease = payload.get("smoke_lease")
    if not isinstance(lease, Mapping):
        raise TokenIssuerContractViolation("smoke_lease is required")
    room_name = lease.get("room_name")
    participant_identity = lease.get("participant_identity")
    if not isinstance(room_name, str) or not room_name.startswith("atlas-voice-"):
        raise TokenIssuerContractViolation("smoke_lease.room_name must stay inside atlas-voice- namespace")
    if not isinstance(participant_identity, str) or not participant_identity.startswith("mobile:"):
        raise TokenIssuerContractViolation("smoke_lease.participant_identity must stay inside mobile namespace")

    token_hash = payload.get("token_hash")
    if token_hash is not None and not _is_lowercase_sha256(token_hash):
        raise TokenIssuerContractViolation("token_hash must be null or lowercase sha256")

    return payload


def _expect(payload: Mapping[str, Any], path: str, expected: Any) -> None:
    actual = _get(payload, path)
    if actual != expected:
        raise TokenIssuerContractViolation(f"{path} expected {expected!r}, got {actual!r}")


def _get(payload: Mapping[str, Any], path: str) -> Any:
    value: Any = payload
    for part in path.split("."):
        if not isinstance(value, Mapping) or part not in value:
            return None
        value = value[part]

    return value


def _is_lowercase_sha256(value: Any) -> bool:
    return isinstance(value, str) and len(value) == 64 and all(char in "0123456789abcdef" for char in value)


def _looks_like_unredacted_secret(line: str) -> bool:
    if line.startswith("LIVEKIT_API_KEY=") or line.startswith("LIVEKIT_API_SECRET="):
        value = line.split("=", 1)[1].strip()
        return value != "" and "<" not in value and ">" not in value

    return False


def _contains_forbidden_runtime_secret(value: Any) -> bool:
    if isinstance(value, Mapping):
        for key, child in value.items():
            key_text = str(key)
            if key_text in {"access_token", "LIVEKIT_API_KEY", "LIVEKIT_API_SECRET"}:
                return True
            if key_text in {"api_key", "api_secret", "token"} and child not in {None, "", False}:
                return True
            if _contains_forbidden_runtime_secret(child):
                return True
    elif isinstance(value, list):
        return any(_contains_forbidden_runtime_secret(item) for item in value)

    return False
