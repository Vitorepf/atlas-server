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
from .session_lease import AtlasVoiceSessionLease
from .session_payload import AtlasVoiceSessionPayload
from .turn_payload import AtlasVoiceTurnPayload
from .wake_word_payload import AtlasVoiceWakeWordPayload

PostJson = Callable[[str, Mapping[str, Any]], Mapping[str, Any]]
GetJson = Callable[[str, Mapping[str, Any]], Mapping[str, Any]]


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

        return self._get_transport(self.contract.readiness_url, {"hours": bounded_hours})

    def rivals(self, hours: int = 24) -> Mapping[str, Any]:
        bounded_hours = max(1, min(8760, int(hours)))

        return self._get_transport(self.contract.rivals_url, {"hours": bounded_hours})

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
