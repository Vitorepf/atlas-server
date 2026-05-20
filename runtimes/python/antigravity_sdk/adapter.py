#!/usr/bin/env python3
"""Atlas Antigravity SDK adapter.

This adapter is deliberately small and contract-shaped. Laravel remains the
authority for provider/model selection, scope, evidence and completion. The
adapter only translates an already-authorized Atlas manifest into a single
Antigravity SDK agent chat call and returns structured JSON.
"""

from __future__ import annotations

import asyncio
import hashlib
import importlib
import json
import os
import sys
import time
import traceback
from pathlib import Path
from typing import Any


def _hash(value: Any) -> str:
    return hashlib.sha256(json.dumps(value, sort_keys=True, ensure_ascii=False).encode()).hexdigest()


def _string_list(value: Any) -> list[str]:
    if not isinstance(value, list):
        return []
    return [item.strip() for item in value if isinstance(item, str) and item.strip()]


def _emit(payload: dict[str, Any], exit_code: int = 0) -> None:
    sys.stdout.write(json.dumps(payload, ensure_ascii=False, separators=(",", ":")))
    sys.exit(exit_code)


def _blocked(manifest: dict[str, Any], blockers: list[str], note: str, started: float) -> None:
    _emit(
        {
            "schema_version": "atlas.provider.antigravity_sdk.invocation_result.v1",
            "provider": "antigravity_sdk",
            "model_observed": manifest.get("model"),
            "provider_called": False,
            "external_provider_call": False,
            "provider_tokens_spent": False,
            "artifacts": [],
            "changed_files": [],
            "blockers": blockers,
            "failure_type": blockers[0] if blockers else None,
            "duration_ms": int((time.time() - started) * 1000),
            "performance_signal": _performance_signal(manifest, "failed", int((time.time() - started) * 1000), blockers, []),
            "note": note,
        },
        2,
    )


def _performance_signal(
    manifest: dict[str, Any],
    status: str,
    duration_ms: int,
    blockers: list[str],
    changed_files: list[str],
) -> dict[str, Any]:
    metadata = manifest.get("metadata") if isinstance(manifest.get("metadata"), dict) else {}
    return {
        "schema_version": "atlas.provider.antigravity_sdk.performance_signal.v1",
        "provider": "antigravity_sdk",
        "model_observed": manifest.get("model"),
        "domain": metadata.get("domain") or "programming",
        "flow": metadata.get("flow") or "programming.forge",
        "task_type": metadata.get("task_type"),
        "status": status,
        "duration_ms": duration_ms,
        "changed_files_count": len(changed_files),
        "required_gates_passed": False,
        "completion_claim_promoted": False,
        "routing_effect": "none",
        "advisory_only": True,
        "blockers": blockers,
    }


async def _run_agent(module: Any, manifest: dict[str, Any]) -> str:
    agent_cls = getattr(module, "Agent", None)
    config_cls = getattr(module, "LocalAgentConfig", None)
    if agent_cls is None or config_cls is None:
        raise RuntimeError("antigravity_sdk_entrypoint_missing")

    prompt = manifest.get("prompt") if isinstance(manifest.get("prompt"), dict) else {}
    scope = manifest.get("scope_contract") if isinstance(manifest.get("scope_contract"), dict) else {}
    allowed = _string_list(scope.get("allowed_files"))
    forbidden = _string_list(scope.get("forbidden_files"))
    forbidden_actions = _string_list(scope.get("forbidden_actions"))

    system_instructions = "\n".join(
        [
            "You are an executor inside Atlas, not an authority.",
            "Atlas Decide already selected this provider/model; do not change provider policy.",
            "Never write memory, mutate policy, bypass gates, or claim completion.",
            "Operate only inside allowed files and respect forbidden files/actions.",
            "Return concise implementation output with changed files, blockers, tests, and evidence.",
            f"Allowed files: {json.dumps(allowed, ensure_ascii=False)}",
            f"Forbidden files: {json.dumps(forbidden, ensure_ascii=False)}",
            f"Forbidden actions: {json.dumps(forbidden_actions, ensure_ascii=False)}",
        ]
    )

    config_kwargs: dict[str, Any] = {"system_instructions": system_instructions}
    api_key = os.environ.get("ANTIGRAVITY_API_KEY") or os.environ.get("GEMINI_API_KEY") or os.environ.get("GOOGLE_API_KEY")
    if api_key:
        config_kwargs["api_key"] = api_key

    config = config_cls(**config_kwargs)
    task = {
        "atlas_contract": {
            "decision_receipt_id": manifest.get("decision_receipt_id"),
            "decision_receipt_hash": manifest.get("decision_receipt_hash"),
            "dispatch_id": manifest.get("dispatch_id"),
            "completion_claim_promoted": False,
        },
        "prompt": prompt,
        "scope_contract": scope,
        "completion_criteria": manifest.get("completion_criteria", []),
    }

    async with agent_cls(config) as agent:
        response = await agent.chat(json.dumps(task, ensure_ascii=False))
        text_method = getattr(response, "text", None)
        if callable(text_method):
            text = text_method()
            if hasattr(text, "__await__"):
                text = await text
            return str(text)
        return str(response)


async def _main() -> None:
    started = time.time()
    if len(sys.argv) != 2:
        _emit({"schema_version": "atlas.provider.antigravity_sdk.invocation_result.v1", "blockers": ["manifest_path_required"]}, 2)

    manifest_path = Path(sys.argv[1])
    manifest = json.loads(manifest_path.read_text())
    scope = manifest.get("scope_contract") if isinstance(manifest.get("scope_contract"), dict) else {}
    allowed = _string_list(scope.get("allowed_files"))
    forbidden = _string_list(scope.get("forbidden_files"))
    blockers: list[str] = []

    if not manifest.get("decision_receipt_id") or not manifest.get("decision_receipt_hash"):
        blockers.append("decision_receipt_required")
    if not allowed:
        blockers.append("antigravity_sdk_allowed_files_required")
    if blockers:
        _blocked(manifest, blockers, "Atlas manifest failed adapter preflight.", started)

    module_name = os.environ.get("ATLAS_ANTIGRAVITY_SDK_MODULE", "google.antigravity")
    try:
        module = importlib.import_module(module_name)
    except Exception:
        _blocked(manifest, ["antigravity_sdk_module_missing"], f"Could not import {module_name}.", started)

    try:
        output = await _run_agent(module, manifest)
        duration_ms = int((time.time() - started) * 1000)
        artifacts = [
            {
                "kind": "text",
                "name": "antigravity_sdk_response",
                "sha256": hashlib.sha256(output.encode()).hexdigest(),
            }
        ]
        _emit(
            {
                "schema_version": "atlas.provider.antigravity_sdk.invocation_result.v1",
                "provider": "antigravity_sdk",
                "model_observed": manifest.get("model"),
                "provider_called": True,
                "external_provider_call": True,
                "provider_tokens_spent": "unknown",
                "output_hash": hashlib.sha256(output.encode()).hexdigest(),
                "output_excerpt": output[:4000],
                "artifacts": artifacts,
                "changed_files": [],
                "blockers": [],
                "duration_ms": duration_ms,
                "performance_signal": _performance_signal(manifest, "succeeded", duration_ms, [], []),
                "note": "Antigravity SDK Agent completed under Atlas adapter.",
            }
        )
    except Exception as exc:
        duration_ms = int((time.time() - started) * 1000)
        failure = "antigravity_sdk_entrypoint_missing" if "entrypoint_missing" in str(exc) else "antigravity_sdk_runtime_error"
        _emit(
            {
                "schema_version": "atlas.provider.antigravity_sdk.invocation_result.v1",
                "provider": "antigravity_sdk",
                "model_observed": manifest.get("model"),
                "provider_called": False,
                "external_provider_call": False,
                "provider_tokens_spent": False,
                "artifacts": [],
                "changed_files": [],
                "blockers": [failure],
                "failure_type": failure,
                "duration_ms": duration_ms,
                "stderr_hash": hashlib.sha256(traceback.format_exc().encode()).hexdigest(),
                "performance_signal": _performance_signal(manifest, "failed", duration_ms, [failure], []),
                "note": str(exc),
            },
            2,
        )


if __name__ == "__main__":
    asyncio.run(_main())
