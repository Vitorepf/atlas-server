from __future__ import annotations

import unittest

from atlas_voice_agent.supervised_start_plan import (
    SCHEMA_VERSION,
    build_supervised_start_plan,
)


def activation_contract(status: str = "ready_to_start_worker") -> dict[str, object]:
    return {
        "schema_version": "atlas.voice_realtime.activation_contract.v1",
        "status": status,
        "gates": {
            "callback_loop_wired": True,
            "production_sdk_loop_wired": True,
        },
    }


def production_loop_plan(*, wired: bool = True, normalizer: bool = True) -> dict[str, object]:
    return {
        "schema_version": "atlas.voice_realtime.production_loop_plan.v1",
        "production_sdk_loop_wired": wired,
        "sdk_wiring_contract": {
            "schema_version": "atlas.voice_realtime.sdk_wiring_contract.v1",
            "status": "wired",
            "guardrails": {
                "kernel_event_normalizer_required_for_real_loop": normalizer,
            },
            "required_components": {
                "kernel_event_normalizer": "KernelRuntimeEventNormalizerGuard" if normalizer else None,
            },
        },
    }


class SupervisedStartPlanTest(unittest.TestCase):
    def test_blocks_until_human_review(self) -> None:
        payload = build_supervised_start_plan(
            worker_start_status="blocked_pending_human_review",
            production_review_valid=False,
            daemon_implementation_review_valid=False,
            activation_contract=activation_contract(),
            production_loop_plan=production_loop_plan(),
        )

        self.assertEqual(SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("blocked_pending_human_review", payload["status"])
        self.assertFalse(payload["start_allowed"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["execution_implemented"])
        self.assertTrue(payload["supervisor_required"])
        self.assertFalse(payload["guardrails"]["direct_provider_call_allowed"])
        self.assertFalse(payload["guardrails"]["raw_audio_persistence_allowed"])
        self.assertEqual(
            "atlas.voice_realtime.daemon_supervisor_contract.v1",
            payload["supervisor_contract"]["schema_version"],
        )
        self.assertFalse(payload["supervisor_contract"]["process_launch_implemented"])
        self.assertEqual(
            "atlas.voice_realtime.daemon_supervisor_health_snapshot.v1",
            payload["supervisor_health_snapshot"]["schema_version"],
        )
        self.assertEqual(
            "atlas.voice_realtime.daemon_supervisor_preflight.v1",
            payload["supervisor_preflight"]["schema_version"],
        )
        self.assertEqual("blocked", payload["supervisor_health_snapshot"]["status"])
        self.assertEqual("blocked", payload["supervisor_preflight"]["status"])
        self.assertFalse(payload["supervisor_health_snapshot"]["daemon_started"])
        self.assertFalse(payload["supervisor_preflight"]["process_launch_attempted"])
        self.assertFalse(payload["supervisor_preflight"]["daemon_started"])
        self.assertFalse(payload["supervisor_health_snapshot"]["guardrails"]["starts_process"])

    def test_blocks_until_daemon_implementation_review(self) -> None:
        payload = build_supervised_start_plan(
            worker_start_status="blocked_pending_daemon_implementation_review",
            production_review_valid=True,
            daemon_implementation_review_valid=False,
            activation_contract=activation_contract(),
            production_loop_plan=production_loop_plan(),
        )

        self.assertEqual("blocked_pending_daemon_implementation_review", payload["status"])
        self.assertFalse(payload["start_allowed"])
        self.assertTrue(payload["gates"]["production_review_valid"])
        self.assertFalse(payload["gates"]["daemon_implementation_review_valid"])
        self.assertEqual("fix_supervised_start_prerequisites", payload["next_action"])

    def test_ready_for_implementation_still_does_not_start(self) -> None:
        payload = build_supervised_start_plan(
            worker_start_status="blocked_unimplemented_start",
            production_review_valid=True,
            daemon_implementation_review_valid=True,
            activation_contract=activation_contract(),
            production_loop_plan=production_loop_plan(),
        )

        self.assertEqual("ready_for_supervised_start_implementation", payload["status"])
        self.assertFalse(payload["start_allowed"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["execution_implemented"])
        self.assertEqual("implement_supervised_daemon_start", payload["next_action"])
        self.assertTrue(payload["gates"]["worker_process_launch_disabled"])
        self.assertTrue(payload["gates"]["supervisor_contract_declared"])
        self.assertTrue(payload["gates"]["supervisor_health_snapshot_declared"])
        self.assertIn("healthy", payload["supervisor_contract"]["lifecycle_states"])
        self.assertIn("callback_router_roundtrip", payload["supervisor_contract"]["required_health_checks"])
        self.assertIn("stop_livekit_worker", payload["supervisor_contract"]["rollback_actions"])
        self.assertIn("restart_without_decision_receipt", payload["supervisor_contract"]["forbidden_shortcuts"])
        self.assertFalse(payload["supervisor_contract"]["restart_policy"]["automatic_restart_allowed"])
        self.assertTrue(payload["supervisor_contract"]["restart_policy"]["requires_new_decision_receipt"])
        self.assertEqual("ready_for_supervisor_implementation", payload["supervisor_health_snapshot"]["status"])
        self.assertEqual("ready_for_supervisor_execution_implementation", payload["supervisor_preflight"]["status"])
        self.assertEqual("planned", payload["supervisor_health_snapshot"]["lifecycle_state"])
        self.assertEqual("preflight", payload["supervisor_health_snapshot"]["next_lifecycle_state"])
        self.assertIn("start_worker_process", payload["supervisor_preflight"]["required_supervisor_methods"])
        self.assertIn("VOICE_DAEMON_PREFLIGHT_CHECKED", payload["supervisor_preflight"]["evidence_events"])
        self.assertFalse(payload["supervisor_preflight"]["guardrails"]["start_without_decision_receipt_allowed"])
        self.assertEqual("implement_supervisor_execution", payload["supervisor_preflight"]["next_action"])
        self.assertFalse(payload["guardrails"]["health_snapshot_starts_process"])

    def test_kernel_normalizer_is_required(self) -> None:
        payload = build_supervised_start_plan(
            worker_start_status="blocked_unimplemented_start",
            production_review_valid=True,
            daemon_implementation_review_valid=True,
            activation_contract=activation_contract(),
            production_loop_plan=production_loop_plan(normalizer=False),
        )

        self.assertEqual("blocked_kernel_normalizer_contract", payload["status"])
        self.assertFalse(payload["start_allowed"])
        self.assertFalse(payload["gates"]["kernel_event_normalizer_required"])


if __name__ == "__main__":
    unittest.main()
