from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Mapping
from urllib.parse import urlparse

from .callback_contract import REQUIRED_CALLBACK_PAYLOAD_SCHEMAS


class ContractViolation(RuntimeError):
    """Raised when the Kernel bootstrap manifest is unsafe or incomplete."""


@dataclass(frozen=True)
class AtlasVoiceRuntimeContract:
    manifest: Mapping[str, Any]

    REQUIRED_SCHEMA = "atlas.voice_realtime.runtime_bootstrap.v1"
    REQUIRED_FORBIDDEN = {
        "direct_llm_provider_call",
        "direct_tool_execution",
        "memory_write",
        "policy_override",
        "raw_audio_persistence",
        "raw_transcript_persistence",
    }
    REQUIRED_ENV = {
        "ATLAS_BASE_URL",
        "ATLAS_TOKEN",
        "LIVEKIT_URL",
        "LIVEKIT_API_KEY",
        "LIVEKIT_API_SECRET",
        "ATLAS_VOICE_BOOTSTRAP",
    }
    ALLOWED_BOOTSTRAP_TOKEN_STATUS = {
        "not_issued_scaffold",
        "issued_when_session_starts",
        "not_issued_missing_config",
    }
    REQUIRED_ALLOWLISTS = {
        "allowlists.client_surfaces": {"mobile", "mac_edge"},
        "allowlists.transports": {"mobile_push_to_talk", "livekit_webrtc"},
        "allowlists.runtimes": {"livekit_agents_sdk"},
        "allowlists.privacy_classes": {"p1_public", "p2_internal", "p3_audio", "p4_secret"},
    }
    REQUIRED_RUNTIME_INVOCATION_FIELDS = {
        "envelope_id",
        "decision_receipt_hash",
        "runtime_family",
        "capability",
        "mode",
        "limits",
        "privacy_class",
        "evidence_sink",
    }
    REQUIRED_FORBIDDEN_RUNTIME_AUTHORITY = {
        "choose_provider_or_model",
        "choose_domain_or_flow",
        "mutate_policy",
        "write_memory_directly",
        "bypass_evidence_ledger",
        "create_parallel_context_store",
    }
    REQUIRED_RUNTIME_RETURN_FIELDS = {
        "schema_version",
        "envelope_id",
        "decision_receipt_hash",
        "status",
        "artifacts",
        "metrics",
        "evidence_refs",
        "errors",
    }

    @classmethod
    def from_manifest(cls, manifest: Mapping[str, Any]) -> "AtlasVoiceRuntimeContract":
        contract = cls(manifest=manifest)
        contract.validate()

        return contract

    def validate(self) -> None:
        self._expect("schema_version", self.REQUIRED_SCHEMA)
        self._expect("status", "ready")
        self._expect("surface_id", "voice_realtime")
        self._expect("runtime_family", "python_ai_data")
        self._expect("default_providers.llm", "atlas_kernel_only")
        self._expect("session_lease.schema_version", "atlas.voice.session_lease.v1")
        token_status = self._get("session_lease.token_status")
        if token_status not in self.ALLOWED_BOOTSTRAP_TOKEN_STATUS:
            raise ContractViolation(f"session_lease.token_status is not allowed in bootstrap: {token_status!r}")
        room_prefix = self._get("session_lease.room_prefix")
        if not isinstance(room_prefix, str) or not room_prefix.startswith("atlas-voice-"):
            raise ContractViolation("session_lease.room_prefix must stay inside the atlas-voice- namespace")
        self._expect("session_lease.kernel_decision_required_per_turn", True)
        self._expect("persistence_contract.raw_audio", False)
        self._expect("persistence_contract.raw_transcript", False)
        self._expect("persistence_contract.raw_response_text", False)
        self._expect("callback_payload_schemas", REQUIRED_CALLBACK_PAYLOAD_SCHEMAS)
        self._expect("auth_contract.internal_api.middleware", "atlas.token")
        self._expect("runtime_invocation_contract.schema_version", "atlas.runtime_invocation_contract.v1")
        self._expect("runtime_invocation_contract.kernel_first", True)
        self._expect("runtime_invocation_contract.selected_runtime_family", "python_ai_data")
        self._expect("runtime_invocation_contract.runtime_id", "livekit_agents_sdk")

        for path, expected in self.REQUIRED_ALLOWLISTS.items():
            actual = set(self._get(path) or [])
            if actual != expected:
                raise ContractViolation(f"{path} expected {sorted(expected)!r}, got {sorted(actual)!r}")

        required_runtime_fields = set(self._get("runtime_invocation_contract.required_fields") or [])
        missing_runtime_fields = self.REQUIRED_RUNTIME_INVOCATION_FIELDS - required_runtime_fields
        if missing_runtime_fields:
            raise ContractViolation(f"missing runtime_invocation_contract.required_fields: {sorted(missing_runtime_fields)}")

        forbidden_runtime_authority = set(self._get("runtime_invocation_contract.forbidden_runtime_authority") or [])
        missing_forbidden_authority = self.REQUIRED_FORBIDDEN_RUNTIME_AUTHORITY - forbidden_runtime_authority
        if missing_forbidden_authority:
            raise ContractViolation(
                f"missing runtime_invocation_contract.forbidden_runtime_authority: {sorted(missing_forbidden_authority)}"
            )

        runtime_return_fields = set(self._get("runtime_invocation_contract.return_contract") or [])
        missing_runtime_return_fields = self.REQUIRED_RUNTIME_RETURN_FIELDS - runtime_return_fields
        if missing_runtime_return_fields:
            raise ContractViolation(
                f"missing runtime_invocation_contract.return_contract: {sorted(missing_runtime_return_fields)}"
            )

        required_env = set(self._get("required_env") or [])
        missing_env = self.REQUIRED_ENV - required_env
        if missing_env:
            raise ContractViolation(f"missing required_env: {sorted(missing_env)}")

        forbidden = set(self._get("forbidden_capabilities") or [])
        missing_forbidden = self.REQUIRED_FORBIDDEN - forbidden
        if missing_forbidden:
            raise ContractViolation(f"missing forbidden_capabilities: {sorted(missing_forbidden)}")

        for path in [
            "kernel.contract_url",
            "kernel.session_start_url",
            "kernel.session_end_url",
            "kernel.readiness_url",
            "kernel.rivals_url",
            "kernel.wake_word_url",
            "kernel.turn_url",
            "kernel.runtime_event_normalizer_url",
            "kernel.runtime_event_sequence_normalizer_url",
            "kernel.callbacks.turn_synthesized",
            "kernel.callbacks.turn_played",
            "kernel.callbacks.turn_interrupted",
            "kernel.callbacks.runtime_failed",
            "kernel.callbacks.provider_health_degraded",
        ]:
            self._absolute_http_url(path)

        if not isinstance(self._get("contract_hash"), str) or self._get("contract_hash") == "":
            raise ContractViolation("contract_hash is required")

    @property
    def wake_word_url(self) -> str:
        return str(self._get("kernel.wake_word_url"))

    @property
    def room_prefix(self) -> str:
        return str(self._get("session_lease.room_prefix") or "atlas-voice-")

    @property
    def livekit_url(self) -> str | None:
        value = self._get("session_lease.livekit_url")

        return str(value) if isinstance(value, str) and value != "" else None

    @property
    def session_start_url(self) -> str:
        return str(self._get("kernel.session_start_url"))

    @property
    def session_end_url(self) -> str:
        return str(self._get("kernel.session_end_url"))

    @property
    def readiness_url(self) -> str:
        return str(self._get("kernel.readiness_url"))

    @property
    def rivals_url(self) -> str:
        return str(self._get("kernel.rivals_url"))

    @property
    def turn_url(self) -> str:
        return str(self._get("kernel.turn_url"))

    @property
    def runtime_event_normalizer_url(self) -> str:
        return str(self._get("kernel.runtime_event_normalizer_url"))

    @property
    def runtime_event_sequence_normalizer_url(self) -> str:
        return str(self._get("kernel.runtime_event_sequence_normalizer_url"))

    @property
    def synthesized_url(self) -> str:
        return str(self._get("kernel.callbacks.turn_synthesized"))

    @property
    def played_url(self) -> str:
        return str(self._get("kernel.callbacks.turn_played"))

    @property
    def failed_url(self) -> str:
        return str(self._get("kernel.callbacks.runtime_failed"))

    @property
    def interrupted_url(self) -> str:
        return str(self._get("kernel.callbacks.turn_interrupted"))

    @property
    def provider_health_degraded_url(self) -> str:
        return str(self._get("kernel.callbacks.provider_health_degraded"))

    def _expect(self, path: str, expected: Any) -> None:
        actual = self._get(path)
        if actual != expected:
            raise ContractViolation(f"{path} expected {expected!r}, got {actual!r}")

    def _absolute_http_url(self, path: str) -> str:
        value = self._get(path)
        if not isinstance(value, str) or value == "" or any(ord(char) < 32 or ord(char) == 127 for char in value):
            raise ContractViolation(f"{path} must be absolute http(s) URL without control characters")

        parsed = urlparse(value)
        if parsed.scheme not in {"http", "https"} or not parsed.netloc:
            raise ContractViolation(f"{path} must be absolute http(s) URL")

        return value

    def _get(self, path: str) -> Any:
        value: Any = self.manifest
        for part in path.split("."):
            if not isinstance(value, Mapping) or part not in value:
                return None
            value = value[part]

        return value
