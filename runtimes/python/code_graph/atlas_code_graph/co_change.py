"""Co-change (temporal coupling) edges from git history (AP-815, python_ai_data).

Reveals HIDDEN coupling that AST/import analysis cannot see: two files that
keep changing together in the same commit are coupled in practice even when
there is no static reference between them. This is the kind of heavy
history-aggregation analytic that, per atlas-ai-runtime-language-boundaries.md,
belongs in the python_ai_data runtime (the muscle) rather than the Laravel
Kernel — the Kernel hands us the commit->files matrix and we compute over it.

Pure stdlib (itertools.combinations + collections.Counter), deterministic,
fail-safe (never raises on malformed input — garbage entries are skipped and a
safe default is returned). NOT promoted to production until human review
(runtime_promotion_policy.v1).

Input ``commits``: a list of commits, each a list of file paths changed in that
commit, e.g. ``[["a.py", "b.py", "c.py"], ["a.py", "b.py"]]``.
Output: deterministic co-change edges with ``from`` < ``to`` (lexical canonical
direction) so unordered pairs are deduped, sorted by (-weight, from, to).
"""

from __future__ import annotations

from collections import Counter
from itertools import combinations
from typing import Any, Dict, List

SCHEMA = "atlas.code_graph.co_change.v1"


def _clean_commit(commit: Any) -> List[str]:
    """Coerce one commit into a deterministic list of distinct file paths.

    Non-list commits and non-string / empty path entries are dropped. Order is
    sorted so that ``combinations`` always yields pairs in canonical
    (from < to) lexical direction regardless of the caller's ordering.
    """
    if not isinstance(commit, (list, tuple)):
        return []
    seen = set()
    for path in commit:
        if not isinstance(path, str):
            continue
        path = path.strip()
        if not path:
            continue
        seen.add(path)
    return sorted(seen)


def co_change(
    commits: Any,
    min_support: int = 2,
    max_pairs: int = 100000,
) -> Dict[str, Any]:
    """Aggregate co-change (temporal coupling) edges from commit file-sets.

    For each commit, every unordered pair of distinct files that changed
    together contributes one count; counts are aggregated across all commits.
    An edge is emitted per pair whose total co-change count >= ``min_support``,
    in canonical ``from`` < ``to`` direction (so pairs are deduped), sorted
    deterministically by (-weight, from, to) and bounded by ``max_pairs``.

    Args:
        commits: list of commits, each a list of file paths changed together.
        min_support: minimum co-change count for a pair to be emitted (>= 1).
        max_pairs: hard cap on the number of emitted edges.

    Returns:
        ``{"schema_version", "edges": [{"from", "to", "weight", "support"}],
        "files": int, "commits": int}``. Always a well-formed dict; never
        raises on malformed input.
    """
    try:
        min_support = int(min_support)
    except (TypeError, ValueError):
        min_support = 2
    if min_support < 1:
        min_support = 1

    try:
        max_pairs = int(max_pairs)
    except (TypeError, ValueError):
        max_pairs = 100000
    if max_pairs < 0:
        max_pairs = 0

    pair_counts: Counter = Counter()
    files = set()
    commit_count = 0

    if isinstance(commits, (list, tuple)):
        for commit in commits:
            cleaned = _clean_commit(commit)
            if not cleaned:
                # Skip empty / garbage commits but still count real commits.
                if isinstance(commit, (list, tuple)):
                    commit_count += 1
                continue
            commit_count += 1
            files.update(cleaned)
            # cleaned is sorted -> each pair is already (from < to).
            for a, b in combinations(cleaned, 2):
                pair_counts[(a, b)] += 1

    edges: List[Dict[str, Any]] = [
        {"from": a, "to": b, "weight": count, "support": count}
        for (a, b), count in pair_counts.items()
        if count >= min_support
    ]
    # Deterministic order: strongest coupling first, then lexical for ties.
    edges.sort(key=lambda e: (-e["weight"], e["from"], e["to"]))

    if len(edges) > max_pairs:
        edges = edges[:max_pairs]

    return {
        "schema_version": SCHEMA,
        "edges": edges,
        "files": len(files),
        "commits": commit_count,
    }
