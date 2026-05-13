from __future__ import annotations

import json
import sys
from pathlib import Path

from atlas_programming_intelligence import analyze_manifest


def main(argv: list[str]) -> int:
    if len(argv) != 2:
        print(json.dumps({"ok": False, "error": "manifest_path_required"}))
        return 2

    try:
        manifest = json.loads(Path(argv[1]).read_text(encoding="utf-8"))
        result = analyze_manifest(manifest)
    except Exception as exc:
        print(json.dumps({"ok": False, "error": str(exc)}))
        return 1

    print(json.dumps({"ok": True, "result": result}, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
