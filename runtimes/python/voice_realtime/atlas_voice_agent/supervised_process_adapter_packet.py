from __future__ import annotations

from typing import Any, Mapping

from .payload_safety import reject_forbidden_keys_recursive
from .turn_payload import UnsafeVoicePayload


class SupervisedProcessAdapterPacketViolation(RuntimeError):
    """Raised when the process adapter contract is not fail-closed."""


FORBIDDEN_SUPERVISED_PROCESS_ADAPTER_KEYS = {
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


def validate_supervised_process_adapter_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    try:
        reject_forbidden_keys_recursive(
            payload,
            FORBIDDEN_SUPERVISED_PROCESS_ADAPTER_KEYS,
            label="supervised_process_adapter",
        )
    except UnsafeVoicePayload as exc:
        raise SupervisedProcessAdapterPacketViolation(str(exc)) from exc

    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.supervised_process_adapter.v1")
    _expect("launch_allowed", payload.get("launch_allowed"), False)
    _expect("process_launch_attempted", payload.get("process_launch_attempted"), False)
    _expect("daemon_started", payload.get("daemon_started"), False)
    _expect("subprocess_module_imported", payload.get("subprocess_module_imported"), False)
    _expect("livekit_sdk_imported", payload.get("livekit_sdk_imported"), False)

    gates = _expect_mapping("gates", payload.get("gates"))
    _expect("gates.launch_disabled", gates.get("launch_disabled"), True)
    _expect("gates.secrets_redacted", gates.get("secrets_redacted"), True)
    _expect("gates.provider_calls_forbidden", gates.get("provider_calls_forbidden"), True)
    _expect("gates.tool_calls_forbidden", gates.get("tool_calls_forbidden"), True)

    env_contract = _expect_mapping("managed_environment_contract", payload.get("managed_environment_contract"))
    _expect("managed_environment_contract.schema_version", env_contract.get("schema_version"), "atlas.voice_realtime.managed_env_contract.v1")
    _expect("managed_environment_contract.env_value_logging_allowed", env_contract.get("env_value_logging_allowed"), False)
    _expect("managed_environment_contract.env_file_write_attempted", env_contract.get("env_file_write_attempted"), False)
    _expect("managed_environment_contract.env_file_written", env_contract.get("env_file_written"), False)
    _expect("managed_environment_contract.secret_values_present_in_output", env_contract.get("secret_values_present_in_output"), False)

    launch_auth = _expect_mapping("launch_authorization_contract", payload.get("launch_authorization_contract"))
    _expect("launch_authorization_contract.schema_version", launch_auth.get("schema_version"), "atlas.voice_realtime.launch_authorization_contract.v1")
    _expect("launch_authorization_contract.launch_allowed", launch_auth.get("launch_allowed"), False)
    _expect("launch_authorization_contract.process_launch_attempted", launch_auth.get("process_launch_attempted"), False)
    _expect("launch_authorization_contract.daemon_started", launch_auth.get("daemon_started"), False)
    _expect("launch_authorization_contract.decision_receipt_required", launch_auth.get("decision_receipt_required"), True)

    launch_execution = _expect_mapping("supervised_launch_execution", payload.get("supervised_launch_execution"))
    _expect("supervised_launch_execution.schema_version", launch_execution.get("schema_version"), "atlas.voice_realtime.supervised_launch_execution.v1")
    _expect("supervised_launch_execution.subprocess_launch_implemented", launch_execution.get("subprocess_launch_implemented"), False)
    _expect("supervised_launch_execution.process_launch_attempted", launch_execution.get("process_launch_attempted"), False)
    _expect("supervised_launch_execution.daemon_started", launch_execution.get("daemon_started"), False)
    _expect("supervised_launch_execution.process_launch_allowed", launch_execution.get("process_launch_allowed"), False)
    _expect("supervised_launch_execution.subprocess_module_imported", launch_execution.get("subprocess_module_imported"), False)
    _expect("supervised_launch_execution.livekit_sdk_imported", launch_execution.get("livekit_sdk_imported"), False)

    start_contract = _expect_mapping("subprocess_start_contract", payload.get("subprocess_start_contract"))
    _expect("subprocess_start_contract.schema_version", start_contract.get("schema_version"), "atlas.voice_realtime.subprocess_start_contract.v1")
    _expect("subprocess_start_contract.subprocess_launch_implemented", start_contract.get("subprocess_launch_implemented"), False)
    _expect("subprocess_start_contract.process_launch_attempted", start_contract.get("process_launch_attempted"), False)
    _expect("subprocess_start_contract.daemon_started", start_contract.get("daemon_started"), False)
    _expect("subprocess_start_contract.process_launch_allowed", start_contract.get("process_launch_allowed"), False)
    _expect("subprocess_start_contract.subprocess_module_imported", start_contract.get("subprocess_module_imported"), False)
    _expect("subprocess_start_contract.livekit_sdk_imported", start_contract.get("livekit_sdk_imported"), False)
    _expect("subprocess_start_contract.provider_calls_made", start_contract.get("provider_calls_made"), False)
    _expect("subprocess_start_contract.tool_calls_made", start_contract.get("tool_calls_made"), False)
    _expect("subprocess_start_contract.raw_audio_touched", start_contract.get("raw_audio_touched"), False)

    reviewed_start = _expect_mapping("reviewed_subprocess_start_execution", payload.get("reviewed_subprocess_start_execution"))
    _expect("reviewed_subprocess_start_execution.schema_version", reviewed_start.get("schema_version"), "atlas.voice_realtime.reviewed_subprocess_start_execution.v1")
    _expect("reviewed_subprocess_start_execution.reviewed_subprocess_start_execution_implemented", reviewed_start.get("reviewed_subprocess_start_execution_implemented"), True)
    _expect("reviewed_subprocess_start_execution.real_subprocess_start_implemented", reviewed_start.get("real_subprocess_start_implemented"), False)
    _expect("reviewed_subprocess_start_execution.subprocess_launch_implemented", reviewed_start.get("subprocess_launch_implemented"), False)
    _expect("reviewed_subprocess_start_execution.process_launch_attempted", reviewed_start.get("process_launch_attempted"), False)
    _expect("reviewed_subprocess_start_execution.daemon_started", reviewed_start.get("daemon_started"), False)
    _expect("reviewed_subprocess_start_execution.process_launch_allowed", reviewed_start.get("process_launch_allowed"), False)
    _expect("reviewed_subprocess_start_execution.subprocess_module_imported", reviewed_start.get("subprocess_module_imported"), False)
    _expect("reviewed_subprocess_start_execution.livekit_sdk_imported", reviewed_start.get("livekit_sdk_imported"), False)
    _expect("reviewed_subprocess_start_execution.provider_calls_made", reviewed_start.get("provider_calls_made"), False)
    _expect("reviewed_subprocess_start_execution.tool_calls_made", reviewed_start.get("tool_calls_made"), False)
    _expect("reviewed_subprocess_start_execution.raw_audio_touched", reviewed_start.get("raw_audio_touched"), False)

    real_start_adapter = _expect_mapping("real_start_adapter_disabled", payload.get("real_start_adapter_disabled"))
    _expect("real_start_adapter_disabled.schema_version", real_start_adapter.get("schema_version"), "atlas.voice_realtime.real_start_adapter_disabled.v1")
    _expect("real_start_adapter_disabled.real_start_adapter_contract_implemented", real_start_adapter.get("real_start_adapter_contract_implemented"), True)
    _expect("real_start_adapter_disabled.real_start_adapter_enabled", real_start_adapter.get("real_start_adapter_enabled"), False)
    _expect("real_start_adapter_disabled.real_subprocess_start_implemented", real_start_adapter.get("real_subprocess_start_implemented"), False)
    _expect("real_start_adapter_disabled.process_launch_attempted", real_start_adapter.get("process_launch_attempted"), False)
    _expect("real_start_adapter_disabled.daemon_started", real_start_adapter.get("daemon_started"), False)
    _expect("real_start_adapter_disabled.process_launch_allowed", real_start_adapter.get("process_launch_allowed"), False)
    _expect("real_start_adapter_disabled.subprocess_module_imported", real_start_adapter.get("subprocess_module_imported"), False)
    _expect("real_start_adapter_disabled.livekit_sdk_imported", real_start_adapter.get("livekit_sdk_imported"), False)
    _expect("real_start_adapter_disabled.provider_calls_made", real_start_adapter.get("provider_calls_made"), False)
    _expect("real_start_adapter_disabled.tool_calls_made", real_start_adapter.get("tool_calls_made"), False)
    _expect("real_start_adapter_disabled.raw_audio_touched", real_start_adapter.get("raw_audio_touched"), False)

    enablement_gate = _expect_mapping("real_start_enablement_gate", payload.get("real_start_enablement_gate"))
    _expect("real_start_enablement_gate.schema_version", enablement_gate.get("schema_version"), "atlas.voice_realtime.real_start_enablement_gate.v1")
    _expect("real_start_enablement_gate.real_start_enablement_gate_implemented", enablement_gate.get("real_start_enablement_gate_implemented"), True)
    _expect("real_start_enablement_gate.real_start_adapter_enabled", enablement_gate.get("real_start_adapter_enabled"), False)
    _expect("real_start_enablement_gate.start_execution_allowed", enablement_gate.get("start_execution_allowed"), False)
    _expect("real_start_enablement_gate.real_subprocess_start_implemented", enablement_gate.get("real_subprocess_start_implemented"), False)
    _expect("real_start_enablement_gate.process_launch_attempted", enablement_gate.get("process_launch_attempted"), False)
    _expect("real_start_enablement_gate.daemon_started", enablement_gate.get("daemon_started"), False)
    _expect("real_start_enablement_gate.process_launch_allowed", enablement_gate.get("process_launch_allowed"), False)
    _expect("real_start_enablement_gate.subprocess_module_imported", enablement_gate.get("subprocess_module_imported"), False)
    _expect("real_start_enablement_gate.livekit_sdk_imported", enablement_gate.get("livekit_sdk_imported"), False)
    _expect("real_start_enablement_gate.provider_calls_made", enablement_gate.get("provider_calls_made"), False)
    _expect("real_start_enablement_gate.tool_calls_made", enablement_gate.get("tool_calls_made"), False)
    _expect("real_start_enablement_gate.raw_audio_touched", enablement_gate.get("raw_audio_touched"), False)

    policy_review = _expect_mapping("runtime_policy_enablement_review", payload.get("runtime_policy_enablement_review"))
    _expect("runtime_policy_enablement_review.schema_version", policy_review.get("schema_version"), "atlas.voice_realtime.runtime_policy_enablement_review.v1")
    _expect("runtime_policy_enablement_review.runtime_policy_enablement_review_implemented", policy_review.get("runtime_policy_enablement_review_implemented"), True)
    _expect("runtime_policy_enablement_review.runtime_policy_start_enabled", policy_review.get("runtime_policy_start_enabled"), False)
    _expect("runtime_policy_enablement_review.real_start_adapter_enabled", policy_review.get("real_start_adapter_enabled"), False)
    _expect("runtime_policy_enablement_review.start_execution_allowed", policy_review.get("start_execution_allowed"), False)
    _expect("runtime_policy_enablement_review.real_subprocess_start_implemented", policy_review.get("real_subprocess_start_implemented"), False)
    _expect("runtime_policy_enablement_review.process_launch_attempted", policy_review.get("process_launch_attempted"), False)
    _expect("runtime_policy_enablement_review.daemon_started", policy_review.get("daemon_started"), False)
    _expect("runtime_policy_enablement_review.process_launch_allowed", policy_review.get("process_launch_allowed"), False)
    _expect("runtime_policy_enablement_review.subprocess_module_imported", policy_review.get("subprocess_module_imported"), False)
    _expect("runtime_policy_enablement_review.livekit_sdk_imported", policy_review.get("livekit_sdk_imported"), False)
    _expect("runtime_policy_enablement_review.provider_calls_made", policy_review.get("provider_calls_made"), False)
    _expect("runtime_policy_enablement_review.tool_calls_made", policy_review.get("tool_calls_made"), False)
    _expect("runtime_policy_enablement_review.raw_audio_touched", policy_review.get("raw_audio_touched"), False)

    adapter_review = _expect_mapping("real_start_adapter_review_contract", payload.get("real_start_adapter_review_contract"))
    _expect("real_start_adapter_review_contract.schema_version", adapter_review.get("schema_version"), "atlas.voice_realtime.real_start_adapter_review_contract.v1")
    _expect("real_start_adapter_review_contract.real_start_adapter_review_contract_implemented", adapter_review.get("real_start_adapter_review_contract_implemented"), True)
    _expect("real_start_adapter_review_contract.runtime_policy_start_enabled", adapter_review.get("runtime_policy_start_enabled"), False)
    _expect("real_start_adapter_review_contract.real_start_adapter_enabled", adapter_review.get("real_start_adapter_enabled"), False)
    _expect("real_start_adapter_review_contract.start_execution_allowed", adapter_review.get("start_execution_allowed"), False)
    _expect("real_start_adapter_review_contract.real_subprocess_start_implemented", adapter_review.get("real_subprocess_start_implemented"), False)
    _expect("real_start_adapter_review_contract.process_launch_attempted", adapter_review.get("process_launch_attempted"), False)
    _expect("real_start_adapter_review_contract.daemon_started", adapter_review.get("daemon_started"), False)
    _expect("real_start_adapter_review_contract.process_launch_allowed", adapter_review.get("process_launch_allowed"), False)
    _expect("real_start_adapter_review_contract.subprocess_module_imported", adapter_review.get("subprocess_module_imported"), False)
    _expect("real_start_adapter_review_contract.livekit_sdk_imported", adapter_review.get("livekit_sdk_imported"), False)
    _expect("real_start_adapter_review_contract.provider_calls_made", adapter_review.get("provider_calls_made"), False)
    _expect("real_start_adapter_review_contract.tool_calls_made", adapter_review.get("tool_calls_made"), False)
    _expect("real_start_adapter_review_contract.raw_audio_touched", adapter_review.get("raw_audio_touched"), False)

    real_execution = _expect_mapping("reviewed_real_start_execution_contract", payload.get("reviewed_real_start_execution_contract"))
    _expect("reviewed_real_start_execution_contract.schema_version", real_execution.get("schema_version"), "atlas.voice_realtime.reviewed_real_start_execution_contract.v1")
    _expect("reviewed_real_start_execution_contract.reviewed_real_start_execution_contract_implemented", real_execution.get("reviewed_real_start_execution_contract_implemented"), True)
    _expect("reviewed_real_start_execution_contract.runtime_policy_start_enabled", real_execution.get("runtime_policy_start_enabled"), False)
    _expect("reviewed_real_start_execution_contract.real_start_adapter_enabled", real_execution.get("real_start_adapter_enabled"), False)
    _expect("reviewed_real_start_execution_contract.start_execution_allowed", real_execution.get("start_execution_allowed"), False)
    _expect("reviewed_real_start_execution_contract.real_subprocess_start_implemented", real_execution.get("real_subprocess_start_implemented"), False)
    _expect("reviewed_real_start_execution_contract.process_launch_attempted", real_execution.get("process_launch_attempted"), False)
    _expect("reviewed_real_start_execution_contract.daemon_started", real_execution.get("daemon_started"), False)
    _expect("reviewed_real_start_execution_contract.process_launch_allowed", real_execution.get("process_launch_allowed"), False)
    _expect("reviewed_real_start_execution_contract.subprocess_module_imported", real_execution.get("subprocess_module_imported"), False)
    _expect("reviewed_real_start_execution_contract.livekit_sdk_imported", real_execution.get("livekit_sdk_imported"), False)
    _expect("reviewed_real_start_execution_contract.provider_calls_made", real_execution.get("provider_calls_made"), False)
    _expect("reviewed_real_start_execution_contract.tool_calls_made", real_execution.get("tool_calls_made"), False)
    _expect("reviewed_real_start_execution_contract.raw_audio_touched", real_execution.get("raw_audio_touched"), False)

    start_attempt = _expect_mapping("start_attempt", payload.get("start_attempt"))
    _expect("start_attempt.process_launch_attempted", start_attempt.get("process_launch_attempted"), False)
    _expect("start_attempt.daemon_started", start_attempt.get("daemon_started"), False)
    _expect("start_attempt.launch_allowed", start_attempt.get("launch_allowed"), False)
    _expect("start_attempt.decision_receipt_required", start_attempt.get("decision_receipt_required"), True)

    return payload


def _expect(path: str, actual: Any, expected: Any) -> None:
    if actual != expected:
        raise SupervisedProcessAdapterPacketViolation(f"{path} expected {expected!r}, got {actual!r}")


def _expect_mapping(path: str, value: Any) -> Mapping[str, Any]:
    if not isinstance(value, Mapping):
        raise SupervisedProcessAdapterPacketViolation(f"{path} must be an object")

    return value
