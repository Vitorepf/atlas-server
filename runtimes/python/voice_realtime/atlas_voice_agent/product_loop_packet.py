from __future__ import annotations

from typing import Any, Mapping


SCHEMA_VERSION = "atlas.voice_realtime.product_loop_check.v1"


class ProductLoopCheckViolation(RuntimeError):
    """Raised when a Kernel product-loop check is unsafe to consume."""


def validate_product_loop_check(payload: Mapping[str, Any]) -> Mapping[str, Any]:
    _expect("schema_version", payload.get("schema_version"), SCHEMA_VERSION)
    _expect("surface_id", payload.get("surface_id"), "voice_realtime")
    _expect("runtime_id", payload.get("runtime_id"), "livekit_agents_sdk")
    _expect("kernel_only", payload.get("kernel_only"), True)
    _expect("mobile_first", payload.get("mobile_first"), True)
    _expect("daemon_started", payload.get("daemon_started"), False)

    status = payload.get("status")
    if status not in [
        "blocked",
        "ready_for_human_review",
        "ready_for_daemon_implementation_review",
        "ready_for_supervised_start_implementation",
    ]:
        raise ProductLoopCheckViolation(f"status must be a governed voice product-loop state, got {status!r}")

    gates = _expect_mapping("gates", payload.get("gates"))
    for key in [
        "callback_loop_wired",
        "production_sdk_loop_wired",
        "worker_start_still_blocked",
        "production_promotion_blocked",
        "direct_provider_forbidden",
        "raw_audio_forbidden",
    ]:
        _expect_bool(f"gates.{key}", gates.get(key))

    guardrails = _expect_mapping("guardrails", payload.get("guardrails"))
    for key in [
        "direct_provider_call_allowed",
        "direct_tool_execution_allowed",
        "raw_audio_persistence_allowed",
        "access_token_log_allowed",
        "auto_promotion_allowed",
    ]:
        _expect(f"guardrails.{key}", guardrails.get(key), False)

    next_action = payload.get("next_action")
    if not (isinstance(next_action, str) and next_action.strip() != ""):
        raise ProductLoopCheckViolation("next_action must be a non-empty string")

    return payload


def _expect(path: str, actual: Any, expected: Any) -> None:
    if actual != expected:
        raise ProductLoopCheckViolation(f"{path} expected {expected!r}, got {actual!r}")


def _expect_bool(path: str, value: Any) -> None:
    if not isinstance(value, bool):
        raise ProductLoopCheckViolation(f"{path} must be a bool")


def _expect_mapping(path: str, value: Any) -> Mapping[str, Any]:
    if not isinstance(value, Mapping):
        raise ProductLoopCheckViolation(f"{path} must be an object")

    return value
