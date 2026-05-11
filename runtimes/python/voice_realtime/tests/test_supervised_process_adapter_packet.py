from __future__ import annotations

import copy
import unittest

from atlas_voice_agent.daemon_supervisor import evaluate_daemon_supervisor
from atlas_voice_agent.supervised_process_adapter_packet import (
    SupervisedProcessAdapterPacketViolation,
    validate_supervised_process_adapter_packet,
)
from test_daemon_supervisor import ready_worker_start


class SupervisedProcessAdapterPacketTest(unittest.TestCase):
    def adapter_payload(self) -> dict[str, object]:
        supervisor = evaluate_daemon_supervisor(ready_worker_start())
        return copy.deepcopy(supervisor["supervised_process_adapter"])  # type: ignore[index]

    def test_accepts_fail_closed_adapter_packet(self) -> None:
        payload = self.adapter_payload()

        validated = validate_supervised_process_adapter_packet(payload)

        self.assertIs(validated, payload)
        self.assertFalse(validated["launch_allowed"])
        self.assertFalse(validated["subprocess_module_imported"])

    def test_rejects_env_contract_that_exposes_secrets(self) -> None:
        payload = self.adapter_payload()
        payload["managed_environment_contract"]["secret_values_present_in_output"] = True  # type: ignore[index]

        with self.assertRaisesRegex(SupervisedProcessAdapterPacketViolation, "secret_values_present_in_output"):
            validate_supervised_process_adapter_packet(payload)

    def test_rejects_launch_authorization_that_allows_launch(self) -> None:
        payload = self.adapter_payload()
        payload["launch_authorization_contract"]["launch_allowed"] = True  # type: ignore[index]

        with self.assertRaisesRegex(SupervisedProcessAdapterPacketViolation, "launch_allowed"):
            validate_supervised_process_adapter_packet(payload)

    def test_rejects_nested_raw_audio_payload(self) -> None:
        payload = self.adapter_payload()
        payload["health_snapshot"]["debug"] = {"raw_audio": "never"}  # type: ignore[index]

        with self.assertRaisesRegex(SupervisedProcessAdapterPacketViolation, "forbidden supervised_process_adapter keys"):
            validate_supervised_process_adapter_packet(payload)


if __name__ == "__main__":
    unittest.main()
