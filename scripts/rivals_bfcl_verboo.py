#!/usr/bin/env python3
"""Runtime-only BFCL model registration for the authorized Verboo endpoint."""

import json
import sys

from bfcl_eval.constants.model_config import MODEL_CONFIG_MAPPING, ModelConfig
from bfcl_eval.model_handler.api_inference.openai_completion import (
    OpenAICompletionsHandler,
)


MODEL_CONFIG_MAPPING["kimi-k2.7-FC"] = ModelConfig(
    model_name="kimi-k2.7",
    display_name="Kimi K2.7 via Verboo (FC)",
    url="https://code.verboo.ai",
    org="MoonshotAI / Verboo",
    license="Proprietary",
    model_handler=OpenAICompletionsHandler,
    input_price=0.0,
    output_price=0.0,
    is_fc_model=True,
    underscore_to_dot=True,
)


def take_option(name: str) -> str | None:
    prefix = f"{name}="
    for index, value in enumerate(list(sys.argv)):
        if value.startswith(prefix):
            sys.argv.pop(index)
            return value[len(prefix) :]
    return None


if __name__ == "__main__":
    native_test_id = take_option("--rivals-test-id")
    native_category = take_option("--rivals-category")
    if native_test_id and native_category:
        from bfcl_eval.constants.eval_config import PROJECT_ROOT

        (PROJECT_ROOT / "test_case_ids_to_generate.json").write_text(
            json.dumps({native_category: [native_test_id]})
        )
    from bfcl_eval.__main__ import cli

    cli()
