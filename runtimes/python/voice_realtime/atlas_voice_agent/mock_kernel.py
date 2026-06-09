from __future__ import annotations

import hashlib
import json
from datetime import datetime, timedelta, timezone
from typing import Any, Mapping


class MockKernelTransport:
    """Deterministic Kernel transport for local scripted smoke tests.

    This is deliberately a transport, not a runtime shortcut. The worker still
    calls AtlasKernelClient, so payload guards, turn ordering and callback
    sequencing remain exercised without requiring a running Laravel server.
    """

    def __init__(self) -> None:
        self.calls: list[dict[str, Any]] = []

    def post_json(self, url: str, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        self.calls.append({
            "method": "POST",
            "url": url,
            "payload_hash": self._hash(payload),
        })

        if url.endswith("/runtime/events/normalize-sequence"):
            return self._normalized_sequence(payload)
        if url.endswith("/runtime/events/normalize"):
            return self._normalized_event(payload)
        if url.endswith("/session/start"):
            return self._session_started(payload)
        if url.endswith("/turn"):
            return self._turn_accepted(payload)
        if url.endswith("/session/end"):
            return self._ok("session_ended_scaffold", url, payload)
        if url.endswith("/turn/synthesized"):
            return self._ok("turn_synthesized_recorded", url, payload)
        if url.endswith("/turn/played"):
            return self._ok("turn_played_recorded", url, payload)
        if url.endswith("/turn/interrupted"):
            return self._ok("turn_interrupted_recorded", url, payload)
        if url.endswith("/runtime/failed"):
            return self._ok("runtime_failure_recorded", url, payload)
        if url.endswith("/provider/health-degraded"):
            return self._ok("provider_health_degraded_recorded", url, payload)
        if url.endswith("/wake-word"):
            return self._ok("wake_word_detected_recorded", url, payload)

        return self._ok("mock_kernel_unknown_endpoint", url, payload)

    def get_json(self, url: str, query: Mapping[str, Any]) -> Mapping[str, Any]:
        self.calls.append({
            "method": "GET",
            "url": url,
            "query_hash": self._hash(query),
        })

        if url.endswith("/runtime/promotion-review-packet"):
            return self._promotion_review_packet(query)
        if url.endswith("/runtime/product-loop-check"):
            return self._product_loop_check(query)
        if url.endswith("/runtime/dependency-install-plan"):
            return self._dependency_install_plan(query)
        if url.endswith("/runtime/token-issuer-plan"):
            return self._token_issuer_plan(query)
        if url.endswith("/runtime/token-issuer-smoke"):
            return self._token_issuer_smoke(query)
        if url.endswith("/readiness"):
            return self._readiness(query)
        if url.endswith("/rivals"):
            return self._rivals(query)

        return self._ok("mock_kernel_unknown_get_endpoint", url, query)

    def _product_loop_check(self, query: Mapping[str, Any]) -> Mapping[str, Any]:
        callback_loop_wired = self._truthy(query.get("callback_loop_wired"))
        production_sdk_loop_wired = self._truthy(query.get("production_sdk_loop_wired"))
        wired = callback_loop_wired and production_sdk_loop_wired

        return {
            "schema_version": "atlas.voice_realtime.product_loop_check.v1",
            "status": "ready_for_human_review" if wired else "blocked",
            "surface_id": "voice_realtime",
            "runtime_id": str(query.get("runtime") or "livekit_agents_sdk"),
            "kernel_only": True,
            "mobile_first": True,
            "daemon_started": False,
            "gates": {
                "callback_loop_wired": callback_loop_wired,
                "production_sdk_loop_wired": production_sdk_loop_wired,
                "worker_start_still_blocked": True,
                "production_promotion_blocked": True,
                "sdk_probe_import_safe": True,
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

    def call_count(self) -> int:
        return len(self.calls)

    def _dependency_install_plan(self, query: Mapping[str, Any]) -> Mapping[str, Any]:
        return {
            "schema_version": "atlas.voice_realtime.dependency_install_plan.v1",
            "status": "ready_to_install_optional_dependency",
            "runtime_id": str(query.get("runtime") or "livekit_agents_sdk"),
            "runtime_family": "python_ai_data",
            "surface_id": "voice_realtime",
            "operator_managed": True,
            "pip_execution_attempted": False,
            "sdk_imported": False,
            "daemon_started": False,
            "kernel_only": True,
            "mobile_first": True,
            "requirements_file": "runtimes/python/voice_realtime/requirements-livekit.txt",
            "requirements_path": "/mock/requirements-livekit.txt",
            "requirements_sha256": "b" * 64,
            "expected_packages": [
                "livekit-agents",
                "livekit-plugins-openai",
            ],
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
            "verify_command": "${ATLAS_VOICE_PYTHON_BIN:-python3} -m atlas_voice_agent.main --bootstrap runtimes/python/voice_realtime/bootstrap.local.json --sdk-check --require-sdk",
            "activation_gate": "sdk_check_ready_and_runtime_certification_ready",
            "install_policy": {
                "operator_managed": True,
                "auto_install": False,
            },
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

    def _promotion_review_packet(self, query: Mapping[str, Any]) -> Mapping[str, Any]:
        hours = max(1, min(8760, int(query.get("hours") or 24)))
        callback_loop_wired = self._truthy(query.get("callback_loop_wired"))
        production_sdk_loop_wired = self._truthy(query.get("production_sdk_loop_wired"))

        return {
            "schema_version": "atlas.voice_realtime.production_promotion_review_bundle.v1",
            "status": "blocked_until_machine_gates_pass",
            "surface_id": "voice_realtime",
            "runtime_id": str(query.get("runtime") or "livekit_agents_sdk"),
            "hours": hours,
            "mock_kernel": True,
            "kernel_only": True,
            "mobile_first": True,
            "promotion_allowed": False,
            "auto_promotion_allowed": False,
            "daemon_started": False,
            "human_review_required": True,
            "decision_receipt_required": True,
            "rollback_plan_required": True,
            "callback_loop_wired": callback_loop_wired,
            "production_sdk_loop_wired": production_sdk_loop_wired,
            "raw_audio_persistence_allowed": False,
            "direct_provider_call_allowed": False,
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
            "summary": {
                "evidence_count": 4,
                "failed_machine_gates": [
                    "livekit_token_issuer_ready",
                ],
                "review_ready": False,
            },
            "evidence": {
                key: {
                    "name": key,
                    "schema_version": f"atlas.voice_realtime.{key}.v1",
                    "status": "mock_ready",
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
            "bundle_hash": self._hash({
                "runtime": str(query.get("runtime") or "livekit_agents_sdk"),
                "hours": hours,
                "mock_kernel": True,
                "callback_loop_wired": callback_loop_wired,
                "production_sdk_loop_wired": production_sdk_loop_wired,
            }),
        }

    def _token_issuer_plan(self, query: Mapping[str, Any]) -> Mapping[str, Any]:
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
                {
                    "name": "ATLAS_VOICE_LIVEKIT_TOKEN_ISSUER_ENABLED",
                    "configured": False,
                    "secret": False,
                },
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

    def _token_issuer_smoke(self, query: Mapping[str, Any]) -> Mapping[str, Any]:
        ephemeral = str(query.get("ephemeral_test_config") or "") == "1"

        return {
            "schema_version": "atlas.voice_realtime.livekit_token_issuer_smoke.v1",
            "status": "blocked" if not ephemeral else "passed_ephemeral_config",
            "surface_id": "voice_realtime",
            "runtime_id": str(query.get("runtime") or "livekit_agents_sdk"),
            "mobile_first": True,
            "kernel_only": True,
            "ephemeral_test_config": ephemeral,
            "production_readiness": "not_proven_by_ephemeral_smoke" if ephemeral else "current_environment_checked",
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
            "token_issued": ephemeral,
            "token_hash": "d" * 64 if ephemeral else None,
            "token_segments_count": 3 if ephemeral else 0,
            "access_token_exposed": False,
            "issued_token": {
                "issued": ephemeral,
                "status": "issued_redacted_ephemeral" if ephemeral else "not_issued_missing_config",
                "issuer": "atlas_voice_livekit_token_issuer",
                "reason": "ephemeral_test_config" if ephemeral else "livekit_token_issuer_not_ready",
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
            "next_action": "configure_real_livekit_env_then_run_token_issuer_smoke_without_ephemeral_test_config"
            if ephemeral else "configure_livekit_token_issuer",
        }

    def _readiness(self, query: Mapping[str, Any]) -> Mapping[str, Any]:
        hours = max(1, min(8760, int(query.get("hours") or 24)))

        return {
            "schema_version": "atlas.voice.readiness.v1",
            "status": "ready",
            "mock_kernel": True,
            "hours": hours,
            "mobile_first": True,
            "gates": {
                "ledger_available": True,
                "required_events_present": True,
                "latency_slo_clean": True,
                "raw_audio_forbidden": True,
                "kernel_decision_per_turn": True,
                "rivals_voice_ready": False,
            },
            "phase0_hardening": {
                "schema_version": "atlas.voice_realtime.phase0_hardening_gate.v1",
                "status": "ready",
                "surface_id": "voice_realtime",
                "runtime_id": str(query.get("runtime") or "livekit_agents_sdk"),
                "mobile_first": True,
                "kernel_only": True,
                "promotion_allowed": False,
                "auto_promotion_allowed": False,
            },
            "product_loop_check": {
                "schema_version": "atlas.voice_realtime.product_loop_check_reference.v1",
                "status": "available_as_runtime_contract",
                "surface_id": "voice_realtime",
                "runtime_id": str(query.get("runtime") or "livekit_agents_sdk"),
                "promotion_allowed": False,
                "auto_promotion_allowed": False,
                "daemon_started": False,
            },
            "runtime_dependency_summary": {
                "schema_version": "atlas.voice_realtime.runtime_dependency_summary.v1",
                "status": "ready",
                "runtime_id": str(query.get("runtime") or "livekit_agents_sdk"),
                "operator_managed": True,
                "auto_install_allowed": False,
            },
            "next_action": "collect_real_mobile_voice_usage_before_livekit_production_promotion",
        }

    def _rivals(self, query: Mapping[str, Any]) -> Mapping[str, Any]:
        return {
            "schema_version": "atlas.voice.rivals.v1",
            "status": "observed",
            "mock_kernel": True,
            "runtime_id": str(query.get("runtime") or "livekit_agents_sdk"),
            "hours": max(1, min(8760, int(query.get("hours") or 24))),
            "readiness": self._readiness(query),
            "require_sdk": self._truthy(query.get("require_sdk")),
            "callback_loop_wired": self._truthy(query.get("callback_loop_wired")),
            "production_sdk_loop_wired": self._truthy(query.get("production_sdk_loop_wired")),
            "promotion_allowed": False,
            "auto_promotion_allowed": False,
            "daemon_started": False,
            "direct_provider_call_allowed": False,
            "direct_tool_execution_allowed": False,
            "raw_audio_persistence_allowed": False,
            "runtime_certification": {
                "schema_version": "atlas.voice_realtime.runtime_certification.v1",
                "status": "certified_scaffold",
                "surface_id": "voice_realtime",
                "runtime_id": str(query.get("runtime") or "livekit_agents_sdk"),
                "kernel_only": True,
                "mobile_first": True,
                "daemon_started": False,
            },
            "production_promotion_gate": {
                "schema_version": "atlas.voice_realtime.production_promotion_gate.v1",
                "status": "blocked",
                "surface_id": "voice_realtime",
                "runtime_id": str(query.get("runtime") or "livekit_agents_sdk"),
                "kernel_only": True,
                "mobile_first": True,
                "human_review_required": True,
                "promotion_allowed": False,
                "auto_promotion_allowed": False,
            },
            "next_action": "continue_rivals_voice_comparison_without_daemon_start",
        }

    def _session_started(self, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        session_id = str(payload.get("session_id") or "voice_session_mock")
        room_name = self._atlas_voice_room(str(payload.get("room_name") or session_id))
        participant_identity = self._participant_identity(str(payload.get("participant_identity") or "vitor"))

        return {
            "schema_version": "atlas.voice_realtime.scaffold.v1",
            "status": "session_started_scaffold",
            "session": {
                "session_id": session_id,
                "room_name": room_name,
                "participant_identity": participant_identity,
                "runtime": str(payload.get("runtime") or "livekit_agents_sdk"),
                "transport": str(payload.get("transport") or "livekit_webrtc"),
                "privacy_class": str(payload.get("privacy_class") or "p3_audio"),
                "rivals_arm": str(payload.get("rivals_arm") or "atlas_voice"),
            },
            "session_lease": {
                "schema_version": "atlas.voice.session_lease.v1",
                "mode": "mobile_push_to_talk",
                "room_name": room_name,
                "participant_identity": participant_identity,
                "runtime_id": "livekit_agents_sdk",
                "transport": "livekit_webrtc",
                "token_status": "not_issued_scaffold",
                "token_issuer": "mock_kernel",
                "expires_at": (datetime.now(timezone.utc) + timedelta(minutes=10)).isoformat(),
                "livekit_url": None,
                "kernel_decision_required_per_turn": True,
                "raw_audio_persistence_allowed": False,
            },
            "contract": {
                "runtime_requires_decision_receipt": True,
                "raw_audio_persistence_allowed": False,
            },
        }

    def _turn_accepted(self, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        turn_id = str(payload.get("turn_id") or "voice_turn_mock")
        receipt_id = "mock_receipt_" + self._hash(payload)[:16]

        return {
            "schema_version": "atlas.voice_realtime.scaffold.v1",
            "status": "turn_accepted_scaffold",
            "session": {
                "session_id": str(payload.get("session_id") or "voice_session_mock"),
            },
            "turn": {
                "turn_id": turn_id,
                "decision_receipt": {
                    "receipt_id": receipt_id,
                    "schema_version": "atlas.decide.v2",
                    "dry_run": True,
                    "domain": str(payload.get("domain_hint") or "general"),
                    "flow": str(payload.get("flow_hint") or "general.answer"),
                    "receipt_hash": self._hash({"receipt_id": receipt_id, "payload": payload}),
                    "chain_hash": self._hash({"parent": "mock_kernel", "receipt_id": receipt_id}),
                },
                "provider_execution_enabled": False,
                "runtime_execution_enabled": False,
                "requires_decision_receipt": True,
                "raw_audio_persisted": False,
            },
            "contract": {
                "runtime_requires_decision_receipt": True,
                "raw_audio_persistence_allowed": False,
            },
        }

    def _normalized_sequence(self, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        events = payload.get("events")
        count = len(events) if isinstance(events, list) else 0

        return {
            "schema_version": "atlas.voice_realtime.runtime_event_normalizer.v1",
            "status": "normalized_sequence",
            "valid": True,
            "event_count": count,
            "errors": [],
            "contract": self._normalizer_contract(),
        }

    def _normalized_event(self, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        return {
            "schema_version": "atlas.voice_realtime.runtime_event_normalizer.v1",
            "status": "normalized",
            "valid": True,
            "event_count": 1,
            "errors": [],
            "contract": self._normalizer_contract(),
        }

    def _normalizer_contract(self) -> Mapping[str, Any]:
        return {
            "guardrails": {
                "runtime_execution_enabled": False,
                "provider_execution_enabled": False,
                "raw_audio_persistence_allowed": False,
                "secret_persistence_allowed": False,
            },
        }

    def _ok(self, status: str, url: str, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        return {
            "schema_version": "atlas.voice_realtime.mock_kernel.v1",
            "status": status,
            "mock_kernel": True,
            "endpoint_hash": self._hash({"url": url}),
            "payload_hash": self._hash(payload),
        }

    @staticmethod
    def _hash(payload: Mapping[str, Any]) -> str:
        return hashlib.sha256(
            json.dumps(payload, sort_keys=True, separators=(",", ":"), default=str).encode("utf-8")
        ).hexdigest()

    @staticmethod
    def _truthy(value: Any) -> bool:
        if isinstance(value, bool):
            return value
        if isinstance(value, int):
            return value == 1

        return str(value).strip().lower() in {"1", "true", "yes", "on"}

    @staticmethod
    def _atlas_voice_room(value: str) -> str:
        clean = "".join(char for char in value.strip() if ord(char) >= 32 and ord(char) != 127) or "voice_session_mock"
        return clean if clean.startswith("atlas-voice-") else f"atlas-voice-{clean}"

    @staticmethod
    def _participant_identity(value: str) -> str:
        clean = "".join(char for char in value.strip() if ord(char) >= 32 and ord(char) != 127) or "vitor"
        if clean.startswith(("mobile:", "mac_edge:")) and clean.split(":", 1)[1] != "":
            return clean

        return f"mobile:{clean.split(':', 1)[-1] or 'vitor'}"
