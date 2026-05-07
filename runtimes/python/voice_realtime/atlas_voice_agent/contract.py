from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Mapping

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
        self._expect("session_lease.kernel_decision_required_per_turn", True)
        self._expect("persistence_contract.raw_audio", False)
        self._expect("persistence_contract.raw_transcript", False)
        self._expect("persistence_contract.raw_response_text", False)
        self._expect("callback_payload_schemas", REQUIRED_CALLBACK_PAYLOAD_SCHEMAS)
        self._expect("auth_contract.internal_api.middleware", "atlas.token")

        for path, expected in self.REQUIRED_ALLOWLISTS.items():
            actual = set(self._get(path) or [])
            if actual != expected:
                raise ContractViolation(f"{path} expected {sorted(expected)!r}, got {sorted(actual)!r}")

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
            "kernel.callbacks.turn_synthesized",
            "kernel.callbacks.turn_played",
            "kernel.callbacks.turn_interrupted",
            "kernel.callbacks.runtime_failed",
            "kernel.callbacks.provider_health_degraded",
        ]:
            value = self._get(path)
            if not isinstance(value, str) or not value.startswith(("http://", "https://")):
                raise ContractViolation(f"{path} must be absolute http(s) URL")

        if not isinstance(self._get("contract_hash"), str) or self._get("contract_hash") == "":
            raise ContractViolation("contract_hash is required")

    @property
    def wake_word_url(self) -> str:
        return str(self._get("kernel.wake_word_url"))

    @property
    def room_prefix(self) -> str:
        return str(self._get("session_lease.room_prefix") or "atlas-voice")

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

    def _get(self, path: str) -> Any:
        value: Any = self.manifest
        for part in path.split("."):
            if not isinstance(value, Mapping) or part not in value:
                return None
            value = value[part]

        return value
