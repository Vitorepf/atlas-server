#!/usr/bin/env bash
#
# Atlas Code Graph context hook — AP-815 I-4 (Stage 2).
#
# A Claude Code `UserPromptSubmit` hook that injects the precise, BM25-ranked code-graph
# context pack for the operator's prompt, using the SAME proven retrieval as `atlas:ctx`
# (keyword terms -> A3 BM25 ranked candidates -> E-3 budgeted pack). The pack is the one,
# shared retrieval path; this hook is just the Claude-Code transport for it.
#
# Contract (mirrors the in-process Open Brain seam): best-effort recall, NEVER a gate.
#   - Reads the hook event JSON on stdin, extracts `.prompt`.
#   - cd into $CLAUDE_PROJECT_DIR and runs `php artisan atlas:ctx "<prompt>" --budget=4000 --json`.
#   - If the pack has included_count > 0, emits a UserPromptSubmit `additionalContext`
#     envelope carrying the rendered pack so the symbols reach the model's context.
#   - Otherwise (no match, empty prompt, missing jq/php, any error) exits 0 SILENTLY so it
#     can never block or corrupt a prompt submission.
#
# Wire it in settings.json (see docs/engineering-knowledge-base/code-graph-consumption.md):
#   "hooks": { "UserPromptSubmit": [ { "hooks": [
#     { "type": "command", "command": "$CLAUDE_PROJECT_DIR/.claude/hooks/atlas-ctx.sh" } ] } ] }

set -u

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
PROJECT_DIR="${CLAUDE_PROJECT_DIR:-}"
if [ -z "$PROJECT_DIR" ]; then
    PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." 2>/dev/null && pwd)"
fi
cd "$PROJECT_DIR" 2>/dev/null || exit 0

# Run the proven retrieval. Capture stdout only; swallow stderr/non-zero (never block).
PACK_JSON="$(php artisan atlas:ctx "$PROMPT" --budget=4000 --json 2>/dev/null || true)"
[ -n "$PACK_JSON" ] || exit 0

# Only inject when the pack actually included symbols (included_count > 0).
INCLUDED="$(printf '%s' "$PACK_JSON" | jq -r '.included_count // 0' 2>/dev/null || echo 0)"
case "$INCLUDED" in
    ''|*[!0-9]*) exit 0 ;;            # non-numeric -> treat as nothing to inject
esac
[ "$INCLUDED" -gt 0 ] 2>/dev/null || exit 0

# Build a compact, human-readable context block from the pack's included symbols, then
# wrap it in the UserPromptSubmit additionalContext envelope. jq does all string-encoding
# so the pack content can never break the JSON envelope.
printf '%s' "$PACK_JSON" | jq -c '
    (
        "# Atlas Code Graph Context (atlas:ctx)\n"
        + "workspace=" + (.workspace_id // "atlas-server")
        + "  query=" + (.query // "")
        + "  included=" + ((.included_count // 0) | tostring)
        + "  ~" + ((.estimated_tokens // 0) | tostring) + "/" + ((.budget // 0) | tostring) + " tokens\n\n"
        + (
            ((.pack.included // []) | map(
                "- " + (.id // "symbol")
                + " [" + (.file_path // "n/a") + "]"
                + " type=" + (if (.symbol_type // "") == "" then "n/a" else .symbol_type end)
                + "; tokens=" + ((.tokens // 0) | tostring)
                + (if (.signature // "") == "" then "" else "; sig=" + (.signature | .[0:200]) end)
            ) | join("\n"))
        )
    ) as $ctx
    | {
        hookSpecificOutput: {
            hookEventName: "UserPromptSubmit",
            additionalContext: $ctx
        }
    }
' 2>/dev/null || exit 0

exit 0
