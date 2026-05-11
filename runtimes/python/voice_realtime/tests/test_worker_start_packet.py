from __future__ import annotations

from copy import deepcopy
import unittest

from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.livekit_runtime_entrypoint import start_livekit_agents_worker
from atlas_voice_agent.worker_start_packet import WorkerStartPacketViolation, validate_worker_start_packet

from test_contract import manifest


def valid_packet() -> dict:
    contract = AtlasVoiceRuntimeContract.from_manifest(manifest())

    return dict(start_livekit_agents_worker(contract))


class WorkerStartPacketTest(unittest.TestCase):
    def test_accepts_fail_closed_worker_start_packet(self) -> None:
        payload = valid_packet()

        self.assertEqual(payload, validate_worker_start_packet(payload))
        self.assertFalse(payload["started"])
        self.assertFalse(payload["guardrails"]["direct_provider_call_allowed"])

    def test_rejects_start_or_guardrail_relaxation(self) -> None:
        for path, key in [
            ("root", "started"),
            ("guardrails", "direct_provider_call_allowed"),
            ("guardrails", "direct_tool_execution_allowed"),
            ("guardrails", "raw_audio_persistence_allowed"),
            ("guardrails", "worker_start_without_production_promotion_allowed"),
        ]:
            payload = valid_packet()
            target = payload if path == "root" else payload[path]
            target[key] = True

            with self.subTest(path=path, key=key), self.assertRaises(WorkerStartPacketViolation):
                validate_worker_start_packet(payload)

    def test_rejects_boolean_promotion_or_daemon_review_shortcuts(self) -> None:
        for path, key in [
            ("production_promotion", "boolean_approval_is_sufficient"),
            ("production_promotion", "auto_promotion_allowed"),
            ("daemon_implementation", "start_allowed_by_review"),
        ]:
            payload = valid_packet()
            payload[path][key] = True

            with self.subTest(path=path, key=key), self.assertRaises(WorkerStartPacketViolation):
                validate_worker_start_packet(payload)

    def test_rejects_nested_secret_or_raw_payload(self) -> None:
        for key in ["provider_api_key", "raw_audio_bytes", "response_text", "tool_args"]:
            payload = deepcopy(valid_packet())
            payload["worker_plan"]["debug"] = {key: "unsafe"}

            with self.subTest(key=key), self.assertRaises(WorkerStartPacketViolation):
                validate_worker_start_packet(payload)


if __name__ == "__main__":
    unittest.main()
