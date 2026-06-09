from __future__ import annotations

from typing import Any, Mapping

from .packet_validation import PacketValidator


class ManagedEnvWriterPacketViolation(RuntimeError):
    """Raised when a managed-env packet leaks secrets or weakens launch safety."""


VALIDATOR = PacketValidator(ManagedEnvWriterPacketViolation)

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
    VALIDATOR.reject_forbidden(payload, FORBIDDEN_MANAGED_ENV_KEYS, label="managed_env_writer")
    VALIDATOR.expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.managed_env_writer.v1")
    if payload.get("status") not in {"ready_for_write_implementation", "blocked"}:
        raise ManagedEnvWriterPacketViolation("status must be a governed managed-env writer state")
    VALIDATOR.expect("writer_contract_implemented", payload.get("writer_contract_implemented"), True)
    VALIDATOR.expect("write_execution_available", payload.get("write_execution_available"), True)
    VALIDATOR.expect("write_execution_implemented", payload.get("write_execution_implemented"), False)
    VALIDATOR.expect("env_file_write_attempted", payload.get("env_file_write_attempted"), False)
    VALIDATOR.expect("env_file_written", payload.get("env_file_written"), False)
    VALIDATOR.expect("env_value_logging_allowed", payload.get("env_value_logging_allowed"), False)
    VALIDATOR.expect("secret_values_present_in_output", payload.get("secret_values_present_in_output"), False)

    gates = VALIDATOR.expect_mapping("gates", payload.get("gates"))
    for key in ["write_execution_disabled", "secret_values_redacted", "process_launch_disabled"]:
        VALIDATOR.expect(f"gates.{key}", gates.get(key), True)

    if payload.get("managed_env_file_path") != "<managed-env-file>":
        raise ManagedEnvWriterPacketViolation("managed_env_file_path must remain symbolic")

    return payload


def validate_managed_env_write_execution_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    VALIDATOR.reject_forbidden(payload, FORBIDDEN_MANAGED_ENV_KEYS, label="managed_env_writer")
    VALIDATOR.expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.managed_env_write_execution.v1")
    if payload.get("status") not in {"written_placeholder_env", "blocked"}:
        raise ManagedEnvWriterPacketViolation("status must be a governed managed-env write state")
    VALIDATOR.expect("write_execution_implemented", payload.get("write_execution_implemented"), True)
    VALIDATOR.expect("process_launch_attempted", payload.get("process_launch_attempted"), False)
    VALIDATOR.expect("daemon_started", payload.get("daemon_started"), False)
    VALIDATOR.expect("secret_values_present_in_output", payload.get("secret_values_present_in_output"), False)

    gates = VALIDATOR.expect_mapping("gates", payload.get("gates"))
    VALIDATOR.expect("gates.process_launch_disabled", gates.get("process_launch_disabled"), True)

    if payload.get("status") == "written_placeholder_env":
        VALIDATOR.expect("env_file_write_attempted", payload.get("env_file_write_attempted"), True)
        VALIDATOR.expect("env_file_written", payload.get("env_file_written"), True)
        VALIDATOR.expect("gates.manifest_placeholders_safe", gates.get("manifest_placeholders_safe"), True)
        VALIDATOR.expect_sha256_hex(
            "content_sha256",
            payload.get("content_sha256"),
            lowercase=False,
            error_message="content_sha256 must be a hex sha256",
        )
    else:
        VALIDATOR.expect("env_file_write_attempted", payload.get("env_file_write_attempted"), False)
        VALIDATOR.expect("env_file_written", payload.get("env_file_written"), False)
        VALIDATOR.expect("target_path", payload.get("target_path"), "<blocked>")

    return payload
