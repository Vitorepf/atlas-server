# Shared ASI-04 hook backpressure primitives.
#
# Contract: fail-open, local-only, provider-safe logs. The public entrypoint
# exits 0 when a hook should be skipped; otherwise it installs an EXIT cleanup
# trap and returns to the caller.

atlas_hook_bp_hash() {
    if command -v shasum >/dev/null 2>&1; then
        printf '%s' "$1" | shasum -a 256 | cut -d' ' -f1
    else
        printf '%s' "$1" | cksum | cut -d' ' -f1
    fi
}

atlas_hook_bp_runtime_dir() {
    printf '%s' "${ATLAS_AOBG_HOOK_RUNTIME_DIR:-${TMPDIR:-/tmp}/atlas-aobg-hooks}"
}

atlas_hook_bp_log() {
    local reason="$1"
    local loadavg="${2:-}"
    local log_path="${ATLAS_AOBG_HOOK_LOG:-${TMPDIR:-/tmp}/atlas-aobg-hooks.log}"
    local log_dir

    [ "$log_path" = "0" ] && return 0
    log_dir="$(dirname "$log_path")"
    mkdir -p "$log_dir" 2>/dev/null || true
    printf '%s hook=%s action=skip reason=%s target_hash=%s pid=%s cap=%s loadavg=%s\n' \
        "$(date -u '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null || date)" \
        "${ATLAS_HOOK_BP_NAME:-unknown}" \
        "$reason" \
        "${ATLAS_HOOK_BP_TARGET_HASH:-unknown}" \
        "$$" \
        "${ATLAS_AOBG_HOOK_GLOBAL_CAP:-6}" \
        "$loadavg" >> "$log_path" 2>/dev/null || true
}

atlas_hook_bp_try_mkdir_lock() {
    local dir="$1"
    local pid=""

    if mkdir "$dir" 2>/dev/null; then
        printf '%s\n' "$$" > "$dir/pid" 2>/dev/null || true
        return 0
    fi

    if [ -f "$dir/pid" ]; then
        IFS= read -r pid < "$dir/pid" 2>/dev/null || pid=""
        if [ -n "$pid" ] && ! kill -0 "$pid" 2>/dev/null; then
            rm -rf "$dir" 2>/dev/null || true
            if mkdir "$dir" 2>/dev/null; then
                printf '%s\n' "$$" > "$dir/pid" 2>/dev/null || true
                return 0
            fi
        fi
    fi

    return 1
}

atlas_hook_bp_acquire_target_lock() {
    local runtime="$1"
    local lock_file="$runtime/locks/$ATLAS_HOOK_BP_NAME-$ATLAS_HOOK_BP_TARGET_HASH.lock"

    mkdir -p "$runtime/locks" 2>/dev/null || return 0
    if command -v flock >/dev/null 2>&1; then
        exec 9>"$lock_file" || return 0
        if flock -n 9 2>/dev/null; then
            ATLAS_HOOK_BP_TARGET_LOCK_MODE="flock"
            return 0
        fi
        exec 9>&- 2>/dev/null || true
        return 1
    fi

    ATLAS_HOOK_BP_TARGET_LOCK_DIR="$lock_file.d"
    if atlas_hook_bp_try_mkdir_lock "$ATLAS_HOOK_BP_TARGET_LOCK_DIR"; then
        ATLAS_HOOK_BP_TARGET_LOCK_MODE="mkdir"
        return 0
    fi

    return 1
}

atlas_hook_bp_release_target_lock() {
    case "${ATLAS_HOOK_BP_TARGET_LOCK_MODE:-}" in
        flock)
            flock -u 9 2>/dev/null || true
            exec 9>&- 2>/dev/null || true
            ;;
        mkdir)
            rm -rf "${ATLAS_HOOK_BP_TARGET_LOCK_DIR:-}" 2>/dev/null || true
            ;;
    esac
}

atlas_hook_bp_acquire_guard_lock() {
    local runtime="$1"
    local lock_file="$runtime/global-cap.lock"

    mkdir -p "$runtime" 2>/dev/null || return 1
    if command -v flock >/dev/null 2>&1; then
        exec 8>"$lock_file" || return 1
        if flock -n 8 2>/dev/null; then
            ATLAS_HOOK_BP_GUARD_LOCK_MODE="flock"
            return 0
        fi
        exec 8>&- 2>/dev/null || true
        return 1
    fi

    ATLAS_HOOK_BP_GUARD_LOCK_DIR="$lock_file.d"
    if atlas_hook_bp_try_mkdir_lock "$ATLAS_HOOK_BP_GUARD_LOCK_DIR"; then
        ATLAS_HOOK_BP_GUARD_LOCK_MODE="mkdir"
        return 0
    fi

    return 1
}

atlas_hook_bp_release_guard_lock() {
    case "${ATLAS_HOOK_BP_GUARD_LOCK_MODE:-}" in
        flock)
            flock -u 8 2>/dev/null || true
            exec 8>&- 2>/dev/null || true
            ;;
        mkdir)
            rm -rf "${ATLAS_HOOK_BP_GUARD_LOCK_DIR:-}" 2>/dev/null || true
            ;;
    esac
    ATLAS_HOOK_BP_GUARD_LOCK_MODE=""
}

atlas_hook_bp_live_slot_count() {
    local slots_dir="$1"
    local count=0
    local slot
    local pid

    for slot in "$slots_dir"/*.slot; do
        [ -e "$slot" ] || continue
        pid=""
        IFS= read -r pid < "$slot" 2>/dev/null || pid=""
        if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
            count=$((count + 1))
        else
            rm -f "$slot" 2>/dev/null || true
        fi
    done

    printf '%s' "$count"
}

atlas_hook_bp_acquire_global_slot() {
    local runtime="$1"
    local cap="${ATLAS_AOBG_HOOK_GLOBAL_CAP:-6}"
    local slots_dir="$runtime/slots"
    local count

    case "$cap" in
        ''|*[!0-9]*) cap=6 ;;
    esac
    [ "$cap" -gt 0 ] 2>/dev/null || return 0
    mkdir -p "$slots_dir" 2>/dev/null || return 0

    if ! atlas_hook_bp_acquire_guard_lock "$runtime"; then
        atlas_hook_bp_log "global_cap_guard_busy"
        return 1
    fi

    count="$(atlas_hook_bp_live_slot_count "$slots_dir")"
    if [ "$count" -ge "$cap" ] 2>/dev/null; then
        atlas_hook_bp_release_guard_lock
        atlas_hook_bp_log "global_cap"
        return 1
    fi

    ATLAS_HOOK_BP_SLOT_FILE="$slots_dir/$$-$RANDOM.slot"
    printf '%s\n' "$$" > "$ATLAS_HOOK_BP_SLOT_FILE" 2>/dev/null || true
    atlas_hook_bp_release_guard_lock
    return 0
}

atlas_hook_bp_loadavg() {
    local load=""

    if [ -n "${ATLAS_AOBG_HOOK_LOADAVG_OVERRIDE:-}" ]; then
        printf '%s' "$ATLAS_AOBG_HOOK_LOADAVG_OVERRIDE"
        return 0
    fi

    if [ -r /proc/loadavg ]; then
        IFS=' ' read -r load _ < /proc/loadavg 2>/dev/null || load=""
        printf '%s' "$load"
        return 0
    fi

    uptime 2>/dev/null | sed -E 's/.*load averages?: ([0-9.]+).*/\1/' 2>/dev/null || true
}

atlas_hook_bp_float_gt() {
    awk -v left="$1" -v right="$2" 'BEGIN { exit !(left > right) }' 2>/dev/null
}

atlas_hook_bp_cleanup() {
    [ -n "${ATLAS_HOOK_BP_SLOT_FILE:-}" ] && rm -f "$ATLAS_HOOK_BP_SLOT_FILE" 2>/dev/null || true
    atlas_hook_bp_release_target_lock
}

atlas_hook_backpressure_enter() {
    local hook_name="$1"
    local target="$2"
    local shed_on_load="${3:-0}"
    local runtime
    local loadavg
    local max_load="${ATLAS_AOBG_HOOK_LOADAVG_MAX:-8}"

    ATLAS_HOOK_BP_NAME="$hook_name"
    ATLAS_HOOK_BP_TARGET_HASH="$(atlas_hook_bp_hash "$hook_name|$target")"

    if [ "$shed_on_load" = "1" ] && [ -n "$max_load" ] && [ "$max_load" != "0" ]; then
        loadavg="$(atlas_hook_bp_loadavg)"
        if [ -n "$loadavg" ] && atlas_hook_bp_float_gt "$loadavg" "$max_load"; then
            atlas_hook_bp_log "load_shed" "$loadavg"
            exit 0
        fi
    fi

    runtime="$(atlas_hook_bp_runtime_dir)"
    mkdir -p "$runtime" 2>/dev/null || true

    if ! atlas_hook_bp_acquire_target_lock "$runtime"; then
        atlas_hook_bp_log "coalesced"
        exit 0
    fi

    if ! atlas_hook_bp_acquire_global_slot "$runtime"; then
        atlas_hook_bp_release_target_lock
        exit 0
    fi

    trap atlas_hook_bp_cleanup EXIT
}
