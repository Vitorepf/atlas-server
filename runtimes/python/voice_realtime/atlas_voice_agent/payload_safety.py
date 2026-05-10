from __future__ import annotations

from collections.abc import Mapping, Sequence
from typing import Any


def reject_forbidden_keys_recursive(payload: Any, forbidden: set[str], *, label: str) -> None:
    """Reject sensitive keys even when an SDK nests them in metadata."""

    paths = _forbidden_paths(payload, forbidden)
    if paths:
        from .turn_payload import UnsafeVoicePayload

        raise UnsafeVoicePayload(f"forbidden {label} keys: {paths}")


def _forbidden_paths(value: Any, forbidden: set[str], prefix: str = "") -> list[str]:
    if isinstance(value, Mapping):
        paths: list[str] = []
        for raw_key, item in value.items():
            key = str(raw_key)
            path = f"{prefix}.{key}" if prefix else key
            if key in forbidden:
                paths.append(path)
                continue
            paths.extend(_forbidden_paths(item, forbidden, path))

        return paths

    if isinstance(value, Sequence) and not isinstance(value, (str, bytes, bytearray)):
        paths = []
        for index, item in enumerate(value):
            path = f"{prefix}[{index}]" if prefix else f"[{index}]"
            paths.extend(_forbidden_paths(item, forbidden, path))

        return paths

    return []
