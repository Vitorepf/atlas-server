"""HAL agent that delegates one SWE-style task to Atlas Dev."""

import json
import os
import subprocess
import tempfile
from pathlib import Path


def run(input: dict[str, dict], **kwargs) -> dict:
    return _run(input, "atlas_dev", **kwargs)


def run_bare(input: dict[str, dict], **kwargs) -> dict:
    return _run(input, "bare", **kwargs)


def _run(input: dict[str, dict], runtime: str, **kwargs) -> dict:
    if len(input) != 1:
        raise ValueError("Atlas HAL agent requires exactly one task")
    task_id, task = next(iter(input.items()))
    model = str(kwargs.get("model_name", "openai/kimi-k2.7")).split("/", 1)[-1]
    root = Path(os.environ["ATLAS_RIVALS_ROOT"])
    with tempfile.TemporaryDirectory(prefix="rivals-hal-atlas-") as temporary:
        workspace = Path(temporary) / task["repo"].split("/")[-1]
        repo_url = "https://github.com/" + task["repo"] + ".git"
        base_commit = str(task["base_commit"])
        # Shallow+timeout: full blobless clones of django hang for 10+ minutes on
        # flaky networks and stall the uplift battery.
        clone = subprocess.run(
            [
                "git",
                "clone",
                "--filter=blob:none",
                "--no-checkout",
                repo_url,
                str(workspace),
            ],
            capture_output=True,
            text=True,
            timeout=300,
        )
        if clone.returncode != 0:
            raise RuntimeError(f"hal_git_clone_failed: {clone.stderr[-500:]}")
        fetch = subprocess.run(
            ["git", "fetch", "--depth", "1", "origin", base_commit],
            cwd=workspace,
            capture_output=True,
            text=True,
            timeout=300,
        )
        if fetch.returncode != 0:
            # Fallback: deepen enough to reach the base commit.
            fetch = subprocess.run(
                ["git", "fetch", "--filter=blob:none", "origin", base_commit],
                cwd=workspace,
                capture_output=True,
                text=True,
                timeout=600,
            )
            if fetch.returncode != 0:
                raise RuntimeError(f"hal_git_fetch_failed: {fetch.stderr[-500:]}")
        subprocess.run(
            ["git", "checkout", "--detach", base_commit],
            cwd=workspace,
            check=True,
            capture_output=True,
            text=True,
            timeout=120,
        )
        prompt_file = workspace / ".rivals_task.md"
        prompt_file.write_text(
            "Solve this issue in the checked-out repository. Make a production-quality patch and run focused tests.\n\n"
            + task["problem_statement"]
        )
        bridge = (
            "rivals-atlas-dev-bridge.php"
            if runtime == "atlas_dev"
            else "rivals-hermes-bare.php"
        )
        process = subprocess.run(
            [
                "php",
                str(root / "scripts" / bridge),
                f"--workspace={workspace}",
                f"--prompt-file={prompt_file}",
                f"--model={model}",
                "--timeout=3600",
            ],
            cwd=root,
            capture_output=True,
            text=True,
            timeout=3700,
        )
        proof_path = workspace / (
            ".rivals_atlas_dev_bridge.json"
            if runtime == "atlas_dev"
            else ".rivals_bare_provider.json"
        )
        proof = json.loads(proof_path.read_text()) if proof_path.is_file() else {}
        if process.returncode != 0 or proof.get("real_provider") is not True:
            raise RuntimeError(f"{runtime} runtime proof missing")
        patch = subprocess.run(
            ["git", "diff", "--binary"],
            cwd=workspace,
            check=True,
            capture_output=True,
            text=True,
        ).stdout
        usage = proof.get("usage") or {}

        return {
            task_id: {
                "answer": patch,
                "metrics": {
                    "total_input_tokens": int(usage.get("input_tokens") or 0),
                    "total_output_tokens": int(usage.get("output_tokens") or 0),
                    "estimated_cost": float(usage.get("cost_usd") or 0.0),
                    **({"runtime_bridge": proof} if runtime == "atlas_dev" else {}),
                },
            }
        }
