from __future__ import annotations

import unittest
from pathlib import Path
from tempfile import TemporaryDirectory

from atlas_voice_agent.managed_env_writer import execute_managed_env_write, inspect_managed_env_writer
from atlas_voice_agent.managed_env_writer_packet import (
    ManagedEnvWriterPacketViolation,
    validate_managed_env_write_execution_packet,
    validate_managed_env_writer_packet,
)
from test_managed_env_writer import managed_environment_contract, launch_authorization_contract


class ManagedEnvWriterPacketTest(unittest.TestCase):
    def test_writer_packet_rejects_symbolic_path_escape(self) -> None:
        payload = dict(inspect_managed_env_writer(
            managed_environment_contract=managed_environment_contract(),
            launch_authorization_contract=launch_authorization_contract(),
        ))
        payload["managed_env_file_path"] = "/tmp/atlas-voice-worker.env"

        with self.assertRaisesRegex(ManagedEnvWriterPacketViolation, "symbolic"):
            validate_managed_env_writer_packet(payload)

    def test_writer_packet_rejects_nested_secret(self) -> None:
        payload = dict(inspect_managed_env_writer(
            managed_environment_contract=managed_environment_contract(),
            launch_authorization_contract=launch_authorization_contract(),
        ))
        payload["nested"] = {"secret_value": "leak"}

        with self.assertRaisesRegex(ManagedEnvWriterPacketViolation, "secret_value"):
            validate_managed_env_writer_packet(payload)

    def test_write_execution_packet_rejects_file_contents(self) -> None:
        writer = inspect_managed_env_writer(
            managed_environment_contract=managed_environment_contract(),
            launch_authorization_contract=launch_authorization_contract(),
        )

        with TemporaryDirectory() as directory:
            payload = dict(execute_managed_env_write(
                managed_env_writer=writer,
                target_path=Path(directory) / "atlas-voice-worker.env",
                write_authorization={
                    "schema_version": "atlas.voice_realtime.managed_env_write_authorization.v1",
                    "status": "approved",
                    "write_allowed": True,
                    "process_launch_allowed": False,
                    "decision_receipt_id": "decision_receipt_env_write_1",
                },
            ))

        payload["env_file_contents"] = "ATLAS_TOKEN=<secret-ref:ATLAS_TOKEN>"

        with self.assertRaisesRegex(ManagedEnvWriterPacketViolation, "env_file_contents"):
            validate_managed_env_write_execution_packet(payload)


if __name__ == "__main__":
    unittest.main()
