from __future__ import annotations

from copy import deepcopy
import unittest
from unittest.mock import patch

from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.daemon_implementation_review import SCHEMA_VERSION as DAEMON_REVIEW_SCHEMA_VERSION
from atlas_voice_agent.product_loop_check import build_product_loop_check
from atlas_voice_agent.product_loop_packet import (
    ProductLoopCheckViolation,
    validate_product_loop_check,
)
from atlas_voice_agent.production_promotion_review import REVIEWED_BUNDLE_SCHEMA_VERSION, SCHEMA_VERSION

from test_contract import manifest


def valid_review() -> dict[str, object]:
    return {
        "schema_version": SCHEMA_VERSION,
        "status": "approved",
        "surface_id": "voice_realtime",
        "runtime_id": "livekit_agents_sdk",
        "reviewed_bundle_schema_version": REVIEWED_BUNDLE_SCHEMA_VERSION,
        "reviewed_bundle_hash": "c"*64,
        "reviewed_machine_gate_status": "ready_for_human_review",
        "decision_receipt_id": "decision_receipt_voice_1",
        "approved_by": "vitor",
        "approved_at": "2026-05-10T12:00:00Z",
        "required_evidence_reviewed": [
            "runtime_certification",
            "product_loop_check",
            "pre_start_health_checks_smoke",
            "rivals_voice_comparison",
        ],
        "failed_machine_gates_acknowledged": [],
        "rollback_plan": [
            "disable_livekit_token_issuer",
            "stop_livekit_worker",
            "revert_runtime_policy",
        ],
        "forbidden_actions_acknowledged": [
            "bypass_kernel_decision_receipt",
            "auto_promote_voice_runtime",
            "persist_raw_audio",
        ],
        "auto_promotion_allowed": False,
    }


def valid_review_bundle() -> dict[str, object]:
    evidence = {
        name: {
            "name": name,
            "daemon_started": False,
            "promotion_allowed": False,
            "auto_promotion_allowed": False,
            "payload_hash": f"{index}"*64,
        }
        for index, name in enumerate([
            "runtime_certification",
            "product_loop_check",
            "pre_start_health_checks_smoke",
            "rivals_voice_comparison",
        ], start=1)
    }

    return {
        "schema_version": REVIEWED_BUNDLE_SCHEMA_VERSION,
        "status": "ready_for_human_review",
        "surface_id": "voice_realtime",
        "runtime_id": "livekit_agents_sdk",
        "kernel_only": True,
        "mobile_first": True,
        "promotion_allowed": False,
        "auto_promotion_allowed": False,
        "daemon_started": False,
        "human_review_required": True,
        "decision_receipt_required": True,
        "rollback_plan_required": True,
        "callback_loop_wired": True,
        "production_sdk_loop_wired": True,
        "guardrails": {
            "raw_audio_persistence_allowed": False,
            "direct_provider_call_allowed": False,
            "direct_tool_execution_allowed": False,
            "memory_write_allowed": False,
            "start_daemon_allowed": False,
            "boolean_approval_is_sufficient": False,
        },
        "production_promotion_gate": {
            "schema_version": "atlas.voice_realtime.production_promotion_gate.v1",
            "status": "review_required",
            "failed_keys": [],
        },
        "review_packet": {
            "schema_version": "atlas.voice_realtime.production_promotion_review_packet.v1",
            "required_decision_receipt": True,
            "required_rollback_plan": ["disable_livekit_token_issuer"],
            "required_evidence": ["runtime_certification"],
            "forbidden_actions": ["persist_raw_audio"],
        },
        "evidence": evidence,
        "summary": {
            "evidence_count": len(evidence),
            "failed_machine_gates": [],
            "review_ready": True,
        },
        "bundle_hash": "c"*64,
    }


def valid_daemon_review() -> dict[str, object]:
    return {
        "schema_version": DAEMON_REVIEW_SCHEMA_VERSION,
        "status": "approved",
        "surface_id": "voice_realtime",
        "runtime_id": "livekit_agents_sdk",
        "decision_receipt_id": "decision_receipt_voice_daemon_1",
        "implementation_ref": "commit:voice-daemon-reviewed",
        "reviewed_by": "vitor",
        "reviewed_at": "2026-05-10T12:30:00Z",
        "rollback_plan": [
            "disable_livekit_worker_launch",
            "stop_livekit_worker",
            "revert_runtime_policy",
        ],
        "forbidden_actions_acknowledged": [
            "bypass_kernel_decision_receipt",
            "direct_provider_call_from_daemon",
            "persist_raw_audio",
            "start_without_supervisor",
        ],
        "supervised_start_required": True,
        "kernel_decision_receipt_required": True,
        "direct_provider_call_allowed": False,
        "raw_audio_persistence_allowed": False,
    }


class ProductLoopCheckTest(unittest.TestCase):
    def test_product_loop_check_aggregates_wired_gates_without_starting_daemon(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = build_product_loop_check(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            mock_kernel=False,
            callback_loop_wired=True,
            production_sdk_loop_wired=True,
        )

        self.assertEqual("atlas.voice_realtime.product_loop_check.v1", payload["schema_version"])
        self.assertEqual("voice_realtime", payload["surface_id"])
        self.assertTrue(payload["kernel_only"])
        self.assertTrue(payload["mobile_first"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["callback_loop_wired"])
        self.assertTrue(payload["gates"]["production_sdk_loop_wired"])
        self.assertTrue(payload["gates"]["worker_start_still_blocked"])
        self.assertTrue(payload["gates"]["production_promotion_blocked"])
        self.assertTrue(payload["gates"]["sdk_probe_import_safe"])
        self.assertTrue(payload["gates"]["sdk_handler_blueprint_available"])
        self.assertTrue(payload["gates"]["sdk_kernel_normalizer_required"])
        self.assertTrue(payload["gates"]["supervised_start_plan_available"])
        self.assertTrue(payload["gates"]["daemon_supervisor_contract_available"])
        self.assertTrue(payload["gates"]["daemon_supervisor_health_snapshot_available"])
        self.assertTrue(payload["gates"]["daemon_supervisor_preflight_available"])
        self.assertTrue(payload["gates"]["daemon_supervisor_execution_available"])
        self.assertTrue(payload["gates"]["daemon_supervisor_process_launch_disabled"])
        self.assertTrue(payload["gates"]["daemon_process_adapter_blueprint_available"])
        self.assertTrue(payload["gates"]["supervised_process_adapter_available"])
        self.assertTrue(payload["gates"]["managed_env_contract_available"])
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
        self.assertTrue(payload["gates"]["guarded_start_runtime_handoff_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_policy_patch_review_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_human_review_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_final_enablement_gate_available"])
        self.assertTrue(payload["gates"]["guarded_start_policy_enablement_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_activation_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_execution_attempt_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_execution_rehearsal_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_observability_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_release_candidate_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_operator_acceptance_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_final_start_receipt_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_launch_window_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_pre_launch_guard_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_executor_runtime_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_process_spawn_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_spawn_review_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_subprocess_import_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_launch_invocation_contract_available"])
        self.assertTrue(payload["gates"]["guarded_start_final_process_start_contract_available"])
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
        self.assertFalse(payload["gates"]["production_review_receipt_valid"])
        self.assertFalse(payload["gates"]["daemon_implementation_review_valid"])
        self.assertFalse(payload["gates"]["boolean_approval_is_sufficient"])
        self.assertFalse(payload["guardrails"]["direct_provider_call_allowed"])
        self.assertFalse(payload["guardrails"]["raw_audio_persistence_allowed"])
        self.assertFalse(payload["guardrails"]["auto_promotion_allowed"])
        self.assertEqual("atlas.voice_realtime.callback_loop_contract.v1", payload["callback_loop"]["schema_version"])
        self.assertEqual("atlas.voice_realtime.production_loop_plan.v1", payload["production_loop_plan"]["schema_version"])
        self.assertEqual("atlas.voice_realtime.worker_start.v1", payload["worker_start"]["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.supervised_start_plan.v1",
            payload["supervised_start_plan"]["schema_version"],
        )
        self.assertFalse(payload["supervised_start_plan"]["start_allowed"])
        self.assertEqual(
            "atlas.voice_realtime.daemon_supervisor_preflight.v1",
            payload["supervised_start_plan"]["supervisor_preflight"]["schema_version"],
        )
        self.assertFalse(payload["supervised_start_plan"]["supervisor_preflight"]["process_launch_attempted"])
        self.assertEqual(
            "atlas.voice_realtime.daemon_supervisor_execution.v1",
            payload["daemon_supervisor_execution"]["schema_version"],
        )
        self.assertFalse(payload["daemon_supervisor_execution"]["process_launch_attempted"])
        self.assertFalse(payload["daemon_supervisor_execution"]["daemon_started"])
        self.assertFalse(payload["daemon_supervisor_execution"]["start_allowed"])
        self.assertEqual(
            "atlas.voice_realtime.daemon_process_adapter_blueprint.v1",
            payload["daemon_supervisor_execution"]["process_adapter_blueprint"]["schema_version"],
        )
        self.assertFalse(payload["daemon_supervisor_execution"]["process_adapter_blueprint"]["launch_allowed"])
        self.assertEqual(
            "atlas.voice_realtime.supervised_process_adapter.v1",
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["schema_version"],
        )
        self.assertFalse(payload["daemon_supervisor_execution"]["supervised_process_adapter"]["process_launch_attempted"])
        self.assertFalse(payload["daemon_supervisor_execution"]["supervised_process_adapter"]["daemon_started"])
        self.assertEqual(
            "atlas.voice_realtime.managed_env_contract.v1",
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["managed_environment_contract"]["schema_version"],
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["managed_environment_contract"]["env_file_write_attempted"]
        )
        self.assertEqual(
            "atlas.voice_realtime.launch_authorization_contract.v1",
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["launch_authorization_contract"]["schema_version"],
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["launch_authorization_contract"]["launch_allowed"]
        )
        self.assertEqual(
            "atlas.voice_realtime.managed_env_writer.v1",
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["managed_env_writer"]["schema_version"],
        )
        self.assertTrue(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["managed_env_writer"]["writer_contract_implemented"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["managed_env_writer"]["write_execution_implemented"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["managed_env_writer"]["env_file_write_attempted"]
        )
        self.assertEqual(
            "atlas.voice_realtime.supervised_launch_execution.v1",
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["supervised_launch_execution"]["schema_version"],
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["supervised_launch_execution"]["process_launch_attempted"]
        )
        self.assertTrue(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["supervised_launch_execution"]["pre_start_health_checks_execution_available"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["supervised_launch_execution"]["pre_start_health_checks_executed"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["supervised_launch_execution"]["daemon_started"]
        )
        self.assertEqual(
            "atlas.voice_realtime.subprocess_start_contract.v1",
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["subprocess_start_contract"]["schema_version"],
        )
        self.assertEqual(
            "blocked",
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["subprocess_start_contract"]["status"],
        )
        self.assertTrue(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["subprocess_start_contract"]["subprocess_start_contract_implemented"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["subprocess_start_contract"]["process_launch_attempted"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["subprocess_start_contract"]["daemon_started"]
        )
        self.assertEqual(
            "atlas.voice_realtime.reviewed_subprocess_start_execution.v1",
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["reviewed_subprocess_start_execution"]["schema_version"],
        )
        self.assertTrue(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["reviewed_subprocess_start_execution"]["reviewed_subprocess_start_execution_implemented"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["reviewed_subprocess_start_execution"]["real_subprocess_start_implemented"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["reviewed_subprocess_start_execution"]["process_launch_attempted"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["reviewed_subprocess_start_execution"]["daemon_started"]
        )
        self.assertEqual(
            "atlas.voice_realtime.real_start_adapter_disabled.v1",
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["real_start_adapter_disabled"]["schema_version"],
        )
        self.assertTrue(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["real_start_adapter_disabled"]["real_start_adapter_contract_implemented"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["real_start_adapter_disabled"]["real_start_adapter_enabled"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["real_start_adapter_disabled"]["process_launch_attempted"]
        )
        self.assertEqual(
            "atlas.voice_realtime.real_start_enablement_gate.v1",
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["real_start_enablement_gate"]["schema_version"],
        )
        self.assertTrue(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["real_start_enablement_gate"]["real_start_enablement_gate_implemented"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["real_start_enablement_gate"]["real_start_adapter_enabled"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["real_start_enablement_gate"]["process_launch_attempted"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["real_start_enablement_gate"]["daemon_started"]
        )
        self.assertEqual(
            "atlas.voice_realtime.runtime_policy_enablement_review.v1",
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["runtime_policy_enablement_review"]["schema_version"],
        )
        self.assertTrue(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["runtime_policy_enablement_review"]["runtime_policy_enablement_review_implemented"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["runtime_policy_enablement_review"]["runtime_policy_start_enabled"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["runtime_policy_enablement_review"]["process_launch_attempted"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["runtime_policy_enablement_review"]["daemon_started"]
        )
        self.assertEqual(
            "atlas.voice_realtime.real_start_adapter_review_contract.v1",
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["real_start_adapter_review_contract"]["schema_version"],
        )
        self.assertTrue(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["real_start_adapter_review_contract"]["real_start_adapter_review_contract_implemented"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["real_start_adapter_review_contract"]["runtime_policy_start_enabled"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["real_start_adapter_review_contract"]["process_launch_attempted"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["real_start_adapter_review_contract"]["daemon_started"]
        )
        self.assertEqual(
            "atlas.voice_realtime.reviewed_real_start_execution_contract.v1",
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["reviewed_real_start_execution_contract"]["schema_version"],
        )
        self.assertTrue(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["reviewed_real_start_execution_contract"]["reviewed_real_start_execution_contract_implemented"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["reviewed_real_start_execution_contract"]["runtime_policy_start_enabled"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["reviewed_real_start_execution_contract"]["process_launch_attempted"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["reviewed_real_start_execution_contract"]["daemon_started"]
        )
        self.assertEqual(
            "atlas.voice_realtime.final_start_executor_disabled.v1",
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["final_start_executor_disabled"]["schema_version"],
        )
        self.assertTrue(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["final_start_executor_disabled"]["final_start_executor_contract_implemented"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["final_start_executor_disabled"]["final_start_executor_enabled"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["final_start_executor_disabled"]["process_launch_attempted"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["final_start_executor_disabled"]["daemon_started"]
        )
        self.assertEqual(
            "atlas.voice_realtime.final_start_executor_enablement_gate.v1",
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["final_start_executor_enablement_gate"]["schema_version"],
        )
        self.assertTrue(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["final_start_executor_enablement_gate"]["final_start_executor_enablement_gate_implemented"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["final_start_executor_enablement_gate"]["final_start_executor_enabled"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["final_start_executor_enablement_gate"]["process_launch_attempted"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["final_start_executor_enablement_gate"]["daemon_started"]
        )
        self.assertEqual(
            "atlas.voice_realtime.supervised_start_execution_review.v1",
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["supervised_start_execution_review"]["schema_version"],
        )
        self.assertTrue(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["supervised_start_execution_review"]["supervised_start_execution_review_implemented"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["supervised_start_execution_review"]["start_execution_allowed"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["supervised_start_execution_review"]["process_launch_attempted"]
        )
        self.assertFalse(
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["supervised_start_execution_review"]["daemon_started"]
        )

        if payload["worker_start"]["status"] == "blocked_pending_human_review":
            self.assertEqual("ready_for_human_review", payload["status"])
            self.assertEqual("submit_voice_production_promotion_for_human_review", payload["next_action"])
        elif payload["worker_start"]["status"] == "blocked_unimplemented_start":
            self.assertEqual("ready_for_supervised_start_implementation", payload["status"])
            self.assertEqual("implement_supervised_daemon_start", payload["next_action"])
        else:
            self.assertEqual("blocked", payload["status"])

    def test_product_loop_check_is_not_wired_without_explicit_wiring_flags(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = build_product_loop_check(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            mock_kernel=False,
        )

        self.assertFalse(payload["gates"]["callback_loop_wired"])
        self.assertFalse(payload["gates"]["production_sdk_loop_wired"])
        self.assertEqual("blocked", payload["status"])
        self.assertIn(payload["next_action"], [
            "wire_real_sdk_callback_loop",
            "wire_production_sdk_loop",
            "fix_sdk_handler_blueprint_contract",
            "install_livekit_agents_sdk",
            "fix_voice_product_loop_gates",
        ])

    def test_product_loop_check_never_treats_mock_kernel_as_product_ready(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = build_product_loop_check(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            mock_kernel=True,
            callback_loop_wired=True,
            production_sdk_loop_wired=True,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertFalse(payload["daemon_started"])
        if payload["worker_start"]["status"] == "blocked_mock_kernel":
            self.assertEqual("use_real_kernel_not_mock", payload["next_action"])

    def test_product_loop_check_propagates_missing_sdk_probe_without_daemon_start(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        worker_plan = {
            "schema_version": "atlas.voice_realtime.worker_plan.v1",
            "status": "blocked_missing_sdk",
            "sdk_status": {
                "status": "missing_optional_dependency",
                "sdk_imported": False,
                "import_probe_only": True,
                "missing_imports": ["livekit.agents"],
                "package_checks": [{
                    "pip": "livekit-agents",
                    "import": "livekit.agents",
                    "installed": False,
                    "version": None,
                }],
            },
            "activation": {"can_start_long_running_worker": False},
        }

        with patch("atlas_voice_agent.livekit_runtime_entrypoint.build_livekit_worker_plan", return_value=worker_plan):
            payload = build_product_loop_check(
                contract,
                env={},
                settings_loaded=True,
                boundary_created=True,
                mock_kernel=False,
                callback_loop_wired=True,
                production_sdk_loop_wired=True,
            )

        sdk_status = payload["worker_start"]["worker_plan"]["sdk_status"]

        self.assertEqual("blocked", payload["status"])
        self.assertEqual("install_livekit_agents_sdk", payload["next_action"])
        self.assertEqual("blocked_missing_sdk", payload["worker_start"]["status"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["worker_start"]["started"])
        self.assertTrue(payload["gates"]["sdk_probe_import_safe"])
        self.assertFalse(sdk_status["sdk_imported"])
        self.assertTrue(sdk_status["import_probe_only"])
        self.assertEqual(["livekit.agents"], sdk_status["missing_imports"])
        self.assertIsNone(sdk_status["package_checks"][0]["version"])

    def test_product_loop_check_blocks_if_sdk_probe_contract_was_bypassed(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        worker_start = {
            "schema_version": "atlas.voice_realtime.worker_start.v1",
            "status": "blocked_unimplemented_start",
            "started": False,
            "worker_plan": {
                "schema_version": "atlas.voice_realtime.worker_plan.v1",
                "status": "ready_to_wire_callbacks",
                "sdk_status": {
                    "status": "ready",
                    "sdk_imported": True,
                    "import_probe_only": False,
                    "missing_imports": [],
                    "package_checks": [{
                        "pip": "livekit-agents",
                        "import": "livekit.agents",
                        "available": True,
                    }],
                },
            },
            "production_promotion": {"auto_promotion_allowed": False},
            "guardrails": {
                "direct_provider_call_allowed": False,
                "raw_audio_persistence_allowed": False,
            },
        }

        with patch("atlas_voice_agent.product_loop_check.start_livekit_agents_worker", return_value=worker_start):
            payload = build_product_loop_check(
                contract,
                env={},
                settings_loaded=True,
                boundary_created=True,
                mock_kernel=False,
                callback_loop_wired=True,
                production_sdk_loop_wired=True,
            )

        self.assertEqual("blocked", payload["status"])
        self.assertEqual("fix_sdk_probe_contract", payload["next_action"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["gates"]["sdk_probe_import_safe"])

    def test_product_loop_check_blocks_if_sdk_handler_blueprint_is_missing(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        production_loop = {
            "schema_version": "atlas.voice_realtime.production_loop_plan.v1",
            "status": "ready_to_wire",
            "production_sdk_loop_wired": True,
            "sdk_wiring_contract": {
                "schema_version": "atlas.voice_realtime.sdk_wiring_contract.v1",
                "status": "wired",
                "wiring_invariants": [],
                "required_handlers": [{"sdk_event_kind": "room_connected"}],
            },
        }
        worker_start = {
            "schema_version": "atlas.voice_realtime.worker_start.v1",
            "status": "blocked_unimplemented_start",
            "started": False,
            "worker_plan": {
                "sdk_status": {
                    "status": "ready",
                    "sdk_imported": False,
                    "import_probe_only": True,
                },
            },
            "production_promotion": {"auto_promotion_allowed": False},
            "guardrails": {
                "direct_provider_call_allowed": False,
                "raw_audio_persistence_allowed": False,
            },
        }

        with patch("atlas_voice_agent.product_loop_check.build_production_loop_plan", return_value=production_loop), \
            patch("atlas_voice_agent.product_loop_check.start_livekit_agents_worker", return_value=worker_start):
            payload = build_product_loop_check(
                contract,
                env={},
                settings_loaded=True,
                boundary_created=True,
                mock_kernel=False,
                callback_loop_wired=True,
                production_sdk_loop_wired=True,
            )

        self.assertEqual("blocked", payload["status"])
        self.assertEqual("fix_sdk_handler_blueprint_contract", payload["next_action"])
        self.assertFalse(payload["gates"]["sdk_handler_blueprint_available"])

    def test_product_loop_check_blocks_if_kernel_normalizer_is_not_required(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        production_loop = {
            "schema_version": "atlas.voice_realtime.production_loop_plan.v1",
            "status": "ready_to_wire",
            "production_sdk_loop_wired": True,
            "sdk_wiring_contract": {
                "schema_version": "atlas.voice_realtime.sdk_wiring_contract.v1",
                "status": "wired",
                "complete_handler_registry": True,
                "handler_registry_contract": {
                    "schema_version": "atlas.voice_realtime.sdk_handler_registry.v1",
                },
                "guardrails": {
                    "kernel_event_normalizer_required_for_real_loop": False,
                },
                "required_components": {},
                "wiring_invariants": [
                    "route_all_livekit_sdk_handlers_through_registry",
                ],
                "required_handlers": [{
                    "sdk_event_kind": "room_connected",
                    "handler_blueprint": {
                        "required_path": [
                            "LiveKitSdkEventBridge.to_callback_event",
                            "LiveKitCallbackRouter.route",
                        ],
                    },
                }],
            },
        }
        worker_start = {
            "schema_version": "atlas.voice_realtime.worker_start.v1",
            "status": "blocked_unimplemented_start",
            "started": False,
            "worker_plan": {
                "sdk_status": {
                    "status": "ready",
                    "sdk_imported": False,
                    "import_probe_only": True,
                },
            },
            "production_promotion": {"auto_promotion_allowed": False},
            "guardrails": {
                "direct_provider_call_allowed": False,
                "raw_audio_persistence_allowed": False,
            },
        }

        with patch("atlas_voice_agent.product_loop_check.build_production_loop_plan", return_value=production_loop), \
            patch("atlas_voice_agent.product_loop_check.start_livekit_agents_worker", return_value=worker_start):
            payload = build_product_loop_check(
                contract,
                env={},
                settings_loaded=True,
                boundary_created=True,
                mock_kernel=False,
                callback_loop_wired=True,
                production_sdk_loop_wired=True,
            )

        self.assertEqual("blocked", payload["status"])
        self.assertEqual("fix_sdk_kernel_normalizer_contract", payload["next_action"])
        self.assertFalse(payload["gates"]["sdk_kernel_normalizer_required"])

    def test_product_loop_check_reaches_daemon_review_only_with_valid_review_receipt(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = build_product_loop_check(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            mock_kernel=False,
            callback_loop_wired=True,
            production_sdk_loop_wired=True,
            production_promotion_review=valid_review(),
        )

        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["guardrails"]["auto_promotion_allowed"])
        if payload["worker_start"]["status"] == "blocked_unimplemented_start":
            self.assertEqual("ready_for_daemon_implementation_review", payload["status"])
            self.assertEqual("submit_daemon_implementation_review", payload["next_action"])
            self.assertTrue(payload["gates"]["production_review_receipt_valid"])
            self.assertFalse(payload["gates"]["daemon_implementation_review_valid"])

    def test_product_loop_check_requires_review_to_match_expected_bundle_when_supplied(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        bundle = valid_review_bundle()
        bundle["bundle_hash"] = "f"*64
        payload = build_product_loop_check(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            mock_kernel=False,
            callback_loop_wired=True,
            production_sdk_loop_wired=True,
            production_promotion_review=valid_review(),
            production_promotion_review_bundle=bundle,
        )

        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["gates"]["production_review_receipt_valid"])
        self.assertFalse(payload["gates"]["production_review_bound_to_expected_bundle"])
        self.assertFalse(payload["gates"]["production_review_expected_bundle_validated"])
        self.assertIn(
            "reviewed_bundle_hash_mismatch",
            payload["worker_start"]["production_promotion"]["review_check"]["errors"],
        )

    def test_product_loop_check_reaches_supervised_start_only_with_daemon_review_receipt(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = build_product_loop_check(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            mock_kernel=False,
            callback_loop_wired=True,
            production_sdk_loop_wired=True,
            production_promotion_review=valid_review(),
            daemon_implementation_review=valid_daemon_review(),
        )

        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["guardrails"]["auto_promotion_allowed"])
        if payload["worker_start"]["status"] == "blocked_unimplemented_start":
            self.assertEqual("ready_for_supervised_start_implementation", payload["status"])
            self.assertEqual("implement_supervised_daemon_start", payload["next_action"])
            self.assertTrue(payload["gates"]["production_review_receipt_valid"])
            self.assertTrue(payload["gates"]["daemon_implementation_review_valid"])
            self.assertEqual("ready_for_supervised_start_implementation", payload["supervised_start_plan"]["status"])
            self.assertFalse(payload["supervised_start_plan"]["execution_implemented"])
            self.assertTrue(payload["gates"]["daemon_supervisor_preflight_available"])
            self.assertTrue(payload["gates"]["daemon_supervisor_execution_available"])
            self.assertTrue(payload["gates"]["daemon_process_adapter_blueprint_available"])
            self.assertTrue(payload["gates"]["supervised_process_adapter_available"])
            self.assertTrue(payload["gates"]["managed_env_contract_available"])
            self.assertTrue(payload["gates"]["launch_authorization_contract_available"])
            self.assertTrue(payload["gates"]["launch_authorization_contract_ready"])
            self.assertTrue(payload["gates"]["managed_env_writer_contract_available"])
            self.assertTrue(payload["gates"]["subprocess_start_contract_available"])
            self.assertTrue(payload["gates"]["reviewed_subprocess_start_execution_available"])
            self.assertTrue(payload["gates"]["real_start_adapter_disabled_available"])
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
            self.assertEqual(
                "ready_for_supervisor_execution_implementation",
                payload["supervised_start_plan"]["supervisor_preflight"]["status"],
            )
            self.assertFalse(payload["supervised_start_plan"]["supervisor_preflight"]["daemon_started"])
            self.assertEqual(
                "ready_for_process_adapter_implementation",
                payload["daemon_supervisor_execution"]["status"],
            )
            self.assertEqual("implement_reviewed_process_adapter", payload["daemon_supervisor_execution"]["next_action"])
            self.assertEqual(
                "ready_for_reviewed_adapter_runtime",
                payload["daemon_supervisor_execution"]["process_adapter_blueprint"]["status"],
            )
            self.assertEqual(
                "ready_fail_closed",
                payload["daemon_supervisor_execution"]["supervised_process_adapter"]["status"],
            )
            self.assertEqual(
                "ready_for_write_implementation",
                payload["daemon_supervisor_execution"]["supervised_process_adapter"]["managed_env_writer"]["status"],
            )
            self.assertFalse(
                payload["daemon_supervisor_execution"]["supervised_process_adapter"]["managed_env_writer"]["write_execution_implemented"],
            )
        else:
            self.assertEqual("blocked", payload["status"])

    def test_validate_product_loop_check_accepts_kernel_only_fail_closed_payload(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = build_product_loop_check(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            mock_kernel=False,
            callback_loop_wired=True,
            production_sdk_loop_wired=True,
        )

        self.assertIs(validate_product_loop_check(payload), payload)

    def test_validate_product_loop_check_rejects_daemon_started_payload(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = dict(build_product_loop_check(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            mock_kernel=False,
            callback_loop_wired=True,
            production_sdk_loop_wired=True,
        ))
        payload["daemon_started"] = True

        with self.assertRaises(ProductLoopCheckViolation):
            validate_product_loop_check(payload)

    def test_validate_product_loop_check_rejects_runtime_authority_bypass(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = deepcopy(build_product_loop_check(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            mock_kernel=False,
            callback_loop_wired=True,
            production_sdk_loop_wired=True,
        ))
        payload["guardrails"]["direct_provider_call_allowed"] = True

        with self.assertRaises(ProductLoopCheckViolation):
            validate_product_loop_check(payload)

    def test_validate_product_loop_check_rejects_nested_secret_or_raw_payload(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = deepcopy(build_product_loop_check(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            mock_kernel=False,
            callback_loop_wired=True,
            production_sdk_loop_wired=True,
        ))
        payload["worker_start"]["artifacts"] = {
            "unsafe": {
                "access_token": "header.payload.signature",
            },
        }

        with self.assertRaises(ProductLoopCheckViolation):
            validate_product_loop_check(payload)

    def test_validate_product_loop_check_rejects_false_fail_closed_gate(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = deepcopy(build_product_loop_check(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            mock_kernel=False,
            callback_loop_wired=True,
            production_sdk_loop_wired=True,
        ))
        payload["gates"]["direct_provider_forbidden"] = False

        with self.assertRaises(ProductLoopCheckViolation):
            validate_product_loop_check(payload)

    def test_validate_product_loop_check_rejects_ready_state_without_wiring(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = deepcopy(build_product_loop_check(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            mock_kernel=False,
            callback_loop_wired=True,
            production_sdk_loop_wired=True,
        ))
        payload["status"] = "ready_for_human_review"
        payload["gates"]["callback_loop_wired"] = False

        with self.assertRaises(ProductLoopCheckViolation):
            validate_product_loop_check(payload)

    def test_validate_product_loop_check_rejects_missing_critical_gate(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = deepcopy(build_product_loop_check(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            mock_kernel=False,
            callback_loop_wired=True,
            production_sdk_loop_wired=True,
        ))
        del payload["gates"]["raw_audio_forbidden"]

        with self.assertRaises(ProductLoopCheckViolation):
            validate_product_loop_check(payload)


if __name__ == "__main__":
    unittest.main()
