#!/usr/bin/env python3
"""Aggregate, reproducible test runner for the Atlas Code Graph python runtime.

Pure standard library — pytest is NOT required (and is not installed). Every
``test_*.py`` in this tree self-runs via an ``if __name__ == "__main__"`` footer
that prints ``PASS``/``FAIL``/``ERROR`` lines and exits 0 on success, non-zero on
failure. This runner discovers those files, executes each as a subprocess with
the SAME interpreter that launched it, and produces a single honest summary so a
silently-skipped (dep-gated) test can no longer masquerade as a pass.

Run it with the venv interpreter so the optional deps resolve::

    cd runtimes/python/code_graph && .venv/bin/python run_tests.py

Exit code is non-zero iff at least one test file FAILED.

Skip semantics
--------------
The code_graph tests are written to degrade safely: when an optional dependency
(fastembed / whisper / igraph / leidenalg / PIL / fpdf / pypdf / tree_sitter …)
is absent they generally assert the documented fail-safe contract and still
"pass" — but the heavy code path was never exercised. To stop that from reading
as a genuine green, this runner labels a file ``SKIPPED(dep)`` when BOTH hold:

1. the file statically references an optional dependency that is NOT importable
   in the current interpreter, and
2. the run did not actually FAIL (a real failure is always reported as FAILED,
   never hidden behind a skip label).

It also scans captured output for hard import-failure markers
(``ImportError`` / ``ModuleNotFoundError`` / ``not available`` / an explicit
pytest-style ``SKIPPED``) as a secondary signal, so a dep that breaks at import
time is surfaced rather than silently counted. The bare word "skip" is
deliberately NOT used as a signal: several tests legitimately contain it in test
names ("test_unknown_language_skipped") and fixture data.
"""

from __future__ import annotations

import importlib.util
import os
import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path

# --------------------------------------------------------------------------- #
# Configuration
# --------------------------------------------------------------------------- #

# Root of the code_graph runtime == directory holding this file. Used both for
# discovery and as the PYTHONPATH entry so ``import atlas_code_graph...`` works
# regardless of the caller's cwd.
ROOT = Path(__file__).resolve().parent

# Optional dependencies that gate "heavy" code paths in the suite. If a test
# file statically references one of these and it is NOT importable here, the
# file's heavy path could not have run, so the file is labeled SKIPPED(dep)
# (unless it actually FAILED). Keyed by the import name; the value is the set of
# substrings that, when found in a file's source, mean "this file needs it".
OPTIONAL_DEPS: dict[str, tuple[str, ...]] = {
    "fastembed": ("fastembed",),
    "whisper": ("whisper",),
    "igraph": ("igraph",),
    "leidenalg": ("leidenalg",),
    "PIL": ("PIL", "from PIL", "import PIL"),
    "fpdf": ("fpdf",),
    "pypdf": ("pypdf",),
    # Real heavy dep is the parser bundle; the base `tree_sitter` is not enough.
    # Substrings include the modules that transitively require it (callgraph /
    # typed_callgraph tests reference it only indirectly, never by name).
    "tree_sitter_language_pack": ("tree_sitter", "treesitter", "callgraph"),
    "sentence_transformers": ("sentence_transformers",),
}

# Hard markers in captured output that indicate a real import-time skip/failure
# of an optional path (NOT the benign word "skip"). Matched case-sensitively
# except where noted in the scan.
IMPORT_FAILURE_MARKERS: tuple[str, ...] = (
    "ImportError",
    "ModuleNotFoundError",
    "ModuleNotFoundError".lower(),  # defensive
    "not available",
    "SKIPPED",  # pytest-style explicit skip, should a file ever emit one
)

# How many characters of stdout/stderr to show for a failing file.
TAIL_CHARS = 3000

# Per-file wall-clock budget (seconds). Heavy embedding/model paths can be slow
# on a cold cache; keep generous but bounded so a hang is reported, not eternal.
PER_FILE_TIMEOUT = 600


@dataclass
class Result:
    path: Path
    rel: str
    returncode: int
    stdout: str
    stderr: str
    timed_out: bool
    missing_deps: list[str]  # optional deps this file needs that are absent

    @property
    def passed(self) -> bool:
        return (not self.timed_out) and self.returncode == 0

    @property
    def failed(self) -> bool:
        return self.timed_out or self.returncode != 0

    @property
    def import_failure_in_output(self) -> bool:
        blob = f"{self.stdout}\n{self.stderr}"
        return any(m and m in blob for m in IMPORT_FAILURE_MARKERS)

    @property
    def dep_skipped(self) -> bool:
        """Passed, but a needed optional dep is missing (heavy path not run)."""
        return self.passed and bool(self.missing_deps)


# --------------------------------------------------------------------------- #
# Helpers
# --------------------------------------------------------------------------- #

def _is_importable(module: str) -> bool:
    """True if ``module`` can be imported in this interpreter (no side effects)."""
    try:
        return importlib.util.find_spec(module) is not None
    except (ImportError, ValueError, ModuleNotFoundError):
        # find_spec can raise for a partially-broken package; treat as absent.
        return False


def _missing_optional_deps() -> set[str]:
    """Subset of OPTIONAL_DEPS not importable here, computed once up front."""
    return {dep for dep in OPTIONAL_DEPS if not _is_importable(dep)}


def discover_tests() -> list[Path]:
    """Every ``test_*.py`` under ROOT, recursively, excluding any venv tree.

    Covers both the package root and a ``tests/`` subdir. Vendored tests inside
    a virtualenv (``.venv``/``venv``/``site-packages``) are excluded so the
    suite reports on code_graph's own tests only.
    """
    excluded_parts = {".venv", "venv", "site-packages", "__pycache__"}
    found: list[Path] = []
    for path in ROOT.rglob("test_*.py"):
        if path.name == "run_tests.py":
            continue
        if any(part in excluded_parts for part in path.relative_to(ROOT).parts):
            continue
        found.append(path)
    return sorted(found)


def _needed_missing_deps(source: str, missing: set[str]) -> list[str]:
    """Optional deps a file references that are also currently missing."""
    needed: list[str] = []
    for dep in missing:
        substrings = OPTIONAL_DEPS[dep]
        if any(s in source for s in substrings):
            needed.append(dep)
    return sorted(needed)


def run_one(path: Path, missing: set[str]) -> Result:
    """Execute a single test file as a subprocess and capture its outcome."""
    try:
        source = path.read_text(encoding="utf-8", errors="replace")
    except OSError:
        source = ""
    needed_missing = _needed_missing_deps(source, missing)

    env = dict(os.environ)
    # Prepend the code_graph root so ``import atlas_code_graph...`` resolves even
    # though the files also self-insert it; belt-and-suspenders and lets a file
    # be run from any cwd. Preserve any existing PYTHONPATH.
    existing = env.get("PYTHONPATH", "")
    env["PYTHONPATH"] = (
        f"{ROOT}{os.pathsep}{existing}" if existing else str(ROOT)
    )
    # Deterministic, unbuffered child output.
    env.setdefault("PYTHONUNBUFFERED", "1")

    timed_out = False
    try:
        proc = subprocess.run(
            [sys.executable, str(path)],
            cwd=str(ROOT),
            env=env,
            capture_output=True,
            text=True,
            timeout=PER_FILE_TIMEOUT,
        )
        returncode = proc.returncode
        stdout, stderr = proc.stdout, proc.stderr
    except subprocess.TimeoutExpired as exc:
        timed_out = True
        returncode = -1
        stdout = exc.stdout or ""
        stderr = (exc.stderr or "") + f"\n[run_tests] TIMEOUT after {PER_FILE_TIMEOUT}s"
        if isinstance(stdout, bytes):
            stdout = stdout.decode("utf-8", "replace")
        if isinstance(stderr, bytes):
            stderr = stderr.decode("utf-8", "replace")

    return Result(
        path=path,
        rel=str(path.relative_to(ROOT)),
        returncode=returncode,
        stdout=stdout,
        stderr=stderr,
        timed_out=timed_out,
        missing_deps=needed_missing,
    )


def _tail(text: str, limit: int = TAIL_CHARS) -> str:
    text = text.rstrip()
    if len(text) <= limit:
        return text
    return "... [truncated] ...\n" + text[-limit:]


# --------------------------------------------------------------------------- #
# Main
# --------------------------------------------------------------------------- #

def main() -> int:
    tests = discover_tests()
    missing = _missing_optional_deps()

    print("=" * 72)
    print("Atlas Code Graph — aggregate test run (pure stdlib, no pytest)")
    print(f"  interpreter : {sys.executable}")
    print(f"  root        : {ROOT}")
    print(f"  discovered  : {len(tests)} test file(s)")
    if missing:
        print(f"  missing deps: {', '.join(sorted(missing))}")
    else:
        print("  missing deps: none (all optional deps importable)")
    print("=" * 72)

    results: list[Result] = []
    for path in tests:
        res = run_one(path, missing)
        results.append(res)
        if res.failed:
            status = "FAIL"
        elif res.dep_skipped:
            status = "SKIP(dep)"
        else:
            status = "PASS"
        note = ""
        if res.dep_skipped:
            note = f"  (missing: {', '.join(res.missing_deps)})"
        elif res.passed and res.import_failure_in_output:
            # Passed overall but output shows an import-failure marker: surface
            # it as a dep-skip so it cannot hide as a clean green.
            status = "SKIP(dep)"
            note = "  (import-failure marker in output)"
        print(f"  [{status:9}] {res.rel}{note}")

    # Re-classify the import-marker case into the SKIPPED bucket for counting.
    def is_skip(r: Result) -> bool:
        return r.dep_skipped or (r.passed and r.import_failure_in_output)

    failed = [r for r in results if r.failed]
    skipped = [r for r in results if (not r.failed) and is_skip(r)]
    passed = [r for r in results if r.passed and not is_skip(r)]

    if failed:
        print("\n" + "-" * 72)
        print("FAILURE DETAIL")
        print("-" * 72)
        for r in failed:
            reason = "TIMEOUT" if r.timed_out else f"exit {r.returncode}"
            print(f"\n### {r.rel}  ({reason})")
            out_tail = _tail(r.stdout)
            err_tail = _tail(r.stderr)
            if out_tail:
                print("--- stdout (tail) ---")
                print(out_tail)
            if err_tail:
                print("--- stderr (tail) ---")
                print(err_tail)

    if skipped:
        print("\n" + "-" * 72)
        print("DEP-SKIPPED DETAIL (heavy path not exercised — NOT a real pass)")
        print("-" * 72)
        for r in skipped:
            why = (
                ", ".join(r.missing_deps)
                if r.missing_deps
                else "import-failure marker in output"
            )
            print(f"  {r.rel}  -> missing: {why}")

    print("\n" + "=" * 72)
    print(f"PASSED: {len(passed)}  FAILED: {len(failed)}  SKIPPED(dep): {len(skipped)}")
    print("=" * 72)

    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
