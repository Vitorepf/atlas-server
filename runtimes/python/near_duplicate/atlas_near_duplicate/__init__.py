"""Atlas near-duplicate-memory Python data runtime.

Real contiguous-bigram-shingle Jaccard near-duplicate detection over memory rows,
computed in numpy (vectorised pairwise similarity) per the runtime_language_boundary
canon and the operator thesis (data math belongs in Python, never hand-rolled in the
PHP kernel; the kernel scanner forbids reimplementing engines in PHP). Never
reimplemented in PHP; the PHP kernel invokes this runtime via the signed boundary.
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
from .near_duplicate import (
    SCHEMA_VERSION,
    SIMILARITY_PRECISION,
    detect,
)

__all__ = [
    "run_manifest",
    "validate_manifest",
    "probe",
    "ManifestError",
    "REQUEST_SCHEMA",
    "RECEIPT_SCHEMA",
    "detect",
    "SCHEMA_VERSION",
    "SIMILARITY_PRECISION",
]
