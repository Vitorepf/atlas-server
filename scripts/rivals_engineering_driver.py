#!/usr/bin/env python3
"""Materialize and score one native engineering benchmark unit.

The model-facing wrapper is deliberately outside this file. This driver only
reads the upstream dataset, prepares the exact artifact contract and invokes
the upstream-native scorer. It never calls a provider.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
from pathlib import Path


def load_case(path: Path, suite: str) -> dict:
    case = json.loads(path.read_text())
    if case.get("suite_id") != suite or not case.get("case_id"):
        raise RuntimeError(f"{suite}_engineering_native_case_invalid")
    return case


def archbench_row(case: dict) -> dict:
    dataset = Path.home() / ".cache" / "archbench" / "adr" / "0_shot.csv"
    if not dataset.is_file():
        raise RuntimeError("archbench_adr_dataset_missing")
    with dataset.open(encoding="utf-8") as stream:
        rows = list(csv.DictReader(stream))
    index = int(case.get("native_index", -1))
    if index < 0 or index >= len(rows):
        raise RuntimeError("archbench_adr_case_index_out_of_range")
    expected_id = f"adr:{index}"
    if case.get("native_task_id") != expected_id:
        raise RuntimeError("archbench_adr_native_task_mismatch")
    return rows[index]


def prepare_archbench(case: dict, workspace: Path) -> dict:
    row = archbench_row(case)
    workspace.mkdir(parents=True, exist_ok=True)
    (workspace / "decision.md").write_text("")
    material = {
        "schema_version": "atlas.rivals2.engineering_case_material.v1",
        "suite_id": "archbench",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "context": row["context"],
    }
    (workspace / "case_material.json").write_text(
        json.dumps(material, indent=2, ensure_ascii=False)
    )
    prompt = (
        "You are solving one official ArchBench ADR instance.\n\n"
        "Read case_material.json. Use its `context` field as the ADR Context. "
        "Write only the proposed Decision content to decision.md. The file must "
        "be non-empty, specific to the context, and must not include a `## Decision` "
        "heading. Do not edit case_material.json.\n"
    )
    (workspace / ".rivals_task.md").write_text(prompt)
    return {
        "suite_id": "archbench",
        "case_id": case["case_id"],
        "artifact_target": str(workspace / "decision.md"),
        "prompt_file": str(workspace / ".rivals_task.md"),
    }


def evaluate_archbench(case: dict, workspace: Path, artifact: Path) -> dict:
    from archbench.tasks.adr.grading import compute_adr_metrics

    material = json.loads((workspace / "case_material.json").read_text())
    if (
        material.get("case_id") != case["case_id"]
        or material.get("native_task_id") != case["native_task_id"]
    ):
        raise RuntimeError("archbench_adr_material_identity_mismatch")
    prediction = (workspace / "decision.md").read_text().strip()
    reference = str(archbench_row(case).get("decision") or "")
    metrics = compute_adr_metrics(
        prediction=prediction,
        reference=reference,
        compute_bertscore=False,
    )
    valid = bool(prediction) and any(
        isinstance(metrics.get(name), (int, float))
        for name in ("rouge1", "rouge2", "rougeL", "bleu", "meteor")
    )
    score = metrics.get("rougeL")
    native = {
        "schema_version": "archbench.native_unit.v1",
        "suite_id": "archbench",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "valid_result": valid,
        "measurement_type": "continuous",
        "score_metric": "rougeL",
        "score": score,
        "metrics": metrics,
        "prediction": prediction,
        "reference_sha256": hashlib.sha256(reference.encode()).hexdigest(),
        "bertscore_omitted": True,
        "bertscore_omitted_reason": "native_profile_without_torch",
    }
    artifact.parent.mkdir(parents=True, exist_ok=True)
    artifact.write_text(json.dumps(native, indent=2, ensure_ascii=False))
    return {
        "valid_result": valid,
        "failure_reason": None if valid else "archbench_prediction_empty_or_metrics_missing",
        "measurement_type": "continuous",
        "score_metric": "rougeL",
        "score": score,
        "native_metrics": metrics,
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("action", choices=["prepare", "evaluate"])
    parser.add_argument("--suite", required=True)
    parser.add_argument("--case-file", type=Path, required=True)
    parser.add_argument("--repo-root", type=Path, required=True)
    parser.add_argument("--workspace", type=Path, required=True)
    parser.add_argument("--artifact", type=Path)
    args = parser.parse_args()
    case = load_case(args.case_file, args.suite)

    if args.suite != "archbench":
        raise RuntimeError(f"{args.suite}_engineering_native_driver_not_implemented")
    if args.action == "prepare":
        result = prepare_archbench(case, args.workspace)
    else:
        if args.artifact is None:
            raise RuntimeError("engineering_native_artifact_path_missing")
        result = evaluate_archbench(case, args.workspace, args.artifact)
    print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()
