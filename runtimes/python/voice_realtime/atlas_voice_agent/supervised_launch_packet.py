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


def validate_final_start_executor_disabled_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.final_start_executor_disabled.v1")
    _expect("final_start_executor_contract_implemented", payload.get("final_start_executor_contract_implemented"), True)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
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
        "start_disabled_by_default",
        "single_start_per_receipt_required",
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


def validate_final_start_executor_enablement_gate_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.final_start_executor_enablement_gate.v1")
    _expect("final_start_executor_enablement_gate_implemented", payload.get("final_start_executor_enablement_gate_implemented"), True)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
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
        "single_start_per_receipt_required",
        "post_start_ready_event_required",
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


def validate_supervised_start_execution_review_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.supervised_start_execution_review.v1")
    _expect("supervised_start_execution_review_implemented", payload.get("supervised_start_execution_review_implemented"), True)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
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
        "technical_review_required",
        "final_pre_start_receipt_required",
        "current_bundle_review_required",
        "single_start_per_receipt_required",
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


def validate_real_start_execution_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.real_start_execution_contract.v1")
    _expect("real_start_execution_contract_implemented", payload.get("real_start_execution_contract_implemented"), True)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
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
        "technical_review_required",
        "current_bundle_review_required",
        "single_start_per_receipt_required",
        "pid_file_guard_required",
        "startup_timeout_required",
        "stdout_stderr_sanitization_required",
        "post_start_ready_event_required",
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


def validate_guarded_start_executor_disabled_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_executor_disabled.v1")
    _expect("guarded_start_executor_contract_implemented", payload.get("guarded_start_executor_contract_implemented"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
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
        "guarded_executor_disabled_by_default",
        "pid_file_guard_required",
        "startup_timeout_required",
        "stdout_stderr_sanitization_required",
        "single_start_per_receipt_required",
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


def validate_guarded_start_executor_enablement_gate_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_executor_enablement_gate.v1")
    _expect("guarded_start_executor_enablement_gate_implemented", payload.get("guarded_start_executor_enablement_gate_implemented"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
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
        "single_start_per_receipt_required",
        "pid_file_guard_required",
        "startup_timeout_required",
        "stdout_stderr_sanitization_required",
        "post_start_ready_event_required",
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


def validate_reviewed_guarded_start_execution_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.reviewed_guarded_start_execution_contract.v1")
    _expect("reviewed_guarded_start_execution_contract_implemented", payload.get("reviewed_guarded_start_execution_contract_implemented"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
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
        "technical_review_required",
        "current_bundle_review_required",
        "single_start_per_receipt_required",
        "pid_file_guard_required",
        "startup_timeout_required",
        "stdout_stderr_sanitization_required",
        "post_start_ready_event_required",
        "rollback_rehearsal_passed",
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


def validate_guarded_start_dry_run_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_dry_run_contract.v1")
    _expect("guarded_start_dry_run_contract_implemented", payload.get("guarded_start_dry_run_contract_implemented"), True)
    _expect("dry_run_only", payload.get("dry_run_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
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
        "dry_run_only_enforced",
        "simulated_pid_file_guard_passed",
        "simulated_startup_timeout_present",
        "stdout_stderr_sanitization_simulated",
        "post_start_ready_event_simulated",
        "rollback_rehearsal_reference_attached",
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


def validate_guarded_start_simulation_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_simulation_contract.v1")
    _expect("guarded_start_simulation_contract_implemented", payload.get("guarded_start_simulation_contract_implemented"), True)
    _expect("simulation_only", payload.get("simulation_only"), True)
    _expect("dry_run_only", payload.get("dry_run_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
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
        "simulation_only_enforced",
        "synthetic_lifecycle_simulated",
        "synthetic_ready_probe_passed",
        "synthetic_exit_code_zero",
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


def validate_guarded_start_runtime_handoff_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_runtime_handoff_contract.v1")
    _expect("guarded_start_runtime_handoff_contract_implemented", payload.get("guarded_start_runtime_handoff_contract_implemented"), True)
    _expect("runtime_handoff_contract_only", payload.get("runtime_handoff_contract_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
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
        "runtime_handoff_contract_only",
        "kernel_runtime_invocation_contract_required",
        "decision_receipt_required",
        "evidence_sink_required",
        "rollback_plan_required",
        "policy_patch_review_required",
        "human_review_required",
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


def validate_guarded_start_policy_patch_review_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_policy_patch_review_contract.v1")
    _expect("guarded_start_policy_patch_review_contract_implemented", payload.get("guarded_start_policy_patch_review_contract_implemented"), True)
    _expect("policy_patch_review_only", payload.get("policy_patch_review_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
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
        "policy_patch_review_only",
        "rollback_plan_required",
        "decision_receipt_required",
        "human_review_required",
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


def validate_guarded_start_human_review_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_human_review_contract.v1")
    _expect("guarded_start_human_review_contract_implemented", payload.get("guarded_start_human_review_contract_implemented"), True)
    _expect("human_review_contract_only", payload.get("human_review_contract_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
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
        "human_review_contract_only",
        "rollback_plan_reviewed",
        "decision_receipt_required",
        "final_enablement_gate_required",
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


def validate_guarded_start_final_enablement_gate_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_final_enablement_gate.v1")
    _expect("guarded_start_final_enablement_gate_implemented", payload.get("guarded_start_final_enablement_gate_implemented"), True)
    _expect("final_enablement_gate_only", payload.get("final_enablement_gate_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
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
        "final_enablement_gate_only",
        "final_pre_start_receipt_attached",
        "single_start_per_receipt_required",
        "post_start_ready_event_required",
        "rollback_rehearsal_passed",
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


def validate_guarded_start_policy_enablement_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_policy_enablement_contract.v1")
    _expect("guarded_start_policy_enablement_contract_implemented", payload.get("guarded_start_policy_enablement_contract_implemented"), True)
    _expect("policy_enablement_contract_only", payload.get("policy_enablement_contract_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    policy_enabled = payload.get("status") == "ready_for_guarded_start_activation_contract"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), policy_enabled)
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
        "policy_enablement_contract_only",
        "final_enablement_gate_attached",
        "human_review_contract_attached",
        "rollback_plan_attached",
        "single_start_per_receipt_required",
        "post_start_ready_event_required",
        "policy_revoke_supported",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect("gates.runtime_policy_start_enabled", gates.get("runtime_policy_start_enabled"), policy_enabled)

    return payload


def validate_guarded_start_activation_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_activation_contract.v1")
    _expect("guarded_start_activation_contract_implemented", payload.get("guarded_start_activation_contract_implemented"), True)
    _expect("activation_contract_only", payload.get("activation_contract_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    activation_ready = payload.get("status") == "ready_for_guarded_start_execution_attempt_contract"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), activation_ready)
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
        "activation_contract_only",
        "policy_enablement_contract_attached",
        "final_enablement_gate_attached",
        "human_review_contract_attached",
        "activation_window_declared",
        "operator_activation_review_required",
        "post_start_observability_required",
        "rollback_plan_attached",
        "policy_revoke_supported",
        "single_start_per_receipt_required",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect("gates.runtime_policy_start_enabled", gates.get("runtime_policy_start_enabled"), activation_ready)

    return payload


def validate_guarded_start_execution_attempt_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect(
        "schema_version",
        payload.get("schema_version"),
        "atlas.voice_realtime.guarded_start_execution_attempt_contract.v1",
    )
    _expect(
        "guarded_start_execution_attempt_contract_implemented",
        payload.get("guarded_start_execution_attempt_contract_implemented"),
        True,
    )
    _expect("execution_attempt_contract_only", payload.get("execution_attempt_contract_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    execution_attempt_ready = payload.get("status") == "ready_for_guarded_start_execution_rehearsal_contract"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), execution_attempt_ready)
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
        "execution_attempt_contract_only",
        "activation_contract_attached",
        "policy_enablement_contract_attached",
        "operator_activation_review_required",
        "post_start_observability_required",
        "pid_guard_required",
        "startup_timeout_required",
        "stdout_stderr_sanitization_required",
        "ready_event_required",
        "rollback_plan_attached",
        "policy_revoke_supported",
        "single_start_per_receipt_required",
        "dry_run_rehearsal_attached",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect("gates.runtime_policy_start_enabled", gates.get("runtime_policy_start_enabled"), execution_attempt_ready)

    return payload


def validate_guarded_start_execution_rehearsal_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect(
        "schema_version",
        payload.get("schema_version"),
        "atlas.voice_realtime.guarded_start_execution_rehearsal_contract.v1",
    )
    _expect(
        "guarded_start_execution_rehearsal_contract_implemented",
        payload.get("guarded_start_execution_rehearsal_contract_implemented"),
        True,
    )
    _expect("execution_rehearsal_contract_only", payload.get("execution_rehearsal_contract_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    rehearsal_ready = payload.get("status") == "ready_for_guarded_start_observability_contract"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), rehearsal_ready)
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
        "execution_rehearsal_contract_only",
        "execution_attempt_contract_attached",
        "pid_guard_rehearsed",
        "startup_timeout_rehearsed",
        "stdout_stderr_sanitization_rehearsed",
        "ready_event_rehearsed",
        "rollback_rehearsed",
        "policy_revoke_supported",
        "single_start_per_receipt_required",
        "dry_run_rehearsal_only",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect("gates.runtime_policy_start_enabled", gates.get("runtime_policy_start_enabled"), rehearsal_ready)

    return payload


def validate_guarded_start_observability_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_observability_contract.v1")
    _expect(
        "guarded_start_observability_contract_implemented",
        payload.get("guarded_start_observability_contract_implemented"),
        True,
    )
    _expect("observability_contract_only", payload.get("observability_contract_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    observability_ready = payload.get("status") == "ready_for_guarded_start_release_candidate_contract"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), observability_ready)
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
        "observability_contract_only",
        "execution_rehearsal_contract_attached",
        "ready_event_required",
        "health_snapshot_required",
        "stderr_stdout_sanitized_required",
        "latency_slo_metrics_required",
        "rollback_telemetry_required",
        "evidence_sink_required",
        "policy_revoke_supported",
        "single_start_per_receipt_required",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect("gates.runtime_policy_start_enabled", gates.get("runtime_policy_start_enabled"), observability_ready)

    return payload


def validate_guarded_start_release_candidate_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_release_candidate_contract.v1")
    _expect(
        "guarded_start_release_candidate_contract_implemented",
        payload.get("guarded_start_release_candidate_contract_implemented"),
        True,
    )
    _expect("release_candidate_contract_only", payload.get("release_candidate_contract_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    release_candidate_ready = payload.get("status") == "ready_for_guarded_start_operator_acceptance_contract"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), release_candidate_ready)
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
        "release_candidate_contract_only",
        "observability_contract_attached",
        "bundle_hash_attached",
        "evidence_manifest_attached",
        "rollback_plan_attached",
        "operator_review_required",
        "final_start_receipt_required",
        "policy_revoke_supported",
        "single_start_per_receipt_required",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect("gates.runtime_policy_start_enabled", gates.get("runtime_policy_start_enabled"), release_candidate_ready)

    return payload


def validate_guarded_start_operator_acceptance_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_operator_acceptance_contract.v1")
    _expect(
        "guarded_start_operator_acceptance_contract_implemented",
        payload.get("guarded_start_operator_acceptance_contract_implemented"),
        True,
    )
    _expect("operator_acceptance_contract_only", payload.get("operator_acceptance_contract_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    operator_acceptance_ready = payload.get("status") == "ready_for_guarded_start_final_start_receipt_contract"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), operator_acceptance_ready)
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
        "operator_acceptance_contract_only",
        "operator_review_completed",
        "operator_acceptance_explicit",
        "release_candidate_contract_attached",
        "evidence_manifest_reviewed",
        "rollback_plan_reviewed",
        "bundle_hash_matches",
        "policy_patch_hash_matches",
        "final_start_receipt_required",
        "policy_revoke_supported",
        "single_start_per_receipt_required",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect("gates.runtime_policy_start_enabled", gates.get("runtime_policy_start_enabled"), operator_acceptance_ready)

    return payload


def validate_guarded_start_final_start_receipt_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_final_start_receipt_contract.v1")
    _expect(
        "guarded_start_final_start_receipt_contract_implemented",
        payload.get("guarded_start_final_start_receipt_contract_implemented"),
        True,
    )
    _expect("final_start_receipt_contract_only", payload.get("final_start_receipt_contract_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    final_receipt_ready = payload.get("status") == "ready_for_guarded_start_launch_window_contract"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), final_receipt_ready)
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
        "final_start_receipt_contract_only",
        "operator_acceptance_contract_attached",
        "final_start_receipt_attached",
        "receipt_fresh",
        "single_start_per_receipt_required",
        "ready_event_required",
        "rollback_plan_reviewed",
        "policy_revoke_supported",
        "bundle_hash_matches",
        "policy_patch_hash_matches",
        "operator_acceptance_receipt_matches",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect("gates.runtime_policy_start_enabled", gates.get("runtime_policy_start_enabled"), final_receipt_ready)

    return payload


def validate_guarded_start_launch_window_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_launch_window_contract.v1")
    _expect(
        "guarded_start_launch_window_contract_implemented",
        payload.get("guarded_start_launch_window_contract_implemented"),
        True,
    )
    _expect("launch_window_contract_only", payload.get("launch_window_contract_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    launch_window_ready = payload.get("status") == "ready_for_guarded_start_pre_launch_guard_contract"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), launch_window_ready)
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
        "launch_window_contract_only",
        "final_start_receipt_contract_attached",
        "launch_window_declared",
        "operator_present",
        "receipts_fresh",
        "single_start_per_receipt_required",
        "observability_armed",
        "rollback_armed",
        "policy_revoke_supported",
        "bundle_hash_matches",
        "policy_patch_hash_matches",
        "final_start_receipt_matches",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect("gates.runtime_policy_start_enabled", gates.get("runtime_policy_start_enabled"), launch_window_ready)

    return payload


def validate_guarded_start_pre_launch_guard_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_pre_launch_guard_contract.v1")
    _expect(
        "guarded_start_pre_launch_guard_contract_implemented",
        payload.get("guarded_start_pre_launch_guard_contract_implemented"),
        True,
    )
    _expect("pre_launch_guard_contract_only", payload.get("pre_launch_guard_contract_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    pre_launch_guard_ready = payload.get("status") == "ready_for_guarded_start_executor_runtime_contract"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), pre_launch_guard_ready)
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
        "pre_launch_guard_contract_only",
        "launch_window_contract_attached",
        "kernel_health_fresh",
        "token_lease_fresh",
        "callback_router_fresh",
        "observability_armed",
        "rollback_armed",
        "policy_revoke_supported",
        "operator_present",
        "final_start_receipt_matches",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect("gates.runtime_policy_start_enabled", gates.get("runtime_policy_start_enabled"), pre_launch_guard_ready)

    return payload


def validate_guarded_start_executor_runtime_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_executor_runtime_contract.v1")
    _expect(
        "guarded_start_executor_runtime_contract_implemented",
        payload.get("guarded_start_executor_runtime_contract_implemented"),
        True,
    )
    _expect("executor_runtime_contract_only", payload.get("executor_runtime_contract_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    executor_runtime_ready = payload.get("status") == "ready_for_guarded_start_process_spawn_contract"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), executor_runtime_ready)
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
        "executor_runtime_contract_only",
        "pre_launch_guard_contract_attached",
        "runtime_family_allowed",
        "env_contract_attached",
        "argv_redacted",
        "pid_guard_configured",
        "stdout_stderr_sanitized",
        "ready_event_required",
        "rollback_armed",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect("gates.runtime_policy_start_enabled", gates.get("runtime_policy_start_enabled"), executor_runtime_ready)

    return payload


def validate_guarded_start_process_spawn_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_process_spawn_contract.v1")
    _expect(
        "guarded_start_process_spawn_contract_implemented",
        payload.get("guarded_start_process_spawn_contract_implemented"),
        True,
    )
    _expect("process_spawn_contract_only", payload.get("process_spawn_contract_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    process_spawn_ready = payload.get("status") == "ready_for_guarded_start_spawn_review_contract"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), process_spawn_ready)
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
        "process_spawn_contract_only",
        "executor_runtime_contract_attached",
        "runtime_family_allowed",
        "env_contract_attached",
        "argv_redacted",
        "cwd_confined",
        "pid_guard_configured",
        "startup_timeout_configured",
        "stdout_stderr_sanitized",
        "ready_event_required",
        "rollback_armed",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect("gates.runtime_policy_start_enabled", gates.get("runtime_policy_start_enabled"), process_spawn_ready)

    return payload


def validate_guarded_start_spawn_review_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_spawn_review_contract.v1")
    _expect(
        "guarded_start_spawn_review_contract_implemented",
        payload.get("guarded_start_spawn_review_contract_implemented"),
        True,
    )
    _expect("spawn_review_contract_only", payload.get("spawn_review_contract_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    spawn_review_ready = payload.get("status") == "ready_for_guarded_start_subprocess_import_contract"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), spawn_review_ready)
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
        "spawn_review_contract_only",
        "process_spawn_contract_attached",
        "technical_review_completed",
        "bundle_hash_reviewed",
        "cwd_confined_reviewed",
        "argv_redaction_reviewed",
        "timeout_reviewed",
        "ready_event_reviewed",
        "rollback_reviewed",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect("gates.runtime_policy_start_enabled", gates.get("runtime_policy_start_enabled"), spawn_review_ready)

    return payload


def validate_guarded_start_subprocess_import_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_subprocess_import_contract.v1")
    _expect(
        "guarded_start_subprocess_import_contract_implemented",
        payload.get("guarded_start_subprocess_import_contract_implemented"),
        True,
    )
    _expect("subprocess_import_contract_only", payload.get("subprocess_import_contract_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    subprocess_import_ready = payload.get("status") == "ready_for_guarded_start_launch_invocation_contract"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), subprocess_import_ready)
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
        "subprocess_import_contract_only",
        "spawn_review_contract_attached",
        "localized_import_boundary_declared",
        "no_top_level_subprocess_import",
        "executor_only_import_required",
        "import_audit_event_required",
        "process_launch_disabled",
        "start_execution_disabled",
        "subprocess_import_not_executed",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect("gates.runtime_policy_start_enabled", gates.get("runtime_policy_start_enabled"), subprocess_import_ready)

    return payload


def validate_guarded_start_launch_invocation_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_launch_invocation_contract.v1")
    _expect(
        "guarded_start_launch_invocation_contract_implemented",
        payload.get("guarded_start_launch_invocation_contract_implemented"),
        True,
    )
    _expect("launch_invocation_contract_only", payload.get("launch_invocation_contract_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    launch_invocation_ready = payload.get("status") == "ready_for_guarded_start_final_process_start_contract"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), launch_invocation_ready)
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
        "launch_invocation_contract_only",
        "subprocess_import_contract_attached",
        "command_template_reviewed",
        "argv_redacted",
        "env_redacted",
        "cwd_confined",
        "pid_guard_required",
        "startup_timeout_required",
        "ready_event_required",
        "rollback_required",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect("gates.runtime_policy_start_enabled", gates.get("runtime_policy_start_enabled"), launch_invocation_ready)

    return payload


def validate_guarded_start_final_process_start_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_final_process_start_contract.v1")
    _expect(
        "guarded_start_final_process_start_contract_implemented",
        payload.get("guarded_start_final_process_start_contract_implemented"),
        True,
    )
    _expect("final_process_start_contract_only", payload.get("final_process_start_contract_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    final_process_start_ready = payload.get("status") == "ready_for_guarded_start_process_execution_review"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), final_process_start_ready)
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
        "final_process_start_contract_only",
        "launch_invocation_contract_attached",
        "decision_receipt_fresh",
        "single_start_per_receipt_required",
        "ready_event_required",
        "pid_guard_required",
        "startup_timeout_required",
        "stdout_stderr_sanitized",
        "rollback_required",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect("gates.runtime_policy_start_enabled", gates.get("runtime_policy_start_enabled"), final_process_start_ready)

    return payload


def validate_guarded_start_process_execution_review_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_process_execution_review.v1")
    _expect(
        "guarded_start_process_execution_review_implemented",
        payload.get("guarded_start_process_execution_review_implemented"),
        True,
    )
    _expect("process_execution_review_only", payload.get("process_execution_review_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    process_execution_review_ready = payload.get("status") == "ready_for_guarded_start_process_execution_packet"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), process_execution_review_ready)
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
        "process_execution_review_only",
        "final_process_start_contract_attached",
        "technical_review_completed",
        "receipt_bound_to_final_start",
        "pid_guard_reviewed",
        "startup_timeout_reviewed",
        "ready_event_reviewed",
        "stdout_stderr_sanitization_reviewed",
        "rollback_reviewed",
        "observability_reviewed",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect(
        "gates.runtime_policy_start_enabled",
        gates.get("runtime_policy_start_enabled"),
        process_execution_review_ready,
    )

    return payload


def validate_guarded_start_process_execution_packet_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_process_execution_packet.v1")
    _expect(
        "guarded_start_process_execution_packet_implemented",
        payload.get("guarded_start_process_execution_packet_implemented"),
        True,
    )
    _expect("process_execution_packet_only", payload.get("process_execution_packet_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    process_execution_packet_ready = payload.get("status") == "ready_for_guarded_start_process_executor_stub"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), process_execution_packet_ready)
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
        "process_execution_packet_only",
        "process_execution_review_attached",
        "receipt_bound_to_execution_packet",
        "argv_redacted",
        "env_redacted",
        "cwd_confined",
        "pid_guard_attached",
        "startup_timeout_attached",
        "ready_event_attached",
        "stdout_stderr_sanitizers_attached",
        "rollback_attached",
        "observability_attached",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect(
        "gates.runtime_policy_start_enabled",
        gates.get("runtime_policy_start_enabled"),
        process_execution_packet_ready,
    )

    return payload


def validate_guarded_start_process_executor_stub_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_process_executor_stub.v1")
    _expect(
        "guarded_start_process_executor_stub_implemented",
        payload.get("guarded_start_process_executor_stub_implemented"),
        True,
    )
    _expect("process_executor_stub_only", payload.get("process_executor_stub_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    process_executor_stub_ready = payload.get("status") == "ready_for_guarded_start_process_executor_review"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), process_executor_stub_ready)
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
        "process_executor_stub_only",
        "process_execution_packet_attached",
        "localized_subprocess_import_required",
        "pid_guard_required",
        "startup_timeout_required",
        "ready_event_required",
        "stdout_stderr_sanitizers_required",
        "rollback_required",
        "observability_required",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect(
        "gates.runtime_policy_start_enabled",
        gates.get("runtime_policy_start_enabled"),
        process_executor_stub_ready,
    )

    return payload


def validate_guarded_start_process_executor_review_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_process_executor_review.v1")
    _expect(
        "guarded_start_process_executor_review_implemented",
        payload.get("guarded_start_process_executor_review_implemented"),
        True,
    )
    _expect("process_executor_review_only", payload.get("process_executor_review_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    process_executor_review_ready = payload.get("status") == "ready_for_guarded_start_process_executor_contract"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), process_executor_review_ready)
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
        "process_executor_review_only",
        "process_executor_stub_attached",
        "technical_review_completed",
        "localized_subprocess_import_reviewed",
        "pid_guard_reviewed",
        "startup_timeout_reviewed",
        "ready_event_reviewed",
        "stdout_stderr_sanitization_reviewed",
        "rollback_reviewed",
        "observability_reviewed",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect(
        "gates.runtime_policy_start_enabled",
        gates.get("runtime_policy_start_enabled"),
        process_executor_review_ready,
    )

    return payload


def validate_guarded_start_process_executor_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_process_executor_contract.v1")
    _expect(
        "guarded_start_process_executor_contract_implemented",
        payload.get("guarded_start_process_executor_contract_implemented"),
        True,
    )
    _expect("process_executor_contract_only", payload.get("process_executor_contract_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    process_executor_contract_ready = payload.get("status") == "ready_for_guarded_start_process_runtime_adapter"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), process_executor_contract_ready)
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
        "process_executor_contract_only",
        "process_executor_review_attached",
        "localized_subprocess_import_contract_required",
        "pid_guard_contract_required",
        "startup_timeout_contract_required",
        "ready_event_contract_required",
        "stdout_stderr_sanitization_contract_required",
        "rollback_contract_required",
        "observability_contract_required",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect(
        "gates.runtime_policy_start_enabled",
        gates.get("runtime_policy_start_enabled"),
        process_executor_contract_ready,
    )

    return payload


def validate_guarded_start_process_runtime_adapter_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_process_runtime_adapter.v1")
    _expect(
        "guarded_start_process_runtime_adapter_implemented",
        payload.get("guarded_start_process_runtime_adapter_implemented"),
        True,
    )
    _expect("process_runtime_adapter_only", payload.get("process_runtime_adapter_only"), True)
    _expect("runtime_adapter_contract_only", payload.get("runtime_adapter_contract_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    process_runtime_adapter_ready = payload.get("status") == "ready_for_guarded_start_process_adapter_review"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), process_runtime_adapter_ready)
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
        "process_runtime_adapter_only",
        "process_executor_contract_attached",
        "localized_subprocess_import_boundary_required",
        "pid_guard_adapter_required",
        "startup_timeout_adapter_required",
        "ready_event_adapter_required",
        "stdout_stderr_sanitizers_required",
        "rollback_adapter_required",
        "observability_adapter_required",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect(
        "gates.runtime_policy_start_enabled",
        gates.get("runtime_policy_start_enabled"),
        process_runtime_adapter_ready,
    )

    return payload


def validate_guarded_start_process_adapter_review_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_process_adapter_review.v1")
    _expect(
        "guarded_start_process_adapter_review_implemented",
        payload.get("guarded_start_process_adapter_review_implemented"),
        True,
    )
    _expect("process_adapter_review_only", payload.get("process_adapter_review_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    process_adapter_review_ready = payload.get("status") == "ready_for_guarded_start_process_adapter_contract"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), process_adapter_review_ready)
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
        "process_adapter_review_only",
        "process_runtime_adapter_attached",
        "technical_review_completed",
        "runtime_adapter_contract_reviewed",
        "localized_subprocess_import_boundary_reviewed",
        "pid_guard_adapter_reviewed",
        "startup_timeout_adapter_reviewed",
        "ready_event_adapter_reviewed",
        "stdout_stderr_sanitizers_reviewed",
        "rollback_adapter_reviewed",
        "observability_adapter_reviewed",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect(
        "gates.runtime_policy_start_enabled",
        gates.get("runtime_policy_start_enabled"),
        process_adapter_review_ready,
    )

    return payload


def validate_guarded_start_process_adapter_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_process_adapter_contract.v1")
    _expect(
        "guarded_start_process_adapter_contract_implemented",
        payload.get("guarded_start_process_adapter_contract_implemented"),
        True,
    )
    _expect("process_adapter_contract_only", payload.get("process_adapter_contract_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    process_adapter_contract_ready = payload.get("status") == "ready_for_guarded_start_process_runner_contract"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), process_adapter_contract_ready)
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
        "process_adapter_contract_only",
        "process_adapter_review_attached",
        "runtime_adapter_contract_required",
        "localized_subprocess_import_boundary_required",
        "pid_guard_adapter_contract_required",
        "startup_timeout_adapter_contract_required",
        "ready_event_adapter_contract_required",
        "stdout_stderr_sanitizers_contract_required",
        "rollback_adapter_contract_required",
        "observability_adapter_contract_required",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect(
        "gates.runtime_policy_start_enabled",
        gates.get("runtime_policy_start_enabled"),
        process_adapter_contract_ready,
    )

    return payload


def validate_guarded_start_process_runner_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_process_runner_contract.v1")
    _expect(
        "guarded_start_process_runner_contract_implemented",
        payload.get("guarded_start_process_runner_contract_implemented"),
        True,
    )
    _expect("process_runner_contract_only", payload.get("process_runner_contract_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    process_runner_contract_ready = payload.get("status") == "ready_for_guarded_start_process_runner_review"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), process_runner_contract_ready)
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
        "process_runner_contract_only",
        "process_adapter_contract_attached",
        "runner_contract_only",
        "single_start_receipt_required",
        "pid_guard_runner_required",
        "startup_timeout_runner_required",
        "ready_event_runner_required",
        "stdout_stderr_sanitizers_runner_required",
        "rollback_runner_required",
        "observability_runner_required",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect(
        "gates.runtime_policy_start_enabled",
        gates.get("runtime_policy_start_enabled"),
        process_runner_contract_ready,
    )

    return payload


def validate_guarded_start_process_runner_review_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_process_runner_review.v1")
    _expect(
        "guarded_start_process_runner_review_implemented",
        payload.get("guarded_start_process_runner_review_implemented"),
        True,
    )
    _expect("process_runner_review_only", payload.get("process_runner_review_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    process_runner_review_ready = payload.get("status") == "ready_for_guarded_start_process_runner_packet"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), process_runner_review_ready)
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
        "process_runner_review_only",
        "process_runner_contract_attached",
        "technical_review_completed",
        "single_start_receipt_reviewed",
        "pid_guard_runner_reviewed",
        "startup_timeout_runner_reviewed",
        "ready_event_runner_reviewed",
        "stdout_stderr_sanitizers_runner_reviewed",
        "rollback_runner_reviewed",
        "observability_runner_reviewed",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect(
        "gates.runtime_policy_start_enabled",
        gates.get("runtime_policy_start_enabled"),
        process_runner_review_ready,
    )

    return payload


def validate_guarded_start_process_runner_packet_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.guarded_start_process_runner_packet.v1")
    _expect(
        "guarded_start_process_runner_packet_implemented",
        payload.get("guarded_start_process_runner_packet_implemented"),
        True,
    )
    _expect("process_runner_packet_only", payload.get("process_runner_packet_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    process_runner_packet_ready = payload.get("status") == "ready_for_guarded_start_process_runner_execution_review"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), process_runner_packet_ready)
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
        "process_runner_packet_only",
        "process_runner_review_attached",
        "runner_packet_only",
        "decision_receipt_attached",
        "argv_env_cwd_redacted",
        "pid_guard_attached",
        "startup_timeout_attached",
        "ready_event_attached",
        "stdout_stderr_sanitizers_attached",
        "rollback_attached",
        "observability_attached",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect(
        "gates.runtime_policy_start_enabled",
        gates.get("runtime_policy_start_enabled"),
        process_runner_packet_ready,
    )

    return payload


def validate_guarded_start_process_runner_execution_review_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect(
        "schema_version",
        payload.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_runner_execution_review.v1",
    )
    _expect(
        "guarded_start_process_runner_execution_review_implemented",
        payload.get("guarded_start_process_runner_execution_review_implemented"),
        True,
    )
    _expect("process_runner_execution_review_only", payload.get("process_runner_execution_review_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    process_runner_execution_review_ready = (
        payload.get("status") == "ready_for_guarded_start_process_runner_execution_contract"
    )
    _expect(
        "runtime_policy_start_enabled",
        payload.get("runtime_policy_start_enabled"),
        process_runner_execution_review_ready,
    )
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
        "process_runner_execution_review_only",
        "process_runner_packet_attached",
        "technical_review_completed",
        "decision_receipt_reviewed",
        "argv_env_cwd_reviewed",
        "pid_guard_reviewed",
        "startup_timeout_reviewed",
        "ready_event_reviewed",
        "stdout_stderr_sanitizers_reviewed",
        "rollback_reviewed",
        "observability_reviewed",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect(
        "gates.runtime_policy_start_enabled",
        gates.get("runtime_policy_start_enabled"),
        process_runner_execution_review_ready,
    )

    return payload


def validate_guarded_start_process_runner_execution_contract_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect(
        "schema_version",
        payload.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_runner_execution_contract.v1",
    )
    _expect(
        "guarded_start_process_runner_execution_contract_implemented",
        payload.get("guarded_start_process_runner_execution_contract_implemented"),
        True,
    )
    _expect("process_runner_execution_contract_only", payload.get("process_runner_execution_contract_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    execution_contract_ready = payload.get("status") == "ready_for_guarded_start_process_runner_start_gate"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), execution_contract_ready)
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
        "process_runner_execution_contract_only",
        "process_runner_execution_review_attached",
        "execution_contract_only",
        "decision_receipt_bound",
        "argv_env_cwd_bound",
        "pid_guard_bound",
        "startup_timeout_bound",
        "ready_event_bound",
        "stdout_stderr_sanitizers_bound",
        "rollback_bound",
        "observability_bound",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect(
        "gates.runtime_policy_start_enabled",
        gates.get("runtime_policy_start_enabled"),
        execution_contract_ready,
    )

    return payload


def validate_guarded_start_process_runner_start_gate_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect(
        "schema_version",
        payload.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_runner_start_gate.v1",
    )
    _expect(
        "guarded_start_process_runner_start_gate_implemented",
        payload.get("guarded_start_process_runner_start_gate_implemented"),
        True,
    )
    _expect("process_runner_start_gate_only", payload.get("process_runner_start_gate_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    start_gate_ready = payload.get("status") == "ready_for_guarded_start_process_runner_final_review"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), start_gate_ready)
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
        "process_runner_start_gate_only",
        "process_runner_execution_contract_attached",
        "start_gate_only",
        "final_receipt_required",
        "single_start_required",
        "pid_guard_required",
        "startup_timeout_required",
        "ready_event_required",
        "stdout_stderr_sanitizers_required",
        "rollback_required",
        "observability_required",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    _expect("gates.runtime_policy_start_enabled", gates.get("runtime_policy_start_enabled"), start_gate_ready)

    return payload


def validate_guarded_start_process_runner_final_review_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect(
        "schema_version",
        payload.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_runner_final_review.v1",
    )
    _expect(
        "guarded_start_process_runner_final_review_implemented",
        payload.get("guarded_start_process_runner_final_review_implemented"),
        True,
    )
    _expect("process_runner_final_review_only", payload.get("process_runner_final_review_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    final_review_ready = payload.get("status") == "ready_for_guarded_start_process_runner_promotion_packet"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), final_review_ready)
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
        "process_runner_final_review_only",
        "process_runner_start_gate_attached",
        "final_review_only",
        "final_receipt_attached",
        "single_start_verified",
        "pid_guard_reviewed",
        "startup_timeout_reviewed",
        "ready_event_reviewed",
        "stdout_stderr_sanitizers_reviewed",
        "rollback_reviewed",
        "observability_reviewed",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    if final_review_ready:
        _expect("gates.guarded_start_process_runner_start_gate_ready", gates.get("guarded_start_process_runner_start_gate_ready"), True)
    _expect("gates.runtime_policy_start_enabled", gates.get("runtime_policy_start_enabled"), final_review_ready)

    return payload


def validate_guarded_start_process_runner_promotion_packet_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _reject_forbidden(payload)
    _expect(
        "schema_version",
        payload.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_runner_promotion_packet.v1",
    )
    _expect(
        "guarded_start_process_runner_promotion_packet_implemented",
        payload.get("guarded_start_process_runner_promotion_packet_implemented"),
        True,
    )
    _expect("process_runner_promotion_packet_only", payload.get("process_runner_promotion_packet_only"), True)
    _expect("guarded_start_executor_enabled", payload.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_implemented", payload.get("guarded_start_executor_implemented"), False)
    _expect("final_start_executor_enabled", payload.get("final_start_executor_enabled"), False)
    promotion_packet_ready = payload.get("status") == "ready_for_guarded_start_process_runner_operator_release"
    _expect("runtime_policy_start_enabled", payload.get("runtime_policy_start_enabled"), promotion_packet_ready)
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
        "process_runner_promotion_packet_only",
        "process_runner_final_review_attached",
        "promotion_packet_only",
        "final_review_receipt_attached",
        "bundle_hash_attached",
        "evidence_manifest_attached",
        "rollback_plan_attached",
        "operator_release_review_required",
        "start_execution_disabled",
        "process_launch_disabled",
        "subprocess_import_disabled",
        "provider_calls_forbidden",
        "tool_calls_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect(f"gates.{key}", gates.get(key), True)

    if promotion_packet_ready:
        _expect(
            "gates.guarded_start_process_runner_final_review_ready",
            gates.get("guarded_start_process_runner_final_review_ready"),
            True,
        )
    _expect("gates.runtime_policy_start_enabled", gates.get("runtime_policy_start_enabled"), promotion_packet_ready)

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
