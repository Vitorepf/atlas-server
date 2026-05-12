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
        self.assertIn("inspect_final_start_executor_disabled_by_default", payload["implemented_methods"])
        self.assertIn("inspect_final_start_executor_enablement_gate", payload["implemented_methods"])
        self.assertIn("inspect_supervised_start_execution_review", payload["implemented_methods"])
        self.assertIn("inspect_real_start_execution_contract", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_executor_disabled_by_default", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_executor_enablement_gate", payload["implemented_methods"])
        self.assertIn("inspect_reviewed_guarded_start_execution_contract", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_dry_run_contract", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_simulation_contract", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_runtime_handoff_contract", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_policy_patch_review_contract", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_human_review_contract", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_final_enablement_gate_contract", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_policy_enablement_contract", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_activation_contract", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_execution_attempt_contract", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_execution_rehearsal_contract", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_observability_contract", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_release_candidate_contract", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_operator_acceptance_contract", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_final_start_receipt_contract", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_launch_window_contract", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_pre_launch_guard_contract", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_executor_runtime_contract", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_process_spawn_contract", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_spawn_review_contract", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_process_runner_promotion_packet", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_process_runner_operator_release_review", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_process_runner_release_finalization", payload["implemented_methods"])
        self.assertIn("inspect_guarded_start_process_runner_release_authorization", payload["implemented_methods"])
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
        self.assertTrue(payload["gates"]["final_start_executor_disabled_available"])
        self.assertEqual(
            "atlas.voice_realtime.final_start_executor_disabled.v1",
            payload["final_start_executor_disabled"]["schema_version"],
        )
        self.assertEqual("blocked", payload["final_start_executor_disabled"]["status"])
        self.assertTrue(payload["final_start_executor_disabled"]["final_start_executor_contract_implemented"])
        self.assertFalse(payload["final_start_executor_disabled"]["final_start_executor_enabled"])
        self.assertFalse(payload["final_start_executor_disabled"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["final_start_executor_disabled"]["process_launch_attempted"])
        self.assertFalse(payload["final_start_executor_disabled"]["daemon_started"])
        self.assertTrue(payload["gates"]["final_start_executor_enablement_gate_available"])
        self.assertEqual(
            "atlas.voice_realtime.final_start_executor_enablement_gate.v1",
            payload["final_start_executor_enablement_gate"]["schema_version"],
        )
        self.assertEqual("blocked", payload["final_start_executor_enablement_gate"]["status"])
        self.assertTrue(payload["final_start_executor_enablement_gate"]["final_start_executor_enablement_gate_implemented"])
        self.assertFalse(payload["final_start_executor_enablement_gate"]["final_start_executor_enabled"])
        self.assertFalse(payload["final_start_executor_enablement_gate"]["start_execution_allowed"])
        self.assertFalse(payload["final_start_executor_enablement_gate"]["process_launch_attempted"])
        self.assertFalse(payload["final_start_executor_enablement_gate"]["daemon_started"])
        self.assertTrue(payload["gates"]["supervised_start_execution_review_available"])
        self.assertEqual(
            "atlas.voice_realtime.supervised_start_execution_review.v1",
            payload["supervised_start_execution_review"]["schema_version"],
        )
        self.assertEqual("blocked", payload["supervised_start_execution_review"]["status"])
        self.assertTrue(payload["supervised_start_execution_review"]["supervised_start_execution_review_implemented"])
        self.assertFalse(payload["supervised_start_execution_review"]["start_execution_allowed"])
        self.assertFalse(payload["supervised_start_execution_review"]["process_launch_attempted"])
        self.assertFalse(payload["supervised_start_execution_review"]["daemon_started"])
        self.assertTrue(payload["gates"]["real_start_execution_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.real_start_execution_contract.v1",
            payload["real_start_execution_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["real_start_execution_contract"]["status"])
        self.assertTrue(payload["real_start_execution_contract"]["real_start_execution_contract_implemented"])
        self.assertFalse(payload["real_start_execution_contract"]["guarded_start_executor_implemented"])
        self.assertFalse(payload["real_start_execution_contract"]["start_execution_allowed"])
        self.assertFalse(payload["real_start_execution_contract"]["process_launch_attempted"])
        self.assertFalse(payload["real_start_execution_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_executor_disabled_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_executor_disabled.v1",
            payload["guarded_start_executor_disabled"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_executor_disabled"]["status"])
        self.assertTrue(payload["guarded_start_executor_disabled"]["guarded_start_executor_contract_implemented"])
        self.assertFalse(payload["guarded_start_executor_disabled"]["guarded_start_executor_enabled"])
        self.assertFalse(payload["guarded_start_executor_disabled"]["guarded_start_executor_implemented"])
        self.assertFalse(payload["guarded_start_executor_disabled"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_executor_disabled"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_executor_enablement_gate_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_executor_enablement_gate.v1",
            payload["guarded_start_executor_enablement_gate"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_executor_enablement_gate"]["status"])
        self.assertTrue(payload["guarded_start_executor_enablement_gate"]["guarded_start_executor_enablement_gate_implemented"])
        self.assertFalse(payload["guarded_start_executor_enablement_gate"]["guarded_start_executor_enabled"])
        self.assertFalse(payload["guarded_start_executor_enablement_gate"]["guarded_start_executor_implemented"])
        self.assertFalse(payload["guarded_start_executor_enablement_gate"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_executor_enablement_gate"]["daemon_started"])
        self.assertTrue(payload["gates"]["reviewed_guarded_start_execution_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.reviewed_guarded_start_execution_contract.v1",
            payload["reviewed_guarded_start_execution_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["reviewed_guarded_start_execution_contract"]["status"])
        self.assertTrue(payload["reviewed_guarded_start_execution_contract"]["reviewed_guarded_start_execution_contract_implemented"])
        self.assertFalse(payload["reviewed_guarded_start_execution_contract"]["guarded_start_executor_enabled"])
        self.assertFalse(payload["reviewed_guarded_start_execution_contract"]["guarded_start_executor_implemented"])
        self.assertFalse(payload["reviewed_guarded_start_execution_contract"]["process_launch_attempted"])
        self.assertFalse(payload["reviewed_guarded_start_execution_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_dry_run_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_dry_run_contract.v1",
            payload["guarded_start_dry_run_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_dry_run_contract"]["status"])
        self.assertTrue(payload["guarded_start_dry_run_contract"]["guarded_start_dry_run_contract_implemented"])
        self.assertTrue(payload["guarded_start_dry_run_contract"]["dry_run_only"])
        self.assertFalse(payload["guarded_start_dry_run_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_dry_run_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_simulation_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_simulation_contract.v1",
            payload["guarded_start_simulation_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_simulation_contract"]["status"])
        self.assertTrue(payload["guarded_start_simulation_contract"]["guarded_start_simulation_contract_implemented"])
        self.assertTrue(payload["guarded_start_simulation_contract"]["simulation_only"])
        self.assertFalse(payload["guarded_start_simulation_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_simulation_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_runtime_handoff_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_runtime_handoff_contract.v1",
            payload["guarded_start_runtime_handoff_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_runtime_handoff_contract"]["status"])
        self.assertTrue(payload["guarded_start_runtime_handoff_contract"]["guarded_start_runtime_handoff_contract_implemented"])
        self.assertTrue(payload["guarded_start_runtime_handoff_contract"]["runtime_handoff_contract_only"])
        self.assertFalse(payload["guarded_start_runtime_handoff_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_runtime_handoff_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_policy_patch_review_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_policy_patch_review_contract.v1",
            payload["guarded_start_policy_patch_review_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_policy_patch_review_contract"]["status"])
        self.assertTrue(payload["guarded_start_policy_patch_review_contract"]["guarded_start_policy_patch_review_contract_implemented"])
        self.assertTrue(payload["guarded_start_policy_patch_review_contract"]["policy_patch_review_only"])
        self.assertFalse(payload["guarded_start_policy_patch_review_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_policy_patch_review_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_policy_patch_review_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_human_review_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_human_review_contract.v1",
            payload["guarded_start_human_review_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_human_review_contract"]["status"])
        self.assertTrue(payload["guarded_start_human_review_contract"]["guarded_start_human_review_contract_implemented"])
        self.assertTrue(payload["guarded_start_human_review_contract"]["human_review_contract_only"])
        self.assertFalse(payload["guarded_start_human_review_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_human_review_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_human_review_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_final_enablement_gate_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_final_enablement_gate.v1",
            payload["guarded_start_final_enablement_gate"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_final_enablement_gate"]["status"])
        self.assertTrue(payload["guarded_start_final_enablement_gate"]["guarded_start_final_enablement_gate_implemented"])
        self.assertTrue(payload["guarded_start_final_enablement_gate"]["final_enablement_gate_only"])
        self.assertFalse(payload["guarded_start_final_enablement_gate"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_final_enablement_gate"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_final_enablement_gate"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_policy_enablement_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_policy_enablement_contract.v1",
            payload["guarded_start_policy_enablement_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_policy_enablement_contract"]["status"])
        self.assertTrue(payload["guarded_start_policy_enablement_contract"]["guarded_start_policy_enablement_contract_implemented"])
        self.assertTrue(payload["guarded_start_policy_enablement_contract"]["policy_enablement_contract_only"])
        self.assertFalse(payload["guarded_start_policy_enablement_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_policy_enablement_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_policy_enablement_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_activation_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_activation_contract.v1",
            payload["guarded_start_activation_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_activation_contract"]["status"])
        self.assertTrue(payload["guarded_start_activation_contract"]["guarded_start_activation_contract_implemented"])
        self.assertTrue(payload["guarded_start_activation_contract"]["activation_contract_only"])
        self.assertFalse(payload["guarded_start_activation_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_activation_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_activation_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_execution_attempt_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_execution_attempt_contract.v1",
            payload["guarded_start_execution_attempt_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_execution_attempt_contract"]["status"])
        self.assertTrue(
            payload["guarded_start_execution_attempt_contract"][
                "guarded_start_execution_attempt_contract_implemented"
            ]
        )
        self.assertTrue(payload["guarded_start_execution_attempt_contract"]["execution_attempt_contract_only"])
        self.assertFalse(payload["guarded_start_execution_attempt_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_execution_attempt_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_execution_attempt_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_execution_rehearsal_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_execution_rehearsal_contract.v1",
            payload["guarded_start_execution_rehearsal_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_execution_rehearsal_contract"]["status"])
        self.assertTrue(
            payload["guarded_start_execution_rehearsal_contract"][
                "guarded_start_execution_rehearsal_contract_implemented"
            ]
        )
        self.assertTrue(payload["guarded_start_execution_rehearsal_contract"]["execution_rehearsal_contract_only"])
        self.assertFalse(payload["guarded_start_execution_rehearsal_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_execution_rehearsal_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_execution_rehearsal_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_observability_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_observability_contract.v1",
            payload["guarded_start_observability_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_observability_contract"]["status"])
        self.assertTrue(payload["guarded_start_observability_contract"]["guarded_start_observability_contract_implemented"])
        self.assertTrue(payload["guarded_start_observability_contract"]["observability_contract_only"])
        self.assertFalse(payload["guarded_start_observability_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_observability_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_observability_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_release_candidate_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_release_candidate_contract.v1",
            payload["guarded_start_release_candidate_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_release_candidate_contract"]["status"])
        self.assertTrue(payload["guarded_start_release_candidate_contract"]["guarded_start_release_candidate_contract_implemented"])
        self.assertTrue(payload["guarded_start_release_candidate_contract"]["release_candidate_contract_only"])
        self.assertFalse(payload["guarded_start_release_candidate_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_release_candidate_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_release_candidate_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_operator_acceptance_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_operator_acceptance_contract.v1",
            payload["guarded_start_operator_acceptance_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_operator_acceptance_contract"]["status"])
        self.assertTrue(payload["guarded_start_operator_acceptance_contract"]["guarded_start_operator_acceptance_contract_implemented"])
        self.assertTrue(payload["guarded_start_operator_acceptance_contract"]["operator_acceptance_contract_only"])
        self.assertFalse(payload["guarded_start_operator_acceptance_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_operator_acceptance_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_operator_acceptance_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_final_start_receipt_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_final_start_receipt_contract.v1",
            payload["guarded_start_final_start_receipt_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_final_start_receipt_contract"]["status"])
        self.assertTrue(payload["guarded_start_final_start_receipt_contract"]["guarded_start_final_start_receipt_contract_implemented"])
        self.assertTrue(payload["guarded_start_final_start_receipt_contract"]["final_start_receipt_contract_only"])
        self.assertFalse(payload["guarded_start_final_start_receipt_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_final_start_receipt_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_final_start_receipt_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_launch_window_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_launch_window_contract.v1",
            payload["guarded_start_launch_window_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_launch_window_contract"]["status"])
        self.assertTrue(payload["guarded_start_launch_window_contract"]["guarded_start_launch_window_contract_implemented"])
        self.assertTrue(payload["guarded_start_launch_window_contract"]["launch_window_contract_only"])
        self.assertFalse(payload["guarded_start_launch_window_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_launch_window_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_launch_window_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_pre_launch_guard_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_pre_launch_guard_contract.v1",
            payload["guarded_start_pre_launch_guard_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_pre_launch_guard_contract"]["status"])
        self.assertTrue(payload["guarded_start_pre_launch_guard_contract"]["guarded_start_pre_launch_guard_contract_implemented"])
        self.assertTrue(payload["guarded_start_pre_launch_guard_contract"]["pre_launch_guard_contract_only"])
        self.assertFalse(payload["guarded_start_pre_launch_guard_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_pre_launch_guard_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_pre_launch_guard_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_executor_runtime_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_executor_runtime_contract.v1",
            payload["guarded_start_executor_runtime_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_executor_runtime_contract"]["status"])
        self.assertTrue(payload["guarded_start_executor_runtime_contract"]["guarded_start_executor_runtime_contract_implemented"])
        self.assertTrue(payload["guarded_start_executor_runtime_contract"]["executor_runtime_contract_only"])
        self.assertFalse(payload["guarded_start_executor_runtime_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_executor_runtime_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_executor_runtime_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_spawn_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_process_spawn_contract.v1",
            payload["guarded_start_process_spawn_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_process_spawn_contract"]["status"])
        self.assertTrue(payload["guarded_start_process_spawn_contract"]["guarded_start_process_spawn_contract_implemented"])
        self.assertTrue(payload["guarded_start_process_spawn_contract"]["process_spawn_contract_only"])
        self.assertFalse(payload["guarded_start_process_spawn_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_process_spawn_contract"]["subprocess_module_imported"])
        self.assertFalse(payload["guarded_start_process_spawn_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_process_spawn_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_spawn_review_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_spawn_review_contract.v1",
            payload["guarded_start_spawn_review_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_spawn_review_contract"]["status"])
        self.assertTrue(payload["guarded_start_spawn_review_contract"]["guarded_start_spawn_review_contract_implemented"])
        self.assertTrue(payload["guarded_start_spawn_review_contract"]["spawn_review_contract_only"])
        self.assertFalse(payload["guarded_start_spawn_review_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_spawn_review_contract"]["subprocess_module_imported"])
        self.assertFalse(payload["guarded_start_spawn_review_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_spawn_review_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_subprocess_import_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_subprocess_import_contract.v1",
            payload["guarded_start_subprocess_import_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_subprocess_import_contract"]["status"])
        self.assertTrue(payload["guarded_start_subprocess_import_contract"]["guarded_start_subprocess_import_contract_implemented"])
        self.assertTrue(payload["guarded_start_subprocess_import_contract"]["subprocess_import_contract_only"])
        self.assertFalse(payload["guarded_start_subprocess_import_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_subprocess_import_contract"]["subprocess_module_imported"])
        self.assertFalse(payload["guarded_start_subprocess_import_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_subprocess_import_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_launch_invocation_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_launch_invocation_contract.v1",
            payload["guarded_start_launch_invocation_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_launch_invocation_contract"]["status"])
        self.assertTrue(payload["guarded_start_launch_invocation_contract"]["guarded_start_launch_invocation_contract_implemented"])
        self.assertTrue(payload["guarded_start_launch_invocation_contract"]["launch_invocation_contract_only"])
        self.assertFalse(payload["guarded_start_launch_invocation_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_launch_invocation_contract"]["subprocess_module_imported"])
        self.assertFalse(payload["guarded_start_launch_invocation_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_launch_invocation_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_final_process_start_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_final_process_start_contract.v1",
            payload["guarded_start_final_process_start_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_final_process_start_contract"]["status"])
        self.assertTrue(payload["guarded_start_final_process_start_contract"]["guarded_start_final_process_start_contract_implemented"])
        self.assertTrue(payload["guarded_start_final_process_start_contract"]["final_process_start_contract_only"])
        self.assertFalse(payload["guarded_start_final_process_start_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_final_process_start_contract"]["subprocess_module_imported"])
        self.assertFalse(payload["guarded_start_final_process_start_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_final_process_start_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_execution_review_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_process_execution_review.v1",
            payload["guarded_start_process_execution_review"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_process_execution_review"]["status"])
        self.assertTrue(payload["guarded_start_process_execution_review"]["guarded_start_process_execution_review_implemented"])
        self.assertTrue(payload["guarded_start_process_execution_review"]["process_execution_review_only"])
        self.assertFalse(payload["guarded_start_process_execution_review"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_process_execution_review"]["subprocess_module_imported"])
        self.assertFalse(payload["guarded_start_process_execution_review"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_process_execution_review"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_execution_packet_available"])
        self.assertTrue(payload["gates"]["guarded_start_process_executor_stub_available"])
        self.assertTrue(payload["gates"]["guarded_start_process_executor_review_available"])
        self.assertTrue(payload["gates"]["guarded_start_process_executor_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_process_executor_review.v1",
            payload["guarded_start_process_executor_review"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_process_executor_review"]["status"])
        self.assertTrue(payload["guarded_start_process_executor_review"]["guarded_start_process_executor_review_implemented"])
        self.assertTrue(payload["guarded_start_process_executor_review"]["process_executor_review_only"])
        self.assertFalse(payload["guarded_start_process_executor_review"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_process_executor_review"]["subprocess_module_imported"])
        self.assertFalse(payload["guarded_start_process_executor_review"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_process_executor_review"]["daemon_started"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_process_executor_contract.v1",
            payload["guarded_start_process_executor_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_process_executor_contract"]["status"])
        self.assertTrue(payload["guarded_start_process_executor_contract"]["guarded_start_process_executor_contract_implemented"])
        self.assertTrue(payload["guarded_start_process_executor_contract"]["process_executor_contract_only"])
        self.assertFalse(payload["guarded_start_process_executor_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_process_executor_contract"]["subprocess_module_imported"])
        self.assertFalse(payload["guarded_start_process_executor_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_process_executor_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runtime_adapter_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_process_runtime_adapter.v1",
            payload["guarded_start_process_runtime_adapter"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_process_runtime_adapter"]["status"])
        self.assertTrue(payload["guarded_start_process_runtime_adapter"]["guarded_start_process_runtime_adapter_implemented"])
        self.assertTrue(payload["guarded_start_process_runtime_adapter"]["process_runtime_adapter_only"])
        self.assertFalse(payload["guarded_start_process_runtime_adapter"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_process_runtime_adapter"]["subprocess_module_imported"])
        self.assertFalse(payload["guarded_start_process_runtime_adapter"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_process_runtime_adapter"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_adapter_review_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_process_adapter_review.v1",
            payload["guarded_start_process_adapter_review"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_process_adapter_review"]["status"])
        self.assertTrue(payload["guarded_start_process_adapter_review"]["guarded_start_process_adapter_review_implemented"])
        self.assertTrue(payload["guarded_start_process_adapter_review"]["process_adapter_review_only"])
        self.assertFalse(payload["guarded_start_process_adapter_review"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_process_adapter_review"]["subprocess_module_imported"])
        self.assertFalse(payload["guarded_start_process_adapter_review"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_process_adapter_review"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_adapter_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_process_adapter_contract.v1",
            payload["guarded_start_process_adapter_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_process_adapter_contract"]["status"])
        self.assertTrue(payload["guarded_start_process_adapter_contract"]["guarded_start_process_adapter_contract_implemented"])
        self.assertTrue(payload["guarded_start_process_adapter_contract"]["process_adapter_contract_only"])
        self.assertFalse(payload["guarded_start_process_adapter_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_process_adapter_contract"]["subprocess_module_imported"])
        self.assertFalse(payload["guarded_start_process_adapter_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_process_adapter_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_process_runner_contract.v1",
            payload["guarded_start_process_runner_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_process_runner_contract"]["status"])
        self.assertTrue(payload["guarded_start_process_runner_contract"]["guarded_start_process_runner_contract_implemented"])
        self.assertTrue(payload["guarded_start_process_runner_contract"]["process_runner_contract_only"])
        self.assertFalse(payload["guarded_start_process_runner_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_process_runner_contract"]["subprocess_module_imported"])
        self.assertFalse(payload["guarded_start_process_runner_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_process_runner_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_review_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_process_runner_review.v1",
            payload["guarded_start_process_runner_review"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_process_runner_review"]["status"])
        self.assertTrue(payload["guarded_start_process_runner_review"]["guarded_start_process_runner_review_implemented"])
        self.assertTrue(payload["guarded_start_process_runner_review"]["process_runner_review_only"])
        self.assertFalse(payload["guarded_start_process_runner_review"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_process_runner_review"]["subprocess_module_imported"])
        self.assertFalse(payload["guarded_start_process_runner_review"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_process_runner_review"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_packet_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_process_runner_packet.v1",
            payload["guarded_start_process_runner_packet"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_process_runner_packet"]["status"])
        self.assertTrue(payload["guarded_start_process_runner_packet"]["guarded_start_process_runner_packet_implemented"])
        self.assertTrue(payload["guarded_start_process_runner_packet"]["process_runner_packet_only"])
        self.assertFalse(payload["guarded_start_process_runner_packet"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_process_runner_packet"]["subprocess_module_imported"])
        self.assertFalse(payload["guarded_start_process_runner_packet"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_process_runner_packet"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_execution_review_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_process_runner_execution_review.v1",
            payload["guarded_start_process_runner_execution_review"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_process_runner_execution_review"]["status"])
        self.assertTrue(payload["guarded_start_process_runner_execution_review"]["guarded_start_process_runner_execution_review_implemented"])
        self.assertTrue(payload["guarded_start_process_runner_execution_review"]["process_runner_execution_review_only"])
        self.assertFalse(payload["guarded_start_process_runner_execution_review"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_process_runner_execution_review"]["subprocess_module_imported"])
        self.assertFalse(payload["guarded_start_process_runner_execution_review"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_process_runner_execution_review"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_execution_contract_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_process_runner_execution_contract.v1",
            payload["guarded_start_process_runner_execution_contract"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_process_runner_execution_contract"]["status"])
        self.assertTrue(payload["guarded_start_process_runner_execution_contract"]["guarded_start_process_runner_execution_contract_implemented"])
        self.assertTrue(payload["guarded_start_process_runner_execution_contract"]["process_runner_execution_contract_only"])
        self.assertFalse(payload["guarded_start_process_runner_execution_contract"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_process_runner_execution_contract"]["subprocess_module_imported"])
        self.assertFalse(payload["guarded_start_process_runner_execution_contract"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_process_runner_execution_contract"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_start_gate_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_process_runner_start_gate.v1",
            payload["guarded_start_process_runner_start_gate"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_process_runner_start_gate"]["status"])
        self.assertTrue(payload["guarded_start_process_runner_start_gate"]["guarded_start_process_runner_start_gate_implemented"])
        self.assertTrue(payload["guarded_start_process_runner_start_gate"]["process_runner_start_gate_only"])
        self.assertFalse(payload["guarded_start_process_runner_start_gate"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_process_runner_start_gate"]["subprocess_module_imported"])
        self.assertFalse(payload["guarded_start_process_runner_start_gate"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_process_runner_start_gate"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_final_review_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_process_runner_final_review.v1",
            payload["guarded_start_process_runner_final_review"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_process_runner_final_review"]["status"])
        self.assertTrue(payload["guarded_start_process_runner_final_review"]["guarded_start_process_runner_final_review_implemented"])
        self.assertTrue(payload["guarded_start_process_runner_final_review"]["process_runner_final_review_only"])
        self.assertFalse(payload["guarded_start_process_runner_final_review"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_process_runner_final_review"]["subprocess_module_imported"])
        self.assertFalse(payload["guarded_start_process_runner_final_review"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_process_runner_final_review"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_promotion_packet_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_process_runner_promotion_packet.v1",
            payload["guarded_start_process_runner_promotion_packet"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_process_runner_promotion_packet"]["status"])
        self.assertTrue(payload["guarded_start_process_runner_promotion_packet"]["guarded_start_process_runner_promotion_packet_implemented"])
        self.assertTrue(payload["guarded_start_process_runner_promotion_packet"]["process_runner_promotion_packet_only"])
        self.assertFalse(payload["guarded_start_process_runner_promotion_packet"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_process_runner_promotion_packet"]["subprocess_module_imported"])
        self.assertFalse(payload["guarded_start_process_runner_promotion_packet"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_process_runner_promotion_packet"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_operator_release_review_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_process_runner_operator_release_review.v1",
            payload["guarded_start_process_runner_operator_release_review"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_process_runner_operator_release_review"]["status"])
        self.assertTrue(payload["guarded_start_process_runner_operator_release_review"]["guarded_start_process_runner_operator_release_review_implemented"])
        self.assertTrue(payload["guarded_start_process_runner_operator_release_review"]["process_runner_operator_release_review_only"])
        self.assertFalse(payload["guarded_start_process_runner_operator_release_review"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_process_runner_operator_release_review"]["subprocess_module_imported"])
        self.assertFalse(payload["guarded_start_process_runner_operator_release_review"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_process_runner_operator_release_review"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_release_finalization_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_process_runner_release_finalization.v1",
            payload["guarded_start_process_runner_release_finalization"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_process_runner_release_finalization"]["status"])
        self.assertTrue(payload["guarded_start_process_runner_release_finalization"]["guarded_start_process_runner_release_finalization_implemented"])
        self.assertTrue(payload["guarded_start_process_runner_release_finalization"]["process_runner_release_finalization_only"])
        self.assertFalse(payload["guarded_start_process_runner_release_finalization"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_process_runner_release_finalization"]["subprocess_module_imported"])
        self.assertFalse(payload["guarded_start_process_runner_release_finalization"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_process_runner_release_finalization"]["daemon_started"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_release_authorization_available"])
        self.assertEqual(
            "atlas.voice_realtime.guarded_start_process_runner_release_authorization_contract.v1",
            payload["guarded_start_process_runner_release_authorization"]["schema_version"],
        )
        self.assertEqual("blocked", payload["guarded_start_process_runner_release_authorization"]["status"])
        self.assertTrue(payload["guarded_start_process_runner_release_authorization"]["guarded_start_process_runner_release_authorization_implemented"])
        self.assertTrue(payload["guarded_start_process_runner_release_authorization"]["process_runner_release_authorization_only"])
        self.assertFalse(payload["guarded_start_process_runner_release_authorization"]["runtime_policy_start_enabled"])
        self.assertFalse(payload["guarded_start_process_runner_release_authorization"]["subprocess_module_imported"])
        self.assertFalse(payload["guarded_start_process_runner_release_authorization"]["process_launch_attempted"])
        self.assertFalse(payload["guarded_start_process_runner_release_authorization"]["daemon_started"])
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
        self.assertIn("VOICE_DAEMON_FINAL_START_EXECUTOR_DECLARED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_FINAL_START_ENABLEMENT_GATE_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_SUPERVISED_START_EXECUTION_REVIEWED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_REAL_START_EXECUTION_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_EXECUTOR_DECLARED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_ENABLEMENT_GATE_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_REVIEWED_GUARDED_START_EXECUTION_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_DRY_RUN_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_SIMULATION_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_RUNTIME_HANDOFF_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_POLICY_PATCH_REVIEW_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_HUMAN_REVIEW_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_FINAL_ENABLEMENT_GATE_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_POLICY_ENABLEMENT_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_ACTIVATION_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_EXECUTION_ATTEMPT_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_EXECUTION_REHEARSAL_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_OBSERVABILITY_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_RELEASE_CANDIDATE_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_PROCESS_EXECUTION_REVIEWED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_PROCESS_EXECUTOR_REVIEWED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_PROCESS_EXECUTOR_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_PROMOTION_PACKET_ATTACHED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_GUARDED_START_PROCESS_RUNNER_OPERATOR_RELEASE_REVIEWED", payload["evidence_events"])

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
        self.assertTrue(payload["gates"]["final_start_executor_disabled_available"])
        self.assertTrue(payload["gates"]["final_start_executor_enablement_gate_available"])
        self.assertTrue(payload["gates"]["supervised_start_execution_review_available"])
        self.assertTrue(payload["gates"]["real_start_execution_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_executor_disabled_available"])
        self.assertTrue(payload["gates"]["guarded_start_executor_enablement_gate_available"])
        self.assertTrue(payload["gates"]["reviewed_guarded_start_execution_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_dry_run_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_simulation_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_policy_enablement_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_activation_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_execution_attempt_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_execution_rehearsal_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_observability_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_release_candidate_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_operator_acceptance_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_final_start_receipt_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_process_execution_review_available"])
        self.assertTrue(payload["gates"]["guarded_start_process_execution_packet_available"])
        self.assertTrue(payload["gates"]["guarded_start_process_executor_stub_available"])
        self.assertTrue(payload["gates"]["guarded_start_process_executor_review_available"])
        self.assertTrue(payload["gates"]["guarded_start_process_executor_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_process_runtime_adapter_available"])
        self.assertTrue(payload["gates"]["guarded_start_process_adapter_review_available"])
        self.assertTrue(payload["gates"]["guarded_start_process_adapter_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_review_available"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_packet_available"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_execution_review_available"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_execution_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_start_gate_available"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_final_review_available"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_promotion_packet_available"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_operator_release_review_available"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_release_finalization_available"])
        self.assertTrue(payload["gates"]["guarded_start_process_runner_release_authorization_available"])
        self.assertEqual("blocked", payload["launch_authorization_contract"]["status"])
        self.assertEqual("fix_supervised_process_adapter_prerequisites", payload["next_action"])


if __name__ == "__main__":
    unittest.main()
