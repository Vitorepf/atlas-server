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

    final_start_executor = _expect_mapping("final_start_executor_disabled", payload.get("final_start_executor_disabled"))
    _expect("final_start_executor_disabled.schema_version", final_start_executor.get("schema_version"), "atlas.voice_realtime.final_start_executor_disabled.v1")
    _expect("final_start_executor_disabled.final_start_executor_contract_implemented", final_start_executor.get("final_start_executor_contract_implemented"), True)
    _expect("final_start_executor_disabled.final_start_executor_enabled", final_start_executor.get("final_start_executor_enabled"), False)
    _expect("final_start_executor_disabled.runtime_policy_start_enabled", final_start_executor.get("runtime_policy_start_enabled"), False)
    _expect("final_start_executor_disabled.real_start_adapter_enabled", final_start_executor.get("real_start_adapter_enabled"), False)
    _expect("final_start_executor_disabled.start_execution_allowed", final_start_executor.get("start_execution_allowed"), False)
    _expect("final_start_executor_disabled.real_subprocess_start_implemented", final_start_executor.get("real_subprocess_start_implemented"), False)
    _expect("final_start_executor_disabled.process_launch_attempted", final_start_executor.get("process_launch_attempted"), False)
    _expect("final_start_executor_disabled.daemon_started", final_start_executor.get("daemon_started"), False)
    _expect("final_start_executor_disabled.process_launch_allowed", final_start_executor.get("process_launch_allowed"), False)
    _expect("final_start_executor_disabled.subprocess_module_imported", final_start_executor.get("subprocess_module_imported"), False)
    _expect("final_start_executor_disabled.livekit_sdk_imported", final_start_executor.get("livekit_sdk_imported"), False)
    _expect("final_start_executor_disabled.provider_calls_made", final_start_executor.get("provider_calls_made"), False)
    _expect("final_start_executor_disabled.tool_calls_made", final_start_executor.get("tool_calls_made"), False)
    _expect("final_start_executor_disabled.raw_audio_touched", final_start_executor.get("raw_audio_touched"), False)

    final_enablement = _expect_mapping("final_start_executor_enablement_gate", payload.get("final_start_executor_enablement_gate"))
    _expect("final_start_executor_enablement_gate.schema_version", final_enablement.get("schema_version"), "atlas.voice_realtime.final_start_executor_enablement_gate.v1")
    _expect("final_start_executor_enablement_gate.final_start_executor_enablement_gate_implemented", final_enablement.get("final_start_executor_enablement_gate_implemented"), True)
    _expect("final_start_executor_enablement_gate.final_start_executor_enabled", final_enablement.get("final_start_executor_enabled"), False)
    _expect("final_start_executor_enablement_gate.runtime_policy_start_enabled", final_enablement.get("runtime_policy_start_enabled"), False)
    _expect("final_start_executor_enablement_gate.real_start_adapter_enabled", final_enablement.get("real_start_adapter_enabled"), False)
    _expect("final_start_executor_enablement_gate.start_execution_allowed", final_enablement.get("start_execution_allowed"), False)
    _expect("final_start_executor_enablement_gate.real_subprocess_start_implemented", final_enablement.get("real_subprocess_start_implemented"), False)
    _expect("final_start_executor_enablement_gate.process_launch_attempted", final_enablement.get("process_launch_attempted"), False)
    _expect("final_start_executor_enablement_gate.daemon_started", final_enablement.get("daemon_started"), False)
    _expect("final_start_executor_enablement_gate.process_launch_allowed", final_enablement.get("process_launch_allowed"), False)
    _expect("final_start_executor_enablement_gate.subprocess_module_imported", final_enablement.get("subprocess_module_imported"), False)
    _expect("final_start_executor_enablement_gate.livekit_sdk_imported", final_enablement.get("livekit_sdk_imported"), False)
    _expect("final_start_executor_enablement_gate.provider_calls_made", final_enablement.get("provider_calls_made"), False)
    _expect("final_start_executor_enablement_gate.tool_calls_made", final_enablement.get("tool_calls_made"), False)
    _expect("final_start_executor_enablement_gate.raw_audio_touched", final_enablement.get("raw_audio_touched"), False)

    start_review = _expect_mapping("supervised_start_execution_review", payload.get("supervised_start_execution_review"))
    _expect("supervised_start_execution_review.schema_version", start_review.get("schema_version"), "atlas.voice_realtime.supervised_start_execution_review.v1")
    _expect("supervised_start_execution_review.supervised_start_execution_review_implemented", start_review.get("supervised_start_execution_review_implemented"), True)
    _expect("supervised_start_execution_review.final_start_executor_enabled", start_review.get("final_start_executor_enabled"), False)
    _expect("supervised_start_execution_review.runtime_policy_start_enabled", start_review.get("runtime_policy_start_enabled"), False)
    _expect("supervised_start_execution_review.real_start_adapter_enabled", start_review.get("real_start_adapter_enabled"), False)
    _expect("supervised_start_execution_review.start_execution_allowed", start_review.get("start_execution_allowed"), False)
    _expect("supervised_start_execution_review.real_subprocess_start_implemented", start_review.get("real_subprocess_start_implemented"), False)
    _expect("supervised_start_execution_review.process_launch_attempted", start_review.get("process_launch_attempted"), False)
    _expect("supervised_start_execution_review.daemon_started", start_review.get("daemon_started"), False)
    _expect("supervised_start_execution_review.process_launch_allowed", start_review.get("process_launch_allowed"), False)
    _expect("supervised_start_execution_review.subprocess_module_imported", start_review.get("subprocess_module_imported"), False)
    _expect("supervised_start_execution_review.livekit_sdk_imported", start_review.get("livekit_sdk_imported"), False)
    _expect("supervised_start_execution_review.provider_calls_made", start_review.get("provider_calls_made"), False)
    _expect("supervised_start_execution_review.tool_calls_made", start_review.get("tool_calls_made"), False)
    _expect("supervised_start_execution_review.raw_audio_touched", start_review.get("raw_audio_touched"), False)

    real_start_contract = _expect_mapping("real_start_execution_contract", payload.get("real_start_execution_contract"))
    _expect("real_start_execution_contract.schema_version", real_start_contract.get("schema_version"), "atlas.voice_realtime.real_start_execution_contract.v1")
    _expect("real_start_execution_contract.real_start_execution_contract_implemented", real_start_contract.get("real_start_execution_contract_implemented"), True)
    _expect("real_start_execution_contract.guarded_start_executor_implemented", real_start_contract.get("guarded_start_executor_implemented"), False)
    _expect("real_start_execution_contract.final_start_executor_enabled", real_start_contract.get("final_start_executor_enabled"), False)
    _expect("real_start_execution_contract.runtime_policy_start_enabled", real_start_contract.get("runtime_policy_start_enabled"), False)
    _expect("real_start_execution_contract.real_start_adapter_enabled", real_start_contract.get("real_start_adapter_enabled"), False)
    _expect("real_start_execution_contract.start_execution_allowed", real_start_contract.get("start_execution_allowed"), False)
    _expect("real_start_execution_contract.real_subprocess_start_implemented", real_start_contract.get("real_subprocess_start_implemented"), False)
    _expect("real_start_execution_contract.process_launch_attempted", real_start_contract.get("process_launch_attempted"), False)
    _expect("real_start_execution_contract.daemon_started", real_start_contract.get("daemon_started"), False)
    _expect("real_start_execution_contract.process_launch_allowed", real_start_contract.get("process_launch_allowed"), False)
    _expect("real_start_execution_contract.subprocess_module_imported", real_start_contract.get("subprocess_module_imported"), False)
    _expect("real_start_execution_contract.livekit_sdk_imported", real_start_contract.get("livekit_sdk_imported"), False)
    _expect("real_start_execution_contract.provider_calls_made", real_start_contract.get("provider_calls_made"), False)
    _expect("real_start_execution_contract.tool_calls_made", real_start_contract.get("tool_calls_made"), False)
    _expect("real_start_execution_contract.raw_audio_touched", real_start_contract.get("raw_audio_touched"), False)

    guarded_start_executor = _expect_mapping("guarded_start_executor_disabled", payload.get("guarded_start_executor_disabled"))
    _expect("guarded_start_executor_disabled.schema_version", guarded_start_executor.get("schema_version"), "atlas.voice_realtime.guarded_start_executor_disabled.v1")
    _expect("guarded_start_executor_disabled.guarded_start_executor_contract_implemented", guarded_start_executor.get("guarded_start_executor_contract_implemented"), True)
    _expect("guarded_start_executor_disabled.guarded_start_executor_enabled", guarded_start_executor.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_disabled.guarded_start_executor_implemented", guarded_start_executor.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_executor_disabled.final_start_executor_enabled", guarded_start_executor.get("final_start_executor_enabled"), False)
    _expect("guarded_start_executor_disabled.runtime_policy_start_enabled", guarded_start_executor.get("runtime_policy_start_enabled"), False)
    _expect("guarded_start_executor_disabled.real_start_adapter_enabled", guarded_start_executor.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_executor_disabled.start_execution_allowed", guarded_start_executor.get("start_execution_allowed"), False)
    _expect("guarded_start_executor_disabled.real_subprocess_start_implemented", guarded_start_executor.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_executor_disabled.process_launch_attempted", guarded_start_executor.get("process_launch_attempted"), False)
    _expect("guarded_start_executor_disabled.daemon_started", guarded_start_executor.get("daemon_started"), False)
    _expect("guarded_start_executor_disabled.process_launch_allowed", guarded_start_executor.get("process_launch_allowed"), False)
    _expect("guarded_start_executor_disabled.subprocess_module_imported", guarded_start_executor.get("subprocess_module_imported"), False)
    _expect("guarded_start_executor_disabled.livekit_sdk_imported", guarded_start_executor.get("livekit_sdk_imported"), False)
    _expect("guarded_start_executor_disabled.provider_calls_made", guarded_start_executor.get("provider_calls_made"), False)
    _expect("guarded_start_executor_disabled.tool_calls_made", guarded_start_executor.get("tool_calls_made"), False)
    _expect("guarded_start_executor_disabled.raw_audio_touched", guarded_start_executor.get("raw_audio_touched"), False)

    guarded_enablement = _expect_mapping("guarded_start_executor_enablement_gate", payload.get("guarded_start_executor_enablement_gate"))
    _expect("guarded_start_executor_enablement_gate.schema_version", guarded_enablement.get("schema_version"), "atlas.voice_realtime.guarded_start_executor_enablement_gate.v1")
    _expect("guarded_start_executor_enablement_gate.guarded_start_executor_enablement_gate_implemented", guarded_enablement.get("guarded_start_executor_enablement_gate_implemented"), True)
    _expect("guarded_start_executor_enablement_gate.guarded_start_executor_enabled", guarded_enablement.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_enablement_gate.guarded_start_executor_implemented", guarded_enablement.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_executor_enablement_gate.final_start_executor_enabled", guarded_enablement.get("final_start_executor_enabled"), False)
    _expect("guarded_start_executor_enablement_gate.runtime_policy_start_enabled", guarded_enablement.get("runtime_policy_start_enabled"), False)
    _expect("guarded_start_executor_enablement_gate.real_start_adapter_enabled", guarded_enablement.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_executor_enablement_gate.start_execution_allowed", guarded_enablement.get("start_execution_allowed"), False)
    _expect("guarded_start_executor_enablement_gate.real_subprocess_start_implemented", guarded_enablement.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_executor_enablement_gate.process_launch_attempted", guarded_enablement.get("process_launch_attempted"), False)
    _expect("guarded_start_executor_enablement_gate.daemon_started", guarded_enablement.get("daemon_started"), False)
    _expect("guarded_start_executor_enablement_gate.process_launch_allowed", guarded_enablement.get("process_launch_allowed"), False)
    _expect("guarded_start_executor_enablement_gate.subprocess_module_imported", guarded_enablement.get("subprocess_module_imported"), False)
    _expect("guarded_start_executor_enablement_gate.livekit_sdk_imported", guarded_enablement.get("livekit_sdk_imported"), False)
    _expect("guarded_start_executor_enablement_gate.provider_calls_made", guarded_enablement.get("provider_calls_made"), False)
    _expect("guarded_start_executor_enablement_gate.tool_calls_made", guarded_enablement.get("tool_calls_made"), False)
    _expect("guarded_start_executor_enablement_gate.raw_audio_touched", guarded_enablement.get("raw_audio_touched"), False)

    reviewed_guarded = _expect_mapping("reviewed_guarded_start_execution_contract", payload.get("reviewed_guarded_start_execution_contract"))
    _expect("reviewed_guarded_start_execution_contract.schema_version", reviewed_guarded.get("schema_version"), "atlas.voice_realtime.reviewed_guarded_start_execution_contract.v1")
    _expect("reviewed_guarded_start_execution_contract.reviewed_guarded_start_execution_contract_implemented", reviewed_guarded.get("reviewed_guarded_start_execution_contract_implemented"), True)
    _expect("reviewed_guarded_start_execution_contract.guarded_start_executor_enabled", reviewed_guarded.get("guarded_start_executor_enabled"), False)
    _expect("reviewed_guarded_start_execution_contract.guarded_start_executor_implemented", reviewed_guarded.get("guarded_start_executor_implemented"), False)
    _expect("reviewed_guarded_start_execution_contract.final_start_executor_enabled", reviewed_guarded.get("final_start_executor_enabled"), False)
    _expect("reviewed_guarded_start_execution_contract.runtime_policy_start_enabled", reviewed_guarded.get("runtime_policy_start_enabled"), False)
    _expect("reviewed_guarded_start_execution_contract.real_start_adapter_enabled", reviewed_guarded.get("real_start_adapter_enabled"), False)
    _expect("reviewed_guarded_start_execution_contract.start_execution_allowed", reviewed_guarded.get("start_execution_allowed"), False)
    _expect("reviewed_guarded_start_execution_contract.real_subprocess_start_implemented", reviewed_guarded.get("real_subprocess_start_implemented"), False)
    _expect("reviewed_guarded_start_execution_contract.process_launch_attempted", reviewed_guarded.get("process_launch_attempted"), False)
    _expect("reviewed_guarded_start_execution_contract.daemon_started", reviewed_guarded.get("daemon_started"), False)
    _expect("reviewed_guarded_start_execution_contract.process_launch_allowed", reviewed_guarded.get("process_launch_allowed"), False)
    _expect("reviewed_guarded_start_execution_contract.subprocess_module_imported", reviewed_guarded.get("subprocess_module_imported"), False)
    _expect("reviewed_guarded_start_execution_contract.livekit_sdk_imported", reviewed_guarded.get("livekit_sdk_imported"), False)
    _expect("reviewed_guarded_start_execution_contract.provider_calls_made", reviewed_guarded.get("provider_calls_made"), False)
    _expect("reviewed_guarded_start_execution_contract.tool_calls_made", reviewed_guarded.get("tool_calls_made"), False)
    _expect("reviewed_guarded_start_execution_contract.raw_audio_touched", reviewed_guarded.get("raw_audio_touched"), False)

    dry_run = _expect_mapping("guarded_start_dry_run_contract", payload.get("guarded_start_dry_run_contract"))
    _expect("guarded_start_dry_run_contract.schema_version", dry_run.get("schema_version"), "atlas.voice_realtime.guarded_start_dry_run_contract.v1")
    _expect("guarded_start_dry_run_contract.guarded_start_dry_run_contract_implemented", dry_run.get("guarded_start_dry_run_contract_implemented"), True)
    _expect("guarded_start_dry_run_contract.dry_run_only", dry_run.get("dry_run_only"), True)
    _expect("guarded_start_dry_run_contract.guarded_start_executor_enabled", dry_run.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_dry_run_contract.guarded_start_executor_implemented", dry_run.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_dry_run_contract.final_start_executor_enabled", dry_run.get("final_start_executor_enabled"), False)
    _expect("guarded_start_dry_run_contract.runtime_policy_start_enabled", dry_run.get("runtime_policy_start_enabled"), False)
    _expect("guarded_start_dry_run_contract.real_start_adapter_enabled", dry_run.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_dry_run_contract.start_execution_allowed", dry_run.get("start_execution_allowed"), False)
    _expect("guarded_start_dry_run_contract.real_subprocess_start_implemented", dry_run.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_dry_run_contract.process_launch_attempted", dry_run.get("process_launch_attempted"), False)
    _expect("guarded_start_dry_run_contract.daemon_started", dry_run.get("daemon_started"), False)
    _expect("guarded_start_dry_run_contract.process_launch_allowed", dry_run.get("process_launch_allowed"), False)
    _expect("guarded_start_dry_run_contract.subprocess_module_imported", dry_run.get("subprocess_module_imported"), False)
    _expect("guarded_start_dry_run_contract.livekit_sdk_imported", dry_run.get("livekit_sdk_imported"), False)
    _expect("guarded_start_dry_run_contract.provider_calls_made", dry_run.get("provider_calls_made"), False)
    _expect("guarded_start_dry_run_contract.tool_calls_made", dry_run.get("tool_calls_made"), False)
    _expect("guarded_start_dry_run_contract.raw_audio_touched", dry_run.get("raw_audio_touched"), False)

    simulation = _expect_mapping("guarded_start_simulation_contract", payload.get("guarded_start_simulation_contract"))
    _expect("guarded_start_simulation_contract.schema_version", simulation.get("schema_version"), "atlas.voice_realtime.guarded_start_simulation_contract.v1")
    _expect("guarded_start_simulation_contract.guarded_start_simulation_contract_implemented", simulation.get("guarded_start_simulation_contract_implemented"), True)
    _expect("guarded_start_simulation_contract.simulation_only", simulation.get("simulation_only"), True)
    _expect("guarded_start_simulation_contract.dry_run_only", simulation.get("dry_run_only"), True)
    _expect("guarded_start_simulation_contract.guarded_start_executor_enabled", simulation.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_simulation_contract.guarded_start_executor_implemented", simulation.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_simulation_contract.final_start_executor_enabled", simulation.get("final_start_executor_enabled"), False)
    _expect("guarded_start_simulation_contract.runtime_policy_start_enabled", simulation.get("runtime_policy_start_enabled"), False)
    _expect("guarded_start_simulation_contract.real_start_adapter_enabled", simulation.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_simulation_contract.start_execution_allowed", simulation.get("start_execution_allowed"), False)
    _expect("guarded_start_simulation_contract.real_subprocess_start_implemented", simulation.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_simulation_contract.process_launch_attempted", simulation.get("process_launch_attempted"), False)
    _expect("guarded_start_simulation_contract.daemon_started", simulation.get("daemon_started"), False)
    _expect("guarded_start_simulation_contract.process_launch_allowed", simulation.get("process_launch_allowed"), False)
    _expect("guarded_start_simulation_contract.subprocess_module_imported", simulation.get("subprocess_module_imported"), False)
    _expect("guarded_start_simulation_contract.livekit_sdk_imported", simulation.get("livekit_sdk_imported"), False)
    _expect("guarded_start_simulation_contract.provider_calls_made", simulation.get("provider_calls_made"), False)
    _expect("guarded_start_simulation_contract.tool_calls_made", simulation.get("tool_calls_made"), False)
    _expect("guarded_start_simulation_contract.raw_audio_touched", simulation.get("raw_audio_touched"), False)

    handoff = _expect_mapping("guarded_start_runtime_handoff_contract", payload.get("guarded_start_runtime_handoff_contract"))
    _expect("guarded_start_runtime_handoff_contract.schema_version", handoff.get("schema_version"), "atlas.voice_realtime.guarded_start_runtime_handoff_contract.v1")
    _expect("guarded_start_runtime_handoff_contract.guarded_start_runtime_handoff_contract_implemented", handoff.get("guarded_start_runtime_handoff_contract_implemented"), True)
    _expect("guarded_start_runtime_handoff_contract.runtime_handoff_contract_only", handoff.get("runtime_handoff_contract_only"), True)
    _expect("guarded_start_runtime_handoff_contract.guarded_start_executor_enabled", handoff.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_runtime_handoff_contract.guarded_start_executor_implemented", handoff.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_runtime_handoff_contract.final_start_executor_enabled", handoff.get("final_start_executor_enabled"), False)
    _expect("guarded_start_runtime_handoff_contract.runtime_policy_start_enabled", handoff.get("runtime_policy_start_enabled"), False)
    _expect("guarded_start_runtime_handoff_contract.real_start_adapter_enabled", handoff.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_runtime_handoff_contract.start_execution_allowed", handoff.get("start_execution_allowed"), False)
    _expect("guarded_start_runtime_handoff_contract.real_subprocess_start_implemented", handoff.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_runtime_handoff_contract.process_launch_attempted", handoff.get("process_launch_attempted"), False)
    _expect("guarded_start_runtime_handoff_contract.daemon_started", handoff.get("daemon_started"), False)
    _expect("guarded_start_runtime_handoff_contract.process_launch_allowed", handoff.get("process_launch_allowed"), False)
    _expect("guarded_start_runtime_handoff_contract.subprocess_module_imported", handoff.get("subprocess_module_imported"), False)
    _expect("guarded_start_runtime_handoff_contract.livekit_sdk_imported", handoff.get("livekit_sdk_imported"), False)
    _expect("guarded_start_runtime_handoff_contract.provider_calls_made", handoff.get("provider_calls_made"), False)
    _expect("guarded_start_runtime_handoff_contract.tool_calls_made", handoff.get("tool_calls_made"), False)
    _expect("guarded_start_runtime_handoff_contract.raw_audio_touched", handoff.get("raw_audio_touched"), False)

    policy_patch = _expect_mapping("guarded_start_policy_patch_review_contract", payload.get("guarded_start_policy_patch_review_contract"))
    _expect("guarded_start_policy_patch_review_contract.schema_version", policy_patch.get("schema_version"), "atlas.voice_realtime.guarded_start_policy_patch_review_contract.v1")
    _expect("guarded_start_policy_patch_review_contract.guarded_start_policy_patch_review_contract_implemented", policy_patch.get("guarded_start_policy_patch_review_contract_implemented"), True)
    _expect("guarded_start_policy_patch_review_contract.policy_patch_review_only", policy_patch.get("policy_patch_review_only"), True)
    _expect("guarded_start_policy_patch_review_contract.guarded_start_executor_enabled", policy_patch.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_policy_patch_review_contract.guarded_start_executor_implemented", policy_patch.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_policy_patch_review_contract.final_start_executor_enabled", policy_patch.get("final_start_executor_enabled"), False)
    _expect("guarded_start_policy_patch_review_contract.runtime_policy_start_enabled", policy_patch.get("runtime_policy_start_enabled"), False)
    _expect("guarded_start_policy_patch_review_contract.real_start_adapter_enabled", policy_patch.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_policy_patch_review_contract.start_execution_allowed", policy_patch.get("start_execution_allowed"), False)
    _expect("guarded_start_policy_patch_review_contract.real_subprocess_start_implemented", policy_patch.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_policy_patch_review_contract.process_launch_attempted", policy_patch.get("process_launch_attempted"), False)
    _expect("guarded_start_policy_patch_review_contract.daemon_started", policy_patch.get("daemon_started"), False)
    _expect("guarded_start_policy_patch_review_contract.process_launch_allowed", policy_patch.get("process_launch_allowed"), False)
    _expect("guarded_start_policy_patch_review_contract.subprocess_module_imported", policy_patch.get("subprocess_module_imported"), False)
    _expect("guarded_start_policy_patch_review_contract.livekit_sdk_imported", policy_patch.get("livekit_sdk_imported"), False)
    _expect("guarded_start_policy_patch_review_contract.provider_calls_made", policy_patch.get("provider_calls_made"), False)
    _expect("guarded_start_policy_patch_review_contract.tool_calls_made", policy_patch.get("tool_calls_made"), False)
    _expect("guarded_start_policy_patch_review_contract.raw_audio_touched", policy_patch.get("raw_audio_touched"), False)

    human_review = _expect_mapping("guarded_start_human_review_contract", payload.get("guarded_start_human_review_contract"))
    _expect("guarded_start_human_review_contract.schema_version", human_review.get("schema_version"), "atlas.voice_realtime.guarded_start_human_review_contract.v1")
    _expect("guarded_start_human_review_contract.guarded_start_human_review_contract_implemented", human_review.get("guarded_start_human_review_contract_implemented"), True)
    _expect("guarded_start_human_review_contract.human_review_contract_only", human_review.get("human_review_contract_only"), True)
    _expect("guarded_start_human_review_contract.guarded_start_executor_enabled", human_review.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_human_review_contract.guarded_start_executor_implemented", human_review.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_human_review_contract.final_start_executor_enabled", human_review.get("final_start_executor_enabled"), False)
    _expect("guarded_start_human_review_contract.runtime_policy_start_enabled", human_review.get("runtime_policy_start_enabled"), False)
    _expect("guarded_start_human_review_contract.real_start_adapter_enabled", human_review.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_human_review_contract.start_execution_allowed", human_review.get("start_execution_allowed"), False)
    _expect("guarded_start_human_review_contract.real_subprocess_start_implemented", human_review.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_human_review_contract.process_launch_attempted", human_review.get("process_launch_attempted"), False)
    _expect("guarded_start_human_review_contract.daemon_started", human_review.get("daemon_started"), False)
    _expect("guarded_start_human_review_contract.process_launch_allowed", human_review.get("process_launch_allowed"), False)
    _expect("guarded_start_human_review_contract.subprocess_module_imported", human_review.get("subprocess_module_imported"), False)
    _expect("guarded_start_human_review_contract.livekit_sdk_imported", human_review.get("livekit_sdk_imported"), False)
    _expect("guarded_start_human_review_contract.provider_calls_made", human_review.get("provider_calls_made"), False)
    _expect("guarded_start_human_review_contract.tool_calls_made", human_review.get("tool_calls_made"), False)
    _expect("guarded_start_human_review_contract.raw_audio_touched", human_review.get("raw_audio_touched"), False)

    final_enablement = _expect_mapping("guarded_start_final_enablement_gate", payload.get("guarded_start_final_enablement_gate"))
    _expect("guarded_start_final_enablement_gate.schema_version", final_enablement.get("schema_version"), "atlas.voice_realtime.guarded_start_final_enablement_gate.v1")
    _expect("guarded_start_final_enablement_gate.guarded_start_final_enablement_gate_implemented", final_enablement.get("guarded_start_final_enablement_gate_implemented"), True)
    _expect("guarded_start_final_enablement_gate.final_enablement_gate_only", final_enablement.get("final_enablement_gate_only"), True)
    _expect("guarded_start_final_enablement_gate.guarded_start_executor_enabled", final_enablement.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_final_enablement_gate.guarded_start_executor_implemented", final_enablement.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_final_enablement_gate.final_start_executor_enabled", final_enablement.get("final_start_executor_enabled"), False)
    _expect("guarded_start_final_enablement_gate.runtime_policy_start_enabled", final_enablement.get("runtime_policy_start_enabled"), False)
    _expect("guarded_start_final_enablement_gate.real_start_adapter_enabled", final_enablement.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_final_enablement_gate.start_execution_allowed", final_enablement.get("start_execution_allowed"), False)
    _expect("guarded_start_final_enablement_gate.real_subprocess_start_implemented", final_enablement.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_final_enablement_gate.process_launch_attempted", final_enablement.get("process_launch_attempted"), False)
    _expect("guarded_start_final_enablement_gate.daemon_started", final_enablement.get("daemon_started"), False)
    _expect("guarded_start_final_enablement_gate.process_launch_allowed", final_enablement.get("process_launch_allowed"), False)
    _expect("guarded_start_final_enablement_gate.subprocess_module_imported", final_enablement.get("subprocess_module_imported"), False)
    _expect("guarded_start_final_enablement_gate.livekit_sdk_imported", final_enablement.get("livekit_sdk_imported"), False)
    _expect("guarded_start_final_enablement_gate.provider_calls_made", final_enablement.get("provider_calls_made"), False)
    _expect("guarded_start_final_enablement_gate.tool_calls_made", final_enablement.get("tool_calls_made"), False)
    _expect("guarded_start_final_enablement_gate.raw_audio_touched", final_enablement.get("raw_audio_touched"), False)

    policy_enablement = _expect_mapping("guarded_start_policy_enablement_contract", payload.get("guarded_start_policy_enablement_contract"))
    _expect("guarded_start_policy_enablement_contract.schema_version", policy_enablement.get("schema_version"), "atlas.voice_realtime.guarded_start_policy_enablement_contract.v1")
    _expect("guarded_start_policy_enablement_contract.guarded_start_policy_enablement_contract_implemented", policy_enablement.get("guarded_start_policy_enablement_contract_implemented"), True)
    _expect("guarded_start_policy_enablement_contract.policy_enablement_contract_only", policy_enablement.get("policy_enablement_contract_only"), True)
    _expect("guarded_start_policy_enablement_contract.guarded_start_executor_enabled", policy_enablement.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_policy_enablement_contract.guarded_start_executor_implemented", policy_enablement.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_policy_enablement_contract.final_start_executor_enabled", policy_enablement.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_policy_enablement_contract.runtime_policy_start_enabled",
        policy_enablement.get("runtime_policy_start_enabled"),
        policy_enablement.get("status") == "ready_for_guarded_start_activation_contract",
    )
    _expect("guarded_start_policy_enablement_contract.real_start_adapter_enabled", policy_enablement.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_policy_enablement_contract.start_execution_allowed", policy_enablement.get("start_execution_allowed"), False)
    _expect("guarded_start_policy_enablement_contract.real_subprocess_start_implemented", policy_enablement.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_policy_enablement_contract.process_launch_attempted", policy_enablement.get("process_launch_attempted"), False)
    _expect("guarded_start_policy_enablement_contract.daemon_started", policy_enablement.get("daemon_started"), False)
    _expect("guarded_start_policy_enablement_contract.process_launch_allowed", policy_enablement.get("process_launch_allowed"), False)
    _expect("guarded_start_policy_enablement_contract.subprocess_module_imported", policy_enablement.get("subprocess_module_imported"), False)
    _expect("guarded_start_policy_enablement_contract.livekit_sdk_imported", policy_enablement.get("livekit_sdk_imported"), False)
    _expect("guarded_start_policy_enablement_contract.provider_calls_made", policy_enablement.get("provider_calls_made"), False)
    _expect("guarded_start_policy_enablement_contract.tool_calls_made", policy_enablement.get("tool_calls_made"), False)
    _expect("guarded_start_policy_enablement_contract.raw_audio_touched", policy_enablement.get("raw_audio_touched"), False)

    activation_contract = _expect_mapping("guarded_start_activation_contract", payload.get("guarded_start_activation_contract"))
    _expect("guarded_start_activation_contract.schema_version", activation_contract.get("schema_version"), "atlas.voice_realtime.guarded_start_activation_contract.v1")
    _expect("guarded_start_activation_contract.guarded_start_activation_contract_implemented", activation_contract.get("guarded_start_activation_contract_implemented"), True)
    _expect("guarded_start_activation_contract.activation_contract_only", activation_contract.get("activation_contract_only"), True)
    _expect("guarded_start_activation_contract.guarded_start_executor_enabled", activation_contract.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_activation_contract.guarded_start_executor_implemented", activation_contract.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_activation_contract.final_start_executor_enabled", activation_contract.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_activation_contract.runtime_policy_start_enabled",
        activation_contract.get("runtime_policy_start_enabled"),
        activation_contract.get("status") == "ready_for_guarded_start_execution_attempt_contract",
    )
    _expect("guarded_start_activation_contract.real_start_adapter_enabled", activation_contract.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_activation_contract.start_execution_allowed", activation_contract.get("start_execution_allowed"), False)
    _expect("guarded_start_activation_contract.real_subprocess_start_implemented", activation_contract.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_activation_contract.process_launch_attempted", activation_contract.get("process_launch_attempted"), False)
    _expect("guarded_start_activation_contract.daemon_started", activation_contract.get("daemon_started"), False)
    _expect("guarded_start_activation_contract.process_launch_allowed", activation_contract.get("process_launch_allowed"), False)
    _expect("guarded_start_activation_contract.subprocess_module_imported", activation_contract.get("subprocess_module_imported"), False)
    _expect("guarded_start_activation_contract.livekit_sdk_imported", activation_contract.get("livekit_sdk_imported"), False)
    _expect("guarded_start_activation_contract.provider_calls_made", activation_contract.get("provider_calls_made"), False)
    _expect("guarded_start_activation_contract.tool_calls_made", activation_contract.get("tool_calls_made"), False)
    _expect("guarded_start_activation_contract.raw_audio_touched", activation_contract.get("raw_audio_touched"), False)

    execution_attempt = _expect_mapping(
        "guarded_start_execution_attempt_contract",
        payload.get("guarded_start_execution_attempt_contract"),
    )
    _expect(
        "guarded_start_execution_attempt_contract.schema_version",
        execution_attempt.get("schema_version"),
        "atlas.voice_realtime.guarded_start_execution_attempt_contract.v1",
    )
    _expect(
        "guarded_start_execution_attempt_contract.guarded_start_execution_attempt_contract_implemented",
        execution_attempt.get("guarded_start_execution_attempt_contract_implemented"),
        True,
    )
    _expect(
        "guarded_start_execution_attempt_contract.execution_attempt_contract_only",
        execution_attempt.get("execution_attempt_contract_only"),
        True,
    )
    _expect("guarded_start_execution_attempt_contract.guarded_start_executor_enabled", execution_attempt.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_execution_attempt_contract.guarded_start_executor_implemented", execution_attempt.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_execution_attempt_contract.final_start_executor_enabled", execution_attempt.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_execution_attempt_contract.runtime_policy_start_enabled",
        execution_attempt.get("runtime_policy_start_enabled"),
        execution_attempt.get("status") == "ready_for_guarded_start_execution_rehearsal_contract",
    )
    _expect("guarded_start_execution_attempt_contract.real_start_adapter_enabled", execution_attempt.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_execution_attempt_contract.start_execution_allowed", execution_attempt.get("start_execution_allowed"), False)
    _expect("guarded_start_execution_attempt_contract.real_subprocess_start_implemented", execution_attempt.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_execution_attempt_contract.process_launch_attempted", execution_attempt.get("process_launch_attempted"), False)
    _expect("guarded_start_execution_attempt_contract.daemon_started", execution_attempt.get("daemon_started"), False)
    _expect("guarded_start_execution_attempt_contract.process_launch_allowed", execution_attempt.get("process_launch_allowed"), False)
    _expect("guarded_start_execution_attempt_contract.subprocess_module_imported", execution_attempt.get("subprocess_module_imported"), False)
    _expect("guarded_start_execution_attempt_contract.livekit_sdk_imported", execution_attempt.get("livekit_sdk_imported"), False)
    _expect("guarded_start_execution_attempt_contract.provider_calls_made", execution_attempt.get("provider_calls_made"), False)
    _expect("guarded_start_execution_attempt_contract.tool_calls_made", execution_attempt.get("tool_calls_made"), False)
    _expect("guarded_start_execution_attempt_contract.raw_audio_touched", execution_attempt.get("raw_audio_touched"), False)

    execution_rehearsal = _expect_mapping(
        "guarded_start_execution_rehearsal_contract",
        payload.get("guarded_start_execution_rehearsal_contract"),
    )
    _expect(
        "guarded_start_execution_rehearsal_contract.schema_version",
        execution_rehearsal.get("schema_version"),
        "atlas.voice_realtime.guarded_start_execution_rehearsal_contract.v1",
    )
    _expect(
        "guarded_start_execution_rehearsal_contract.guarded_start_execution_rehearsal_contract_implemented",
        execution_rehearsal.get("guarded_start_execution_rehearsal_contract_implemented"),
        True,
    )
    _expect(
        "guarded_start_execution_rehearsal_contract.execution_rehearsal_contract_only",
        execution_rehearsal.get("execution_rehearsal_contract_only"),
        True,
    )
    _expect("guarded_start_execution_rehearsal_contract.guarded_start_executor_enabled", execution_rehearsal.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_execution_rehearsal_contract.guarded_start_executor_implemented", execution_rehearsal.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_execution_rehearsal_contract.final_start_executor_enabled", execution_rehearsal.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_execution_rehearsal_contract.runtime_policy_start_enabled",
        execution_rehearsal.get("runtime_policy_start_enabled"),
        execution_rehearsal.get("status") == "ready_for_guarded_start_observability_contract",
    )
    _expect("guarded_start_execution_rehearsal_contract.real_start_adapter_enabled", execution_rehearsal.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_execution_rehearsal_contract.start_execution_allowed", execution_rehearsal.get("start_execution_allowed"), False)
    _expect("guarded_start_execution_rehearsal_contract.real_subprocess_start_implemented", execution_rehearsal.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_execution_rehearsal_contract.process_launch_attempted", execution_rehearsal.get("process_launch_attempted"), False)
    _expect("guarded_start_execution_rehearsal_contract.daemon_started", execution_rehearsal.get("daemon_started"), False)
    _expect("guarded_start_execution_rehearsal_contract.process_launch_allowed", execution_rehearsal.get("process_launch_allowed"), False)
    _expect("guarded_start_execution_rehearsal_contract.subprocess_module_imported", execution_rehearsal.get("subprocess_module_imported"), False)
    _expect("guarded_start_execution_rehearsal_contract.livekit_sdk_imported", execution_rehearsal.get("livekit_sdk_imported"), False)
    _expect("guarded_start_execution_rehearsal_contract.provider_calls_made", execution_rehearsal.get("provider_calls_made"), False)
    _expect("guarded_start_execution_rehearsal_contract.tool_calls_made", execution_rehearsal.get("tool_calls_made"), False)
    _expect("guarded_start_execution_rehearsal_contract.raw_audio_touched", execution_rehearsal.get("raw_audio_touched"), False)

    observability = _expect_mapping("guarded_start_observability_contract", payload.get("guarded_start_observability_contract"))
    _expect(
        "guarded_start_observability_contract.schema_version",
        observability.get("schema_version"),
        "atlas.voice_realtime.guarded_start_observability_contract.v1",
    )
    _expect(
        "guarded_start_observability_contract.guarded_start_observability_contract_implemented",
        observability.get("guarded_start_observability_contract_implemented"),
        True,
    )
    _expect("guarded_start_observability_contract.observability_contract_only", observability.get("observability_contract_only"), True)
    _expect("guarded_start_observability_contract.guarded_start_executor_enabled", observability.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_observability_contract.guarded_start_executor_implemented", observability.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_observability_contract.final_start_executor_enabled", observability.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_observability_contract.runtime_policy_start_enabled",
        observability.get("runtime_policy_start_enabled"),
        observability.get("status") == "ready_for_guarded_start_release_candidate_contract",
    )
    _expect("guarded_start_observability_contract.real_start_adapter_enabled", observability.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_observability_contract.start_execution_allowed", observability.get("start_execution_allowed"), False)
    _expect("guarded_start_observability_contract.real_subprocess_start_implemented", observability.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_observability_contract.process_launch_attempted", observability.get("process_launch_attempted"), False)
    _expect("guarded_start_observability_contract.daemon_started", observability.get("daemon_started"), False)
    _expect("guarded_start_observability_contract.process_launch_allowed", observability.get("process_launch_allowed"), False)
    _expect("guarded_start_observability_contract.subprocess_module_imported", observability.get("subprocess_module_imported"), False)
    _expect("guarded_start_observability_contract.livekit_sdk_imported", observability.get("livekit_sdk_imported"), False)
    _expect("guarded_start_observability_contract.provider_calls_made", observability.get("provider_calls_made"), False)
    _expect("guarded_start_observability_contract.tool_calls_made", observability.get("tool_calls_made"), False)
    _expect("guarded_start_observability_contract.raw_audio_touched", observability.get("raw_audio_touched"), False)

    release_candidate = _expect_mapping(
        "guarded_start_release_candidate_contract",
        payload.get("guarded_start_release_candidate_contract"),
    )
    _expect(
        "guarded_start_release_candidate_contract.schema_version",
        release_candidate.get("schema_version"),
        "atlas.voice_realtime.guarded_start_release_candidate_contract.v1",
    )
    _expect(
        "guarded_start_release_candidate_contract.guarded_start_release_candidate_contract_implemented",
        release_candidate.get("guarded_start_release_candidate_contract_implemented"),
        True,
    )
    _expect("guarded_start_release_candidate_contract.release_candidate_contract_only", release_candidate.get("release_candidate_contract_only"), True)
    _expect("guarded_start_release_candidate_contract.guarded_start_executor_enabled", release_candidate.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_release_candidate_contract.guarded_start_executor_implemented", release_candidate.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_release_candidate_contract.final_start_executor_enabled", release_candidate.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_release_candidate_contract.runtime_policy_start_enabled",
        release_candidate.get("runtime_policy_start_enabled"),
        release_candidate.get("status") == "ready_for_guarded_start_operator_acceptance_contract",
    )
    _expect("guarded_start_release_candidate_contract.real_start_adapter_enabled", release_candidate.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_release_candidate_contract.start_execution_allowed", release_candidate.get("start_execution_allowed"), False)
    _expect("guarded_start_release_candidate_contract.real_subprocess_start_implemented", release_candidate.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_release_candidate_contract.process_launch_attempted", release_candidate.get("process_launch_attempted"), False)
    _expect("guarded_start_release_candidate_contract.daemon_started", release_candidate.get("daemon_started"), False)
    _expect("guarded_start_release_candidate_contract.process_launch_allowed", release_candidate.get("process_launch_allowed"), False)
    _expect("guarded_start_release_candidate_contract.subprocess_module_imported", release_candidate.get("subprocess_module_imported"), False)
    _expect("guarded_start_release_candidate_contract.livekit_sdk_imported", release_candidate.get("livekit_sdk_imported"), False)
    _expect("guarded_start_release_candidate_contract.provider_calls_made", release_candidate.get("provider_calls_made"), False)
    _expect("guarded_start_release_candidate_contract.tool_calls_made", release_candidate.get("tool_calls_made"), False)
    _expect("guarded_start_release_candidate_contract.raw_audio_touched", release_candidate.get("raw_audio_touched"), False)

    operator_acceptance = _expect_mapping(
        "guarded_start_operator_acceptance_contract",
        payload.get("guarded_start_operator_acceptance_contract"),
    )
    _expect(
        "guarded_start_operator_acceptance_contract.schema_version",
        operator_acceptance.get("schema_version"),
        "atlas.voice_realtime.guarded_start_operator_acceptance_contract.v1",
    )
    _expect(
        "guarded_start_operator_acceptance_contract.guarded_start_operator_acceptance_contract_implemented",
        operator_acceptance.get("guarded_start_operator_acceptance_contract_implemented"),
        True,
    )
    _expect("guarded_start_operator_acceptance_contract.operator_acceptance_contract_only", operator_acceptance.get("operator_acceptance_contract_only"), True)
    _expect("guarded_start_operator_acceptance_contract.guarded_start_executor_enabled", operator_acceptance.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_operator_acceptance_contract.guarded_start_executor_implemented", operator_acceptance.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_operator_acceptance_contract.final_start_executor_enabled", operator_acceptance.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_operator_acceptance_contract.runtime_policy_start_enabled",
        operator_acceptance.get("runtime_policy_start_enabled"),
        operator_acceptance.get("status") == "ready_for_guarded_start_final_start_receipt_contract",
    )
    _expect("guarded_start_operator_acceptance_contract.real_start_adapter_enabled", operator_acceptance.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_operator_acceptance_contract.start_execution_allowed", operator_acceptance.get("start_execution_allowed"), False)
    _expect("guarded_start_operator_acceptance_contract.real_subprocess_start_implemented", operator_acceptance.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_operator_acceptance_contract.process_launch_attempted", operator_acceptance.get("process_launch_attempted"), False)
    _expect("guarded_start_operator_acceptance_contract.daemon_started", operator_acceptance.get("daemon_started"), False)
    _expect("guarded_start_operator_acceptance_contract.process_launch_allowed", operator_acceptance.get("process_launch_allowed"), False)
    _expect("guarded_start_operator_acceptance_contract.subprocess_module_imported", operator_acceptance.get("subprocess_module_imported"), False)
    _expect("guarded_start_operator_acceptance_contract.livekit_sdk_imported", operator_acceptance.get("livekit_sdk_imported"), False)
    _expect("guarded_start_operator_acceptance_contract.provider_calls_made", operator_acceptance.get("provider_calls_made"), False)
    _expect("guarded_start_operator_acceptance_contract.tool_calls_made", operator_acceptance.get("tool_calls_made"), False)
    _expect("guarded_start_operator_acceptance_contract.raw_audio_touched", operator_acceptance.get("raw_audio_touched"), False)

    final_start_receipt = _expect_mapping(
        "guarded_start_final_start_receipt_contract",
        payload.get("guarded_start_final_start_receipt_contract"),
    )
    _expect(
        "guarded_start_final_start_receipt_contract.schema_version",
        final_start_receipt.get("schema_version"),
        "atlas.voice_realtime.guarded_start_final_start_receipt_contract.v1",
    )
    _expect(
        "guarded_start_final_start_receipt_contract.guarded_start_final_start_receipt_contract_implemented",
        final_start_receipt.get("guarded_start_final_start_receipt_contract_implemented"),
        True,
    )
    _expect("guarded_start_final_start_receipt_contract.final_start_receipt_contract_only", final_start_receipt.get("final_start_receipt_contract_only"), True)
    _expect("guarded_start_final_start_receipt_contract.guarded_start_executor_enabled", final_start_receipt.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_final_start_receipt_contract.guarded_start_executor_implemented", final_start_receipt.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_final_start_receipt_contract.final_start_executor_enabled", final_start_receipt.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_final_start_receipt_contract.runtime_policy_start_enabled",
        final_start_receipt.get("runtime_policy_start_enabled"),
        final_start_receipt.get("status") == "ready_for_guarded_start_launch_window_contract",
    )
    _expect("guarded_start_final_start_receipt_contract.real_start_adapter_enabled", final_start_receipt.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_final_start_receipt_contract.start_execution_allowed", final_start_receipt.get("start_execution_allowed"), False)
    _expect("guarded_start_final_start_receipt_contract.real_subprocess_start_implemented", final_start_receipt.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_final_start_receipt_contract.process_launch_attempted", final_start_receipt.get("process_launch_attempted"), False)
    _expect("guarded_start_final_start_receipt_contract.daemon_started", final_start_receipt.get("daemon_started"), False)
    _expect("guarded_start_final_start_receipt_contract.process_launch_allowed", final_start_receipt.get("process_launch_allowed"), False)
    _expect("guarded_start_final_start_receipt_contract.subprocess_module_imported", final_start_receipt.get("subprocess_module_imported"), False)
    _expect("guarded_start_final_start_receipt_contract.livekit_sdk_imported", final_start_receipt.get("livekit_sdk_imported"), False)
    _expect("guarded_start_final_start_receipt_contract.provider_calls_made", final_start_receipt.get("provider_calls_made"), False)
    _expect("guarded_start_final_start_receipt_contract.tool_calls_made", final_start_receipt.get("tool_calls_made"), False)
    _expect("guarded_start_final_start_receipt_contract.raw_audio_touched", final_start_receipt.get("raw_audio_touched"), False)

    launch_window = _expect_mapping(
        "guarded_start_launch_window_contract",
        payload.get("guarded_start_launch_window_contract"),
    )
    _expect(
        "guarded_start_launch_window_contract.schema_version",
        launch_window.get("schema_version"),
        "atlas.voice_realtime.guarded_start_launch_window_contract.v1",
    )
    _expect(
        "guarded_start_launch_window_contract.guarded_start_launch_window_contract_implemented",
        launch_window.get("guarded_start_launch_window_contract_implemented"),
        True,
    )
    _expect("guarded_start_launch_window_contract.launch_window_contract_only", launch_window.get("launch_window_contract_only"), True)
    _expect("guarded_start_launch_window_contract.guarded_start_executor_enabled", launch_window.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_launch_window_contract.guarded_start_executor_implemented", launch_window.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_launch_window_contract.final_start_executor_enabled", launch_window.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_launch_window_contract.runtime_policy_start_enabled",
        launch_window.get("runtime_policy_start_enabled"),
        launch_window.get("status") == "ready_for_guarded_start_pre_launch_guard_contract",
    )
    _expect("guarded_start_launch_window_contract.real_start_adapter_enabled", launch_window.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_launch_window_contract.start_execution_allowed", launch_window.get("start_execution_allowed"), False)
    _expect("guarded_start_launch_window_contract.real_subprocess_start_implemented", launch_window.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_launch_window_contract.process_launch_attempted", launch_window.get("process_launch_attempted"), False)
    _expect("guarded_start_launch_window_contract.daemon_started", launch_window.get("daemon_started"), False)
    _expect("guarded_start_launch_window_contract.process_launch_allowed", launch_window.get("process_launch_allowed"), False)
    _expect("guarded_start_launch_window_contract.subprocess_module_imported", launch_window.get("subprocess_module_imported"), False)
    _expect("guarded_start_launch_window_contract.livekit_sdk_imported", launch_window.get("livekit_sdk_imported"), False)
    _expect("guarded_start_launch_window_contract.provider_calls_made", launch_window.get("provider_calls_made"), False)
    _expect("guarded_start_launch_window_contract.tool_calls_made", launch_window.get("tool_calls_made"), False)
    _expect("guarded_start_launch_window_contract.raw_audio_touched", launch_window.get("raw_audio_touched"), False)

    pre_launch_guard = _expect_mapping(
        "guarded_start_pre_launch_guard_contract",
        payload.get("guarded_start_pre_launch_guard_contract"),
    )
    _expect(
        "guarded_start_pre_launch_guard_contract.schema_version",
        pre_launch_guard.get("schema_version"),
        "atlas.voice_realtime.guarded_start_pre_launch_guard_contract.v1",
    )
    _expect(
        "guarded_start_pre_launch_guard_contract.guarded_start_pre_launch_guard_contract_implemented",
        pre_launch_guard.get("guarded_start_pre_launch_guard_contract_implemented"),
        True,
    )
    _expect("guarded_start_pre_launch_guard_contract.pre_launch_guard_contract_only", pre_launch_guard.get("pre_launch_guard_contract_only"), True)
    _expect("guarded_start_pre_launch_guard_contract.guarded_start_executor_enabled", pre_launch_guard.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_pre_launch_guard_contract.guarded_start_executor_implemented", pre_launch_guard.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_pre_launch_guard_contract.final_start_executor_enabled", pre_launch_guard.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_pre_launch_guard_contract.runtime_policy_start_enabled",
        pre_launch_guard.get("runtime_policy_start_enabled"),
        pre_launch_guard.get("status") == "ready_for_guarded_start_executor_runtime_contract",
    )
    _expect("guarded_start_pre_launch_guard_contract.real_start_adapter_enabled", pre_launch_guard.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_pre_launch_guard_contract.start_execution_allowed", pre_launch_guard.get("start_execution_allowed"), False)
    _expect("guarded_start_pre_launch_guard_contract.real_subprocess_start_implemented", pre_launch_guard.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_pre_launch_guard_contract.process_launch_attempted", pre_launch_guard.get("process_launch_attempted"), False)
    _expect("guarded_start_pre_launch_guard_contract.daemon_started", pre_launch_guard.get("daemon_started"), False)
    _expect("guarded_start_pre_launch_guard_contract.process_launch_allowed", pre_launch_guard.get("process_launch_allowed"), False)
    _expect("guarded_start_pre_launch_guard_contract.subprocess_module_imported", pre_launch_guard.get("subprocess_module_imported"), False)
    _expect("guarded_start_pre_launch_guard_contract.livekit_sdk_imported", pre_launch_guard.get("livekit_sdk_imported"), False)
    _expect("guarded_start_pre_launch_guard_contract.provider_calls_made", pre_launch_guard.get("provider_calls_made"), False)
    _expect("guarded_start_pre_launch_guard_contract.tool_calls_made", pre_launch_guard.get("tool_calls_made"), False)
    _expect("guarded_start_pre_launch_guard_contract.raw_audio_touched", pre_launch_guard.get("raw_audio_touched"), False)

    executor_runtime = _expect_mapping(
        "guarded_start_executor_runtime_contract",
        payload.get("guarded_start_executor_runtime_contract"),
    )
    _expect(
        "guarded_start_executor_runtime_contract.schema_version",
        executor_runtime.get("schema_version"),
        "atlas.voice_realtime.guarded_start_executor_runtime_contract.v1",
    )
    _expect(
        "guarded_start_executor_runtime_contract.guarded_start_executor_runtime_contract_implemented",
        executor_runtime.get("guarded_start_executor_runtime_contract_implemented"),
        True,
    )
    _expect("guarded_start_executor_runtime_contract.executor_runtime_contract_only", executor_runtime.get("executor_runtime_contract_only"), True)
    _expect("guarded_start_executor_runtime_contract.guarded_start_executor_enabled", executor_runtime.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_executor_runtime_contract.guarded_start_executor_implemented", executor_runtime.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_executor_runtime_contract.final_start_executor_enabled", executor_runtime.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_executor_runtime_contract.runtime_policy_start_enabled",
        executor_runtime.get("runtime_policy_start_enabled"),
        executor_runtime.get("status") == "ready_for_guarded_start_process_spawn_contract",
    )
    _expect("guarded_start_executor_runtime_contract.real_start_adapter_enabled", executor_runtime.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_executor_runtime_contract.start_execution_allowed", executor_runtime.get("start_execution_allowed"), False)
    _expect("guarded_start_executor_runtime_contract.real_subprocess_start_implemented", executor_runtime.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_executor_runtime_contract.process_launch_attempted", executor_runtime.get("process_launch_attempted"), False)
    _expect("guarded_start_executor_runtime_contract.daemon_started", executor_runtime.get("daemon_started"), False)
    _expect("guarded_start_executor_runtime_contract.process_launch_allowed", executor_runtime.get("process_launch_allowed"), False)
    _expect("guarded_start_executor_runtime_contract.subprocess_module_imported", executor_runtime.get("subprocess_module_imported"), False)
    _expect("guarded_start_executor_runtime_contract.livekit_sdk_imported", executor_runtime.get("livekit_sdk_imported"), False)
    _expect("guarded_start_executor_runtime_contract.provider_calls_made", executor_runtime.get("provider_calls_made"), False)
    _expect("guarded_start_executor_runtime_contract.tool_calls_made", executor_runtime.get("tool_calls_made"), False)
    _expect("guarded_start_executor_runtime_contract.raw_audio_touched", executor_runtime.get("raw_audio_touched"), False)

    process_spawn = _expect_mapping(
        "guarded_start_process_spawn_contract",
        payload.get("guarded_start_process_spawn_contract"),
    )
    _expect(
        "guarded_start_process_spawn_contract.schema_version",
        process_spawn.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_spawn_contract.v1",
    )
    _expect(
        "guarded_start_process_spawn_contract.guarded_start_process_spawn_contract_implemented",
        process_spawn.get("guarded_start_process_spawn_contract_implemented"),
        True,
    )
    _expect("guarded_start_process_spawn_contract.process_spawn_contract_only", process_spawn.get("process_spawn_contract_only"), True)
    _expect("guarded_start_process_spawn_contract.guarded_start_executor_enabled", process_spawn.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_process_spawn_contract.guarded_start_executor_implemented", process_spawn.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_process_spawn_contract.final_start_executor_enabled", process_spawn.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_process_spawn_contract.runtime_policy_start_enabled",
        process_spawn.get("runtime_policy_start_enabled"),
        process_spawn.get("status") == "ready_for_guarded_start_spawn_review_contract",
    )
    _expect("guarded_start_process_spawn_contract.real_start_adapter_enabled", process_spawn.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_process_spawn_contract.start_execution_allowed", process_spawn.get("start_execution_allowed"), False)
    _expect("guarded_start_process_spawn_contract.real_subprocess_start_implemented", process_spawn.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_process_spawn_contract.process_launch_attempted", process_spawn.get("process_launch_attempted"), False)
    _expect("guarded_start_process_spawn_contract.daemon_started", process_spawn.get("daemon_started"), False)
    _expect("guarded_start_process_spawn_contract.process_launch_allowed", process_spawn.get("process_launch_allowed"), False)
    _expect("guarded_start_process_spawn_contract.subprocess_module_imported", process_spawn.get("subprocess_module_imported"), False)
    _expect("guarded_start_process_spawn_contract.livekit_sdk_imported", process_spawn.get("livekit_sdk_imported"), False)
    _expect("guarded_start_process_spawn_contract.provider_calls_made", process_spawn.get("provider_calls_made"), False)
    _expect("guarded_start_process_spawn_contract.tool_calls_made", process_spawn.get("tool_calls_made"), False)
    _expect("guarded_start_process_spawn_contract.raw_audio_touched", process_spawn.get("raw_audio_touched"), False)

    spawn_review = _expect_mapping(
        "guarded_start_spawn_review_contract",
        payload.get("guarded_start_spawn_review_contract"),
    )
    _expect(
        "guarded_start_spawn_review_contract.schema_version",
        spawn_review.get("schema_version"),
        "atlas.voice_realtime.guarded_start_spawn_review_contract.v1",
    )
    _expect(
        "guarded_start_spawn_review_contract.guarded_start_spawn_review_contract_implemented",
        spawn_review.get("guarded_start_spawn_review_contract_implemented"),
        True,
    )
    _expect("guarded_start_spawn_review_contract.spawn_review_contract_only", spawn_review.get("spawn_review_contract_only"), True)
    _expect("guarded_start_spawn_review_contract.guarded_start_executor_enabled", spawn_review.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_spawn_review_contract.guarded_start_executor_implemented", spawn_review.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_spawn_review_contract.final_start_executor_enabled", spawn_review.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_spawn_review_contract.runtime_policy_start_enabled",
        spawn_review.get("runtime_policy_start_enabled"),
        spawn_review.get("status") == "ready_for_guarded_start_subprocess_import_contract",
    )
    _expect("guarded_start_spawn_review_contract.real_start_adapter_enabled", spawn_review.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_spawn_review_contract.start_execution_allowed", spawn_review.get("start_execution_allowed"), False)
    _expect("guarded_start_spawn_review_contract.real_subprocess_start_implemented", spawn_review.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_spawn_review_contract.process_launch_attempted", spawn_review.get("process_launch_attempted"), False)
    _expect("guarded_start_spawn_review_contract.daemon_started", spawn_review.get("daemon_started"), False)
    _expect("guarded_start_spawn_review_contract.process_launch_allowed", spawn_review.get("process_launch_allowed"), False)
    _expect("guarded_start_spawn_review_contract.subprocess_module_imported", spawn_review.get("subprocess_module_imported"), False)
    _expect("guarded_start_spawn_review_contract.livekit_sdk_imported", spawn_review.get("livekit_sdk_imported"), False)
    _expect("guarded_start_spawn_review_contract.provider_calls_made", spawn_review.get("provider_calls_made"), False)
    _expect("guarded_start_spawn_review_contract.tool_calls_made", spawn_review.get("tool_calls_made"), False)
    _expect("guarded_start_spawn_review_contract.raw_audio_touched", spawn_review.get("raw_audio_touched"), False)

    subprocess_import = _expect_mapping(
        "guarded_start_subprocess_import_contract",
        payload.get("guarded_start_subprocess_import_contract"),
    )
    _expect(
        "guarded_start_subprocess_import_contract.schema_version",
        subprocess_import.get("schema_version"),
        "atlas.voice_realtime.guarded_start_subprocess_import_contract.v1",
    )
    _expect(
        "guarded_start_subprocess_import_contract.guarded_start_subprocess_import_contract_implemented",
        subprocess_import.get("guarded_start_subprocess_import_contract_implemented"),
        True,
    )
    _expect("guarded_start_subprocess_import_contract.subprocess_import_contract_only", subprocess_import.get("subprocess_import_contract_only"), True)
    _expect("guarded_start_subprocess_import_contract.guarded_start_executor_enabled", subprocess_import.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_subprocess_import_contract.guarded_start_executor_implemented", subprocess_import.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_subprocess_import_contract.final_start_executor_enabled", subprocess_import.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_subprocess_import_contract.runtime_policy_start_enabled",
        subprocess_import.get("runtime_policy_start_enabled"),
        subprocess_import.get("status") == "ready_for_guarded_start_launch_invocation_contract",
    )
    _expect("guarded_start_subprocess_import_contract.real_start_adapter_enabled", subprocess_import.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_subprocess_import_contract.start_execution_allowed", subprocess_import.get("start_execution_allowed"), False)
    _expect("guarded_start_subprocess_import_contract.real_subprocess_start_implemented", subprocess_import.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_subprocess_import_contract.process_launch_attempted", subprocess_import.get("process_launch_attempted"), False)
    _expect("guarded_start_subprocess_import_contract.daemon_started", subprocess_import.get("daemon_started"), False)
    _expect("guarded_start_subprocess_import_contract.process_launch_allowed", subprocess_import.get("process_launch_allowed"), False)
    _expect("guarded_start_subprocess_import_contract.subprocess_module_imported", subprocess_import.get("subprocess_module_imported"), False)
    _expect("guarded_start_subprocess_import_contract.livekit_sdk_imported", subprocess_import.get("livekit_sdk_imported"), False)
    _expect("guarded_start_subprocess_import_contract.provider_calls_made", subprocess_import.get("provider_calls_made"), False)
    _expect("guarded_start_subprocess_import_contract.tool_calls_made", subprocess_import.get("tool_calls_made"), False)
    _expect("guarded_start_subprocess_import_contract.raw_audio_touched", subprocess_import.get("raw_audio_touched"), False)

    launch_invocation = _expect_mapping(
        "guarded_start_launch_invocation_contract",
        payload.get("guarded_start_launch_invocation_contract"),
    )
    _expect(
        "guarded_start_launch_invocation_contract.schema_version",
        launch_invocation.get("schema_version"),
        "atlas.voice_realtime.guarded_start_launch_invocation_contract.v1",
    )
    _expect(
        "guarded_start_launch_invocation_contract.guarded_start_launch_invocation_contract_implemented",
        launch_invocation.get("guarded_start_launch_invocation_contract_implemented"),
        True,
    )
    _expect("guarded_start_launch_invocation_contract.launch_invocation_contract_only", launch_invocation.get("launch_invocation_contract_only"), True)
    _expect("guarded_start_launch_invocation_contract.guarded_start_executor_enabled", launch_invocation.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_launch_invocation_contract.guarded_start_executor_implemented", launch_invocation.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_launch_invocation_contract.final_start_executor_enabled", launch_invocation.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_launch_invocation_contract.runtime_policy_start_enabled",
        launch_invocation.get("runtime_policy_start_enabled"),
        launch_invocation.get("status") == "ready_for_guarded_start_final_process_start_contract",
    )
    _expect("guarded_start_launch_invocation_contract.real_start_adapter_enabled", launch_invocation.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_launch_invocation_contract.start_execution_allowed", launch_invocation.get("start_execution_allowed"), False)
    _expect("guarded_start_launch_invocation_contract.real_subprocess_start_implemented", launch_invocation.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_launch_invocation_contract.process_launch_attempted", launch_invocation.get("process_launch_attempted"), False)
    _expect("guarded_start_launch_invocation_contract.daemon_started", launch_invocation.get("daemon_started"), False)
    _expect("guarded_start_launch_invocation_contract.process_launch_allowed", launch_invocation.get("process_launch_allowed"), False)
    _expect("guarded_start_launch_invocation_contract.subprocess_module_imported", launch_invocation.get("subprocess_module_imported"), False)
    _expect("guarded_start_launch_invocation_contract.livekit_sdk_imported", launch_invocation.get("livekit_sdk_imported"), False)
    _expect("guarded_start_launch_invocation_contract.provider_calls_made", launch_invocation.get("provider_calls_made"), False)
    _expect("guarded_start_launch_invocation_contract.tool_calls_made", launch_invocation.get("tool_calls_made"), False)
    _expect("guarded_start_launch_invocation_contract.raw_audio_touched", launch_invocation.get("raw_audio_touched"), False)

    final_process_start = _expect_mapping(
        "guarded_start_final_process_start_contract",
        payload.get("guarded_start_final_process_start_contract"),
    )
    _expect(
        "guarded_start_final_process_start_contract.schema_version",
        final_process_start.get("schema_version"),
        "atlas.voice_realtime.guarded_start_final_process_start_contract.v1",
    )
    _expect(
        "guarded_start_final_process_start_contract.guarded_start_final_process_start_contract_implemented",
        final_process_start.get("guarded_start_final_process_start_contract_implemented"),
        True,
    )
    _expect("guarded_start_final_process_start_contract.final_process_start_contract_only", final_process_start.get("final_process_start_contract_only"), True)
    _expect("guarded_start_final_process_start_contract.guarded_start_executor_enabled", final_process_start.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_final_process_start_contract.guarded_start_executor_implemented", final_process_start.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_final_process_start_contract.final_start_executor_enabled", final_process_start.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_final_process_start_contract.runtime_policy_start_enabled",
        final_process_start.get("runtime_policy_start_enabled"),
        final_process_start.get("status") == "ready_for_guarded_start_process_execution_review",
    )
    _expect("guarded_start_final_process_start_contract.real_start_adapter_enabled", final_process_start.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_final_process_start_contract.start_execution_allowed", final_process_start.get("start_execution_allowed"), False)
    _expect("guarded_start_final_process_start_contract.real_subprocess_start_implemented", final_process_start.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_final_process_start_contract.process_launch_attempted", final_process_start.get("process_launch_attempted"), False)
    _expect("guarded_start_final_process_start_contract.daemon_started", final_process_start.get("daemon_started"), False)
    _expect("guarded_start_final_process_start_contract.process_launch_allowed", final_process_start.get("process_launch_allowed"), False)
    _expect("guarded_start_final_process_start_contract.subprocess_module_imported", final_process_start.get("subprocess_module_imported"), False)
    _expect("guarded_start_final_process_start_contract.livekit_sdk_imported", final_process_start.get("livekit_sdk_imported"), False)
    _expect("guarded_start_final_process_start_contract.provider_calls_made", final_process_start.get("provider_calls_made"), False)
    _expect("guarded_start_final_process_start_contract.tool_calls_made", final_process_start.get("tool_calls_made"), False)
    _expect("guarded_start_final_process_start_contract.raw_audio_touched", final_process_start.get("raw_audio_touched"), False)

    process_execution_review = _expect_mapping(
        "guarded_start_process_execution_review",
        payload.get("guarded_start_process_execution_review"),
    )
    _expect(
        "guarded_start_process_execution_review.schema_version",
        process_execution_review.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_execution_review.v1",
    )
    _expect(
        "guarded_start_process_execution_review.guarded_start_process_execution_review_implemented",
        process_execution_review.get("guarded_start_process_execution_review_implemented"),
        True,
    )
    _expect(
        "guarded_start_process_execution_review.process_execution_review_only",
        process_execution_review.get("process_execution_review_only"),
        True,
    )
    _expect("guarded_start_process_execution_review.guarded_start_executor_enabled", process_execution_review.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_process_execution_review.guarded_start_executor_implemented", process_execution_review.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_process_execution_review.final_start_executor_enabled", process_execution_review.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_process_execution_review.runtime_policy_start_enabled",
        process_execution_review.get("runtime_policy_start_enabled"),
        process_execution_review.get("status") == "ready_for_guarded_start_process_execution_packet",
    )
    _expect("guarded_start_process_execution_review.real_start_adapter_enabled", process_execution_review.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_process_execution_review.start_execution_allowed", process_execution_review.get("start_execution_allowed"), False)
    _expect("guarded_start_process_execution_review.real_subprocess_start_implemented", process_execution_review.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_process_execution_review.process_launch_attempted", process_execution_review.get("process_launch_attempted"), False)
    _expect("guarded_start_process_execution_review.daemon_started", process_execution_review.get("daemon_started"), False)
    _expect("guarded_start_process_execution_review.process_launch_allowed", process_execution_review.get("process_launch_allowed"), False)
    _expect("guarded_start_process_execution_review.subprocess_module_imported", process_execution_review.get("subprocess_module_imported"), False)
    _expect("guarded_start_process_execution_review.livekit_sdk_imported", process_execution_review.get("livekit_sdk_imported"), False)
    _expect("guarded_start_process_execution_review.provider_calls_made", process_execution_review.get("provider_calls_made"), False)
    _expect("guarded_start_process_execution_review.tool_calls_made", process_execution_review.get("tool_calls_made"), False)
    _expect("guarded_start_process_execution_review.raw_audio_touched", process_execution_review.get("raw_audio_touched"), False)

    process_execution_packet = _expect_mapping(
        "guarded_start_process_execution_packet",
        payload.get("guarded_start_process_execution_packet"),
    )
    _expect(
        "guarded_start_process_execution_packet.schema_version",
        process_execution_packet.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_execution_packet.v1",
    )
    _expect(
        "guarded_start_process_execution_packet.guarded_start_process_execution_packet_implemented",
        process_execution_packet.get("guarded_start_process_execution_packet_implemented"),
        True,
    )
    _expect(
        "guarded_start_process_execution_packet.process_execution_packet_only",
        process_execution_packet.get("process_execution_packet_only"),
        True,
    )
    _expect("guarded_start_process_execution_packet.guarded_start_executor_enabled", process_execution_packet.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_process_execution_packet.guarded_start_executor_implemented", process_execution_packet.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_process_execution_packet.final_start_executor_enabled", process_execution_packet.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_process_execution_packet.runtime_policy_start_enabled",
        process_execution_packet.get("runtime_policy_start_enabled"),
        process_execution_packet.get("status") == "ready_for_guarded_start_process_executor_stub",
    )
    _expect("guarded_start_process_execution_packet.real_start_adapter_enabled", process_execution_packet.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_process_execution_packet.start_execution_allowed", process_execution_packet.get("start_execution_allowed"), False)
    _expect("guarded_start_process_execution_packet.real_subprocess_start_implemented", process_execution_packet.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_process_execution_packet.process_launch_attempted", process_execution_packet.get("process_launch_attempted"), False)
    _expect("guarded_start_process_execution_packet.daemon_started", process_execution_packet.get("daemon_started"), False)
    _expect("guarded_start_process_execution_packet.process_launch_allowed", process_execution_packet.get("process_launch_allowed"), False)
    _expect("guarded_start_process_execution_packet.subprocess_module_imported", process_execution_packet.get("subprocess_module_imported"), False)
    _expect("guarded_start_process_execution_packet.livekit_sdk_imported", process_execution_packet.get("livekit_sdk_imported"), False)
    _expect("guarded_start_process_execution_packet.provider_calls_made", process_execution_packet.get("provider_calls_made"), False)
    _expect("guarded_start_process_execution_packet.tool_calls_made", process_execution_packet.get("tool_calls_made"), False)
    _expect("guarded_start_process_execution_packet.raw_audio_touched", process_execution_packet.get("raw_audio_touched"), False)

    process_executor_stub = _expect_mapping(
        "guarded_start_process_executor_stub",
        payload.get("guarded_start_process_executor_stub"),
    )
    _expect(
        "guarded_start_process_executor_stub.schema_version",
        process_executor_stub.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_executor_stub.v1",
    )
    _expect(
        "guarded_start_process_executor_stub.guarded_start_process_executor_stub_implemented",
        process_executor_stub.get("guarded_start_process_executor_stub_implemented"),
        True,
    )
    _expect(
        "guarded_start_process_executor_stub.process_executor_stub_only",
        process_executor_stub.get("process_executor_stub_only"),
        True,
    )
    _expect("guarded_start_process_executor_stub.guarded_start_executor_enabled", process_executor_stub.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_process_executor_stub.guarded_start_executor_implemented", process_executor_stub.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_process_executor_stub.final_start_executor_enabled", process_executor_stub.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_process_executor_stub.runtime_policy_start_enabled",
        process_executor_stub.get("runtime_policy_start_enabled"),
        process_executor_stub.get("status") == "ready_for_guarded_start_process_executor_review",
    )
    _expect("guarded_start_process_executor_stub.real_start_adapter_enabled", process_executor_stub.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_process_executor_stub.start_execution_allowed", process_executor_stub.get("start_execution_allowed"), False)
    _expect("guarded_start_process_executor_stub.real_subprocess_start_implemented", process_executor_stub.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_process_executor_stub.process_launch_attempted", process_executor_stub.get("process_launch_attempted"), False)
    _expect("guarded_start_process_executor_stub.daemon_started", process_executor_stub.get("daemon_started"), False)
    _expect("guarded_start_process_executor_stub.process_launch_allowed", process_executor_stub.get("process_launch_allowed"), False)
    _expect("guarded_start_process_executor_stub.subprocess_module_imported", process_executor_stub.get("subprocess_module_imported"), False)
    _expect("guarded_start_process_executor_stub.livekit_sdk_imported", process_executor_stub.get("livekit_sdk_imported"), False)
    _expect("guarded_start_process_executor_stub.provider_calls_made", process_executor_stub.get("provider_calls_made"), False)
    _expect("guarded_start_process_executor_stub.tool_calls_made", process_executor_stub.get("tool_calls_made"), False)
    _expect("guarded_start_process_executor_stub.raw_audio_touched", process_executor_stub.get("raw_audio_touched"), False)

    process_executor_review = _expect_mapping(
        "guarded_start_process_executor_review",
        payload.get("guarded_start_process_executor_review"),
    )
    _expect(
        "guarded_start_process_executor_review.schema_version",
        process_executor_review.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_executor_review.v1",
    )
    _expect(
        "guarded_start_process_executor_review.guarded_start_process_executor_review_implemented",
        process_executor_review.get("guarded_start_process_executor_review_implemented"),
        True,
    )
    _expect(
        "guarded_start_process_executor_review.process_executor_review_only",
        process_executor_review.get("process_executor_review_only"),
        True,
    )
    _expect("guarded_start_process_executor_review.guarded_start_executor_enabled", process_executor_review.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_process_executor_review.guarded_start_executor_implemented", process_executor_review.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_process_executor_review.final_start_executor_enabled", process_executor_review.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_process_executor_review.runtime_policy_start_enabled",
        process_executor_review.get("runtime_policy_start_enabled"),
        process_executor_review.get("status") == "ready_for_guarded_start_process_executor_contract",
    )
    _expect("guarded_start_process_executor_review.real_start_adapter_enabled", process_executor_review.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_process_executor_review.start_execution_allowed", process_executor_review.get("start_execution_allowed"), False)
    _expect("guarded_start_process_executor_review.real_subprocess_start_implemented", process_executor_review.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_process_executor_review.process_launch_attempted", process_executor_review.get("process_launch_attempted"), False)
    _expect("guarded_start_process_executor_review.daemon_started", process_executor_review.get("daemon_started"), False)
    _expect("guarded_start_process_executor_review.process_launch_allowed", process_executor_review.get("process_launch_allowed"), False)
    _expect("guarded_start_process_executor_review.subprocess_module_imported", process_executor_review.get("subprocess_module_imported"), False)
    _expect("guarded_start_process_executor_review.livekit_sdk_imported", process_executor_review.get("livekit_sdk_imported"), False)
    _expect("guarded_start_process_executor_review.provider_calls_made", process_executor_review.get("provider_calls_made"), False)
    _expect("guarded_start_process_executor_review.tool_calls_made", process_executor_review.get("tool_calls_made"), False)
    _expect("guarded_start_process_executor_review.raw_audio_touched", process_executor_review.get("raw_audio_touched"), False)

    process_executor_contract = _expect_mapping(
        "guarded_start_process_executor_contract",
        payload.get("guarded_start_process_executor_contract"),
    )
    _expect(
        "guarded_start_process_executor_contract.schema_version",
        process_executor_contract.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_executor_contract.v1",
    )
    _expect(
        "guarded_start_process_executor_contract.guarded_start_process_executor_contract_implemented",
        process_executor_contract.get("guarded_start_process_executor_contract_implemented"),
        True,
    )
    _expect(
        "guarded_start_process_executor_contract.process_executor_contract_only",
        process_executor_contract.get("process_executor_contract_only"),
        True,
    )
    _expect("guarded_start_process_executor_contract.guarded_start_executor_enabled", process_executor_contract.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_process_executor_contract.guarded_start_executor_implemented", process_executor_contract.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_process_executor_contract.final_start_executor_enabled", process_executor_contract.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_process_executor_contract.runtime_policy_start_enabled",
        process_executor_contract.get("runtime_policy_start_enabled"),
        process_executor_contract.get("status") == "ready_for_guarded_start_process_runtime_adapter",
    )
    _expect("guarded_start_process_executor_contract.real_start_adapter_enabled", process_executor_contract.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_process_executor_contract.start_execution_allowed", process_executor_contract.get("start_execution_allowed"), False)
    _expect("guarded_start_process_executor_contract.real_subprocess_start_implemented", process_executor_contract.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_process_executor_contract.process_launch_attempted", process_executor_contract.get("process_launch_attempted"), False)
    _expect("guarded_start_process_executor_contract.daemon_started", process_executor_contract.get("daemon_started"), False)
    _expect("guarded_start_process_executor_contract.process_launch_allowed", process_executor_contract.get("process_launch_allowed"), False)
    _expect("guarded_start_process_executor_contract.subprocess_module_imported", process_executor_contract.get("subprocess_module_imported"), False)
    _expect("guarded_start_process_executor_contract.livekit_sdk_imported", process_executor_contract.get("livekit_sdk_imported"), False)
    _expect("guarded_start_process_executor_contract.provider_calls_made", process_executor_contract.get("provider_calls_made"), False)
    _expect("guarded_start_process_executor_contract.tool_calls_made", process_executor_contract.get("tool_calls_made"), False)
    _expect("guarded_start_process_executor_contract.raw_audio_touched", process_executor_contract.get("raw_audio_touched"), False)

    process_runtime_adapter = _expect_mapping(
        "guarded_start_process_runtime_adapter",
        payload.get("guarded_start_process_runtime_adapter"),
    )
    _expect(
        "guarded_start_process_runtime_adapter.schema_version",
        process_runtime_adapter.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_runtime_adapter.v1",
    )
    _expect(
        "guarded_start_process_runtime_adapter.guarded_start_process_runtime_adapter_implemented",
        process_runtime_adapter.get("guarded_start_process_runtime_adapter_implemented"),
        True,
    )
    _expect(
        "guarded_start_process_runtime_adapter.process_runtime_adapter_only",
        process_runtime_adapter.get("process_runtime_adapter_only"),
        True,
    )
    _expect(
        "guarded_start_process_runtime_adapter.runtime_adapter_contract_only",
        process_runtime_adapter.get("runtime_adapter_contract_only"),
        True,
    )
    _expect("guarded_start_process_runtime_adapter.guarded_start_executor_enabled", process_runtime_adapter.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_process_runtime_adapter.guarded_start_executor_implemented", process_runtime_adapter.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_process_runtime_adapter.final_start_executor_enabled", process_runtime_adapter.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_process_runtime_adapter.runtime_policy_start_enabled",
        process_runtime_adapter.get("runtime_policy_start_enabled"),
        process_runtime_adapter.get("status") == "ready_for_guarded_start_process_adapter_review",
    )
    _expect("guarded_start_process_runtime_adapter.real_start_adapter_enabled", process_runtime_adapter.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_process_runtime_adapter.start_execution_allowed", process_runtime_adapter.get("start_execution_allowed"), False)
    _expect("guarded_start_process_runtime_adapter.real_subprocess_start_implemented", process_runtime_adapter.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_process_runtime_adapter.process_launch_attempted", process_runtime_adapter.get("process_launch_attempted"), False)
    _expect("guarded_start_process_runtime_adapter.daemon_started", process_runtime_adapter.get("daemon_started"), False)
    _expect("guarded_start_process_runtime_adapter.process_launch_allowed", process_runtime_adapter.get("process_launch_allowed"), False)
    _expect("guarded_start_process_runtime_adapter.subprocess_module_imported", process_runtime_adapter.get("subprocess_module_imported"), False)
    _expect("guarded_start_process_runtime_adapter.livekit_sdk_imported", process_runtime_adapter.get("livekit_sdk_imported"), False)
    _expect("guarded_start_process_runtime_adapter.provider_calls_made", process_runtime_adapter.get("provider_calls_made"), False)
    _expect("guarded_start_process_runtime_adapter.tool_calls_made", process_runtime_adapter.get("tool_calls_made"), False)
    _expect("guarded_start_process_runtime_adapter.raw_audio_touched", process_runtime_adapter.get("raw_audio_touched"), False)

    process_adapter_review = _expect_mapping(
        "guarded_start_process_adapter_review",
        payload.get("guarded_start_process_adapter_review"),
    )
    _expect(
        "guarded_start_process_adapter_review.schema_version",
        process_adapter_review.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_adapter_review.v1",
    )
    _expect(
        "guarded_start_process_adapter_review.guarded_start_process_adapter_review_implemented",
        process_adapter_review.get("guarded_start_process_adapter_review_implemented"),
        True,
    )
    _expect(
        "guarded_start_process_adapter_review.process_adapter_review_only",
        process_adapter_review.get("process_adapter_review_only"),
        True,
    )
    _expect("guarded_start_process_adapter_review.guarded_start_executor_enabled", process_adapter_review.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_process_adapter_review.guarded_start_executor_implemented", process_adapter_review.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_process_adapter_review.final_start_executor_enabled", process_adapter_review.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_process_adapter_review.runtime_policy_start_enabled",
        process_adapter_review.get("runtime_policy_start_enabled"),
        process_adapter_review.get("status") == "ready_for_guarded_start_process_adapter_contract",
    )
    _expect("guarded_start_process_adapter_review.real_start_adapter_enabled", process_adapter_review.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_process_adapter_review.start_execution_allowed", process_adapter_review.get("start_execution_allowed"), False)
    _expect("guarded_start_process_adapter_review.real_subprocess_start_implemented", process_adapter_review.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_process_adapter_review.process_launch_attempted", process_adapter_review.get("process_launch_attempted"), False)
    _expect("guarded_start_process_adapter_review.daemon_started", process_adapter_review.get("daemon_started"), False)
    _expect("guarded_start_process_adapter_review.process_launch_allowed", process_adapter_review.get("process_launch_allowed"), False)
    _expect("guarded_start_process_adapter_review.subprocess_module_imported", process_adapter_review.get("subprocess_module_imported"), False)
    _expect("guarded_start_process_adapter_review.livekit_sdk_imported", process_adapter_review.get("livekit_sdk_imported"), False)
    _expect("guarded_start_process_adapter_review.provider_calls_made", process_adapter_review.get("provider_calls_made"), False)
    _expect("guarded_start_process_adapter_review.tool_calls_made", process_adapter_review.get("tool_calls_made"), False)
    _expect("guarded_start_process_adapter_review.raw_audio_touched", process_adapter_review.get("raw_audio_touched"), False)

    process_adapter_contract = _expect_mapping(
        "guarded_start_process_adapter_contract",
        payload.get("guarded_start_process_adapter_contract"),
    )
    _expect(
        "guarded_start_process_adapter_contract.schema_version",
        process_adapter_contract.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_adapter_contract.v1",
    )
    _expect(
        "guarded_start_process_adapter_contract.guarded_start_process_adapter_contract_implemented",
        process_adapter_contract.get("guarded_start_process_adapter_contract_implemented"),
        True,
    )
    _expect(
        "guarded_start_process_adapter_contract.process_adapter_contract_only",
        process_adapter_contract.get("process_adapter_contract_only"),
        True,
    )
    _expect("guarded_start_process_adapter_contract.guarded_start_executor_enabled", process_adapter_contract.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_process_adapter_contract.guarded_start_executor_implemented", process_adapter_contract.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_process_adapter_contract.final_start_executor_enabled", process_adapter_contract.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_process_adapter_contract.runtime_policy_start_enabled",
        process_adapter_contract.get("runtime_policy_start_enabled"),
        process_adapter_contract.get("status") == "ready_for_guarded_start_process_runner_contract",
    )
    _expect("guarded_start_process_adapter_contract.real_start_adapter_enabled", process_adapter_contract.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_process_adapter_contract.start_execution_allowed", process_adapter_contract.get("start_execution_allowed"), False)
    _expect("guarded_start_process_adapter_contract.real_subprocess_start_implemented", process_adapter_contract.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_process_adapter_contract.process_launch_attempted", process_adapter_contract.get("process_launch_attempted"), False)
    _expect("guarded_start_process_adapter_contract.daemon_started", process_adapter_contract.get("daemon_started"), False)
    _expect("guarded_start_process_adapter_contract.process_launch_allowed", process_adapter_contract.get("process_launch_allowed"), False)
    _expect("guarded_start_process_adapter_contract.subprocess_module_imported", process_adapter_contract.get("subprocess_module_imported"), False)
    _expect("guarded_start_process_adapter_contract.livekit_sdk_imported", process_adapter_contract.get("livekit_sdk_imported"), False)
    _expect("guarded_start_process_adapter_contract.provider_calls_made", process_adapter_contract.get("provider_calls_made"), False)
    _expect("guarded_start_process_adapter_contract.tool_calls_made", process_adapter_contract.get("tool_calls_made"), False)
    _expect("guarded_start_process_adapter_contract.raw_audio_touched", process_adapter_contract.get("raw_audio_touched"), False)

    process_runner_contract = _expect_mapping(
        "guarded_start_process_runner_contract",
        payload.get("guarded_start_process_runner_contract"),
    )
    _expect(
        "guarded_start_process_runner_contract.schema_version",
        process_runner_contract.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_runner_contract.v1",
    )
    _expect(
        "guarded_start_process_runner_contract.guarded_start_process_runner_contract_implemented",
        process_runner_contract.get("guarded_start_process_runner_contract_implemented"),
        True,
    )
    _expect(
        "guarded_start_process_runner_contract.process_runner_contract_only",
        process_runner_contract.get("process_runner_contract_only"),
        True,
    )
    _expect("guarded_start_process_runner_contract.guarded_start_executor_enabled", process_runner_contract.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_process_runner_contract.guarded_start_executor_implemented", process_runner_contract.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_process_runner_contract.final_start_executor_enabled", process_runner_contract.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_process_runner_contract.runtime_policy_start_enabled",
        process_runner_contract.get("runtime_policy_start_enabled"),
        process_runner_contract.get("status") == "ready_for_guarded_start_process_runner_review",
    )
    _expect("guarded_start_process_runner_contract.real_start_adapter_enabled", process_runner_contract.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_process_runner_contract.start_execution_allowed", process_runner_contract.get("start_execution_allowed"), False)
    _expect("guarded_start_process_runner_contract.real_subprocess_start_implemented", process_runner_contract.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_process_runner_contract.process_launch_attempted", process_runner_contract.get("process_launch_attempted"), False)
    _expect("guarded_start_process_runner_contract.daemon_started", process_runner_contract.get("daemon_started"), False)
    _expect("guarded_start_process_runner_contract.process_launch_allowed", process_runner_contract.get("process_launch_allowed"), False)
    _expect("guarded_start_process_runner_contract.subprocess_module_imported", process_runner_contract.get("subprocess_module_imported"), False)
    _expect("guarded_start_process_runner_contract.livekit_sdk_imported", process_runner_contract.get("livekit_sdk_imported"), False)
    _expect("guarded_start_process_runner_contract.provider_calls_made", process_runner_contract.get("provider_calls_made"), False)
    _expect("guarded_start_process_runner_contract.tool_calls_made", process_runner_contract.get("tool_calls_made"), False)
    _expect("guarded_start_process_runner_contract.raw_audio_touched", process_runner_contract.get("raw_audio_touched"), False)

    process_runner_review = _expect_mapping(
        "guarded_start_process_runner_review",
        payload.get("guarded_start_process_runner_review"),
    )
    _expect(
        "guarded_start_process_runner_review.schema_version",
        process_runner_review.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_runner_review.v1",
    )
    _expect(
        "guarded_start_process_runner_review.guarded_start_process_runner_review_implemented",
        process_runner_review.get("guarded_start_process_runner_review_implemented"),
        True,
    )
    _expect(
        "guarded_start_process_runner_review.process_runner_review_only",
        process_runner_review.get("process_runner_review_only"),
        True,
    )
    _expect("guarded_start_process_runner_review.guarded_start_executor_enabled", process_runner_review.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_process_runner_review.guarded_start_executor_implemented", process_runner_review.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_process_runner_review.final_start_executor_enabled", process_runner_review.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_process_runner_review.runtime_policy_start_enabled",
        process_runner_review.get("runtime_policy_start_enabled"),
        process_runner_review.get("status") == "ready_for_guarded_start_process_runner_packet",
    )
    _expect("guarded_start_process_runner_review.real_start_adapter_enabled", process_runner_review.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_process_runner_review.start_execution_allowed", process_runner_review.get("start_execution_allowed"), False)
    _expect("guarded_start_process_runner_review.real_subprocess_start_implemented", process_runner_review.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_process_runner_review.process_launch_attempted", process_runner_review.get("process_launch_attempted"), False)
    _expect("guarded_start_process_runner_review.daemon_started", process_runner_review.get("daemon_started"), False)
    _expect("guarded_start_process_runner_review.process_launch_allowed", process_runner_review.get("process_launch_allowed"), False)
    _expect("guarded_start_process_runner_review.subprocess_module_imported", process_runner_review.get("subprocess_module_imported"), False)
    _expect("guarded_start_process_runner_review.livekit_sdk_imported", process_runner_review.get("livekit_sdk_imported"), False)
    _expect("guarded_start_process_runner_review.provider_calls_made", process_runner_review.get("provider_calls_made"), False)
    _expect("guarded_start_process_runner_review.tool_calls_made", process_runner_review.get("tool_calls_made"), False)
    _expect("guarded_start_process_runner_review.raw_audio_touched", process_runner_review.get("raw_audio_touched"), False)

    process_runner_packet = _expect_mapping(
        "guarded_start_process_runner_packet",
        payload.get("guarded_start_process_runner_packet"),
    )
    _expect(
        "guarded_start_process_runner_packet.schema_version",
        process_runner_packet.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_runner_packet.v1",
    )
    _expect(
        "guarded_start_process_runner_packet.guarded_start_process_runner_packet_implemented",
        process_runner_packet.get("guarded_start_process_runner_packet_implemented"),
        True,
    )
    _expect(
        "guarded_start_process_runner_packet.process_runner_packet_only",
        process_runner_packet.get("process_runner_packet_only"),
        True,
    )
    _expect("guarded_start_process_runner_packet.guarded_start_executor_enabled", process_runner_packet.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_process_runner_packet.guarded_start_executor_implemented", process_runner_packet.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_process_runner_packet.final_start_executor_enabled", process_runner_packet.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_process_runner_packet.runtime_policy_start_enabled",
        process_runner_packet.get("runtime_policy_start_enabled"),
        process_runner_packet.get("status") == "ready_for_guarded_start_process_runner_execution_review",
    )
    _expect("guarded_start_process_runner_packet.real_start_adapter_enabled", process_runner_packet.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_process_runner_packet.start_execution_allowed", process_runner_packet.get("start_execution_allowed"), False)
    _expect("guarded_start_process_runner_packet.real_subprocess_start_implemented", process_runner_packet.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_process_runner_packet.process_launch_attempted", process_runner_packet.get("process_launch_attempted"), False)
    _expect("guarded_start_process_runner_packet.daemon_started", process_runner_packet.get("daemon_started"), False)
    _expect("guarded_start_process_runner_packet.process_launch_allowed", process_runner_packet.get("process_launch_allowed"), False)
    _expect("guarded_start_process_runner_packet.subprocess_module_imported", process_runner_packet.get("subprocess_module_imported"), False)
    _expect("guarded_start_process_runner_packet.livekit_sdk_imported", process_runner_packet.get("livekit_sdk_imported"), False)
    _expect("guarded_start_process_runner_packet.provider_calls_made", process_runner_packet.get("provider_calls_made"), False)
    _expect("guarded_start_process_runner_packet.tool_calls_made", process_runner_packet.get("tool_calls_made"), False)
    _expect("guarded_start_process_runner_packet.raw_audio_touched", process_runner_packet.get("raw_audio_touched"), False)

    process_runner_execution_review = _expect_mapping(
        "guarded_start_process_runner_execution_review",
        payload.get("guarded_start_process_runner_execution_review"),
    )
    _expect(
        "guarded_start_process_runner_execution_review.schema_version",
        process_runner_execution_review.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_runner_execution_review.v1",
    )
    _expect(
        "guarded_start_process_runner_execution_review.guarded_start_process_runner_execution_review_implemented",
        process_runner_execution_review.get("guarded_start_process_runner_execution_review_implemented"),
        True,
    )
    _expect(
        "guarded_start_process_runner_execution_review.process_runner_execution_review_only",
        process_runner_execution_review.get("process_runner_execution_review_only"),
        True,
    )
    _expect("guarded_start_process_runner_execution_review.guarded_start_executor_enabled", process_runner_execution_review.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_process_runner_execution_review.guarded_start_executor_implemented", process_runner_execution_review.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_process_runner_execution_review.final_start_executor_enabled", process_runner_execution_review.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_process_runner_execution_review.runtime_policy_start_enabled",
        process_runner_execution_review.get("runtime_policy_start_enabled"),
        process_runner_execution_review.get("status") == "ready_for_guarded_start_process_runner_execution_contract",
    )
    _expect("guarded_start_process_runner_execution_review.real_start_adapter_enabled", process_runner_execution_review.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_process_runner_execution_review.start_execution_allowed", process_runner_execution_review.get("start_execution_allowed"), False)
    _expect("guarded_start_process_runner_execution_review.real_subprocess_start_implemented", process_runner_execution_review.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_process_runner_execution_review.process_launch_attempted", process_runner_execution_review.get("process_launch_attempted"), False)
    _expect("guarded_start_process_runner_execution_review.daemon_started", process_runner_execution_review.get("daemon_started"), False)
    _expect("guarded_start_process_runner_execution_review.process_launch_allowed", process_runner_execution_review.get("process_launch_allowed"), False)
    _expect("guarded_start_process_runner_execution_review.subprocess_module_imported", process_runner_execution_review.get("subprocess_module_imported"), False)
    _expect("guarded_start_process_runner_execution_review.livekit_sdk_imported", process_runner_execution_review.get("livekit_sdk_imported"), False)
    _expect("guarded_start_process_runner_execution_review.provider_calls_made", process_runner_execution_review.get("provider_calls_made"), False)
    _expect("guarded_start_process_runner_execution_review.tool_calls_made", process_runner_execution_review.get("tool_calls_made"), False)
    _expect("guarded_start_process_runner_execution_review.raw_audio_touched", process_runner_execution_review.get("raw_audio_touched"), False)

    process_runner_execution_contract = _expect_mapping(
        "guarded_start_process_runner_execution_contract",
        payload.get("guarded_start_process_runner_execution_contract"),
    )
    _expect(
        "guarded_start_process_runner_execution_contract.schema_version",
        process_runner_execution_contract.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_runner_execution_contract.v1",
    )
    _expect(
        "guarded_start_process_runner_execution_contract.guarded_start_process_runner_execution_contract_implemented",
        process_runner_execution_contract.get("guarded_start_process_runner_execution_contract_implemented"),
        True,
    )
    _expect(
        "guarded_start_process_runner_execution_contract.process_runner_execution_contract_only",
        process_runner_execution_contract.get("process_runner_execution_contract_only"),
        True,
    )
    _expect("guarded_start_process_runner_execution_contract.guarded_start_executor_enabled", process_runner_execution_contract.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_process_runner_execution_contract.guarded_start_executor_implemented", process_runner_execution_contract.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_process_runner_execution_contract.final_start_executor_enabled", process_runner_execution_contract.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_process_runner_execution_contract.runtime_policy_start_enabled",
        process_runner_execution_contract.get("runtime_policy_start_enabled"),
        process_runner_execution_contract.get("status") == "ready_for_guarded_start_process_runner_start_gate",
    )
    _expect("guarded_start_process_runner_execution_contract.real_start_adapter_enabled", process_runner_execution_contract.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_process_runner_execution_contract.start_execution_allowed", process_runner_execution_contract.get("start_execution_allowed"), False)
    _expect("guarded_start_process_runner_execution_contract.real_subprocess_start_implemented", process_runner_execution_contract.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_process_runner_execution_contract.process_launch_attempted", process_runner_execution_contract.get("process_launch_attempted"), False)
    _expect("guarded_start_process_runner_execution_contract.daemon_started", process_runner_execution_contract.get("daemon_started"), False)
    _expect("guarded_start_process_runner_execution_contract.process_launch_allowed", process_runner_execution_contract.get("process_launch_allowed"), False)
    _expect("guarded_start_process_runner_execution_contract.subprocess_module_imported", process_runner_execution_contract.get("subprocess_module_imported"), False)
    _expect("guarded_start_process_runner_execution_contract.livekit_sdk_imported", process_runner_execution_contract.get("livekit_sdk_imported"), False)
    _expect("guarded_start_process_runner_execution_contract.provider_calls_made", process_runner_execution_contract.get("provider_calls_made"), False)
    _expect("guarded_start_process_runner_execution_contract.tool_calls_made", process_runner_execution_contract.get("tool_calls_made"), False)
    _expect("guarded_start_process_runner_execution_contract.raw_audio_touched", process_runner_execution_contract.get("raw_audio_touched"), False)

    process_runner_start_gate = _expect_mapping(
        "guarded_start_process_runner_start_gate",
        payload.get("guarded_start_process_runner_start_gate"),
    )
    _expect(
        "guarded_start_process_runner_start_gate.schema_version",
        process_runner_start_gate.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_runner_start_gate.v1",
    )
    _expect(
        "guarded_start_process_runner_start_gate.guarded_start_process_runner_start_gate_implemented",
        process_runner_start_gate.get("guarded_start_process_runner_start_gate_implemented"),
        True,
    )
    _expect(
        "guarded_start_process_runner_start_gate.process_runner_start_gate_only",
        process_runner_start_gate.get("process_runner_start_gate_only"),
        True,
    )
    _expect("guarded_start_process_runner_start_gate.guarded_start_executor_enabled", process_runner_start_gate.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_process_runner_start_gate.guarded_start_executor_implemented", process_runner_start_gate.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_process_runner_start_gate.final_start_executor_enabled", process_runner_start_gate.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_process_runner_start_gate.runtime_policy_start_enabled",
        process_runner_start_gate.get("runtime_policy_start_enabled"),
        process_runner_start_gate.get("status") == "ready_for_guarded_start_process_runner_final_review",
    )
    _expect("guarded_start_process_runner_start_gate.real_start_adapter_enabled", process_runner_start_gate.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_process_runner_start_gate.start_execution_allowed", process_runner_start_gate.get("start_execution_allowed"), False)
    _expect("guarded_start_process_runner_start_gate.real_subprocess_start_implemented", process_runner_start_gate.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_process_runner_start_gate.process_launch_attempted", process_runner_start_gate.get("process_launch_attempted"), False)
    _expect("guarded_start_process_runner_start_gate.daemon_started", process_runner_start_gate.get("daemon_started"), False)
    _expect("guarded_start_process_runner_start_gate.process_launch_allowed", process_runner_start_gate.get("process_launch_allowed"), False)
    _expect("guarded_start_process_runner_start_gate.subprocess_module_imported", process_runner_start_gate.get("subprocess_module_imported"), False)
    _expect("guarded_start_process_runner_start_gate.livekit_sdk_imported", process_runner_start_gate.get("livekit_sdk_imported"), False)
    _expect("guarded_start_process_runner_start_gate.provider_calls_made", process_runner_start_gate.get("provider_calls_made"), False)
    _expect("guarded_start_process_runner_start_gate.tool_calls_made", process_runner_start_gate.get("tool_calls_made"), False)
    _expect("guarded_start_process_runner_start_gate.raw_audio_touched", process_runner_start_gate.get("raw_audio_touched"), False)

    process_runner_final_review = _expect_mapping(
        "guarded_start_process_runner_final_review",
        payload.get("guarded_start_process_runner_final_review"),
    )
    _expect(
        "guarded_start_process_runner_final_review.schema_version",
        process_runner_final_review.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_runner_final_review.v1",
    )
    _expect(
        "guarded_start_process_runner_final_review.guarded_start_process_runner_final_review_implemented",
        process_runner_final_review.get("guarded_start_process_runner_final_review_implemented"),
        True,
    )
    _expect(
        "guarded_start_process_runner_final_review.process_runner_final_review_only",
        process_runner_final_review.get("process_runner_final_review_only"),
        True,
    )
    _expect("guarded_start_process_runner_final_review.guarded_start_executor_enabled", process_runner_final_review.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_process_runner_final_review.guarded_start_executor_implemented", process_runner_final_review.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_process_runner_final_review.final_start_executor_enabled", process_runner_final_review.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_process_runner_final_review.runtime_policy_start_enabled",
        process_runner_final_review.get("runtime_policy_start_enabled"),
        process_runner_final_review.get("status") == "ready_for_guarded_start_process_runner_promotion_packet",
    )
    _expect("guarded_start_process_runner_final_review.real_start_adapter_enabled", process_runner_final_review.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_process_runner_final_review.start_execution_allowed", process_runner_final_review.get("start_execution_allowed"), False)
    _expect("guarded_start_process_runner_final_review.real_subprocess_start_implemented", process_runner_final_review.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_process_runner_final_review.process_launch_attempted", process_runner_final_review.get("process_launch_attempted"), False)
    _expect("guarded_start_process_runner_final_review.daemon_started", process_runner_final_review.get("daemon_started"), False)
    _expect("guarded_start_process_runner_final_review.process_launch_allowed", process_runner_final_review.get("process_launch_allowed"), False)
    _expect("guarded_start_process_runner_final_review.subprocess_module_imported", process_runner_final_review.get("subprocess_module_imported"), False)
    _expect("guarded_start_process_runner_final_review.livekit_sdk_imported", process_runner_final_review.get("livekit_sdk_imported"), False)
    _expect("guarded_start_process_runner_final_review.provider_calls_made", process_runner_final_review.get("provider_calls_made"), False)
    _expect("guarded_start_process_runner_final_review.tool_calls_made", process_runner_final_review.get("tool_calls_made"), False)
    _expect("guarded_start_process_runner_final_review.raw_audio_touched", process_runner_final_review.get("raw_audio_touched"), False)

    process_runner_promotion_packet = _expect_mapping(
        "guarded_start_process_runner_promotion_packet",
        payload.get("guarded_start_process_runner_promotion_packet"),
    )
    _expect(
        "guarded_start_process_runner_promotion_packet.schema_version",
        process_runner_promotion_packet.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_runner_promotion_packet.v1",
    )
    _expect(
        "guarded_start_process_runner_promotion_packet.guarded_start_process_runner_promotion_packet_implemented",
        process_runner_promotion_packet.get("guarded_start_process_runner_promotion_packet_implemented"),
        True,
    )
    _expect(
        "guarded_start_process_runner_promotion_packet.process_runner_promotion_packet_only",
        process_runner_promotion_packet.get("process_runner_promotion_packet_only"),
        True,
    )
    _expect("guarded_start_process_runner_promotion_packet.guarded_start_executor_enabled", process_runner_promotion_packet.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_process_runner_promotion_packet.guarded_start_executor_implemented", process_runner_promotion_packet.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_process_runner_promotion_packet.final_start_executor_enabled", process_runner_promotion_packet.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_process_runner_promotion_packet.runtime_policy_start_enabled",
        process_runner_promotion_packet.get("runtime_policy_start_enabled"),
        process_runner_promotion_packet.get("status") == "ready_for_guarded_start_process_runner_operator_release",
    )
    _expect("guarded_start_process_runner_promotion_packet.real_start_adapter_enabled", process_runner_promotion_packet.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_process_runner_promotion_packet.start_execution_allowed", process_runner_promotion_packet.get("start_execution_allowed"), False)
    _expect("guarded_start_process_runner_promotion_packet.real_subprocess_start_implemented", process_runner_promotion_packet.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_process_runner_promotion_packet.process_launch_attempted", process_runner_promotion_packet.get("process_launch_attempted"), False)
    _expect("guarded_start_process_runner_promotion_packet.daemon_started", process_runner_promotion_packet.get("daemon_started"), False)
    _expect("guarded_start_process_runner_promotion_packet.process_launch_allowed", process_runner_promotion_packet.get("process_launch_allowed"), False)
    _expect("guarded_start_process_runner_promotion_packet.subprocess_module_imported", process_runner_promotion_packet.get("subprocess_module_imported"), False)
    _expect("guarded_start_process_runner_promotion_packet.livekit_sdk_imported", process_runner_promotion_packet.get("livekit_sdk_imported"), False)
    _expect("guarded_start_process_runner_promotion_packet.provider_calls_made", process_runner_promotion_packet.get("provider_calls_made"), False)
    _expect("guarded_start_process_runner_promotion_packet.tool_calls_made", process_runner_promotion_packet.get("tool_calls_made"), False)
    _expect("guarded_start_process_runner_promotion_packet.raw_audio_touched", process_runner_promotion_packet.get("raw_audio_touched"), False)

    process_runner_operator_release_review = _expect_mapping(
        "guarded_start_process_runner_operator_release_review",
        payload.get("guarded_start_process_runner_operator_release_review"),
    )
    _expect(
        "guarded_start_process_runner_operator_release_review.schema_version",
        process_runner_operator_release_review.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_runner_operator_release_review.v1",
    )
    _expect(
        "guarded_start_process_runner_operator_release_review.guarded_start_process_runner_operator_release_review_implemented",
        process_runner_operator_release_review.get("guarded_start_process_runner_operator_release_review_implemented"),
        True,
    )
    _expect(
        "guarded_start_process_runner_operator_release_review.process_runner_operator_release_review_only",
        process_runner_operator_release_review.get("process_runner_operator_release_review_only"),
        True,
    )
    _expect("guarded_start_process_runner_operator_release_review.guarded_start_executor_enabled", process_runner_operator_release_review.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_process_runner_operator_release_review.guarded_start_executor_implemented", process_runner_operator_release_review.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_process_runner_operator_release_review.final_start_executor_enabled", process_runner_operator_release_review.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_process_runner_operator_release_review.runtime_policy_start_enabled",
        process_runner_operator_release_review.get("runtime_policy_start_enabled"),
        process_runner_operator_release_review.get("status") == "ready_for_guarded_start_process_runner_release_finalization",
    )
    _expect("guarded_start_process_runner_operator_release_review.real_start_adapter_enabled", process_runner_operator_release_review.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_process_runner_operator_release_review.start_execution_allowed", process_runner_operator_release_review.get("start_execution_allowed"), False)
    _expect("guarded_start_process_runner_operator_release_review.real_subprocess_start_implemented", process_runner_operator_release_review.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_process_runner_operator_release_review.process_launch_attempted", process_runner_operator_release_review.get("process_launch_attempted"), False)
    _expect("guarded_start_process_runner_operator_release_review.daemon_started", process_runner_operator_release_review.get("daemon_started"), False)
    _expect("guarded_start_process_runner_operator_release_review.process_launch_allowed", process_runner_operator_release_review.get("process_launch_allowed"), False)
    _expect("guarded_start_process_runner_operator_release_review.subprocess_module_imported", process_runner_operator_release_review.get("subprocess_module_imported"), False)
    _expect("guarded_start_process_runner_operator_release_review.livekit_sdk_imported", process_runner_operator_release_review.get("livekit_sdk_imported"), False)
    _expect("guarded_start_process_runner_operator_release_review.provider_calls_made", process_runner_operator_release_review.get("provider_calls_made"), False)
    _expect("guarded_start_process_runner_operator_release_review.tool_calls_made", process_runner_operator_release_review.get("tool_calls_made"), False)
    _expect("guarded_start_process_runner_operator_release_review.raw_audio_touched", process_runner_operator_release_review.get("raw_audio_touched"), False)

    process_runner_release_finalization = _expect_mapping(
        "guarded_start_process_runner_release_finalization",
        payload.get("guarded_start_process_runner_release_finalization"),
    )
    _expect(
        "guarded_start_process_runner_release_finalization.schema_version",
        process_runner_release_finalization.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_runner_release_finalization.v1",
    )
    _expect(
        "guarded_start_process_runner_release_finalization.guarded_start_process_runner_release_finalization_implemented",
        process_runner_release_finalization.get("guarded_start_process_runner_release_finalization_implemented"),
        True,
    )
    _expect(
        "guarded_start_process_runner_release_finalization.process_runner_release_finalization_only",
        process_runner_release_finalization.get("process_runner_release_finalization_only"),
        True,
    )
    _expect("guarded_start_process_runner_release_finalization.guarded_start_executor_enabled", process_runner_release_finalization.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_process_runner_release_finalization.guarded_start_executor_implemented", process_runner_release_finalization.get("guarded_start_executor_implemented"), False)
    _expect("guarded_start_process_runner_release_finalization.final_start_executor_enabled", process_runner_release_finalization.get("final_start_executor_enabled"), False)
    _expect(
        "guarded_start_process_runner_release_finalization.runtime_policy_start_enabled",
        process_runner_release_finalization.get("runtime_policy_start_enabled"),
        process_runner_release_finalization.get("status") == "ready_for_guarded_start_process_runner_release_authorization",
    )
    _expect("guarded_start_process_runner_release_finalization.real_start_adapter_enabled", process_runner_release_finalization.get("real_start_adapter_enabled"), False)
    _expect("guarded_start_process_runner_release_finalization.start_execution_allowed", process_runner_release_finalization.get("start_execution_allowed"), False)
    _expect("guarded_start_process_runner_release_finalization.real_subprocess_start_implemented", process_runner_release_finalization.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_process_runner_release_finalization.process_launch_attempted", process_runner_release_finalization.get("process_launch_attempted"), False)
    _expect("guarded_start_process_runner_release_finalization.daemon_started", process_runner_release_finalization.get("daemon_started"), False)
    _expect("guarded_start_process_runner_release_finalization.process_launch_allowed", process_runner_release_finalization.get("process_launch_allowed"), False)
    _expect("guarded_start_process_runner_release_finalization.subprocess_module_imported", process_runner_release_finalization.get("subprocess_module_imported"), False)
    _expect("guarded_start_process_runner_release_finalization.livekit_sdk_imported", process_runner_release_finalization.get("livekit_sdk_imported"), False)
    _expect("guarded_start_process_runner_release_finalization.provider_calls_made", process_runner_release_finalization.get("provider_calls_made"), False)
    _expect("guarded_start_process_runner_release_finalization.tool_calls_made", process_runner_release_finalization.get("tool_calls_made"), False)
    _expect("guarded_start_process_runner_release_finalization.raw_audio_touched", process_runner_release_finalization.get("raw_audio_touched"), False)

    process_runner_release_authorization = _expect_mapping(
        "guarded_start_process_runner_release_authorization",
        payload.get("guarded_start_process_runner_release_authorization"),
    )
    _expect(
        "guarded_start_process_runner_release_authorization.schema_version",
        process_runner_release_authorization.get("schema_version"),
        "atlas.voice_realtime.guarded_start_process_runner_release_authorization_contract.v1",
    )
    _expect(
        "guarded_start_process_runner_release_authorization.guarded_start_process_runner_release_authorization_implemented",
        process_runner_release_authorization.get("guarded_start_process_runner_release_authorization_implemented"),
        True,
    )
    _expect(
        "guarded_start_process_runner_release_authorization.process_runner_release_authorization_only",
        process_runner_release_authorization.get("process_runner_release_authorization_only"),
        True,
    )
    _expect("guarded_start_process_runner_release_authorization.guard_start_executor_enabled", process_runner_release_authorization.get("guarded_start_executor_enabled"), False)
    _expect("guarded_start_process_runner_release_authorization.guard_start_executor_implemented", process_runner_release_authorization.get("guarded_start_executor_implemented"), False)
    _expect(
        "guarded_start_process_runner_release_authorization.runtime_policy_start_enabled",
        process_runner_release_authorization.get("runtime_policy_start_enabled"),
        process_runner_release_authorization.get("status") == "ready_for_controlled_livekit_server_supervised_smoke",
    )
    _expect("guarded_start_process_runner_release_authorization.start_execution_allowed", process_runner_release_authorization.get("start_execution_allowed"), False)
    _expect("guarded_start_process_runner_release_authorization.real_subprocess_start_implemented", process_runner_release_authorization.get("real_subprocess_start_implemented"), False)
    _expect("guarded_start_process_runner_release_authorization.process_launch_attempted", process_runner_release_authorization.get("process_launch_attempted"), False)
    _expect("guarded_start_process_runner_release_authorization.daemon_started", process_runner_release_authorization.get("daemon_started"), False)
    _expect("guarded_start_process_runner_release_authorization.process_launch_allowed", process_runner_release_authorization.get("process_launch_allowed"), False)
    _expect("guarded_start_process_runner_release_authorization.subprocess_module_imported", process_runner_release_authorization.get("subprocess_module_imported"), False)
    _expect("guarded_start_process_runner_release_authorization.livekit_sdk_imported", process_runner_release_authorization.get("livekit_sdk_imported"), False)
    _expect("guarded_start_process_runner_release_authorization.provider_calls_made", process_runner_release_authorization.get("provider_calls_made"), False)
    _expect("guarded_start_process_runner_release_authorization.tool_calls_made", process_runner_release_authorization.get("tool_calls_made"), False)
    _expect("guarded_start_process_runner_release_authorization.raw_audio_touched", process_runner_release_authorization.get("raw_audio_touched"), False)

    controlled_smoke_contract = _expect_mapping(
        "controlled_livekit_server_supervised_smoke_contract",
        payload.get("controlled_livekit_server_supervised_smoke_contract"),
    )
    _expect(
        "controlled_livekit_server_supervised_smoke_contract.schema_version",
        controlled_smoke_contract.get("schema_version"),
        "atlas.voice_realtime.controlled_livekit_server_supervised_smoke_contract.v1",
    )
    _expect(
        "controlled_livekit_server_supervised_smoke_contract.controlled_livekit_server_supervised_smoke_contract_implemented",
        controlled_smoke_contract.get("controlled_livekit_server_supervised_smoke_contract_implemented"),
        True,
    )
    _expect("controlled_livekit_server_supervised_smoke_contract.controlled_smoke_only", controlled_smoke_contract.get("controlled_smoke_only"), True)
    _expect("controlled_livekit_server_supervised_smoke_contract.guarded_start_executor_enabled", controlled_smoke_contract.get("guarded_start_executor_enabled"), False)
    _expect("controlled_livekit_server_supervised_smoke_contract.guarded_start_executor_implemented", controlled_smoke_contract.get("guarded_start_executor_implemented"), False)
    _expect(
        "controlled_livekit_server_supervised_smoke_contract.runtime_policy_start_enabled",
        controlled_smoke_contract.get("runtime_policy_start_enabled"),
        controlled_smoke_contract.get("status") == "ready_for_supervised_voice_worker_handshake_smoke",
    )
    _expect("controlled_livekit_server_supervised_smoke_contract.start_execution_allowed", controlled_smoke_contract.get("start_execution_allowed"), False)
    _expect("controlled_livekit_server_supervised_smoke_contract.real_subprocess_start_implemented", controlled_smoke_contract.get("real_subprocess_start_implemented"), False)
    _expect("controlled_livekit_server_supervised_smoke_contract.process_launch_attempted", controlled_smoke_contract.get("process_launch_attempted"), False)
    _expect("controlled_livekit_server_supervised_smoke_contract.daemon_started", controlled_smoke_contract.get("daemon_started"), False)
    _expect("controlled_livekit_server_supervised_smoke_contract.process_launch_allowed", controlled_smoke_contract.get("process_launch_allowed"), False)
    _expect("controlled_livekit_server_supervised_smoke_contract.subprocess_module_imported", controlled_smoke_contract.get("subprocess_module_imported"), False)
    _expect("controlled_livekit_server_supervised_smoke_contract.livekit_sdk_imported", controlled_smoke_contract.get("livekit_sdk_imported"), False)
    _expect("controlled_livekit_server_supervised_smoke_contract.provider_calls_made", controlled_smoke_contract.get("provider_calls_made"), False)
    _expect("controlled_livekit_server_supervised_smoke_contract.tool_calls_made", controlled_smoke_contract.get("tool_calls_made"), False)
    _expect("controlled_livekit_server_supervised_smoke_contract.raw_audio_touched", controlled_smoke_contract.get("raw_audio_touched"), False)

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
