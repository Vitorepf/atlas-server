from __future__ import annotations

import json
from typing import Any, Callable, Mapping
from urllib import parse, request

from .callback_payload import (
    AtlasVoiceFailurePayload,
    AtlasVoiceInterruptedPayload,
    AtlasVoicePlayedPayload,
    AtlasVoiceProviderHealthPayload,
    AtlasVoiceSynthesizedPayload,
)
from .contract import AtlasVoiceRuntimeContract
from .payload_safety import reject_forbidden_keys_recursive
from .product_loop_packet import validate_product_loop_check
from .promotion_review_packet import validate_promotion_review_packet
from .session_lease import AtlasVoiceSessionLease
from .session_payload import AtlasVoiceSessionPayload
from .sdk_status import validate_dependency_install_plan
from .status_packet import validate_readiness_packet, validate_rivals_packet
from .token_issuer_contract import validate_token_issuer_plan, validate_token_issuer_smoke
from .turn_payload import AtlasVoiceTurnPayload, UnsafeVoicePayload
from .wake_word_payload import AtlasVoiceWakeWordPayload

PostJson = Callable[[str, Mapping[str, Any]], Mapping[str, Any]]
GetJson = Callable[[str, Mapping[str, Any]], Mapping[str, Any]]


FORBIDDEN_RUNTIME_EVENT_KEYS = {
    "access_token",
    "api_key",
    "api_secret",
    "audio",
    "audio_bytes",
    "audio_raw",
    "livekit_token",
    "llm_provider",
    "pcm",
    "provider_api_key",
    "raw_audio",
    "raw_audio_bytes",
    "raw_response_text",
    "response_text",
    "token",
    "tool_args",
    "tool_call",
    "tts_text",
    "wav",
}


class AtlasKernelClient:
    def __init__(
        self,
        contract: AtlasVoiceRuntimeContract,
        atlas_token: str,
        timeout_seconds: float = 10.0,
        post_json: PostJson | None = None,
        get_json: GetJson | None = None,
    ) -> None:
        atlas_token = atlas_token.strip()
        if atlas_token == "":
            raise ValueError("atlas_token is required")

        self.contract = contract
        self.atlas_token = atlas_token
        self.timeout_seconds = timeout_seconds
        self._transport = post_json or self._http_post_json
        self._get_transport = get_json or self._http_get_json

    def readiness(self, hours: int = 24) -> Mapping[str, Any]:
        bounded_hours = max(1, min(8760, int(hours)))

        response = self._get_transport(self.contract.readiness_url, {"hours": bounded_hours})

        return validate_readiness_packet(response)

    def rivals(
        self,
        hours: int = 24,
        *,
        require_sdk: bool = False,
        callback_loop_wired: bool = False,
        production_sdk_loop_wired: bool = False,
    ) -> Mapping[str, Any]:
        bounded_hours = max(1, min(8760, int(hours)))

        response = self._get_transport(
            self.contract.rivals_url,
            {
                "runtime": "livekit_agents_sdk",
                "hours": bounded_hours,
                "require_sdk": 1 if require_sdk else 0,
                "callback_loop_wired": 1 if callback_loop_wired else 0,
                "production_sdk_loop_wired": 1 if production_sdk_loop_wired else 0,
            },
        )

        return validate_rivals_packet(response)

    def production_promotion_review_packet(
        self,
        hours: int = 24,
        *,
        callback_loop_wired: bool = False,
        production_sdk_loop_wired: bool = False,
    ) -> Mapping[str, Any]:
        bounded_hours = max(1, min(8760, int(hours)))

        response = self._get_transport(
            self.contract.runtime_promotion_review_packet_url,
            {
                "runtime": "livekit_agents_sdk",
                "hours": bounded_hours,
                "callback_loop_wired": 1 if callback_loop_wired else 0,
                "production_sdk_loop_wired": 1 if production_sdk_loop_wired else 0,
            },
        )

        return validate_promotion_review_packet(response)

    def product_loop_check(
        self,
        *,
        callback_loop_wired: bool = False,
        production_sdk_loop_wired: bool = False,
    ) -> Mapping[str, Any]:
        response = self._get_transport(
            self.contract.runtime_product_loop_check_url,
            {
                "runtime": "livekit_agents_sdk",
                "callback_loop_wired": 1 if callback_loop_wired else 0,
                "production_sdk_loop_wired": 1 if production_sdk_loop_wired else 0,
            },
        )

        return validate_product_loop_check(response)

    def dependency_install_plan(self) -> Mapping[str, Any]:
        response = self._get_transport(
            self.contract.runtime_dependency_install_plan_url,
            {"runtime": "livekit_agents_sdk"},
        )

        return validate_dependency_install_plan(response)

    def token_issuer_plan(self) -> Mapping[str, Any]:
        response = self._get_transport(
            self.contract.runtime_token_issuer_plan_url,
            {"runtime": "livekit_agents_sdk"},
        )

        return validate_token_issuer_plan(response)

    def token_issuer_smoke(self, *, ephemeral_test_config: bool = False) -> Mapping[str, Any]:
        response = self._get_transport(
            self.contract.runtime_token_issuer_smoke_url,
            {
                "runtime": "livekit_agents_sdk",
                "ephemeral_test_config": 1 if ephemeral_test_config else None,
            },
        )

        return validate_token_issuer_smoke(response)

    def normalize_runtime_event(self, event: Mapping[str, Any]) -> Mapping[str, Any]:
        self._assert_safe_runtime_event(event, label="runtime event")

        return self._transport(self.contract.runtime_event_normalizer_url, {"event": event})

    def normalize_runtime_event_sequence(self, events: list[Mapping[str, Any]]) -> Mapping[str, Any]:
        if not events:
            raise UnsafeVoicePayload("runtime event sequence cannot be empty")

        for event in events:
            self._assert_safe_runtime_event(event, label="runtime event sequence")

        return self._transport(self.contract.runtime_event_sequence_normalizer_url, {"events": events})

    def start_session(self, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        safe_payload = AtlasVoiceSessionPayload.from_runtime_event(payload).to_kernel_payload()

        return self._transport(self.contract.session_start_url, safe_payload)

    def start_session_lease(self, payload: Mapping[str, Any]) -> AtlasVoiceSessionLease:
        return AtlasVoiceSessionLease.from_kernel_response(self.start_session(payload))

    def end_session(self, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        safe_payload = AtlasVoiceSessionPayload.from_runtime_event(payload).to_kernel_payload()

        return self._transport(self.contract.session_end_url, safe_payload)

    def submit_turn(self, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        safe_payload = AtlasVoiceTurnPayload.from_runtime_input(payload).to_kernel_payload()

        return self._transport(self.contract.turn_url, safe_payload)

    def report_wake_word(self, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        safe_payload = AtlasVoiceWakeWordPayload.from_runtime_event(payload).to_kernel_payload()

        return self._transport(self.contract.wake_word_url, safe_payload)

    def report_synthesized(self, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        safe_payload = AtlasVoiceSynthesizedPayload.from_runtime_output(payload).to_kernel_payload()

        return self._transport(self.contract.synthesized_url, safe_payload)

    def report_played(self, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        safe_payload = AtlasVoicePlayedPayload.from_runtime_output(payload).to_kernel_payload()

        return self._transport(self.contract.played_url, safe_payload)

    def report_interrupted(self, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        safe_payload = AtlasVoiceInterruptedPayload.from_runtime_output(payload).to_kernel_payload()

        return self._transport(self.contract.interrupted_url, safe_payload)

    def report_failed(self, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        safe_payload = AtlasVoiceFailurePayload.from_runtime_output(payload).to_kernel_payload()

        return self._transport(self.contract.failed_url, safe_payload)

    def report_provider_health_degraded(self, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        safe_payload = AtlasVoiceProviderHealthPayload.from_runtime_output(payload).to_kernel_payload()

        return self._transport(self.contract.provider_health_degraded_url, safe_payload)

    def _http_post_json(self, url: str, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        body = json.dumps(payload).encode("utf-8")
        http_request = request.Request(
            url,
            data=body,
            headers={
                "Content-Type": "application/json",
                "Accept": "application/json",
                "X-Atlas-Token": self.atlas_token,
            },
            method="POST",
        )
        with request.urlopen(http_request, timeout=self.timeout_seconds) as response:
            data = response.read().decode("utf-8")

        decoded = json.loads(data)
        if not isinstance(decoded, Mapping):
            raise RuntimeError("Kernel returned non-object JSON")

        return decoded

    def _assert_safe_runtime_event(self, event: Mapping[str, Any], *, label: str) -> None:
        reject_forbidden_keys_recursive(event, FORBIDDEN_RUNTIME_EVENT_KEYS, label=label)

    def _http_get_json(self, url: str, query: Mapping[str, Any]) -> Mapping[str, Any]:
        encoded = parse.urlencode({key: value for key, value in query.items() if value is not None})
        full_url = f"{url}?{encoded}" if encoded else url
        http_request = request.Request(
            full_url,
            headers={
                "Accept": "application/json",
                "X-Atlas-Token": self.atlas_token,
            },
            method="GET",
        )
        with request.urlopen(http_request, timeout=self.timeout_seconds) as response:
            data = response.read().decode("utf-8")

        decoded = json.loads(data)
        if not isinstance(decoded, Mapping):
            raise RuntimeError("Kernel returned non-object JSON")

        return decoded
