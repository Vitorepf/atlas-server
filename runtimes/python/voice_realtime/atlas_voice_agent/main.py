from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any, Mapping

from .agent_runtime import AtlasVoiceAgentRuntime
from .activation_contract import build_activation_contract
from .contract import AtlasVoiceRuntimeContract
from .kernel_client import AtlasKernelClient, PostJson
from .livekit_boundary import LiveKitAgentBoundary
from .livekit_callback_loop import inspect_callback_loop_contract
from .livekit_callback_router import LiveKitCallbackRouter
from .livekit_production_loop import build_production_loop_plan
from .livekit_production_loop_runner import LiveKitProductionLoopRunner
from .livekit_sdk_adapter import LiveKitSdkAdapter
from .livekit_sdk_event_bridge import LiveKitSdkEventBridge
from .livekit_session import LiveKitVoiceSession
from .livekit_worker import AtlasLiveKitWorker
from .livekit_runtime_entrypoint import start_livekit_agents_worker
from .mock_kernel import MockKernelTransport
from .preflight import run_runtime_preflight
from .product_loop_check import build_product_loop_check
from .sdk_status import inspect_livekit_sdk
from .settings import AtlasVoiceRuntimeSettings
from .worker_plan import build_livekit_worker_plan


def load_manifest(path: Path) -> Mapping[str, Any]:
    with path.open("r", encoding="utf-8") as handle:
        manifest = json.load(handle)

    if not isinstance(manifest, Mapping):
        raise ValueError("bootstrap manifest must be a JSON object")

    return manifest


def create_atlas_voice_agent(manifest: Mapping[str, Any]) -> AtlasVoiceRuntimeContract:
    """Factory named in the Kernel bootstrap manifest.

    LiveKit Agents SDK will call this factory later. For now it validates the
    Kernel contract and returns the typed runtime contract.
    """

    return AtlasVoiceRuntimeContract.from_manifest(manifest)


def create_atlas_voice_agent_from_settings(
    settings: AtlasVoiceRuntimeSettings,
    post_json: PostJson | None = None,
) -> LiveKitAgentBoundary:
    """Build the governed boundary that a LiveKit worker should call."""

    client = settings.build_kernel_client(post_json=post_json)

    return LiveKitAgentBoundary(AtlasVoiceAgentRuntime(client))


def start_livekit_voice_session(
    boundary: LiveKitAgentBoundary,
    event: Mapping[str, Any],
) -> LiveKitVoiceSession:
    """Start a Kernel-governed LiveKit session handle.

    The returned object may expose an in-memory access token for room join code,
    but its log payload is always token-free.
    """

    return LiveKitVoiceSession.start(boundary, event)


def create_livekit_worker(boundary: LiveKitAgentBoundary) -> AtlasLiveKitWorker:
    """Create the Kernel-governed worker adapter for LiveKit Agents SDK."""

    return AtlasLiveKitWorker(boundary)


def load_scripted_events(path: Path) -> list[Mapping[str, Any]]:
    with path.open("r", encoding="utf-8") as handle:
        payload = json.load(handle)

    events = payload.get("events") if isinstance(payload, Mapping) else payload
    if not isinstance(events, list):
        raise ValueError("scripted worker events must be a JSON array or an object with events[]")
    for event in events:
        if not isinstance(event, Mapping):
            raise ValueError("each scripted worker event must be a JSON object")

    return events


def load_callback_event(path: Path) -> Mapping[str, Any]:
    with path.open("r", encoding="utf-8") as handle:
        payload = json.load(handle)

    if not isinstance(payload, Mapping):
        raise ValueError("callback event must be a JSON object")

    return payload


def load_callback_events(path: Path) -> list[Mapping[str, Any]]:
    with path.open("r", encoding="utf-8") as handle:
        payload = json.load(handle)

    events = payload.get("events") if isinstance(payload, Mapping) else payload
    if not isinstance(events, list):
        raise ValueError("callback events must be a JSON array or an object with events[]")
    for event in events:
        if not isinstance(event, Mapping):
            raise ValueError("each callback event must be a JSON object")

    return events


def load_sdk_events(path: Path) -> list[Mapping[str, Any]]:
    with path.open("r", encoding="utf-8") as handle:
        payload = json.load(handle)

    events = payload.get("events") if isinstance(payload, Mapping) else payload
    if not isinstance(events, list):
        raise ValueError("SDK events must be a JSON array or an object with events[]")
    for event in events:
        if not isinstance(event, Mapping):
            raise ValueError("each SDK event must be a JSON object")

    return events


def load_production_promotion_review(path: Path) -> Mapping[str, Any]:
    with path.open("r", encoding="utf-8") as handle:
        payload = json.load(handle)

    if not isinstance(payload, Mapping):
        raise ValueError("production promotion review must be a JSON object")

    return payload


def main() -> int:
    parser = argparse.ArgumentParser(description="Atlas Voice Realtime runtime scaffold")
    parser.add_argument("--bootstrap", help="Path to Kernel bootstrap manifest JSON")
    parser.add_argument("--env", action="store_true", help="Load settings from ATLAS_* and LIVEKIT_* environment variables")
    parser.add_argument("--env-file", help="Load settings from a dotenv-style file, with process env overriding file values")
    parser.add_argument("--check", action="store_true", help="Validate manifest and exit")
    parser.add_argument("--sdk-check", action="store_true", help="Inspect optional LiveKit Agents SDK availability and exit")
    parser.add_argument("--preflight", action="store_true", help="Validate env/bootstrap/SDK readiness without starting a worker")
    parser.add_argument("--require-sdk", action="store_true", help="Make --preflight fail if LiveKit Agents SDK is missing")
    parser.add_argument("--worker-plan", action="store_true", help="Describe fail-closed LiveKit worker activation plan and exit")
    parser.add_argument("--production-loop-plan", action="store_true", help="Describe real LiveKit SDK loop wiring plan without starting a worker")
    parser.add_argument("--product-loop-check", action="store_true", help="Aggregate callback, SDK loop and worker-start gates without starting a daemon")
    parser.add_argument("--activation-contract", action="store_true", help="Publish full preflight + worker-plan activation contract and exit")
    parser.add_argument("--start-worker", action="store_true", help="Attempt governed LiveKit worker startup; returns blocked JSON until all gates pass")
    parser.add_argument("--callback-loop-wired", action="store_true", help="Declare the governed callback router is wired for fail-closed activation checks")
    parser.add_argument("--production-sdk-loop-wired", action="store_true", help="Declare the real LiveKit SDK loop is wired for fail-closed production checks")
    parser.add_argument("--production-promotion-approved", action="store_true", help="Legacy declaration only; a review file is required for actual promotion approval")
    parser.add_argument("--production-promotion-review-file", help="Path to human production-promotion review receipt JSON")
    parser.add_argument("--scripted-events", help="Run a token-safe scripted worker event JSON file and exit")
    parser.add_argument("--callback-event", help="Route one normalized LiveKit SDK callback JSON file and exit")
    parser.add_argument("--callback-events", help="Route a normalized LiveKit SDK callback sequence JSON file and exit")
    parser.add_argument("--sdk-events", help="Run production-shaped LiveKit SDK primitive events through the governed bridge and exit")
    parser.add_argument("--callback-loop-check", action="store_true", help="Inspect callback translation layer readiness without starting a worker")
    parser.add_argument("--mock-kernel", action="store_true", help="Run scripted events against a deterministic local mock Kernel")
    args = parser.parse_args()

    settings = AtlasVoiceRuntimeSettings.from_env_file(Path(args.env_file)) if args.env_file else None
    if settings is None and args.env:
        settings = AtlasVoiceRuntimeSettings.from_env()
    boundary_created = False
    mock_transport: MockKernelTransport | None = None
    if settings is not None:
        boundary = create_atlas_voice_agent_from_settings(settings)
        contract = boundary.runtime.client.contract
        boundary_created = True
    else:
        if not args.bootstrap:
            parser.error("--bootstrap is required unless --env is used")
        contract = create_atlas_voice_agent(load_manifest(Path(args.bootstrap)))
        if args.mock_kernel:
            mock_transport = MockKernelTransport()
            client = AtlasKernelClient(
                contract=contract,
                atlas_token="mock-kernel-token",
                post_json=mock_transport.post_json,
            )
            boundary = LiveKitAgentBoundary(AtlasVoiceAgentRuntime(client))
            boundary_created = True

    if args.check:
        print(json.dumps({
            "status": "ready",
            "schema_version": "atlas.voice_realtime.runtime_check.v1",
            "session_start_url": contract.session_start_url,
            "session_end_url": contract.session_end_url,
            "readiness_url": contract.readiness_url,
            "rivals_url": contract.rivals_url,
            "wake_word_url": contract.wake_word_url,
            "turn_url": contract.turn_url,
            "room_prefix": contract.room_prefix,
            "livekit_url": contract.livekit_url,
            "kernel_only": True,
            "settings_loaded": settings is not None,
            "boundary_created": boundary_created,
            "mock_kernel": args.mock_kernel,
            "worker_adapter": "atlas_livekit_worker",
            "worker_fails_closed": True,
        }, indent=2))

    if args.sdk_check:
        print(json.dumps(inspect_livekit_sdk(contract), indent=2))

    if args.preflight:
        print(json.dumps(run_runtime_preflight(
            env_file=Path(args.env_file) if args.env_file else None,
            env=None if args.env else {},
            require_sdk=args.require_sdk,
        ), indent=2))

    if args.worker_plan:
        print(json.dumps(build_livekit_worker_plan(
            contract,
            settings_loaded=settings is not None,
            boundary_created=boundary_created,
            mock_kernel=args.mock_kernel,
            callback_loop_wired=args.callback_loop_wired,
        ), indent=2))

    if args.production_loop_plan:
        print(json.dumps(build_production_loop_plan(
            contract,
            settings_loaded=settings is not None,
            boundary_created=boundary_created,
            production_sdk_loop_wired=args.production_sdk_loop_wired,
        ), indent=2))

    if args.product_loop_check:
        production_promotion_review = (
            load_production_promotion_review(Path(args.production_promotion_review_file))
            if args.production_promotion_review_file else None
        )
        print(json.dumps(build_product_loop_check(
            contract,
            env_file=Path(args.env_file) if args.env_file else None,
            env=None if args.env else {},
            settings_loaded=settings is not None,
            boundary_created=boundary_created,
            mock_kernel=args.mock_kernel,
            production_promotion_review=production_promotion_review,
        ), indent=2))

    if args.activation_contract:
        print(json.dumps(build_activation_contract(
            contract,
            env_file=Path(args.env_file) if args.env_file else None,
            env=None if args.env else {},
            settings_loaded=settings is not None,
            boundary_created=boundary_created,
            mock_kernel=args.mock_kernel,
            callback_loop_wired=args.callback_loop_wired,
        ), indent=2))

    if args.start_worker:
        production_promotion_review = (
            load_production_promotion_review(Path(args.production_promotion_review_file))
            if args.production_promotion_review_file else None
        )
        print(json.dumps(start_livekit_agents_worker(
            contract,
            env_file=Path(args.env_file) if args.env_file else None,
            env=None if args.env else {},
            settings_loaded=settings is not None,
            boundary_created=boundary_created,
            mock_kernel=args.mock_kernel,
            callback_loop_wired=args.callback_loop_wired,
            production_sdk_loop_wired=args.production_sdk_loop_wired,
            production_promotion_approved=args.production_promotion_approved,
            production_promotion_review=production_promotion_review,
        ), indent=2))

    if args.callback_loop_check:
        print(json.dumps(inspect_callback_loop_contract(
            production_sdk_loop_wired=args.production_sdk_loop_wired,
        ), indent=2))

    if args.scripted_events:
        if settings is None and mock_transport is None:
            parser.error("--scripted-events requires --env, --env-file or --mock-kernel so the worker can call the Kernel")
        worker = create_livekit_worker(boundary)
        results = worker.process_scripted_events(load_scripted_events(Path(args.scripted_events)))
        print(json.dumps({
            "status": "scripted_worker_completed",
            "schema_version": "atlas.voice_realtime.scripted_worker.v1",
            "mock_kernel": mock_transport is not None,
            "mock_call_count": mock_transport.call_count() if mock_transport is not None else None,
            "result_count": len(results),
            "active_session_count": worker.active_session_count(),
            "results": [result.log_payload() for result in results],
        }, indent=2))

    if args.callback_event:
        if settings is None and mock_transport is None:
            parser.error("--callback-event requires --env, --env-file or --mock-kernel so the router can call the Kernel")
        worker = create_livekit_worker(boundary)
        router = LiveKitCallbackRouter(LiveKitSdkAdapter(worker))
        result = router.route(load_callback_event(Path(args.callback_event)))
        print(json.dumps({
            "status": "callback_routed",
            "schema_version": "atlas.voice_realtime.callback_route.v1",
            "mock_kernel": mock_transport is not None,
            "mock_call_count": mock_transport.call_count() if mock_transport is not None else None,
            "active_session_count": worker.active_session_count(),
            "result": result.log_payload(),
        }, indent=2))

    if args.callback_events:
        if settings is None and mock_transport is None:
            parser.error("--callback-events requires --env, --env-file or --mock-kernel so the router can call the Kernel")
        worker = create_livekit_worker(boundary)
        router = LiveKitCallbackRouter(LiveKitSdkAdapter(worker))
        results = [router.route(event) for event in load_callback_events(Path(args.callback_events))]
        print(json.dumps({
            "status": "callback_sequence_routed",
            "schema_version": "atlas.voice_realtime.callback_sequence.v1",
            "mock_kernel": mock_transport is not None,
            "mock_call_count": mock_transport.call_count() if mock_transport is not None else None,
            "result_count": len(results),
            "active_session_count": worker.active_session_count(),
            "results": [result.log_payload() for result in results],
        }, indent=2))

    if args.sdk_events:
        if settings is None and mock_transport is None:
            parser.error("--sdk-events requires --env, --env-file or --mock-kernel so the runner can call the Kernel")
        worker = create_livekit_worker(boundary)
        router = LiveKitCallbackRouter(LiveKitSdkAdapter(worker))
        bridge = LiveKitSdkEventBridge(router)
        payload = LiveKitProductionLoopRunner(bridge).run_sdk_events(load_sdk_events(Path(args.sdk_events)))
        print(json.dumps({
            **payload,
            "mock_kernel": mock_transport is not None,
            "mock_call_count": mock_transport.call_count() if mock_transport is not None else None,
        }, indent=2))

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
