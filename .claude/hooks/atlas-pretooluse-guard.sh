#!/usr/bin/env bash
#
# Atlas Open Brain guard hook — AOBG N2.F2 (the SENTINEL / killer safety).
#
# A Claude Code `PreToolUse` hook (matcher: Edit|Write|MultiEdit|NotebookEdit) that
# lets the brain check a proposed edit BEFORE it lands. N2.F1's PostToolUse hook
# (atlas-postedit-context.sh) told the model what the brain KNOWS about a file AFTER
# a tool ran; THIS fires BEFORE the write and can warn — or, only when explicitly
# armed, block — the edit. It carries the verdict of `atlas:aobg:guard` (AOBG N2.F2)
# into the Claude Code PreToolUse decision contract.
#
# DECISION MAPPING (safety-first — a false block bricks the session):
#   - guard decision "warn"  -> permissionDecision "allow" + the warning as
#     additionalContext (the model SEES the heads-up; the edit STILL proceeds —
#     advisory, never a gate). This is the DEFAULT for every finding.
#   - guard decision "block" -> permissionDecision "deny" + permissionDecisionReason
#     (the ONLY blocking path; reached only when the operator armed
#     atlas.aobg.guard.block_enabled AND the violation is highest-confidence: a
#     sensitive/secret/cyber-class path touch OR an exact registered-decision
#     contradiction).
#   - guard decision "allow" (clean edit) -> emit NOTHING; the normal permission
#     flow applies (the hook never silently auto-grants a permission).
#   - any error / timeout / missing tool / empty path -> emit NOTHING -> FAIL-OPEN
#     (the edit proceeds under the normal flow; the sentinel can never block by
#     accident). Read-only, local DB only, ZERO provider spend.
#
# ASYMMETRY (honest, never "parity"): rich PreToolUse hooks that can DENY a tool
# call are a Claude-Code capability — so this BEFORE-the-edit enforcement is a
# Claude-Code-only surface. Codex / Cursor reach the SAME brain only via the
# `atlas:aobg:guard` MCP/CLI subset (callable on demand, NOT auto-fired before a
# tool runs, and not able to deny a tool call). This script does not, and must not,
# claim those engines get the same automatic intervention.
#
# Wire it in settings.json under PreToolUse with the Edit|Write matcher.
#
# Dry-run / lint (cost-free): pipe a sample PreToolUse event in —
#   echo '{"tool_name":"Write","tool_input":{"file_path":"secrets/keys.env"}}' \
#     | ATLAS_AOBG_GUARD_FORCE_BLOCK=1 .claude/hooks/atlas-pretooluse-guard.sh

set -u

# Char budget for the assembled warning text (matches config
# atlas.aobg.guard.budget_chars default). Overridable for the dry-run; never trusted
# from the event.
ATLAS_AOBG_GUARD_BUDGET="${ATLAS_AOBG_GUARD_BUDGET:-2500}"

# Hard wall-clock ceiling (seconds) for the brain read. PERF floor: a slow/broken
# brain must NEVER stall the operator's interactive path — on timeout the hook emits
# nothing (fail-open) and exits 0. Overridable via ATLAS_AOBG_GUARD_TIMEOUT.
ATLAS_AOBG_GUARD_TIMEOUT="${ATLAS_AOBG_GUARD_TIMEOUT:-8}"

# Dry-run / opt-in arming for the hook itself: when ATLAS_AOBG_GUARD_FORCE_BLOCK=1
# the command is run with --block (lets the dry-run exercise the deny path without
# flipping the global config flag). Default unset -> the command honours the config
# flag (default OFF -> never blocks).
ATLAS_AOBG_GUARD_FORCE_BLOCK="${ATLAS_AOBG_GUARD_FORCE_BLOCK:-}"

# N2.F4 BLACKBOARD: the engine this hook runs inside is Claude Code, so its OWN
# in-flight claims must be EXCLUDED from the cross-engine claim check (an engine never
# warns about its own claim — only about ANOTHER engine, e.g. "codex is editing this
# file"). Passed to the guard as --engine. Overridable for the dry-run.
ATLAS_AOBG_GUARD_ENGINE="${ATLAS_AOBG_GUARD_ENGINE:-claude_code}"

# --- fail-safe preflight: anything missing -> emit nothing (fail-open) ----------------
command -v jq  >/dev/null 2>&1 || exit 0
command -v php >/dev/null 2>&1 || exit 0

# Read the whole event JSON from stdin.
EVENT="$(cat 2>/dev/null || true)"
[ -n "$EVENT" ] || exit 0

# Only fire for MUTATING file tools — a Read / Bash / web tool proposes no write and
# must be a clean no-op. (Read is deliberately NOT guarded: this is the WRITE gate.)
TOOL="$(printf '%s' "$EVENT" | jq -r '.tool_name // empty' 2>/dev/null || true)"
case "$TOOL" in
    Edit|Write|MultiEdit|NotebookEdit) : ;;     # mutating file tools
    *) exit 0 ;;                                 # anything else -> no-op
esac

# Extract the target file path from the proposed tool input.
RAW_PATH="$(printf '%s' "$EVENT" | jq -r '
    .tool_input.file_path
    // .tool_input.path
    // .tool_input.notebook_path
    // .file_path
    // .path
    // empty
' 2>/dev/null || true)"
[ -n "$RAW_PATH" ] || exit 0

# Extract the proposed change text (drives the duplication + contradiction checks).
# Edit carries new_string; Write carries content; MultiEdit carries an edits array;
# NotebookEdit carries new_source. Concatenate whatever shape is present (best-effort).
DIFF="$(printf '%s' "$EVENT" | jq -r '
    [ .tool_input.content // empty,
      .tool_input.new_string // empty,
      .tool_input.new_source // empty,
      ((.tool_input.edits // []) | map(.new_string // empty) | join("\n")) ]
    | map(select(. != "")) | join("\n")
' 2>/dev/null || true)"

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

# Assemble the artisan args. The diff is passed only when present (a long diff is
# fine — it is a local CLI arg, never a provider prompt).
BLOCK_ARG=""
[ "$ATLAS_AOBG_GUARD_FORCE_BLOCK" = "1" ] && BLOCK_ARG="--block"

# Run the proven guard evaluation under a HARD timeout. Capture stdout only; swallow
# stderr/non-zero (never block on a tooling fault). The command is fail-open by
# contract (exit 0 + allow on any fault). `timeout` is optional. The artisan
# argv is built as an array so an empty $DIFF / $BLOCK_ARG simply drops the flag
# (no unquoted-word-split surprises).
GUARD_ARGS=(artisan atlas:aobg:guard "$RAW_PATH" --workspace="$WORKSPACE_DIR" --budget="$ATLAS_AOBG_GUARD_BUDGET" --json)
[ -n "$DIFF" ] && GUARD_ARGS+=(--diff="$DIFF")
[ -n "$ATLAS_AOBG_GUARD_ENGINE" ] && GUARD_ARGS+=(--engine="$ATLAS_AOBG_GUARD_ENGINE")
[ -n "$BLOCK_ARG" ] && GUARD_ARGS+=("$BLOCK_ARG")

if command -v timeout >/dev/null 2>&1; then
    VERDICT_JSON="$(timeout "${ATLAS_AOBG_GUARD_TIMEOUT}s" php "${GUARD_ARGS[@]}" 2>/dev/null || true)"
elif command -v gtimeout >/dev/null 2>&1; then
    VERDICT_JSON="$(gtimeout "${ATLAS_AOBG_GUARD_TIMEOUT}s" php "${GUARD_ARGS[@]}" 2>/dev/null || true)"
elif command -v perl >/dev/null 2>&1; then
    # macOS ships no timeout/gtimeout — perl's alarm gives a hard wall-clock ceiling
    # (SIGALRM terminates) so the PRE-edit guard can NEVER stall the operator session.
    VERDICT_JSON="$(perl -e 'alarm shift; exec @ARGV' "$ATLAS_AOBG_GUARD_TIMEOUT" php "${GUARD_ARGS[@]}" 2>/dev/null || true)"
else
    VERDICT_JSON="$(php "${GUARD_ARGS[@]}" 2>/dev/null || true)"
fi
[ -n "$VERDICT_JSON" ] || exit 0

# Read the decision. Anything we don't recognise -> fail-open (emit nothing).
DECISION="$(printf '%s' "$VERDICT_JSON" | jq -r '.decision // empty' 2>/dev/null || true)"
case "$DECISION" in
    block)
        # The ONLY deny path — emit permissionDecision "deny" + the reason. jq does
        # all string-encoding so the reason can never break the JSON envelope.
        printf '%s' "$VERDICT_JSON" | jq -c '
            ((.warning // "") | if . == "" then "Atlas brain blocked this edit (highest-confidence violation)." else . end) as $reason
            | {
                hookSpecificOutput: {
                    hookEventName: "PreToolUse",
                    permissionDecision: "deny",
                    permissionDecisionReason: ("Atlas brain (AOBG sentinel) blocked this edit:\n" + $reason)
                }
            }
        ' 2>/dev/null || exit 0
        exit 0
        ;;
    warn)
        # Advisory — ALLOW the edit but inject the warning so the model sees it. Only
        # emit when there is actual warning text (a warn with no reasons is a no-op).
        WARNING="$(printf '%s' "$VERDICT_JSON" | jq -r '.warning // empty' 2>/dev/null || true)"
        [ -n "$WARNING" ] || exit 0
        printf '%s' "$VERDICT_JSON" | jq -c '
            ("# Atlas brain — heads-up before this edit (AOBG sentinel)\n"
             + "file=" + (.path // "") + "  workspace=" + (.workspace // "atlas-server")
             + "  provider-bound=yes  advisory (the edit will proceed)\n\n"
             + (.warning // "")) as $ctx
            | {
                hookSpecificOutput: {
                    hookEventName: "PreToolUse",
                    permissionDecision: "allow",
                    additionalContext: $ctx
                }
            }
        ' 2>/dev/null || exit 0
        exit 0
        ;;
    *)
        # allow (clean edit) OR anything unrecognised / fail-open -> emit NOTHING so
        # the normal permission flow applies. The sentinel never auto-grants.
        exit 0
        ;;
esac
