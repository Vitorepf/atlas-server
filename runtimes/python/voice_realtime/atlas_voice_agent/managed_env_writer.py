from __future__ import annotations

import hashlib
import os
from pathlib import Path
from typing import Any, Mapping


SCHEMA_VERSION = "atlas.voice_realtime.managed_env_writer.v1"
MANAGED_ENV_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.managed_env_contract.v1"
LAUNCH_AUTHORIZATION_CONTRACT_SCHEMA_VERSION = "atlas.voice_realtime.launch_authorization_contract.v1"
WRITE_AUTHORIZATION_SCHEMA_VERSION = "atlas.voice_realtime.managed_env_write_authorization.v1"
WRITE_EXECUTION_SCHEMA_VERSION = "atlas.voice_realtime.managed_env_write_execution.v1"


def inspect_managed_env_writer(
    *,
    managed_environment_contract: Mapping[str, Any],
    launch_authorization_contract: Mapping[str, Any],
) -> Mapping[str, Any]:
    """Return the governed env-file writer contract without writing a file.

    AP-687 needs a concrete writer boundary before process launch can be
    implemented, but the current production-promotion gate must remain
    fail-closed. This function therefore validates the contracts, renders only
    placeholders and keeps file writing disabled.
    """

    manifest = managed_environment_contract.get("env_manifest_template", {})
    manifest_is_safe = (
        isinstance(manifest, Mapping)
        and all(_safe_env_key(key) and _safe_placeholder(value) for key, value in manifest.items())
    )
    env_ready = (
        managed_environment_contract.get("schema_version") == MANAGED_ENV_CONTRACT_SCHEMA_VERSION
        and managed_environment_contract.get("env_file_write_attempted") is False
        and managed_environment_contract.get("env_file_written") is False
        and managed_environment_contract.get("secret_values_present_in_output") is False
        and manifest_is_safe
    )
    launch_contract_available = (
        launch_authorization_contract.get("schema_version") == LAUNCH_AUTHORIZATION_CONTRACT_SCHEMA_VERSION
        and launch_authorization_contract.get("launch_allowed") is False
        and launch_authorization_contract.get("process_launch_attempted") is False
        and launch_authorization_contract.get("daemon_started") is False
    )
    ready_for_write_implementation = env_ready and launch_contract_available

    return {
        "schema_version": SCHEMA_VERSION,
        "status": "ready_for_write_implementation" if ready_for_write_implementation else "blocked",
        "writer_id": "livekit_agents_managed_env_writer",
        "implementation_status": "contract_only_no_file_write",
        "writer_contract_implemented": True,
        "write_execution_available": True,
        "write_execution_implemented": False,
        "env_file_write_attempted": False,
        "env_file_written": False,
        "env_value_logging_allowed": False,
        "secret_values_present_in_output": False,
        "managed_env_file_path": "<managed-env-file>",
        "required_env_refs": list(managed_environment_contract.get("required_env_refs", [])),
        "required_public_env_refs": list(managed_environment_contract.get("required_public_env_refs", [])),
        "required_secret_env_refs": list(managed_environment_contract.get("required_secret_env_refs", [])),
        "redacted_env_manifest": dict(manifest) if isinstance(manifest, Mapping) else {},
        "gates": {
            "managed_environment_contract_ready": env_ready,
            "launch_authorization_contract_available": launch_contract_available,
            "manifest_placeholders_safe": manifest_is_safe,
            "write_execution_disabled": True,
            "secret_values_redacted": True,
            "process_launch_disabled": True,
        },
        "forbidden_shortcuts": [
            "write_env_file_from_writer_contract",
            "log_env_file_contents",
            "inline_livekit_secret",
            "inline_atlas_token",
            "reuse_stale_env_file",
            "start_process_after_env_render",
        ],
        "evidence_events": [
            "VOICE_DAEMON_MANAGED_ENV_WRITER_EVALUATED",
            "VOICE_DAEMON_MANAGED_ENV_WRITE_BLOCKED",
        ],
        "next_action": "implement_reviewed_env_file_write_execution" if ready_for_write_implementation else "fix_managed_env_writer_prerequisites",
    }


def execute_managed_env_write(
    *,
    managed_env_writer: Mapping[str, Any],
    target_path: Path,
    write_authorization: Mapping[str, Any],
) -> Mapping[str, Any]:
    """Write a placeholder-only managed env file after explicit authorization.

    This is the first executable writer boundary, not daemon start. It only
    accepts placeholder refs already validated by the writer contract, writes
    with owner-only permissions, never returns file contents and never launches
    a process.
    """

    writer_ready = (
        managed_env_writer.get("schema_version") == SCHEMA_VERSION
        and managed_env_writer.get("status") == "ready_for_write_implementation"
        and managed_env_writer.get("write_execution_available") is True
        and managed_env_writer.get("env_file_write_attempted") is False
        and managed_env_writer.get("secret_values_present_in_output") is False
    )
    authorization_ready = (
        write_authorization.get("schema_version") == WRITE_AUTHORIZATION_SCHEMA_VERSION
        and write_authorization.get("status") == "approved"
        and write_authorization.get("write_allowed") is True
        and write_authorization.get("process_launch_allowed") is False
        and isinstance(write_authorization.get("decision_receipt_id"), str)
        and write_authorization.get("decision_receipt_id") != ""
    )
    path_ready = _safe_target_path(target_path)
    manifest = managed_env_writer.get("redacted_env_manifest", {})
    manifest_ready = (
        isinstance(manifest, Mapping)
        and all(_safe_env_key(key) and _safe_placeholder(value) for key, value in manifest.items())
    )
    can_write = writer_ready and authorization_ready and path_ready and manifest_ready

    if not can_write:
        return {
            "schema_version": WRITE_EXECUTION_SCHEMA_VERSION,
            "status": "blocked",
            "write_execution_implemented": True,
            "env_file_write_attempted": False,
            "env_file_written": False,
            "process_launch_attempted": False,
            "daemon_started": False,
            "secret_values_present_in_output": False,
            "target_path": "<blocked>",
            "content_sha256": None,
            "gates": {
                "managed_env_writer_ready": writer_ready,
                "write_authorization_ready": authorization_ready,
                "target_path_safe": path_ready,
                "manifest_placeholders_safe": manifest_ready,
                "process_launch_disabled": True,
            },
            "evidence_events": [
                "VOICE_DAEMON_MANAGED_ENV_WRITE_BLOCKED",
            ],
            "next_action": "fix_managed_env_write_execution_prerequisites",
        }

    rendered = _render_env_manifest(manifest)
    target_path.parent.mkdir(parents=True, exist_ok=True)
    target_path.write_text(rendered, encoding="utf-8")
    os.chmod(target_path, 0o600)

    return {
        "schema_version": WRITE_EXECUTION_SCHEMA_VERSION,
        "status": "written_placeholder_env",
        "write_execution_implemented": True,
        "env_file_write_attempted": True,
        "env_file_written": True,
        "process_launch_attempted": False,
        "daemon_started": False,
        "secret_values_present_in_output": False,
        "target_path": str(target_path),
        "content_sha256": hashlib.sha256(rendered.encode("utf-8")).hexdigest(),
        "line_count": len(rendered.splitlines()),
        "byte_count": len(rendered.encode("utf-8")),
        "gates": {
            "managed_env_writer_ready": True,
            "write_authorization_ready": True,
            "target_path_safe": True,
            "manifest_placeholders_safe": True,
            "process_launch_disabled": True,
        },
        "evidence_events": [
            "VOICE_DAEMON_MANAGED_ENV_WRITE_EXECUTED",
        ],
        "next_action": "implement_supervised_subprocess_launch_with_written_env",
    }


def _safe_env_key(value: object) -> bool:
    if not isinstance(value, str) or value == "":
        return False

    return value.replace("_", "").isalnum() and value.upper() == value


def _safe_placeholder(value: object) -> bool:
    if not isinstance(value, str):
        return False
    if any(character in value for character in ["\n", "\r", "\x00"]):
        return False

    return (
        (value.startswith("<env-ref:") or value.startswith("<secret-ref:"))
        and value.endswith(">")
    )


def _safe_target_path(path: Path) -> bool:
    if path.name == "" or path.suffix != ".env":
        return False
    if not path.name.startswith("atlas-voice-"):
        return False
    if path.exists() and path.is_symlink():
        return False
    if any(character in str(path) for character in ["\n", "\r", "\x00"]):
        return False

    return True


def _render_env_manifest(manifest: Mapping[str, Any]) -> str:
    return "".join(
        f"{key}={manifest[key]}\n"
        for key in sorted(manifest)
    )
