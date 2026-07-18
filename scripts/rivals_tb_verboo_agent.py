"""Terminal-Bench Aider agent with explicit Verboo OpenAI-compatible routing."""

import inspect
import os
import shlex
from pathlib import Path

from terminal_bench.agents.installed_agents.abstract_installed_agent import (
    AbstractInstalledAgent,
)
from terminal_bench.agents.installed_agents.aider.aider_agent import AiderAgent
from terminal_bench.terminal.models import TerminalCommand


class VerbooAiderAgent(AbstractInstalledAgent):
    def __init__(self, model_name: str = "openai/kimi-k2.7", **kwargs):
        super().__init__(**kwargs)
        if not model_name.startswith("openai/"):
            raise ValueError("Verboo model must use openai/<model> routing")
        self._model_name = model_name
        self._version = kwargs.get("version", "latest")

    @staticmethod
    def name() -> str:
        return "rivals-verboo-aider"

    @property
    def _env(self) -> dict[str, str]:
        return {
            "OPENAI_API_KEY": os.environ["OPENAI_API_KEY"],
            "OPENAI_BASE_URL": os.environ["OPENAI_BASE_URL"],
            "OPENAI_API_BASE": os.environ["OPENAI_BASE_URL"],
            "AIDER_API_KEY": f"openai={os.environ['OPENAI_API_KEY']}",
        }

    @property
    def _install_agent_script_path(self) -> Path:
        return Path(inspect.getfile(AiderAgent)).parent / "aider-setup.sh"

    def _run_agent_commands(self, instruction: str) -> list[TerminalCommand]:
        return [
            TerminalCommand(
                command=(
                    f"aider --message {shlex.quote(instruction)} --yes "
                    f"--model {shlex.quote(self._model_name)}"
                ),
                min_timeout_sec=0.0,
                # block=True: com block=False o harness digitava o comando e
                # corria para os testes SEM esperar o aider — o kimi leva >11s
                # para responder, o arquivo nascia DEPOIS do veredito e TODA
                # tarefa bare saía failure ("Applied edit" tarde demais no
                # cast). O timeout da unidade continua governando o teto.
                max_timeout_sec=1800.0,
                block=True,
                append_enter=True,
            )
        ]
