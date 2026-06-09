"""Tests for code_graph cross-repo entity resolution via embeddings (AP-815, X-7).

Runnable with pytest OR directly: `python3 tests/test_entity_resolution.py`
(networkx/pytest may be absent in the local runtime, so it self-runs). The
embedding model ``BAAI/bge-small-en-v1.5`` downloads (~130MB) on first run via
fastembed — that is expected; allow time. Embedding tests assert RELATIVE
clustering membership (robust to exact cosine scores), never absolute floats.
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from atlas_code_graph.entity_resolution import (  # noqa: E402
    SCHEMA,
    resolve_entities,
)


def _cluster_of(result: dict, name: str) -> dict:
    """Return the cluster dict containing a member with the given name."""
    for cluster in result["clusters"]:
        if any(m["name"] == name for m in cluster["members"]):
            return cluster
    raise AssertionError(f"no cluster contains member name {name!r}")


# --- Pure / fail-safe tests (no model needed) -------------------------------

def test_empty_input_is_safe() -> None:
    result = resolve_entities([])
    assert result["schema_version"] == SCHEMA
    assert result["clusters"] == []
    assert result["singletons"] == 0
    assert "note" in result


def test_non_list_input_is_safe() -> None:
    for bad in (None, "garbage", 42, {"id": "x"}):
        result = resolve_entities(bad)
        assert result["clusters"] == []
        assert result["singletons"] == 0


def test_all_garbage_rows_is_safe() -> None:
    # None rows, non-dicts, missing id, missing/blank name, bool id -> all dropped.
    result = resolve_entities(
        [
            None,
            "not-a-dict",
            123,
            {"name": "no id"},
            {"id": "x", "name": "   "},  # blank name skipped
            {"id": "", "name": "blank id"},  # blank id skipped
            {"id": True, "name": "bool id rejected"},  # bool id rejected
        ]
    )
    assert result["clusters"] == []
    assert result["singletons"] == 0
    assert "note" in result


def test_bad_threshold_does_not_raise() -> None:
    # Non-numeric / out-of-range thresholds must fall back, never raise.
    ents = [{"id": "1", "name": "Alpha"}]
    for thr in ("bad", -5, 2.0, float("nan"), float("inf"), None):
        result = resolve_entities(ents, threshold=thr)
        assert result["schema_version"] == SCHEMA
        # single entity -> exactly one singleton cluster regardless of threshold
        assert result["singletons"] == 1
        assert len(result["clusters"]) == 1


def test_threshold_above_one_clamped_single_entity() -> None:
    result = resolve_entities([{"id": "a", "name": "Solo"}], threshold=1.5)
    assert len(result["clusters"]) == 1
    assert result["clusters"][0]["canonical"] == "Solo"
    assert result["clusters"][0]["size"] == 1


# --- Embedding-backed tests (download the model on first run) ---------------

def test_spec_payment_cluster_spans_workspaces_orderrouter_separate() -> None:
    # Spec: "PaymentService"@api + "payment service"@web -> ONE cluster
    # spanning [api, web]; "OrderRouter"@api separate. Assert MEMBERSHIP,
    # robust to exact cosine scores.
    entities = [
        {"id": "e1", "name": "PaymentService", "workspace": "api"},
        {"id": "e2", "name": "payment service", "workspace": "web"},
        {"id": "e3", "name": "OrderRouter", "workspace": "api"},
    ]
    result = resolve_entities(entities)
    assert result["schema_version"] == SCHEMA

    pay = _cluster_of(result, "PaymentService")
    other = _cluster_of(result, "payment service")
    order = _cluster_of(result, "OrderRouter")

    # The two payment entities land in ONE cluster...
    assert pay is other
    pay_names = sorted(m["name"] for m in pay["members"])
    assert pay_names == ["PaymentService", "payment service"]
    assert pay["size"] == 2
    # ...spanning both workspaces, deduped + sorted.
    assert pay["workspaces"] == ["api", "web"]
    # canonical = lexicographically smallest member name.
    assert pay["canonical"] == "PaymentService"

    # OrderRouter is its OWN cluster, not merged with payment.
    assert order is not pay
    assert order["size"] == 1
    assert [m["name"] for m in order["members"]] == ["OrderRouter"]

    # Exactly one singleton (OrderRouter).
    assert result["singletons"] == 1
    assert len(result["clusters"]) == 2
    # Largest cluster first.
    assert result["clusters"][0]["canonical"] == "PaymentService"


def test_identical_names_always_merge() -> None:
    # Identical strings embed identically -> cosine 1.0 -> always one cluster,
    # independent of model quality. Spans both workspaces.
    entities = [
        {"id": "b", "name": "InventoryService", "workspace": "web"},
        {"id": "a", "name": "InventoryService", "workspace": "api"},
    ]
    result = resolve_entities(entities)
    assert len(result["clusters"]) == 1
    cluster = result["clusters"][0]
    assert cluster["size"] == 2
    assert cluster["canonical"] == "InventoryService"
    assert cluster["workspaces"] == ["api", "web"]
    assert result["singletons"] == 0


def test_threshold_one_keeps_distinct_names_apart() -> None:
    # threshold=1.0 means only (near-)identical names merge; two clearly
    # different concepts must remain separate singletons.
    entities = [
        {"id": "1", "name": "PaymentService", "workspace": "api"},
        {"id": "2", "name": "OrderRouter", "workspace": "api"},
    ]
    result = resolve_entities(entities, threshold=1.0)
    assert len(result["clusters"]) == 2
    assert result["singletons"] == 2


def test_workspace_optional_and_deduped() -> None:
    # Missing workspace is tolerated (excluded from the workspaces list, not
    # rendered as None); duplicates collapse.
    entities = [
        {"id": "1", "name": "Widget", "workspace": "api"},
        {"id": "2", "name": "Widget", "workspace": "api"},  # dup workspace
        {"id": "3", "name": "Widget"},  # no workspace
    ]
    result = resolve_entities(entities)
    cluster = _cluster_of(result, "Widget")
    assert cluster["size"] == 3
    # api appears once; the missing-workspace entity contributes no None entry.
    assert cluster["workspaces"] == ["api"]
    for member in cluster["members"]:
        # workspace key always present (None when absent).
        assert "workspace" in member


def test_member_shape_is_complete() -> None:
    result = resolve_entities([{"id": "z9", "name": "Thing", "workspace": "ws"}])
    cluster = result["clusters"][0]
    assert set(cluster.keys()) == {"canonical", "members", "size", "workspaces"}
    member = cluster["members"][0]
    assert member == {"id": "z9", "name": "Thing", "workspace": "ws"}


def test_deterministic_across_runs() -> None:
    entities = [
        {"id": "e3", "name": "OrderRouter", "workspace": "api"},
        {"id": "e1", "name": "PaymentService", "workspace": "api"},
        {"id": "e2", "name": "payment service", "workspace": "web"},
    ]
    # Same input (even shuffled id order) -> identical result.
    assert resolve_entities(entities) == resolve_entities(entities)


def test_int_ids_coerced() -> None:
    # Int ids are accepted and coerced to str so they sort/dedupe cleanly.
    entities = [
        {"id": 2, "name": "PaymentService", "workspace": "api"},
        {"id": 1, "name": "payment service", "workspace": "web"},
    ]
    result = resolve_entities(entities)
    cluster = _cluster_of(result, "PaymentService")
    ids = sorted(m["id"] for m in cluster["members"])
    assert ids == ["1", "2"]
    assert all(isinstance(m["id"], str) for m in cluster["members"])


if __name__ == "__main__":
    failures = 0
    for name, fn in sorted(globals().items()):
        if name.startswith("test_") and callable(fn):
            try:
                fn()
                print(f"PASS {name}")
            except AssertionError as exc:
                failures += 1
                print(f"FAIL {name}: {exc}")
            except Exception as exc:  # noqa: BLE001 — surface unexpected raises
                failures += 1
                print(f"ERROR {name}: {exc!r}")
    print(f"\n{'OK' if failures == 0 else 'FAILED'}: {failures} failure(s)")
    raise SystemExit(1 if failures else 0)
