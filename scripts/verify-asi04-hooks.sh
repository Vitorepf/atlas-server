#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
POST="$ROOT/.claude/hooks/atlas-postedit-context.sh"
CTX="$ROOT/.claude/hooks/atlas-ctx.sh"

TMP="$(mktemp -d "${TMPDIR:-/tmp}/atlas-asi04-hooks.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT

mkdir -p "$TMP/bin" "$TMP/runtime" "$TMP/tmpdir"

FAKE_PHP_LOG="$TMP/php.log"
HOOK_LOG="$TMP/hooks.log"

cat > "$TMP/bin/php" <<'SH'
#!/usr/bin/env bash
set -euo pipefail

sleep "${ATLAS_ASI04_FAKE_PHP_SLEEP:-0}"
printf 'run pid=%s args=%s\n' "$$" "$*" >> "${ATLAS_ASI04_FAKE_PHP_LOG:?}"

case "$*" in
    *"atlas:aobg:file-context"*)
        printf '%s\n' '{"counts":{"defined_symbols":1,"consumers":0,"reality_graph_paths":0,"memory":0},"markdown":"fake file context"}'
        ;;
    *"atlas:context-pack"*)
        printf '%s\n' '{"counts":{"code_graph":1,"reality_graph_paths":0,"memory":0},"markdown":"fake context pack"}'
        ;;
    *"atlas:aobg:workspace activate"*)
        printf '%s\n' '{"ok":true}'
        ;;
    *"atlas:aobg:guard"*)
        printf '%s\n' '{"decision":"allow"}'
        ;;
    *)
        printf '%s\n' '{}'
        ;;
esac
SH
chmod +x "$TMP/bin/php"

count_matches() {
    local file="$1"
    local pattern="$2"

    if [ ! -f "$file" ]; then
        printf '0\n'
        return 0
    fi

    grep -c "$pattern" "$file" 2>/dev/null || true
}

run_post_hook() {
    local target="$1"
    printf '{"tool_name":"Read","tool_input":{"file_path":"%s"}}' "$target" | "$POST" >/dev/null
}

common_env=(
    PATH="$TMP/bin:$PATH"
    TMPDIR="$TMP/tmpdir"
    CLAUDE_PROJECT_DIR="$ROOT"
    ATLAS_AOBG_HOOK_RUNTIME_DIR="$TMP/runtime"
    ATLAS_AOBG_HOOK_LOG="$HOOK_LOG"
    ATLAS_ASI04_FAKE_PHP_LOG="$FAKE_PHP_LOG"
)

# Coalescing: six identical PostToolUse hook/target invocations collapse to one run.
: > "$FAKE_PHP_LOG"
: > "$HOOK_LOG"
for _ in 1 2 3 4 5 6; do
    (env "${common_env[@]}" ATLAS_AOBG_HOOK_GLOBAL_CAP=20 ATLAS_ASI04_FAKE_PHP_SLEEP=0.35 \
        bash -c 'printf "%s" "$1" | "$2" >/dev/null' _ \
        '{"tool_name":"Read","tool_input":{"file_path":"app/Services/Ai/Fake.php"}}' "$POST") &
done
wait

runs="$(count_matches "$FAKE_PHP_LOG" 'atlas:aobg:file-context')"
coalesced="$(count_matches "$HOOK_LOG" 'reason=coalesced')"
if [ "$runs" -ne 1 ] || [ "$coalesced" -ne 5 ]; then
    echo "ASI-04 coalescing failed: runs=$runs coalesced=$coalesced"
    exit 1
fi
echo "asi04_coalescing_ok runs=$runs coalesced=$coalesced"

# Global cap: concurrent different targets above cap skip fail-open instead of running.
rm -rf "$TMP/runtime"
mkdir -p "$TMP/runtime"
: > "$FAKE_PHP_LOG"
: > "$HOOK_LOG"
for target in app/A.php app/B.php app/C.php; do
    (env "${common_env[@]}" ATLAS_AOBG_HOOK_GLOBAL_CAP=1 ATLAS_ASI04_FAKE_PHP_SLEEP=0.35 \
        bash -c 'printf "{\"tool_name\":\"Read\",\"tool_input\":{\"file_path\":\"%s\"}}" "$1" | "$2" >/dev/null' _ \
        "$target" "$POST") &
done
wait

runs="$(count_matches "$FAKE_PHP_LOG" 'atlas:aobg:file-context')"
cap_skips="$(count_matches "$HOOK_LOG" 'reason=global_cap')"
if [ "$runs" -ne 1 ] || [ "$cap_skips" -ne 2 ]; then
    echo "ASI-04 global cap failed: runs=$runs cap_skips=$cap_skips"
    exit 1
fi
echo "asi04_global_cap_ok runs=$runs cap_skips=$cap_skips"

# Forced high load sheds PostToolUse and still exits 0 without invoking php/artisan.
rm -rf "$TMP/runtime"
mkdir -p "$TMP/runtime"
: > "$FAKE_PHP_LOG"
: > "$HOOK_LOG"
env "${common_env[@]}" ATLAS_AOBG_HOOK_LOADAVG_MAX=1 ATLAS_AOBG_HOOK_LOADAVG_OVERRIDE=99 \
    ATLAS_AOBG_HOOK_GLOBAL_CAP=20 ATLAS_ASI04_FAKE_PHP_SLEEP=0 \
    bash -c 'printf "%s" "$1" | "$2" >/dev/null' _ \
    '{"tool_name":"Read","tool_input":{"file_path":"app/Services/Ai/Hot.php"}}' "$POST"

runs="$(count_matches "$FAKE_PHP_LOG" 'atlas:aobg:file-context')"
shed="$(count_matches "$HOOK_LOG" 'reason=load_shed')"
if [ "$runs" -ne 0 ] || [ "$shed" -ne 1 ]; then
    echo "ASI-04 load shed failed: runs=$runs shed=$shed"
    exit 1
fi
echo "asi04_load_shed_ok runs=$runs shed=$shed"

# UserPromptSubmit is never load-shed: forced high load still reaches context-pack.
rm -rf "$TMP/runtime"
mkdir -p "$TMP/runtime"
: > "$FAKE_PHP_LOG"
: > "$HOOK_LOG"
env "${common_env[@]}" ATLAS_AOBG_HOOK_LOADAVG_MAX=1 ATLAS_AOBG_HOOK_LOADAVG_OVERRIDE=99 \
    ATLAS_AOBG_HOOK_GLOBAL_CAP=20 ATLAS_AOBG_HOOK_AUTO_ACTIVATE=0 ATLAS_AOBG_HOOK_DEDUPE=0 \
    ATLAS_ASI04_FAKE_PHP_SLEEP=0 \
    bash -c 'printf "%s" "$1" | "$2" >/dev/null' _ \
    '{"prompt":"need architecture context"}' "$CTX"

runs="$(count_matches "$FAKE_PHP_LOG" 'atlas:context-pack')"
shed="$(count_matches "$HOOK_LOG" 'reason=load_shed')"
if [ "$runs" -ne 1 ] || [ "$shed" -ne 0 ]; then
    echo "ASI-04 UserPromptSubmit no-shed failed: runs=$runs shed=$shed"
    exit 1
fi
echo "asi04_user_prompt_no_shed_ok runs=$runs shed=$shed"

echo "ASI-04 hooks OK"
