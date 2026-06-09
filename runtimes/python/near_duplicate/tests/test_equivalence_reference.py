"""Equivalence proof: the vectorised numpy engine == the naive O(n^2) reference.

The whole point of the runtime is to replace the removed hand-rolled PHP O(n^2)
pairwise shingle-Jaccard double loop with one vectorised numpy matmul. This test
re-implements that EXACT naive double loop in plain Python (no numpy) as an
independent reference oracle, then asserts the numpy engine produces a bit-identical
full result on a battery of randomised + adversarial fixtures. If the matmul ever
diverged from textbook set-Jaccard + transitive closure + the PHP ordering rules,
this fails.
"""

from __future__ import annotations

import random
from typing import Any

from atlas_near_duplicate import detect


# --------------------------------------------------------------------------- #
# Independent naive reference (plain Python sets + double loop + union-find)
# --------------------------------------------------------------------------- #
def _clean(tokens: Any) -> list[str]:
    if not isinstance(tokens, list):
        return []
    return [t for t in (str(x).strip() for x in tokens) if t != ""]


def _shingles(tokens: list[str]) -> set[str]:
    if len(tokens) == 1:
        return {tokens[0]}
    return {tokens[i] + "|" + tokens[i + 1] for i in range(len(tokens) - 1)}


def _jaccard(a: set[str], b: set[str]) -> float:
    if not a and not b:
        return 0.0
    inter = len(a & b)
    union = len(a) + len(b) - inter
    return 0.0 if union == 0 else inter / union


def _round4(v: float) -> float:
    # round half away from zero, matching PHP round()
    import math

    f = 10**4
    s = v * f
    r = math.floor(s + 0.5) if s >= 0 else math.ceil(s - 0.5)
    return r / f


def _spaceship(a: Any, b: Any) -> int:
    sa, sb = str(a), str(b)

    def numeric(s: str) -> bool:
        t = s.strip()
        if t == "" or "_" in t:
            return False
        try:
            float(t)
        except ValueError:
            return False
        return t.lower().lstrip("+-") not in {"inf", "infinity", "nan"}

    if numeric(sa) and numeric(sb):
        fa, fb = float(sa), float(sb)
        return -1 if fa < fb else (1 if fa > fb else 0)
    return -1 if sa < sb else (1 if sa > sb else 0)


def _outranks(c: dict, i: dict) -> bool:
    for k in ("priority", "importance", "recency"):
        if c[k] != i[k]:
            return c[k] > i[k]
    return str(c["id"]) < str(i["id"])  # strcmp


def reference_detect(rows: list[dict], threshold: float) -> dict:
    valid = []
    for r in rows:
        toks = _clean(r.get("tokens", []))
        if not toks:
            continue
        rid = r.get("id", "")
        if isinstance(rid, bool):
            rid = str(rid)
        elif not isinstance(rid, int):
            rid = str(rid)
        valid.append(
            {
                "id": rid,
                "sh": _shingles(toks),
                "mt": str(r.get("memory_type", "")),
                "sc": str(r.get("scope", "")),
                "priority": int(r.get("priority", 0) or 0),
                "importance": int(r.get("importance", 0) or 0),
                "recency": int(r.get("recency", 0) or 0),
            }
        )

    groups: dict[str, list[dict]] = {}
    for r in valid:
        groups.setdefault(r["mt"] + "::" + r["sc"], []).append(r)

    clusters = []
    for group in groups.values():
        n = len(group)
        if n < 2:
            continue
        parent = list(range(n))

        def find(x):
            while parent[x] != x:
                parent[x] = parent[parent[x]]
                x = parent[x]
            return x

        def union(a, b):
            ra, rb = find(a), find(b)
            if ra == rb:
                return
            if ra < rb:
                parent[rb] = ra
            else:
                parent[ra] = rb

        qualifying = []
        for i in range(n):
            for j in range(i + 1, n):
                sim = _jaccard(group[i]["sh"], group[j]["sh"])
                if sim >= threshold:
                    union(i, j)
                    qualifying.append((i, j, _round4(sim)))

        by_root: dict[int, list[int]] = {}
        for i in range(n):
            by_root.setdefault(find(i), []).append(i)

        for indices in by_root.values():
            if len(indices) < 2:
                continue
            idx_set = set(indices)
            member_ids = sorted((group[i]["id"] for i in indices), key=lambda x: str(x))
            best = indices[0]
            for i in indices:
                if _outranks(group[i], group[best]):
                    best = i
            canonical_id = group[best]["id"]
            pairs = []
            max_sim = 0.0
            for (i, j, sim) in qualifying:
                if i in idx_set and j in idx_set:
                    pairs.append({"a": group[i]["id"], "b": group[j]["id"], "similarity": sim})
                    if sim > max_sim:
                        max_sim = sim
            import functools

            pairs.sort(
                key=functools.cmp_to_key(
                    lambda p, q: _spaceship(p["a"], q["a"]) or _spaceship(p["b"], q["b"])
                )
            )
            merge = [m for m in member_ids if str(m) != str(canonical_id)]
            clusters.append(
                {
                    "key": group[best]["mt"] + "::" + group[best]["sc"],
                    "member_ids": member_ids,
                    "canonical_id": canonical_id,
                    "merge_candidate_ids": merge,
                    "pairs": pairs,
                    "max_similarity": _round4(max_sim),
                }
            )

    import functools

    clusters.sort(
        key=functools.cmp_to_key(
            lambda c, d: (
                _spaceship(-c["max_similarity"], -d["max_similarity"])
                or _spaceship(c["key"], d["key"])
                or _spaceship(str(c["canonical_id"]), str(d["canonical_id"]))
            )
        )
    )

    return {
        "schema_version": "atlas.memory_governance.near_duplicate.v1",
        "threshold": float(threshold),
        "evaluated": len(valid),
        "clusters": clusters,
        "cluster_count": len(clusters),
        "duplicate_count": sum(len(c["merge_candidate_ids"]) for c in clusters),
    }


def _strip_boundary(result: dict) -> dict:
    r = dict(result)
    r.pop("boundary", None)
    return r


# --------------------------------------------------------------------------- #
# Fixtures
# --------------------------------------------------------------------------- #
def _random_rows(rng: random.Random) -> list[dict]:
    vocab = ["alpha", "beta", "gamma", "delta", "epsilon", "zeta", "eta", "theta", "iota"]
    mtypes = ["decision", "learning", "harness_learning"]
    scopes = ["global", "project"]
    rows = []
    n = rng.randint(0, 14)
    for k in range(n):
        # mix integer ids and string ids to exercise both comparison paths
        rid: Any = k if rng.random() < 0.5 else f"id-{rng.randint(0, 30)}"
        ln = rng.randint(0, 6)
        toks = [rng.choice(vocab) for _ in range(ln)]
        # occasionally inject blanks to exercise the skip/clean path
        if rng.random() < 0.15:
            toks += ["   ", ""]
        rows.append(
            {
                "id": rid,
                "tokens": toks,
                "memory_type": rng.choice(mtypes),
                "scope": rng.choice(scopes),
                "priority": rng.randint(0, 5),
                "importance": rng.randint(0, 5),
                "recency": rng.randint(0, 5),
            }
        )
    return rows


def test_vectorised_equals_naive_reference_on_random_battery():
    rng = random.Random(20260609)
    for _ in range(300):
        rows = _random_rows(rng)
        threshold = rng.choice([0.3, 0.5, 0.6, 0.82, 1.0])
        got = _strip_boundary(detect(rows, threshold))
        want = reference_detect(rows, threshold)
        assert got == want, (threshold, rows, got, want)


def test_vectorised_equals_naive_reference_on_adversarial_integer_ids():
    # Numeric-string id ordering is the subtle PHP `<=>` vs strcmp split.
    rows = [
        {"id": i, "tokens": ["a", "b", "c", "d", "e"], "memory_type": "d", "scope": "g", "priority": 1, "importance": 1, "recency": 1}
        for i in (1, 2, 10, 11, 20, 100, 3)
    ]
    for t in (0.5, 0.82, 1.0):
        assert _strip_boundary(detect(rows, t)) == reference_detect(rows, t)
