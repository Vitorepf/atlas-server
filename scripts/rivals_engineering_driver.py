#!/usr/bin/env python3
"""Materialize and score one native engineering benchmark unit.

The model-facing wrapper is deliberately outside this file. This driver only
reads the upstream dataset, prepares the exact artifact contract and invokes
the upstream-native scorer. It never calls a provider.
"""

from __future__ import annotations

import argparse
import contextlib
import csv
import hashlib
import io
import json
import os
import re
import shutil
import subprocess
import sys
import urllib.request
from pathlib import Path
from types import SimpleNamespace


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
    prediction = read_text_or_empty(workspace / "decision.md").strip()
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


def jsonl_row(path: Path, index: int) -> dict:
    if index < 0:
        raise RuntimeError("engineering_native_case_index_out_of_range")
    with path.open(encoding="utf-8") as stream:
        for current, line in enumerate(stream):
            if current == index:
                row = json.loads(line)
                if not isinstance(row, dict):
                    break
                return row
    raise RuntimeError("engineering_native_case_index_out_of_range")


def read_text_or_empty(path: Path) -> str:
    return path.read_text() if path.is_file() else ""


def write_material_and_prompt(
    workspace: Path,
    material: dict,
    prompt: str,
    target: str,
    initial: str = "",
) -> dict:
    workspace.mkdir(parents=True, exist_ok=True)
    (workspace / "case_material.json").write_text(
        json.dumps(material, indent=2, ensure_ascii=False)
    )
    if initial:
        (workspace / target).write_text(initial)
    # MATERIAL DO CASO EMBUTIDO NO PROMPT (forense 21/07, o defeito-mãe das
    # nativas): o prompt dizia "leia case_material.json", mas só o braço CRU
    # pode ler (hermes agêntico com tools); o braço Atlas roda oneshot com
    # allowed_tools=[] — o modelo NUNCA via o problema. Resultado provado em
    # testeval: stubs de 167 bytes e chutes do clássico (tests de isMatch/LC10
    # BYTE-IDÊNTICOS em casos diferentes = determinismo de prompt-cego, não
    # cache) julgados contra threeSum → 0 fabricado. Embutir o material no
    # prompt COMPARTILHADO dá o problema aos DOIS braços igualmente — o cru
    # mantém seu harness agêntico; nenhum braço fica vendado.
    inline = (
        "\n\n# Case material (inline; identical content lives in case_material.json)\n"
        "```json\n" + json.dumps(material, indent=2, ensure_ascii=False) + "\n```\n"
    )
    (workspace / ".rivals_task.md").write_text(prompt + inline)
    return {
        "suite_id": material["suite_id"],
        "case_id": material["case_id"],
        "artifact_target": str(workspace / target),
        "prompt_file": str(workspace / ".rivals_task.md"),
    }


def private_reference_path(workspace: Path, suite: str, case_id: str) -> Path:
    return workspace.parent / f".{suite}_{case_id}_upstream.json"


def write_private_reference(
    workspace: Path,
    suite: str,
    case_id: str,
    payload: dict,
) -> None:
    private_reference_path(workspace, suite, case_id).write_text(
        json.dumps(payload, ensure_ascii=False)
    )


def read_private_reference(
    workspace: Path,
    suite: str,
    case_id: str,
) -> dict | None:
    path = private_reference_path(workspace, suite, case_id)
    if not path.is_file():
        return None
    payload = json.loads(path.read_text())
    return payload if isinstance(payload, dict) else None


def cruxeval_row(case: dict, repo_root: Path) -> dict:
    row = jsonl_row(repo_root / "data" / "cruxeval.jsonl", int(case["native_index"]))
    if row.get("id") != case.get("native_task_id"):
        raise RuntimeError("cruxeval_native_task_mismatch")
    return row


def prepare_cruxeval(case: dict, repo_root: Path, workspace: Path) -> dict:
    row = cruxeval_row(case, repo_root)
    material = {
        "schema_version": "atlas.rivals2.engineering_case_material.v1",
        "suite_id": "cruxeval",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "mode": "output",
        "code": row["code"],
        "input": row["input"],
    }
    prompt = (
        "Solve one official CRUXEval-O instance. Read case_material.json. "
        "Determine the exact Python value returned when its `code` is called "
        "with `input`. Write only one valid Python expression representing that "
        "value to answer.txt; no prose or markdown.\n"
    )
    return write_material_and_prompt(workspace, material, prompt, "answer.txt")


def evaluate_cruxeval(
    case: dict, repo_root: Path, workspace: Path, artifact: Path
) -> dict:
    row = cruxeval_row(case, repo_root)
    answer = read_text_or_empty(workspace / "answer.txt").strip()
    sys.path.insert(0, str(repo_root / "evaluation"))
    from utils_general import evaluate_score

    execution = evaluate_score(
        ([answer], (row["code"], row["input"], row["output"]), "output")
    )
    passed = execution == [True]
    native = {
        "schema_version": "cruxeval.native_unit.v1",
        "suite_id": "cruxeval",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "mode": "output",
        "valid_result": bool(answer) and len(execution) == 1,
        "score_metric": "pass@1",
        "score": 1.0 if passed else 0.0,
        "execution_results": execution,
        "prediction": answer,
        "reference_sha256": hashlib.sha256(row["output"].encode()).hexdigest(),
    }
    artifact.parent.mkdir(parents=True, exist_ok=True)
    artifact.write_text(json.dumps(native, indent=2, ensure_ascii=False))
    return {
        "valid_result": native["valid_result"],
        "benchmark_pass": passed,
        "failure_reason": None if passed else "cruxeval_output_mismatch",
        "measurement_type": "binary",
        "score_metric": "pass@1",
        "score": native["score"],
        "native_metrics": {"pass@1": native["score"]},
    }


def evalplus_problem(case: dict) -> dict:
    from evalplus.data import get_human_eval_plus

    problems = get_human_eval_plus()
    problem = problems.get(case.get("native_task_id"))
    if not isinstance(problem, dict):
        raise RuntimeError("evalplus_native_task_missing")
    return problem


def prepare_evalplus(case: dict, workspace: Path) -> dict:
    problem = evalplus_problem(case)
    prompt_text = str(problem["prompt"])
    material = {
        "schema_version": "atlas.rivals2.engineering_case_material.v1",
        "suite_id": "evalplus",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "dataset": "humaneval",
        "entry_point": problem["entry_point"],
        "prompt_sha256": hashlib.sha256(prompt_text.encode()).hexdigest(),
    }
    prompt = (
        "Solve one official HumanEval+ task. Complete solution.py in place. "
        "Keep the provided signature and docstring, implement the function, and "
        "leave the file as executable Python. Do not add tests or markdown.\n"
    )
    return write_material_and_prompt(
        workspace, material, prompt, "solution.py", prompt_text
    )


def evaluate_evalplus(case: dict, workspace: Path, artifact: Path) -> dict:
    from evalplus.data import get_human_eval_plus, get_human_eval_plus_hash
    from evalplus.evaluate import check_correctness, get_groundtruth

    problems = get_human_eval_plus()
    problem = evalplus_problem(case)
    expected = get_groundtruth(problems, get_human_eval_plus_hash(), [])
    solution = (workspace / "solution.py").read_text()
    result = check_correctness(
        "humaneval",
        0,
        problem,
        solution,
        expected[case["native_task_id"]],
        min_time_limit=1.0,
        gt_time_limit_factor=4.0,
    )
    base_status = result["base"][0]
    plus_status = result["plus"][0]
    valid = base_status in {"pass", "fail", "timeout"} and plus_status in {
        "pass",
        "fail",
        "timeout",
    }
    passed = base_status == "pass" and plus_status == "pass"
    native = {
        "schema_version": "evalplus.native_unit.v1",
        "suite_id": "evalplus",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "valid_result": valid,
        "score_metric": "plus_pass@1",
        "score": 1.0 if passed else 0.0,
        "base_status": base_status,
        "plus_status": plus_status,
        "solution_sha256": hashlib.sha256(solution.encode()).hexdigest(),
    }
    artifact.parent.mkdir(parents=True, exist_ok=True)
    artifact.write_text(json.dumps(native, indent=2, ensure_ascii=False))
    return {
        "valid_result": valid,
        "benchmark_pass": passed,
        "failure_reason": None if passed else f"evalplus_base={base_status};plus={plus_status}",
        "measurement_type": "binary",
        "score_metric": "plus_pass@1",
        "score": native["score"],
        "native_metrics": {
            "base_pass@1": 1.0 if base_status == "pass" else 0.0,
            "plus_pass@1": 1.0 if plus_status == "pass" else 0.0,
        },
    }


def reval_rows(case: dict, repo_root: Path) -> tuple[dict, dict, dict]:
    index = int(case["native_index"])
    data = jsonl_row(repo_root / "data" / "DREval_data.jsonl", index)
    task = jsonl_row(repo_root / "data" / "DREval_tasks.jsonl", index)
    if task.get("idx") != index or case.get("native_task_id") != f"reval:{index}":
        raise RuntimeError("reval_native_task_mismatch")
    pair = task["tasks"][0]
    target = pair["task"][0]
    return data, pair, target


def prepare_reval(case: dict, repo_root: Path, workspace: Path) -> dict:
    data, pair, target = reval_rows(case, repo_root)
    code = str(data["code"])
    input_value = str(data["inputs"][pair["input_idx"]])
    line = int(target["lineno"])
    material = {
        "schema_version": "atlas.rivals2.engineering_case_material.v1",
        "suite_id": "reval",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "task": "coverage",
        "code": code,
        "invocation": f'{data["entry_point"]}{input_value[:-2]})',
        "line": line,
        "codeline": code.splitlines()[line - 1],
    }
    prompt = (
        "Solve one official REval coverage query. Read case_material.json. "
        "Decide whether the specified source line executes for the invocation. "
        "Write exactly YES or NO to answer.txt; no prose or markdown.\n"
    )
    return write_material_and_prompt(workspace, material, prompt, "answer.txt")


def evaluate_reval(
    case: dict, repo_root: Path, workspace: Path, artifact: Path
) -> dict:
    data, pair, target = reval_rows(case, repo_root)
    sys.path.insert(0, str(repo_root))
    from dynamics import FunctionFactory, Sandbox
    from evaluation import Coverage

    function = FunctionFactory.create(data["entry_point"], data["code"])
    _, states = Sandbox(function).run(*eval(data["inputs"][pair["input_idx"]]))
    actual = states.get_coverage(int(target["lineno"]) - 1)
    answer = read_text_or_empty(workspace / "answer.txt").strip()
    parser = type("DirectCoverageParser", (), {"prompt_type": "direct"})()
    predicted = Coverage._postprocess(parser, answer)
    valid = answer != ""
    passed = valid and predicted == actual
    native = {
        "schema_version": "reval.native_unit.v1",
        "suite_id": "reval",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "valid_result": valid,
        "score_metric": "accuracy",
        "score": 1.0 if passed else 0.0,
        "predicted": predicted,
        "expected": actual,
    }
    artifact.parent.mkdir(parents=True, exist_ok=True)
    artifact.write_text(json.dumps(native, indent=2, ensure_ascii=False))
    return {
        "valid_result": native["valid_result"],
        "benchmark_pass": passed,
        "failure_reason": (
            None
            if passed
            else "reval_empty_answer"
            if not valid
            else "reval_coverage_mismatch"
        ),
        "measurement_type": "binary",
        "score_metric": "accuracy",
        "score": native["score"],
        "native_metrics": {"accuracy": native["score"]},
    }


def classeval_row(case: dict, repo_root: Path) -> dict:
    rows = json.loads((repo_root / "data" / "ClassEval_data.json").read_text())
    index = int(case["native_index"])
    if index < 0 or index >= len(rows):
        raise RuntimeError("classeval_case_index_out_of_range")
    row = rows[index]
    if row.get("task_id") != case.get("native_task_id"):
        raise RuntimeError("classeval_native_task_mismatch")
    return row


def prepare_classeval(case: dict, repo_root: Path, workspace: Path) -> dict:
    row = classeval_row(case, repo_root)
    material = {
        "schema_version": "atlas.rivals2.engineering_case_material.v1",
        "suite_id": "classeval",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "class_name": row["class_name"],
        "class_description": row["class_description"],
        "test_class_count": len(row["test_classes"]),
    }
    prompt = (
        "Solve one official ClassEval task. Complete solution.py in place. "
        "Implement the entire class shown by the existing skeleton, preserving "
        "its public methods and signatures. Return executable Python only; do "
        "not add tests, prose, or markdown.\n"
    )
    return write_material_and_prompt(
        workspace, material, prompt, "solution.py", str(row["skeleton"])
    )


def evaluate_classeval(
    case: dict, repo_root: Path, workspace: Path, artifact: Path
) -> dict:
    row = classeval_row(case, repo_root)
    evaluation_root = repo_root / "classeval_evaluation"
    sys.path.insert(0, str(evaluation_root))
    import path_util
    from test_pipeline import AutoTest

    path_util.LOGS_DIR = str(workspace / "evaluation_logs")
    solution = (workspace / "solution.py").read_text()
    evaluator = AutoTest("ClassEval_data")
    extracted = evaluator.extract_code(solution, "rivals_engineering")
    extracted = evaluator.add_static_statement(extracted)
    code = "\n".join(row["import_statement"]) + "\n" + extracted
    module_name = f"{row['task_id']}_0"
    (workspace / f"{module_name}.py").write_text(code + "\n" + row["test"])
    original_cwd = Path.cwd()
    sys.path.insert(0, str(workspace))
    try:
        os.chdir(workspace)
        result = evaluator.test(
            1,
            str(row["task_id"]),
            list(row["test_classes"]),
            "rivals_engineering",
        )[module_name]
    finally:
        os.chdir(original_cwd)
        sys.modules.pop(module_name, None)
    answers = {
        test_class: evaluator.get_test_answer(test_result)
        for test_class, test_result in result.items()
    }
    total = len(answers)
    successes = sum(answer == "success" for answer in answers.values())
    partials = sum(
        answer in {"success", "partial_success"} for answer in answers.values()
    )
    valid = bool(solution.strip()) and total == len(row["test_classes"]) and total > 0
    score = successes / total if total else None
    native = {
        "schema_version": "classeval.native_unit.v1",
        "suite_id": "classeval",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "valid_result": valid,
        "score_metric": "fun_success",
        "score": score,
        "class_success": 1.0 if successes == total and total > 0 else 0.0,
        "fun_partial_success": partials / total if total else None,
        "test_class_outcomes": answers,
        "solution_sha256": hashlib.sha256(solution.encode()).hexdigest(),
    }
    artifact.parent.mkdir(parents=True, exist_ok=True)
    artifact.write_text(json.dumps(native, indent=2, ensure_ascii=False))
    return {
        "valid_result": valid,
        "failure_reason": None if valid else "classeval_evaluator_result_invalid",
        "measurement_type": "continuous",
        "score_metric": "fun_success",
        "score": score,
        "native_metrics": {
            "fun_success": score,
            "class_success": native["class_success"],
            "fun_partial_success": native["fun_partial_success"],
        },
    }


def repobench_row(case: dict, repo_root: Path) -> dict:
    from datasets import load_dataset

    subset = str(case["subset"])
    level = str(case["level"])
    target_index = int(case["native_index"])
    cache_path = (
        repo_root
        / ".atlas-rivals-cache"
        / f"repobench_python_{subset}_{level}.json"
    )
    # O cache precisa cobrir o ÍNDICE pedido, não um teto fixo: com o pack em 10
    # casos, um cache regenerado com 3 mataria os índices 3..9 em silêncio.
    needed = max(target_index + 1, 10)
    rows = None
    if cache_path.is_file():
        cached = json.loads(cache_path.read_text())
        if isinstance(cached, list) and len(cached) > target_index:
            rows = cached
    if rows is None:
        dataset = load_dataset(
            "tianyang/repobench_python_v1.1",
            streaming=True,
        )
        rows = []
        for row in dataset[subset]:
            if str(row.get("level")) != level:
                continue
            rows.append(dict(row))
            if len(rows) >= needed:
                break
        cache_path.parent.mkdir(parents=True, exist_ok=True)
        cache_path.write_text(json.dumps(rows, ensure_ascii=False))
    if 0 <= target_index < len(rows):
        expected = f"python:{subset}:{level}:{target_index}"
        if case.get("native_task_id") != expected:
            raise RuntimeError("repobench_native_task_mismatch")
        return dict(rows[target_index])
    raise RuntimeError("repobench_case_index_out_of_range")


def prepare_repobench(case: dict, repo_root: Path, workspace: Path) -> dict:
    row = repobench_row(case, repo_root)
    write_private_reference(
        workspace,
        "repobench",
        case["case_id"],
        row,
    )
    sys.path.insert(0, str(repo_root))
    from data.utils import construct_prompt

    source_prompt = construct_prompt(
        row,
        language="python",
        tokenizer=None,
        max_token_nums=15800,
    )
    material = {
        "schema_version": "atlas.rivals2.engineering_case_material.v1",
        "suite_id": "repobench",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "subset": case["subset"],
        "level": case["level"],
        "source": source_prompt,
    }
    prompt = (
        "Solve one official RepoBench next-line completion. Read the `source` "
        "field in case_material.json and infer the single line that follows it. "
        "Write only that raw line to completion.txt, with no markdown or prose.\n"
    )
    return write_material_and_prompt(workspace, material, prompt, "completion.txt")


def evaluate_repobench(
    case: dict, repo_root: Path, workspace: Path, artifact: Path
) -> dict:
    row = read_private_reference(
        workspace,
        "repobench",
        case["case_id"],
    ) or repobench_row(case, repo_root)
    sys.path.insert(0, str(repo_root))
    from evaluation.metrics import (
        codebleu_score,
        edit_similarity_score,
        exact_match_score,
    )
    from predict_openai import get_first_line_not_comment

    raw = read_text_or_empty(workspace / "completion.txt")
    prediction = get_first_line_not_comment(
        raw.replace("```python", "").replace("```", ""),
        language="python",
    )
    reference = str(row["next_line"])
    exact = exact_match_score([prediction], [reference])
    edit = edit_similarity_score([prediction], [reference]) / 100.0
    codebleu = codebleu_score([prediction], [reference], "python")
    valid = bool(raw.strip()) and all(
        isinstance(value, (int, float)) for value in (exact, edit, codebleu)
    )
    native = {
        "schema_version": "repobench.native_unit.v1",
        "suite_id": "repobench",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "valid_result": valid,
        "score_metric": "edit_similarity",
        "score": edit,
        "metrics": {
            "exact_match": exact,
            "edit_similarity": edit,
            "codebleu": codebleu,
        },
        "prediction": prediction,
        "reference_sha256": hashlib.sha256(reference.encode()).hexdigest(),
    }
    artifact.parent.mkdir(parents=True, exist_ok=True)
    artifact.write_text(json.dumps(native, indent=2, ensure_ascii=False))
    return {
        "valid_result": valid,
        "failure_reason": None if valid else "repobench_completion_or_metric_invalid",
        "measurement_type": "continuous",
        "score_metric": "edit_similarity",
        "score": edit,
        "native_metrics": native["metrics"],
    }


def locagent_row(case: dict, repo_root: Path | None = None) -> dict:
    from datasets import load_dataset

    cache_path = (
        repo_root / ".atlas-rivals-cache" / "locagent_swebench_lite_first3.json"
        if repo_root is not None
        else None
    )
    # Cache precisa cobrir o índice pedido (pack em 10), não o teto fixo de 3.
    needed = max(int(case["native_index"]) + 1, 10)
    rows = None
    if cache_path is not None and cache_path.is_file():
        cached = json.loads(cache_path.read_text())
        if isinstance(cached, list) and len(cached) >= needed:
            rows = cached
    if rows is None:
        assert cache_path is not None
        parquet_path = cache_path.parent / "locagent_swebench_lite_test.parquet"
        if not parquet_path.is_file():
            parquet_path.parent.mkdir(parents=True, exist_ok=True)
            temporary = parquet_path.with_suffix(".download")
            urllib.request.urlretrieve(
                "https://huggingface.co/datasets/czlll/SWE-bench_Lite/"
                "resolve/main/data/test-00000-of-00001.parquet",
                temporary,
            )
            os.replace(temporary, parquet_path)
        upstream = load_dataset(
            "parquet",
            data_files=str(parquet_path),
            split="train",
        )
        rows = [dict(upstream[index]) for index in range(needed)]
        cache_path.parent.mkdir(parents=True, exist_ok=True)
        cache_path.write_text(json.dumps(rows, ensure_ascii=False))
    index = int(case["native_index"])
    if index < 0 or index >= len(rows):
        raise RuntimeError("locagent_case_index_out_of_range")
    row = dict(rows[index])
    expected = f"czlll/SWE-bench_Lite:test:{index}"
    if case.get("native_task_id") != expected:
        raise RuntimeError("locagent_native_task_mismatch")
    return row


def prepare_locagent(case: dict, repo_root: Path, workspace: Path) -> dict:
    row = locagent_row(case, repo_root)
    write_private_reference(
        workspace,
        "locagent",
        case["case_id"],
        row,
    )
    material = {
        "schema_version": "atlas.rivals2.engineering_case_material.v1",
        "suite_id": "locagent",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "instance_id": row["instance_id"],
        "repository": row["repo"],
        "problem_statement": row["problem_statement"],
        "hints_text": row.get("hints_text") or "",
    }
    prompt = (
        "Solve one official LocAgent file-localization task. Read "
        "case_material.json. Write localization.json as a JSON array containing "
        "up to five repository-relative Python file paths, ranked most likely "
        "to require editing first. Output only the JSON array in that file.\n"
    )
    return write_material_and_prompt(workspace, material, prompt, "localization.json")


def evaluate_locagent(
    case: dict, repo_root: Path, workspace: Path, artifact: Path
) -> dict:
    row = read_private_reference(
        workspace,
        "locagent",
        case["case_id"],
    ) or locagent_row(case, repo_root)
    raw = read_text_or_empty(workspace / "localization.json").strip()
    try:
        decoded = json.loads(raw)
    except json.JSONDecodeError:
        decoded = None
    predictions = (
        [str(item).strip() for item in decoded if str(item).strip()][:5]
        if isinstance(decoded, list)
        else []
    )
    valid = isinstance(decoded, list) and all(
        isinstance(item, str) for item in decoded
    )
    sys.path.insert(0, str(repo_root))
    sys.path.insert(0, str(repo_root / "evaluation"))
    import eval_metric
    import torch

    gold_files = []
    for edit_function in row["edit_functions"]:
        path = str(edit_function).split(":")[0]
        if path not in gold_files:
            gold_files.append(path)
    ideal_labels = [1 if index < len(gold_files) else 0 for index in range(5)]
    predicted_labels = [
        1 if index < len(predictions) and predictions[index] in gold_files else 0
        for index in range(5)
    ]
    predicted_tensor = torch.tensor([predicted_labels])
    ideal_tensor = torch.tensor([ideal_labels])
    metrics = {}
    for k in (1, 3, 5):
        metrics[f"Acc@{k}"] = round(
            eval_metric.acc_at_k(predicted_tensor, ideal_tensor, k=k).item(),
            4,
        )
        metrics[f"NDCG@{k}"] = round(
            eval_metric.normalized_dcg(
                predicted_tensor,
                ideal_tensor,
                k=k,
            ).item(),
            4,
        )
        metrics[f"P@{k}"] = round(
            eval_metric.precision_at_k(
                predicted_tensor,
                ideal_tensor,
                k=k,
            ).item(),
            4,
        )
        metrics[f"Recall@{k}"] = round(
            eval_metric.recall_at_k(
                predicted_tensor,
                ideal_tensor,
                k=k,
            ).item(),
            4,
        )
        metrics[f"MAP@{k}"] = round(
            eval_metric.average_precision_at_k(
                predicted_tensor,
                ideal_tensor,
                k=k,
            ).item(),
            4,
        )
    score = metrics["Recall@5"] if valid else None
    native = {
        "schema_version": "locagent.native_unit.v1",
        "suite_id": "locagent",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "instance_id": row["instance_id"],
        "valid_result": valid,
        "score_metric": "Recall@5",
        "score": score,
        "metrics": metrics,
        "predicted_files": predictions,
    }
    artifact.parent.mkdir(parents=True, exist_ok=True)
    artifact.write_text(json.dumps(native, indent=2, ensure_ascii=False))
    return {
        "valid_result": valid,
        "failure_reason": None if valid else "locagent_localization_json_invalid",
        "measurement_type": "continuous",
        "score_metric": "Recall@5",
        "score": score,
        "native_metrics": metrics,
    }


def debug_gym_task(case: dict, repo_root: Path) -> tuple[str, Path, str]:
    task = str(case["native_task_id"])
    task_root = repo_root / "data" / "mini_nightmare" / task
    code_path = task_root / f"{task}_code.py"
    if not task_root.is_dir() or not code_path.is_file():
        raise RuntimeError("debug_gym_native_task_missing")
    return task, task_root, code_path.name


def prepare_debug_gym(case: dict, repo_root: Path, workspace: Path) -> dict:
    task, task_root, code_name = debug_gym_task(case, repo_root)
    workspace.mkdir(parents=True, exist_ok=True)
    for source in task_root.iterdir():
        target = workspace / source.name
        if source.is_dir():
            shutil.copytree(source, target, dirs_exist_ok=True)
        else:
            shutil.copy2(source, target)
    material = {
        "schema_version": "atlas.rivals2.engineering_case_material.v1",
        "suite_id": "debug_gym",
        "case_id": case["case_id"],
        "native_task_id": task,
        "code_file": code_name,
        "test_command": "python -m pytest --tb=no -q test.py",
    }
    (workspace / "case_material.json").write_text(
        json.dumps(material, indent=2, ensure_ascii=False)
    )
    (workspace / ".rivals_task.md").write_text(
        "Solve one official debug-gym mini_nightmare task. Investigate the "
        "workspace, run the tests, and edit the implementation file named in "
        "case_material.json until the existing tests pass. Do not edit test.py "
        "or case_material.json.\n"
    )
    return {
        "suite_id": "debug_gym",
        "case_id": case["case_id"],
        "artifact_target": str(workspace / code_name),
        "prompt_file": str(workspace / ".rivals_task.md"),
    }


def evaluate_debug_gym(
    case: dict, repo_root: Path, workspace: Path, artifact: Path
) -> dict:
    task, _, code_name = debug_gym_task(case, repo_root)
    process = subprocess.run(
        [sys.executable, "-m", "pytest", "--tb=no", "-q", "test.py"],
        cwd=workspace,
        text=True,
        capture_output=True,
        timeout=120,
    )
    output = (process.stdout + "\n" + process.stderr).strip()
    passed = process.returncode == 0
    valid = process.returncode in {0, 1}
    native = {
        "schema_version": "debug_gym.native_unit.v1",
        "suite_id": "debug_gym",
        "case_id": case["case_id"],
        "native_task_id": task,
        "valid_result": valid,
        "score_metric": "resolved",
        "score": 1.0 if passed else 0.0,
        "pytest_exit_code": process.returncode,
        "pytest_output_tail": output[-4000:],
        "solution_sha256": hashlib.sha256(
            (workspace / code_name).read_bytes()
        ).hexdigest(),
    }
    artifact.parent.mkdir(parents=True, exist_ok=True)
    artifact.write_text(json.dumps(native, indent=2, ensure_ascii=False))
    return {
        "valid_result": valid,
        "benchmark_pass": passed,
        "failure_reason": None if passed else f"debug_gym_pytest_exit_{process.returncode}",
        "measurement_type": "binary",
        "score_metric": "resolved",
        "score": native["score"],
        "native_metrics": {"resolved": native["score"]},
    }


def testeval_row(case: dict, repo_root: Path) -> dict:
    row = jsonl_row(
        repo_root / "data" / "leetcode-py.jsonl",
        int(case["native_index"]),
    )
    if str(row.get("task_num")) != str(case.get("native_task_id")):
        raise RuntimeError("testeval_native_task_mismatch")
    return row


def prepare_testeval(case: dict, repo_root: Path, workspace: Path) -> dict:
    row = testeval_row(case, repo_root)
    material = {
        "schema_version": "atlas.rivals2.engineering_case_material.v1",
        "suite_id": "testeval",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "task_title": row["task_title"],
        "difficulty": row["difficulty"],
        "func_name": row["func_name"],
        "description": row["description"],
        "code": row["python_solution"],
    }
    prompt = (
        "Solve one official TestEval unit-test generation task. Read "
        "case_material.json, especially `code`, `description`, and `func_name`. "
        "Write tests.py containing one executable function named "
        "`test_<func_name>()`. Inside it instantiate `solution = Solution()` and "
        "test the target method with meaningful assertions. Output Python only; "
        "do not import the Solution class because the evaluator injects it.\n"
    )
    return write_material_and_prompt(workspace, material, prompt, "tests.py")


def evaluate_testeval(
    case: dict, repo_root: Path, workspace: Path, artifact: Path
) -> dict:
    row = testeval_row(case, repo_root)
    tests = read_text_or_empty(workspace / "tests.py")
    eval_root = workspace / "testeval_eval"
    eval_root.mkdir(parents=True, exist_ok=True)
    shutil.copy2(repo_root / ".coveragerc", eval_root / ".coveragerc")
    sys.path.insert(0, str(repo_root))
    from eval_overall import check_correctness

    generated = [
        {
            "task_num": row["task_num"],
            "difficulty": row["difficulty"],
            "func_name": row["func_name"],
            "code": row["python_solution"],
            "tests": [tests],
        }
    ]
    original_cwd = Path.cwd()
    original_path = os.environ.get("PATH", "")
    output = io.StringIO()
    try:
        os.chdir(eval_root)
        sys.path.insert(0, str(eval_root))
        os.environ["PATH"] = str(repo_root / ".venv" / "bin") + os.pathsep + original_path
        with contextlib.redirect_stdout(output):
            correctness, failures = check_correctness(generated, ks=[1])
    finally:
        os.chdir(original_cwd)
        if str(eval_root) in sys.path:
            sys.path.remove(str(eval_root))
        os.environ["PATH"] = original_path
    stdout = output.getvalue()

    def printed_metric(label: str) -> float | None:
        match = re.search(rf"{re.escape(label)}\s+([0-9.]+)", stdout)
        return float(match.group(1)) if match else None

    line_coverage = printed_metric("line coverage@1")
    branch_coverage = printed_metric("branch coverage@1")
    syntax = float(correctness["syn_correct"])
    executable = float(correctness["exec_correct"])
    valid = bool(tests.strip()) and all(
        isinstance(value, (int, float))
        for value in (line_coverage, branch_coverage, syntax, executable)
    )
    native_metrics = {
        "line_coverage@1": line_coverage,
        "branch_coverage@1": branch_coverage,
        "syntax_correctness": syntax,
        "executable_correctness": executable,
    }
    native = {
        "schema_version": "testeval.native_unit.v1",
        "suite_id": "testeval",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "valid_result": valid,
        "score_metric": "line_coverage@1",
        "score": line_coverage,
        "metrics": native_metrics,
        "execution_failures": failures,
        "tests_sha256": hashlib.sha256(tests.encode()).hexdigest(),
    }
    artifact.parent.mkdir(parents=True, exist_ok=True)
    artifact.write_text(json.dumps(native, indent=2, ensure_ascii=False, default=str))
    return {
        "valid_result": valid,
        "failure_reason": (
            None
            if valid
            else (
                "testeval_tests_missing_or_empty"
                if not tests.strip()
                else "testeval_metrics_missing"
            )
        ),
        "measurement_type": "continuous",
        "score_metric": "line_coverage@1",
        "score": line_coverage,
        "native_metrics": native_metrics,
    }


def crosscodeeval_row(case: dict, repo_root: Path) -> dict:
    path = (
        repo_root
        / "data"
        / "python"
        / "line_completion_rg1_unixcoder_cosine_sim.jsonl"
    )
    row = jsonl_row(path, int(case["native_index"]))
    expected = f"python:line_completion:{int(case['native_index'])}"
    if case.get("native_task_id") != expected:
        raise RuntimeError("crosscodeeval_native_task_mismatch")
    return row


def prepare_crosscodeeval(case: dict, repo_root: Path, workspace: Path) -> dict:
    row = crosscodeeval_row(case, repo_root)
    source = str(row.get("crossfile_context", {}).get("text") or "") + str(
        row["prompt"]
    )
    material = {
        "schema_version": "atlas.rivals2.engineering_case_material.v1",
        "suite_id": "crosscodeeval",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "source": source,
        "metadata": row["metadata"],
    }
    prompt = (
        "Solve one official CrossCodeEval cross-file line-completion task. Read "
        "the `source` field in case_material.json and write only the code that "
        "immediately follows it to completion.txt. No markdown or prose.\n"
    )
    return write_material_and_prompt(workspace, material, prompt, "completion.txt")


def evaluate_crosscodeeval(
    case: dict, repo_root: Path, workspace: Path, artifact: Path
) -> dict:
    row = crosscodeeval_row(case, repo_root)
    prediction = read_text_or_empty(workspace / "completion.txt")
    scripts_root = repo_root / "scripts"
    sys.path.insert(0, str(scripts_root))
    import eval_metric
    from tree_sitter import Language, Parser

    language = Language(str(repo_root / "build" / "python-lang-parser.so"), "python")
    parser = Parser()
    parser.set_language(language)
    eval_metric.parser = parser
    sample = {
        "task_id": row["metadata"]["task_id"],
        "pred": prediction,
    }
    truncated, exact = eval_metric.process_examples("python", (sample, row))
    edit_similarity = float(
        eval_metric.cal_edit_sim([truncated["target"]], [truncated["pred"]])
    ) / 100.0
    pred_ids = truncated["pred_ids"]
    target_ids = truncated["target_ids"]
    tp, fp, fn = eval_metric.compute_id_match(pred_ids, target_ids)
    identifier_f1 = 2 * tp / (2 * tp + fp + fn) if (2 * tp + fp + fn) else 0.0
    valid = bool(prediction.strip())
    native_metrics = {
        "exact_match": float(exact),
        "edit_similarity": edit_similarity,
        "identifier_f1": identifier_f1,
    }
    native = {
        "schema_version": "crosscodeeval.native_unit.v1",
        "suite_id": "crosscodeeval",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "valid_result": valid,
        "score_metric": "edit_similarity",
        "score": edit_similarity,
        "metrics": native_metrics,
        "prediction": truncated["pred"],
        "reference_sha256": hashlib.sha256(
            str(row["groundtruth"]).encode()
        ).hexdigest(),
    }
    artifact.parent.mkdir(parents=True, exist_ok=True)
    artifact.write_text(json.dumps(native, indent=2, ensure_ascii=False))
    return {
        "valid_result": valid,
        "failure_reason": None if valid else "crosscodeeval_completion_empty",
        "measurement_type": "continuous",
        "score_metric": "edit_similarity",
        "score": edit_similarity,
        "native_metrics": native_metrics,
    }


def bigcodebench_problem(case: dict, repo_root: Path) -> dict:
    from bigcodebench.data import get_bigcodebench

    cache_path = (
        repo_root
        / ".atlas-rivals-cache"
        / "bigcodebench_first3.json"
    )
    # O cache precisa conter a TASK pedida, não um teto fixo de 3: com o pack em
    # 10 casos, regenerar só 3 mataria BigCodeBench/3..9 em silêncio.
    task_id = str(case.get("native_task_id", ""))
    needed = max(int(case.get("native_index", 0)) + 1, 10)
    problems = None
    if cache_path.is_file():
        cached = json.loads(cache_path.read_text())
        if isinstance(cached, dict) and task_id in cached:
            problems = cached
    if problems is None:
        upstream = get_bigcodebench(err_incomplete=False, subset="full")
        problems = {
            f"BigCodeBench/{index}": upstream[f"BigCodeBench/{index}"]
            for index in range(needed)
        }
        cache_path.parent.mkdir(parents=True, exist_ok=True)
        cache_path.write_text(json.dumps(problems, ensure_ascii=False))
    problem = problems.get(case.get("native_task_id"))
    if not isinstance(problem, dict):
        raise RuntimeError("bigcodebench_native_task_missing")
    return problem


def prepare_bigcodebench(
    case: dict, repo_root: Path, workspace: Path
) -> dict:
    problem = bigcodebench_problem(case, repo_root)
    write_private_reference(
        workspace,
        "bigcodebench",
        case["case_id"],
        problem,
    )
    material = {
        "schema_version": "atlas.rivals2.engineering_case_material.v1",
        "suite_id": "bigcodebench",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "entry_point": problem["entry_point"],
        "instruct_prompt": problem["instruct_prompt"],
        "libraries": problem.get("libs"),
    }
    prompt = (
        "Solve one official BigCodeBench task. Complete solution.py in place. "
        "Keep the supplied imports, signature, and docstring, and implement the "
        "function as self-contained executable Python. No tests or markdown.\n"
    )
    return write_material_and_prompt(
        workspace,
        material,
        prompt,
        "solution.py",
        str(problem["complete_prompt"]),
    )


def evaluate_bigcodebench(
    case: dict, repo_root: Path, workspace: Path, artifact: Path
) -> dict:
    problem = read_private_reference(
        workspace,
        "bigcodebench",
        case["case_id"],
    ) or bigcodebench_problem(case, repo_root)
    from bigcodebench.eval import unsafe_execute

    solution = (workspace / "solution.py").read_text()
    stat = SimpleNamespace(value=3)
    details = {}
    unsafe_execute(
        problem["entry_point"],
        solution,
        problem["test"],
        60.0,
        30 * 1024,
        30 * 1024,
        10,
        stat,
        details,
    )
    status = {
        0: "pass",
        1: "fail",
        2: "timeout",
        3: "timeout",
    }.get(stat.value, "timeout")
    if status == "pass" and details:
        status = "fail"
    valid = status in {"pass", "fail", "timeout"}
    passed = status == "pass"
    native = {
        "schema_version": "bigcodebench.native_unit.v1",
        "suite_id": "bigcodebench",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "valid_result": valid,
        "score_metric": "pass@1",
        "score": 1.0 if passed else 0.0,
        "status": status,
        "details": details,
        "solution_sha256": hashlib.sha256(solution.encode()).hexdigest(),
    }
    artifact.parent.mkdir(parents=True, exist_ok=True)
    artifact.write_text(json.dumps(native, indent=2, ensure_ascii=False, default=str))
    return {
        "valid_result": valid,
        "benchmark_pass": passed,
        "failure_reason": None if passed else f"bigcodebench_status={status}",
        "measurement_type": "binary",
        "score_metric": "pass@1",
        "score": native["score"],
        "native_metrics": {"pass@1": native["score"]},
    }


def deveval_row(case: dict, repo_root: Path) -> dict:
    row = jsonl_row(repo_root / "data.jsonl", int(case["native_index"]))
    if case.get("native_task_id") != f"deveval:{int(case['native_index'])}":
        raise RuntimeError("deveval_native_task_mismatch")
    return row


def deveval_prompt(row: dict, repo_root: Path) -> str:
    namespace = str(row["namespace"])
    with (
        repo_root
        / "Experiments"
        / "prompt"
        / "without_context"
        / "gpt-35-1106_prompt.jsonl"
    ).open(encoding="utf-8") as stream:
        for line in stream:
            prompt_row = json.loads(line)
            if prompt_row.get("namespace") == namespace:
                return str(prompt_row["prompt"])
    raise RuntimeError("deveval_prompt_missing")


def prepare_deveval(case: dict, repo_root: Path, workspace: Path) -> dict:
    row = deveval_row(case, repo_root)
    source_root = repo_root / "Source_Code"
    source_project = source_root / str(row["project_path"])
    if not source_project.is_dir():
        raise RuntimeError("deveval_source_project_missing")
    project_target = workspace / "Source_Code" / str(row["project_path"])
    project_target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copytree(
        source_project,
        project_target,
        ignore=shutil.ignore_patterns(
            ".pytest_cache",
            "__pycache__",
            "*.pyc",
            ".coverage",
        ),
    )
    completion_target = workspace / "Source_Code" / str(row["completion_path"])
    lines = completion_target.read_text().splitlines(keepends=True)
    start, end = int(row["body_position"][0]) - 1, int(row["body_position"][1])
    lines[start:end] = [" " * int(row["indent"]) + "pass\n"]
    completion_target.write_text("".join(lines))
    material = {
        "schema_version": "atlas.rivals2.engineering_case_material.v1",
        "suite_id": "deveval",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "namespace": row["namespace"],
        "project_path": row["project_path"],
        "completion_path": row["completion_path"],
        "requirement": row["requirement"],
        "tests": row["tests"],
        "official_prompt": deveval_prompt(row, repo_root),
    }
    prompt = (
        "Solve one official DevEval repository-level completion task. Read "
        "case_material.json and inspect the copied project when useful. Write "
        "only the function body implementation to completion.py, without the "
        "signature, markdown, or prose. Do not edit the copied project directly; "
        "the official evaluator injects completion.py into it.\n"
    )
    return write_material_and_prompt(workspace, material, prompt, "completion.py")


def evaluate_deveval(
    case: dict, repo_root: Path, workspace: Path, artifact: Path
) -> dict:
    row = deveval_row(case, repo_root)
    completion = read_text_or_empty(workspace / "completion.py")
    sys.path.insert(0, str(repo_root))
    import pass_k

    args = SimpleNamespace(source_code_root=workspace / "Source_Code")
    evaluation_row = dict(row)
    evaluation_row["completion"] = pass_k.adjust_indent(
        completion,
        int(row["indent"]),
    )
    project_path = args.source_code_root / str(row["project_path"])
    outputs = []
    status = "Pass"
    pass_k.SetUp_evaluation(
        args,
        evaluation_row,
        evaluation_row["completion"],
    )
    try:
        for test_directive in row["tests"]:
            process = subprocess.run(
                [
                    sys.executable,
                    "-m",
                    "pytest",
                    "-q",
                    str(test_directive),
                ],
                cwd=project_path,
                text=True,
                capture_output=True,
                timeout=60,
            )
            outputs.append(process.stdout + "\n" + process.stderr)
            if process.returncode != 0:
                status = "Error"
                break
    except subprocess.TimeoutExpired as exception:
        status = "TimeOut"
        outputs.append(str(exception))
    finally:
        pass_k.TearDown_evaluation(args, evaluation_row)
    valid = bool(completion.strip()) and status in {
        "Pass",
        "Error",
        "OOM",
        "TimeOut",
    }
    passed = status == "Pass"
    native = {
        "schema_version": "deveval.native_unit.v1",
        "suite_id": "deveval",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "namespace": row["namespace"],
        "valid_result": valid,
        "score_metric": "pass@1",
        "score": 1.0 if passed else 0.0,
        "status": status,
        "evaluator_output_tail": "\n".join(outputs)[-4000:],
        "completion_sha256": hashlib.sha256(completion.encode()).hexdigest(),
    }
    artifact.parent.mkdir(parents=True, exist_ok=True)
    artifact.write_text(json.dumps(native, indent=2, ensure_ascii=False))
    return {
        "valid_result": valid,
        "benchmark_pass": passed,
        "failure_reason": None if passed else f"deveval_status={status}",
        "measurement_type": "binary",
        "score_metric": "pass@1",
        "score": native["score"],
        "native_metrics": {"pass@1": native["score"]},
    }


def long_code_arena_root(repo_root: Path) -> Path:
    root = repo_root / "library_based_code_generation"
    if not root.is_dir():
        raise RuntimeError("long_code_arena_library_root_missing")
    return root


def long_code_arena_row(case: dict, repo_root: Path) -> dict:
    from datasets import load_dataset

    cache_path = (
        long_code_arena_root(repo_root)
        / ".atlas-rivals-cache"
        / "library_based_code_generation_first3.json"
    )
    # Cache precisa cobrir o índice pedido (pack em 10), não o teto fixo de 3.
    needed = max(int(case["native_index"]) + 1, 10)
    rows = None
    if cache_path.is_file():
        cached = json.loads(cache_path.read_text())
        if isinstance(cached, list) and len(cached) >= needed:
            rows = cached
    if rows is None:
        parquet_path = (
            cache_path.parent
            / "library_based_code_generation_test.parquet"
        )
        if not parquet_path.is_file():
            parquet_path.parent.mkdir(parents=True, exist_ok=True)
            temporary = parquet_path.with_suffix(".download")
            urllib.request.urlretrieve(
                "https://huggingface.co/datasets/JetBrains-Research/"
                "lca-library-based-code-generation/resolve/main/data/"
                "test-00000-of-00001-518ed46ecbe35ff9.parquet",
                temporary,
            )
            os.replace(temporary, parquet_path)
        upstream = load_dataset(
            "parquet",
            data_files=str(parquet_path),
            split="train",
        )
        rows = [dict(upstream[index]) for index in range(needed)]
        cache_path.parent.mkdir(parents=True, exist_ok=True)
        cache_path.write_text(json.dumps(rows, ensure_ascii=False))
    index = int(case["native_index"])
    if index < 0 or index >= len(rows):
        raise RuntimeError("long_code_arena_case_index_out_of_range")
    expected = f"library_based_code_generation:{index}"
    if case.get("native_task_id") != expected:
        raise RuntimeError("long_code_arena_native_task_mismatch")
    return dict(rows[index])


def prepare_long_code_arena(
    case: dict, repo_root: Path, workspace: Path
) -> dict:
    row = long_code_arena_row(case, repo_root)
    write_private_reference(
        workspace,
        "long_code_arena",
        case["case_id"],
        row,
    )
    material = {
        "schema_version": "atlas.rivals2.engineering_case_material.v1",
        "suite_id": "long_code_arena",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "instruction": row["instruction"],
        "project_defined_elements": row["project_defined_elements"],
    }
    prompt = (
        "Solve one official Long Code Arena library-based code generation task. "
        "Read the instruction and project-defined elements in case_material.json. "
        "Write the complete requested Python code to solution.py with no prose "
        "or markdown.\n"
    )
    return write_material_and_prompt(workspace, material, prompt, "solution.py")


def evaluate_long_code_arena(
    case: dict, repo_root: Path, workspace: Path, artifact: Path
) -> dict:
    row = read_private_reference(
        workspace,
        "long_code_arena",
        case["case_id"],
    ) or long_code_arena_row(case, repo_root)
    root = long_code_arena_root(repo_root)
    sys.path.insert(0, str(root))
    from src.metrics.chrf import ChrF
    from src.metrics.overlap import Overlap

    solution = read_text_or_empty(workspace / "solution.py")
    chrf = ChrF().score(solution, row["clean_reference"], row["unique_apis"])
    api_recall = Overlap().score(
        solution,
        row["clean_reference"],
        row["unique_apis"],
    )
    valid = bool(solution.strip()) and all(
        isinstance(value, (int, float)) for value in (chrf, api_recall)
    )
    native_metrics = {
        "ChrF": chrf,
        "API_recall": api_recall,
    }
    native = {
        "schema_version": "long_code_arena.native_unit.v1",
        "suite_id": "long_code_arena",
        "case_id": case["case_id"],
        "native_task_id": case["native_task_id"],
        "valid_result": valid,
        "score_metric": "API_recall",
        "score": api_recall,
        "metrics": native_metrics,
        "solution_sha256": hashlib.sha256(solution.encode()).hexdigest(),
        "reference_sha256": hashlib.sha256(
            str(row["clean_reference"]).encode()
        ).hexdigest(),
    }
    artifact.parent.mkdir(parents=True, exist_ok=True)
    artifact.write_text(json.dumps(native, indent=2, ensure_ascii=False))
    return {
        "valid_result": valid,
        "failure_reason": None if valid else "long_code_arena_solution_or_metric_invalid",
        "measurement_type": "continuous",
        "score_metric": "API_recall",
        "score": api_recall,
        "native_metrics": native_metrics,
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
    sys.path.insert(0, str(args.repo_root))
    case = load_case(args.case_file, args.suite)
    canonical_stdout = sys.stdout
    # Benchmark libraries may narrate cache/model loading, but the native
    # runner's stdout is a machine contract: exactly one JSON object. Keep
    # upstream diagnostics in stderr, which the receipt persists verbatim.
    sys.stdout = sys.stderr

    if args.action == "prepare":
        if args.suite == "archbench":
            result = prepare_archbench(case, args.workspace)
        elif args.suite == "cruxeval":
            result = prepare_cruxeval(case, args.repo_root, args.workspace)
        elif args.suite == "classeval":
            result = prepare_classeval(case, args.repo_root, args.workspace)
        elif args.suite == "repobench":
            result = prepare_repobench(case, args.repo_root, args.workspace)
        elif args.suite == "locagent":
            result = prepare_locagent(case, args.repo_root, args.workspace)
        elif args.suite == "debug_gym":
            result = prepare_debug_gym(case, args.repo_root, args.workspace)
        elif args.suite == "testeval":
            result = prepare_testeval(case, args.repo_root, args.workspace)
        elif args.suite == "evalplus":
            result = prepare_evalplus(case, args.workspace)
        elif args.suite == "crosscodeeval":
            result = prepare_crosscodeeval(case, args.repo_root, args.workspace)
        elif args.suite == "bigcodebench":
            result = prepare_bigcodebench(
                case, args.repo_root, args.workspace
            )
        elif args.suite == "deveval":
            result = prepare_deveval(case, args.repo_root, args.workspace)
        elif args.suite == "long_code_arena":
            result = prepare_long_code_arena(
                case, args.repo_root, args.workspace
            )
        elif args.suite == "reval":
            result = prepare_reval(case, args.repo_root, args.workspace)
        else:
            raise RuntimeError(
                f"{args.suite}_engineering_native_driver_not_implemented"
            )
    else:
        if args.artifact is None:
            raise RuntimeError("engineering_native_artifact_path_missing")
        if args.suite == "archbench":
            result = evaluate_archbench(case, args.workspace, args.artifact)
        elif args.suite == "cruxeval":
            result = evaluate_cruxeval(
                case, args.repo_root, args.workspace, args.artifact
            )
        elif args.suite == "classeval":
            result = evaluate_classeval(
                case, args.repo_root, args.workspace, args.artifact
            )
        elif args.suite == "repobench":
            result = evaluate_repobench(
                case, args.repo_root, args.workspace, args.artifact
            )
        elif args.suite == "locagent":
            result = evaluate_locagent(
                case, args.repo_root, args.workspace, args.artifact
            )
        elif args.suite == "debug_gym":
            result = evaluate_debug_gym(
                case, args.repo_root, args.workspace, args.artifact
            )
        elif args.suite == "testeval":
            result = evaluate_testeval(
                case, args.repo_root, args.workspace, args.artifact
            )
        elif args.suite == "evalplus":
            result = evaluate_evalplus(case, args.workspace, args.artifact)
        elif args.suite == "crosscodeeval":
            result = evaluate_crosscodeeval(
                case, args.repo_root, args.workspace, args.artifact
            )
        elif args.suite == "bigcodebench":
            result = evaluate_bigcodebench(
                case, args.repo_root, args.workspace, args.artifact
            )
        elif args.suite == "deveval":
            result = evaluate_deveval(
                case, args.repo_root, args.workspace, args.artifact
            )
        elif args.suite == "long_code_arena":
            result = evaluate_long_code_arena(
                case, args.repo_root, args.workspace, args.artifact
            )
        elif args.suite == "reval":
            result = evaluate_reval(
                case, args.repo_root, args.workspace, args.artifact
            )
        else:
            raise RuntimeError(
                f"{args.suite}_engineering_native_driver_not_implemented"
            )
    print(json.dumps(result, ensure_ascii=False), file=canonical_stdout)


if __name__ == "__main__":
    main()
