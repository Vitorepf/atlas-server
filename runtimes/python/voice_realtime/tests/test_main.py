from __future__ import annotations

import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

from atlas_voice_agent.livekit_boundary import LiveKitAgentBoundary
from atlas_voice_agent.livekit_worker import AtlasLiveKitWorker
from atlas_voice_agent.main import (
    create_atlas_voice_agent,
    create_atlas_voice_agent_from_settings,
    create_livekit_worker,
    load_callback_event,
    load_callback_events,
    load_sdk_events,
    load_scripted_events,
)
from atlas_voice_agent.settings import AtlasVoiceRuntimeSettings

from test_contract import manifest


def write_manifest() -> Path:
    handle = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
    json.dump(manifest(), handle)
    handle.close()

    return Path(handle.name)


def env(path: Path) -> dict[str, str]:
    return {
        "ATLAS_BASE_URL": "http://atlas.test",
        "ATLAS_TOKEN": "atlas-token",
        "LIVEKIT_URL": "http://livekit.test",
        "LIVEKIT_API_KEY": "livekit-key",
        "LIVEKIT_API_SECRET": "livekit-secret",
        "ATLAS_VOICE_BOOTSTRAP": str(path),
    }


def write_env_file(bootstrap_path: Path) -> Path:
    handle = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
    handle.write("\n".join(f"{key}={value}" for key, value in env(bootstrap_path).items()))
    handle.close()

    return Path(handle.name)


class AtlasVoiceMainEntrypointTest(unittest.TestCase):
    def test_manifest_factory_preserves_contract_check_mode(self) -> None:
        contract = create_atlas_voice_agent(manifest())

        self.assertEqual("http://atlas.test/ai/voice/wake-word", contract.wake_word_url)
        self.assertEqual("http://atlas.test/ai/voice/rivals", contract.rivals_url)
        self.assertEqual("http://atlas.test/ai/voice/turn", contract.turn_url)
        self.assertEqual("atlas-voice-", contract.room_prefix)

    def test_settings_factory_builds_livekit_boundary_without_direct_provider_authority(self) -> None:
        path = write_manifest()
        settings = AtlasVoiceRuntimeSettings.from_env(env(path))
        boundary = create_atlas_voice_agent_from_settings(
            settings,
            post_json=lambda url, payload: {
                "status": "turn_accepted_scaffold",
                "turn": {"decision_receipt": {"receipt_id": "receipt_1"}},
            },
        )

        self.assertIsInstance(boundary, LiveKitAgentBoundary)
        self.assertEqual("http://atlas.test/ai/voice/turn", boundary.runtime.client.contract.turn_url)

    def test_settings_factory_accepts_env_file_loaded_settings(self) -> None:
        path = write_manifest()
        settings = AtlasVoiceRuntimeSettings.from_env_file(
            path=write_env_file(path),
            env={},
        )
        boundary = create_atlas_voice_agent_from_settings(
            settings,
            post_json=lambda url, payload: {
                "status": "turn_accepted_scaffold",
                "turn": {"decision_receipt": {"receipt_id": "receipt_1"}},
            },
        )

        self.assertIsInstance(boundary, LiveKitAgentBoundary)

    def test_worker_factory_wraps_governed_boundary(self) -> None:
        path = write_manifest()
        settings = AtlasVoiceRuntimeSettings.from_env(env(path))
        boundary = create_atlas_voice_agent_from_settings(
            settings,
            post_json=lambda url, payload: {
                "status": "turn_accepted_scaffold",
                "turn": {"decision_receipt": {"receipt_id": "receipt_1"}},
            },
        )

        self.assertIsInstance(create_livekit_worker(boundary), AtlasLiveKitWorker)

    def test_loads_scripted_worker_events_from_array_or_object(self) -> None:
        array_file = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
        json.dump([{"event_kind": "start_session", "session_id": "voice_session"}], array_file)
        array_file.close()
        object_file = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
        json.dump({"events": [{"event_kind": "session_ended", "session_id": "voice_session"}]}, object_file)
        object_file.close()

        self.assertEqual("start_session", load_scripted_events(Path(array_file.name))[0]["event_kind"])
        self.assertEqual("session_ended", load_scripted_events(Path(object_file.name))[0]["event_kind"])

    def test_loads_callback_event_object(self) -> None:
        callback_file = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
        json.dump({
            "callback_kind": "participant_joined",
            "payload": {
                "session_id": "voice_session",
                "participant_identity": "mobile:vitor",
                "room_name": "atlas-voice-",
            },
        }, callback_file)
        callback_file.close()

        self.assertEqual("participant_joined", load_callback_event(Path(callback_file.name))["callback_kind"])

    def test_loads_callback_events_from_array_or_object(self) -> None:
        array_file = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
        json.dump([{"callback_kind": "participant_left", "payload": {"session_id": "voice_session"}}], array_file)
        array_file.close()
        object_file = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
        json.dump({"events": [{"callback_kind": "participant_joined", "payload": {"session_id": "voice_session"}}]}, object_file)
        object_file.close()

        self.assertEqual("participant_left", load_callback_events(Path(array_file.name))[0]["callback_kind"])
        self.assertEqual("participant_joined", load_callback_events(Path(object_file.name))[0]["callback_kind"])

    def test_loads_sdk_events_from_array_or_object(self) -> None:
        array_file = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
        json.dump([{"event_kind": "room_connected", "session_id": "voice_session"}], array_file)
        array_file.close()
        object_file = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
        json.dump({"events": [{"event_kind": "room_disconnected", "session_id": "voice_session"}]}, object_file)
        object_file.close()

        self.assertEqual("room_connected", load_sdk_events(Path(array_file.name))[0]["event_kind"])
        self.assertEqual("room_disconnected", load_sdk_events(Path(object_file.name))[0]["event_kind"])

    def test_rejects_invalid_scripted_worker_events_file(self) -> None:
        invalid_file = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
        json.dump({"events": "not-a-list"}, invalid_file)
        invalid_file.close()

        with self.assertRaises(ValueError):
            load_scripted_events(Path(invalid_file.name))

    def test_scripted_events_can_run_against_mock_kernel_without_real_server(self) -> None:
        bootstrap_path = write_manifest()
        runtime_root = Path(__file__).resolve().parents[1]
        example_path = runtime_root / "scripted-events.example.json"
        env_payload = {
            **os.environ,
            "PYTHONPATH": str(runtime_root),
        }

        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--bootstrap",
                str(bootstrap_path),
                "--mock-kernel",
                "--scripted-events",
                str(example_path),
            ],
            cwd=str(runtime_root),
            env=env_payload,
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("scripted_worker_completed", payload["status"])
        self.assertEqual("atlas.voice_realtime.scripted_worker.v1", payload["schema_version"])
        self.assertTrue(payload["mock_kernel"])
        self.assertEqual(6, payload["mock_call_count"])
        self.assertEqual(6, payload["result_count"])
        self.assertEqual(0, payload["active_session_count"])
        self.assertNotIn('"access_token"', completed.stdout)
        self.assertNotIn("header.payload.signature", completed.stdout)
        self.assertNotIn('"raw_audio":', completed.stdout)
        self.assertNotIn('"livekit_token"', completed.stdout)
        self.assertNotIn('"api_secret"', completed.stdout)

    def test_callback_event_can_route_against_mock_kernel_without_real_server(self) -> None:
        bootstrap_path = write_manifest()
        callback_file = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
        json.dump({
            "callback_kind": "participant_joined",
            "payload": {
                "session_id": "voice_session",
                "participant_identity": "mobile:vitor",
                "room_name": "atlas-voice-callback",
            },
        }, callback_file)
        callback_file.close()
        runtime_root = Path(__file__).resolve().parents[1]

        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--bootstrap",
                str(bootstrap_path),
                "--mock-kernel",
                "--callback-event",
                callback_file.name,
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("callback_routed", payload["status"])
        self.assertEqual("atlas.voice_realtime.callback_route.v1", payload["schema_version"])
        self.assertEqual(1, payload["mock_call_count"])
        self.assertEqual(1, payload["active_session_count"])
        self.assertEqual("session_started", payload["result"]["event_kind"])
        self.assertNotIn("header.payload.signature", completed.stdout)

    def test_callback_events_sequence_can_route_against_mock_kernel_without_real_server(self) -> None:
        bootstrap_path = write_manifest()
        runtime_root = Path(__file__).resolve().parents[1]
        example_path = runtime_root / "callback-events.example.json"

        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--bootstrap",
                str(bootstrap_path),
                "--mock-kernel",
                "--callback-events",
                str(example_path),
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("callback_sequence_routed", payload["status"])
        self.assertEqual("atlas.voice_realtime.callback_sequence.v1", payload["schema_version"])
        self.assertEqual(5, payload["mock_call_count"])
        self.assertEqual(5, payload["result_count"])
        self.assertEqual(0, payload["active_session_count"])
        self.assertEqual("session_started", payload["results"][0]["event_kind"])
        self.assertEqual("transcribed_turn", payload["results"][1]["event_kind"])
        self.assertEqual("synthesized", payload["results"][2]["event_kind"])
        self.assertEqual("played", payload["results"][3]["event_kind"])
        self.assertEqual("session_ended", payload["results"][4]["event_kind"])
        self.assertNotIn('"access_token"', completed.stdout)
        self.assertNotIn("header.payload.signature", completed.stdout)
        self.assertNotIn('"raw_audio":', completed.stdout)

    def test_sdk_events_sequence_can_run_production_loop_smoke_against_mock_kernel(self) -> None:
        bootstrap_path = write_manifest()
        runtime_root = Path(__file__).resolve().parents[1]
        example_path = runtime_root / "sdk-events.example.json"

        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--bootstrap",
                str(bootstrap_path),
                "--mock-kernel",
                "--sdk-events",
                str(example_path),
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("production_loop_smoke_completed", payload["status"])
        self.assertEqual("atlas.voice_realtime.production_loop_smoke.v1", payload["schema_version"])
        self.assertTrue(payload["mock_kernel"])
        self.assertEqual(5, payload["mock_call_count"])
        self.assertEqual(5, payload["event_count"])
        self.assertEqual(5, payload["result_count"])
        self.assertEqual(0, payload["active_session_count"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["sdk_imported"])
        self.assertIn('"access_token"', completed.stdout)
        self.assertIn('"livekit_token"', completed.stdout)
        self.assertIn('"api_secret"', completed.stdout)
        self.assertNotIn("header.payload.signature", completed.stdout)
        self.assertNotIn("worker-start-secret", completed.stdout)
        self.assertNotIn("worker-start-token", completed.stdout)
        self.assertNotIn('"raw_audio":', completed.stdout)

    def test_sdk_check_reports_optional_livekit_agents_dependency(self) -> None:
        bootstrap_path = write_manifest()
        runtime_root = Path(__file__).resolve().parents[1]
        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--bootstrap",
                str(bootstrap_path),
                "--sdk-check",
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("atlas.voice_realtime.sdk_check.v1", payload["schema_version"])
        self.assertIn(payload["status"], ["ready", "missing_optional_dependency"])
        self.assertTrue(payload["kernel_only"])
        self.assertEqual("voice_realtime", payload["surface_id"])
        self.assertEqual("atlas.voice_realtime.runtime_dependencies.v1", payload["dependency_manifest"]["schema_version"])
        self.assertEqual([], payload["dependency_manifest"]["core_third_party_dependencies"])
        self.assertEqual("python3 -m pip install livekit-agents", payload["dependency_manifest"]["install_command"])
        self.assertIn("probe_policy", payload["dependency_manifest"])
        self.assertFalse(payload["sdk_imported"])
        self.assertTrue(payload["import_probe_only"])
        self.assertEqual("livekit-agents", payload["package_checks"][0]["pip"])
        self.assertEqual("livekit.agents", payload["package_checks"][0]["import"])
        self.assertFalse(payload["contract"]["raw_audio_persistence_allowed"])

    def test_callback_loop_check_reports_translation_layer_without_daemon_start(self) -> None:
        bootstrap_path = write_manifest()
        runtime_root = Path(__file__).resolve().parents[1]
        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--bootstrap",
                str(bootstrap_path),
                "--callback-loop-check",
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("atlas.voice_realtime.callback_loop_contract.v1", payload["schema_version"])
        self.assertEqual("ready", payload["status"])
        self.assertTrue(payload["translation_layer_ready"])
        self.assertFalse(payload["production_sdk_loop_wired"])
        self.assertFalse(payload["worker_start_callback_loop_wired"])
        self.assertEqual("wire_real_livekit_agents_sdk_loop", payload["next_action"])

    def test_callback_loop_check_can_report_product_loop_wiring_without_daemon_start(self) -> None:
        bootstrap_path = write_manifest()
        runtime_root = Path(__file__).resolve().parents[1]
        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--bootstrap",
                str(bootstrap_path),
                "--callback-loop-check",
                "--production-sdk-loop-wired",
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("atlas.voice_realtime.callback_loop_contract.v1", payload["schema_version"])
        self.assertEqual("ready", payload["status"])
        self.assertTrue(payload["production_sdk_loop_wired"])
        self.assertTrue(payload["worker_start_callback_loop_wired"])
        self.assertEqual("run_worker_start_check", payload["next_action"])

    def test_worker_plan_reports_fail_closed_activation_path(self) -> None:
        bootstrap_path = write_manifest()
        runtime_root = Path(__file__).resolve().parents[1]
        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--bootstrap",
                str(bootstrap_path),
                "--worker-plan",
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("atlas.voice_realtime.worker_plan.v1", payload["schema_version"])
        self.assertIn(payload["status"], ["ready_to_wire_callbacks", "blocked_missing_sdk"])
        self.assertTrue(payload["kernel_only"])
        self.assertTrue(payload["mobile_first"])
        self.assertFalse(payload["settings_loaded"])
        self.assertFalse(payload["activation"]["can_start_long_running_worker"])
        self.assertEqual("AtlasLiveKitWorker", payload["entrypoint"]["worker_adapter"])
        self.assertEqual("LiveKitSdkAdapter", payload["entrypoint"]["sdk_adapter"])
        self.assertEqual("LiveKitCallbackRouter", payload["entrypoint"]["callback_router"])
        self.assertFalse(payload["guardrails"]["direct_provider_call_allowed"])

    def test_production_loop_plan_reports_sdk_wiring_sequence_without_daemon_start(self) -> None:
        bootstrap_path = write_manifest()
        runtime_root = Path(__file__).resolve().parents[1]
        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--bootstrap",
                str(bootstrap_path),
                "--production-loop-plan",
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("atlas.voice_realtime.production_loop_plan.v1", payload["schema_version"])
        self.assertEqual("voice_realtime", payload["surface_id"])
        self.assertFalse(payload["production_sdk_loop_wired"])
        self.assertFalse(payload["guardrails"]["worker_start_allowed_by_this_plan"])
        self.assertIn("route_all_sdk_callbacks_through_LiveKitCallbackRouter", payload["implementation_sequence"])

    def test_production_loop_plan_accepts_explicit_wired_gate_without_starting_daemon(self) -> None:
        bootstrap_path = write_manifest()
        runtime_root = Path(__file__).resolve().parents[1]
        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--bootstrap",
                str(bootstrap_path),
                "--production-loop-plan",
                "--production-sdk-loop-wired",
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("atlas.voice_realtime.production_loop_plan.v1", payload["schema_version"])
        self.assertTrue(payload["production_sdk_loop_wired"])
        self.assertTrue(payload["worker_start_callback_loop_wired"])
        self.assertEqual("wired", payload["sdk_wiring_contract"]["status"])
        self.assertFalse(payload["guardrails"]["worker_start_allowed_by_this_plan"])

    def test_product_loop_check_aggregates_real_product_path_without_daemon_start(self) -> None:
        bootstrap_path = write_manifest()
        env_path = write_env_file(bootstrap_path)
        runtime_root = Path(__file__).resolve().parents[1]
        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--env-file",
                str(env_path),
                "--product-loop-check",
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("atlas.voice_realtime.product_loop_check.v1", payload["schema_version"])
        self.assertTrue(payload["gates"]["callback_loop_wired"])
        self.assertTrue(payload["gates"]["production_sdk_loop_wired"])
        self.assertTrue(payload["gates"]["worker_start_still_blocked"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["guardrails"]["direct_provider_call_allowed"])
        self.assertFalse(payload["guardrails"]["auto_promotion_allowed"])

    def test_start_worker_returns_fail_closed_json_until_sdk_and_loop_are_ready(self) -> None:
        bootstrap_path = write_manifest()
        runtime_root = Path(__file__).resolve().parents[1]
        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--bootstrap",
                str(bootstrap_path),
                "--start-worker",
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("atlas.voice_realtime.worker_start.v1", payload["schema_version"])
        self.assertIn(payload["status"], [
            "blocked_missing_sdk",
            "blocked_missing_runtime_settings",
            "blocked_by_activation_gate",
            "blocked_by_activation_contract",
            "blocked_unwired_sdk_callbacks",
            "blocked_unwired_production_loop",
        ])
        self.assertFalse(payload["started"])
        self.assertTrue(payload["kernel_only"])
        self.assertFalse(payload["guardrails"]["direct_provider_call_allowed"])
        self.assertFalse(payload["guardrails"]["raw_audio_persistence_allowed"])
        self.assertEqual("atlas.voice_realtime.worker_plan.v1", payload["worker_plan"]["schema_version"])
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
        self.assertEqual(payload["activation_contract"]["next_action"], payload["activation_next_action"])

    def test_start_worker_accepts_explicit_product_loop_wiring_but_still_does_not_start(self) -> None:
        bootstrap_path = write_manifest()
        env_path = write_env_file(bootstrap_path)
        runtime_root = Path(__file__).resolve().parents[1]
        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--env-file",
                str(env_path),
                "--start-worker",
                "--callback-loop-wired",
                "--production-sdk-loop-wired",
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("atlas.voice_realtime.worker_start.v1", payload["schema_version"])
        self.assertTrue(payload["callback_loop_wired"])
        self.assertTrue(payload["production_sdk_loop_wired"])
        self.assertTrue(payload["activation_contract"]["gates"]["callback_loop_wired"])
        self.assertEqual("wired", payload["sdk_wiring_contract"]["status"])
        self.assertFalse(payload["started"])
        self.assertFalse(payload["production_promotion"]["auto_promotion_allowed"])


if __name__ == "__main__":
    unittest.main()
