from __future__ import annotations

from typing import Any, Mapping

from .payload_safety import reject_forbidden_keys_recursive
from .turn_payload import UnsafeVoicePayload


class ManagedEnvWriterPacketViolation(RuntimeError):
    """Raised when a managed-env packet leaks secrets or weakens launch safety."""


FORBIDDEN_MANAGED_ENV_KEYS = {
    "access_token",
    "api_key",
    "api_secret",
    "audio",
    "audio_bytes",
    "audio_raw",
    "direct_provider_call",
    "direct_tool_execution",
    "env_file_contents",
    "livekit_token",
    "memory_write",
    "pcm",
    "provider_api_key",
    "raw_audio",
    "raw_audio_bytes",
    "raw_response_text",
    "response_text",
    "secret",
    "secret_value",
    "token",
    "tool_args",
    "tool_call",
    "tts_text",
    "wav",
}


def validate_managed_env_writer_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.managed_env_writer.v1")
    if payload.get("status") not in {"ready_for_write_implementation", "blocked"}:
        raise ManagedEnvWriterPacketViolation("status must be a governed managed-env writer state")
    _expect("writer_contract_implemented", payload.get("writer_contract_implemented"), True)
    _expect("write_execution_available", payload.get("write_execution_available"), True)
    _expect("write_execution_implemented", payload.get("write_execution_implemented"), False)
    _expect("env_file_write_attempted", payload.get("env_file_write_attempted"), False)
    _expect("env_file_written", payload.get("env_file_written"), False)
    _expect("env_value_logging_allowed", payload.get("env_value_logging_allowed"), False)
    _expect("secret_values_present_in_output", payload.get("secret_values_present_in_output"), False)

    gates = _expect_mapping("gates", payload.get("gates"))
    for key in ["write_execution_disabled", "secret_values_redacted", "process_launch_disabled"]:
        _expect(f"gates.{key}", gates.get(key), True)

    if payload.get("managed_env_file_path") != "<managed-env-file>":
        raise ManagedEnvWriterPacketViolation("managed_env_file_path must remain symbolic")

    return payload


def validate_managed_env_write_execution_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.managed_env_write_execution.v1")
    if payload.get("status") not in {"written_placeholder_env", "blocked"}:
        raise ManagedEnvWriterPacketViolation("status must be a governed managed-env write state")
    _expect("write_execution_implemented", payload.get("write_execution_implemented"), True)
    _expect("process_launch_attempted", payload.get("process_launch_attempted"), False)
    _expect("daemon_started", payload.get("daemon_started"), False)
    _expect("secret_values_present_in_output", payload.get("secret_values_present_in_output"), False)

    gates = _expect_mapping("gates", payload.get("gates"))
    _expect("gates.process_launch_disabled", gates.get("process_launch_disabled"), True)

    if payload.get("status") == "written_placeholder_env":
        _expect("env_file_write_attempted", payload.get("env_file_write_attempted"), True)
        _expect("env_file_written", payload.get("env_file_written"), True)
        _expect("gates.manifest_placeholders_safe", gates.get("manifest_placeholders_safe"), True)
        digest = payload.get("content_sha256")
        if not isinstance(digest, str) or len(digest) != 64 or any(char not in "0123456789abcdef" for char in digest.lower()):
            raise ManagedEnvWriterPacketViolation("content_sha256 must be a hex sha256")
    else:
        _expect("env_file_write_attempted", payload.get("env_file_write_attempted"), False)
        _expect("env_file_written", payload.get("env_file_written"), False)
        _expect("target_path", payload.get("target_path"), "<blocked>")

    return payload


def _reject_forbidden(payload: Mapping[str, Any]) -> None:
    try:
        reject_forbidden_keys_recursive(
            payload,
            FORBIDDEN_MANAGED_ENV_KEYS,
            label="managed_env_writer",
        )
    except UnsafeVoicePayload as exc:
        raise ManagedEnvWriterPacketViolation(str(exc)) from exc


def _expect(path: str, actual: Any, expected: Any) -> None:
    if actual != expected:
        raise ManagedEnvWriterPacketViolation(f"{path} expected {expected!r}, got {actual!r}")


def _expect_mapping(path: str, value: Any) -> Mapping[str, Any]:
    if not isinstance(value, Mapping):
        raise ManagedEnvWriterPacketViolation(f"{path} must be an object")

    return value
