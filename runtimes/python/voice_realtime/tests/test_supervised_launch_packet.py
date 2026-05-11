from __future__ import annotations

import copy
import unittest

from atlas_voice_agent.supervised_launch_execution import (
    execute_pre_start_health_checks,
    inspect_guarded_start_activation_contract,
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


if __name__ == "__main__":
    unittest.main()
