from __future__ import annotations

import unittest
from unittest.mock import patch

from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.product_loop_check import build_product_loop_check

from test_contract import manifest


class ProductLoopCheckTest(unittest.TestCase):
    def test_product_loop_check_aggregates_wired_gates_without_starting_daemon(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = build_product_loop_check(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            mock_kernel=False,
        )

        self.assertEqual("atlas.voice_realtime.product_loop_check.v1", payload["schema_version"])
        self.assertEqual("voice_realtime", payload["surface_id"])
        self.assertTrue(payload["kernel_only"])
        self.assertTrue(payload["mobile_first"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["callback_loop_wired"])
        self.assertTrue(payload["gates"]["production_sdk_loop_wired"])
        self.assertTrue(payload["gates"]["worker_start_still_blocked"])
        self.assertTrue(payload["gates"]["production_promotion_blocked"])
        self.assertTrue(payload["gates"]["sdk_probe_import_safe"])
        self.assertTrue(payload["gates"]["sdk_handler_blueprint_available"])
        self.assertFalse(payload["guardrails"]["direct_provider_call_allowed"])
        self.assertFalse(payload["guardrails"]["raw_audio_persistence_allowed"])
        self.assertFalse(payload["guardrails"]["auto_promotion_allowed"])
        self.assertEqual("atlas.voice_realtime.callback_loop_contract.v1", payload["callback_loop"]["schema_version"])
        self.assertEqual("atlas.voice_realtime.production_loop_plan.v1", payload["production_loop_plan"]["schema_version"])
        self.assertEqual("atlas.voice_realtime.worker_start.v1", payload["worker_start"]["schema_version"])

        if payload["worker_start"]["status"] == "blocked_unimplemented_start":
            self.assertEqual("ready_for_daemon_implementation_review", payload["status"])
            self.assertEqual("submit_daemon_implementation_review", payload["next_action"])
        else:
            self.assertEqual("blocked", payload["status"])

    def test_product_loop_check_never_treats_mock_kernel_as_product_ready(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        payload = build_product_loop_check(
            contract,
            env={},
            settings_loaded=True,
            boundary_created=True,
            mock_kernel=True,
        )

        self.assertEqual("blocked", payload["status"])
        self.assertFalse(payload["daemon_started"])
        if payload["worker_start"]["status"] == "blocked_mock_kernel":
            self.assertEqual("use_real_kernel_not_mock", payload["next_action"])

    def test_product_loop_check_propagates_missing_sdk_probe_without_daemon_start(self) -> None:
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
            payload = build_product_loop_check(
                contract,
                env={},
                settings_loaded=True,
                boundary_created=True,
                mock_kernel=False,
            )

        sdk_status = payload["worker_start"]["worker_plan"]["sdk_status"]

        self.assertEqual("blocked", payload["status"])
        self.assertEqual("install_livekit_agents_sdk", payload["next_action"])
        self.assertEqual("blocked_missing_sdk", payload["worker_start"]["status"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["worker_start"]["started"])
        self.assertTrue(payload["gates"]["sdk_probe_import_safe"])
        self.assertFalse(sdk_status["sdk_imported"])
        self.assertTrue(sdk_status["import_probe_only"])
        self.assertEqual(["livekit.agents"], sdk_status["missing_imports"])
        self.assertIsNone(sdk_status["package_checks"][0]["version"])

    def test_product_loop_check_blocks_if_sdk_probe_contract_was_bypassed(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        worker_start = {
            "schema_version": "atlas.voice_realtime.worker_start.v1",
            "status": "blocked_unimplemented_start",
            "started": False,
            "worker_plan": {
                "schema_version": "atlas.voice_realtime.worker_plan.v1",
                "status": "ready_to_wire_callbacks",
                "sdk_status": {
                    "status": "ready",
                    "sdk_imported": True,
                    "import_probe_only": False,
                    "missing_imports": [],
                    "package_checks": [{
                        "pip": "livekit-agents",
                        "import": "livekit.agents",
                        "available": True,
                    }],
                },
            },
            "production_promotion": {"auto_promotion_allowed": False},
            "guardrails": {
                "direct_provider_call_allowed": False,
                "raw_audio_persistence_allowed": False,
            },
        }

        with patch("atlas_voice_agent.product_loop_check.start_livekit_agents_worker", return_value=worker_start):
            payload = build_product_loop_check(
                contract,
                env={},
                settings_loaded=True,
                boundary_created=True,
                mock_kernel=False,
            )

        self.assertEqual("blocked", payload["status"])
        self.assertEqual("fix_sdk_probe_contract", payload["next_action"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["gates"]["sdk_probe_import_safe"])

    def test_product_loop_check_blocks_if_sdk_handler_blueprint_is_missing(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())
        production_loop = {
            "schema_version": "atlas.voice_realtime.production_loop_plan.v1",
            "status": "ready_to_wire",
            "production_sdk_loop_wired": True,
            "sdk_wiring_contract": {
                "schema_version": "atlas.voice_realtime.sdk_wiring_contract.v1",
                "status": "wired",
                "wiring_invariants": [],
                "required_handlers": [{"sdk_event_kind": "room_connected"}],
            },
        }
        worker_start = {
            "schema_version": "atlas.voice_realtime.worker_start.v1",
            "status": "blocked_unimplemented_start",
            "started": False,
            "worker_plan": {
                "sdk_status": {
                    "status": "ready",
                    "sdk_imported": False,
                    "import_probe_only": True,
                },
            },
            "production_promotion": {"auto_promotion_allowed": False},
            "guardrails": {
                "direct_provider_call_allowed": False,
                "raw_audio_persistence_allowed": False,
            },
        }

        with patch("atlas_voice_agent.product_loop_check.build_production_loop_plan", return_value=production_loop), \
            patch("atlas_voice_agent.product_loop_check.start_livekit_agents_worker", return_value=worker_start):
            payload = build_product_loop_check(
                contract,
                env={},
                settings_loaded=True,
                boundary_created=True,
                mock_kernel=False,
            )

        self.assertEqual("blocked", payload["status"])
        self.assertEqual("fix_sdk_handler_blueprint_contract", payload["next_action"])
        self.assertFalse(payload["gates"]["sdk_handler_blueprint_available"])


if __name__ == "__main__":
    unittest.main()
