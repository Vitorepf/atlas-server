"""Atlas world-model graph-ranking Python data runtime.

Real node-centrality / edge-weight propagation / incoming-outgoing scoring,
computed in networkx + numpy per the runtime_language_boundary canon (never in
the PHP kernel, never faked). Faithful, behaviour-preserving port of the removed
hand-rolled PHP math in WorldModelGraphRanker.
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
from .scoring import SCHEMA, rank_nodes

__all__ = [
    "run_manifest",
    "validate_manifest",
    "probe",
    "ManifestError",
    "REQUEST_SCHEMA",
    "RECEIPT_SCHEMA",
    "rank_nodes",
    "SCHEMA",
]
