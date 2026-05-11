from __future__ import annotations

import unittest

from atlas_voice_agent.daemon_implementation_review import (
    CHECK_SCHEMA_VERSION,
    SCHEMA_VERSION,
    validate_daemon_implementation_review,
)


def valid_review() -> dict[str, object]:
    return {
        "schema_version": SCHEMA_VERSION,
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


class DaemonImplementationReviewTest(unittest.TestCase):
    def test_valid_daemon_implementation_review_is_approved_and_sanitized(self) -> None:
        payload = validate_daemon_implementation_review(valid_review())

        self.assertEqual(CHECK_SCHEMA_VERSION, payload["schema_version"])
        self.assertTrue(payload["valid"])
        self.assertEqual("approved", payload["status"])
        self.assertEqual([], payload["errors"])
        self.assertEqual(
            "commit:voice-daemon-reviewed",
            payload["sanitized_review"]["implementation_ref"],
        )
        self.assertTrue(payload["sanitized_review"]["supervised_start_required"])
        self.assertFalse(payload["sanitized_review"]["direct_provider_call_allowed"])
        self.assertFalse(payload["sanitized_review"]["raw_audio_persistence_allowed"])

    def test_missing_daemon_implementation_review_is_not_approved(self) -> None:
        payload = validate_daemon_implementation_review(None)

        self.assertFalse(payload["valid"])
        self.assertEqual("missing", payload["status"])
        self.assertIn("daemon_implementation_review_missing", payload["errors"])

    def test_rejects_review_without_required_rollback_actions(self) -> None:
        review = valid_review()
        review["rollback_plan"] = ["stop_livekit_worker"]

        payload = validate_daemon_implementation_review(review)

        self.assertFalse(payload["valid"])
        self.assertTrue(any(error.startswith("rollback_plan_missing_required_actions") for error in payload["errors"]))

    def test_rejects_direct_provider_or_raw_audio_shortcut(self) -> None:
        review = valid_review()
        review["direct_provider_call_allowed"] = True
        review["raw_audio_persistence_allowed"] = True

        payload = validate_daemon_implementation_review(review)

        self.assertFalse(payload["valid"])
        self.assertIn("direct_provider_call_allowed_must_be_false", payload["errors"])
        self.assertIn("raw_audio_persistence_allowed_must_be_false", payload["errors"])

    def test_rejects_nested_secret_keys_in_daemon_review(self) -> None:
        review = valid_review()
        review["metadata"] = {"LIVEKIT_API_SECRET": "should-never-appear"}

        payload = validate_daemon_implementation_review(review)

        self.assertFalse(payload["valid"])
        self.assertTrue(any("forbidden daemon implementation review keys" in error for error in payload["errors"]))


if __name__ == "__main__":
    unittest.main()
