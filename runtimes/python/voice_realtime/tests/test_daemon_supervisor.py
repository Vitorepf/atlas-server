from __future__ import annotations

import unittest

from atlas_voice_agent.daemon_supervisor import (
    SCHEMA_VERSION,
    evaluate_daemon_supervisor,
)
from atlas_voice_agent.supervised_start_plan import build_supervised_start_plan


def ready_worker_start() -> dict[str, object]:
    supervised_start_plan = build_supervised_start_plan(
        worker_start_status="blocked_unimplemented_start",
        production_review_valid=True,
        daemon_implementation_review_valid=True,
        activation_contract={
            "schema_version": "atlas.voice_realtime.activation_contract.v1",
            "status": "ready_to_start_worker",
        },
        production_loop_plan={
            "schema_version": "atlas.voice_realtime.production_loop_plan.v1",
            "production_sdk_loop_wired": True,
            "sdk_wiring_contract": {
                "schema_version": "atlas.voice_realtime.sdk_wiring_contract.v1",
                "guardrails": {
                    "kernel_event_normalizer_required_for_real_loop": True,
                },
                "required_components": {
                    "kernel_event_normalizer": "KernelRuntimeEventNormalizerGuard",
                },
            },
        },
    )

    return {
        "schema_version": "atlas.voice_realtime.worker_start.v1",
        "status": "blocked_unimplemented_start",
        "surface_id": "voice_realtime",
        "runtime_id": "livekit_agents_sdk",
        "production_promotion_review_valid": True,
        "daemon_implementation_review_valid": True,
        "started": False,
        "supervised_start_plan": supervised_start_plan,
    }


class DaemonSupervisorTest(unittest.TestCase):
    def test_ready_worker_reaches_process_adapter_boundary_without_launch(self) -> None:
        payload = evaluate_daemon_supervisor(ready_worker_start())

        self.assertEqual(SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("atlas.voice_realtime.daemon_supervisor_execution.v1", payload["schema_version"])
        self.assertEqual("ready_for_process_adapter_implementation", payload["status"])
        self.assertEqual("supervised_contract_only", payload["execution_mode"])
        self.assertTrue(payload["preflight_ready"])
        self.assertTrue(payload["receipts_ready"])
        self.assertFalse(payload["start_allowed"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["process_adapter_implemented"])
        self.assertTrue(payload["gates"]["process_adapter_blueprint_available"])
        self.assertEqual(
            "atlas.voice_realtime.daemon_process_adapter_blueprint.v1",
            payload["process_adapter_blueprint"]["schema_version"],
        )
        self.assertEqual("ready_for_reviewed_adapter_runtime", payload["process_adapter_blueprint"]["status"])
        self.assertFalse(payload["process_adapter_blueprint"]["launch_allowed"])
        self.assertFalse(payload["process_adapter_blueprint"]["process_launch_attempted"])
        self.assertIn("--start-worker", payload["process_adapter_blueprint"]["argv_template"])
        self.assertIn("ATLAS_TOKEN", payload["process_adapter_blueprint"]["required_env_keys"])
        self.assertFalse(payload["process_adapter_blueprint"]["secret_safe_output_policy"]["log_env_values"])
        self.assertIn("decision_receipt_hash_bound_to_launch", payload["process_adapter_blueprint"]["supervision_requirements"])
        self.assertIn("call_provider_from_process_adapter", payload["process_adapter_blueprint"]["forbidden_shortcuts"])
        self.assertEqual(
            "atlas.voice_realtime.supervised_process_adapter.v1",
            payload["supervised_process_adapter"]["schema_version"],
        )
        self.assertEqual("ready_fail_closed", payload["supervised_process_adapter"]["status"])
        self.assertFalse(payload["supervised_process_adapter"]["launch_allowed"])
        self.assertFalse(payload["supervised_process_adapter"]["process_launch_attempted"])
        self.assertFalse(payload["supervised_process_adapter"]["daemon_started"])
        self.assertFalse(payload["supervised_process_adapter"]["subprocess_module_imported"])
        self.assertEqual(
            "atlas.voice_realtime.managed_env_contract.v1",
            payload["supervised_process_adapter"]["managed_environment_contract"]["schema_version"],
        )
        self.assertFalse(payload["supervised_process_adapter"]["managed_environment_contract"]["env_file_write_attempted"])
        self.assertFalse(payload["supervised_process_adapter"]["managed_environment_contract"]["secret_values_present_in_output"])
        self.assertEqual(
            "atlas.voice_realtime.launch_authorization_contract.v1",
            payload["supervised_process_adapter"]["launch_authorization_contract"]["schema_version"],
        )
        self.assertFalse(payload["supervised_process_adapter"]["launch_authorization_contract"]["launch_allowed"])
        self.assertFalse(payload["supervised_process_adapter"]["launch_authorization_contract"]["process_launch_attempted"])
        self.assertEqual("blocked_launch_not_implemented", payload["supervised_process_adapter"]["start_attempt"]["status"])
        self.assertTrue(payload["gates"]["process_launch_disabled"])
        self.assertIn("start_worker_process", payload["missing_process_methods"])
        self.assertIn("stop_worker_process", payload["missing_process_methods"])
        self.assertIn("VOICE_DAEMON_SUPERVISOR_EVALUATED", payload["evidence_events"])
        self.assertFalse(payload["guardrails"]["process_launch_allowed_by_this_contract"])
        self.assertEqual("implement_reviewed_process_adapter", payload["next_action"])

    def test_blocks_when_receipts_are_missing(self) -> None:
        worker_start = ready_worker_start()
        worker_start["production_promotion_review_valid"] = False

        payload = evaluate_daemon_supervisor(worker_start)

        self.assertEqual("blocked", payload["status"])
        self.assertFalse(payload["receipts_ready"])
        self.assertFalse(payload["gates"]["production_promotion_review_valid"])
        self.assertEqual("blocked_by_supervisor_prerequisites", payload["process_adapter_blueprint"]["status"])
        self.assertEqual("fix_supervisor_execution_prerequisites", payload["next_action"])

    def test_blocks_when_preflight_is_not_ready(self) -> None:
        worker_start = ready_worker_start()
        worker_start["supervised_start_plan"]["supervisor_preflight"]["status"] = "blocked"  # type: ignore[index]

        payload = evaluate_daemon_supervisor(worker_start)

        self.assertEqual("blocked", payload["status"])
        self.assertFalse(payload["preflight_ready"])
        self.assertFalse(payload["gates"]["supervisor_preflight_ready"])
        self.assertFalse(payload["process_launch_attempted"])


if __name__ == "__main__":
    unittest.main()
