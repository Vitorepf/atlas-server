from __future__ import annotations

from typing import Any, Mapping

from .payload_safety import reject_forbidden_keys_recursive
from .turn_payload import UnsafeVoicePayload


class PacketValidator:
    """Shared fail-closed validation helpers for Kernel-owned voice packets."""

    def __init__(self, violation_type: type[RuntimeError]) -> None:
        self._violation_type = violation_type

    def reject_forbidden(self, payload: Mapping[str, Any], forbidden: set[str], *, label: str) -> None:
        try:
            reject_forbidden_keys_recursive(payload, forbidden, label=label)
        except UnsafeVoicePayload as exc:
            raise self._violation_type(str(exc)) from exc

    def expect(self, path: str, actual: Any, expected: Any) -> None:
        if actual != expected:
            raise self._violation_type(f"{path} expected {expected!r}, got {actual!r}")

    def expect_bool(self, path: str, value: Any) -> None:
        if not isinstance(value, bool):
            raise self._violation_type(f"{path} must be a bool")

    def expect_int(self, path: str, value: Any) -> int:
        if not isinstance(value, int):
            raise self._violation_type(f"{path} must be an int")

        return value

    def expect_mapping(self, path: str, value: Any) -> Mapping[str, Any]:
        if not isinstance(value, Mapping):
            raise self._violation_type(f"{path} must be an object")

        return value

    def expect_non_empty_list(self, path: str, value: Any) -> None:
        if not isinstance(value, list) or value == []:
            raise self._violation_type(f"{path} must be a non-empty list")

    def expect_sha256_hex(
        self,
        path: str,
        value: Any,
        *,
        lowercase: bool = True,
        error_message: str | None = None,
    ) -> None:
        if not isinstance(value, str) or len(value) != 64:
            raise self._violation_type(error_message or f"{path} must be a lowercase sha256 hex string")

        candidate = value if lowercase else value.lower()
        if any(char not in "0123456789abcdef" for char in candidate):
            raise self._violation_type(error_message or f"{path} must be a lowercase sha256 hex string")
