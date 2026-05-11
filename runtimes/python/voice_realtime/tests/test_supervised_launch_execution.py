from __future__ import annotations

import unittest
from pathlib import Path
from tempfile import TemporaryDirectory

from atlas_voice_agent.daemon_supervisor import evaluate_daemon_supervisor
from atlas_voice_agent.managed_env_writer import (
    execute_managed_env_write,
    inspect_managed_env_writer,
)
from atlas_voice_agent.supervised_launch_execution import (
    LAUNCH_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
    PRE_START_HEALTH_CHECKS_AUTHORIZATION_SCHEMA_VERSION,
    REAL_START_ADAPTER_AUTHORIZATION_SCHEMA_VERSION,
    REAL_START_ADAPTER_DISABLED_SCHEMA_VERSION,
    REAL_START_ADAPTER_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
    REAL_START_ADAPTER_REVIEW_CONTRACT_SCHEMA_VERSION,
    REAL_START_ENABLEMENT_GATE_AUTHORIZATION_SCHEMA_VERSION,
    REAL_START_ENABLEMENT_GATE_SCHEMA_VERSION,
    REVIEWED_REAL_START_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
    REVIEWED_REAL_START_EXECUTION_CONTRACT_SCHEMA_VERSION,
    REVIEWED_SUBPROCESS_START_AUTHORIZATION_SCHEMA_VERSION,
    REVIEWED_SUBPROCESS_START_EXECUTION_SCHEMA_VERSION,
    RUNTIME_POLICY_ENABLEMENT_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
    RUNTIME_POLICY_ENABLEMENT_REVIEW_SCHEMA_VERSION,
    SCHEMA_VERSION,
    SUBPROCESS_START_AUTHORIZATION_SCHEMA_VERSION,
    SUBPROCESS_START_CONTRACT_SCHEMA_VERSION,
    execute_pre_start_health_checks,
    inspect_real_start_adapter_enablement_gate,
    inspect_real_start_adapter_disabled_by_default,
    inspect_real_start_adapter_review_contract,
    inspect_reviewed_real_start_execution_contract,
    inspect_reviewed_subprocess_start_execution,
    inspect_runtime_policy_enablement_review,
    inspect_supervised_launch_execution,
    inspect_subprocess_start_contract,
)
from atlas_voice_agent.pre_start_health_checks_smoke import build_pre_start_health_checks_smoke
from test_daemon_supervisor import ready_worker_start
from test_managed_env_writer import launch_authorization_contract, managed_environment_contract


def valid_launch_execution_authorization() -> dict[str, object]:
    return {
        "schema_version": LAUNCH_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_subprocess_implementation",
        "subprocess_implementation_allowed": True,
        "process_launch_allowed": False,
        "decision_receipt_id": "decision_receipt_launch_execution_1",
    }


def valid_health_check_authorization() -> dict[str, object]:
    return {
        "schema_version": PRE_START_HEALTH_CHECKS_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved",
        "health_checks_allowed": True,
        "process_launch_allowed": False,
        "decision_receipt_id": "decision_receipt_pre_start_health_1",
    }


def valid_subprocess_start_authorization() -> dict[str, object]:
    return {
        "schema_version": SUBPROCESS_START_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_start_contract",
        "subprocess_contract_allowed": True,
        "process_launch_allowed": False,
        "decision_receipt_id": "decision_receipt_subprocess_start_contract_1",
    }


def valid_reviewed_subprocess_start_authorization() -> dict[str, object]:
    return {
        "schema_version": REVIEWED_SUBPROCESS_START_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_real_start_implementation_plan",
        "reviewed_execution_allowed": True,
        "real_process_start_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_reviewed_subprocess_start_1",
    }


def valid_real_start_adapter_authorization() -> dict[str, object]:
    return {
        "schema_version": REAL_START_ADAPTER_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_disabled_adapter_contract",
        "disabled_adapter_contract_allowed": True,
        "real_process_start_allowed": False,
        "subprocess_module_import_allowed": False,
        "start_enabled": False,
        "decision_receipt_id": "decision_receipt_real_start_adapter_disabled_1",
    }


def valid_real_start_enablement_gate_authorization() -> dict[str, object]:
    return {
        "schema_version": REAL_START_ENABLEMENT_GATE_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_start_enablement_gate",
        "start_enablement_gate_allowed": True,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "policy_patch_required": True,
        "human_review_required": True,
        "rollback_required": True,
        "decision_receipt_id": "decision_receipt_real_start_enablement_gate_1",
    }


def valid_runtime_policy_enablement_review_authorization() -> dict[str, object]:
    return {
        "schema_version": RUNTIME_POLICY_ENABLEMENT_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_runtime_policy_enablement_review",
        "runtime_policy_review_allowed": True,
        "policy_patch_attached": True,
        "reviewed_bundle_hash": "d"*64,
        "human_review_required": True,
        "rollback_required": True,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "decision_receipt_id": "decision_receipt_runtime_policy_enablement_review_1",
    }


def valid_real_start_adapter_review_authorization() -> dict[str, object]:
    return {
        "schema_version": REAL_START_ADAPTER_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_real_start_adapter_review_contract",
        "real_start_adapter_review_allowed": True,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "pid_file_guard_required": True,
        "startup_timeout_required": True,
        "post_start_health_probe_required": True,
        "stdout_stderr_sanitization_required": True,
        "rollback_required": True,
        "decision_receipt_id": "decision_receipt_real_start_adapter_review_1",
    }


def valid_reviewed_real_start_execution_authorization() -> dict[str, object]:
    return {
        "schema_version": REVIEWED_REAL_START_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
        "status": "approved_for_reviewed_real_start_execution_contract",
        "reviewed_real_start_execution_allowed": True,
        "start_execution_allowed": False,
        "process_launch_allowed": False,
        "subprocess_module_import_allowed": False,
        "final_pre_start_receipt_required": True,
        "post_start_ready_event_required": True,
        "rollback_rehearsal_required": True,
        "decision_receipt_id": "decision_receipt_reviewed_real_start_execution_1",
    }


def full_launch_authorization_contract() -> dict[str, object]:
    contract = launch_authorization_contract()
    contract["required_pre_start_checks"] = [
        "kernel_health",
        "livekit_room_connectivity",
        "callback_router_roundtrip",
        "kernel_event_normalizer_roundtrip",
        "turn_receipt_roundtrip",
    ]
    contract["required_receipts"] = [
        "production_promotion_review_receipt",
        "daemon_implementation_review_receipt",
        "launch_execution_decision_receipt",
    ]

    return contract


def passed_health_checks(required_checks: list[str]) -> list[dict[str, object]]:
    return [
        {
            "check": check,
            "status": "passed",
            "passed": True,
            "starts_process": False,
            "provider_calls_made": False,
            "tool_calls_made": False,
            "raw_audio_touched": False,
        }
        for check in required_checks
    ]


def ready_launch_execution() -> dict[str, object]:
    supervisor_execution = evaluate_daemon_supervisor(ready_worker_start())
    writer = inspect_managed_env_writer(
        managed_environment_contract=managed_environment_contract(),
        launch_authorization_contract=launch_authorization_contract(),
    )

    with TemporaryDirectory() as directory:
        env_write = execute_managed_env_write(
            managed_env_writer=writer,
            target_path=Path(directory) / "atlas-voice-worker.env",
            write_authorization={
                "schema_version": "atlas.voice_realtime.managed_env_write_authorization.v1",
                "status": "approved",
                "write_allowed": True,
                "process_launch_allowed": False,
                "decision_receipt_id": "decision_receipt_env_write_1",
            },
        )

    return dict(inspect_supervised_launch_execution(
        supervisor_execution=supervisor_execution,
        launch_authorization_contract=full_launch_authorization_contract(),
        managed_env_write_execution=env_write,
        launch_execution_authorization=valid_launch_execution_authorization(),
    ))


def passed_pre_start_health_checks_execution() -> dict[str, object]:
    launch_execution = ready_launch_execution()

    return dict(execute_pre_start_health_checks(
        supervised_launch_execution=launch_execution,
        health_check_results=passed_health_checks(list(launch_execution["required_pre_start_checks"])),
        health_check_authorization=valid_health_check_authorization(),
    ))


def ready_subprocess_start_contract() -> dict[str, object]:
    launch_execution = ready_launch_execution()
    health_checks_execution = execute_pre_start_health_checks(
        supervised_launch_execution=launch_execution,
        health_check_results=passed_health_checks(list(launch_execution["required_pre_start_checks"])),
        health_check_authorization=valid_health_check_authorization(),
    )

    return dict(inspect_subprocess_start_contract(
        supervised_launch_execution=launch_execution,
        pre_start_health_checks_execution=health_checks_execution,
        subprocess_start_authorization=valid_subprocess_start_authorization(),
    ))


def ready_reviewed_subprocess_start_execution() -> dict[str, object]:
    return dict(inspect_reviewed_subprocess_start_execution(
        subprocess_start_contract=ready_subprocess_start_contract(),
        reviewed_start_authorization=valid_reviewed_subprocess_start_authorization(),
    ))


def ready_real_start_adapter_disabled() -> dict[str, object]:
    return dict(inspect_real_start_adapter_disabled_by_default(
        reviewed_subprocess_start_execution=ready_reviewed_subprocess_start_execution(),
        real_start_adapter_authorization=valid_real_start_adapter_authorization(),
    ))


def ready_real_start_enablement_gate() -> dict[str, object]:
    return dict(inspect_real_start_adapter_enablement_gate(
        real_start_adapter_disabled=ready_real_start_adapter_disabled(),
        enablement_gate_authorization=valid_real_start_enablement_gate_authorization(),
    ))


def ready_runtime_policy_enablement_review() -> dict[str, object]:
    return dict(inspect_runtime_policy_enablement_review(
        real_start_enablement_gate=ready_real_start_enablement_gate(),
        policy_review_authorization=valid_runtime_policy_enablement_review_authorization(),
    ))


def ready_real_start_adapter_review_contract() -> dict[str, object]:
    return dict(inspect_real_start_adapter_review_contract(
        runtime_policy_enablement_review=ready_runtime_policy_enablement_review(),
        real_start_review_authorization=valid_real_start_adapter_review_authorization(),
    ))


class SupervisedLaunchExecutionTest(unittest.TestCase):
    def test_launch_execution_contract_is_available_but_blocked_without_written_env(self) -> None:
        supervisor_execution = evaluate_daemon_supervisor(ready_worker_start())
        payload = inspect_supervised_launch_execution(
            supervisor_execution=supervisor_execution,
            launch_authorization_contract=launch_authorization_contract(),
        )

        self.assertEqual(SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual("atlas.voice_realtime.supervised_launch_execution.v1", payload["schema_version"])
        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["launch_execution_implemented"])
        self.assertFalse(payload["subprocess_launch_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertTrue(payload["pre_start_health_checks_execution_available"])
        self.assertFalse(payload["pre_start_health_checks_executed"])
        self.assertTrue(payload["gates"]["supervisor_execution_ready"])
        self.assertTrue(payload["gates"]["launch_authorization_contract_ready"])
        self.assertFalse(payload["gates"]["managed_env_write_execution_ready"])
        self.assertFalse(payload["gates"]["launch_execution_authorization_ready"])
        self.assertTrue(payload["gates"]["process_launch_disabled"])
        self.assertEqual("<not-written>", payload["managed_env_target_path"])
        self.assertIn("VOICE_DAEMON_SUPERVISED_LAUNCH_EVALUATED", payload["evidence_events"])
        self.assertIn("import_subprocess_from_launch_execution_contract", payload["forbidden_shortcuts"])
        self.assertEqual("fix_supervised_launch_execution_prerequisites", payload["next_action"])
        self.assertEqual(
            "atlas.voice_realtime.launch_execution_authorization.v1",
            LAUNCH_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
        )

    def test_launch_execution_becomes_ready_after_written_env_and_authorization_without_starting(self) -> None:
        payload = ready_launch_execution()

        self.assertEqual("ready_for_subprocess_implementation", payload["status"])
        self.assertEqual("atlas.voice_realtime.managed_env_write_execution.v1", payload["managed_env_write_execution_schema_version"])
        self.assertTrue(payload["gates"]["managed_env_write_execution_ready"])
        self.assertTrue(payload["gates"]["launch_execution_authorization_ready"])
        self.assertFalse(payload["subprocess_launch_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertEqual("<managed-env-file-written>", payload["managed_env_target_path"])
        self.assertIsInstance(payload["managed_env_content_sha256"], str)
        self.assertIn("<managed-env-file-written>", payload["argv_redacted"])
        self.assertEqual("implement_subprocess_start_after_health_checks", payload["next_action"])

    def test_launch_execution_blocks_if_authorization_would_allow_process_launch(self) -> None:
        supervisor_execution = evaluate_daemon_supervisor(ready_worker_start())
        payload = inspect_supervised_launch_execution(
            supervisor_execution=supervisor_execution,
            launch_authorization_contract=full_launch_authorization_contract(),
            launch_execution_authorization={
                **valid_launch_execution_authorization(),
                "process_launch_allowed": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertFalse(payload["gates"]["launch_execution_authorization_ready"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_supervised_launch_execution_prerequisites", payload["next_action"])

    def test_pre_start_health_checks_pass_without_starting_process(self) -> None:
        launch_execution = ready_launch_execution()
        payload = execute_pre_start_health_checks(
            supervised_launch_execution=launch_execution,
            health_check_results=passed_health_checks(list(launch_execution["required_pre_start_checks"])),
            health_check_authorization=valid_health_check_authorization(),
        )

        self.assertEqual("atlas.voice_realtime.pre_start_health_checks_execution.v1", payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.pre_start_health_checks_authorization.v1",
            PRE_START_HEALTH_CHECKS_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("passed_no_process_start", payload["status"])
        self.assertTrue(payload["pre_start_health_checks_executed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertFalse(payload["provider_calls_made"])
        self.assertFalse(payload["raw_audio_touched"])
        self.assertTrue(payload["gates"]["required_checks_passed"])
        self.assertIn("VOICE_DAEMON_PRE_START_HEALTH_CHECKS_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_subprocess_start_contract_after_pre_start_checks", payload["next_action"])

    def test_pre_start_health_checks_block_missing_or_unsafe_check(self) -> None:
        launch_execution = ready_launch_execution()
        required_checks = list(launch_execution["required_pre_start_checks"])
        unsafe_results = passed_health_checks(required_checks[:-1])
        unsafe_results[0]["provider_calls_made"] = True

        payload = execute_pre_start_health_checks(
            supervised_launch_execution=launch_execution,
            health_check_results=unsafe_results,
            health_check_authorization=valid_health_check_authorization(),
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["missing_checks"])
        self.assertTrue(payload["invalid_checks"])
        self.assertFalse(payload["gates"]["required_checks_present"])
        self.assertFalse(payload["gates"]["required_checks_passed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_pre_start_health_check_prerequisites", payload["next_action"])

    def test_subprocess_start_contract_blocks_without_pre_start_health_checks(self) -> None:
        payload = inspect_subprocess_start_contract(
            supervised_launch_execution=ready_launch_execution(),
            subprocess_start_authorization=valid_subprocess_start_authorization(),
        )

        self.assertEqual(SUBPROCESS_START_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.subprocess_start_contract.v1",
            SUBPROCESS_START_CONTRACT_SCHEMA_VERSION,
        )
        self.assertEqual(
            "atlas.voice_realtime.subprocess_start_authorization.v1",
            SUBPROCESS_START_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["subprocess_start_contract_implemented"])
        self.assertFalse(payload["subprocess_launch_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertFalse(payload["provider_calls_made"])
        self.assertFalse(payload["raw_audio_touched"])
        self.assertTrue(payload["gates"]["supervised_launch_ready"])
        self.assertFalse(payload["gates"]["pre_start_health_checks_passed"])
        self.assertTrue(payload["gates"]["subprocess_start_authorization_ready"])
        self.assertIn("VOICE_DAEMON_SUBPROCESS_START_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("fix_subprocess_start_contract_prerequisites", payload["next_action"])

    def test_subprocess_start_contract_becomes_ready_without_importing_or_starting(self) -> None:
        launch_execution = ready_launch_execution()
        health_checks_execution = execute_pre_start_health_checks(
            supervised_launch_execution=launch_execution,
            health_check_results=passed_health_checks(list(launch_execution["required_pre_start_checks"])),
            health_check_authorization=valid_health_check_authorization(),
        )

        payload = inspect_subprocess_start_contract(
            supervised_launch_execution=launch_execution,
            pre_start_health_checks_execution=health_checks_execution,
            subprocess_start_authorization=valid_subprocess_start_authorization(),
        )

        self.assertEqual("ready_for_reviewed_subprocess_start_implementation", payload["status"])
        self.assertTrue(payload["subprocess_start_contract_implemented"])
        self.assertFalse(payload["subprocess_launch_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertFalse(payload["provider_calls_made"])
        self.assertFalse(payload["tool_calls_made"])
        self.assertFalse(payload["raw_audio_touched"])
        self.assertTrue(payload["pre_start_health_checks_executed"])
        self.assertEqual("passed_no_process_start", payload["pre_start_health_checks_status"])
        self.assertEqual("decision_receipt_subprocess_start_contract_1", payload["decision_receipt_id"])
        self.assertEqual("<managed-env-file-written>", payload["env_file_ref"])
        self.assertIn("startup_timeout_guard", payload["required_runtime_guards"])
        self.assertIn("<managed-env-file-written>", payload["argv_redacted"])
        self.assertTrue(payload["gates"]["supervised_launch_ready"])
        self.assertTrue(payload["gates"]["pre_start_health_checks_passed"])
        self.assertTrue(payload["gates"]["subprocess_start_authorization_ready"])
        self.assertTrue(payload["gates"]["process_launch_disabled"])
        self.assertEqual("implement_real_subprocess_start_after_final_review", payload["next_action"])

    def test_subprocess_start_contract_blocks_if_authorization_would_allow_process_launch(self) -> None:
        payload = inspect_subprocess_start_contract(
            supervised_launch_execution=ready_launch_execution(),
            pre_start_health_checks_execution=passed_pre_start_health_checks_execution(),
            subprocess_start_authorization={
                **valid_subprocess_start_authorization(),
                "process_launch_allowed": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertFalse(payload["gates"]["subprocess_start_authorization_ready"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertEqual("fix_subprocess_start_contract_prerequisites", payload["next_action"])

    def test_reviewed_subprocess_start_execution_becomes_ready_without_importing_or_starting(self) -> None:
        payload = inspect_reviewed_subprocess_start_execution(
            subprocess_start_contract=ready_subprocess_start_contract(),
            reviewed_start_authorization=valid_reviewed_subprocess_start_authorization(),
        )

        self.assertEqual(REVIEWED_SUBPROCESS_START_EXECUTION_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.reviewed_subprocess_start_authorization.v1",
            REVIEWED_SUBPROCESS_START_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_real_start_implementation", payload["status"])
        self.assertTrue(payload["reviewed_subprocess_start_execution_implemented"])
        self.assertFalse(payload["real_subprocess_start_implemented"])
        self.assertFalse(payload["subprocess_launch_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertFalse(payload["provider_calls_made"])
        self.assertFalse(payload["tool_calls_made"])
        self.assertFalse(payload["raw_audio_touched"])
        self.assertEqual("decision_receipt_reviewed_subprocess_start_1", payload["decision_receipt_id"])
        self.assertIn("startup_timeout_enforced", payload["required_real_start_controls"])
        self.assertIn("startup_timeout_guard", payload["required_runtime_guards"])
        self.assertTrue(payload["gates"]["subprocess_start_contract_ready"])
        self.assertTrue(payload["gates"]["review_authorization_ready"])
        self.assertTrue(payload["gates"]["real_start_disabled"])
        self.assertTrue(payload["gates"]["subprocess_import_disabled"])
        self.assertIn("VOICE_DAEMON_REVIEWED_SUBPROCESS_START_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_real_start_adapter_disabled_by_default", payload["next_action"])

    def test_reviewed_subprocess_start_execution_blocks_if_authorization_would_allow_real_start(self) -> None:
        payload = inspect_reviewed_subprocess_start_execution(
            subprocess_start_contract=ready_subprocess_start_contract(),
            reviewed_start_authorization={
                **valid_reviewed_subprocess_start_authorization(),
                "real_process_start_allowed": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["subprocess_start_contract_ready"])
        self.assertFalse(payload["gates"]["review_authorization_ready"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertEqual("fix_reviewed_subprocess_start_execution_prerequisites", payload["next_action"])

    def test_real_start_adapter_contract_is_ready_but_disabled_by_default(self) -> None:
        payload = inspect_real_start_adapter_disabled_by_default(
            reviewed_subprocess_start_execution=ready_reviewed_subprocess_start_execution(),
            real_start_adapter_authorization=valid_real_start_adapter_authorization(),
        )

        self.assertEqual(REAL_START_ADAPTER_DISABLED_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.real_start_adapter_authorization.v1",
            REAL_START_ADAPTER_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_disabled_by_default", payload["status"])
        self.assertTrue(payload["real_start_adapter_contract_implemented"])
        self.assertFalse(payload["real_start_adapter_enabled"])
        self.assertFalse(payload["real_subprocess_start_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertFalse(payload["provider_calls_made"])
        self.assertFalse(payload["tool_calls_made"])
        self.assertFalse(payload["raw_audio_touched"])
        self.assertTrue(payload["gates"]["reviewed_subprocess_start_execution_ready"])
        self.assertTrue(payload["gates"]["real_start_adapter_authorization_ready"])
        self.assertTrue(payload["gates"]["start_disabled_by_default"])
        self.assertIn("runtime_policy_must_enable_start_explicitly", payload["disabled_by_default_controls"])
        self.assertIn("VOICE_DAEMON_REAL_START_ADAPTER_DECLARED", payload["evidence_events"])
        self.assertEqual("implement_real_start_adapter_enablement_gate", payload["next_action"])

    def test_real_start_adapter_contract_blocks_if_authorization_enables_start(self) -> None:
        payload = inspect_real_start_adapter_disabled_by_default(
            reviewed_subprocess_start_execution=ready_reviewed_subprocess_start_execution(),
            real_start_adapter_authorization={
                **valid_real_start_adapter_authorization(),
                "start_enabled": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["reviewed_subprocess_start_execution_ready"])
        self.assertFalse(payload["gates"]["real_start_adapter_authorization_ready"])
        self.assertFalse(payload["real_start_adapter_enabled"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_real_start_adapter_disabled_prerequisites", payload["next_action"])

    def test_real_start_enablement_gate_is_ready_without_enabling_or_starting(self) -> None:
        payload = inspect_real_start_adapter_enablement_gate(
            real_start_adapter_disabled=ready_real_start_adapter_disabled(),
            enablement_gate_authorization=valid_real_start_enablement_gate_authorization(),
        )

        self.assertEqual(REAL_START_ENABLEMENT_GATE_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.real_start_enablement_gate_authorization.v1",
            REAL_START_ENABLEMENT_GATE_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_policy_enablement_review", payload["status"])
        self.assertTrue(payload["real_start_enablement_gate_implemented"])
        self.assertFalse(payload["real_start_adapter_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["real_subprocess_start_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertFalse(payload["provider_calls_made"])
        self.assertFalse(payload["tool_calls_made"])
        self.assertFalse(payload["raw_audio_touched"])
        self.assertTrue(payload["gates"]["real_start_adapter_disabled_ready"])
        self.assertTrue(payload["gates"]["enablement_gate_authorization_ready"])
        self.assertTrue(payload["gates"]["policy_patch_required"])
        self.assertTrue(payload["gates"]["human_review_required"])
        self.assertTrue(payload["gates"]["rollback_required"])
        self.assertTrue(payload["gates"]["start_execution_disabled"])
        self.assertIn("runtime_policy_patch_required_before_start_enabled", payload["policy_enablement_controls"])
        self.assertIn("VOICE_DAEMON_REAL_START_ENABLEMENT_GATE_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_runtime_policy_enablement_review", payload["next_action"])

    def test_real_start_enablement_gate_blocks_if_authorization_allows_start(self) -> None:
        payload = inspect_real_start_adapter_enablement_gate(
            real_start_adapter_disabled=ready_real_start_adapter_disabled(),
            enablement_gate_authorization={
                **valid_real_start_enablement_gate_authorization(),
                "start_execution_allowed": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["real_start_adapter_disabled_ready"])
        self.assertFalse(payload["gates"]["enablement_gate_authorization_ready"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_real_start_enablement_gate_prerequisites", payload["next_action"])

    def test_runtime_policy_enablement_review_is_ready_without_enabling_start(self) -> None:
        payload = inspect_runtime_policy_enablement_review(
            real_start_enablement_gate=ready_real_start_enablement_gate(),
            policy_review_authorization=valid_runtime_policy_enablement_review_authorization(),
        )

        self.assertEqual(RUNTIME_POLICY_ENABLEMENT_REVIEW_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.runtime_policy_enablement_review_authorization.v1",
            RUNTIME_POLICY_ENABLEMENT_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_real_start_adapter_review", payload["status"])
        self.assertTrue(payload["runtime_policy_enablement_review_implemented"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["real_start_adapter_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["real_subprocess_start_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertFalse(payload["provider_calls_made"])
        self.assertFalse(payload["tool_calls_made"])
        self.assertFalse(payload["raw_audio_touched"])
        self.assertTrue(payload["gates"]["real_start_enablement_gate_ready"])
        self.assertTrue(payload["gates"]["policy_review_authorization_ready"])
        self.assertTrue(payload["gates"]["policy_patch_attached"])
        self.assertTrue(payload["gates"]["reviewed_bundle_hash_required"])
        self.assertTrue(payload["gates"]["runtime_policy_start_disabled"])
        self.assertIn("policy_patch_attached_to_current_evidence_bundle", payload["required_policy_review_controls"])
        self.assertIn("VOICE_DAEMON_RUNTIME_POLICY_ENABLEMENT_REVIEW_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_real_start_adapter_review_contract", payload["next_action"])

    def test_runtime_policy_enablement_review_blocks_without_bundle_hash(self) -> None:
        payload = inspect_runtime_policy_enablement_review(
            real_start_enablement_gate=ready_real_start_enablement_gate(),
            policy_review_authorization={
                **valid_runtime_policy_enablement_review_authorization(),
                "reviewed_bundle_hash": "too-short",
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["real_start_enablement_gate_ready"])
        self.assertFalse(payload["gates"]["policy_review_authorization_ready"])
        self.assertFalse(payload["gates"]["reviewed_bundle_hash_required"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_runtime_policy_enablement_review_prerequisites", payload["next_action"])

    def test_real_start_adapter_review_contract_is_ready_without_starting(self) -> None:
        payload = inspect_real_start_adapter_review_contract(
            runtime_policy_enablement_review=ready_runtime_policy_enablement_review(),
            real_start_review_authorization=valid_real_start_adapter_review_authorization(),
        )

        self.assertEqual(REAL_START_ADAPTER_REVIEW_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.real_start_adapter_review_authorization.v1",
            REAL_START_ADAPTER_REVIEW_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_reviewed_real_start_execution_contract", payload["status"])
        self.assertTrue(payload["real_start_adapter_review_contract_implemented"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["real_start_adapter_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["real_subprocess_start_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertFalse(payload["provider_calls_made"])
        self.assertFalse(payload["tool_calls_made"])
        self.assertFalse(payload["raw_audio_touched"])
        self.assertTrue(payload["gates"]["runtime_policy_enablement_review_ready"])
        self.assertTrue(payload["gates"]["real_start_review_authorization_ready"])
        self.assertTrue(payload["gates"]["pid_file_guard_required"])
        self.assertTrue(payload["gates"]["startup_timeout_required"])
        self.assertTrue(payload["gates"]["post_start_health_probe_required"])
        self.assertTrue(payload["gates"]["stdout_stderr_sanitization_required"])
        self.assertIn("pid_file_written_with_0600_permissions", payload["required_real_start_adapter_controls"])
        self.assertIn("VOICE_DAEMON_REAL_START_ADAPTER_REVIEW_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_reviewed_real_start_execution_contract", payload["next_action"])

    def test_real_start_adapter_review_contract_blocks_if_authorization_allows_subprocess_import(self) -> None:
        payload = inspect_real_start_adapter_review_contract(
            runtime_policy_enablement_review=ready_runtime_policy_enablement_review(),
            real_start_review_authorization={
                **valid_real_start_adapter_review_authorization(),
                "subprocess_module_import_allowed": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["runtime_policy_enablement_review_ready"])
        self.assertFalse(payload["gates"]["real_start_review_authorization_ready"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_real_start_adapter_review_contract_prerequisites", payload["next_action"])

    def test_reviewed_real_start_execution_contract_is_ready_without_starting(self) -> None:
        payload = inspect_reviewed_real_start_execution_contract(
            real_start_adapter_review_contract=ready_real_start_adapter_review_contract(),
            reviewed_real_start_authorization=valid_reviewed_real_start_execution_authorization(),
        )

        self.assertEqual(REVIEWED_REAL_START_EXECUTION_CONTRACT_SCHEMA_VERSION, payload["schema_version"])
        self.assertEqual(
            "atlas.voice_realtime.reviewed_real_start_execution_authorization.v1",
            REVIEWED_REAL_START_EXECUTION_AUTHORIZATION_SCHEMA_VERSION,
        )
        self.assertEqual("ready_for_start_execution_implementation", payload["status"])
        self.assertTrue(payload["reviewed_real_start_execution_contract_implemented"])
        self.assertFalse(payload["runtime_policy_start_enabled"])
        self.assertFalse(payload["real_start_adapter_enabled"])
        self.assertFalse(payload["start_execution_allowed"])
        self.assertFalse(payload["real_subprocess_start_implemented"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertTrue(payload["gates"]["real_start_adapter_review_contract_ready"])
        self.assertTrue(payload["gates"]["reviewed_real_start_authorization_ready"])
        self.assertTrue(payload["gates"]["final_pre_start_receipt_required"])
        self.assertTrue(payload["gates"]["post_start_ready_event_required"])
        self.assertTrue(payload["gates"]["rollback_rehearsal_required"])
        self.assertIn("single_start_attempt_per_receipt", payload["required_start_execution_controls"])
        self.assertIn("VOICE_DAEMON_REVIEWED_REAL_START_EXECUTION_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertEqual("implement_final_start_executor_disabled_by_default", payload["next_action"])

    def test_reviewed_real_start_execution_contract_blocks_if_authorization_allows_start(self) -> None:
        payload = inspect_reviewed_real_start_execution_contract(
            real_start_adapter_review_contract=ready_real_start_adapter_review_contract(),
            reviewed_real_start_authorization={
                **valid_reviewed_real_start_execution_authorization(),
                "start_execution_allowed": True,
            },
        )

        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["gates"]["real_start_adapter_review_contract_ready"])
        self.assertFalse(payload["gates"]["reviewed_real_start_authorization_ready"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertEqual("fix_reviewed_real_start_execution_contract_prerequisites", payload["next_action"])

    def test_pre_start_health_checks_smoke_passes_without_process_start_or_secret_path_leak(self) -> None:
        payload = build_pre_start_health_checks_smoke()

        self.assertEqual("atlas.voice_realtime.pre_start_health_checks_smoke.v1", payload["schema_version"])
        self.assertEqual("passed_no_process_start", payload["status"])
        self.assertTrue(payload["smoke_only"])
        self.assertEqual("not_proven_by_smoke", payload["production_readiness"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["subprocess_module_imported"])
        self.assertFalse(payload["livekit_sdk_imported"])
        self.assertFalse(payload["provider_calls_made"])
        self.assertFalse(payload["tool_calls_made"])
        self.assertFalse(payload["raw_audio_touched"])
        self.assertEqual("<temporary-managed-env-file>", payload["managed_env_write_execution"]["target_path"])
        self.assertTrue(payload["temporary_env_file_removed_after_smoke"])
        self.assertTrue(payload["gates"]["managed_env_placeholder_written"])
        self.assertTrue(payload["gates"]["managed_env_target_redacted"])
        self.assertTrue(payload["gates"]["pre_start_health_checks_passed"])
        self.assertTrue(payload["gates"]["subprocess_start_contract_ready"])
        self.assertTrue(payload["gates"]["reviewed_subprocess_start_execution_ready"])
        self.assertEqual("passed_no_process_start", payload["pre_start_health_checks"]["status"])
        self.assertEqual(
            "ready_for_reviewed_subprocess_start_implementation",
            payload["subprocess_start_contract"]["status"],
        )
        self.assertEqual(
            "ready_for_real_start_implementation",
            payload["reviewed_subprocess_start_execution"]["status"],
        )
        self.assertTrue(payload["gates"]["real_start_adapter_disabled_ready"])
        self.assertTrue(payload["gates"]["real_start_enablement_gate_ready"])
        self.assertTrue(payload["gates"]["runtime_policy_enablement_review_ready"])
        self.assertTrue(payload["gates"]["real_start_adapter_review_contract_ready"])
        self.assertTrue(payload["gates"]["reviewed_real_start_execution_contract_ready"])
        self.assertEqual(
            "ready_disabled_by_default",
            payload["real_start_adapter_disabled"]["status"],
        )
        self.assertEqual(
            "ready_for_policy_enablement_review",
            payload["real_start_enablement_gate"]["status"],
        )
        self.assertEqual(
            "ready_for_real_start_adapter_review",
            payload["runtime_policy_enablement_review"]["status"],
        )
        self.assertEqual(
            "ready_for_reviewed_real_start_execution_contract",
            payload["real_start_adapter_review_contract"]["status"],
        )
        self.assertEqual(
            "ready_for_start_execution_implementation",
            payload["reviewed_real_start_execution_contract"]["status"],
        )
        self.assertIn("VOICE_DAEMON_PRE_START_HEALTH_CHECKS_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_REVIEWED_SUBPROCESS_START_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_REAL_START_ADAPTER_DECLARED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_REAL_START_ENABLEMENT_GATE_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_RUNTIME_POLICY_ENABLEMENT_REVIEW_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_REAL_START_ADAPTER_REVIEW_CONTRACT_EVALUATED", payload["evidence_events"])
        self.assertIn("VOICE_DAEMON_REVIEWED_REAL_START_EXECUTION_CONTRACT_EVALUATED", payload["evidence_events"])


if __name__ == "__main__":
    unittest.main()
