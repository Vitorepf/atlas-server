#!/usr/bin/env bash
#
# Atlas Open Brain session-capture hook — AOBG N2.F3 (STRUCTURAL capture).
#
# A Claude Code `Stop` / `SessionEnd` hook that feeds the brain AUTOMATICALLY when a
# session ends — not because the agent volunteered to call a write-back tool, but
# because the session ENDING fires the distiller. It reads the session transcript
# path from the stop event on stdin and runs `atlas:aobg:capture-session`, which
# DETERMINISTICALLY distils what the session did (the files it touched + the result +
# any EXPLICIT, file-cited learnings) and feeds them THROUGH the governed write-back:
# a provider-safe mission/evidence node (NEVER a merge) + `proposed` learnings awaiting
# human review (the capture quality gate rejects noise; nothing auto-applies).
#
# N1's write-back (atlas_record_outcome / atlas_propose_learning) is the VOLUNTARY
# door — the agent had to choose to call it. THIS hook makes capture STRUCTURAL: the
# session feeds the brain whether or not the agent remembered to.
#
# CONTRACT (mirrors the proven atlas-postedit-context.sh seam): best-effort, NEVER a
# gate, fail-open + fast-enough — it must NEVER block or stall session end.
#   - Reads the Stop/SessionEnd event JSON on stdin, extracts the transcript path
#     (.transcript_path, with fallbacks).
#   - cd into $CLAUDE_PROJECT_DIR so the capture auto-scopes to THIS workspace via the
#     command's --workspace (CodeGraphWorkspaceIdentity); a capture for project B never
#     lands under project A.
#   - Runs `php artisan atlas:aobg:capture-session --transcript=<path> ...` under a hard
#     `timeout` (PERF floor: capture can never stall the operator's session end).
#   - Emits NOTHING to the harness — a Stop hook needs no decision/context; the capture
#     side effect (a brain node + pending-review learnings) is the whole point. Any
#     error / missing tool / missing transcript / timeout -> exit 0 silently (fail-open).
#     Read-only-to-the-provider, local DB only, ZERO provider spend (no LLM distill).
#
# ASYMMETRY (honest, never "parity"): Claude Code has rich session-lifecycle hooks, so
# this AUTOMATIC end-of-session capture is a Claude-Code-only capability. Codex / Cursor
# reach the SAME governed write-back only via the MCP-tool subset (atlas_record_outcome /
# atlas_propose_learning) — callable on demand, NOT auto-fired when their session ends.
# This script does not, and must not, claim those engines get the same automatic capture.
#
# Wire it in settings.json under Stop (and/or SessionEnd).
#
# Dry-run / lint (cost-free): pipe a sample Stop event in —
#   echo '{"transcript_path":"/tmp/session.jsonl"}' | .claude/hooks/atlas-session-capture.sh

set -u

# Hard wall-clock ceiling (seconds) for the capture. PERF floor: a slow/broken brain
# must NEVER stall session end — on timeout the hook just exits 0. Overridable via
# ATLAS_AOBG_CAPTURE_TIMEOUT.
ATLAS_AOBG_CAPTURE_TIMEOUT="${ATLAS_AOBG_CAPTURE_TIMEOUT:-20}"

# Provider/agent label stamped on the recorded outcome. Overridable.
ATLAS_AOBG_CAPTURE_PROVIDER="${ATLAS_AOBG_CAPTURE_PROVIDER:-claude-code}"

# --- fail-safe preflight: anything missing -> silent no-op (exit 0) ------------------
command -v jq  >/dev/null 2>&1 || exit 0
command -v php >/dev/null 2>&1 || exit 0

# Read the whole event JSON from stdin.
EVENT="$(cat 2>/dev/null || true)"
[ -n "$EVENT" ] || exit 0

# Extract the transcript path from the stop event. Claude Code stamps
# .transcript_path on Stop/SessionEnd; fall back to a couple of shapes.
TRANSCRIPT="$(printf '%s' "$EVENT" | jq -r '
    .transcript_path
    // .transcript
    // .session.transcript_path
    // empty
' 2>/dev/null || true)"
[ -n "$TRANSCRIPT" ] || exit 0
# The transcript must be a readable file; otherwise nothing to distil (fail-open).
[ -f "$TRANSCRIPT" ] || exit 0

# An explicit session id rides the event when present (the stable node identity).
SESSION_ID="$(printf '%s' "$EVENT" | jq -r '.session_id // .sessionId // empty' 2>/dev/null || true)"

# Anchor to the project root the harness hands us; fall back to the hook's own repo.
# This cwd is what scopes the capture's workspace (multi-project: ANY repo, auto-scoped).
PROJECT_DIR="${CLAUDE_PROJECT_DIR:-}"
if [ -z "$PROJECT_DIR" ]; then
    PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." 2>/dev/null && pwd)"
fi
cd "$PROJECT_DIR" 2>/dev/null || exit 0

# Assemble the artisan argv as an array so an empty $SESSION_ID simply drops the flag
# (no unquoted-word-split surprises). The transcript path is a local CLI arg, never a
# provider prompt.
CAPTURE_ARGS=(artisan atlas:aobg:capture-session
    "--transcript=$TRANSCRIPT"
    "--provider=$ATLAS_AOBG_CAPTURE_PROVIDER"
    --json)
[ -n "$SESSION_ID" ] && CAPTURE_ARGS+=("--session-id=$SESSION_ID")

# Run the capture under a HARD timeout. Swallow stdout/stderr/non-zero — the side
# effect (a brain node + pending-review learnings) is the point; the harness needs no
# output from a Stop hook. The command is fail-open by contract (exit 0 on any fault).
if command -v timeout >/dev/null 2>&1; then
    timeout "${ATLAS_AOBG_CAPTURE_TIMEOUT}s" php "${CAPTURE_ARGS[@]}" >/dev/null 2>&1 || true
else
    php "${CAPTURE_ARGS[@]}" >/dev/null 2>&1 || true
fi

# Always succeed — a capture fault must never stall or fail session end.
exit 0
