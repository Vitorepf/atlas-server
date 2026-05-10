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

    def call_count(self) -> int:
        return len(self.calls)

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
    def _atlas_voice_room(value: str) -> str:
        clean = "".join(char for char in value.strip() if ord(char) >= 32 and ord(char) != 127) or "voice_session_mock"
        return clean if clean.startswith("atlas-voice-") else f"atlas-voice-{clean}"

    @staticmethod
    def _participant_identity(value: str) -> str:
        clean = "".join(char for char in value.strip() if ord(char) >= 32 and ord(char) != 127) or "vitor"
        if clean.startswith(("mobile:", "mac_edge:")) and clean.split(":", 1)[1] != "":
            return clean

        return f"mobile:{clean.split(':', 1)[-1] or 'vitor'}"
