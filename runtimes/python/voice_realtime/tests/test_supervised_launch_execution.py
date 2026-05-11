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
    SCHEMA_VERSION,
    SUBPROCESS_START_AUTHORIZATION_SCHEMA_VERSION,
    SUBPROCESS_START_CONTRACT_SCHEMA_VERSION,
    execute_pre_start_health_checks,
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
        self.assertEqual("passed_no_process_start", payload["pre_start_health_checks"]["status"])
        self.assertEqual(
            "ready_for_reviewed_subprocess_start_implementation",
            payload["subprocess_start_contract"]["status"],
        )
        self.assertIn("VOICE_DAEMON_PRE_START_HEALTH_CHECKS_EVALUATED", payload["evidence_events"])


if __name__ == "__main__":
    unittest.main()
