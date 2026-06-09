from __future__ import annotations

import unittest
from typing import Any, Mapping

from atlas_voice_agent.contract import AtlasVoiceRuntimeContract
from atlas_voice_agent.kernel_client import AtlasKernelClient
from atlas_voice_agent.sdk_status import DependencyInstallPlanViolation
from atlas_voice_agent.status_packet import VoiceStatusPacketViolation
from atlas_voice_agent.turn_payload import UnsafeVoicePayload

from test_contract import manifest


class RecordingTransport:
    def __init__(self) -> None:
        self.calls: list[tuple[str, Mapping[str, Any]]] = []
        self.response: Mapping[str, Any] = {"status": "ok", "url": ""}

    def __call__(self, url: str, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        self.calls.append((url, dict(payload)))

        if "url" not in self.response:
            return self.response

        return {**self.response, "url": url}


def readiness_payload(*, status: str = "ledger_unavailable", hours: int = 24) -> Mapping[str, Any]:
    return {
        "schema_version": "atlas.voice.readiness.v1",
        "available": False,
        "status": status,
        "hours": hours,
        "mobile_first": True,
        "gates": {
            "ledger_available": False,
            "required_events_present": False,
            "latency_slo_clean": False,
            "raw_audio_forbidden": True,
            "kernel_decision_per_turn": True,
            "rivals_voice_ready": False,
        },
        "phase0_hardening": {
            "schema_version": "atlas.voice_realtime.phase0_hardening_gate.v1",
            "status": "ready",
            "surface_id": "voice_realtime",
            "runtime_id": "livekit_agents_sdk",
            "mobile_first": True,
            "kernel_only": True,
            "promotion_allowed": False,
            "auto_promotion_allowed": False,
        },
        "product_loop_check": {
            "schema_version": "atlas.voice_realtime.product_loop_check_reference.v1",
            "status": "available_as_runtime_contract",
            "surface_id": "voice_realtime",
            "runtime_id": "livekit_agents_sdk",
            "promotion_allowed": False,
            "auto_promotion_allowed": False,
            "daemon_started": False,
        },
        "runtime_dependency_summary": {
            "schema_version": "atlas.voice_realtime.runtime_dependency_summary.v1",
            "status": "ready",
            "runtime_id": "livekit_agents_sdk",
            "operator_managed": True,
            "auto_install_allowed": False,
        },
        "next_action": "run_ledger_migrations_before_voice_readiness",
    }


def rivals_payload(*, status: str = "ledger_unavailable", hours: int = 24) -> Mapping[str, Any]:
    return {
        "schema_version": "atlas.voice.rivals.v1",
        "available": False,
        "status": status,
        "hours": hours,
        "readiness": readiness_payload(status=status, hours=hours),
        "runtime_certification": {
            "schema_version": "atlas.voice_realtime.runtime_certification.v1",
            "status": "certified_scaffold",
            "surface_id": "voice_realtime",
            "runtime_id": "livekit_agents_sdk",
            "kernel_only": True,
            "mobile_first": True,
            "daemon_started": False,
            "product_loop_check": {
                "schema_version": "atlas.voice_realtime.product_loop_check.v1",
                "status": "blocked",
                "daemon_started": False,
            },
        },
        "production_promotion_gate": {
            "schema_version": "atlas.voice_realtime.production_promotion_gate.v1",
            "status": "blocked",
            "surface_id": "voice_realtime",
            "runtime_id": "livekit_agents_sdk",
            "mobile_first": True,
            "kernel_only": True,
            "human_review_required": True,
            "promotion_allowed": False,
            "auto_promotion_allowed": False,
        },
        "next_action": "run_ledger_migrations_before_rivals_voice",
    }


class RecordingGetTransport:
    def __init__(self) -> None:
        self.calls: list[tuple[str, Mapping[str, Any]]] = []

    def __call__(self, url: str, query: Mapping[str, Any]) -> Mapping[str, Any]:
        self.calls.append((url, dict(query)))

        if url.endswith("/runtime/promotion-review-packet"):
            return {
                "schema_version": "atlas.voice_realtime.production_promotion_review_bundle.v1",
                "status": "blocked_until_machine_gates_pass",
                "surface_id": "voice_realtime",
                "runtime_id": str(query.get("runtime") or "livekit_agents_sdk"),
                "kernel_only": True,
                "mobile_first": True,
                "promotion_allowed": False,
                "auto_promotion_allowed": False,
                "daemon_started": False,
                "human_review_required": True,
                "decision_receipt_required": True,
                "rollback_plan_required": True,
                "callback_loop_wired": query.get("callback_loop_wired") == 1,
                "production_sdk_loop_wired": query.get("production_sdk_loop_wired") == 1,
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
                    "failed_keys": ["livekit_token_issuer_ready"],
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
                    "failed_machine_gates": ["livekit_token_issuer_ready"],
                    "review_ready": False,
                },
                "bundle_hash": "b" * 64,
            }
        if url.endswith("/runtime/dependency-install-plan"):
            return {
                "schema_version": "atlas.voice_realtime.dependency_install_plan.v1",
                "status": "ready_to_install_optional_dependency",
                "surface_id": "voice_realtime",
                "runtime_id": str(query.get("runtime") or "livekit_agents_sdk"),
                "runtime_family": "python_ai_data",
                "operator_managed": True,
                "pip_execution_attempted": False,
                "sdk_imported": False,
                "daemon_started": False,
                "kernel_only": True,
                "mobile_first": True,
                "requirements_file": "runtimes/python/voice_realtime/requirements-livekit.txt",
                "requirements_sha256": "d" * 64,
                "expected_packages": ["livekit-agents", "livekit-plugins-openai"],
                "expected_requirements": [
                    "livekit-agents>=1.5,<2.0",
                    "livekit-plugins-openai>=1.5,<2.0",
                ],
                "requirements_packages": [
                    "livekit-agents>=1.5,<2.0",
                    "livekit-plugins-openai>=1.5,<2.0",
                    "python-dotenv>=1.0,<2.0",
                ],
                "missing_requirements": [],
                "unsafe_requirements": [],
                "install_command": "${ATLAS_VOICE_PYTHON_BIN:-python3} -m pip install -r runtimes/python/voice_realtime/requirements-livekit.txt",
                "verify_command": "python -m atlas_voice_agent.main --sdk-check --require-sdk",
                "activation_gate": "runtime-certify --require-sdk",
                "install_policy": "operator_managed",
                "gates": {
                    "manifest_available": True,
                    "requirements_file_declared": True,
                    "requirements_file_exists": True,
                    "requirements_match_manifest": True,
                    "requirements_safe": True,
                    "pip_not_executed": True,
                    "sdk_not_imported": True,
                    "daemon_not_started": True,
                },
                "forbidden_shortcuts": [
                    "run_pip_from_sdk_check",
                    "install_dependency_without_operator_review",
                    "import_livekit_during_install_plan",
                    "start_daemon_after_dependency_install",
                    "change_kernel_policy_from_dependency_install",
                ],
                "next_action": "run_install_command_then_sdk_check",
            }
        if url.endswith("/runtime/product-loop-check"):
            wired = query.get("callback_loop_wired") == 1 and query.get("production_sdk_loop_wired") == 1
            return {
                "schema_version": "atlas.voice_realtime.product_loop_check.v1",
                "status": "ready_for_human_review" if wired else "blocked",
                "surface_id": "voice_realtime",
                "runtime_id": str(query.get("runtime") or "livekit_agents_sdk"),
                "kernel_only": True,
                "mobile_first": True,
                "daemon_started": False,
                "gates": {
                    "callback_loop_wired": query.get("callback_loop_wired") == 1,
                    "production_sdk_loop_wired": query.get("production_sdk_loop_wired") == 1,
                    "worker_start_still_blocked": True,
                    "production_promotion_blocked": True,
                    "direct_provider_forbidden": True,
                    "raw_audio_forbidden": True,
                },
                "guardrails": {
                    "direct_provider_call_allowed": False,
                    "direct_tool_execution_allowed": False,
                    "raw_audio_persistence_allowed": False,
                    "access_token_log_allowed": False,
                    "auto_promotion_allowed": False,
                },
                "next_action": "submit_voice_production_promotion_for_human_review" if wired else "wire_real_livekit_agents_sdk_loop",
            }
        if url.endswith("/runtime/token-issuer-plan"):
            return {
                "schema_version": "atlas.voice_realtime.livekit_token_issuer_config_plan.v1",
                "status": "blocked",
                "surface_id": "voice_realtime",
                "runtime_id": str(query.get("runtime") or "livekit_agents_sdk"),
                "mobile_first": True,
                "kernel_only": True,
                "readiness": {
                    "schema_version": "atlas.voice_realtime.livekit_token_issuer_readiness.v1",
                    "status": "blocked",
                    "enabled": False,
                    "livekit_url_configured": False,
                    "api_key_configured": False,
                    "api_secret_configured": False,
                    "secrets_exposed": False,
                    "next_action": "configure_livekit_token_issuer",
                },
                "required_env": [
                    {"name": "ATLAS_VOICE_LIVEKIT_TOKEN_ISSUER_ENABLED", "configured": False, "secret": False},
                    {"name": "LIVEKIT_URL", "configured": False, "secret": False},
                    {"name": "LIVEKIT_API_KEY", "configured": False, "secret": True},
                    {"name": "LIVEKIT_API_SECRET", "configured": False, "secret": True},
                    {"name": "ATLAS_VOICE_LIVEKIT_TOKEN_TTL_SECONDS", "configured": True, "secret": False},
                ],
                "missing_env": [
                    "ATLAS_VOICE_LIVEKIT_TOKEN_ISSUER_ENABLED",
                    "LIVEKIT_URL",
                    "LIVEKIT_API_KEY",
                    "LIVEKIT_API_SECRET",
                ],
                "redacted_env_template": [
                    "ATLAS_VOICE_LIVEKIT_TOKEN_ISSUER_ENABLED=true",
                    "LIVEKIT_URL=https://<your-livekit-host>",
                    "LIVEKIT_API_KEY=<set-in-local-env-only>",
                    "LIVEKIT_API_SECRET=<set-in-local-env-only>",
                    "ATLAS_VOICE_LIVEKIT_TOKEN_TTL_SECONDS=900",
                ],
                "redacted_env_template_hash": "c" * 64,
                "security_contract": {
                    "secrets_exposed": False,
                    "writes_env_file": False,
                    "starts_daemon": False,
                    "issues_token_during_plan": False,
                    "raw_audio_persistence_allowed": False,
                    "direct_provider_call_allowed": False,
                    "direct_tool_execution_allowed": False,
                },
                "next_action": "set_missing_livekit_env_in_local_environment_only",
            }
        if url.endswith("/runtime/token-issuer-smoke"):
            return {
                "schema_version": "atlas.voice_realtime.livekit_token_issuer_smoke.v1",
                "status": "blocked",
                "surface_id": "voice_realtime",
                "runtime_id": str(query.get("runtime") or "livekit_agents_sdk"),
                "mobile_first": True,
                "kernel_only": True,
                "ephemeral_test_config": bool(query.get("ephemeral_test_config")),
                "production_readiness": "current_environment_checked",
                "readiness": {
                    "schema_version": "atlas.voice_realtime.livekit_token_issuer_readiness.v1",
                    "status": "blocked",
                    "enabled": False,
                    "livekit_url_configured": False,
                    "api_key_configured": False,
                    "api_secret_configured": False,
                    "secrets_exposed": False,
                    "next_action": "configure_livekit_token_issuer",
                },
                "token_issued": False,
                "token_hash": None,
                "token_segments_count": 0,
                "access_token_exposed": False,
                "issued_token": {
                    "issued": False,
                    "status": "not_issued_missing_config",
                    "issuer": "atlas_voice_livekit_token_issuer",
                    "reason": "livekit_token_issuer_not_ready",
                },
                "smoke_lease": {
                    "room_name": "atlas-voice-smoke",
                    "participant_identity": "mobile:smoke",
                },
                "security_contract": {
                    "secrets_exposed": False,
                    "access_token_exposed": False,
                    "writes_env_file": False,
                    "starts_daemon": False,
                    "raw_audio_persistence_allowed": False,
                    "direct_provider_call_allowed": False,
                    "direct_tool_execution_allowed": False,
                    "ephemeral_config_persists_after_command": False,
                },
                "next_action": "configure_livekit_token_issuer",
            }
        if url.endswith("/readiness"):
            return readiness_payload(hours=int(query["hours"]))
        if url.endswith("/rivals"):
            return rivals_payload(hours=int(query["hours"]))

        return {"status": "ready", "url": url}


class AtlasKernelClientTest(unittest.TestCase):
    def client(self, transport: RecordingTransport, get_transport: RecordingGetTransport | None = None) -> AtlasKernelClient:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())

        return AtlasKernelClient(contract=contract, atlas_token="token", post_json=transport, get_json=get_transport)

    def test_rejects_empty_atlas_token(self) -> None:
        contract = AtlasVoiceRuntimeContract.from_manifest(manifest())

        with self.assertRaises(ValueError):
            AtlasKernelClient(contract=contract, atlas_token=" ")

    def test_submit_turn_sanitizes_payload_before_transport(self) -> None:
        transport = RecordingTransport()
        self.client(transport).submit_turn({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "transcript": "corrija o teste",
        })

        url, payload = transport.calls[0]
        self.assertEqual("http://atlas.test/ai/voice/turn", url)
        self.assertIn("transcript_hash", payload)
        self.assertNotIn("raw_audio", payload)

    def test_submit_turn_rejects_raw_audio_before_transport(self) -> None:
        transport = RecordingTransport()

        with self.assertRaises(UnsafeVoicePayload):
            self.client(transport).submit_turn({
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "transcript": "hello",
                "raw_audio": "blocked",
            })

        self.assertEqual([], transport.calls)

    def test_start_and_end_session_use_kernel_session_endpoints(self) -> None:
        transport = RecordingTransport()
        client = self.client(transport)
        client.start_session({
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
            "room_name": "atlas-voice-vitor",
        })
        client.end_session({
            "session_id": "voice_session",
            "reason": "operator_finished",
        })

        self.assertEqual("http://atlas.test/ai/voice/session/start", transport.calls[0][0])
        self.assertEqual("mobile:vitor", transport.calls[0][1]["participant_identity"])
        self.assertNotIn("token", transport.calls[0][1])
        self.assertEqual("http://atlas.test/ai/voice/session/end", transport.calls[1][0])
        self.assertEqual("operator_finished", transport.calls[1][1]["reason"])

    def test_start_session_lease_parses_kernel_lease_without_logging_token(self) -> None:
        transport = RecordingTransport()
        transport.response = {
            "status": "session_started_scaffold",
            "session_lease": {
                "schema_version": "atlas.voice.session_lease.v1",
                "mode": "mobile_push_to_talk",
                "room_name": "atlas-voice-vitor",
                "participant_identity": "mobile:vitor",
                "runtime_id": "livekit_agents_sdk",
                "transport": "livekit_webrtc",
                "livekit_url": "http://livekit.test",
                "token_status": "issued",
                "token_issuer": "atlas_voice_livekit_token_issuer",
                "expires_at": "2026-05-07T12:15:00Z",
                "access_token": "header.payload.signature",
                "kernel_decision_required_per_turn": True,
                "raw_audio_persistence_allowed": False,
            },
        }

        lease = self.client(transport).start_session_lease({
            "session_id": "voice_session",
            "participant_identity": "mobile:vitor",
        })

        self.assertEqual("header.payload.signature", lease.access_token)
        self.assertNotIn("access_token", lease.to_log_payload())
        self.assertEqual("http://atlas.test/ai/voice/session/start", transport.calls[0][0])

    def test_readiness_uses_kernel_readiness_endpoint_with_bounded_hours(self) -> None:
        post_transport = RecordingTransport()
        get_transport = RecordingGetTransport()
        response = self.client(post_transport, get_transport).readiness(hours=99999)

        self.assertEqual("ledger_unavailable", response["status"])
        self.assertEqual("http://atlas.test/ai/voice/readiness", get_transport.calls[0][0])
        self.assertEqual(8760, get_transport.calls[0][1]["hours"])
        self.assertEqual([], post_transport.calls)

    def test_rivals_uses_kernel_rivals_endpoint_with_bounded_hours(self) -> None:
        post_transport = RecordingTransport()
        get_transport = RecordingGetTransport()
        response = self.client(post_transport, get_transport).rivals(hours=0)

        self.assertEqual("ledger_unavailable", response["status"])
        self.assertEqual("http://atlas.test/ai/voice/rivals", get_transport.calls[0][0])
        self.assertEqual("livekit_agents_sdk", get_transport.calls[0][1]["runtime"])
        self.assertEqual(1, get_transport.calls[0][1]["hours"])
        self.assertEqual(0, get_transport.calls[0][1]["require_sdk"])
        self.assertEqual(0, get_transport.calls[0][1]["callback_loop_wired"])
        self.assertEqual(0, get_transport.calls[0][1]["production_sdk_loop_wired"])
        self.assertEqual([], post_transport.calls)

    def test_rivals_forwards_runtime_product_wiring_flags_to_kernel(self) -> None:
        post_transport = RecordingTransport()
        get_transport = RecordingGetTransport()
        response = self.client(post_transport, get_transport).rivals(
            hours=24,
            require_sdk=True,
            callback_loop_wired=True,
            production_sdk_loop_wired=True,
        )

        self.assertEqual("ledger_unavailable", response["status"])
        self.assertEqual("http://atlas.test/ai/voice/rivals", get_transport.calls[0][0])
        self.assertEqual("livekit_agents_sdk", get_transport.calls[0][1]["runtime"])
        self.assertEqual(1, get_transport.calls[0][1]["require_sdk"])
        self.assertEqual(1, get_transport.calls[0][1]["callback_loop_wired"])
        self.assertEqual(1, get_transport.calls[0][1]["production_sdk_loop_wired"])
        self.assertEqual([], post_transport.calls)

    def test_readiness_rejects_kernel_response_that_relaxes_promotion_guardrails(self) -> None:
        post_transport = RecordingTransport()

        def unsafe_get(url: str, query: Mapping[str, Any]) -> Mapping[str, Any]:
            payload = dict(readiness_payload(hours=int(query["hours"])))
            payload["phase0_hardening"] = {
                **dict(payload["phase0_hardening"]),
                "promotion_allowed": True,
            }

            return payload

        with self.assertRaises(VoiceStatusPacketViolation):
            self.client(post_transport, unsafe_get).readiness()

        self.assertEqual([], post_transport.calls)

    def test_rivals_rejects_kernel_response_that_relaxes_runtime_guardrails(self) -> None:
        post_transport = RecordingTransport()

        def unsafe_get(url: str, query: Mapping[str, Any]) -> Mapping[str, Any]:
            payload = dict(rivals_payload(hours=int(query["hours"])))
            payload["runtime_certification"] = {
                **dict(payload["runtime_certification"]),
                "daemon_started": True,
            }

            return payload

        with self.assertRaises(VoiceStatusPacketViolation):
            self.client(post_transport, unsafe_get).rivals()

        self.assertEqual([], post_transport.calls)

    def test_promotion_review_packet_uses_kernel_endpoint_with_runtime_and_bounded_hours(self) -> None:
        post_transport = RecordingTransport()
        get_transport = RecordingGetTransport()
        response = self.client(post_transport, get_transport).production_promotion_review_packet(hours=0)

        self.assertEqual("blocked_until_machine_gates_pass", response["status"])
        self.assertEqual("http://atlas.test/ai/voice/runtime/promotion-review-packet", get_transport.calls[0][0])
        self.assertEqual("livekit_agents_sdk", get_transport.calls[0][1]["runtime"])
        self.assertEqual(1, get_transport.calls[0][1]["hours"])
        self.assertEqual(0, get_transport.calls[0][1]["callback_loop_wired"])
        self.assertEqual(0, get_transport.calls[0][1]["production_sdk_loop_wired"])
        self.assertFalse(response["callback_loop_wired"])
        self.assertFalse(response["production_sdk_loop_wired"])
        self.assertEqual([], post_transport.calls)

    def test_promotion_review_packet_forwards_product_loop_wiring_flags_to_kernel(self) -> None:
        post_transport = RecordingTransport()
        get_transport = RecordingGetTransport()
        response = self.client(post_transport, get_transport).production_promotion_review_packet(
            hours=24,
            callback_loop_wired=True,
            production_sdk_loop_wired=True,
        )

        self.assertEqual("http://atlas.test/ai/voice/runtime/promotion-review-packet", get_transport.calls[0][0])
        self.assertEqual("livekit_agents_sdk", get_transport.calls[0][1]["runtime"])
        self.assertEqual(1, get_transport.calls[0][1]["callback_loop_wired"])
        self.assertEqual(1, get_transport.calls[0][1]["production_sdk_loop_wired"])
        self.assertTrue(response["callback_loop_wired"])
        self.assertTrue(response["production_sdk_loop_wired"])
        self.assertEqual([], post_transport.calls)

    def test_dependency_install_plan_uses_kernel_endpoint_without_pip_sdk_or_daemon(self) -> None:
        post_transport = RecordingTransport()
        get_transport = RecordingGetTransport()
        response = self.client(post_transport, get_transport).dependency_install_plan()

        self.assertEqual("atlas.voice_realtime.dependency_install_plan.v1", response["schema_version"])
        self.assertTrue(response["operator_managed"])
        self.assertFalse(response["pip_execution_attempted"])
        self.assertFalse(response["sdk_imported"])
        self.assertFalse(response["daemon_started"])
        self.assertEqual("http://atlas.test/ai/voice/runtime/dependency-install-plan", get_transport.calls[0][0])
        self.assertEqual("livekit_agents_sdk", get_transport.calls[0][1]["runtime"])
        self.assertEqual([], post_transport.calls)

    def test_dependency_install_plan_rejects_kernel_payload_with_nested_secret(self) -> None:
        post_transport = RecordingTransport()

        def get_transport(url: str, query: Mapping[str, Any]) -> Mapping[str, Any]:
            self.assertTrue(url.endswith("/runtime/dependency-install-plan"))
            return {
                "schema_version": "atlas.voice_realtime.dependency_install_plan.v1",
                "status": "ready_to_install_optional_dependency",
                "surface_id": "voice_realtime",
                "runtime_id": str(query.get("runtime") or "livekit_agents_sdk"),
                "runtime_family": "python_ai_data",
                "operator_managed": True,
                "pip_execution_attempted": False,
                "sdk_imported": False,
                "daemon_started": False,
                "kernel_only": True,
                "mobile_first": True,
                "requirements_file": "runtimes/python/voice_realtime/requirements-livekit.txt",
                "requirements_sha256": "d" * 64,
                "expected_packages": ["livekit-agents", "livekit-plugins-openai"],
                "expected_requirements": [
                    "livekit-agents>=1.5,<2.0",
                    "livekit-plugins-openai>=1.5,<2.0",
                ],
                "requirements_packages": [
                    "livekit-agents>=1.5,<2.0",
                    "livekit-plugins-openai>=1.5,<2.0",
                    "python-dotenv>=1.0,<2.0",
                ],
                "missing_requirements": [],
                "unsafe_requirements": [],
                "install_command": "${ATLAS_VOICE_PYTHON_BIN:-python3} -m pip install -r runtimes/python/voice_realtime/requirements-livekit.txt",
                "verify_command": "python -m atlas_voice_agent.main --sdk-check --require-sdk",
                "activation_gate": "runtime-certify --require-sdk",
                "install_policy": "operator_managed",
                "gates": {
                    "manifest_available": True,
                    "requirements_file_declared": True,
                    "requirements_file_exists": True,
                    "requirements_match_manifest": True,
                    "requirements_safe": True,
                    "pip_not_executed": True,
                    "sdk_not_imported": True,
                    "daemon_not_started": True,
                },
                "forbidden_shortcuts": [
                    "run_pip_from_sdk_check",
                    "install_dependency_without_operator_review",
                    "import_livekit_during_install_plan",
                    "start_daemon_after_dependency_install",
                    "change_kernel_policy_from_dependency_install",
                ],
                "nested": {"api_secret": "secret"},
                "next_action": "run_install_command_then_sdk_check",
            }

        with self.assertRaisesRegex(DependencyInstallPlanViolation, "api_secret"):
            self.client(post_transport, get_transport).dependency_install_plan()

    def test_product_loop_check_uses_kernel_endpoint_without_daemon_start(self) -> None:
        post_transport = RecordingTransport()
        get_transport = RecordingGetTransport()
        response = self.client(post_transport, get_transport).product_loop_check()

        self.assertEqual("atlas.voice_realtime.product_loop_check.v1", response["schema_version"])
        self.assertEqual("blocked", response["status"])
        self.assertFalse(response["daemon_started"])
        self.assertTrue(response["gates"]["worker_start_still_blocked"])
        self.assertFalse(response["gates"]["callback_loop_wired"])
        self.assertFalse(response["gates"]["production_sdk_loop_wired"])
        self.assertFalse(response["guardrails"]["direct_provider_call_allowed"])
        self.assertEqual("http://atlas.test/ai/voice/runtime/product-loop-check", get_transport.calls[0][0])
        self.assertEqual("livekit_agents_sdk", get_transport.calls[0][1]["runtime"])
        self.assertEqual(0, get_transport.calls[0][1]["callback_loop_wired"])
        self.assertEqual(0, get_transport.calls[0][1]["production_sdk_loop_wired"])
        self.assertEqual([], post_transport.calls)

    def test_product_loop_check_forwards_wiring_flags_to_kernel_without_daemon_start(self) -> None:
        post_transport = RecordingTransport()
        get_transport = RecordingGetTransport()
        response = self.client(post_transport, get_transport).product_loop_check(
            callback_loop_wired=True,
            production_sdk_loop_wired=True,
        )

        self.assertEqual("atlas.voice_realtime.product_loop_check.v1", response["schema_version"])
        self.assertFalse(response["daemon_started"])
        self.assertTrue(response["gates"]["callback_loop_wired"])
        self.assertTrue(response["gates"]["production_sdk_loop_wired"])
        self.assertEqual("http://atlas.test/ai/voice/runtime/product-loop-check", get_transport.calls[0][0])
        self.assertEqual(1, get_transport.calls[0][1]["callback_loop_wired"])
        self.assertEqual(1, get_transport.calls[0][1]["production_sdk_loop_wired"])
        self.assertEqual([], post_transport.calls)

    def test_token_issuer_plan_uses_kernel_endpoint_without_secret_or_write(self) -> None:
        post_transport = RecordingTransport()
        get_transport = RecordingGetTransport()
        response = self.client(post_transport, get_transport).token_issuer_plan()

        self.assertEqual("atlas.voice_realtime.livekit_token_issuer_config_plan.v1", response["schema_version"])
        self.assertFalse(response["security_contract"]["secrets_exposed"])
        self.assertFalse(response["security_contract"]["writes_env_file"])
        self.assertFalse(response["security_contract"]["starts_daemon"])
        self.assertEqual("http://atlas.test/ai/voice/runtime/token-issuer-plan", get_transport.calls[0][0])
        self.assertEqual("livekit_agents_sdk", get_transport.calls[0][1]["runtime"])
        self.assertEqual([], post_transport.calls)

    def test_token_issuer_smoke_uses_kernel_endpoint_without_token_leak(self) -> None:
        post_transport = RecordingTransport()
        get_transport = RecordingGetTransport()
        response = self.client(post_transport, get_transport).token_issuer_smoke(ephemeral_test_config=True)

        self.assertEqual("atlas.voice_realtime.livekit_token_issuer_smoke.v1", response["schema_version"])
        self.assertFalse(response["access_token_exposed"])
        self.assertFalse(response["security_contract"]["starts_daemon"])
        self.assertEqual("http://atlas.test/ai/voice/runtime/token-issuer-smoke", get_transport.calls[0][0])
        self.assertEqual("livekit_agents_sdk", get_transport.calls[0][1]["runtime"])
        self.assertEqual(1, get_transport.calls[0][1]["ephemeral_test_config"])
        self.assertEqual([], post_transport.calls)

    def test_normalize_runtime_event_uses_kernel_normalizer_without_execution(self) -> None:
        transport = RecordingTransport()
        response = self.client(transport).normalize_runtime_event({
            "event_kind": "transcribed_turn",
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "transcript": "continue",
            "ignored_sdk_object": {"safe": True},
        })

        self.assertEqual("ok", response["status"])
        self.assertEqual("http://atlas.test/ai/voice/runtime/events/normalize", transport.calls[0][0])
        self.assertEqual("transcribed_turn", transport.calls[0][1]["event"]["event_kind"])
        self.assertIn("ignored_sdk_object", transport.calls[0][1]["event"])

    def test_normalize_runtime_event_sequence_uses_kernel_sequence_normalizer(self) -> None:
        transport = RecordingTransport()
        response = self.client(transport).normalize_runtime_event_sequence([
            {
                "event_kind": "room_connected",
                "session_id": "voice_session",
                "participant_identity": "mobile:vitor",
                "room_name": "atlas-voice-vitor",
            },
            {
                "event_kind": "room_disconnected",
                "session_id": "voice_session",
            },
        ])

        self.assertEqual("ok", response["status"])
        self.assertEqual("http://atlas.test/ai/voice/runtime/events/normalize-sequence", transport.calls[0][0])
        self.assertEqual(2, len(transport.calls[0][1]["events"]))

    def test_normalize_runtime_event_rejects_raw_audio_and_secrets_before_transport(self) -> None:
        transport = RecordingTransport()
        client = self.client(transport)

        with self.assertRaises(UnsafeVoicePayload):
            client.normalize_runtime_event({
                "event_kind": "transcribed_turn",
                "session_id": "voice_session",
                "turn_id": "voice_turn",
                "raw_audio": "blocked",
            })

        with self.assertRaises(UnsafeVoicePayload):
            client.normalize_runtime_event_sequence([
                {
                    "event_kind": "room_connected",
                    "session_id": "voice_session",
                    "participant_identity": "mobile:vitor",
                    "room_name": "atlas-voice-vitor",
                    "metadata": {"provider_api_key": "blocked"},
                },
            ])

        self.assertEqual([], transport.calls)

    def test_normalize_runtime_event_sequence_rejects_empty_sequence(self) -> None:
        transport = RecordingTransport()

        with self.assertRaises(UnsafeVoicePayload):
            self.client(transport).normalize_runtime_event_sequence([])

        self.assertEqual([], transport.calls)

    def test_report_wake_word_sends_safe_payload_before_transport(self) -> None:
        transport = RecordingTransport()
        self.client(transport).report_wake_word({
            "session_id": "voice_session",
            "wake_word_engine": "swift_local_edge",
            "latency_ms": 42,
        })

        url, payload = transport.calls[0]
        self.assertEqual("http://atlas.test/ai/voice/wake-word", url)
        self.assertEqual("swift_local_edge", payload["wake_word_engine"])
        self.assertNotIn("raw_audio", payload)

    def test_report_synthesized_hashes_text_before_transport(self) -> None:
        transport = RecordingTransport()
        self.client(transport).report_synthesized({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "response_text": "resposta sensivel",
        })

        url, payload = transport.calls[0]
        self.assertEqual("http://atlas.test/ai/voice/turn/synthesized", url)
        self.assertIn("response_text_hash", payload)
        self.assertNotIn("response_text", payload)

    def test_reports_interruption_and_provider_health_to_expected_urls(self) -> None:
        transport = RecordingTransport()
        client = self.client(transport)
        client.report_interrupted({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "reason": "operator_started_speaking",
        })
        client.report_provider_health_degraded({
            "session_id": "voice_session",
            "turn_id": "voice_turn",
            "provider": "deepgram",
            "reason": "latency_p95_breach",
        })

        self.assertEqual("http://atlas.test/ai/voice/turn/interrupted", transport.calls[0][0])
        self.assertEqual("http://atlas.test/ai/voice/provider/health-degraded", transport.calls[1][0])
        self.assertEqual("deepgram", transport.calls[1][1]["provider"])


if __name__ == "__main__":
    unittest.main()
