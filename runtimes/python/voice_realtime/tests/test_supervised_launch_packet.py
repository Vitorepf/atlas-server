from __future__ import annotations

import copy
import unittest

from atlas_voice_agent.supervised_launch_execution import (
    execute_pre_start_health_checks,
    inspect_guarded_start_activation_contract,
    inspect_guarded_start_execution_attempt_contract,
    inspect_guarded_start_execution_rehearsal_contract,
    inspect_guarded_start_observability_contract,
    inspect_guarded_start_operator_acceptance_contract,
    inspect_guarded_start_final_start_receipt_contract,
    inspect_guarded_start_launch_window_contract,
    inspect_guarded_start_pre_launch_guard_contract,
    inspect_guarded_start_executor_runtime_contract,
    inspect_guarded_start_process_spawn_contract,
    inspect_guarded_start_spawn_review_contract,
    inspect_guarded_start_subprocess_import_contract,
    inspect_guarded_start_launch_invocation_contract,
    inspect_guarded_start_final_process_start_contract,
    inspect_guarded_start_process_execution_review,
    inspect_guarded_start_process_execution_packet,
    inspect_guarded_start_process_executor_stub,
    inspect_guarded_start_process_executor_review,
    inspect_guarded_start_process_executor_contract,
    inspect_guarded_start_process_runtime_adapter,
    inspect_guarded_start_process_adapter_review,
    inspect_guarded_start_process_adapter_contract,
    inspect_guarded_start_process_runner_contract,
    inspect_guarded_start_process_runner_execution_contract,
    inspect_guarded_start_process_runner_execution_review,
    inspect_guarded_start_process_runner_packet,
    inspect_guarded_start_process_runner_review,
    inspect_guarded_start_process_runner_final_review,
    inspect_guarded_start_process_runner_operator_release_review,
    inspect_guarded_start_process_runner_promotion_packet,
    inspect_guarded_start_process_runner_release_authorization,
    inspect_guarded_start_process_runner_release_finalization,
    inspect_controlled_livekit_server_supervised_smoke_contract,
    inspect_guarded_start_process_runner_start_gate,
    inspect_guarded_start_release_candidate_contract,
    inspect_final_start_executor_disabled_by_default,
    inspect_final_start_executor_enablement_gate,
    inspect_guarded_start_dry_run_contract,
    inspect_guarded_start_final_enablement_gate_contract,
    inspect_guarded_start_human_review_contract,
    inspect_guarded_start_policy_enablement_contract,
    inspect_guarded_start_policy_patch_review_contract,
    inspect_guarded_start_runtime_handoff_contract,
    inspect_guarded_start_simulation_contract,
    inspect_guarded_start_executor_disabled_by_default,
    inspect_guarded_start_executor_enablement_gate,
    inspect_reviewed_guarded_start_execution_contract,
    inspect_real_start_execution_contract,
    inspect_supervised_start_execution_review,
    inspect_real_start_adapter_enablement_gate,
    inspect_real_start_adapter_disabled_by_default,
    inspect_real_start_adapter_review_contract,
    inspect_reviewed_real_start_execution_contract,
    inspect_reviewed_subprocess_start_execution,
    inspect_runtime_policy_enablement_review,
    inspect_subprocess_start_contract,
)
from atlas_voice_agent.supervised_launch_packet import (
    SupervisedLaunchPacketViolation,
    validate_pre_start_health_checks_packet,
    validate_guarded_start_activation_contract_packet,
    validate_guarded_start_execution_attempt_contract_packet,
    validate_guarded_start_execution_rehearsal_contract_packet,
    validate_guarded_start_observability_contract_packet,
    validate_guarded_start_operator_acceptance_contract_packet,
    validate_guarded_start_final_start_receipt_contract_packet,
    validate_guarded_start_launch_window_contract_packet,
    validate_guarded_start_pre_launch_guard_contract_packet,
    validate_guarded_start_executor_runtime_contract_packet,
    validate_guarded_start_process_spawn_contract_packet,
    validate_guarded_start_spawn_review_contract_packet,
    validate_guarded_start_subprocess_import_contract_packet,
    validate_guarded_start_launch_invocation_contract_packet,
    validate_guarded_start_final_process_start_contract_packet,
    validate_guarded_start_process_execution_review_packet,
    validate_guarded_start_process_execution_packet_packet,
    validate_guarded_start_process_executor_stub_packet,
    validate_guarded_start_process_executor_review_packet,
    validate_guarded_start_process_executor_contract_packet,
    validate_guarded_start_process_runtime_adapter_packet,
    validate_guarded_start_process_adapter_review_packet,
    validate_guarded_start_process_adapter_contract_packet,
    validate_guarded_start_process_runner_contract_packet,
    validate_guarded_start_process_runner_execution_contract_packet,
    validate_guarded_start_process_runner_execution_review_packet,
    validate_guarded_start_process_runner_packet_packet,
    validate_guarded_start_process_runner_review_packet,
    validate_guarded_start_process_runner_final_review_packet,
    validate_guarded_start_process_runner_operator_release_review_packet,
    validate_guarded_start_process_runner_promotion_packet_packet,
    validate_guarded_start_process_runner_release_finalization_packet,
    validate_guarded_start_process_runner_release_authorization_packet,
    validate_controlled_livekit_server_supervised_smoke_contract_packet,
    validate_guarded_start_process_runner_start_gate_packet,
    validate_guarded_start_release_candidate_contract_packet,
    validate_final_start_executor_disabled_packet,
    validate_final_start_executor_enablement_gate_packet,
    validate_guarded_start_dry_run_contract_packet,
    validate_guarded_start_final_enablement_gate_contract_packet,
    validate_guarded_start_human_review_contract_packet,
    validate_guarded_start_policy_enablement_contract_packet,
    validate_guarded_start_policy_patch_review_contract_packet,
    validate_guarded_start_runtime_handoff_contract_packet,
    validate_guarded_start_simulation_contract_packet,
    validate_guarded_start_executor_disabled_packet,
    validate_guarded_start_executor_enablement_gate_packet,
    validate_reviewed_guarded_start_execution_contract_packet,
    validate_real_start_execution_contract_packet,
    validate_real_start_adapter_enablement_gate_packet,
    validate_real_start_adapter_disabled_packet,
    validate_real_start_adapter_review_contract_packet,
    validate_reviewed_subprocess_start_execution_packet,
    validate_reviewed_real_start_execution_contract_packet,
    validate_runtime_policy_enablement_review_packet,
    validate_subprocess_start_packet,
    validate_supervised_start_execution_review_packet,
    validate_supervised_launch_packet,
)
from test_supervised_launch_execution import (
    passed_health_checks,
    ready_launch_execution,
    ready_real_start_adapter_disabled,
    ready_real_start_enablement_gate,
    ready_runtime_policy_enablement_review,
    ready_real_start_adapter_review_contract,
    ready_reviewed_real_start_execution_contract,
    ready_final_start_executor_disabled,
    ready_final_start_executor_enablement_gate,
    ready_guarded_start_dry_run_contract,
    ready_guarded_start_final_enablement_gate_contract,
    ready_guarded_start_human_review_contract,
    ready_guarded_start_policy_enablement_contract,
    ready_guarded_start_activation_contract,
    ready_guarded_start_execution_attempt_contract,
    ready_guarded_start_execution_rehearsal_contract,
    ready_guarded_start_observability_contract,
    ready_guarded_start_operator_acceptance_contract,
    ready_guarded_start_final_start_receipt_contract,
    ready_guarded_start_launch_window_contract,
    ready_guarded_start_pre_launch_guard_contract,
    ready_guarded_start_executor_runtime_contract,
    ready_guarded_start_process_spawn_contract,
    ready_guarded_start_spawn_review_contract,
    ready_guarded_start_subprocess_import_contract,
    ready_guarded_start_launch_invocation_contract,
    ready_guarded_start_final_process_start_contract,
    ready_guarded_start_process_execution_review,
    ready_guarded_start_process_execution_packet,
    ready_guarded_start_process_executor_stub,
    ready_guarded_start_process_executor_review,
    ready_guarded_start_process_executor_contract,
    ready_guarded_start_process_runtime_adapter,
    ready_guarded_start_process_adapter_review,
    ready_guarded_start_process_adapter_contract,
    ready_guarded_start_process_runner_contract,
    ready_guarded_start_process_runner_execution_contract,
    ready_guarded_start_process_runner_execution_review,
    ready_guarded_start_process_runner_packet,
    ready_guarded_start_process_runner_review,
    ready_guarded_start_process_runner_final_review,
    ready_guarded_start_process_runner_operator_release_review,
    ready_guarded_start_process_runner_promotion_packet,
    ready_guarded_start_process_runner_release_finalization,
    ready_guarded_start_process_runner_release_authorization,
    ready_controlled_livekit_server_supervised_smoke_contract,
    ready_guarded_start_process_runner_start_gate,
    ready_guarded_start_release_candidate_contract,
    ready_guarded_start_policy_patch_review_contract,
    ready_guarded_start_runtime_handoff_contract,
    ready_guarded_start_simulation_contract,
    ready_guarded_start_executor_disabled,
    ready_guarded_start_executor_enablement_gate,
    ready_reviewed_guarded_start_execution_contract,
    ready_real_start_execution_contract,
    ready_supervised_start_execution_review,
    ready_reviewed_subprocess_start_execution,
    ready_subprocess_start_contract,
    valid_real_start_enablement_gate_authorization,
    valid_real_start_adapter_authorization,
    valid_real_start_adapter_review_authorization,
    valid_final_start_executor_authorization,
    valid_final_start_executor_enablement_authorization,
    valid_guarded_start_dry_run_plan,
    valid_guarded_start_final_enablement_authorization,
    valid_guarded_start_human_review_authorization,
    valid_guarded_start_policy_enablement_authorization,
    valid_guarded_start_activation_authorization,
    valid_guarded_start_execution_attempt_authorization,
    valid_guarded_start_execution_rehearsal_authorization,
    valid_guarded_start_observability_authorization,
    valid_guarded_start_operator_acceptance_authorization,
    valid_guarded_start_final_start_receipt_authorization,
    valid_guarded_start_launch_window_authorization,
    valid_guarded_start_pre_launch_guard_authorization,
    valid_guarded_start_executor_runtime_authorization,
    valid_guarded_start_process_spawn_authorization,
    valid_guarded_start_spawn_review_authorization,
    valid_guarded_start_subprocess_import_authorization,
    valid_guarded_start_launch_invocation_authorization,
    valid_guarded_start_final_process_start_authorization,
    valid_guarded_start_process_execution_review_authorization,
    valid_guarded_start_process_execution_packet_authorization,
    valid_guarded_start_process_executor_stub_authorization,
    valid_guarded_start_process_executor_review_authorization,
    valid_guarded_start_process_executor_contract_authorization,
    valid_guarded_start_process_runtime_adapter_authorization,
    valid_guarded_start_process_adapter_review_authorization,
    valid_guarded_start_process_adapter_contract_authorization,
    valid_guarded_start_process_runner_contract_authorization,
    valid_guarded_start_process_runner_execution_contract_authorization,
    valid_guarded_start_process_runner_execution_review_authorization,
    valid_guarded_start_process_runner_packet_authorization,
    valid_guarded_start_process_runner_review_authorization,
    valid_guarded_start_process_runner_final_review_authorization,
    valid_guarded_start_process_runner_operator_release_review_authorization,
    valid_guarded_start_process_runner_promotion_packet_authorization,
    valid_guarded_start_process_runner_release_finalization_authorization,
    valid_guarded_start_process_runner_release_authorization,
    valid_controlled_livekit_server_supervised_smoke_plan,
    valid_guarded_start_process_runner_start_gate_authorization,
    valid_guarded_start_release_candidate_authorization,
    valid_guarded_start_policy_patch_review_authorization,
    valid_guarded_start_runtime_handoff_plan,
    valid_guarded_start_simulation_plan,
    valid_guarded_start_executor_authorization,
    valid_guarded_start_executor_enablement_authorization,
    valid_reviewed_guarded_start_execution_authorization,
    valid_real_start_execution_authorization,
    valid_supervised_start_execution_review_authorization,
    valid_reviewed_real_start_execution_authorization,
    valid_reviewed_subprocess_start_authorization,
    valid_runtime_policy_enablement_review_authorization,
    valid_health_check_authorization,
    valid_subprocess_start_authorization,
)


class SupervisedLaunchPacketTest(unittest.TestCase):
    def test_accepts_fail_closed_supervised_launch_packet(self) -> None:
        payload = ready_launch_execution()

        validated = validate_supervised_launch_packet(payload)

        self.assertIs(validated, payload)
        self.assertFalse(validated["process_launch_attempted"])

    def test_rejects_supervised_launch_that_imports_subprocess(self) -> None:
        payload = ready_launch_execution()
        payload["subprocess_module_imported"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "subprocess_module_imported"):
            validate_supervised_launch_packet(payload)

    def test_accepts_fail_closed_pre_start_health_packet(self) -> None:
        launch = ready_launch_execution()
        payload = execute_pre_start_health_checks(
            supervised_launch_execution=launch,
            health_check_results=passed_health_checks(list(launch["required_pre_start_checks"])),
            health_check_authorization=valid_health_check_authorization(),
        )

        validated = validate_pre_start_health_checks_packet(payload)

        self.assertIs(validated, payload)
        self.assertFalse(validated["raw_audio_touched"])

    def test_rejects_pre_start_health_packet_with_nested_raw_audio(self) -> None:
        launch = ready_launch_execution()
        payload = execute_pre_start_health_checks(
            supervised_launch_execution=launch,
            health_check_results=passed_health_checks(list(launch["required_pre_start_checks"])),
            health_check_authorization=valid_health_check_authorization(),
        )
        payload["debug"] = {"raw_audio": "never"}  # type: ignore[index]

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_pre_start_health_checks_packet(payload)

    def test_accepts_fail_closed_subprocess_start_packet(self) -> None:
        launch = ready_launch_execution()
        health = execute_pre_start_health_checks(
            supervised_launch_execution=launch,
            health_check_results=passed_health_checks(list(launch["required_pre_start_checks"])),
            health_check_authorization=valid_health_check_authorization(),
        )
        payload = inspect_subprocess_start_contract(
            supervised_launch_execution=launch,
            pre_start_health_checks_execution=health,
            subprocess_start_authorization=valid_subprocess_start_authorization(),
        )

        validated = validate_subprocess_start_packet(payload)

        self.assertIs(validated, payload)
        self.assertFalse(validated["subprocess_module_imported"])

    def test_rejects_subprocess_start_that_allows_process_launch(self) -> None:
        launch = ready_launch_execution()
        health = execute_pre_start_health_checks(
            supervised_launch_execution=launch,
            health_check_results=passed_health_checks(list(launch["required_pre_start_checks"])),
            health_check_authorization=valid_health_check_authorization(),
        )
        payload = dict(inspect_subprocess_start_contract(
            supervised_launch_execution=launch,
            pre_start_health_checks_execution=health,
            subprocess_start_authorization=valid_subprocess_start_authorization(),
        ))
        payload["process_launch_allowed"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_allowed"):
            validate_subprocess_start_packet(payload)

    def test_accepts_fail_closed_reviewed_subprocess_start_execution_packet(self) -> None:
        payload = inspect_reviewed_subprocess_start_execution(
            subprocess_start_contract=ready_subprocess_start_contract(),
            reviewed_start_authorization=valid_reviewed_subprocess_start_authorization(),
        )

        validated = validate_reviewed_subprocess_start_execution_packet(payload)

        self.assertIs(validated, payload)
        self.assertFalse(validated["real_subprocess_start_implemented"])
        self.assertFalse(validated["subprocess_module_imported"])

    def test_rejects_reviewed_subprocess_start_execution_with_nested_tool_call(self) -> None:
        payload = dict(inspect_reviewed_subprocess_start_execution(
            subprocess_start_contract=ready_subprocess_start_contract(),
            reviewed_start_authorization=valid_reviewed_subprocess_start_authorization(),
        ))
        payload["debug"] = {"tool_call": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_reviewed_subprocess_start_execution_packet(payload)

    def test_rejects_reviewed_subprocess_start_execution_that_imports_subprocess(self) -> None:
        payload = dict(inspect_reviewed_subprocess_start_execution(
            subprocess_start_contract=ready_subprocess_start_contract(),
            reviewed_start_authorization=valid_reviewed_subprocess_start_authorization(),
        ))
        payload["subprocess_module_imported"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "subprocess_module_imported"):
            validate_reviewed_subprocess_start_execution_packet(payload)

    def test_accepts_fail_closed_real_start_adapter_disabled_packet(self) -> None:
        payload = inspect_real_start_adapter_disabled_by_default(
            reviewed_subprocess_start_execution=ready_reviewed_subprocess_start_execution(),
            real_start_adapter_authorization=valid_real_start_adapter_authorization(),
        )

        validated = validate_real_start_adapter_disabled_packet(payload)

        self.assertIs(validated, payload)
        self.assertFalse(validated["real_start_adapter_enabled"])
        self.assertFalse(validated["process_launch_attempted"])

    def test_rejects_real_start_adapter_disabled_packet_that_starts(self) -> None:
        payload = dict(inspect_real_start_adapter_disabled_by_default(
            reviewed_subprocess_start_execution=ready_reviewed_subprocess_start_execution(),
            real_start_adapter_authorization=valid_real_start_adapter_authorization(),
        ))
        payload["daemon_started"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "daemon_started"):
            validate_real_start_adapter_disabled_packet(payload)

    def test_rejects_real_start_adapter_disabled_packet_with_nested_raw_audio(self) -> None:
        payload = dict(inspect_real_start_adapter_disabled_by_default(
            reviewed_subprocess_start_execution=ready_reviewed_subprocess_start_execution(),
            real_start_adapter_authorization=valid_real_start_adapter_authorization(),
        ))
        payload["debug"] = {"raw_audio_bytes": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_real_start_adapter_disabled_packet(payload)

    def test_accepts_fail_closed_real_start_enablement_gate_packet(self) -> None:
        payload = inspect_real_start_adapter_enablement_gate(
            real_start_adapter_disabled=ready_real_start_adapter_disabled(),
            enablement_gate_authorization=valid_real_start_enablement_gate_authorization(),
        )

        validated = validate_real_start_adapter_enablement_gate_packet(payload)

        self.assertIs(validated, payload)
        self.assertFalse(validated["real_start_adapter_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_attempted"])

    def test_rejects_real_start_enablement_gate_packet_that_starts(self) -> None:
        payload = dict(inspect_real_start_adapter_enablement_gate(
            real_start_adapter_disabled=ready_real_start_adapter_disabled(),
            enablement_gate_authorization=valid_real_start_enablement_gate_authorization(),
        ))
        payload["daemon_started"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "daemon_started"):
            validate_real_start_adapter_enablement_gate_packet(payload)

    def test_rejects_real_start_enablement_gate_packet_with_nested_tool_call(self) -> None:
        payload = dict(inspect_real_start_adapter_enablement_gate(
            real_start_adapter_disabled=ready_real_start_adapter_disabled(),
            enablement_gate_authorization=valid_real_start_enablement_gate_authorization(),
        ))
        payload["debug"] = {"tool_call": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_real_start_adapter_enablement_gate_packet(payload)

    def test_accepts_fail_closed_runtime_policy_enablement_review_packet(self) -> None:
        payload = inspect_runtime_policy_enablement_review(
            real_start_enablement_gate=ready_real_start_enablement_gate(),
            policy_review_authorization=valid_runtime_policy_enablement_review_authorization(),
        )

        validated = validate_runtime_policy_enablement_review_packet(payload)

        self.assertIs(validated, payload)
        self.assertFalse(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["process_launch_attempted"])

    def test_rejects_runtime_policy_enablement_review_packet_that_starts(self) -> None:
        payload = dict(inspect_runtime_policy_enablement_review(
            real_start_enablement_gate=ready_real_start_enablement_gate(),
            policy_review_authorization=valid_runtime_policy_enablement_review_authorization(),
        ))
        payload["process_launch_attempted"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_attempted"):
            validate_runtime_policy_enablement_review_packet(payload)

    def test_rejects_runtime_policy_enablement_review_packet_with_nested_raw_audio(self) -> None:
        payload = dict(inspect_runtime_policy_enablement_review(
            real_start_enablement_gate=ready_real_start_enablement_gate(),
            policy_review_authorization=valid_runtime_policy_enablement_review_authorization(),
        ))
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_runtime_policy_enablement_review_packet(payload)

    def test_accepts_fail_closed_real_start_adapter_review_contract_packet(self) -> None:
        payload = inspect_real_start_adapter_review_contract(
            runtime_policy_enablement_review=ready_runtime_policy_enablement_review(),
            real_start_review_authorization=valid_real_start_adapter_review_authorization(),
        )

        validated = validate_real_start_adapter_review_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertFalse(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["process_launch_attempted"])

    def test_rejects_real_start_adapter_review_contract_packet_that_imports_subprocess(self) -> None:
        payload = dict(inspect_real_start_adapter_review_contract(
            runtime_policy_enablement_review=ready_runtime_policy_enablement_review(),
            real_start_review_authorization=valid_real_start_adapter_review_authorization(),
        ))
        payload["subprocess_module_imported"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "subprocess_module_imported"):
            validate_real_start_adapter_review_contract_packet(payload)

    def test_rejects_real_start_adapter_review_contract_packet_with_nested_tool_call(self) -> None:
        payload = dict(inspect_real_start_adapter_review_contract(
            runtime_policy_enablement_review=ready_runtime_policy_enablement_review(),
            real_start_review_authorization=valid_real_start_adapter_review_authorization(),
        ))
        payload["debug"] = {"tool_call": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_real_start_adapter_review_contract_packet(payload)

    def test_accepts_fail_closed_reviewed_real_start_execution_contract_packet(self) -> None:
        payload = inspect_reviewed_real_start_execution_contract(
            real_start_adapter_review_contract=ready_real_start_adapter_review_contract(),
            reviewed_real_start_authorization=valid_reviewed_real_start_execution_authorization(),
        )

        validated = validate_reviewed_real_start_execution_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_attempted"])

    def test_rejects_reviewed_real_start_execution_contract_packet_that_starts(self) -> None:
        payload = dict(inspect_reviewed_real_start_execution_contract(
            real_start_adapter_review_contract=ready_real_start_adapter_review_contract(),
            reviewed_real_start_authorization=valid_reviewed_real_start_execution_authorization(),
        ))
        payload["daemon_started"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "daemon_started"):
            validate_reviewed_real_start_execution_contract_packet(payload)

    def test_rejects_reviewed_real_start_execution_contract_packet_with_nested_raw_audio(self) -> None:
        payload = dict(inspect_reviewed_real_start_execution_contract(
            real_start_adapter_review_contract=ready_real_start_adapter_review_contract(),
            reviewed_real_start_authorization=valid_reviewed_real_start_execution_authorization(),
        ))
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_reviewed_real_start_execution_contract_packet(payload)

    def test_accepts_fail_closed_final_start_executor_disabled_packet(self) -> None:
        payload = inspect_final_start_executor_disabled_by_default(
            reviewed_real_start_execution_contract=ready_reviewed_real_start_execution_contract(),
            final_start_executor_authorization=valid_final_start_executor_authorization(),
        )

        validated = validate_final_start_executor_disabled_packet(payload)

        self.assertIs(validated, payload)
        self.assertFalse(validated["final_start_executor_enabled"])
        self.assertFalse(validated["process_launch_attempted"])

    def test_rejects_final_start_executor_disabled_packet_that_starts(self) -> None:
        payload = dict(ready_final_start_executor_disabled())
        payload["daemon_started"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "daemon_started"):
            validate_final_start_executor_disabled_packet(payload)

    def test_rejects_final_start_executor_disabled_packet_with_nested_tool_call(self) -> None:
        payload = dict(ready_final_start_executor_disabled())
        payload["debug"] = {"tool_call": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_final_start_executor_disabled_packet(payload)

    def test_accepts_fail_closed_final_start_executor_enablement_gate_packet(self) -> None:
        payload = inspect_final_start_executor_enablement_gate(
            final_start_executor_disabled=ready_final_start_executor_disabled(),
            final_start_executor_enablement_authorization=valid_final_start_executor_enablement_authorization(),
        )

        validated = validate_final_start_executor_enablement_gate_packet(payload)

        self.assertIs(validated, payload)
        self.assertFalse(validated["final_start_executor_enabled"])
        self.assertFalse(validated["process_launch_attempted"])

    def test_rejects_final_start_executor_enablement_gate_packet_that_starts(self) -> None:
        payload = dict(ready_final_start_executor_enablement_gate())
        payload["process_launch_attempted"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_attempted"):
            validate_final_start_executor_enablement_gate_packet(payload)

    def test_rejects_final_start_executor_enablement_gate_packet_with_nested_raw_audio(self) -> None:
        payload = dict(ready_final_start_executor_enablement_gate())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_final_start_executor_enablement_gate_packet(payload)

    def test_accepts_fail_closed_supervised_start_execution_review_packet(self) -> None:
        payload = inspect_supervised_start_execution_review(
            final_start_executor_enablement_gate=ready_final_start_executor_enablement_gate(),
            supervised_start_review_authorization=valid_supervised_start_execution_review_authorization(),
        )

        validated = validate_supervised_start_execution_review_packet(payload)

        self.assertIs(validated, payload)
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_attempted"])

    def test_rejects_supervised_start_execution_review_packet_that_starts(self) -> None:
        payload = dict(ready_supervised_start_execution_review())
        payload["daemon_started"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "daemon_started"):
            validate_supervised_start_execution_review_packet(payload)

    def test_rejects_supervised_start_execution_review_packet_with_nested_tool_call(self) -> None:
        payload = dict(ready_supervised_start_execution_review())
        payload["debug"] = {"tool_call": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_supervised_start_execution_review_packet(payload)

    def test_accepts_fail_closed_real_start_execution_contract_packet(self) -> None:
        payload = inspect_real_start_execution_contract(
            supervised_start_execution_review=ready_supervised_start_execution_review(),
            real_start_execution_authorization=valid_real_start_execution_authorization(),
        )

        validated = validate_real_start_execution_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertFalse(validated["guarded_start_executor_implemented"])
        self.assertFalse(validated["process_launch_attempted"])

    def test_rejects_real_start_execution_contract_packet_that_starts(self) -> None:
        payload = dict(ready_real_start_execution_contract())
        payload["daemon_started"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "daemon_started"):
            validate_real_start_execution_contract_packet(payload)

    def test_rejects_real_start_execution_contract_packet_with_nested_tool_call(self) -> None:
        payload = dict(ready_real_start_execution_contract())
        payload["debug"] = {"tool_call": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_real_start_execution_contract_packet(payload)

    def test_accepts_fail_closed_guarded_start_executor_disabled_packet(self) -> None:
        payload = inspect_guarded_start_executor_disabled_by_default(
            real_start_execution_contract=ready_real_start_execution_contract(),
            guarded_start_executor_authorization=valid_guarded_start_executor_authorization(),
        )

        validated = validate_guarded_start_executor_disabled_packet(payload)

        self.assertIs(validated, payload)
        self.assertFalse(validated["guarded_start_executor_enabled"])
        self.assertFalse(validated["process_launch_attempted"])

    def test_rejects_guarded_start_executor_disabled_packet_that_imports_subprocess(self) -> None:
        payload = dict(ready_guarded_start_executor_disabled())
        payload["subprocess_module_imported"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "subprocess_module_imported"):
            validate_guarded_start_executor_disabled_packet(payload)

    def test_rejects_guarded_start_executor_disabled_packet_with_nested_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_executor_disabled())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_executor_disabled_packet(payload)

    def test_accepts_fail_closed_guarded_start_executor_enablement_gate_packet(self) -> None:
        payload = inspect_guarded_start_executor_enablement_gate(
            guarded_start_executor_disabled=ready_guarded_start_executor_disabled(),
            guarded_start_executor_enablement_authorization=valid_guarded_start_executor_enablement_authorization(),
        )

        validated = validate_guarded_start_executor_enablement_gate_packet(payload)

        self.assertIs(validated, payload)
        self.assertFalse(validated["guarded_start_executor_enabled"])
        self.assertFalse(validated["process_launch_attempted"])

    def test_rejects_guarded_start_executor_enablement_gate_packet_that_starts(self) -> None:
        payload = dict(ready_guarded_start_executor_enablement_gate())
        payload["process_launch_attempted"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_attempted"):
            validate_guarded_start_executor_enablement_gate_packet(payload)

    def test_rejects_guarded_start_executor_enablement_gate_packet_with_nested_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_executor_enablement_gate())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_executor_enablement_gate_packet(payload)

    def test_accepts_fail_closed_reviewed_guarded_start_execution_contract_packet(self) -> None:
        payload = inspect_reviewed_guarded_start_execution_contract(
            guarded_start_executor_enablement_gate=ready_guarded_start_executor_enablement_gate(),
            reviewed_guarded_start_authorization=valid_reviewed_guarded_start_execution_authorization(),
        )

        validated = validate_reviewed_guarded_start_execution_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertFalse(validated["guarded_start_executor_enabled"])
        self.assertFalse(validated["process_launch_attempted"])

    def test_rejects_reviewed_guarded_start_execution_contract_packet_that_starts(self) -> None:
        payload = dict(ready_reviewed_guarded_start_execution_contract())
        payload["daemon_started"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "daemon_started"):
            validate_reviewed_guarded_start_execution_contract_packet(payload)

    def test_rejects_reviewed_guarded_start_execution_contract_packet_with_nested_tool_call(self) -> None:
        payload = dict(ready_reviewed_guarded_start_execution_contract())
        payload["debug"] = {"tool_call": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_reviewed_guarded_start_execution_contract_packet(payload)

    def test_accepts_fail_closed_guarded_start_dry_run_contract_packet(self) -> None:
        payload = inspect_guarded_start_dry_run_contract(
            reviewed_guarded_start_execution_contract=ready_reviewed_guarded_start_execution_contract(),
            guarded_start_dry_run_plan=valid_guarded_start_dry_run_plan(),
        )

        validated = validate_guarded_start_dry_run_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["dry_run_only"])
        self.assertFalse(validated["process_launch_attempted"])

    def test_rejects_guarded_start_dry_run_contract_packet_that_starts(self) -> None:
        payload = dict(ready_guarded_start_dry_run_contract())
        payload["process_launch_attempted"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_attempted"):
            validate_guarded_start_dry_run_contract_packet(payload)

    def test_rejects_guarded_start_dry_run_contract_packet_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_dry_run_contract())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_dry_run_contract_packet(payload)

    def test_accepts_fail_closed_guarded_start_simulation_contract_packet(self) -> None:
        payload = inspect_guarded_start_simulation_contract(
            guarded_start_dry_run_contract=ready_guarded_start_dry_run_contract(),
            guarded_start_simulation_plan=valid_guarded_start_simulation_plan(),
        )

        validated = validate_guarded_start_simulation_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["simulation_only"])
        self.assertFalse(validated["process_launch_attempted"])

    def test_rejects_guarded_start_simulation_contract_packet_that_starts(self) -> None:
        payload = dict(ready_guarded_start_simulation_contract())
        payload["daemon_started"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "daemon_started"):
            validate_guarded_start_simulation_contract_packet(payload)

    def test_rejects_guarded_start_simulation_contract_packet_with_provider_call(self) -> None:
        payload = dict(ready_guarded_start_simulation_contract())
        payload["debug"] = {"direct_provider_call": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_simulation_contract_packet(payload)

    def test_accepts_fail_closed_guarded_start_runtime_handoff_contract_packet(self) -> None:
        payload = inspect_guarded_start_runtime_handoff_contract(
            guarded_start_simulation_contract=ready_guarded_start_simulation_contract(),
            guarded_start_runtime_handoff_plan=valid_guarded_start_runtime_handoff_plan(),
        )

        validated = validate_guarded_start_runtime_handoff_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["runtime_handoff_contract_only"])
        self.assertFalse(validated["process_launch_attempted"])

    def test_rejects_guarded_start_runtime_handoff_contract_packet_that_starts(self) -> None:
        payload = dict(ready_guarded_start_runtime_handoff_contract())
        payload["daemon_started"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "daemon_started"):
            validate_guarded_start_runtime_handoff_contract_packet(payload)

    def test_rejects_guarded_start_runtime_handoff_contract_packet_with_raw_secret(self) -> None:
        payload = dict(ready_guarded_start_runtime_handoff_contract())
        payload["debug"] = {"secret": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_runtime_handoff_contract_packet(payload)

    def test_accepts_fail_closed_guarded_start_policy_patch_review_contract_packet(self) -> None:
        payload = inspect_guarded_start_policy_patch_review_contract(
            guarded_start_runtime_handoff_contract=ready_guarded_start_runtime_handoff_contract(),
            policy_patch_review_authorization=valid_guarded_start_policy_patch_review_authorization(),
        )

        validated = validate_guarded_start_policy_patch_review_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["policy_patch_review_only"])
        self.assertFalse(validated["runtime_policy_start_enabled"])

    def test_rejects_guarded_start_policy_patch_review_contract_packet_that_starts(self) -> None:
        payload = dict(ready_guarded_start_policy_patch_review_contract())
        payload["process_launch_attempted"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_attempted"):
            validate_guarded_start_policy_patch_review_contract_packet(payload)

    def test_rejects_guarded_start_policy_patch_review_contract_packet_with_provider_call(self) -> None:
        payload = dict(ready_guarded_start_policy_patch_review_contract())
        payload["debug"] = {"direct_provider_call": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_policy_patch_review_contract_packet(payload)

    def test_accepts_fail_closed_guarded_start_human_review_contract_packet(self) -> None:
        payload = inspect_guarded_start_human_review_contract(
            guarded_start_policy_patch_review_contract=ready_guarded_start_policy_patch_review_contract(),
            human_review_authorization=valid_guarded_start_human_review_authorization(),
        )

        validated = validate_guarded_start_human_review_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["human_review_contract_only"])
        self.assertFalse(validated["runtime_policy_start_enabled"])

    def test_rejects_guarded_start_human_review_contract_packet_that_starts(self) -> None:
        payload = dict(ready_guarded_start_human_review_contract())
        payload["daemon_started"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "daemon_started"):
            validate_guarded_start_human_review_contract_packet(payload)

    def test_rejects_guarded_start_human_review_contract_packet_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_human_review_contract())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_human_review_contract_packet(payload)

    def test_accepts_fail_closed_guarded_start_final_enablement_gate_contract_packet(self) -> None:
        payload = inspect_guarded_start_final_enablement_gate_contract(
            guarded_start_human_review_contract=ready_guarded_start_human_review_contract(),
            final_enablement_authorization=valid_guarded_start_final_enablement_authorization(),
        )

        validated = validate_guarded_start_final_enablement_gate_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["final_enablement_gate_only"])
        self.assertFalse(validated["runtime_policy_start_enabled"])

    def test_rejects_guarded_start_final_enablement_gate_contract_packet_that_starts(self) -> None:
        payload = dict(ready_guarded_start_final_enablement_gate_contract())
        payload["process_launch_attempted"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_attempted"):
            validate_guarded_start_final_enablement_gate_contract_packet(payload)

    def test_rejects_guarded_start_final_enablement_gate_contract_packet_with_tool_call(self) -> None:
        payload = dict(ready_guarded_start_final_enablement_gate_contract())
        payload["debug"] = {"tool_call": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_final_enablement_gate_contract_packet(payload)

    def test_accepts_guarded_start_policy_enablement_contract_without_starting(self) -> None:
        payload = inspect_guarded_start_policy_enablement_contract(
            guarded_start_final_enablement_gate=ready_guarded_start_final_enablement_gate_contract(),
            policy_enablement_authorization=valid_guarded_start_policy_enablement_authorization(),
        )

        validated = validate_guarded_start_policy_enablement_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["policy_enablement_contract_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_policy_enablement_contract_that_starts(self) -> None:
        payload = dict(ready_guarded_start_policy_enablement_contract())
        payload["start_execution_allowed"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "start_execution_allowed"):
            validate_guarded_start_policy_enablement_contract_packet(payload)

    def test_rejects_guarded_start_policy_enablement_contract_with_provider_call(self) -> None:
        payload = dict(ready_guarded_start_policy_enablement_contract())
        payload["debug"] = {"direct_provider_call": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_policy_enablement_contract_packet(payload)

    def test_accepts_guarded_start_activation_contract_without_starting(self) -> None:
        payload = inspect_guarded_start_activation_contract(
            guarded_start_policy_enablement_contract=ready_guarded_start_policy_enablement_contract(),
            activation_authorization=valid_guarded_start_activation_authorization(),
        )

        validated = validate_guarded_start_activation_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["activation_contract_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_activation_contract_that_starts(self) -> None:
        payload = dict(ready_guarded_start_activation_contract())
        payload["process_launch_attempted"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_attempted"):
            validate_guarded_start_activation_contract_packet(payload)

    def test_rejects_guarded_start_activation_contract_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_activation_contract())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_activation_contract_packet(payload)

    def test_accepts_guarded_start_execution_attempt_contract_without_starting(self) -> None:
        payload = inspect_guarded_start_execution_attempt_contract(
            guarded_start_activation_contract=ready_guarded_start_activation_contract(),
            execution_attempt_authorization=valid_guarded_start_execution_attempt_authorization(),
        )

        validated = validate_guarded_start_execution_attempt_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["execution_attempt_contract_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_execution_attempt_contract_that_starts(self) -> None:
        payload = dict(ready_guarded_start_execution_attempt_contract())
        payload["daemon_started"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "daemon_started"):
            validate_guarded_start_execution_attempt_contract_packet(payload)

    def test_rejects_guarded_start_execution_attempt_contract_with_tool_call(self) -> None:
        payload = dict(ready_guarded_start_execution_attempt_contract())
        payload["debug"] = {"tool_call": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_execution_attempt_contract_packet(payload)

    def test_accepts_guarded_start_execution_rehearsal_contract_without_starting(self) -> None:
        payload = inspect_guarded_start_execution_rehearsal_contract(
            guarded_start_execution_attempt_contract=ready_guarded_start_execution_attempt_contract(),
            execution_rehearsal_authorization=valid_guarded_start_execution_rehearsal_authorization(),
        )

        validated = validate_guarded_start_execution_rehearsal_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["execution_rehearsal_contract_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_execution_rehearsal_contract_that_starts(self) -> None:
        payload = dict(ready_guarded_start_execution_rehearsal_contract())
        payload["process_launch_attempted"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_attempted"):
            validate_guarded_start_execution_rehearsal_contract_packet(payload)

    def test_rejects_guarded_start_execution_rehearsal_contract_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_execution_rehearsal_contract())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_execution_rehearsal_contract_packet(payload)

    def test_accepts_guarded_start_observability_contract_without_starting(self) -> None:
        payload = inspect_guarded_start_observability_contract(
            guarded_start_execution_rehearsal_contract=ready_guarded_start_execution_rehearsal_contract(),
            observability_authorization=valid_guarded_start_observability_authorization(),
        )

        validated = validate_guarded_start_observability_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["observability_contract_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_observability_contract_that_starts(self) -> None:
        payload = dict(ready_guarded_start_observability_contract())
        payload["process_launch_allowed"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_allowed"):
            validate_guarded_start_observability_contract_packet(payload)

    def test_rejects_guarded_start_observability_contract_with_provider_call(self) -> None:
        payload = dict(ready_guarded_start_observability_contract())
        payload["debug"] = {"direct_provider_call": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_observability_contract_packet(payload)

    def test_accepts_guarded_start_release_candidate_contract_without_starting(self) -> None:
        payload = inspect_guarded_start_release_candidate_contract(
            guarded_start_observability_contract=ready_guarded_start_observability_contract(),
            release_candidate_authorization=valid_guarded_start_release_candidate_authorization(),
        )

        validated = validate_guarded_start_release_candidate_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["release_candidate_contract_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_release_candidate_contract_that_starts(self) -> None:
        payload = dict(ready_guarded_start_release_candidate_contract())
        payload["daemon_started"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "daemon_started"):
            validate_guarded_start_release_candidate_contract_packet(payload)

    def test_rejects_guarded_start_release_candidate_contract_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_release_candidate_contract())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_release_candidate_contract_packet(payload)

    def test_accepts_guarded_start_operator_acceptance_contract_without_starting(self) -> None:
        payload = inspect_guarded_start_operator_acceptance_contract(
            guarded_start_release_candidate_contract=ready_guarded_start_release_candidate_contract(),
            operator_acceptance_authorization=valid_guarded_start_operator_acceptance_authorization(),
        )

        validated = validate_guarded_start_operator_acceptance_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["operator_acceptance_contract_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_operator_acceptance_contract_that_starts(self) -> None:
        payload = dict(ready_guarded_start_operator_acceptance_contract())
        payload["daemon_started"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "daemon_started"):
            validate_guarded_start_operator_acceptance_contract_packet(payload)

    def test_rejects_guarded_start_operator_acceptance_contract_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_operator_acceptance_contract())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_operator_acceptance_contract_packet(payload)

    def test_accepts_guarded_start_final_start_receipt_contract_without_starting(self) -> None:
        payload = inspect_guarded_start_final_start_receipt_contract(
            guarded_start_operator_acceptance_contract=ready_guarded_start_operator_acceptance_contract(),
            final_start_receipt_authorization=valid_guarded_start_final_start_receipt_authorization(),
        )

        validated = validate_guarded_start_final_start_receipt_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["final_start_receipt_contract_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_final_start_receipt_contract_that_starts(self) -> None:
        payload = dict(ready_guarded_start_final_start_receipt_contract())
        payload["daemon_started"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "daemon_started"):
            validate_guarded_start_final_start_receipt_contract_packet(payload)

    def test_rejects_guarded_start_final_start_receipt_contract_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_final_start_receipt_contract())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_final_start_receipt_contract_packet(payload)

    def test_accepts_guarded_start_launch_window_contract_without_starting(self) -> None:
        payload = inspect_guarded_start_launch_window_contract(
            guarded_start_final_start_receipt_contract=ready_guarded_start_final_start_receipt_contract(),
            launch_window_authorization=valid_guarded_start_launch_window_authorization(),
        )

        validated = validate_guarded_start_launch_window_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["launch_window_contract_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_launch_window_contract_that_starts(self) -> None:
        payload = dict(ready_guarded_start_launch_window_contract())
        payload["daemon_started"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "daemon_started"):
            validate_guarded_start_launch_window_contract_packet(payload)

    def test_rejects_guarded_start_launch_window_contract_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_launch_window_contract())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_launch_window_contract_packet(payload)

    def test_accepts_guarded_start_pre_launch_guard_contract_without_starting(self) -> None:
        payload = inspect_guarded_start_pre_launch_guard_contract(
            guarded_start_launch_window_contract=ready_guarded_start_launch_window_contract(),
            pre_launch_guard_authorization=valid_guarded_start_pre_launch_guard_authorization(),
        )

        validated = validate_guarded_start_pre_launch_guard_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["pre_launch_guard_contract_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_pre_launch_guard_contract_that_starts(self) -> None:
        payload = dict(ready_guarded_start_pre_launch_guard_contract())
        payload["daemon_started"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "daemon_started"):
            validate_guarded_start_pre_launch_guard_contract_packet(payload)

    def test_rejects_guarded_start_pre_launch_guard_contract_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_pre_launch_guard_contract())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_pre_launch_guard_contract_packet(payload)

    def test_accepts_guarded_start_executor_runtime_contract_without_starting(self) -> None:
        payload = inspect_guarded_start_executor_runtime_contract(
            guarded_start_pre_launch_guard_contract=ready_guarded_start_pre_launch_guard_contract(),
            executor_runtime_authorization=valid_guarded_start_executor_runtime_authorization(),
        )

        validated = validate_guarded_start_executor_runtime_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["executor_runtime_contract_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_executor_runtime_contract_that_starts(self) -> None:
        payload = dict(ready_guarded_start_executor_runtime_contract())
        payload["daemon_started"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "daemon_started"):
            validate_guarded_start_executor_runtime_contract_packet(payload)

    def test_rejects_guarded_start_executor_runtime_contract_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_executor_runtime_contract())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_executor_runtime_contract_packet(payload)

    def test_accepts_guarded_start_process_spawn_contract_without_starting(self) -> None:
        payload = inspect_guarded_start_process_spawn_contract(
            guarded_start_executor_runtime_contract=ready_guarded_start_executor_runtime_contract(),
            process_spawn_authorization=valid_guarded_start_process_spawn_authorization(),
        )

        validated = validate_guarded_start_process_spawn_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["process_spawn_contract_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_process_spawn_contract_that_imports_subprocess(self) -> None:
        payload = dict(ready_guarded_start_process_spawn_contract())
        payload["subprocess_module_imported"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "subprocess_module_imported"):
            validate_guarded_start_process_spawn_contract_packet(payload)

    def test_rejects_guarded_start_process_spawn_contract_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_process_spawn_contract())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_process_spawn_contract_packet(payload)

    def test_accepts_guarded_start_spawn_review_contract_without_starting(self) -> None:
        payload = inspect_guarded_start_spawn_review_contract(
            guarded_start_process_spawn_contract=ready_guarded_start_process_spawn_contract(),
            spawn_review_authorization=valid_guarded_start_spawn_review_authorization(),
        )

        validated = validate_guarded_start_spawn_review_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["spawn_review_contract_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_spawn_review_contract_that_starts(self) -> None:
        payload = dict(ready_guarded_start_spawn_review_contract())
        payload["process_launch_attempted"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_attempted"):
            validate_guarded_start_spawn_review_contract_packet(payload)

    def test_rejects_guarded_start_spawn_review_contract_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_spawn_review_contract())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_spawn_review_contract_packet(payload)

    def test_accepts_guarded_start_subprocess_import_contract_without_importing(self) -> None:
        payload = inspect_guarded_start_subprocess_import_contract(
            guarded_start_spawn_review_contract=ready_guarded_start_spawn_review_contract(),
            subprocess_import_authorization=valid_guarded_start_subprocess_import_authorization(),
        )

        validated = validate_guarded_start_subprocess_import_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["subprocess_import_contract_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["process_launch_attempted"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_subprocess_import_contract_that_imports(self) -> None:
        payload = dict(ready_guarded_start_subprocess_import_contract())
        payload["subprocess_module_imported"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "subprocess_module_imported"):
            validate_guarded_start_subprocess_import_contract_packet(payload)

    def test_rejects_guarded_start_subprocess_import_contract_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_subprocess_import_contract())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_subprocess_import_contract_packet(payload)

    def test_accepts_guarded_start_launch_invocation_contract_without_launching(self) -> None:
        payload = inspect_guarded_start_launch_invocation_contract(
            guarded_start_subprocess_import_contract=ready_guarded_start_subprocess_import_contract(),
            launch_invocation_authorization=valid_guarded_start_launch_invocation_authorization(),
        )

        validated = validate_guarded_start_launch_invocation_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["launch_invocation_contract_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["process_launch_attempted"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_launch_invocation_contract_that_launches(self) -> None:
        payload = dict(ready_guarded_start_launch_invocation_contract())
        payload["process_launch_attempted"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_attempted"):
            validate_guarded_start_launch_invocation_contract_packet(payload)

    def test_rejects_guarded_start_launch_invocation_contract_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_launch_invocation_contract())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_launch_invocation_contract_packet(payload)

    def test_accepts_guarded_start_final_process_start_contract_without_starting(self) -> None:
        payload = inspect_guarded_start_final_process_start_contract(
            guarded_start_launch_invocation_contract=ready_guarded_start_launch_invocation_contract(),
            final_process_start_authorization=valid_guarded_start_final_process_start_authorization(),
        )

        validated = validate_guarded_start_final_process_start_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["final_process_start_contract_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["process_launch_attempted"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_final_process_start_contract_that_starts(self) -> None:
        payload = dict(ready_guarded_start_final_process_start_contract())
        payload["start_execution_allowed"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "start_execution_allowed"):
            validate_guarded_start_final_process_start_contract_packet(payload)

    def test_rejects_guarded_start_final_process_start_contract_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_final_process_start_contract())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_final_process_start_contract_packet(payload)

    def test_accepts_guarded_start_process_execution_review_without_starting(self) -> None:
        payload = inspect_guarded_start_process_execution_review(
            guarded_start_final_process_start_contract=ready_guarded_start_final_process_start_contract(),
            process_execution_review_authorization=valid_guarded_start_process_execution_review_authorization(),
        )

        validated = validate_guarded_start_process_execution_review_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["process_execution_review_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["process_launch_attempted"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_process_execution_review_that_starts(self) -> None:
        payload = dict(ready_guarded_start_process_execution_review())
        payload["process_launch_allowed"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_allowed"):
            validate_guarded_start_process_execution_review_packet(payload)

    def test_rejects_guarded_start_process_execution_review_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_process_execution_review())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_process_execution_review_packet(payload)

    def test_accepts_guarded_start_process_execution_packet_without_starting(self) -> None:
        payload = inspect_guarded_start_process_execution_packet(
            guarded_start_process_execution_review=ready_guarded_start_process_execution_review(),
            process_execution_packet_authorization=valid_guarded_start_process_execution_packet_authorization(),
        )

        validated = validate_guarded_start_process_execution_packet_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["process_execution_packet_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["process_launch_attempted"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_process_execution_packet_that_starts(self) -> None:
        payload = dict(ready_guarded_start_process_execution_packet())
        payload["process_launch_allowed"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_allowed"):
            validate_guarded_start_process_execution_packet_packet(payload)

    def test_rejects_guarded_start_process_execution_packet_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_process_execution_packet())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_process_execution_packet_packet(payload)

    def test_accepts_guarded_start_process_executor_stub_without_starting(self) -> None:
        payload = inspect_guarded_start_process_executor_stub(
            guarded_start_process_execution_packet=ready_guarded_start_process_execution_packet(),
            process_executor_stub_authorization=valid_guarded_start_process_executor_stub_authorization(),
        )

        validated = validate_guarded_start_process_executor_stub_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["process_executor_stub_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["process_launch_attempted"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_process_executor_stub_that_starts(self) -> None:
        payload = dict(ready_guarded_start_process_executor_stub())
        payload["process_launch_allowed"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_allowed"):
            validate_guarded_start_process_executor_stub_packet(payload)

    def test_rejects_guarded_start_process_executor_stub_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_process_executor_stub())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_process_executor_stub_packet(payload)

    def test_accepts_guarded_start_process_executor_review_without_starting(self) -> None:
        payload = inspect_guarded_start_process_executor_review(
            guarded_start_process_executor_stub=ready_guarded_start_process_executor_stub(),
            process_executor_review_authorization=valid_guarded_start_process_executor_review_authorization(),
        )

        validated = validate_guarded_start_process_executor_review_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["process_executor_review_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["process_launch_attempted"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_process_executor_review_that_starts(self) -> None:
        payload = dict(ready_guarded_start_process_executor_review())
        payload["process_launch_allowed"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_allowed"):
            validate_guarded_start_process_executor_review_packet(payload)

    def test_rejects_guarded_start_process_executor_review_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_process_executor_review())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_process_executor_review_packet(payload)

    def test_accepts_guarded_start_process_executor_contract_without_starting(self) -> None:
        payload = inspect_guarded_start_process_executor_contract(
            guarded_start_process_executor_review=ready_guarded_start_process_executor_review(),
            process_executor_contract_authorization=valid_guarded_start_process_executor_contract_authorization(),
        )

        validated = validate_guarded_start_process_executor_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["process_executor_contract_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["process_launch_attempted"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_process_executor_contract_that_starts(self) -> None:
        payload = dict(ready_guarded_start_process_executor_contract())
        payload["process_launch_allowed"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_allowed"):
            validate_guarded_start_process_executor_contract_packet(payload)

    def test_rejects_guarded_start_process_executor_contract_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_process_executor_contract())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_process_executor_contract_packet(payload)

    def test_accepts_guarded_start_process_runtime_adapter_without_starting(self) -> None:
        payload = inspect_guarded_start_process_runtime_adapter(
            guarded_start_process_executor_contract=ready_guarded_start_process_executor_contract(),
            process_runtime_adapter_authorization=valid_guarded_start_process_runtime_adapter_authorization(),
        )

        validated = validate_guarded_start_process_runtime_adapter_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["process_runtime_adapter_only"])
        self.assertTrue(validated["runtime_adapter_contract_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["process_launch_attempted"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_process_runtime_adapter_that_starts(self) -> None:
        payload = dict(ready_guarded_start_process_runtime_adapter())
        payload["process_launch_attempted"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_attempted"):
            validate_guarded_start_process_runtime_adapter_packet(payload)

    def test_rejects_guarded_start_process_runtime_adapter_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_process_runtime_adapter())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_process_runtime_adapter_packet(payload)

    def test_accepts_guarded_start_process_adapter_review_without_starting(self) -> None:
        payload = inspect_guarded_start_process_adapter_review(
            guarded_start_process_runtime_adapter=ready_guarded_start_process_runtime_adapter(),
            process_adapter_review_authorization=valid_guarded_start_process_adapter_review_authorization(),
        )

        validated = validate_guarded_start_process_adapter_review_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["process_adapter_review_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["process_launch_attempted"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_process_adapter_review_that_starts(self) -> None:
        payload = dict(ready_guarded_start_process_adapter_review())
        payload["daemon_started"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "daemon_started"):
            validate_guarded_start_process_adapter_review_packet(payload)

    def test_rejects_guarded_start_process_adapter_review_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_process_adapter_review())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_process_adapter_review_packet(payload)

    def test_accepts_guarded_start_process_adapter_contract_without_starting(self) -> None:
        payload = inspect_guarded_start_process_adapter_contract(
            guarded_start_process_adapter_review=ready_guarded_start_process_adapter_review(),
            process_adapter_contract_authorization=valid_guarded_start_process_adapter_contract_authorization(),
        )

        validated = validate_guarded_start_process_adapter_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["process_adapter_contract_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["process_launch_attempted"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_process_adapter_contract_that_starts(self) -> None:
        payload = dict(ready_guarded_start_process_adapter_contract())
        payload["process_launch_attempted"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_attempted"):
            validate_guarded_start_process_adapter_contract_packet(payload)

    def test_rejects_guarded_start_process_adapter_contract_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_process_adapter_contract())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_process_adapter_contract_packet(payload)

    def test_accepts_guarded_start_process_runner_contract_without_starting(self) -> None:
        payload = inspect_guarded_start_process_runner_contract(
            guarded_start_process_adapter_contract=ready_guarded_start_process_adapter_contract(),
            process_runner_contract_authorization=valid_guarded_start_process_runner_contract_authorization(),
        )

        validated = validate_guarded_start_process_runner_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["process_runner_contract_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["process_launch_attempted"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_process_runner_contract_that_starts(self) -> None:
        payload = dict(ready_guarded_start_process_runner_contract())
        payload["daemon_started"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "daemon_started"):
            validate_guarded_start_process_runner_contract_packet(payload)

    def test_rejects_guarded_start_process_runner_contract_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_process_runner_contract())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_process_runner_contract_packet(payload)

    def test_accepts_guarded_start_process_runner_review_without_starting(self) -> None:
        payload = inspect_guarded_start_process_runner_review(
            guarded_start_process_runner_contract=ready_guarded_start_process_runner_contract(),
            process_runner_review_authorization=valid_guarded_start_process_runner_review_authorization(),
        )

        validated = validate_guarded_start_process_runner_review_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["process_runner_review_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["process_launch_attempted"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_process_runner_review_that_starts(self) -> None:
        payload = dict(ready_guarded_start_process_runner_review())
        payload["process_launch_attempted"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_attempted"):
            validate_guarded_start_process_runner_review_packet(payload)

    def test_rejects_guarded_start_process_runner_review_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_process_runner_review())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_process_runner_review_packet(payload)

    def test_accepts_guarded_start_process_runner_packet_without_starting(self) -> None:
        payload = inspect_guarded_start_process_runner_packet(
            guarded_start_process_runner_review=ready_guarded_start_process_runner_review(),
            process_runner_packet_authorization=valid_guarded_start_process_runner_packet_authorization(),
        )

        validated = validate_guarded_start_process_runner_packet_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["process_runner_packet_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["process_launch_attempted"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_process_runner_packet_that_starts(self) -> None:
        payload = dict(ready_guarded_start_process_runner_packet())
        payload["process_launch_attempted"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_attempted"):
            validate_guarded_start_process_runner_packet_packet(payload)

    def test_rejects_guarded_start_process_runner_packet_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_process_runner_packet())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_process_runner_packet_packet(payload)

    def test_accepts_guarded_start_process_runner_execution_review_without_starting(self) -> None:
        payload = inspect_guarded_start_process_runner_execution_review(
            guarded_start_process_runner_packet=ready_guarded_start_process_runner_packet(),
            process_runner_execution_review_authorization=valid_guarded_start_process_runner_execution_review_authorization(),
        )

        validated = validate_guarded_start_process_runner_execution_review_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["process_runner_execution_review_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["process_launch_attempted"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_process_runner_execution_review_that_starts(self) -> None:
        payload = dict(ready_guarded_start_process_runner_execution_review())
        payload["process_launch_attempted"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_attempted"):
            validate_guarded_start_process_runner_execution_review_packet(payload)

    def test_rejects_guarded_start_process_runner_execution_review_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_process_runner_execution_review())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_process_runner_execution_review_packet(payload)

    def test_accepts_guarded_start_process_runner_execution_contract_without_starting(self) -> None:
        payload = inspect_guarded_start_process_runner_execution_contract(
            guarded_start_process_runner_execution_review=ready_guarded_start_process_runner_execution_review(),
            process_runner_execution_contract_authorization=(
                valid_guarded_start_process_runner_execution_contract_authorization()
            ),
        )

        validated = validate_guarded_start_process_runner_execution_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["process_runner_execution_contract_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["process_launch_attempted"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_process_runner_execution_contract_that_starts(self) -> None:
        payload = dict(ready_guarded_start_process_runner_execution_contract())
        payload["process_launch_attempted"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_attempted"):
            validate_guarded_start_process_runner_execution_contract_packet(payload)

    def test_rejects_guarded_start_process_runner_execution_contract_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_process_runner_execution_contract())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_process_runner_execution_contract_packet(payload)

    def test_accepts_guarded_start_process_runner_start_gate_without_starting(self) -> None:
        payload = inspect_guarded_start_process_runner_start_gate(
            guarded_start_process_runner_execution_contract=ready_guarded_start_process_runner_execution_contract(),
            process_runner_start_gate_authorization=valid_guarded_start_process_runner_start_gate_authorization(),
        )

        validated = validate_guarded_start_process_runner_start_gate_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["process_runner_start_gate_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["process_launch_attempted"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_process_runner_start_gate_that_starts(self) -> None:
        payload = dict(ready_guarded_start_process_runner_start_gate())
        payload["daemon_started"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "daemon_started"):
            validate_guarded_start_process_runner_start_gate_packet(payload)

    def test_rejects_guarded_start_process_runner_start_gate_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_process_runner_start_gate())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_process_runner_start_gate_packet(payload)

    def test_accepts_guarded_start_process_runner_final_review_without_starting(self) -> None:
        payload = inspect_guarded_start_process_runner_final_review(
            guarded_start_process_runner_start_gate=ready_guarded_start_process_runner_start_gate(),
            process_runner_final_review_authorization=valid_guarded_start_process_runner_final_review_authorization(),
        )

        validated = validate_guarded_start_process_runner_final_review_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["process_runner_final_review_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["process_launch_attempted"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_process_runner_final_review_that_starts(self) -> None:
        payload = dict(ready_guarded_start_process_runner_final_review())
        payload["process_launch_allowed"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "process_launch_allowed"):
            validate_guarded_start_process_runner_final_review_packet(payload)

    def test_rejects_guarded_start_process_runner_final_review_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_process_runner_final_review())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_process_runner_final_review_packet(payload)

    def test_accepts_guarded_start_process_runner_promotion_packet_without_starting(self) -> None:
        payload = inspect_guarded_start_process_runner_promotion_packet(
            guarded_start_process_runner_final_review=ready_guarded_start_process_runner_final_review(),
            process_runner_promotion_packet_authorization=(
                valid_guarded_start_process_runner_promotion_packet_authorization()
            ),
        )

        validated = validate_guarded_start_process_runner_promotion_packet_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["process_runner_promotion_packet_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["process_launch_attempted"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_process_runner_promotion_packet_that_starts(self) -> None:
        payload = dict(ready_guarded_start_process_runner_promotion_packet())
        payload["start_execution_allowed"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "start_execution_allowed"):
            validate_guarded_start_process_runner_promotion_packet_packet(payload)

    def test_rejects_guarded_start_process_runner_promotion_packet_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_process_runner_promotion_packet())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_process_runner_promotion_packet_packet(payload)

    def test_accepts_guarded_start_process_runner_operator_release_review_without_starting(self) -> None:
        payload = inspect_guarded_start_process_runner_operator_release_review(
            guarded_start_process_runner_promotion_packet=ready_guarded_start_process_runner_promotion_packet(),
            operator_release_review_authorization=(
                valid_guarded_start_process_runner_operator_release_review_authorization()
            ),
        )

        validated = validate_guarded_start_process_runner_operator_release_review_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["process_runner_operator_release_review_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["process_launch_attempted"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_process_runner_operator_release_review_that_starts(self) -> None:
        payload = dict(ready_guarded_start_process_runner_operator_release_review())
        payload["start_execution_allowed"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "start_execution_allowed"):
            validate_guarded_start_process_runner_operator_release_review_packet(payload)

    def test_rejects_guarded_start_process_runner_operator_release_review_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_process_runner_operator_release_review())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_process_runner_operator_release_review_packet(payload)

    def test_accepts_guarded_start_process_runner_release_finalization_without_starting(self) -> None:
        payload = inspect_guarded_start_process_runner_release_finalization(
            guarded_start_process_runner_operator_release_review=ready_guarded_start_process_runner_operator_release_review(),
            release_finalization_authorization=(
                valid_guarded_start_process_runner_release_finalization_authorization()
            ),
        )

        validated = validate_guarded_start_process_runner_release_finalization_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["process_runner_release_finalization_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["process_launch_attempted"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_process_runner_release_finalization_that_starts(self) -> None:
        payload = dict(ready_guarded_start_process_runner_release_finalization())
        payload["start_execution_allowed"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "start_execution_allowed"):
            validate_guarded_start_process_runner_release_finalization_packet(payload)

    def test_rejects_guarded_start_process_runner_release_finalization_with_raw_audio(self) -> None:
        payload = dict(ready_guarded_start_process_runner_release_finalization())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_guarded_start_process_runner_release_finalization_packet(payload)

    def test_accepts_guarded_start_process_runner_release_authorization_without_starting(self) -> None:
        payload = inspect_guarded_start_process_runner_release_authorization(
            guarded_start_process_runner_release_finalization=ready_guarded_start_process_runner_release_finalization(),
            release_authorization=valid_guarded_start_process_runner_release_authorization(),
        )

        validated = validate_guarded_start_process_runner_release_authorization_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["process_runner_release_authorization_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["process_launch_attempted"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_guarded_start_process_runner_release_authorization_that_starts(self) -> None:
        payload = dict(ready_guarded_start_process_runner_release_authorization())
        payload["start_execution_allowed"] = True

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "start_execution_allowed"):
            validate_guarded_start_process_runner_release_authorization_packet(payload)

    def test_accepts_controlled_livekit_server_supervised_smoke_contract_without_starting(self) -> None:
        payload = inspect_controlled_livekit_server_supervised_smoke_contract(
            guarded_start_process_runner_release_authorization=ready_guarded_start_process_runner_release_authorization(),
            controlled_smoke_plan=valid_controlled_livekit_server_supervised_smoke_plan(),
        )

        validated = validate_controlled_livekit_server_supervised_smoke_contract_packet(payload)

        self.assertIs(validated, payload)
        self.assertTrue(validated["controlled_smoke_only"])
        self.assertTrue(validated["runtime_policy_start_enabled"])
        self.assertFalse(validated["start_execution_allowed"])
        self.assertFalse(validated["process_launch_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])
        self.assertFalse(validated["livekit_sdk_imported"])
        self.assertFalse(validated["process_launch_attempted"])
        self.assertFalse(validated["daemon_started"])

    def test_rejects_controlled_livekit_server_supervised_smoke_contract_with_raw_audio(self) -> None:
        payload = dict(ready_controlled_livekit_server_supervised_smoke_contract())
        payload["debug"] = {"raw_audio": "never"}

        with self.assertRaisesRegex(SupervisedLaunchPacketViolation, "forbidden supervised_launch keys"):
            validate_controlled_livekit_server_supervised_smoke_contract_packet(payload)


if __name__ == "__main__":
    unittest.main()
