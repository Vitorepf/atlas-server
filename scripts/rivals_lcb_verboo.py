#!/usr/bin/env python3
"""LiveCodeBench overlay for one Kimi K2.7 case via Verboo OpenAI API."""

import os
import sys
import json
import time
from datetime import datetime
from pathlib import Path

from openai import OpenAI

from lcb_runner.lm_styles import (
    LMStyle,
    LanguageModel,
    LanguageModelStore,
)
from lcb_runner.runner.oai_runner import OpenAIRunner
import lcb_runner.runner.main as main_module


def take_option(name: str) -> str:
    prefix = f"{name}="
    for index, value in enumerate(list(sys.argv)):
        if value.startswith(prefix):
            sys.argv.pop(index)
            return value[len(prefix) :]
    return ""


question_id = take_option("--rivals-question-id") or os.environ.get(
    "RIVALS_LCB_QUESTION_ID", ""
)
usage_file = take_option("--rivals-usage-file") or os.environ.get(
    "RIVALS_LCB_USAGE_FILE", ""
)
if question_id:
    os.environ["RIVALS_LCB_QUESTION_ID"] = question_id
if usage_file:
    os.environ["RIVALS_LCB_USAGE_FILE"] = usage_file
LanguageModelStore["kimi-k2.7"] = LanguageModel(
    model_name="kimi-k2.7",
    model_repr="kimi-k2.7-verboo",
    model_style=LMStyle.OpenAIChat,
    release_date=datetime(2026, 7, 1),
    link="https://code.verboo.ai",
)
base_client = OpenAI(
    api_key=os.environ["OPENAI_API_KEY"],
    base_url=os.environ["OPENAI_BASE_URL"],
)


class TrackingCompletions:
    def __init__(self, completions):
        self._completions = completions

    def create(self, *args, **kwargs):
        started = time.monotonic()
        response = self._completions.create(*args, **kwargs)
        # Prefer env so multiprocess workers still see the path after argv was
        # consumed by take_option() in the parent process.
        target = os.environ.get("RIVALS_LCB_USAGE_FILE") or usage_file
        if target and response.usage is not None:
            current = {
                "input_tokens": 0,
                "output_tokens": 0,
                "api_calls": 0,
                "duration_sec": 0.0,
            }
            if os.path.isfile(target):
                with open(target) as handle:
                    current.update(json.load(handle))
            current["input_tokens"] += int(response.usage.prompt_tokens or 0)
            current["output_tokens"] += int(response.usage.completion_tokens or 0)
            current["api_calls"] += 1
            current["duration_sec"] += time.monotonic() - started
            parent = os.path.dirname(target)
            if parent:
                os.makedirs(parent, exist_ok=True)
            with open(target, "w") as handle:
                json.dump(current, handle)
        return response

    def __getattr__(self, name):
        return getattr(self._completions, name)


class ChatProxy:
    def __init__(self, chat):
        self._chat = chat
        self.completions = TrackingCompletions(chat.completions)

    def __getattr__(self, name):
        return getattr(self._chat, name)


class ClientProxy:
    def __init__(self, client):
        self._client = client
        self.chat = ChatProxy(client.chat)

    def __getattr__(self, name):
        return getattr(self._client, name)


OpenAIRunner.client = ClientProxy(base_client)
original_build = main_module.build_prompt_benchmark


def build_one(args):
    benchmark, formatter = original_build(args)
    selected = [item for item in benchmark if str(item.question_id) == question_id]
    if len(selected) != 1:
        raise RuntimeError(
            f"LCB question cardinality for {question_id}: {len(selected)}"
        )
    return selected, formatter


if question_id:
    main_module.build_prompt_benchmark = build_one


def force_fresh_generation(model_repr):
    """Warm-cache guard (mata o skip que zera a prova de usage).

    `--continue_existing_with_eval` faz o lcb_runner PULAR a geração quando a
    questão-alvo já está no cache de output desta temperatura. Skip = create()
    não roda = usage novo não nasce = o attacher reprova o braço como
    env_failure (`bare_runtime_proof_usage_missing` /
    `atlas_dev_runtime_proof_missing_or_invalid`) com o modelo TENDO rodado numa
    run anterior. Provado em 20260720_113852: TODOS os units bare morreram assim
    ("Found N existing generations, continuing with 0 remaining"). Cada run
    PRECISA gerar fresco; removemos SÓ a questão-alvo do cache.

    A decisão de skip lê o arquivo de geração (Scenario...json) e, como
    fallback, o _eval_all.json — ambos com entradas 100% dict. O _eval.json tem
    estrutura POSICIONAL (list+dict pareados); remover por question_id o
    desalinha. Só purga arquivos all-dict.
    """
    qid = str(question_id)
    out_dir = Path("output") / model_repr
    if not out_dir.is_dir():
        return
    for path in out_dir.glob("*.json"):
        try:
            data = json.loads(path.read_text())
        except (OSError, json.JSONDecodeError):
            continue
        if not isinstance(data, list) or not all(isinstance(e, dict) for e in data):
            continue
        kept = [e for e in data if str(e.get("question_id")) != qid]
        if len(kept) != len(data):
            path.write_text(json.dumps(kept))


if __name__ == "__main__":
    if not question_id:
        raise SystemExit("missing --rivals-question-id")
    force_fresh_generation("kimi-k2.7-verboo")
    main_module.main()
