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
    load_daemon_implementation_review,
    load_promotion_review_bundle,
    load_production_promotion_review,
    load_sdk_events,
    load_scripted_events,
)
from atlas_voice_agent.daemon_implementation_review import SCHEMA_VERSION as DAEMON_REVIEW_SCHEMA_VERSION
from atlas_voice_agent.production_promotion_review import (
    REVIEWED_BUNDLE_SCHEMA_VERSION,
    SCHEMA_VERSION as REVIEW_SCHEMA_VERSION,
)
from atlas_voice_agent.settings import AtlasVoiceRuntimeSettings

from test_contract import manifest


EXPECTED_OPTIONAL_PACKAGES = [
    "livekit-agents",
    "livekit-plugins-openai",
]


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


def write_review_file() -> Path:
    handle = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
    json.dump({
        "schema_version": REVIEW_SCHEMA_VERSION,
        "status": "approved",
        "surface_id": "voice_realtime",
        "runtime_id": "livekit_agents_sdk",
        "reviewed_bundle_schema_version": REVIEWED_BUNDLE_SCHEMA_VERSION,
        "reviewed_bundle_hash": "d"*64,
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
    }, handle)
    handle.close()

    return Path(handle.name)


def write_review_bundle_file(*, bundle_hash: str = "d"*64, status: str = "ready_for_human_review") -> Path:
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
    handle = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
    json.dump({
        "schema_version": REVIEWED_BUNDLE_SCHEMA_VERSION,
        "status": status,
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
        "bundle_hash": bundle_hash,
    }, handle)
    handle.close()

    return Path(handle.name)


def write_daemon_review_file() -> Path:
    handle = tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False)
    json.dump({
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
    }, handle)
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

    def test_loads_production_promotion_review_object(self) -> None:
        review_path = write_review_file()

        self.assertEqual(
            "decision_receipt_voice_1",
            load_production_promotion_review(review_path)["decision_receipt_id"],
        )

    def test_loads_daemon_implementation_review_object(self) -> None:
        review_path = write_daemon_review_file()

        self.assertEqual(
            "commit:voice-daemon-reviewed",
            load_daemon_implementation_review(review_path)["implementation_ref"],
        )

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
        self.assertEqual(11, payload["mock_call_count"])
        self.assertEqual(5, payload["event_count"])
        self.assertEqual(5, payload["result_count"])
        self.assertEqual(0, payload["active_session_count"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["sdk_imported"])
        self.assertEqual("valid", payload["kernel_normalizer_contract_report"]["status"])
        self.assertIn('"access_token"', completed.stdout)
        self.assertIn('"livekit_token"', completed.stdout)
        self.assertIn('"api_secret"', completed.stdout)
        self.assertNotIn("header.payload.signature", completed.stdout)
        self.assertNotIn("worker-start-secret", completed.stdout)
        self.assertNotIn("worker-start-token", completed.stdout)
        self.assertNotIn('"raw_audio":', completed.stdout)

    def test_promotion_review_packet_can_be_fetched_from_kernel_without_daemon_start(self) -> None:
        bootstrap_path = write_manifest()
        runtime_root = Path(__file__).resolve().parents[1]

        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--bootstrap",
                str(bootstrap_path),
                "--mock-kernel",
                "--promotion-review-packet",
                "--hours",
                "0",
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("atlas.voice_realtime.production_promotion_review_bundle.v1", payload["schema_version"])
        self.assertEqual("blocked_until_machine_gates_pass", payload["status"])
        self.assertEqual("livekit_agents_sdk", payload["runtime_id"])
        self.assertEqual(1, payload["hours"])
        self.assertTrue(payload["mock_kernel"])
        self.assertTrue(payload["kernel_only"])
        self.assertTrue(payload["mobile_first"])
        self.assertFalse(payload["promotion_allowed"])
        self.assertFalse(payload["auto_promotion_allowed"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["raw_audio_persistence_allowed"])
        self.assertFalse(payload["direct_provider_call_allowed"])
        self.assertNotIn("header.payload.signature", completed.stdout)
        self.assertNotIn('"raw_audio":', completed.stdout)
        self.assertNotIn('"api_secret"', completed.stdout)

    def test_promotion_review_packet_forwards_wiring_flags_without_daemon_start(self) -> None:
        bootstrap_path = write_manifest()
        runtime_root = Path(__file__).resolve().parents[1]

        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--bootstrap",
                str(bootstrap_path),
                "--mock-kernel",
                "--promotion-review-packet",
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

        self.assertEqual("atlas.voice_realtime.production_promotion_review_bundle.v1", payload["schema_version"])
        self.assertTrue(payload["callback_loop_wired"])
        self.assertTrue(payload["production_sdk_loop_wired"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["promotion_allowed"])
        self.assertNotIn("header.payload.signature", completed.stdout)
        self.assertNotIn('"raw_audio":', completed.stdout)
        self.assertNotIn('"api_secret"', completed.stdout)

    def test_readiness_can_be_fetched_from_kernel_without_daemon_start(self) -> None:
        bootstrap_path = write_manifest()
        runtime_root = Path(__file__).resolve().parents[1]

        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--bootstrap",
                str(bootstrap_path),
                "--mock-kernel",
                "--readiness",
                "--hours",
                "99999",
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("atlas.voice.readiness.v1", payload["schema_version"])
        self.assertEqual("ready", payload["status"])
        self.assertEqual(8760, payload["hours"])
        self.assertTrue(payload["mock_kernel"])
        self.assertTrue(payload["gates"]["raw_audio_forbidden"])
        self.assertFalse(payload["phase0_hardening"]["promotion_allowed"])
        self.assertFalse(payload["product_loop_check"]["daemon_started"])
        self.assertNotIn("header.payload.signature", completed.stdout)
        self.assertNotIn('"raw_audio":', completed.stdout)
        self.assertNotIn('"api_secret"', completed.stdout)

    def test_rivals_can_be_fetched_from_kernel_without_daemon_start(self) -> None:
        bootstrap_path = write_manifest()
        runtime_root = Path(__file__).resolve().parents[1]

        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--bootstrap",
                str(bootstrap_path),
                "--mock-kernel",
                "--rivals",
                "--hours",
                "0",
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("atlas.voice.rivals.v1", payload["schema_version"])
        self.assertEqual("observed", payload["status"])
        self.assertEqual("livekit_agents_sdk", payload["runtime_id"])
        self.assertEqual(1, payload["hours"])
        self.assertTrue(payload["mock_kernel"])
        self.assertFalse(payload["promotion_allowed"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["direct_provider_call_allowed"])
        self.assertFalse(payload["direct_tool_execution_allowed"])
        self.assertFalse(payload["raw_audio_persistence_allowed"])
        self.assertNotIn("header.payload.signature", completed.stdout)
        self.assertNotIn('"raw_audio":', completed.stdout)
        self.assertNotIn('"api_secret"', completed.stdout)

    def test_rivals_forwards_wiring_flags_without_daemon_start(self) -> None:
        bootstrap_path = write_manifest()
        runtime_root = Path(__file__).resolve().parents[1]

        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--bootstrap",
                str(bootstrap_path),
                "--mock-kernel",
                "--rivals",
                "--require-sdk",
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

        self.assertEqual("atlas.voice.rivals.v1", payload["schema_version"])
        self.assertTrue(payload["require_sdk"])
        self.assertTrue(payload["callback_loop_wired"])
        self.assertTrue(payload["production_sdk_loop_wired"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["promotion_allowed"])
        self.assertNotIn("header.payload.signature", completed.stdout)
        self.assertNotIn('"raw_audio":', completed.stdout)
        self.assertNotIn('"api_secret"', completed.stdout)

    def test_token_issuer_plan_can_be_fetched_from_kernel_without_env_write_or_token(self) -> None:
        bootstrap_path = write_manifest()
        runtime_root = Path(__file__).resolve().parents[1]

        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--bootstrap",
                str(bootstrap_path),
                "--mock-kernel",
                "--token-issuer-plan",
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("atlas.voice_realtime.livekit_token_issuer_config_plan.v1", payload["schema_version"])
        self.assertEqual("livekit_agents_sdk", payload["runtime_id"])
        self.assertTrue(payload["kernel_only"])
        self.assertTrue(payload["mobile_first"])
        self.assertFalse(payload["security_contract"]["secrets_exposed"])
        self.assertFalse(payload["security_contract"]["writes_env_file"])
        self.assertFalse(payload["security_contract"]["starts_daemon"])
        self.assertFalse(payload["security_contract"]["issues_token_during_plan"])
        self.assertNotIn("header.payload.signature", completed.stdout)
        self.assertNotIn("super-secret", completed.stdout)

    def test_token_issuer_smoke_can_be_fetched_from_kernel_without_token_or_daemon(self) -> None:
        bootstrap_path = write_manifest()
        runtime_root = Path(__file__).resolve().parents[1]

        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--bootstrap",
                str(bootstrap_path),
                "--mock-kernel",
                "--token-issuer-smoke",
                "--ephemeral-test-config",
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("atlas.voice_realtime.livekit_token_issuer_smoke.v1", payload["schema_version"])
        self.assertEqual("livekit_agents_sdk", payload["runtime_id"])
        self.assertTrue(payload["ephemeral_test_config"])
        self.assertTrue(payload["kernel_only"])
        self.assertFalse(payload["access_token_exposed"])
        self.assertFalse(payload["security_contract"]["starts_daemon"])
        self.assertFalse(payload["security_contract"]["access_token_exposed"])
        self.assertEqual("d" * 64, payload["token_hash"])
        self.assertNotIn("header.payload.signature", completed.stdout)
        self.assertNotIn('"access_token"', completed.stdout)

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
        self.assertEqual(
            "${ATLAS_VOICE_PYTHON_BIN:-python3} -m pip install -r runtimes/python/voice_realtime/requirements-livekit.txt",
            payload["dependency_manifest"]["install_command"],
        )
        self.assertEqual(
            "runtimes/python/voice_realtime/requirements-livekit.txt",
            payload["dependency_manifest"]["requirements_file"],
        )
        self.assertIn("probe_policy", payload["dependency_manifest"])
        self.assertFalse(payload["sdk_imported"])
        self.assertTrue(payload["import_probe_only"])
        package_checks = {check["pip"]: check for check in payload["package_checks"]}
        self.assertEqual(EXPECTED_OPTIONAL_PACKAGES, list(package_checks.keys()))
        self.assertEqual("livekit.agents", package_checks["livekit-agents"]["import"])
        self.assertEqual("livekit.plugins.openai", package_checks["livekit-plugins-openai"]["import"])
        self.assertFalse(payload["contract"]["raw_audio_persistence_allowed"])

    def test_dependency_install_plan_reports_operator_managed_requirements_without_running_pip(self) -> None:
        bootstrap_path = write_manifest()
        runtime_root = Path(__file__).resolve().parents[1]
        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--bootstrap",
                str(bootstrap_path),
                "--dependency-install-plan",
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("atlas.voice_realtime.dependency_install_plan.v1", payload["schema_version"])
        self.assertEqual("ready_to_install_optional_dependency", payload["status"])
        self.assertTrue(payload["operator_managed"])
        self.assertFalse(payload["pip_execution_attempted"])
        self.assertFalse(payload["sdk_imported"])
        self.assertFalse(payload["daemon_started"])
        self.assertEqual("runtimes/python/voice_realtime/requirements-livekit.txt", payload["requirements_file"])
        self.assertEqual(EXPECTED_OPTIONAL_PACKAGES, payload["expected_packages"])
        self.assertEqual([], payload["missing_requirements"])
        self.assertEqual([], payload["unsafe_requirements"])
        self.assertTrue(payload["gates"]["requirements_file_exists"])
        self.assertTrue(payload["gates"]["pip_not_executed"])
        self.assertIn("run_pip_from_sdk_check", payload["forbidden_shortcuts"])

    def test_kernel_dependency_install_plan_fetches_kernel_contract_without_running_pip(self) -> None:
        bootstrap_path = write_manifest()
        runtime_root = Path(__file__).resolve().parents[1]
        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--bootstrap",
                str(bootstrap_path),
                "--mock-kernel",
                "--kernel-dependency-install-plan",
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("atlas.voice_realtime.dependency_install_plan.v1", payload["schema_version"])
        self.assertEqual("ready_to_install_optional_dependency", payload["status"])
        self.assertTrue(payload["operator_managed"])
        self.assertTrue(payload["kernel_only"])
        self.assertTrue(payload["mobile_first"])
        self.assertFalse(payload["pip_execution_attempted"])
        self.assertFalse(payload["sdk_imported"])
        self.assertFalse(payload["daemon_started"])
        self.assertEqual("livekit_agents_sdk", payload["runtime_id"])
        self.assertEqual("python_ai_data", payload["runtime_family"])
        self.assertTrue(payload["gates"]["pip_not_executed"])
        self.assertIn("install_dependency_without_operator_review", payload["forbidden_shortcuts"])
        self.assertNotIn('"access_token"', completed.stdout)
        self.assertNotIn('"api_secret"', completed.stdout)

    def test_kernel_product_loop_check_fetches_kernel_contract_without_daemon_start(self) -> None:
        bootstrap_path = write_manifest()
        runtime_root = Path(__file__).resolve().parents[1]
        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--bootstrap",
                str(bootstrap_path),
                "--mock-kernel",
                "--kernel-product-loop-check",
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("atlas.voice_realtime.product_loop_check.v1", payload["schema_version"])
        self.assertEqual("blocked", payload["status"])
        self.assertTrue(payload["kernel_only"])
        self.assertTrue(payload["mobile_first"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["worker_start_still_blocked"])
        self.assertTrue(payload["gates"]["production_promotion_blocked"])
        self.assertFalse(payload["guardrails"]["direct_provider_call_allowed"])
        self.assertNotIn('"access_token"', completed.stdout)
        self.assertNotIn('"api_secret"', completed.stdout)

    def test_kernel_product_loop_check_forwards_wiring_flags_without_daemon_start(self) -> None:
        bootstrap_path = write_manifest()
        runtime_root = Path(__file__).resolve().parents[1]
        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--bootstrap",
                str(bootstrap_path),
                "--mock-kernel",
                "--kernel-product-loop-check",
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

        self.assertEqual("atlas.voice_realtime.product_loop_check.v1", payload["schema_version"])
        self.assertFalse(payload["daemon_started"])
        self.assertTrue(payload["gates"]["callback_loop_wired"])
        self.assertTrue(payload["gates"]["production_sdk_loop_wired"])
        self.assertTrue(payload["gates"]["worker_start_still_blocked"])
        self.assertFalse(payload["guardrails"]["direct_provider_call_allowed"])
        self.assertNotIn('"access_token"', completed.stdout)
        self.assertNotIn('"api_secret"', completed.stdout)

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

    def test_activation_contract_forwards_production_sdk_loop_wired_flag(self) -> None:
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
                "--activation-contract",
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

        self.assertEqual("atlas.voice_realtime.activation_contract.v1", payload["schema_version"])
        self.assertTrue(payload["gates"]["callback_loop_wired"])
        self.assertTrue(payload["gates"]["production_sdk_loop_wired"])
        self.assertFalse(payload["worker_plan"]["activation"]["can_start_long_running_worker"])

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

        self.assertEqual("atlas.voice_realtime.product_loop_check.v1", payload["schema_version"])
        self.assertTrue(payload["gates"]["callback_loop_wired"])
        self.assertTrue(payload["gates"]["production_sdk_loop_wired"])
        self.assertTrue(payload["gates"]["worker_start_still_blocked"])
        self.assertFalse(payload["gates"]["production_review_receipt_valid"])
        self.assertFalse(payload["gates"]["boolean_approval_is_sufficient"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["guardrails"]["direct_provider_call_allowed"])
        self.assertFalse(payload["guardrails"]["auto_promotion_allowed"])

    def test_product_loop_check_cli_requires_explicit_wiring_flags(self) -> None:
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
        self.assertFalse(payload["gates"]["callback_loop_wired"])
        self.assertFalse(payload["gates"]["production_sdk_loop_wired"])
        self.assertEqual("blocked", payload["status"])
        self.assertFalse(payload["daemon_started"])

    def test_product_loop_check_accepts_review_receipt_without_starting_daemon(self) -> None:
        bootstrap_path = write_manifest()
        env_path = write_env_file(bootstrap_path)
        review_path = write_review_file()
        runtime_root = Path(__file__).resolve().parents[1]
        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--env-file",
                str(env_path),
                "--product-loop-check",
                "--callback-loop-wired",
                "--production-sdk-loop-wired",
                "--production-promotion-review-file",
                str(review_path),
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("atlas.voice_realtime.product_loop_check.v1", payload["schema_version"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["guardrails"]["auto_promotion_allowed"])
        self.assertTrue(payload["gates"]["production_review_receipt_valid"])
        self.assertFalse(payload["gates"]["daemon_implementation_review_valid"])
        self.assertFalse(payload["gates"]["boolean_approval_is_sufficient"])
        self.assertNotIn("LIVEKIT_API_SECRET", completed.stdout)

    def test_product_loop_check_binds_review_receipt_to_kernel_bundle_when_supplied(self) -> None:
        bootstrap_path = write_manifest()
        env_path = write_env_file(bootstrap_path)
        review_path = write_review_file()
        bundle_path = write_review_bundle_file()
        runtime_root = Path(__file__).resolve().parents[1]
        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--env-file",
                str(env_path),
                "--product-loop-check",
                "--callback-loop-wired",
                "--production-sdk-loop-wired",
                "--production-promotion-review-file",
                str(review_path),
                "--promotion-review-bundle-file",
                str(bundle_path),
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        review_check = payload["worker_start"]["production_promotion"]["review_check"]
        self.assertTrue(payload["gates"]["production_review_receipt_valid"])
        self.assertTrue(payload["gates"]["production_review_bound_to_expected_bundle"])
        self.assertTrue(payload["gates"]["production_review_expected_bundle_validated"])
        self.assertEqual("d"*64, review_check["expected_bundle"]["bundle_hash"])
        self.assertTrue(review_check["expected_bundle_required_for_final_promotion"])
        self.assertFalse(payload["daemon_started"])

    def test_product_loop_check_rejects_review_receipt_bound_to_stale_kernel_bundle(self) -> None:
        bootstrap_path = write_manifest()
        env_path = write_env_file(bootstrap_path)
        review_path = write_review_file()
        bundle_path = write_review_bundle_file(bundle_hash="e"*64)
        runtime_root = Path(__file__).resolve().parents[1]
        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--env-file",
                str(env_path),
                "--product-loop-check",
                "--callback-loop-wired",
                "--production-sdk-loop-wired",
                "--production-promotion-review-file",
                str(review_path),
                "--promotion-review-bundle-file",
                str(bundle_path),
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        review_check = payload["worker_start"]["production_promotion"]["review_check"]
        self.assertFalse(payload["gates"]["production_review_receipt_valid"])
        self.assertFalse(payload["gates"]["production_review_bound_to_expected_bundle"])
        self.assertFalse(payload["gates"]["production_review_expected_bundle_validated"])
        self.assertIn("reviewed_bundle_hash_mismatch", review_check["errors"])
        self.assertFalse(payload["daemon_started"])

    def test_product_loop_check_accepts_daemon_review_receipt_without_starting_daemon(self) -> None:
        bootstrap_path = write_manifest()
        env_path = write_env_file(bootstrap_path)
        review_path = write_review_file()
        daemon_review_path = write_daemon_review_file()
        runtime_root = Path(__file__).resolve().parents[1]
        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--env-file",
                str(env_path),
                "--product-loop-check",
                "--callback-loop-wired",
                "--production-sdk-loop-wired",
                "--production-promotion-review-file",
                str(review_path),
                "--daemon-implementation-review-file",
                str(daemon_review_path),
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("atlas.voice_realtime.product_loop_check.v1", payload["schema_version"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["guardrails"]["auto_promotion_allowed"])
        self.assertTrue(payload["gates"]["production_review_receipt_valid"])
        self.assertTrue(payload["gates"]["daemon_implementation_review_valid"])
        self.assertTrue(payload["gates"]["daemon_supervisor_execution_available"])
        self.assertFalse(payload["daemon_supervisor_execution"]["process_launch_attempted"])
        self.assertFalse(payload["daemon_supervisor_execution"]["daemon_started"])
        self.assertEqual(
            "atlas.voice_realtime.supervised_process_adapter.v1",
            payload["daemon_supervisor_execution"]["supervised_process_adapter"]["schema_version"],
        )
        self.assertFalse(payload["daemon_supervisor_execution"]["supervised_process_adapter"]["process_launch_attempted"])
        self.assertFalse(payload["gates"]["boolean_approval_is_sufficient"])
        self.assertNotIn("LIVEKIT_API_SECRET", completed.stdout)

    def test_daemon_supervisor_check_reports_boundary_without_process_launch(self) -> None:
        bootstrap_path = write_manifest()
        env_path = write_env_file(bootstrap_path)
        review_path = write_review_file()
        daemon_review_path = write_daemon_review_file()
        runtime_root = Path(__file__).resolve().parents[1]
        completed = subprocess.run(
            [
                "python3",
                "-m",
                "atlas_voice_agent.main",
                "--env-file",
                str(env_path),
                "--daemon-supervisor-check",
                "--callback-loop-wired",
                "--production-sdk-loop-wired",
                "--production-promotion-review-file",
                str(review_path),
                "--daemon-implementation-review-file",
                str(daemon_review_path),
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("atlas.voice_realtime.daemon_supervisor_execution.v1", payload["schema_version"])
        self.assertIn(payload["status"], ["blocked", "ready_for_process_adapter_implementation"])
        self.assertFalse(payload["process_launch_attempted"])
        self.assertFalse(payload["daemon_started"])
        self.assertFalse(payload["start_allowed"])
        self.assertFalse(payload["guardrails"]["process_launch_allowed_by_this_contract"])
        self.assertEqual(
            "atlas.voice_realtime.supervised_process_adapter.v1",
            payload["supervised_process_adapter"]["schema_version"],
        )
        self.assertFalse(payload["supervised_process_adapter"]["process_launch_attempted"])
        self.assertFalse(payload["supervised_process_adapter"]["daemon_started"])
        self.assertNotIn("LIVEKIT_API_SECRET", completed.stdout)

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
            "blocked_pending_human_review",
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
        self.assertFalse(payload["production_promotion"]["human_review_approved"])
        self.assertFalse(payload["production_promotion"]["auto_promotion_allowed"])

    def test_start_worker_requires_review_receipt_not_boolean_flag(self) -> None:
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
                "--production-promotion-approved",
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("atlas.voice_realtime.worker_start.v1", payload["schema_version"])
        self.assertTrue(payload["production_promotion_approved"])
        self.assertFalse(payload["production_promotion_review_valid"])
        self.assertFalse(payload["production_promotion"]["human_review_approved"])
        self.assertFalse(payload["production_promotion"]["review_receipt_valid"])
        self.assertTrue(payload["production_promotion"]["declared_approved_without_receipt"])
        self.assertFalse(payload["production_promotion"]["boolean_approval_is_sufficient"])
        self.assertFalse(payload["started"])
        self.assertFalse(payload["production_promotion"]["auto_promotion_allowed"])

    def test_start_worker_accepts_valid_review_receipt_but_still_does_not_start_daemon(self) -> None:
        bootstrap_path = write_manifest()
        env_path = write_env_file(bootstrap_path)
        review_path = write_review_file()
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
                "--production-promotion-review-file",
                str(review_path),
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("atlas.voice_realtime.worker_start.v1", payload["schema_version"])
        self.assertTrue(payload["production_promotion_review_valid"])
        self.assertTrue(payload["production_promotion"]["human_review_approved"])
        self.assertTrue(payload["production_promotion"]["review_receipt_valid"])
        self.assertFalse(payload["daemon_implementation_review_valid"])
        self.assertFalse(payload["daemon_implementation"]["review_receipt_valid"])
        self.assertFalse(payload["started"])
        self.assertFalse(payload["production_promotion"]["auto_promotion_allowed"])
        self.assertNotIn("LIVEKIT_API_SECRET", completed.stdout)

    def test_start_worker_rejects_review_receipt_bound_to_stale_bundle(self) -> None:
        bootstrap_path = write_manifest()
        env_path = write_env_file(bootstrap_path)
        review_path = write_review_file()
        bundle_path = write_review_bundle_file(bundle_hash="e"*64)
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
                "--production-promotion-review-file",
                str(review_path),
                "--promotion-review-bundle-file",
                str(bundle_path),
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        review_check = payload["production_promotion"]["review_check"]
        self.assertFalse(payload["production_promotion_review_valid"])
        self.assertFalse(payload["production_promotion"]["review_receipt_valid"])
        self.assertIn("reviewed_bundle_hash_mismatch", review_check["errors"])
        self.assertFalse(payload["started"])

    def test_start_worker_accepts_daemon_review_receipt_but_still_does_not_start_daemon(self) -> None:
        bootstrap_path = write_manifest()
        env_path = write_env_file(bootstrap_path)
        review_path = write_review_file()
        daemon_review_path = write_daemon_review_file()
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
                "--production-promotion-review-file",
                str(review_path),
                "--daemon-implementation-review-file",
                str(daemon_review_path),
            ],
            cwd=str(runtime_root),
            env={**os.environ, "PYTHONPATH": str(runtime_root)},
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(completed.stdout)

        self.assertEqual("atlas.voice_realtime.worker_start.v1", payload["schema_version"])
        self.assertTrue(payload["production_promotion_review_valid"])
        self.assertTrue(payload["daemon_implementation_review_valid"])
        self.assertTrue(payload["daemon_implementation"]["review_receipt_valid"])
        self.assertFalse(payload["daemon_implementation"]["start_allowed_by_review"])
        self.assertFalse(payload["started"])
        self.assertFalse(payload["production_promotion"]["auto_promotion_allowed"])
        self.assertNotIn("LIVEKIT_API_SECRET", completed.stdout)


if __name__ == "__main__":
    unittest.main()
