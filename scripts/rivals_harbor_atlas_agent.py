"""Harbor agent that runs Atlas Dev on a host-side workspace mirror.

Espelho do padrão do rivals_tb_atlas_agent.py para suítes Harbor
(senior_swe_bench, swe_marathon): baixa o workspace do ambiente para o host,
roda o bridge governado (atlas:cli:dev → hermes → Verboo) e sobe o resultado
de volta para o verificador nativo da suíte julgar.
"""

import json
import os
import subprocess
import tempfile
from pathlib import Path

from harbor.agents.installed.base import BaseInstalledAgent, with_prompt_template
from harbor.environments.base import BaseEnvironment
from harbor.models.agent.context import AgentContext


class AtlasDev(BaseInstalledAgent):
    @staticmethod
    def name() -> str:
        return "rivals-atlas-dev"

    async def install(self, environment: BaseEnvironment) -> None:
        # Nada a instalar no ambiente: o Atlas roda no HOST sobre um espelho.
        pass

    @with_prompt_template
    async def run(
        self,
        instruction: str,
        environment: BaseEnvironment,
        context: AgentContext,
    ) -> None:
        root = Path(os.environ["ATLAS_RIVALS_ROOT"])
        model = self.model_name.split("/", 1)[-1]
        env_cwd = (await self.exec_as_agent(environment, command="pwd")).stdout.strip()

        with tempfile.TemporaryDirectory(prefix="rivals-harbor-atlas-") as temporary:
            workspace = Path(temporary) / (Path(env_cwd).name or "workspace")
            workspace.mkdir(parents=True, exist_ok=True)
            await environment.download_dir(env_cwd, workspace)
            if not (workspace / ".git").is_dir():
                # Mesma razão do agente TB: tarefa de criação começa vazia e o
                # bridge precisa de uma base git para o diff. --allow-empty
                # porque árvore vazia é a base correta, não um erro.
                self._run(["git", "init", "-q"], workspace)
                self._run(["git", "config", "user.email", "rivals@atlas.local"], workspace)
                self._run(["git", "config", "user.name", "Atlas Rivals"], workspace)
                self._run(["git", "add", "."], workspace)
                self._run(["git", "commit", "-qm", "task baseline", "--allow-empty"], workspace)

            prompt_file = workspace / ".rivals_task.md"
            prompt_file.write_text(instruction)
            process = subprocess.run(
                [
                    "php",
                    str(root / "scripts/rivals-atlas-dev-bridge.php"),
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
            proof_path = workspace / ".rivals_atlas_dev_bridge.json"
            proof = json.loads(proof_path.read_text()) if proof_path.is_file() else {}
            if proof_path.is_file():
                await environment.upload_file(proof_path, "/logs/agent/.rivals_atlas_dev_bridge.json")
            prompt_file.unlink(missing_ok=True)
            proof_path.unlink(missing_ok=True)
            await environment.upload_dir(workspace, env_cwd)

            if process.returncode != 0 or proof.get("real_provider") is not True:
                tail = (process.stderr or process.stdout or "")[-600:]
                raise RuntimeError(f"atlas_dev_bridge_failed:{tail}")

            usage = proof.get("usage") or {}
            context.n_input_tokens = int(usage.get("input_tokens") or 0)
            context.n_output_tokens = int(usage.get("output_tokens") or 0)
            context.cost_usd = 0.0  # Verboo: custo marginal de assinatura.

    @staticmethod
    def _run(argv, cwd: Path) -> None:
        subprocess.run(argv, cwd=cwd, check=True, capture_output=True, text=True)
