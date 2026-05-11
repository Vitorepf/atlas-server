from __future__ import annotations

from typing import Any, Mapping

from .payload_safety import reject_forbidden_keys_recursive
from .turn_payload import UnsafeVoicePayload


class SupervisedLaunchPacketViolation(RuntimeError):
    """Raised when a launch-boundary packet weakens runtime safety."""


FORBIDDEN_SUPERVISED_LAUNCH_KEYS = {
    "access_token",
    "api_key",
    "api_secret",
    "audio",
    "audio_bytes",
    "audio_raw",
    "direct_llm_provider_call",
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


def validate_supervised_launch_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.supervised_launch_execution.v1")
    _expect("launch_execution_implemented", payload.get("launch_execution_implemented"), True)
    _expect("subprocess_launch_implemented", payload.get("subprocess_launch_implemented"), False)
    _expect("process_launch_attempted", payload.get("process_launch_attempted"), False)
    _expect("daemon_started", payload.get("daemon_started"), False)
    _expect("launch_allowed", payload.get("launch_allowed"), False)
    _expect("process_launch_allowed", payload.get("process_launch_allowed"), False)
    _expect("subprocess_module_imported", payload.get("subprocess_module_imported"), False)
    _expect("livekit_sdk_imported", payload.get("livekit_sdk_imported"), False)
    _expect("pre_start_health_checks_execution_available", payload.get("pre_start_health_checks_execution_available"), True)
    _expect("pre_start_health_checks_executed", payload.get("pre_start_health_checks_executed"), False)

    gates = _expect_mapping("gates", payload.get("gates"))
    for key in [
        "process_launch_disabled",
        "secrets_redacted",
        "subprocess_import_disabled",
        "pre_start_health_checks_execution_available",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    return payload


def validate_pre_start_health_checks_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.pre_start_health_checks_execution.v1")
    _expect("health_checks_executed", payload.get("health_checks_executed"), True)
    _expect("pre_start_health_checks_executed", payload.get("pre_start_health_checks_executed"), True)
    _expect("process_launch_attempted", payload.get("process_launch_attempted"), False)
    _expect("daemon_started", payload.get("daemon_started"), False)
    _expect("subprocess_module_imported", payload.get("subprocess_module_imported"), False)
    _expect("livekit_sdk_imported", payload.get("livekit_sdk_imported"), False)
    _expect("provider_calls_made", payload.get("provider_calls_made"), False)
    _expect("tool_calls_made", payload.get("tool_calls_made"), False)
    _expect("raw_audio_touched", payload.get("raw_audio_touched"), False)

    gates = _expect_mapping("gates", payload.get("gates"))
    _expect("gates.process_launch_disabled", gates.get("process_launch_disabled"), True)
    _expect("gates.provider_calls_forbidden", gates.get("provider_calls_forbidden"), True)
    _expect("gates.raw_audio_forbidden", gates.get("raw_audio_forbidden"), True)

    return payload


def validate_subprocess_start_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.subprocess_start_contract.v1")
    _expect("subprocess_start_contract_implemented", payload.get("subprocess_start_contract_implemented"), True)
    _expect("subprocess_launch_implemented", payload.get("subprocess_launch_implemented"), False)
    _expect("process_launch_attempted", payload.get("process_launch_attempted"), False)
    _expect("daemon_started", payload.get("daemon_started"), False)
    _expect("launch_allowed", payload.get("launch_allowed"), False)
    _expect("process_launch_allowed", payload.get("process_launch_allowed"), False)
    _expect("subprocess_module_imported", payload.get("subprocess_module_imported"), False)
    _expect("livekit_sdk_imported", payload.get("livekit_sdk_imported"), False)
    _expect("provider_calls_made", payload.get("provider_calls_made"), False)
    _expect("tool_calls_made", payload.get("tool_calls_made"), False)
    _expect("raw_audio_touched", payload.get("raw_audio_touched"), False)

    gates = _expect_mapping("gates", payload.get("gates"))
    for key in [
        "process_launch_disabled",
        "secrets_redacted",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    return payload


def validate_reviewed_subprocess_start_execution_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.reviewed_subprocess_start_execution.v1")
    _expect("reviewed_subprocess_start_execution_implemented", payload.get("reviewed_subprocess_start_execution_implemented"), True)
    _expect("real_subprocess_start_implemented", payload.get("real_subprocess_start_implemented"), False)
    _expect("subprocess_launch_implemented", payload.get("subprocess_launch_implemented"), False)
    _expect("process_launch_attempted", payload.get("process_launch_attempted"), False)
    _expect("daemon_started", payload.get("daemon_started"), False)
    _expect("launch_allowed", payload.get("launch_allowed"), False)
    _expect("process_launch_allowed", payload.get("process_launch_allowed"), False)
    _expect("subprocess_module_imported", payload.get("subprocess_module_imported"), False)
    _expect("livekit_sdk_imported", payload.get("livekit_sdk_imported"), False)
    _expect("provider_calls_made", payload.get("provider_calls_made"), False)
    _expect("tool_calls_made", payload.get("tool_calls_made"), False)
    _expect("raw_audio_touched", payload.get("raw_audio_touched"), False)

    gates = _expect_mapping("gates", payload.get("gates"))
    for key in [
        "process_launch_disabled",
        "real_start_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
        "rollback_guard_required",
        "pid_file_guard_required",
        "stdout_stderr_sanitization_required",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    return payload


def validate_real_start_adapter_disabled_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.real_start_adapter_disabled.v1")
    _expect("real_start_adapter_contract_implemented", payload.get("real_start_adapter_contract_implemented"), True)
    _expect("real_start_adapter_enabled", payload.get("real_start_adapter_enabled"), False)
    _expect("real_subprocess_start_implemented", payload.get("real_subprocess_start_implemented"), False)
    _expect("process_launch_attempted", payload.get("process_launch_attempted"), False)
    _expect("daemon_started", payload.get("daemon_started"), False)
    _expect("launch_allowed", payload.get("launch_allowed"), False)
    _expect("process_launch_allowed", payload.get("process_launch_allowed"), False)
    _expect("subprocess_module_imported", payload.get("subprocess_module_imported"), False)
    _expect("livekit_sdk_imported", payload.get("livekit_sdk_imported"), False)
    _expect("provider_calls_made", payload.get("provider_calls_made"), False)
    _expect("tool_calls_made", payload.get("tool_calls_made"), False)
    _expect("raw_audio_touched", payload.get("raw_audio_touched"), False)

    gates = _expect_mapping("gates", payload.get("gates"))
    for key in [
        "start_disabled_by_default",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
        "fresh_receipt_required",
        "human_review_required",
        "rollback_required",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    return payload


def validate_real_start_adapter_enablement_gate_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.real_start_enablement_gate.v1")
    _expect("real_start_enablement_gate_implemented", payload.get("real_start_enablement_gate_implemented"), True)
    _expect("real_start_adapter_enabled", payload.get("real_start_adapter_enabled"), False)
    _expect("start_execution_allowed", payload.get("start_execution_allowed"), False)
    _expect("real_subprocess_start_implemented", payload.get("real_subprocess_start_implemented"), False)
    _expect("process_launch_attempted", payload.get("process_launch_attempted"), False)
    _expect("daemon_started", payload.get("daemon_started"), False)
    _expect("launch_allowed", payload.get("launch_allowed"), False)
    _expect("process_launch_allowed", payload.get("process_launch_allowed"), False)
    _expect("subprocess_module_imported", payload.get("subprocess_module_imported"), False)
    _expect("livekit_sdk_imported", payload.get("livekit_sdk_imported"), False)
    _expect("provider_calls_made", payload.get("provider_calls_made"), False)
    _expect("tool_calls_made", payload.get("tool_calls_made"), False)
    _expect("raw_audio_touched", payload.get("raw_audio_touched"), False)

    gates = _expect_mapping("gates", payload.get("gates"))
    for key in [
        "policy_patch_required",
        "human_review_required",
        "rollback_required",
        "fresh_receipt_required",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    return payload


def validate_runtime_policy_enablement_review_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.runtime_policy_enablement_review.v1")
    _expect("runtime_policy_enablement_review_implemented", payload.get("runtime_policy_enablement_review_implemented"), True)
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), False)
    _expect("real_start_adapter_enabled", payload.get("real_start_adapter_enabled"), False)
    _expect("start_execution_allowed", payload.get("start_execution_allowed"), False)
    _expect("real_subprocess_start_implemented", payload.get("real_subprocess_start_implemented"), False)
    _expect("process_launch_attempted", payload.get("process_launch_attempted"), False)
    _expect("daemon_started", payload.get("daemon_started"), False)
    _expect("launch_allowed", payload.get("launch_allowed"), False)
    _expect("process_launch_allowed", payload.get("process_launch_allowed"), False)
    _expect("subprocess_module_imported", payload.get("subprocess_module_imported"), False)
    _expect("livekit_sdk_imported", payload.get("livekit_sdk_imported"), False)
    _expect("provider_calls_made", payload.get("provider_calls_made"), False)
    _expect("tool_calls_made", payload.get("tool_calls_made"), False)
    _expect("raw_audio_touched", payload.get("raw_audio_touched"), False)

    gates = _expect_mapping("gates", payload.get("gates"))
    for key in [
        "human_review_required",
        "rollback_required",
        "fresh_receipt_required",
        "runtime_policy_start_disabled",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    return payload


def validate_real_start_adapter_review_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.real_start_adapter_review_contract.v1")
    _expect("real_start_adapter_review_contract_implemented", payload.get("real_start_adapter_review_contract_implemented"), True)
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), False)
    _expect("real_start_adapter_enabled", payload.get("real_start_adapter_enabled"), False)
    _expect("start_execution_allowed", payload.get("start_execution_allowed"), False)
    _expect("real_subprocess_start_implemented", payload.get("real_subprocess_start_implemented"), False)
    _expect("process_launch_attempted", payload.get("process_launch_attempted"), False)
    _expect("daemon_started", payload.get("daemon_started"), False)
    _expect("launch_allowed", payload.get("launch_allowed"), False)
    _expect("process_launch_allowed", payload.get("process_launch_allowed"), False)
    _expect("subprocess_module_imported", payload.get("subprocess_module_imported"), False)
    _expect("livekit_sdk_imported", payload.get("livekit_sdk_imported"), False)
    _expect("provider_calls_made", payload.get("provider_calls_made"), False)
    _expect("tool_calls_made", payload.get("tool_calls_made"), False)
    _expect("raw_audio_touched", payload.get("raw_audio_touched"), False)

    gates = _expect_mapping("gates", payload.get("gates"))
    for key in [
        "pid_file_guard_required",
        "startup_timeout_required",
        "post_start_health_probe_required",
        "stdout_stderr_sanitization_required",
        "rollback_required",
        "fresh_receipt_required",
        "runtime_policy_start_disabled",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    return payload


def validate_reviewed_real_start_execution_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.reviewed_real_start_execution_contract.v1")
    _expect("reviewed_real_start_execution_contract_implemented", payload.get("reviewed_real_start_execution_contract_implemented"), True)
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), False)
    _expect("real_start_adapter_enabled", payload.get("real_start_adapter_enabled"), False)
    _expect("start_execution_allowed", payload.get("start_execution_allowed"), False)
    _expect("real_subprocess_start_implemented", payload.get("real_subprocess_start_implemented"), False)
    _expect("process_launch_attempted", payload.get("process_launch_attempted"), False)
    _expect("daemon_started", payload.get("daemon_started"), False)
    _expect("launch_allowed", payload.get("launch_allowed"), False)
    _expect("process_launch_allowed", payload.get("process_launch_allowed"), False)
    _expect("subprocess_module_imported", payload.get("subprocess_module_imported"), False)
    _expect("livekit_sdk_imported", payload.get("livekit_sdk_imported"), False)
    _expect("provider_calls_made", payload.get("provider_calls_made"), False)
    _expect("tool_calls_made", payload.get("tool_calls_made"), False)
    _expect("raw_audio_touched", payload.get("raw_audio_touched"), False)

    gates = _expect_mapping("gates", payload.get("gates"))
    for key in [
        "final_pre_start_receipt_required",
        "post_start_ready_event_required",
        "rollback_rehearsal_required",
        "runtime_policy_start_disabled",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    return payload


def _reject_forbidden(payload: Mapping[str, Any]) -> None:
    try:
        reject_forbidden_keys_recursive(
            payload,
            FORBIDDEN_SUPERVISED_LAUNCH_KEYS,
            label="supervised_launch",
        )
    except UnsafeVoicePayload as exc:
        raise SupervisedLaunchPacketViolation(str(exc)) from exc


def _expect(path: str, actual: Any, expected: Any) -> None:
    if actual != expected:
        raise SupervisedLaunchPacketViolation(f"{path} expected {expected!r}, got {actual!r}")


def _expect_mapping(path: str, value: Any) -> Mapping[str, Any]:
    if not isinstance(value, Mapping):
        raise SupervisedLaunchPacketViolation(f"{path} must be an object")

    return value
