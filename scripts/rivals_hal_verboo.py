#!/usr/bin/env python3
"""HAL runtime overlay for the operator's zero-marginal Verboo subscription."""

from hal.utils.weave_utils import MODEL_PRICES_DICT


MODEL_PRICES_DICT["openai/kimi-k2.7"] = {
    "prompt_tokens": 0.0,
    "completion_tokens": 0.0,
}


if __name__ == "__main__":
    from hal.cli import main

    main()
