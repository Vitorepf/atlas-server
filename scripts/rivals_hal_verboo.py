#!/usr/bin/env python3
"""HAL runtime overlay for the operator's zero-marginal Verboo subscription."""

from hal.utils import weave_utils


weave_utils.MODEL_PRICES_DICT["openai/kimi-k2.7"] = {
    "prompt_tokens": 0.0,
    "completion_tokens": 0.0,
}
# Provider usage is recorded by the HAL agent in task_metrics. Do not query the
# external Weave service in a local-first Rivals run.
weave_utils.get_total_cost = lambda client: (0.0, {})
weave_utils.get_weave_calls = lambda client: ({}, {})


if __name__ == "__main__":
    from hal.cli import main

    main()
