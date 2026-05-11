from __future__ import annotations

import unittest
from pathlib import Path
from tempfile import TemporaryDirectory

from atlas_voice_agent.managed_env_writer import (
    SCHEMA_VERSION,
    execute_managed_env_write,
    inspect_managed_env_writer,
)


def managed_environment_contract() -> dict[str, object]:
    return {
        "schema_version": "atlas.voice_realtime.managed_env_contract.v1",
        "status": "prepared_contract_only",
        "env_value_logging_allowed": False,
        "env_file_write_attempted": False,
        "env_file_written": False,
        "required_env_refs": ["ATLAS_BASE_URL", "ATLAS_TOKEN"],
        "required_public_env_refs": ["ATLAS_BASE_URL"],
        "required_secret_env_refs": ["ATLAS_TOKEN"],
        "env_manifest_template": {
            "ATLAS_BASE_URL": "<env-ref:ATLAS_BASE_URL>",
            "ATLAS_TOKEN": "<secret-ref:ATLAS_TOKEN>",
        },
        "secret_values_present_in_output": False,
        "managed_env_file_required": True,
        "managed_env_file_writer_implemented": False,
        "managed_env_file_path": "<managed-env-file>",
    }


def launch_authorization_contract() -> dict[str, object]:
    return {
        "schema_version": "atlas.voice_realtime.launch_authorization_contract.v1",
        "status": "authorized_for_implementation_not_launch",
        "launch_allowed": False,
        "process_launch_attempted": False,
        "daemon_started": False,
    }


class ManagedEnvWriterTest(unittest.TestCase):
    def test_writer_contract_is_ready_without_writing_env_file_or_leaking_secret(self) -> None:
        payload = inspect_managed_env_writer(
            managed_environment_contract=managed_environment_contract(),
            launch_authorization_contract=launch_authorization_contract(),
        )

        self.assertEqual(SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("atlas.voice_realtime.managed_env_writer.v1", payload["schema_version"])
        self.assertEqual("ready_for_write_implementation", payload["status"])
        self.assertEqual("contract_only_no_file_write", payload["implementation_status"])
        self.assertTrue(payload["writer_contract_implemented"])
        self.assertTrue(payload["write_execution_available"])
        self.assertFalse(payload["write_execution_implemented"])
        self.assertFalse(payload["env_file_write_attempted"])
        self.assertFalse(payload["env_file_written"])
        self.assertFalse(payload["env_value_logging_allowed"])
        self.assertFalse(payload["secret_values_present_in_output"])
        self.assertEqual("<managed-env-file>", payload["managed_env_file_path"])
        self.assertEqual(["ATLAS_BASE_URL", "ATLAS_TOKEN"], payload["required_env_refs"])
        self.assertEqual(["ATLAS_TOKEN"], payload["required_secret_env_refs"])
        self.assertEqual("<secret-ref:ATLAS_TOKEN>", payload["redacted_env_manifest"]["ATLAS_TOKEN"])
        self.assertTrue(payload["gates"]["managed_environment_contract_ready"])
        self.assertTrue(payload["gates"]["launch_authorization_contract_available"])
        self.assertTrue(payload["gates"]["manifest_placeholders_safe"])
        self.assertTrue(payload["gates"]["write_execution_disabled"])
        self.assertTrue(payload["gates"]["secret_values_redacted"])
        self.assertIn("VOICE_DAEMON_MANAGED_ENV_WRITER_EVALUATED", payload["evidence_events"])
        self.assertIn("write_env_file_from_writer_contract", payload["forbidden_shortcuts"])
        self.assertEqual("implement_reviewed_env_file_write_execution", payload["next_action"])

    def test_writer_blocks_unsafe_manifest_values(self) -> None:
        contract = managed_environment_contract()
        contract["env_manifest_template"] = {
            "ATLAS_TOKEN": "literal-secret-value",
        }

        payload = inspect_managed_env_writer(
            managed_environment_contract=contract,
            launch_authorization_contract=launch_authorization_contract(),
        )

        self.assertEqual("blocked", payload["status"])
        self.assertFalse(payload["gates"]["managed_environment_contract_ready"])
        self.assertFalse(payload["gates"]["manifest_placeholders_safe"])
        self.assertFalse(payload["env_file_write_attempted"])
        self.assertFalse(payload["secret_values_present_in_output"])
        self.assertEqual("fix_managed_env_writer_prerequisites", payload["next_action"])

    def test_execute_managed_env_write_requires_explicit_authorization(self) -> None:
        writer = inspect_managed_env_writer(
            managed_environment_contract=managed_environment_contract(),
            launch_authorization_contract=launch_authorization_contract(),
        )

        with TemporaryDirectory() as directory:
            payload = execute_managed_env_write(
                managed_env_writer=writer,
                target_path=Path(directory) / "atlas-voice-worker.env",
                write_authorization={
                    "schema_version": "atlas.voice_realtime.managed_env_write_authorization.v1",
                    "status": "pending",
                    "write_allowed": False,
                    "process_launch_allowed": False,
                    "decision_receipt_id": "",
                },
            )

        self.assertEqual("atlas.voice_realtime.managed_env_write_execution.v1", payload["schema_version"])
        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["write_execution_implemented"])
        self.assertFalse(payload["env_file_write_attempted"])
        self.assertFalse(payload["env_file_written"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["secret_values_present_in_output"])
        self.assertFalse(payload["gates"]["write_authorization_ready"])
        self.assertIn("VOICE_DAEMON_MANAGED_ENV_WRITE_BLOCKED", payload["evidence_events"])

    def test_execute_managed_env_write_writes_placeholder_only_file_without_starting_process(self) -> None:
        writer = inspect_managed_env_writer(
            managed_environment_contract=managed_environment_contract(),
            launch_authorization_contract=launch_authorization_contract(),
        )

        with TemporaryDirectory() as directory:
            target = Path(directory) / "atlas-voice-worker.env"
            payload = execute_managed_env_write(
                managed_env_writer=writer,
                target_path=target,
                write_authorization={
                    "schema_version": "atlas.voice_realtime.managed_env_write_authorization.v1",
                    "status": "approved",
                    "write_allowed": True,
                    "process_launch_allowed": False,
                    "decision_receipt_id": "decision_receipt_env_write_1",
                },
            )
            content = target.read_text(encoding="utf-8")
            mode = target.stat().st_mode & 0o777

        self.assertEqual("atlas.voice_realtime.managed_env_write_execution.v1", payload["schema_version"])
        self.assertEqual("written_placeholder_env", payload["status"])
        self.assertTrue(payload["write_execution_implemented"])
        self.assertTrue(payload["env_file_write_attempted"])
        self.assertTrue(payload["env_file_written"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["secret_values_present_in_output"])
        self.assertEqual(0o600, mode)
        self.assertIn("ATLAS_BASE_URL=<env-ref:ATLAS_BASE_URL>", content)
        self.assertIn("ATLAS_TOKEN=<secret-ref:ATLAS_TOKEN>", content)
        self.assertNotIn("literal-secret-value", content)
        self.assertIn("VOICE_DAEMON_MANAGED_ENV_WRITE_EXECUTED", payload["evidence_events"])

    def test_execute_managed_env_write_blocks_unsafe_target_path(self) -> None:
        writer = inspect_managed_env_writer(
            managed_environment_contract=managed_environment_contract(),
            launch_authorization_contract=launch_authorization_contract(),
        )

        with TemporaryDirectory() as directory:
            payload = execute_managed_env_write(
                managed_env_writer=writer,
                target_path=Path(directory) / "worker.env",
                write_authorization={
                    "schema_version": "atlas.voice_realtime.managed_env_write_authorization.v1",
                    "status": "approved",
                    "write_allowed": True,
                    "process_launch_allowed": False,
                    "decision_receipt_id": "decision_receipt_env_write_1",
                },
            )

        self.assertEqual("blocked", payload["status"])
        self.assertFalse(payload["env_file_write_attempted"])
        self.assertFalse(payload["gates"]["target_path_safe"])


if __name__ == "__main__":
    unittest.main()
