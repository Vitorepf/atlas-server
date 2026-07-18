#!/usr/bin/env python3
"""LiveCodeBench overlay: generation via Atlas Dev bridge (governed runtime).

Reusa TODO o maquinário do overlay bare (rivals_lcb_verboo: seleção da questão,
usage-file, avaliador nativo) e troca só o client: cada create() vira uma
passada do bridge governado (atlas:cli:dev → hermes → Verboo) num workspace
descartável cujo solution.py é devolvido como a "resposta do modelo".
"""

import json
import os
import subprocess
import tempfile
from pathlib import Path
from types import SimpleNamespace

import rivals_lcb_verboo as base
from datetime import datetime

from lcb_runner.lm_styles import LanguageModel, LanguageModelStore, LMStyle
from lcb_runner.runner.oai_runner import OpenAIRunner
import lcb_runner.runner.main as main_module

# model_repr PRÓPRIO: o output/<repr>/ é o cache do --continue_existing — com
# o repr do bare, o braço Atlas "continuava" as gerações do bare e media o
# OUTRO braço. Diretório separado = geração sempre do runtime governado.
LanguageModelStore["kimi-k2.7"] = LanguageModel(
    model_name="kimi-k2.7",
    model_repr="kimi-k2.7-atlas",
    model_style=LMStyle.OpenAIChat,
    release_date=datetime(2026, 7, 1),
    link="https://code.verboo.ai",
)

INSTRUCTION = (
    "Write the complete, final solution as a single Python program into the "
    "file solution.py (create or overwrite it). The program must solve the "
    "problem described above exactly as specified (reading stdin and writing "
    "stdout when the problem requires it). Do not create any other files."
)


def render_messages(messages):
    return "\n\n".join(f"[{m['role']}]\n{m['content']}" for m in messages)


class BridgeCompletions:
    def create(self, *, messages, model=None, **kwargs):
        root = Path(os.environ["ATLAS_RIVALS_ROOT"])
        cli_model = (model or "kimi-k2.7").split("/", 1)[-1]
        with tempfile.TemporaryDirectory(prefix="rivals-lcb-atlas-") as temporary:
            workspace = Path(temporary) / "workspace"
            workspace.mkdir()
            (workspace / "solution.py").write_text("")
            for argv in (
                ["git", "init", "-q"],
                ["git", "config", "user.email", "rivals@atlas.local"],
                ["git", "config", "user.name", "Atlas Rivals"],
                ["git", "add", "."],
                ["git", "commit", "-qm", "LCB task baseline"],
            ):
                subprocess.run(argv, cwd=workspace, check=True, capture_output=True)
            prompt_file = workspace / ".rivals_task.md"
            prompt_file.write_text(render_messages(messages) + "\n\n" + INSTRUCTION)
            process = subprocess.run(
                [
                    "php",
                    str(root / "scripts/rivals-atlas-dev-bridge.php"),
                    f"--workspace={workspace}",
                    f"--prompt-file={prompt_file}",
                    f"--model={cli_model}",
                    "--timeout=1800",
                ],
                cwd=root,
                capture_output=True,
                text=True,
                timeout=1900,
            )
            proof_path = workspace / ".rivals_atlas_dev_bridge.json"
            proof = json.loads(proof_path.read_text()) if proof_path.is_file() else {}
            solution = workspace / "solution.py"
            code = solution.read_text() if solution.is_file() else ""
        if process.returncode != 0 or proof.get("real_provider") is not True:
            tail = (process.stderr or process.stdout or "")[-400:]
            raise RuntimeError(f"atlas_dev_bridge_failed:{tail}")
        usage = proof.get("usage") or {}

        return SimpleNamespace(
            choices=[SimpleNamespace(message=SimpleNamespace(content=f"```python\n{code}\n```"))],
            usage=SimpleNamespace(
                prompt_tokens=int(usage.get("input_tokens") or 0),
                completion_tokens=int(usage.get("output_tokens") or 0),
            ),
        )


# TrackingCompletions do overlay bare mantém a contabilidade do usage-file.
OpenAIRunner.client = SimpleNamespace(
    chat=SimpleNamespace(completions=base.TrackingCompletions(BridgeCompletions()))
)

if __name__ == "__main__":
    if not base.question_id:
        raise SystemExit("missing --rivals-question-id")
    main_module.main()
