from __future__ import annotations

import unittest

from atlas_voice_agent.production_promotion_review import (
    CHECK_SCHEMA_VERSION,
    REVIEWED_BUNDLE_SCHEMA_VERSION,
    SCHEMA_VERSION,
    validate_production_promotion_review,
)


def valid_review() -> dict[str, object]:
    return {
        "schema_version": SCHEMA_VERSION,
        "status": "approved",
        "surface_id": "voice_realtime",
        "runtime_id": "livekit_agents_sdk",
        "reviewed_bundle_schema_version": REVIEWED_BUNDLE_SCHEMA_VERSION,
        "reviewed_bundle_hash": "a"*64,
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
        "bundle_hash": "a"*64,
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
        self.assertEqual("a"*64, payload["sanitized_review"]["reviewed_bundle_hash"])
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

    def test_rejects_review_not_bound_to_specific_evidence_bundle(self) -> None:
        review = valid_review()
        review.pop("reviewed_bundle_hash")
        review["required_evidence_reviewed"] = ["runtime_certification"]

        payload = validate_production_promotion_review(review)

        self.assertFalse(payload["valid"])
        self.assertIn("reviewed_bundle_hash_required", payload["errors"])
        self.assertIn("reviewed_bundle_hash_must_be_lowercase_sha256", payload["errors"])
        self.assertTrue(any(error.startswith("required_evidence_reviewed_missing") for error in payload["errors"]))

    def test_rejects_review_without_explicit_failed_gate_ack_list(self) -> None:
        review = valid_review()
        review.pop("failed_machine_gates_acknowledged")

        payload = validate_production_promotion_review(review)

        self.assertFalse(payload["valid"])
        self.assertIn("failed_machine_gates_acknowledged_must_be_list", payload["errors"])

    def test_rejects_nested_secret_keys_in_review_receipt(self) -> None:
        review = valid_review()
        review["metadata"] = {"LIVEKIT_API_SECRET": "should-never-appear"}

        payload = validate_production_promotion_review(review)

        self.assertFalse(payload["valid"])
        self.assertTrue(any("forbidden production promotion review keys" in error for error in payload["errors"]))

    def test_valid_review_must_match_expected_bundle_when_supplied(self) -> None:
        payload = validate_production_promotion_review(
            valid_review(),
            expected_bundle=valid_review_bundle(),
        )

        self.assertTrue(payload["valid"])
        self.assertEqual("a"*64, payload["expected_bundle"]["bundle_hash"])
        self.assertTrue(payload["expected_bundle"]["callback_loop_wired"])
        self.assertTrue(payload["expected_bundle"]["production_sdk_loop_wired"])
        self.assertEqual("ready_for_human_review", payload["expected_bundle"]["status"])
        self.assertTrue(payload["expected_bundle_required_for_final_promotion"])

    def test_rejects_review_bound_to_stale_bundle_hash(self) -> None:
        bundle = valid_review_bundle()
        bundle["bundle_hash"] = "b"*64

        payload = validate_production_promotion_review(
            valid_review(),
            expected_bundle=bundle,
        )

        self.assertFalse(payload["valid"])
        self.assertIn("reviewed_bundle_hash_mismatch", payload["errors"])

    def test_rejects_review_bound_to_different_machine_gate_status(self) -> None:
        bundle = valid_review_bundle()
        bundle["status"] = "ready_for_daemon_implementation_review"

        payload = validate_production_promotion_review(
            valid_review(),
            expected_bundle=bundle,
        )

        self.assertFalse(payload["valid"])
        self.assertIn("reviewed_machine_gate_status_mismatch", payload["errors"])

    def test_rejects_review_when_expected_bundle_is_not_kernel_safe(self) -> None:
        bundle = valid_review_bundle()
        bundle["guardrails"]["raw_audio_persistence_allowed"] = True

        payload = validate_production_promotion_review(
            valid_review(),
            expected_bundle=bundle,
        )

        self.assertFalse(payload["valid"])
        self.assertTrue(any(error.startswith("reviewed_bundle_invalid:") for error in payload["errors"]))


if __name__ == "__main__":
    unittest.main()
