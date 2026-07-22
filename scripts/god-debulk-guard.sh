#!/usr/bin/env bash

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
audit_output="$(/opt/homebrew/bin/php "$root/scripts/god-debulk-audit.php")"
printf '%s\n' "$audit_output"

gt5k="$(awk -F= '/^godfiles_gt_5k=/{print $2}' <<<"$audit_output")"
baseline=14

if [[ ! "$gt5k" =~ ^[0-9]+$ ]]; then
    printf 'GOD_DEBULK_GUARD_FAIL invalid_audit_count\n' >&2
    exit 1
fi

subject="$(git -C "$root" log -1 --format=%s)"
shopt -s nocasematch
if [[ "$subject" =~ (^|[^[:alnum:]_])residual[[:space:]]+pass[[:space:]]+[0-9]+($|[^[:alnum:]_]) ]]; then
    printf 'GOD_DEBULK_GUARD_FAIL residual_pass_commit\n' >&2
    exit 1
fi
shopt -u nocasematch

if (( gt5k > baseline )); then
    printf 'GOD_DEBULK_GUARD_FAIL gt5k_baseline_regression baseline=%s observed=%s\n' "$baseline" "$gt5k" >&2
    exit 1
fi

if (( gt5k > 0 )); then
    printf 'GOD_DEBULK_GUARD_WARNING existing_gt5k_baseline=%s observed=%s\n' "$baseline" "$gt5k"
fi

if [[ "${GOD_DEBULK_ENFORCE:-0}" == "1" ]] && (( gt5k > 0 )); then
    printf 'GOD_DEBULK_GUARD_FAIL enforcement_requires_zero_gt5k observed=%s\n' "$gt5k" >&2
    exit 1
fi

printf 'GOD_DEBULK_GUARD_OK\n'
