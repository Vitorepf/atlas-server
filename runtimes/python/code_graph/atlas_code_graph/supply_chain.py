"""Cross-repo dependency / supply-chain graph (AP-815, python_ai_data).

Reveals the CVE-blast surface across an operator's many workspaces: when a
vulnerability lands in package ``X``, *which repos does it actually reach?* A
single shared dependency is a single point of compromise spanning every
workspace that pins it. This is the kind of cross-repo aggregation analytic
that, per atlas-ai-runtime-language-boundaries.md, belongs in the
python_ai_data runtime (the muscle) rather than the Laravel Kernel — the Kernel
hands us the per-workspace manifests (package.json / composer.json / etc.) and
we fold them into one dependency graph plus the shared-package blast view.

Pure stdlib (dict aggregation), deterministic (sorted output), fail-safe
(never raises on malformed input — garbage manifests / deps are skipped and a
safe default is returned). NOT promoted to production until human review
(runtime_promotion_policy.v1).

Input ``manifests``: a list of per-workspace dependency manifests, each
``{"workspace": str, "ecosystem": str, "dependencies": {name: version}}``,
e.g. ``[{"workspace": "api", "ecosystem": "npm",
"dependencies": {"lodash": "4.17.21"}}]``.

Output:
  ``edges``: one ``ws:<workspace> -> pkg:<name>`` edge per (workspace, package)
    with its pinned ``version`` and ``ecosystem``, sorted by (from, to,
    ecosystem, version).
  ``shared``: packages depended on by MORE THAN ONE workspace — the CVE-blast
    view. Each entry carries the affected ``workspaces``, the distinct
    ``versions`` seen (multiple => a version conflict to surface), and
    ``count`` (number of workspaces). Sorted by (-count, package, ecosystem).
  ``packages``: distinct (ecosystem, package) count.
  ``workspaces``: distinct workspace count.

A package is keyed by ``(ecosystem, name)`` so the same name in two different
ecosystems (e.g. an npm ``foo`` and a pypi ``foo``) is never conflated.
"""

from __future__ import annotations

from typing import Any, Dict, List, Tuple

SCHEMA = "atlas.code_graph.supply_chain.v1"


def _clean_str(value: Any) -> str:
    """Coerce a value to a stripped string; non-strings / blanks -> ''."""
    if not isinstance(value, str):
        return ""
    return value.strip()


def _clean_version(value: Any) -> str:
    """Coerce a version to a stripped string.

    Versions are frequently absent / wildcarded ("*", "latest") or expressed as
    non-strings in messy manifests. We keep whatever stripped string is present
    and fall back to ``"*"`` (the universal unpinned marker) so a missing pin
    still surfaces as a (potentially conflicting) version rather than vanishing.
    """
    if isinstance(value, str):
        cleaned = value.strip()
        return cleaned if cleaned else "*"
    if isinstance(value, bool):
        # bool is an int subclass — guard before the numeric branch so True
        # doesn't masquerade as version "1".
        return "*"
    if isinstance(value, (int, float)):
        return str(value)
    return "*"


def supply_chain(manifests: Any) -> Dict[str, Any]:
    """Fold per-workspace manifests into a cross-repo dependency graph.

    For each well-formed manifest we emit one ``ws:<workspace> -> pkg:<name>``
    edge per declared dependency (carrying its version + ecosystem), then
    aggregate by ``(ecosystem, package)`` to surface every package shared by
    more than one workspace (the CVE-blast view) along with any version
    conflicts (multiple distinct versions across those workspaces).

    Args:
        manifests: list of per-workspace dependency manifests, each
            ``{"workspace", "ecosystem", "dependencies": {name: version}}``.

    Returns:
        ``{"schema_version", "edges", "shared", "packages", "workspaces"}``.
        Always a well-formed dict; never raises on malformed input.
    """
    edges: List[Dict[str, Any]] = []
    workspaces: set = set()
    # (ecosystem, package) -> {"workspaces": {ws: True}, "versions": set}
    aggregate: Dict[Tuple[str, str], Dict[str, set]] = {}
    # Dedupe identical (workspace, ecosystem, package) edges (a manifest can't
    # really list the same dep twice in one dict, but two manifests for the
    # same workspace, or coercion collisions, could collide — keep it idempotent).
    seen_edges: set = set()

    if isinstance(manifests, (list, tuple)):
        for manifest in manifests:
            if not isinstance(manifest, dict):
                continue
            workspace = _clean_str(manifest.get("workspace"))
            if not workspace:
                continue
            ecosystem = _clean_str(manifest.get("ecosystem")) or "unknown"
            deps = manifest.get("dependencies")
            if not isinstance(deps, dict):
                continue

            counted_workspace = False
            for raw_name, raw_version in deps.items():
                name = _clean_str(raw_name)
                if not name:
                    continue
                version = _clean_version(raw_version)

                # Only count a workspace once we've accepted >=1 real dep from it
                # so empty/garbage-dep manifests don't inflate the workspace count.
                if not counted_workspace:
                    workspaces.add(workspace)
                    counted_workspace = True

                # Dedupe the EDGE only (first pin wins for the edge's version),
                # but always feed the aggregate so every distinct version reaches
                # the conflict view even when two manifests share a workspace.
                edge_key = (workspace, ecosystem, name)
                if edge_key not in seen_edges:
                    seen_edges.add(edge_key)
                    edges.append(
                        {
                            "from": "ws:" + workspace,
                            "to": "pkg:" + name,
                            "version": version,
                            "ecosystem": ecosystem,
                        }
                    )

                pkg_key = (ecosystem, name)
                bucket = aggregate.get(pkg_key)
                if bucket is None:
                    bucket = {"workspaces": set(), "versions": set()}
                    aggregate[pkg_key] = bucket
                bucket["workspaces"].add(workspace)
                bucket["versions"].add(version)

    # Deterministic edge order.
    edges.sort(key=lambda e: (e["from"], e["to"], e["ecosystem"], e["version"]))

    shared: List[Dict[str, Any]] = []
    for (ecosystem, name), bucket in aggregate.items():
        ws_list = bucket["workspaces"]
        if len(ws_list) <= 1:
            continue  # used by a single workspace -> not a cross-repo blast
        shared.append(
            {
                "package": name,
                "ecosystem": ecosystem,
                "workspaces": sorted(ws_list),
                "versions": sorted(bucket["versions"]),
                "count": len(ws_list),
            }
        )
    # Widest blast radius first; ties broken lexically (package, ecosystem).
    shared.sort(key=lambda s: (-s["count"], s["package"], s["ecosystem"]))

    return {
        "schema_version": SCHEMA,
        "edges": edges,
        "shared": shared,
        "packages": len(aggregate),
        "workspaces": len(workspaces),
    }
