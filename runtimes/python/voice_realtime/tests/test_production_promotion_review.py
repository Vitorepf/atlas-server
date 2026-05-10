from __future__ import annotations

import unittest

from atlas_voice_agent.production_promotion_review import (
    CHECK_SCHEMA_VERSION,
    SCHEMA_VERSION,
    validate_production_promotion_review,
)


def valid_review() -> dict[str, object]:
    return {
        "schema_version": SCHEMA_VERSION,
        "status": "approved",
        "surface_id": "voice_realtime",
        "runtime_id": "livekit_agents_sdk",
        "decision_receipt_id": "decision_receipt_voice_1",
        "approved_by": "vitor",
        "approved_at": "2026-05-10T12:00:00Z",
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


class ProductionPromotionReviewTest(unittest.TestCase):
    def test_valid_review_receipt_is_approved_and_sanitized(self) -> None:
        payload = validate_production_promotion_review(valid_review())

        self.assertEqual(CHECK_SCHEMA_VERSION, payload["schema_version"])
        self.assertTrue(payload["valid"])
        self.assertEqual("approved", payload["status"])
        self.assertEqual([], payload["errors"])
        self.assertEqual(
            "decision_receipt_voice_1",
            payload["sanitized_review"]["decision_receipt_id"],
        )
        self.assertFalse(payload["sanitized_review"]["auto_promotion_allowed"])

    def test_missing_review_receipt_is_not_approved(self) -> None:
        payload = validate_production_promotion_review(None)

        self.assertFalse(payload["valid"])
        self.assertEqual("missing", payload["status"])
        self.assertIn("review_receipt_missing", payload["errors"])

    def test_rejects_review_without_required_rollback_actions(self) -> None:
        review = valid_review()
        review["rollback_plan"] = ["stop_livekit_worker"]

        payload = validate_production_promotion_review(review)

        self.assertFalse(payload["valid"])
        self.assertTrue(any(error.startswith("rollback_plan_missing_required_actions") for error in payload["errors"]))

    def test_rejects_boolean_or_auto_promotion_shortcut(self) -> None:
        review = valid_review()
        review["auto_promotion_allowed"] = True

        payload = validate_production_promotion_review(review)

        self.assertFalse(payload["valid"])
        self.assertIn("auto_promotion_allowed_must_be_false", payload["errors"])

    def test_rejects_nested_secret_keys_in_review_receipt(self) -> None:
        review = valid_review()
        review["metadata"] = {"LIVEKIT_API_SECRET": "should-never-appear"}

        payload = validate_production_promotion_review(review)

        self.assertFalse(payload["valid"])
        self.assertTrue(any("forbidden production promotion review keys" in error for error in payload["errors"]))


if __name__ == "__main__":
    unittest.main()
