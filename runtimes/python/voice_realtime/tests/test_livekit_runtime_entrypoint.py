from __future__ import annotations

import unittest
from unittest.mock import patch

from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.daemon_implementation_review import SCHEMA_VERSION as DAEMON_REVIEW_SCHEMA_VERSION
from atlas_voice_agent.livekit_runtime_entrypoint import start_livekit_agents_worker
from atlas_voice_agent.production_promotion_review import SCHEMA_VERSION

from test_contract import manifest


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


def valid_daemon_review() -> dict[str, object]:
    return {
        "schema_version": DAEMON_REVIEW_SCHEMA_VERSION,
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


class LiveKitRuntimeEntrypointTest(unittest.TestCase):
    def test_start_worker_fails_closed_without_optional_sdk(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = start_livekit_agents_worker(contract)

        self.assertEqual("atlas.voice_realtime.worker_start.v1", payload["schema_version"])
        self.assertIn(payload["status"], [
            "blocked_missing_sdk",
            "blocked_missing_runtime_settings",
            "blocked_by_activation_gate",
            "blocked_unwired_sdk_callbacks",
            "blocked_pending_human_review",
            "blocked_unimplemented_start",
        ])
        self.assertFalse(payload["started"])
        self.assertFalse(payload["callback_loop_wired"])
        self.assertFalse(payload["production_sdk_loop_wired"])
        self.assertTrue(payload["kernel_only"])
        self.assertTrue(payload["mobile_first"])
        self.assertFalse(payload["guardrails"]["direct_provider_call_allowed"])
        self.assertFalse(payload["guardrails"]["direct_tool_execution_allowed"])
        self.assertFalse(payload["guardrails"]["raw_audio_persistence_allowed"])
        self.assertFalse(payload["guardrails"]["worker_start_without_production_promotion_allowed"])
        self.assertTrue(payload["production_promotion"]["required"])
        self.assertTrue(payload["production_promotion"]["human_review_required"])
        self.assertTrue(payload["production_promotion"]["decision_receipt_required"])
        self.assertTrue(payload["production_promotion"]["rollback_plan_required"])
        self.assertFalse(payload["production_promotion"]["human_review_approved"])
        self.assertFalse(payload["production_promotion"]["review_receipt_valid"])
        self.assertFalse(payload["production_promotion"]["boolean_approval_is_sufficient"])
        self.assertFalse(payload["production_promotion"]["auto_promotion_allowed"])
        self.assertFalse(payload["daemon_implementation_review_valid"])
        self.assertFalse(payload["daemon_implementation"]["review_receipt_valid"])
        self.assertFalse(payload["daemon_implementation"]["start_allowed_by_review"])
        self.assertEqual(
            "atlas.voice_realtime.supervised_start_plan.v1",
            payload["supervised_start_plan"]["schema_version"],
        )
        self.assertFalse(payload["supervised_start_plan"]["start_allowed"])
        self.assertFalse(payload["supervised_start_plan"]["daemon_started"])
        self.assertEqual(
            "atlas.voice_realtime.activation_contract.v1",
            payload["activation_contract"]["schema_version"],
        )
        self.assertEqual(
            "atlas.voice_realtime.production_loop_plan.v1",
            payload["production_loop_plan"]["schema_version"],
        )
        self.assertEqual(
            "atlas.voice_realtime.sdk_wiring_contract.v1",
            payload["sdk_wiring_contract"]["schema_version"],
        )
        self.assertEqual("voice_realtime", payload["activation_contract"]["surface_id"])
        self.assertIn(payload["activation_contract"]["status"], ["blocked", "ready_to_start_worker"])
        self.assertEqual(payload["activation_contract"]["next_action"], payload["activation_next_action"])
        self.assertFalse(payload["activation_contract"]["gates"]["production_sdk_loop_wired"])

    def test_start_worker_propagates_missing_sdk_probe_without_starting_daemon(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        worker_plan = {
            "schema_version": "atlas.voice_realtime.worker_plan.v1",
            "status": "blocked_missing_sdk",
            "sdk_status": {
                "status": "missing_optional_dependency",
                "sdk_imported": False,
                "import_probe_only": True,
                "missing_imports": ["livekit.agents"],
                "package_checks": [{
                    "pip": "livekit-agents",
                    "import": "livekit.agents",
                    "installed": False,
                    "version": None,
                }],
            },
            "activation": {"can_start_long_running_worker": False},
        }

        with patch("atlas_voice_agent.livekit_runtime_entrypoint.build_livekit_worker_plan", return_value=worker_plan):
            payload = start_livekit_agents_worker(
                contract,
                env={},
                settings_loaded=True,
                boundary_created=True,
                callback_loop_wired=True,
                production_sdk_loop_wired=True,
                mock_kernel=False,
            )

        sdk_status = payload["worker_plan"]["sdk_status"]

        self.assertEqual("blocked_missing_sdk", payload["status"])
        self.assertFalse(payload["started"])
        self.assertTrue(payload["callback_loop_wired"])
        self.assertTrue(payload["production_sdk_loop_wired"])
        self.assertFalse(payload["guardrails"]["worker_start_without_production_promotion_allowed"])
        self.assertFalse(sdk_status["sdk_imported"])
        self.assertTrue(sdk_status["import_probe_only"])
        self.assertEqual(["livekit.agents"], sdk_status["missing_imports"])
        self.assertIsNone(sdk_status["package_checks"][0]["version"])

    def test_start_worker_never_starts_against_mock_kernel(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = start_livekit_agents_worker(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            mock_kernel=True,
        )

        self.assertFalse(payload["started"])
        self.assertFalse(payload["activation_contract"]["gates"]["real_kernel_required"])
        if payload["worker_plan"]["sdk_status"]["status"] == "ready":
            self.assertEqual("blocked_mock_kernel", payload["status"])

    def test_start_worker_blocks_until_callback_loop_is_wired(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = start_livekit_agents_worker(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            callback_loop_wired=False,
            mock_kernel=False,
        )

        self.assertFalse(payload["started"])
        self.assertFalse(payload["callback_loop_wired"])
        if payload["worker_plan"]["sdk_status"]["status"] == "ready":
            self.assertEqual("blocked_unwired_sdk_callbacks", payload["status"])
            self.assertEqual("wire_real_sdk_callback_loop", payload["activation_next_action"])

    def test_start_worker_blocks_until_production_sdk_loop_is_wired(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = start_livekit_agents_worker(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            callback_loop_wired=True,
            production_sdk_loop_wired=False,
            mock_kernel=False,
        )

        self.assertFalse(payload["started"])
        self.assertTrue(payload["callback_loop_wired"])
        self.assertFalse(payload["production_sdk_loop_wired"])
        self.assertFalse(payload["activation_contract"]["gates"]["production_sdk_loop_wired"])
        self.assertFalse(payload["production_loop_plan"]["guardrails"]["worker_start_allowed_by_this_plan"])
        if payload["worker_plan"]["sdk_status"]["status"] == "ready":
            self.assertEqual("blocked_unwired_production_loop", payload["status"])

    def test_start_worker_blocks_fully_wired_product_loop_until_human_review(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = start_livekit_agents_worker(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            callback_loop_wired=True,
            production_sdk_loop_wired=True,
            mock_kernel=False,
        )

        self.assertFalse(payload["started"])
        self.assertTrue(payload["callback_loop_wired"])
        self.assertTrue(payload["production_sdk_loop_wired"])
        self.assertTrue(payload["activation_contract"]["gates"]["callback_loop_wired"])
        self.assertTrue(payload["activation_contract"]["gates"]["production_sdk_loop_wired"])
        self.assertTrue(payload["production_loop_plan"]["production_sdk_loop_wired"])
        self.assertTrue(payload["production_loop_plan"]["worker_start_callback_loop_wired"])
        self.assertEqual("wired", payload["sdk_wiring_contract"]["status"])
        self.assertFalse(payload["production_promotion"]["auto_promotion_allowed"])
        self.assertFalse(payload["production_promotion"]["human_review_approved"])
        if payload["worker_plan"]["sdk_status"]["status"] == "ready":
            self.assertEqual("blocked_pending_human_review", payload["status"])

    def test_start_worker_requires_review_receipt_not_boolean_flag(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = start_livekit_agents_worker(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            callback_loop_wired=True,
            production_sdk_loop_wired=True,
            production_promotion_approved=True,
            mock_kernel=False,
        )

        self.assertFalse(payload["started"])
        self.assertTrue(payload["production_promotion_approved"])
        self.assertFalse(payload["production_promotion_review_valid"])
        self.assertFalse(payload["production_promotion"]["human_review_approved"])
        self.assertFalse(payload["production_promotion"]["review_receipt_valid"])
        self.assertTrue(payload["production_promotion"]["declared_approved_without_receipt"])
        self.assertFalse(payload["production_promotion"]["boolean_approval_is_sufficient"])
        self.assertFalse(payload["production_promotion"]["auto_promotion_allowed"])
        if payload["worker_plan"]["sdk_status"]["status"] == "ready":
            self.assertEqual("blocked_pending_human_review", payload["status"])

    def test_start_worker_accepts_valid_review_receipt_but_still_blocks_until_daemon_review(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        worker_plan = {
            "schema_version": "atlas.voice_realtime.worker_plan.v1",
            "status": "ready_to_start_worker",
            "sdk_status": {"status": "ready"},
            "activation": {"can_start_long_running_worker": True},
        }
        activation_contract = {
            "schema_version": "atlas.voice_realtime.activation_contract.v1",
            "status": "ready_to_start_worker",
            "next_action": "start_worker",
            "gates": {
                "callback_loop_wired": True,
                "production_sdk_loop_wired": True,
            },
        }
        production_loop_plan = {
            "schema_version": "atlas.voice_realtime.production_loop_plan.v1",
            "status": "wired",
            "production_sdk_loop_wired": True,
            "worker_start_callback_loop_wired": True,
            "sdk_wiring_contract": {
                "schema_version": "atlas.voice_realtime.sdk_wiring_contract.v1",
                "status": "wired",
                "guardrails": {
                    "kernel_event_normalizer_required_for_real_loop": True,
                },
                "required_components": {
                    "kernel_event_normalizer": "KernelRuntimeEventNormalizerGuard",
                },
            },
        }

        with patch("atlas_voice_agent.livekit_runtime_entrypoint.build_livekit_worker_plan", return_value=worker_plan), \
            patch("atlas_voice_agent.livekit_runtime_entrypoint.build_activation_contract", return_value=activation_contract), \
            patch("atlas_voice_agent.livekit_runtime_entrypoint.build_production_loop_plan", return_value=production_loop_plan):
            payload = start_livekit_agents_worker(
                contract,
                env={},
                settings_loaded=True,
                boundary_created=True,
                callback_loop_wired=True,
                production_sdk_loop_wired=True,
                production_promotion_review=valid_review(),
                mock_kernel=False,
            )

        self.assertFalse(payload["started"])
        self.assertFalse(payload["production_promotion_approved"])
        self.assertTrue(payload["production_promotion_review_valid"])
        self.assertTrue(payload["production_promotion"]["human_review_approved"])
        self.assertTrue(payload["production_promotion"]["review_receipt_valid"])
        self.assertFalse(payload["production_promotion"]["auto_promotion_allowed"])
        self.assertFalse(payload["daemon_implementation_review_valid"])
        self.assertFalse(payload["daemon_implementation"]["review_receipt_valid"])
        self.assertEqual("blocked_pending_daemon_implementation_review", payload["supervised_start_plan"]["status"])
        self.assertFalse(payload["supervised_start_plan"]["start_allowed"])
        self.assertEqual("blocked_pending_daemon_implementation_review", payload["status"])

    def test_start_worker_accepts_daemon_review_receipt_but_still_does_not_start(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        worker_plan = {
            "schema_version": "atlas.voice_realtime.worker_plan.v1",
            "status": "ready_to_start_worker",
            "sdk_status": {"status": "ready"},
            "activation": {"can_start_long_running_worker": True},
        }
        activation_contract = {
            "schema_version": "atlas.voice_realtime.activation_contract.v1",
            "status": "ready_to_start_worker",
            "next_action": "start_worker",
            "gates": {
                "callback_loop_wired": True,
                "production_sdk_loop_wired": True,
            },
        }
        production_loop_plan = {
            "schema_version": "atlas.voice_realtime.production_loop_plan.v1",
            "status": "wired",
            "production_sdk_loop_wired": True,
            "worker_start_callback_loop_wired": True,
            "sdk_wiring_contract": {
                "schema_version": "atlas.voice_realtime.sdk_wiring_contract.v1",
                "status": "wired",
                "guardrails": {
                    "kernel_event_normalizer_required_for_real_loop": True,
                },
                "required_components": {
                    "kernel_event_normalizer": "KernelRuntimeEventNormalizerGuard",
                },
            },
        }

        with patch("atlas_voice_agent.livekit_runtime_entrypoint.build_livekit_worker_plan", return_value=worker_plan), \
            patch("atlas_voice_agent.livekit_runtime_entrypoint.build_activation_contract", return_value=activation_contract), \
            patch("atlas_voice_agent.livekit_runtime_entrypoint.build_production_loop_plan", return_value=production_loop_plan):
            payload = start_livekit_agents_worker(
                contract,
                env={},
                settings_loaded=True,
                boundary_created=True,
                callback_loop_wired=True,
                production_sdk_loop_wired=True,
                production_promotion_review=valid_review(),
                daemon_implementation_review=valid_daemon_review(),
                mock_kernel=False,
            )

        self.assertFalse(payload["started"])
        self.assertTrue(payload["production_promotion_review_valid"])
        self.assertTrue(payload["daemon_implementation_review_valid"])
        self.assertTrue(payload["daemon_implementation"]["review_receipt_valid"])
        self.assertFalse(payload["daemon_implementation"]["start_allowed_by_review"])
        self.assertEqual("ready_for_supervised_start_implementation", payload["supervised_start_plan"]["status"])
        self.assertEqual("implement_supervised_daemon_start", payload["supervised_start_plan"]["next_action"])
        self.assertFalse(payload["supervised_start_plan"]["start_allowed"])
        self.assertFalse(payload["supervised_start_plan"]["execution_implemented"])
        self.assertEqual("blocked_unimplemented_start", payload["status"])


if __name__ == "__main__":
    unittest.main()
