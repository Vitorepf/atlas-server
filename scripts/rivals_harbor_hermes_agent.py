"""Harbor Hermes agent that works in benchmark images where apt is removed."""

import os
import shlex

import yaml
from harbor.agents.installed.base import with_prompt_template
from harbor.agents.installed.hermes import Hermes
from harbor.environments.base import BaseEnvironment
from harbor.models.agent.context import AgentContext


class VerbooHermes(Hermes):
    @staticmethod
    def name() -> str:
        return "rivals-hermes-verboo"

    async def install(self, environment) -> None:
        await self.exec_as_agent(
            environment,
            command=(
                "set -euo pipefail; "
                "curl -LsSf https://astral.sh/uv/install.sh | sh && "
                'export PATH="$HOME/.local/bin:$HOME/.cargo/bin:$PATH" && '
                "uv tool install --force --python 3.11 "
                "'hermes-agent @ https://github.com/NousResearch/hermes-agent/archive/refs/heads/main.tar.gz' && "
                'export HERMES_HOME="${HERMES_HOME:-/tmp/hermes}" && '
                'mkdir -p "$HERMES_HOME" "$HERMES_HOME/sessions" "$HERMES_HOME/skills" "$HERMES_HOME/memories" && '
                "hermes version"
            ),
        )

    @with_prompt_template
    async def run(
        self,
        instruction: str,
        environment: BaseEnvironment,
        context: AgentContext,
    ) -> None:
        api_key = os.environ["VERBOO_API_KEY"]
        base_url = os.environ["OPENAI_BASE_URL"]
        model = self.model_name.split("/", 1)[-1]
        config = yaml.safe_load(self._build_config_yaml(model))
        config["model"] = {
            "default": model,
            "provider": "verboo",
            "base_url": "",
        }
        config["providers"] = {
            "verboo": {
                "api": base_url,
                "key_env": "VERBOO_API_KEY",
                "transport": "chat_completions",
                "default_model": model,
                "discover_models": True,
                "request_timeout_seconds": 900,
                "stale_timeout_seconds": 600,
                "models": {model: {"context_length": 1_048_576}},
            }
        }
        config_yaml = yaml.safe_dump(config, sort_keys=False)
        env = {
            "HERMES_HOME": "/tmp/hermes",
            "TERMINAL_ENV": "local",
            "VERBOO_API_KEY": api_key,
            "OPENAI_BASE_URL": base_url,
            "HARBOR_INSTRUCTION": instruction,
        }
        await self.exec_as_agent(
            environment,
            command=(
                "mkdir -p /tmp/hermes && "
                f"cat > /tmp/hermes/config.yaml << 'EOF'\n{config_yaml}EOF"
            ),
            env=env,
            timeout_sec=10,
        )
        run_cmd = (
            'export PATH="$HOME/.local/bin:$PATH" && '
            "hermes --usage-file /logs/agent/hermes-usage.json --yolo chat "
            '-q "$HARBOR_INSTRUCTION" -Q '
            f"--provider verboo --model {shlex.quote(model)} "
            "2>&1 | stdbuf -oL tee /logs/agent/hermes.txt"
        )
        try:
            await self.exec_as_agent(environment, command=run_cmd, env=env)
        finally:
            try:
                await self.exec_as_agent(
                    environment,
                    command=(
                        'export PATH="$HOME/.local/bin:$PATH" && '
                        "hermes sessions export /logs/agent/hermes-session.jsonl "
                        "--source cli 2>/dev/null || true"
                    ),
                    env={"HERMES_HOME": "/tmp/hermes"},
                    timeout_sec=30,
                )
            except Exception:
                pass
