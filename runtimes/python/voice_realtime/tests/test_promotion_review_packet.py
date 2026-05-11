from __future__ import annotations

from copy import deepcopy
import unittest

from atlas_voice_agent.promotion_review_packet import (
    PromotionReviewPacketViolation,
    SCHEMA_VERSION,
    validate_promotion_review_packet,
)


def valid_packet() -> dict:
    return {
        "schema_version": SCHEMA_VERSION,
        "status": "blocked_until_machine_gates_pass",
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
        "callback_loop_wired": False,
        "production_sdk_loop_wired": False,
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
            "status": "blocked",
            "next_action": "configure_livekit_token_issuer",
            "failed_keys": [
                "livekit_token_issuer_ready",
            ],
            "passed_gates": 9,
            "failed_gates": 1,
        },
        "review_packet": {
            "schema_version": "atlas.voice_realtime.production_promotion_review_packet.v1",
            "status": "blocked_until_machine_gates_pass",
            "required_human_decision": "approve_or_reject_voice_production_promotion",
            "required_decision_receipt": True,
            "required_rollback_plan": [
                "disable_livekit_token_issuer",
                "stop_livekit_worker",
            ],
            "required_evidence": [
                "runtime_certification",
                "product_loop_check",
                "pre_start_health_checks_smoke",
                "rivals_voice_comparison",
            ],
            "forbidden_actions": [
                "auto_promote_voice_runtime",
                "start_daemon_without_review",
                "persist_raw_audio",
            ],
        },
        "evidence": {
            key: {
                "name": key,
                "schema_version": f"atlas.voice_realtime.{key}.v1",
                "status": "blocked",
                "daemon_started": False,
                "promotion_allowed": False,
                "auto_promotion_allowed": False,
                "next_action": "continue_review",
                "payload_hash": "a" * 64,
            }
            for key in [
                "runtime_certification",
                "product_loop_check",
                "pre_start_health_checks_smoke",
                "rivals_voice_comparison",
            ]
        },
        "summary": {
            "evidence_count": 4,
            "failed_machine_gates": [
                "livekit_token_issuer_ready",
            ],
            "review_ready": False,
        },
        "bundle_hash": "b" * 64,
    }


class PromotionReviewPacketTest(unittest.TestCase):
    def test_accepts_fail_closed_kernel_bundle(self) -> None:
        payload = valid_packet()

        self.assertEqual(payload, validate_promotion_review_packet(payload))

    def test_rejects_auto_promotion_or_daemon_start(self) -> None:
        for key in ["promotion_allowed", "auto_promotion_allowed", "daemon_started"]:
            payload = valid_packet()
            payload[key] = True

            with self.subTest(key=key), self.assertRaises(PromotionReviewPacketViolation):
                validate_promotion_review_packet(payload)

    def test_rejects_bundle_without_explicit_product_loop_wiring_flags(self) -> None:
        for key in ["callback_loop_wired", "production_sdk_loop_wired"]:
            payload = valid_packet()
            del payload[key]

            with self.subTest(key=key), self.assertRaises(PromotionReviewPacketViolation):
                validate_promotion_review_packet(payload)

    def test_rejects_runtime_or_surface_drift(self) -> None:
        payload = valid_packet()
        payload["runtime_id"] = "rogue_runtime"

        with self.assertRaises(PromotionReviewPacketViolation):
            validate_promotion_review_packet(payload)

    def test_rejects_guardrail_relaxation(self) -> None:
        payload = valid_packet()
        payload["guardrails"]["direct_provider_call_allowed"] = True

        with self.assertRaises(PromotionReviewPacketViolation):
            validate_promotion_review_packet(payload)

    def test_rejects_bundle_without_review_packet_or_evidence_hashes(self) -> None:
        for key in ["review_packet", "evidence", "bundle_hash"]:
            payload = valid_packet()
            del payload[key]

            with self.subTest(key=key), self.assertRaises(PromotionReviewPacketViolation):
                validate_promotion_review_packet(payload)

    def test_rejects_evidence_that_allows_promotion_or_daemon(self) -> None:
        for key in ["daemon_started", "promotion_allowed", "auto_promotion_allowed"]:
            payload = deepcopy(valid_packet())
            payload["evidence"]["product_loop_check"][key] = True

            with self.subTest(key=key), self.assertRaises(PromotionReviewPacketViolation):
                validate_promotion_review_packet(payload)

    def test_rejects_boolean_review_without_rollback_or_required_evidence(self) -> None:
        for key in ["required_rollback_plan", "required_evidence", "forbidden_actions"]:
            payload = valid_packet()
            payload["review_packet"][key] = []

            with self.subTest(key=key), self.assertRaises(PromotionReviewPacketViolation):
                validate_promotion_review_packet(payload)


if __name__ == "__main__":
    unittest.main()
