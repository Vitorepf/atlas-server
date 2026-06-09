"""Atlas honest-finance-metrics Python data runtime.

Real moments / Deflated Sharpe (Bailey-López de Prado 2014 + Lo 2002 variance
floor) / PBO via CSCV, computed in numpy per the runtime_language_boundary canon
and the operator thesis (data math belongs in Python, never hand-rolled in the PHP
kernel). Never reimplemented in PHP; the PHP kernel invokes this runtime.
"""

from __future__ import annotations

from .contract import (
    RECEIPT_SCHEMA,
    REQUEST_SCHEMA,
    ManifestError,
    probe,
    run_manifest,
    validate_manifest,
)
from .metrics import (
    deflated_sharpe,
    deflated_sharpe_ratio,
    erf,
    inverse_normal_cdf,
    kurtosis,
    max_drawdown,
    mean,
    normal_cdf,
    pbo,
    per_period_sharpe,
    sharpe,
    skewness,
    std,
)

__all__ = [
    "run_manifest",
    "validate_manifest",
    "probe",
    "ManifestError",
    "REQUEST_SCHEMA",
    "RECEIPT_SCHEMA",
    "mean",
    "std",
    "skewness",
    "kurtosis",
    "per_period_sharpe",
    "sharpe",
    "max_drawdown",
    "deflated_sharpe",
    "deflated_sharpe_ratio",
    "pbo",
    "normal_cdf",
    "inverse_normal_cdf",
    "erf",
]
