"""Behavioural tests for the near_duplicate numpy engine.

These pin the engine to the EXACT behaviour of the removed hand-rolled PHP
MemoryNearDuplicateDetector (every case from its PHP unit test is ported here),
plus the byte-identical edge cases the equivalence proof surfaced:

  - PHP `<=>` is numeric-aware for numeric-string ids (pairs & cluster ordering),
    while `strcmp` (member_ids / canonical tie-break) is pure byte order;
  - integer-id pair ordering ('1' before '10' before '2' is WRONG for pairs but
    RIGHT for member_ids).

They also prove the engine is REAL numpy (probe + boundary receipt), so a PHP
hand-rolled stand-in could never satisfy this suite.
"""

from __future__ import annotations

import numpy as np

from atlas_near_duplicate import SCHEMA_VERSION, detect, probe, run_manifest
from atlas_near_duplicate.contract import ManifestError


def _member_ids(result: dict) -> list:
    out = []
    for c in result["clusters"]:
        out.extend(c["member_ids"])
    return out


# --------------------------------------------------------------------------- #
# Real-engine proofs (a PHP fake / hash stub cannot pass these)
# --------------------------------------------------------------------------- #
def test_engine_is_real_numpy_probe():
    p = probe()
    assert p["available"] is True
    assert p["library"] == "numpy"
    assert p["numpy_version"] == np.__version__


def test_result_carries_real_boundary_receipt():
    result = detect(
        [
            {"id": "a", "tokens": ["x", "y", "z"], "memory_type": "d", "scope": "g"},
            {"id": "b", "tokens": ["x", "y", "z"], "memory_type": "d", "scope": "g"},
        ],
        0.5,
    )
    b = result["boundary"]
    assert b["near_duplicate_in_python"] is True
    assert b["fabricated"] is False
    assert b["real_jaccard"] is True
    assert b["library"] == "numpy"


def test_jaccard_matmul_matches_manual_set_jaccard():
    # The vectorised matmul Jaccard must equal the textbook set definition on a
    # known case: identical -> 1.0, one-shingle-shared-of-three -> 1/3.
    from atlas_near_duplicate.near_duplicate import _jaccard_matrix, _normalize_rows

    rows = _normalize_rows(
        [
            {"id": 1, "tokens": ["a", "b", "c"], "memory_type": "d", "scope": "g"},
            {"id": 2, "tokens": ["a", "b", "c"], "memory_type": "d", "scope": "g"},
            {"id": 3, "tokens": ["c", "d", "e"], "memory_type": "d", "scope": "g"},
        ]
    )
    m = _jaccard_matrix(rows)
    assert m[0, 1] == 1.0
    # shingles {a|b,b|c} vs {c|d,d|e}: intersection 0 -> 0.0
    assert m[0, 2] == 0.0
    # shingles {a|b,b|c} vs {b|c,c|d}: |∩|=1 (b|c), |∪|=3 -> 1/3
    rows2 = _normalize_rows(
        [
            {"id": 1, "tokens": ["a", "b", "c"], "memory_type": "d", "scope": "g"},
            {"id": 2, "tokens": ["b", "c", "d"], "memory_type": "d", "scope": "g"},
        ]
    )
    m2 = _jaccard_matrix(rows2)
    assert abs(m2[0, 1] - (1.0 / 3.0)) < 1e-12


# --------------------------------------------------------------------------- #
# Ported PHP MemoryNearDuplicateDetectorTest cases
# --------------------------------------------------------------------------- #
def test_schema_version_and_threshold_echo():
    result = detect([], 0.82)
    assert result["schema_version"] == SCHEMA_VERSION
    assert result["schema_version"] == "atlas.memory_governance.near_duplicate.v1"
    assert result["threshold"] == 0.82
    assert result["evaluated"] == 0
    assert result["clusters"] == []
    assert result["cluster_count"] == 0
    assert result["duplicate_count"] == 0


def test_threshold_boundary_uses_greater_or_equal():
    rows = [
        {"id": "eq-a", "tokens": ["a", "b", "c", "d", "e"], "memory_type": "decision", "scope": "global", "priority": 5, "importance": 1, "recency": 1},
        {"id": "eq-b", "tokens": ["a", "b", "c", "d", "f"], "memory_type": "decision", "scope": "global", "priority": 1, "importance": 1, "recency": 1},
        {"id": "lo-a", "tokens": ["m", "n", "o", "p", "q"], "memory_type": "learning", "scope": "global", "priority": 1, "importance": 1, "recency": 1},
        {"id": "lo-b", "tokens": ["m", "n", "z", "p", "q"], "memory_type": "learning", "scope": "global", "priority": 1, "importance": 1, "recency": 1},
    ]
    result = detect(rows, 0.6)
    assert result["cluster_count"] == 1
    cluster = result["clusters"][0]
    assert cluster["member_ids"] == ["eq-a", "eq-b"]
    assert cluster["max_similarity"] == 0.6
    assert cluster["key"] == "decision::global"
    assert "lo-a" not in _member_ids(result)
    assert "lo-b" not in _member_ids(result)


def test_transitive_closure_merges_chain_into_single_cluster():
    rows = [
        {"id": "A", "tokens": ["a", "b", "c", "d"], "memory_type": "decision", "scope": "global", "priority": 1, "importance": 1, "recency": 1},
        {"id": "B", "tokens": ["a", "b", "c", "x"], "memory_type": "decision", "scope": "global", "priority": 1, "importance": 1, "recency": 1},
        {"id": "C", "tokens": ["y", "b", "c", "x"], "memory_type": "decision", "scope": "global", "priority": 1, "importance": 1, "recency": 1},
    ]
    result = detect(rows, 0.5)
    assert result["cluster_count"] == 1
    cluster = result["clusters"][0]
    assert cluster["member_ids"] == ["A", "B", "C"]
    assert cluster["max_similarity"] == 0.5
    pair_keys = [f"{p['a']}-{p['b']}" for p in cluster["pairs"]]
    assert "A-B" in pair_keys
    assert "B-C" in pair_keys
    assert "A-C" not in pair_keys
    assert len(cluster["pairs"]) == 2


def test_canonical_chosen_by_highest_priority():
    rows = [
        {"id": "A", "tokens": ["a", "b", "c", "d"], "memory_type": "decision", "scope": "global", "priority": 3, "importance": 9, "recency": 9},
        {"id": "B", "tokens": ["a", "b", "c", "x"], "memory_type": "decision", "scope": "global", "priority": 7, "importance": 1, "recency": 1},
        {"id": "C", "tokens": ["y", "b", "c", "x"], "memory_type": "decision", "scope": "global", "priority": 5, "importance": 5, "recency": 5},
    ]
    result = detect(rows, 0.5)
    assert result["cluster_count"] == 1
    cluster = result["clusters"][0]
    assert cluster["canonical_id"] == "B"
    assert cluster["merge_candidate_ids"] == ["A", "C"]


def test_canonical_tie_break_picks_lexicographically_smallest_id():
    rows = [
        {"id": "mem-b", "tokens": ["a", "b", "c", "d", "e"], "memory_type": "decision", "scope": "global", "priority": 4, "importance": 4, "recency": 4},
        {"id": "mem-a", "tokens": ["a", "b", "c", "d", "f"], "memory_type": "decision", "scope": "global", "priority": 4, "importance": 4, "recency": 4},
    ]
    result = detect(rows, 0.6)
    assert result["cluster_count"] == 1
    cluster = result["clusters"][0]
    assert cluster["canonical_id"] == "mem-a"
    assert cluster["merge_candidate_ids"] == ["mem-b"]


def test_scope_isolation_yields_zero_clusters():
    rows = [
        {"id": "x1", "tokens": ["a", "b", "c", "d", "e"], "memory_type": "decision", "scope": "global", "priority": 1, "importance": 1, "recency": 1},
        {"id": "x2", "tokens": ["a", "b", "c", "d", "e"], "memory_type": "learning", "scope": "global", "priority": 1, "importance": 1, "recency": 1},
        {"id": "x3", "tokens": ["a", "b", "c", "d", "e"], "memory_type": "decision", "scope": "project", "priority": 1, "importance": 1, "recency": 1},
    ]
    result = detect(rows, 0.5)
    assert result["cluster_count"] == 0
    assert result["clusters"] == []
    assert result["duplicate_count"] == 0
    assert result["evaluated"] == 3


def test_one_token_difference_detected_and_empty_rows_skipped():
    rows = [
        {"id": "near-1", "tokens": ["a", "b", "c", "d", "e"], "memory_type": "decision", "scope": "global", "priority": 1, "importance": 1, "recency": 1},
        {"id": "near-2", "tokens": ["a", "b", "c", "d", "f"], "memory_type": "decision", "scope": "global", "priority": 1, "importance": 1, "recency": 1},
        {"id": "empty-1", "tokens": [], "memory_type": "decision", "scope": "global", "priority": 9, "importance": 9, "recency": 9},
        {"id": "blank-1", "tokens": ["   ", ""], "memory_type": "decision", "scope": "global", "priority": 9, "importance": 9, "recency": 9},
    ]
    result = detect(rows, 0.5)
    assert result["evaluated"] == 2
    assert result["cluster_count"] == 1
    cluster = result["clusters"][0]
    assert cluster["member_ids"] == ["near-1", "near-2"]
    assert 0.5 < cluster["max_similarity"] < 1.0
    assert cluster["max_similarity"] == 0.6
    assert "empty-1" not in _member_ids(result)
    assert "blank-1" not in _member_ids(result)


def test_clusters_ordered_by_max_similarity_desc_then_key_asc():
    rows = [
        {"id": "b1", "tokens": ["a", "b", "c", "d", "e"], "memory_type": "beta", "scope": "global", "priority": 1, "importance": 1, "recency": 1},
        {"id": "b2", "tokens": ["a", "b", "c", "d", "f"], "memory_type": "beta", "scope": "global", "priority": 1, "importance": 1, "recency": 1},
        {"id": "a1", "tokens": ["p", "q", "r", "s"], "memory_type": "alpha", "scope": "global", "priority": 1, "importance": 1, "recency": 1},
        {"id": "a2", "tokens": ["p", "q", "r", "s"], "memory_type": "alpha", "scope": "global", "priority": 1, "importance": 1, "recency": 1},
    ]
    result = detect(rows, 0.5)
    assert result["cluster_count"] == 2
    assert result["clusters"][0]["max_similarity"] == 1.0
    assert result["clusters"][0]["key"] == "alpha::global"
    assert result["clusters"][1]["max_similarity"] == 0.6
    assert result["clusters"][1]["key"] == "beta::global"
    assert result["duplicate_count"] == 2


def test_key_tie_break_ascending_when_max_similarity_equal():
    rows = [
        {"id": "p1", "tokens": ["a", "b", "c", "d"], "memory_type": "k-b", "scope": "global", "priority": 1, "importance": 1, "recency": 1},
        {"id": "p2", "tokens": ["a", "b", "c", "d"], "memory_type": "k-b", "scope": "global", "priority": 1, "importance": 1, "recency": 1},
        {"id": "q1", "tokens": ["e", "f", "g", "h"], "memory_type": "k-a", "scope": "global", "priority": 1, "importance": 1, "recency": 1},
        {"id": "q2", "tokens": ["e", "f", "g", "h"], "memory_type": "k-a", "scope": "global", "priority": 1, "importance": 1, "recency": 1},
    ]
    result = detect(rows, 0.5)
    assert result["cluster_count"] == 2
    assert result["clusters"][0]["max_similarity"] == 1.0
    assert result["clusters"][1]["max_similarity"] == 1.0
    assert result["clusters"][0]["key"] == "k-a::global"
    assert result["clusters"][1]["key"] == "k-b::global"


def test_deterministic_across_repeated_runs():
    rows = [
        {"id": "A", "tokens": ["a", "b", "c", "d"], "memory_type": "decision", "scope": "global", "priority": 1, "importance": 1, "recency": 1},
        {"id": "B", "tokens": ["a", "b", "c", "x"], "memory_type": "decision", "scope": "global", "priority": 1, "importance": 1, "recency": 1},
        {"id": "C", "tokens": ["y", "b", "c", "x"], "memory_type": "decision", "scope": "global", "priority": 1, "importance": 1, "recency": 1},
    ]
    assert detect(rows, 0.5) == detect(rows, 0.5)


# --------------------------------------------------------------------------- #
# Byte-identity edge cases the equivalence proof surfaced
# --------------------------------------------------------------------------- #
def test_integer_id_member_order_is_lexicographic_strcmp():
    # member_ids uses PHP strcmp -> '1' < '10' < '2' (lexicographic, NOT numeric).
    rows = [
        {"id": 1, "tokens": ["a", "b", "c", "d", "e"], "memory_type": "d", "scope": "g", "priority": 1, "importance": 1, "recency": 1},
        {"id": 2, "tokens": ["a", "b", "c", "d", "e"], "memory_type": "d", "scope": "g", "priority": 1, "importance": 1, "recency": 1},
        {"id": 10, "tokens": ["a", "b", "c", "d", "e"], "memory_type": "d", "scope": "g", "priority": 1, "importance": 1, "recency": 1},
    ]
    result = detect(rows, 0.5)
    cluster = result["clusters"][0]
    assert cluster["member_ids"] == [1, 10, 2]  # strcmp order
    assert cluster["canonical_id"] == 1  # smallest by strcmp tie-break


def test_integer_id_pair_order_is_php_spaceship_numeric():
    # pairs use PHP `<=>` over the string casts, which is NUMERIC for numeric
    # strings -> (1,2) before (1,10) before (2,10). This is the case the naive
    # lexicographic Python sort got WRONG before the fix.
    rows = [
        {"id": 1, "tokens": ["a", "b", "c", "d", "e"], "memory_type": "d", "scope": "g", "priority": 1, "importance": 1, "recency": 1},
        {"id": 2, "tokens": ["a", "b", "c", "d", "e"], "memory_type": "d", "scope": "g", "priority": 1, "importance": 1, "recency": 1},
        {"id": 10, "tokens": ["a", "b", "c", "d", "e"], "memory_type": "d", "scope": "g", "priority": 1, "importance": 1, "recency": 1},
    ]
    result = detect(rows, 0.5)
    cluster = result["clusters"][0]
    pair_order = [(p["a"], p["b"]) for p in cluster["pairs"]]
    assert pair_order == [(1, 2), (1, 10), (2, 10)]


def test_max_similarity_is_float_one_for_identical_rows():
    # The PHP unit test asserts assertSame(1.0, ...) — a float. Identical rows must
    # produce max_similarity == 1.0 (float), which round-trips JSON to a PHP float.
    rows = [
        {"id": "a", "tokens": ["x", "y", "z"], "memory_type": "d", "scope": "g", "priority": 1, "importance": 1, "recency": 1},
        {"id": "b", "tokens": ["x", "y", "z"], "memory_type": "d", "scope": "g", "priority": 1, "importance": 1, "recency": 1},
    ]
    result = detect(rows, 0.5)
    ms = result["clusters"][0]["max_similarity"]
    assert ms == 1.0
    assert isinstance(ms, float)


# --------------------------------------------------------------------------- #
# Manifest / boundary contract
# --------------------------------------------------------------------------- #
def test_run_manifest_detect_round_trip():
    manifest = {
        "operation": "detect",
        "threshold": 0.5,
        "rows": [
            {"id": "a", "tokens": ["x", "y", "z"], "memory_type": "d", "scope": "g"},
            {"id": "b", "tokens": ["x", "y", "z"], "memory_type": "d", "scope": "g"},
        ],
    }
    result = run_manifest(manifest)
    assert result["cluster_count"] == 1
    assert result["boundary"]["near_duplicate_in_python"] is True


def test_manifest_rejects_forbidden_keys():
    import pytest

    with pytest.raises(ManifestError):
        run_manifest({"operation": "detect", "rows": [], "provider": "claude"})


def test_manifest_rejects_unknown_operation():
    import pytest

    with pytest.raises(ManifestError):
        run_manifest({"operation": "nope", "rows": []})


def test_manifest_requires_rows():
    import pytest

    with pytest.raises(ManifestError):
        run_manifest({"operation": "detect"})
