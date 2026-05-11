from __future__ import annotations

import unittest

from atlas_voice_agent.daemon_supervisor import evaluate_daemon_supervisor
from atlas_voice_agent.supervised_process_adapter import (
    SCHEMA_VERSION,
    inspect_supervised_process_adapter,
)
from test_daemon_supervisor import ready_worker_start


class SupervisedProcessAdapterTest(unittest.TestCase):
    def test_inspects_ready_supervisor_without_launching_process(self) -> None:
        supervisor_execution = evaluate_daemon_supervisor(ready_worker_start())
        payload = inspect_supervised_process_adapter(supervisor_execution)

        self.assertEqual(SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("atlas.voice_realtime.supervised_process_adapter.v1", payload["schema_version"])
        self.assertEqual("ready_fail_closed", payload["status"])
        self.assertEqual("reviewed_shell_no_launch", payload["implementation_status"])
        self.assertFalse(payload["launch_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertIn("start_worker_process", payload["implemented_methods"])
        self.assertIn("execute_managed_env_write", payload["implemented_methods"])
        self.assertTrue(payload["gates"]["supervisor_execution_ready"])
        self.assertTrue(payload["gates"]["launch_disabled"])
        self.assertTrue(payload["gates"]["secrets_redacted"])
        self.assertTrue(payload["gates"]["managed_environment_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.managed_env_contract.v1",
            payload["managed_environment_contract"]["schema_version"],
        )
        self.assertFalse(payload["managed_environment_contract"]["env_file_write_attempted"])
        self.assertFalse(payload["managed_environment_contract"]["env_file_written"])
        self.assertFalse(payload["managed_environment_contract"]["secret_values_present_in_output"])
        self.assertFalse(payload["managed_environment_contract"]["env_value_logging_allowed"])
        self.assertIn("ATLAS_TOKEN", payload["managed_environment_contract"]["required_secret_env_refs"])
        self.assertEqual(
            "<secret-ref:ATLAS_TOKEN>",
            payload["managed_environment_contract"]["env_manifest_template"]["ATLAS_TOKEN"],
        )
        self.assertEqual(
            "<env-ref:ATLAS_BASE_URL>",
            payload["managed_environment_contract"]["env_manifest_template"]["ATLAS_BASE_URL"],
        )
        self.assertIn(
            "write_env_file_in_contract_shell",
            payload["managed_environment_contract"]["forbidden_shortcuts"],
        )
        self.assertTrue(payload["gates"]["launch_authorization_contract_available"])
        self.assertTrue(payload["gates"]["launch_authorization_contract_ready"])
        self.assertEqual(
            "atlas.voice_realtime.launch_authorization_contract.v1",
            payload["launch_authorization_contract"]["schema_version"],
        )
        self.assertEqual(
            "authorized_for_implementation_not_launch",
            payload["launch_authorization_contract"]["status"],
        )
        self.assertFalse(payload["launch_authorization_contract"]["launch_allowed"])
        self.assertFalse(payload["launch_authorization_contract"]["process_launch_attempted"])
        self.assertFalse(payload["launch_authorization_contract"]["daemon_started"])
        self.assertTrue(payload["launch_authorization_contract"]["decision_receipt_required"])
        self.assertFalse(payload["launch_authorization_contract"]["managed_env_file_writer_implemented"])
        self.assertFalse(payload["launch_authorization_contract"]["subprocess_launch_implemented"])
        self.assertIn(
            "launch_execution_decision_receipt",
            payload["launch_authorization_contract"]["required_receipts"],
        )
        self.assertTrue(payload["launch_authorization_contract"]["gates"]["process_launch_disabled"])
        self.assertIn(
            "start_process_from_authorization_contract",
            payload["launch_authorization_contract"]["forbidden_shortcuts"],
        )
        self.assertTrue(payload["gates"]["managed_env_writer_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.managed_env_writer.v1",
            payload["managed_env_writer"]["schema_version"],
        )
        self.assertEqual("ready_for_write_implementation", payload["managed_env_writer"]["status"])
        self.assertTrue(payload["managed_env_writer"]["writer_contract_implemented"])
        self.assertTrue(payload["managed_env_writer"]["write_execution_available"])
        self.assertFalse(payload["managed_env_writer"]["write_execution_implemented"])
        self.assertFalse(payload["managed_env_writer"]["env_file_write_attempted"])
        self.assertFalse(payload["managed_env_writer"]["env_file_written"])
        self.assertFalse(payload["managed_env_writer"]["secret_values_present_in_output"])
        self.assertIn(
            "write_env_file_from_writer_contract",
            payload["managed_env_writer"]["forbidden_shortcuts"],
        )
        self.assertEqual("blocked_launch_not_implemented", payload["start_attempt"]["status"])
        self.assertFalse(payload["start_attempt"]["process_launch_attempted"])
        self.assertFalse(payload["start_attempt"]["daemon_started"])
        self.assertIn("import_subprocess_in_contract_shell", payload["start_attempt"]["forbidden_shortcuts"])
        self.assertEqual("atlas.voice_realtime.supervised_process_adapter_health.v1", payload["health_snapshot"]["schema_version"])
        self.assertFalse(payload["health_snapshot"]["process_launch_attempted"])
        self.assertIn("VOICE_DAEMON_PROCESS_ADAPTER_INSPECTED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_MANAGED_ENV_CONTRACT_DECLARED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_LAUNCH_AUTHORIZATION_DECLARED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_MANAGED_ENV_WRITER_EVALUATED", payload["evidence_events"])

    def test_blocks_when_supervisor_execution_is_not_ready(self) -> None:
        worker_start = ready_worker_start()
        worker_start["production_promotion_review_valid"] = False
        supervisor_execution = evaluate_daemon_supervisor(worker_start)

        payload = inspect_supervised_process_adapter(supervisor_execution)

        self.assertEqual("blocked", payload["status"])
        self.assertFalse(payload["gates"]["supervisor_execution_ready"])
        self.assertFalse(payload["launch_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertTrue(payload["gates"]["launch_authorization_contract_available"])
        self.assertFalse(payload["gates"]["launch_authorization_contract_ready"])
        self.assertTrue(payload["gates"]["managed_env_writer_contract_available"])
        self.assertEqual("blocked", payload["launch_authorization_contract"]["status"])
        self.assertEqual("fix_supervised_process_adapter_prerequisites", payload["next_action"])


if __name__ == "__main__":
    unittest.main()
