from __future__ import annotations

import json
from typing import Any, Callable, Mapping
from urllib import request

from .callback_payload import (
    AtlasVoiceFailurePayload,
    AtlasVoiceInterruptedPayload,
    AtlasVoicePlayedPayload,
    AtlasVoiceProviderHealthPayload,
    AtlasVoiceSynthesizedPayload,
)
from .contract import AtlasVoiceRuntimeContract
from .turn_payload import AtlasVoiceTurnPayload

PostJson = Callable[[str, Mapping[str, Any]], Mapping[str, Any]]


class AtlasKernelClient:
    def __init__(
        self,
        contract: AtlasVoiceRuntimeContract,
        atlas_token: str,
        timeout_seconds: float = 10.0,
        post_json: PostJson | None = None,
    ) -> None:
        atlas_token = atlas_token.strip()
        if atlas_token == "":
            raise ValueError("atlas_token is required")

        self.contract = contract
        self.atlas_token = atlas_token
        self.timeout_seconds = timeout_seconds
        self._transport = post_json or self._http_post_json

    def submit_turn(self, payload: Mapping[str, Any]) -> Mapping[str, Any]:
        safe_payload = AtlasVoiceTurnPayload.from_runtime_input(payload).to_kernel_payload()

        return self._transport(self.contract.turn_url, safe_payload)

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
