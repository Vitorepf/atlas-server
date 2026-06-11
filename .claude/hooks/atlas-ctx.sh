#!/usr/bin/env bash
#
# Atlas Open Brain context hook — AOBG N1.F4 (the full brain).
#
# A Claude Code `UserPromptSubmit` hook that injects the unified, provider-bound Atlas
# context pack for the operator's prompt. UPGRADE over the original code-graph-only hook
# (AP-815 I-4): instead of `atlas:ctx` (BM25 symbols only, built before Saltos 1-3), it now
# calls `atlas:context-pack` (AOBG N1.F1) — the SINGLE fused front door that assembles the
# three already-proven, already-provider-safe brains under one budget + one workspace:
#
#   * code-graph    — BM25 + E-3 budgeted symbol pack (the old hook's whole payload).
#   * reality graph — AURG cross-layer paths (code+memory+domain+evidence), provider_bound.
#   * semantic memory — pgvector recall over REDACTED provider-safe projections only.
#
# It builds NO parallel context engine — it is just the Claude-Code transport for the one
# shared pack. The pack is provider-bound end to end (sensitive/secret/cyber excluded by
# construction; memory redacted; evidence ids/hashes only) and is a CURATED TOP-K slice,
# not omniscience — the rendered brief labels itself so.
#
# Contract (UNCHANGED — same proven hook seam): best-effort recall, NEVER a gate.
#   - Reads the hook event JSON on stdin, extracts `.prompt`.
#   - cd into $CLAUDE_PROJECT_DIR (the caller's project) so the pack auto-scopes to THIS
#     workspace via the command's --workspace default (CodeGraphWorkspaceIdentity) — a
#     pack built for project B never leaks project A's symbols.
#   - Runs `php artisan atlas:context-pack "<prompt>" --budget=6000 --json`.
#   - If the pack has any content (code + reality paths + memory counts > 0), emits a
#     UserPromptSubmit `additionalContext` envelope carrying the pack's rendered markdown
#     brief so the full brain reaches the model's context.
#   - Otherwise (no match, empty prompt, missing jq/php, any error) exits 0 SILENTLY so it
#     can never block or corrupt a prompt submission. Read-only, local DB only, zero
#     provider spend.
#
# Wire it in settings.json (see docs/engineering-knowledge-base/code-graph-consumption.md):
#   "hooks": { "UserPromptSubmit": [ { "hooks": [
#     { "type": "command", "command": "$CLAUDE_PROJECT_DIR/.claude/hooks/atlas-ctx.sh" } ] } ] }
#
# Dry-run / lint (cost-free): pipe a sample event in —
#   echo '{"prompt":"workspace identity resolver"}' | .claude/hooks/atlas-ctx.sh

set -u

# Total char budget for the fused pack (matches config atlas.aobg.budget_chars default).
# Overridable for the dry-run via ATLAS_AOBG_HOOK_BUDGET; never trusted from the event.
ATLAS_AOBG_HOOK_BUDGET="${ATLAS_AOBG_HOOK_BUDGET:-6000}"

# --- fail-safe preflight: anything missing -> silent no-op (exit 0) ------------------
command -v jq  >/dev/null 2>&1 || exit 0
command -v php >/dev/null 2>&1 || exit 0

# Read the whole event JSON from stdin.
EVENT="$(cat 2>/dev/null || true)"
[ -n "$EVENT" ] || exit 0

# Extract the operator's prompt. `.prompt // empty` -> empty string when absent/null.
PROMPT="$(printf '%s' "$EVENT" | jq -r '.prompt // empty' 2>/dev/null || true)"
[ -n "$PROMPT" ] || exit 0

# Anchor to the project root the harness hands us; fall back to the hook's own repo.
# WORKSPACE_DIR is the codebase Claude is operating on. ATLAS_SERVER_DIR is where
# Artisan lives. They are the same for atlas-server, but intentionally differ when
# Claude opens the umbrella /Users/vitorepf/develop/Atlas workspace.
PROJECT_DIR="${CLAUDE_PROJECT_DIR:-}"
if [ -z "$PROJECT_DIR" ]; then
    PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." 2>/dev/null && pwd)"
fi
WORKSPACE_DIR="$PROJECT_DIR"
ATLAS_SERVER_DIR="$PROJECT_DIR"
if [ ! -f "$ATLAS_SERVER_DIR/artisan" ] && [ -f "$PROJECT_DIR/atlas-server/artisan" ]; then
    ATLAS_SERVER_DIR="$PROJECT_DIR/atlas-server"
fi
if [ ! -f "$ATLAS_SERVER_DIR/artisan" ]; then
    ATLAS_SERVER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." 2>/dev/null && pwd)"
fi
[ -f "$ATLAS_SERVER_DIR/artisan" ] || exit 0
cd "$ATLAS_SERVER_DIR" 2>/dev/null || exit 0

# Best-effort workspace activation: bind the opened folder into AWIS, install/merge
# provider bootstrap files, and index the CodeGraph on the first prompt for a new
# workspace. Subsequent prompts are cheap because activation sees the workspace is
# already indexed. Disable with ATLAS_AOBG_HOOK_AUTO_ACTIVATE=0.
if [ "${ATLAS_AOBG_HOOK_AUTO_ACTIVATE:-1}" = "1" ]; then
    php artisan atlas:aobg:workspace activate --workspace="$WORKSPACE_DIR" --json >/dev/null 2>&1 || true
fi

# Run the proven unified retrieval. Capture stdout only; swallow stderr/non-zero (never
# block). The command is fail-safe by contract (exit 0 + honest-empty pack on any fault).
PACK_JSON="$(php artisan atlas:context-pack "$PROMPT" --workspace="$WORKSPACE_DIR" --budget="$ATLAS_AOBG_HOOK_BUDGET" --json 2>/dev/null || true)"
[ -n "$PACK_JSON" ] || exit 0

# Only inject when the pack actually carries SOMETHING — the sum of the three section
# counts (code_graph + reality_graph_paths + memory). A pack with all sections empty is an
# honest "the brain has nothing here"; injecting it would be noise.
TOTAL="$(printf '%s' "$PACK_JSON" | jq -r '
    ((.counts.code_graph // 0)
     + (.counts.reality_graph_paths // 0)
     + (.counts.memory // 0)) | tostring
' 2>/dev/null || echo 0)"
case "$TOTAL" in
    ''|*[!0-9]*) exit 0 ;;            # non-numeric -> treat as nothing to inject
esac
[ "$TOTAL" -gt 0 ] 2>/dev/null || exit 0

# The pack already renders a compact, human/agent-readable markdown brief (the one block a
# hook injects). Carry it verbatim, wrapped in the UserPromptSubmit additionalContext
# envelope. jq does all string-encoding so the pack content can never break the JSON
# envelope. Fall back to a synthesized header only if `.markdown` is somehow empty.
printf '%s' "$PACK_JSON" | jq -c '
    ((.markdown // "") | if . == "" then
        ("# Atlas Open Brain Context Pack (AOBG)\n"
         + "task=" + (.task // "") + "  workspace=" + (.workspace // "atlas-server")
         + "  provider-bound=yes  " + (.honesty // "curated top-K (not exhaustive)"))
      else . end) as $ctx
    | {
        hookSpecificOutput: {
            hookEventName: "UserPromptSubmit",
            additionalContext: $ctx
        }
    }
' 2>/dev/null || exit 0

exit 0
