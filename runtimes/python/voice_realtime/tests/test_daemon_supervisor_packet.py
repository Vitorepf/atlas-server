from __future__ import annotations

import copy
import unittest

from atlas_voice_agent.daemon_supervisor_packet import (
    DaemonSupervisorPacketViolation,
    validate_daemon_supervisor_packet,
)
from test_daemon_supervisor import ready_worker_start
from atlas_voice_agent.daemon_supervisor import evaluate_daemon_supervisor


class DaemonSupervisorPacketTest(unittest.TestCase):
    def test_accepts_fail_closed_supervisor_packet(self) -> None:
        payload = evaluate_daemon_supervisor(ready_worker_start())

        validated = validate_daemon_supervisor_packet(payload)

        self.assertIs(validated, payload)
        self.assertFalse(validated["daemon_started"])
        self.assertFalse(validated["process_adapter_blueprint"]["launch_allowed"])

    def test_rejects_relaxed_top_level_launch_flags(self) -> None:
        payload = dict(evaluate_daemon_supervisor(ready_worker_start()))
        payload["start_allowed"] = True

        with self.assertRaisesRegex(DaemonSupervisorPacketViolation, "start_allowed"):
            validate_daemon_supervisor_packet(payload)

    def test_rejects_relaxed_process_adapter_blueprint(self) -> None:
        payload = copy.deepcopy(evaluate_daemon_supervisor(ready_worker_start()))
        payload["process_adapter_blueprint"]["secret_safe_output_policy"]["log_env_values"] = True

        with self.assertRaisesRegex(DaemonSupervisorPacketViolation, "log_env_values"):
            validate_daemon_supervisor_packet(payload)

    def test_rejects_relaxed_nested_supervised_adapter(self) -> None:
        payload = copy.deepcopy(evaluate_daemon_supervisor(ready_worker_start()))
        payload["supervised_process_adapter"]["subprocess_start_contract"]["subprocess_module_imported"] = True

        with self.assertRaisesRegex(DaemonSupervisorPacketViolation, "subprocess_module_imported"):
            validate_daemon_supervisor_packet(payload)

    def test_rejects_nested_secret_or_raw_payload(self) -> None:
        payload = copy.deepcopy(evaluate_daemon_supervisor(ready_worker_start()))
        payload["supervised_process_adapter"]["health_snapshot"]["debug"] = {
            "raw_audio": "never",
        }

        with self.assertRaisesRegex(DaemonSupervisorPacketViolation, "forbidden daemon_supervisor keys"):
            validate_daemon_supervisor_packet(payload)


if __name__ == "__main__":
    unittest.main()
