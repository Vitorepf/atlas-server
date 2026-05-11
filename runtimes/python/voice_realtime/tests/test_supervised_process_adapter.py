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
        self.assertIn("inspect_supervised_launch_execution", payload["implemented_methods"])
        self.assertIn("execute_pre_start_health_checks", payload["implemented_methods"])
        self.assertIn("inspect_subprocess_start_contract", payload["implemented_methods"])
        self.assertIn("inspect_reviewed_subprocess_start_execution", payload["implemented_methods"])
        self.assertIn("inspect_real_start_adapter_disabled_by_default", payload["implemented_methods"])
        self.assertIn("inspect_real_start_adapter_enablement_gate", payload["implemented_methods"])
        self.assertIn("inspect_runtime_policy_enablement_review", payload["implemented_methods"])
        self.assertIn("inspect_real_start_adapter_review_contract", payload["implemented_methods"])
        self.assertIn("inspect_reviewed_real_start_execution_contract", payload["implemented_methods"])
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
        self.assertTrue(payload["gates"]["supervised_launch_execution_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.supervised_launch_execution.v1",
            payload["supervised_launch_execution"]["schema_version"],
        )
        self.assertEqual("blocked", payload["supervised_launch_execution"]["status"])
        self.assertTrue(payload["supervised_launch_execution"]["launch_execution_implemented"])
        self.assertTrue(payload["supervised_launch_execution"]["pre_start_health_checks_execution_available"])
        self.assertFalse(payload["supervised_launch_execution"]["pre_start_health_checks_executed"])
        self.assertFalse(payload["supervised_launch_execution"]["subprocess_launch_implemented"])
        self.assertFalse(payload["supervised_launch_execution"]["process_launch_attempted"])
        self.assertFalse(payload["supervised_launch_execution"]["daemon_started"])
        self.assertFalse(payload["supervised_launch_execution"]["gates"]["managed_env_write_execution_ready"])
        self.assertIn(
            "start_without_launch_execution_decision_receipt",
            payload["supervised_launch_execution"]["forbidden_shortcuts"],
        )
        self.assertTrue(payload["gates"]["subprocess_start_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.subprocess_start_contract.v1",
            payload["subprocess_start_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["subprocess_start_contract"]["status"])
        self.assertTrue(payload["subprocess_start_contract"]["subprocess_start_contract_implemented"])
        self.assertFalse(payload["subprocess_start_contract"]["subprocess_launch_implemented"])
        self.assertFalse(payload["subprocess_start_contract"]["process_launch_attempted"])
        self.assertFalse(payload["subprocess_start_contract"]["daemon_started"])
        self.assertFalse(payload["subprocess_start_contract"]["subprocess_module_imported"])
        self.assertFalse(payload["subprocess_start_contract"]["livekit_sdk_imported"])
        self.assertFalse(payload["subprocess_start_contract"]["gates"]["supervised_launch_ready"])
        self.assertFalse(payload["subprocess_start_contract"]["gates"]["pre_start_health_checks_passed"])
        self.assertTrue(payload["subprocess_start_contract"]["gates"]["process_launch_disabled"])
        self.assertIn(
            "start_without_pre_start_health_checks_execution",
            payload["subprocess_start_contract"]["forbidden_shortcuts"],
        )
        self.assertTrue(payload["gates"]["reviewed_subprocess_start_execution_available"])
        self.assertEqual(
            "atlas.voice_realtime.reviewed_subprocess_start_execution.v1",
            payload["reviewed_subprocess_start_execution"]["schema_version"],
        )
        self.assertEqual("blocked", payload["reviewed_subprocess_start_execution"]["status"])
        self.assertTrue(payload["reviewed_subprocess_start_execution"]["reviewed_subprocess_start_execution_implemented"])
        self.assertFalse(payload["reviewed_subprocess_start_execution"]["real_subprocess_start_implemented"])
        self.assertFalse(payload["reviewed_subprocess_start_execution"]["process_launch_attempted"])
        self.assertFalse(payload["reviewed_subprocess_start_execution"]["daemon_started"])
        self.assertFalse(payload["reviewed_subprocess_start_execution"]["subprocess_module_imported"])
        self.assertFalse(payload["reviewed_subprocess_start_execution"]["livekit_sdk_imported"])
        self.assertFalse(payload["reviewed_subprocess_start_execution"]["gates"]["subprocess_start_contract_ready"])
        self.assertFalse(payload["reviewed_subprocess_start_execution"]["gates"]["review_authorization_ready"])
        self.assertTrue(payload["gates"]["real_start_adapter_disabled_available"])
        self.assertEqual(
            "atlas.voice_realtime.real_start_adapter_disabled.v1",
            payload["real_start_adapter_disabled"]["schema_version"],
        )
        self.assertEqual("blocked", payload["real_start_adapter_disabled"]["status"])
        self.assertTrue(payload["real_start_adapter_disabled"]["real_start_adapter_contract_implemented"])
        self.assertFalse(payload["real_start_adapter_disabled"]["real_start_adapter_enabled"])
        self.assertFalse(payload["real_start_adapter_disabled"]["real_subprocess_start_implemented"])
        self.assertFalse(payload["real_start_adapter_disabled"]["process_launch_attempted"])
        self.assertFalse(payload["real_start_adapter_disabled"]["daemon_started"])
        self.assertTrue(payload["gates"]["real_start_enablement_gate_available"])
        self.assertEqual(
            "atlas.voice_realtime.real_start_enablement_gate.v1",
            payload["real_start_enablement_gate"]["schema_version"],
        )
        self.assertEqual("blocked", payload["real_start_enablement_gate"]["status"])
        self.assertTrue(payload["real_start_enablement_gate"]["real_start_enablement_gate_implemented"])
        self.assertFalse(payload["real_start_enablement_gate"]["real_start_adapter_enabled"])
        self.assertFalse(payload["real_start_enablement_gate"]["start_execution_allowed"])
        self.assertFalse(payload["real_start_enablement_gate"]["real_subprocess_start_implemented"])
        self.assertFalse(payload["real_start_enablement_gate"]["process_launch_attempted"])
        self.assertFalse(payload["real_start_enablement_gate"]["daemon_started"])
        self.assertTrue(payload["gates"]["runtime_policy_enablement_review_available"])
        self.assertEqual(
            "atlas.voice_realtime.runtime_policy_enablement_review.v1",
            payload["runtime_policy_enablement_review"]["schema_version"],
        )
        self.assertEqual("blocked", payload["runtime_policy_enablement_review"]["status"])
        self.assertTrue(payload["runtime_policy_enablement_review"]["runtime_policy_enablement_review_implemented"])
        self.assertFalse(payload["runtime_policy_enablement_review"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["runtime_policy_enablement_review"]["real_start_adapter_enabled"])
        self.assertFalse(payload["runtime_policy_enablement_review"]["start_execution_allowed"])
        self.assertFalse(payload["runtime_policy_enablement_review"]["real_subprocess_start_implemented"])
        self.assertFalse(payload["runtime_policy_enablement_review"]["process_launch_attempted"])
        self.assertFalse(payload["runtime_policy_enablement_review"]["daemon_started"])
        self.assertTrue(payload["gates"]["real_start_adapter_review_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.real_start_adapter_review_contract.v1",
            payload["real_start_adapter_review_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["real_start_adapter_review_contract"]["status"])
        self.assertTrue(payload["real_start_adapter_review_contract"]["real_start_adapter_review_contract_implemented"])
        self.assertFalse(payload["real_start_adapter_review_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["real_start_adapter_review_contract"]["real_start_adapter_enabled"])
        self.assertFalse(payload["real_start_adapter_review_contract"]["start_execution_allowed"])
        self.assertFalse(payload["real_start_adapter_review_contract"]["real_subprocess_start_implemented"])
        self.assertFalse(payload["real_start_adapter_review_contract"]["process_launch_attempted"])
        self.assertFalse(payload["real_start_adapter_review_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["reviewed_real_start_execution_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.reviewed_real_start_execution_contract.v1",
            payload["reviewed_real_start_execution_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["reviewed_real_start_execution_contract"]["status"])
        self.assertTrue(payload["reviewed_real_start_execution_contract"]["reviewed_real_start_execution_contract_implemented"])
        self.assertFalse(payload["reviewed_real_start_execution_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["reviewed_real_start_execution_contract"]["real_start_adapter_enabled"])
        self.assertFalse(payload["reviewed_real_start_execution_contract"]["start_execution_allowed"])
        self.assertFalse(payload["reviewed_real_start_execution_contract"]["real_subprocess_start_implemented"])
        self.assertFalse(payload["reviewed_real_start_execution_contract"]["process_launch_attempted"])
        self.assertFalse(payload["reviewed_real_start_execution_contract"]["daemon_started"])
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
        self.assertIn("VOICE_DAEMON_SUPERVISED_LAUNCH_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_SUBPROCESS_START_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_REVIEWED_SUBPROCESS_START_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_REAL_START_ADAPTER_DECLARED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_REAL_START_ENABLEMENT_GATE_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_RUNTIME_POLICY_ENABLEMENT_REVIEW_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_REAL_START_ADAPTER_REVIEW_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_REVIEWED_REAL_START_EXECUTION_CONTRACT_EVALUATED", payload["evidence_events"])

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
        self.assertTrue(payload["gates"]["supervised_launch_execution_contract_available"])
        self.assertTrue(payload["gates"]["subprocess_start_contract_available"])
        self.assertTrue(payload["gates"]["reviewed_subprocess_start_execution_available"])
        self.assertTrue(payload["gates"]["real_start_adapter_disabled_available"])
        self.assertTrue(payload["gates"]["real_start_enablement_gate_available"])
        self.assertTrue(payload["gates"]["runtime_policy_enablement_review_available"])
        self.assertTrue(payload["gates"]["real_start_adapter_review_contract_available"])
        self.assertTrue(payload["gates"]["reviewed_real_start_execution_contract_available"])
        self.assertEqual("blocked", payload["launch_authorization_contract"]["status"])
        self.assertEqual("fix_supervised_process_adapter_prerequisites", payload["next_action"])


if __name__ == "__main__":
    unittest.main()
