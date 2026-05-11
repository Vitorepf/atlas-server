from __future__ import annotations

from typing import Any, Mapping

from .payload_safety import reject_forbidden_keys_recursive
from .turn_payload import UnsafeVoicePayload


class DaemonSupervisorPacketViolation(RuntimeError):
    """Raised when the daemon supervisor output weakens the launch boundary."""


FORBIDDEN_DAEMON_SUPERVISOR_KEYS = {
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


def validate_daemon_supervisor_packet(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    try:
        reject_forbidden_keys_recursive(
            payload,
            FORBIDDEN_DAEMON_SUPERVISOR_KEYS,
            label="daemon_supervisor",
        )
    except UnsafeVoicePayload as exc:
        raise DaemonSupervisorPacketViolation(str(exc)) from exc

    _expect("schema_version", payload.get("schema_version"), "atlas.voice_realtime.daemon_supervisor_execution.v1")
    _expect("surface_id", payload.get("surface_id"), "voice_realtime")
    _expect("runtime_id", payload.get("runtime_id"), "livekit_agents_sdk")
    _expect("kernel_only", payload.get("kernel_only"), True)
    _expect("mobile_first", payload.get("mobile_first"), True)
    _expect("execution_mode", payload.get("execution_mode"), "supervised_contract_only")
    _expect("process_launch_attempted", payload.get("process_launch_attempted"), False)
    _expect("daemon_started", payload.get("daemon_started"), False)
    _expect("process_adapter_implemented", payload.get("process_adapter_implemented"), False)
    _expect("start_allowed", payload.get("start_allowed"), False)

    guardrails = _expect_mapping("guardrails", payload.get("guardrails"))
    for key in [
        "direct_provider_call_allowed",
        "direct_tool_execution_allowed",
        "raw_audio_persistence_allowed",
        "access_token_log_allowed",
        "start_without_decision_receipt_allowed",
        "start_without_supervisor_allowed",
        "process_launch_allowed_by_this_contract",
    ]:
        _expect(f"guardrails.{key}", guardrails.get(key), False)

    gates = _expect_mapping("gates", payload.get("gates"))
    _expect("gates.process_launch_disabled", gates.get("process_launch_disabled"), True)
    _expect_bool("gates.production_promotion_review_valid", gates.get("production_promotion_review_valid"))
    _expect_bool("gates.daemon_implementation_review_valid", gates.get("daemon_implementation_review_valid"))
    _expect_bool("gates.supervisor_preflight_ready", gates.get("supervisor_preflight_ready"))

    blueprint = _expect_mapping("process_adapter_blueprint", payload.get("process_adapter_blueprint"))
    _expect(
        "process_adapter_blueprint.schema_version",
        blueprint.get("schema_version"),
        "atlas.voice_realtime.daemon_process_adapter_blueprint.v1",
    )
    _expect("process_adapter_blueprint.launch_allowed", blueprint.get("launch_allowed"), False)
    _expect("process_adapter_blueprint.process_launch_attempted", blueprint.get("process_launch_attempted"), False)
    _expect("process_adapter_blueprint.daemon_started", blueprint.get("daemon_started"), False)
    secret_policy = _expect_mapping(
        "process_adapter_blueprint.secret_safe_output_policy",
        blueprint.get("secret_safe_output_policy"),
    )
    for key in ["log_env_values", "log_access_tokens", "log_livekit_secret", "log_raw_audio"]:
        _expect(f"process_adapter_blueprint.secret_safe_output_policy.{key}", secret_policy.get(key), False)

    adapter = _expect_mapping("supervised_process_adapter", payload.get("supervised_process_adapter"))
    _expect(
        "supervised_process_adapter.schema_version",
        adapter.get("schema_version"),
        "atlas.voice_realtime.supervised_process_adapter.v1",
    )
    _expect("supervised_process_adapter.launch_allowed", adapter.get("launch_allowed"), False)
    _expect("supervised_process_adapter.process_launch_attempted", adapter.get("process_launch_attempted"), False)
    _expect("supervised_process_adapter.daemon_started", adapter.get("daemon_started"), False)
    _expect("supervised_process_adapter.subprocess_module_imported", adapter.get("subprocess_module_imported"), False)
    _expect("supervised_process_adapter.livekit_sdk_imported", adapter.get("livekit_sdk_imported"), False)

    _validate_managed_environment(adapter)
    _validate_launch_authorization(adapter)
    _validate_supervised_launch_execution(adapter)
    _validate_subprocess_start_contract(adapter)
    _validate_start_attempt(adapter)

    return payload


def _validate_managed_environment(adapter: Mapping[str, Any]) -> None:
    env_contract = _expect_mapping("supervised_process_adapter.managed_environment_contract", adapter.get("managed_environment_contract"))
    _expect("managed_environment_contract.schema_version", env_contract.get("schema_version"), "atlas.voice_realtime.managed_env_contract.v1")
    _expect("managed_environment_contract.env_value_logging_allowed", env_contract.get("env_value_logging_allowed"), False)
    _expect("managed_environment_contract.env_file_write_attempted", env_contract.get("env_file_write_attempted"), False)
    _expect("managed_environment_contract.env_file_written", env_contract.get("env_file_written"), False)
    _expect("managed_environment_contract.secret_values_present_in_output", env_contract.get("secret_values_present_in_output"), False)


def _validate_launch_authorization(adapter: Mapping[str, Any]) -> None:
    launch_auth = _expect_mapping("supervised_process_adapter.launch_authorization_contract", adapter.get("launch_authorization_contract"))
    _expect(
        "launch_authorization_contract.schema_version",
        launch_auth.get("schema_version"),
        "atlas.voice_realtime.launch_authorization_contract.v1",
    )
    _expect("launch_authorization_contract.launch_allowed", launch_auth.get("launch_allowed"), False)
    _expect("launch_authorization_contract.process_launch_attempted", launch_auth.get("process_launch_attempted"), False)
    _expect("launch_authorization_contract.daemon_started", launch_auth.get("daemon_started"), False)
    _expect("launch_authorization_contract.decision_receipt_required", launch_auth.get("decision_receipt_required"), True)


def _validate_supervised_launch_execution(adapter: Mapping[str, Any]) -> None:
    launch_execution = _expect_mapping("supervised_process_adapter.supervised_launch_execution", adapter.get("supervised_launch_execution"))
    _expect(
        "supervised_launch_execution.schema_version",
        launch_execution.get("schema_version"),
        "atlas.voice_realtime.supervised_launch_execution.v1",
    )
    _expect("supervised_launch_execution.subprocess_launch_implemented", launch_execution.get("subprocess_launch_implemented"), False)
    _expect("supervised_launch_execution.process_launch_attempted", launch_execution.get("process_launch_attempted"), False)
    _expect("supervised_launch_execution.daemon_started", launch_execution.get("daemon_started"), False)
    _expect("supervised_launch_execution.launch_allowed", launch_execution.get("launch_allowed"), False)
    _expect("supervised_launch_execution.process_launch_allowed", launch_execution.get("process_launch_allowed"), False)
    _expect("supervised_launch_execution.subprocess_module_imported", launch_execution.get("subprocess_module_imported"), False)
    _expect("supervised_launch_execution.livekit_sdk_imported", launch_execution.get("livekit_sdk_imported"), False)


def _validate_subprocess_start_contract(adapter: Mapping[str, Any]) -> None:
    start_contract = _expect_mapping("supervised_process_adapter.subprocess_start_contract", adapter.get("subprocess_start_contract"))
    _expect(
        "subprocess_start_contract.schema_version",
        start_contract.get("schema_version"),
        "atlas.voice_realtime.subprocess_start_contract.v1",
    )
    _expect("subprocess_start_contract.subprocess_launch_implemented", start_contract.get("subprocess_launch_implemented"), False)
    _expect("subprocess_start_contract.process_launch_attempted", start_contract.get("process_launch_attempted"), False)
    _expect("subprocess_start_contract.daemon_started", start_contract.get("daemon_started"), False)
    _expect("subprocess_start_contract.launch_allowed", start_contract.get("launch_allowed"), False)
    _expect("subprocess_start_contract.process_launch_allowed", start_contract.get("process_launch_allowed"), False)
    _expect("subprocess_start_contract.subprocess_module_imported", start_contract.get("subprocess_module_imported"), False)
    _expect("subprocess_start_contract.livekit_sdk_imported", start_contract.get("livekit_sdk_imported"), False)
    _expect("subprocess_start_contract.provider_calls_made", start_contract.get("provider_calls_made"), False)
    _expect("subprocess_start_contract.tool_calls_made", start_contract.get("tool_calls_made"), False)
    _expect("subprocess_start_contract.raw_audio_touched", start_contract.get("raw_audio_touched"), False)


def _validate_start_attempt(adapter: Mapping[str, Any]) -> None:
    start_attempt = _expect_mapping("supervised_process_adapter.start_attempt", adapter.get("start_attempt"))
    _expect("start_attempt.process_launch_attempted", start_attempt.get("process_launch_attempted"), False)
    _expect("start_attempt.daemon_started", start_attempt.get("daemon_started"), False)
    _expect("start_attempt.launch_allowed", start_attempt.get("launch_allowed"), False)
    _expect("start_attempt.decision_receipt_required", start_attempt.get("decision_receipt_required"), True)


def _expect(path: str, actual: Any, expected: Any) -> None:
    if actual != expected:
        raise DaemonSupervisorPacketViolation(f"{path} expected {expected!r}, got {actual!r}")


def _expect_bool(path: str, value: Any) -> None:
    if not isinstance(value, bool):
        raise DaemonSupervisorPacketViolation(f"{path} must be a bool")


def _expect_mapping(path: str, value: Any) -> Mapping[str, Any]:
    if not isinstance(value, Mapping):
        raise DaemonSupervisorPacketViolation(f"{path} must be an object")

    return value
