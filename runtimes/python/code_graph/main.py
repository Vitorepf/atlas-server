"""Entry point for the code_graph python_ai_data runtime (AP-812).

Matches the manifest-in / json-out convention of the existing
programming_intelligence runtime. Invoked by the Laravel Kernel via a signed
runtime.invoke payload; never decides domain/provider/policy on its own.

Usage: python3 main.py <manifest.json>
Manifest: {"op": "betweenness", "edges": [...], "limit": 20, "normalized": true}
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

from atlas_code_graph import betweenness_centrality

_OPS = {
    "betweenness": lambda m: betweenness_centrality(
        m.get("edges", []),
        normalized=bool(m.get("normalized", True)),
        limit=int(m.get("limit", 20)),
    ),
}


def main(argv: list[str]) -> int:
    if len(argv) != 2:
        print(json.dumps({"ok": False, "error": "manifest_path_required"}))
        return 2

    try:
        manifest = json.loads(Path(argv[1]).read_text(encoding="utf-8"))
        op = manifest.get("op", "betweenness")
        handler = _OPS.get(op)
        if handler is None:
            print(json.dumps({"ok": False, "error": f"unknown_op:{op}"}))
            return 1
        result = handler(manifest)
    except Exception as exc:  # noqa: BLE001 — boundary returns structured error
        print(json.dumps({"ok": False, "error": str(exc)}))
        return 1

    print(json.dumps({"ok": True, "result": result}, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
