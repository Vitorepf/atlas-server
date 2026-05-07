from __future__ import annotations

from typing import Any, Mapping

from .livekit_sdk_event_bridge import LiveKitSdkEventBridge
from .livekit_worker import LiveKitWorkerResult
from .turn_payload import UnsafeVoicePayload


class LiveKitProductionLoopRunner:
    """Run production-shaped SDK events through the governed bridge.

    This is not a daemon and does not import LiveKit. It proves the exact path
    that the future SDK loop must use: raw SDK primitive mapping -> bridge ->
    callback router -> worker -> Kernel.
    """

    def __init__(self, bridge: LiveKitSdkEventBridge) -> None:
        self.bridge = bridge

    def run_sdk_events(self, events: list[Mapping[str, Any]]) -> Mapping[str, Any]:
        if not events:
            raise ValueError("production loop SDK events cannot be empty")

        results: list[LiveKitWorkerResult] = []
        for event in events:
            results.append(self.bridge.route_sdk_event(event))

        active_session_count = self._active_session_count()
        if active_session_count not in (0, None):
            raise UnsafeVoicePayload("production loop smoke must end with all sessions closed")

        return {
            "schema_version": "atlas.voice_realtime.production_loop_smoke.v1",
            "status": "production_loop_smoke_completed",
            "kernel_only": True,
            "mobile_first": True,
            "daemon_started": False,
            "sdk_imported": False,
            "event_count": len(events),
            "result_count": len(results),
            "active_session_count": active_session_count,
            "bridge_contract": LiveKitSdkEventBridge.contract(),
            "results": [result.log_payload() for result in results],
            "guardrails": {
                "direct_provider_call_allowed": False,
                "direct_tool_execution_allowed": False,
                "raw_audio_persistence_allowed": False,
                "access_token_log_allowed": False,
            },
        }

    def _active_session_count(self) -> int | None:
        worker = self.bridge.router.adapter.worker
        counter = getattr(worker, "active_session_count", None)
        if not callable(counter):
            return None

        return int(counter())
