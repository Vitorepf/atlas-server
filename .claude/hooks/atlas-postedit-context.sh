#!/usr/bin/env bash
#
# Atlas Open Brain file-context hook — AOBG N2.F1 (the ACTIVE brain / sentinel).
#
# A Claude Code `PostToolUse` hook (matcher: Read|Edit|Write) that makes the brain
# INTERVENE DURING the session: the moment Claude Code opens or edits a file, this
# injects the brain's brain-delta ABOUT that file — the decisions/missions touching
# its module, the provider-safe memories that reference it, and who consumes it
# (code-graph neighbors). N1's UserPromptSubmit hook (atlas-ctx.sh) answered the
# PROMPT; this answers the FILE the task just touched ("this file is governed by
# decision X; a prior mission here failed by Y; it is consumed by Z").
#
# It builds NO parallel engine — it is just the Claude-Code transport for the one
# shared, provider-bound delta produced by `atlas:aobg:file-context` (AOBG N2.F1),
# which ASSEMBLES the same three already-proven brains (code-graph neighbors + AURG
# reality paths + semantic memory), seeded from the FILE rather than the prompt.
# The delta is provider-bound end to end (sensitive/secret/cyber excluded by
# construction; memory redacted; ids/hashes only) and is a CURATED slice the brain
# happens to hold about this file, not omniscience — the rendered brief labels itself so.
#
# ASYMMETRY (honest, never "parity"): Claude Code has rich PostToolUse hooks, so
# THIS file-following intervention is a Claude-Code-only capability. Codex / Cursor
# do NOT have these hooks — they reach the SAME brain only via the MCP-tool subset
# (callable on demand, not auto-fired after a tool runs). This script does not, and
# must not, claim those engines get the same automatic intervention.
#
# Contract (mirrors the proven atlas-ctx.sh seam): best-effort recall, NEVER a gate.
#   - Reads the PostToolUse event JSON on stdin, extracts the touched file path from
#     the tool input (.tool_input.file_path, with fallbacks for .file_path / .path).
#   - Only fires for file tools (Read|Edit|Write|MultiEdit|NotebookEdit); any other
#     tool, or a missing path, is a silent no-op (exit 0).
#   - cd into $CLAUDE_PROJECT_DIR so the delta auto-scopes to THIS workspace via the
#     command's --workspace default (CodeGraphWorkspaceIdentity) — a delta for
#     project B never leaks project A's symbols.
#   - Runs `php artisan atlas:aobg:file-context "<path>" --budget=<n> --json` under a
#     hard `timeout` (perf floor: the brain can never stall the interactive path).
#   - If the delta has any content (has_context true / any section count > 0) emits a
#     PostToolUse `additionalContext` envelope carrying the rendered markdown brief.
#   - Otherwise (unknown file, non-file tool, empty path, missing jq/php/timeout, any
#     error, or a timeout) exits 0 SILENTLY — it can never block or corrupt a session.
#     Read-only, local DB only, ZERO provider spend.
#
# Wire it in settings.json under PostToolUse with the Read|Edit|Write matcher (see
# docs/engineering-knowledge-base/code-graph-consumption.md and the mirror of how
# atlas-ctx.sh is registered under UserPromptSubmit).
#
# Dry-run / lint (cost-free): pipe a sample PostToolUse event in —
#   echo '{"tool_name":"Edit","tool_input":{"file_path":"app/Services/Ai/Foo.php"}}' \
#     | .claude/hooks/atlas-postedit-context.sh

set -u

# Total char budget for the file-context delta (matches config
# atlas.aobg.file_context.budget_chars default). Overridable for the dry-run via
# ATLAS_AOBG_FC_HOOK_BUDGET; never trusted from the event.
ATLAS_AOBG_FC_HOOK_BUDGET="${ATLAS_AOBG_FC_HOOK_BUDGET:-3500}"

# Hard wall-clock ceiling (seconds) for the brain read. PERF floor: a slow/broken
# brain must NEVER stall the operator's interactive path — on timeout the hook
# emits nothing and exits 0. Overridable via ATLAS_AOBG_FC_HOOK_TIMEOUT.
ATLAS_AOBG_FC_HOOK_TIMEOUT="${ATLAS_AOBG_FC_HOOK_TIMEOUT:-8}"

# --- fail-safe preflight: anything missing -> silent no-op (exit 0) ------------------
command -v jq  >/dev/null 2>&1 || exit 0
command -v php >/dev/null 2>&1 || exit 0

# Read the whole event JSON from stdin.
EVENT="$(cat 2>/dev/null || true)"
[ -n "$EVENT" ] || exit 0

# Only fire for file tools — a non-file tool (Bash, Grep, a web fetch...) carries no
# file path and must be a clean no-op. `.tool_name // empty` -> empty when absent.
TOOL="$(printf '%s' "$EVENT" | jq -r '.tool_name // empty' 2>/dev/null || true)"
case "$TOOL" in
    Read|Edit|Write|MultiEdit|NotebookEdit) : ;;     # supported file tools
    *) exit 0 ;;                                       # anything else -> no-op
esac

# Extract the touched file path from the tool input. PostToolUse nests the tool
# args under .tool_input; fall back to a couple of shapes other tools may use.
RAW_PATH="$(printf '%s' "$EVENT" | jq -r '
    .tool_input.file_path
    // .tool_input.path
    // .tool_input.notebook_path
    // .file_path
    // .path
    // empty
' 2>/dev/null || true)"
[ -n "$RAW_PATH" ] || exit 0

# Anchor to the project root the harness hands us; fall back to the hook's own repo.
# This cwd is what scopes the delta's workspace (multi-project: ANY repo, auto-scoped).
PROJECT_DIR="${CLAUDE_PROJECT_DIR:-}"
if [ -z "$PROJECT_DIR" ]; then
    PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." 2>/dev/null && pwd)"
fi
cd "$PROJECT_DIR" 2>/dev/null || exit 0

# Run the proven file-context retrieval under a HARD timeout. Capture stdout only;
# swallow stderr/non-zero (never block). The command is fail-safe by contract (exit
# 0 + honest-empty delta on any fault). `timeout` is optional: when absent, run the
# command directly (the command is still read-only + fail-safe, just unbounded).
if command -v timeout >/dev/null 2>&1; then
    DELTA_JSON="$(timeout "${ATLAS_AOBG_FC_HOOK_TIMEOUT}s" \
        php artisan atlas:aobg:file-context "$RAW_PATH" --budget="$ATLAS_AOBG_FC_HOOK_BUDGET" --json 2>/dev/null || true)"
elif command -v gtimeout >/dev/null 2>&1; then
    DELTA_JSON="$(gtimeout "${ATLAS_AOBG_FC_HOOK_TIMEOUT}s" \
        php artisan atlas:aobg:file-context "$RAW_PATH" --budget="$ATLAS_AOBG_FC_HOOK_BUDGET" --json 2>/dev/null || true)"
elif command -v perl >/dev/null 2>&1; then
    # macOS has no timeout/gtimeout — perl alarm gives the wall-clock ceiling so this
    # post-action hook can never stall the interactive path with no bound.
    DELTA_JSON="$(perl -e 'alarm shift; exec @ARGV' "$ATLAS_AOBG_FC_HOOK_TIMEOUT" \
        php artisan atlas:aobg:file-context "$RAW_PATH" --budget="$ATLAS_AOBG_FC_HOOK_BUDGET" --json 2>/dev/null || true)"
else
    DELTA_JSON="$(php artisan atlas:aobg:file-context "$RAW_PATH" --budget="$ATLAS_AOBG_FC_HOOK_BUDGET" --json 2>/dev/null || true)"
fi
[ -n "$DELTA_JSON" ] || exit 0

# Only inject when the brain actually KNOWS something about this file — the sum of
# the four section counts. A delta with all sections empty is an honest "the brain
# has nothing here"; injecting it would be noise on every file open.
TOTAL="$(printf '%s' "$DELTA_JSON" | jq -r '
    ((.counts.defined_symbols // 0)
     + (.counts.consumers // 0)
     + (.counts.reality_graph_paths // 0)
     + (.counts.memory // 0)) | tostring
' 2>/dev/null || echo 0)"
case "$TOTAL" in
    ''|*[!0-9]*) exit 0 ;;            # non-numeric -> treat as nothing to inject
esac
[ "$TOTAL" -gt 0 ] 2>/dev/null || exit 0

# The delta already renders a compact, human/agent-readable markdown brief (the one
# block a hook injects). Carry it verbatim, wrapped in the PostToolUse
# additionalContext envelope. jq does all string-encoding so the delta content can
# never break the JSON envelope. Fall back to a synthesized header only if
# `.markdown` is somehow empty.
printf '%s' "$DELTA_JSON" | jq -c '
    ((.markdown // "") | if . == "" then
        ("# Atlas brain — what is known about this file (AOBG)\n"
         + "file=" + (.path // "") + "  workspace=" + (.workspace // "atlas-server")
         + "  provider-bound=yes  " + (.honesty // "curated top-K (not exhaustive)"))
      else . end) as $ctx
    | {
        hookSpecificOutput: {
            hookEventName: "PostToolUse",
            additionalContext: $ctx
        }
    }
' 2>/dev/null || exit 0

exit 0
