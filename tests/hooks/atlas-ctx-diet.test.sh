#!/usr/bin/env bash
#
# S4 (Obra #19) — runnable check for the atlas-ctx.sh hook diet: (1) an operational
# prompt is skipped, (2) a design prompt injects the pack, (3) the SAME pack on the
# next prompt is deduped (empty). Stubs `php` to emit a canned pack; uses real jq.
#
#   bash tests/hooks/atlas-ctx-diet.test.sh   # exits 0 on pass, 1 on any failure
set -u

HOOK="$(cd "$(dirname "$0")/../.." && pwd)/.claude/hooks/atlas-ctx.sh"
WORK="$(mktemp -d)"
export TMPDIR="$WORK/tmp"; mkdir -p "$TMPDIR"
touch "$WORK/artisan"                       # so the hook's `[ -f artisan ]` preflight passes
BIN="$WORK/bin"; mkdir -p "$BIN"
cat > "$BIN/php" <<'STUB'
#!/usr/bin/env bash
# stub php: emit a canned pack whose markdown embeds the prompt (arg after context-pack),
# so different prompts yield different packs (distinguishes inject from dedupe).
p=""
while [ "$#" -gt 0 ]; do [ "$1" = "atlas:context-pack" ] && { p="$2"; break; }; shift; done
printf '{"counts":{"code_graph":1,"reality_graph_paths":0,"memory":0},"markdown":"BRIEF: %s","task":"t","workspace":"w"}\n' "$p"
STUB
chmod +x "$BIN/php"
export PATH="$BIN:$PATH"
export CLAUDE_PROJECT_DIR="$WORK"
export ATLAS_AOBG_HOOK_AUTO_ACTIVATE=0

fail=0
check() { if [ "$1" = "$2" ]; then echo "PASS $3"; else echo "FAIL $3 (expected [$1] got [$2])"; fail=1; fi; }

# 1) operational prompt -> skipped (empty output)
out="$(printf '{"prompt":"git status"}' | bash "$HOOK")"
check "" "$out" "ops-skip (git status)"

# 2) design prompt -> injects the pack (contains CANNED BRIEF)
out="$(printf '{"prompt":"design the retrieval architecture end to end"}' | bash "$HOOK")"
if printf '%s' "$out" | grep -q "BRIEF: design"; then echo "PASS inject"; else echo "FAIL inject (got [$out])"; fail=1; fi

# 3) SAME design prompt again -> deduped (empty output)
out="$(printf '{"prompt":"design the retrieval architecture end to end"}' | bash "$HOOK")"
check "" "$out" "dedupe (identical pack)"

rm -rf "$WORK"
if [ "$fail" = 0 ]; then echo "ALL PASS"; else echo "FAILURES"; fi
exit "$fail"
