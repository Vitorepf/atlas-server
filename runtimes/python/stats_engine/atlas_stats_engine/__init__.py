"""Atlas telemetry-statistics Python data runtime.

Real KS / Mann-Whitney / Mann-Kendall / CUSUM / EWMA / Wilson, computed in numpy
per the runtime_language_boundary canon (never in the PHP kernel, never faked).
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
from .stats import (
    cusum,
    ewma,
    kolmogorov_smirnov,
    mann_kendall,
    wilson,
)

__all__ = [
    "run_manifest",
    "validate_manifest",
    "probe",
    "ManifestError",
    "REQUEST_SCHEMA",
    "RECEIPT_SCHEMA",
    "kolmogorov_smirnov",
    "mann_kendall",
    "cusum",
    "ewma",
    "wilson",
]
