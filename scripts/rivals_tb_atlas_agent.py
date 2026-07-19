"""Terminal-Bench agent that runs Atlas Dev on a host-side workspace mirror."""

import io
import json
import os
import shutil
import subprocess
import sys
import tarfile
import tempfile
from pathlib import Path

from terminal_bench.agents.base_agent import AgentResult, BaseAgent
from terminal_bench.agents.failure_mode import FailureMode


def emit_process_logs(process: subprocess.CompletedProcess[str]) -> None:
    """Forward captured bridge streams into Terminal-Bench's durable agent log."""
    if process.stdout:
        print(process.stdout, end="" if process.stdout.endswith("\n") else "\n", flush=True)
    if process.stderr:
        print(
            process.stderr,
            end="" if process.stderr.endswith("\n") else "\n",
            file=sys.stderr,
            flush=True,
        )


class AtlasDevAgent(BaseAgent):
    def __init__(self, model_name: str = "openai/kimi-k2.7", **kwargs):
        super().__init__(**kwargs)
        self._model = model_name.split("/", 1)[-1]

    @staticmethod
    def name() -> str:
        return "rivals-atlas-dev"

    def perform_task(self, instruction, session, logging_dir=None):
        root = Path(os.environ["ATLAS_RIVALS_ROOT"])
        cwd_result = session.container.exec_run(
            [
                "tmux",
                "display-message",
                "-p",
                "-t",
                session._session_name,
                "#{pane_current_path}",
            ]
        )
        if cwd_result.exit_code != 0:
            return AgentResult(failure_mode=FailureMode.UNKNOWN_AGENT_ERROR)
        container_cwd = cwd_result.output.decode().strip()

        with tempfile.TemporaryDirectory(prefix="rivals-tb-atlas-") as temporary:
            temporary_path = Path(temporary)
            archive, _ = session.container.get_archive(container_cwd)
            archive_bytes = b"".join(archive)
            with tarfile.open(fileobj=io.BytesIO(archive_bytes)) as bundle:
                self._safe_extract(bundle, temporary_path)
            workspace = temporary_path / Path(container_cwd).name
            if not (workspace / ".git").is_dir():
                self._run(["git", "init", "-q"], workspace)
                self._run(["git", "config", "user.email", "rivals@atlas.local"], workspace)
                self._run(["git", "config", "user.name", "Atlas Rivals"], workspace)
                self._run(["git", "add", "."], workspace)
                # --allow-empty: `git commit` sai 1 com "nothing to commit" quando
                # a árvore está vazia, e tarefa de CRIAÇÃO começa vazia — é o caso
                # do `hello-world`. Sem isto o agente morre no setup, antes de o
                # Atlas existir, e a unidade vira environment_failure.
                #
                # A assimetria é o que denuncia: o braço bare
                # (rivals_tb_verboo_agent.py) não faz baseline nenhum — zero git.
                # Este passo existe SÓ no braço Atlas, logo só o braço Atlas
                # quebrava. Toda tarefa de criação do terminal_bench era perdida
                # de um lado só, e o relatório lia isso como falha de ambiente.
                #
                # O baseline vazio é legítimo e é o ponto: ele existe para o
                # `git diff` posterior ter contra o que comparar. Numa árvore
                # vazia, o commit vazio É a base correta.
                self._run(["git", "commit", "-qm", "task baseline", "--allow-empty"], workspace)
            prompt_file = workspace / ".rivals_task.md"
            prompt_file.write_text(instruction)
            process = subprocess.run(
                [
                    "php",
                    str(root / "scripts/rivals-atlas-dev-bridge.php"),
                    f"--workspace={workspace}",
                    f"--prompt-file={prompt_file}",
                    f"--model={self._model}",
                    "--timeout=3600",
                ],
                cwd=root,
                capture_output=True,
                text=True,
                timeout=3700,
            )
            emit_process_logs(process)
            proof_path = workspace / ".rivals_atlas_dev_bridge.json"
            proof = json.loads(proof_path.read_text()) if proof_path.is_file() else {}
            if proof_path.is_file():
                if logging_dir is not None:
                    Path(logging_dir).mkdir(parents=True, exist_ok=True)
                    shutil.copy2(
                        proof_path,
                        Path(logging_dir) / ".rivals_atlas_dev_bridge.json",
                    )
                session.copy_to_container(
                    proof_path,
                    container_dir="/logs/agent",
                    container_filename=".rivals_atlas_dev_bridge.json",
                )
            prompt_file.unlink(missing_ok=True)
            proof_path.unlink(missing_ok=True)
            self._copy_back(session.container, workspace, container_cwd)
            if process.returncode != 0 or proof.get("real_provider") is not True:
                return AgentResult(failure_mode=FailureMode.UNKNOWN_AGENT_ERROR)
            usage = proof.get("usage") or {}

            return AgentResult(
                total_input_tokens=int(usage.get("input_tokens") or 0),
                total_output_tokens=int(usage.get("output_tokens") or 0),
                failure_mode=FailureMode.NONE,
            )

    @staticmethod
    def _safe_extract(bundle: tarfile.TarFile, destination: Path) -> None:
        root = destination.resolve()
        for member in bundle.getmembers():
            target = (destination / member.name).resolve()
            if root not in target.parents and target != root:
                raise RuntimeError("unsafe task archive path")
        bundle.extractall(destination)

    @staticmethod
    def _copy_back(container, workspace: Path, container_cwd: str) -> None:
        buffer = io.BytesIO()
        with tarfile.open(fileobj=buffer, mode="w") as bundle:
            bundle.add(workspace, arcname=workspace.name)
        buffer.seek(0)
        parent = str(Path(container_cwd).parent)
        if not container.put_archive(parent, buffer.read()):
            raise RuntimeError("failed to copy Atlas Dev workspace into task container")

    @staticmethod
    def _run(argv, cwd: Path) -> None:
        subprocess.run(argv, cwd=cwd, check=True, capture_output=True, text=True)
