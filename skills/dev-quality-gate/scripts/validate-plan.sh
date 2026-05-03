#!/usr/bin/env bash
set -euo pipefail

PLAN="${1:?Usage: validate-plan.sh <plan.json>}"
WORKSPACE="${ATLAS_WORKSPACE:-$(pwd)}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ATLAS_SERVER_ROOT="${ATLAS_SERVER_ROOT:-$(cd "$SCRIPT_DIR/../../.." && pwd)}"
ATLAS_MIN_PHP_VERSION="${ATLAS_MIN_PHP_VERSION:-8.4.0}"

php_satisfies_minimum() {
  local candidate="$1"
  "$candidate" -r 'exit(version_compare(PHP_VERSION, $argv[1], ">=") ? 0 : 1);' "$ATLAS_MIN_PHP_VERSION" >/dev/null 2>&1
}

resolve_php_bin() {
  local configured="${ATLAS_PHP_BIN:-}"
  local resolved=""

  if [ -n "$configured" ]; then
    if [ -x "$configured" ]; then
      resolved="$configured"
    else
      resolved="$(command -v "$configured" 2>/dev/null || true)"
    fi

    if [ -n "$resolved" ] && php_satisfies_minimum "$resolved"; then
      printf '%s\n' "$resolved"
      return 0
    fi
  fi

  local command_php
  command_php="$(command -v php 2>/dev/null || true)"
  local candidates=()
  if [ -n "$command_php" ]; then
    candidates+=("$command_php")
  fi
  candidates+=(
    "/opt/homebrew/bin/php"
    "/opt/homebrew/opt/php/bin/php"
    "/usr/local/bin/php"
    "/usr/local/opt/php/bin/php"
    "/opt/homebrew/Cellar/php/8.5.0/bin/php"
  )

  local candidate
  for candidate in "${candidates[@]}"; do
    if [ -x "$candidate" ] && php_satisfies_minimum "$candidate"; then
      printf '%s\n' "$candidate"
      return 0
    fi
  done

  printf 'Error: PHP >= %s not found for Atlas dev-quality-gate. Set ATLAS_PHP_BIN to a compatible PHP binary.\n' "$ATLAS_MIN_PHP_VERSION" >&2
  return 127
}

PHP_BIN="$(resolve_php_bin)"

if [ ! -f "$PLAN" ]; then
  printf '{"ok":false,"error":"plan_file_not_found","message":"Plan file not found: %s"}\n' "$PLAN"
  exit 1
fi

validation_json="$(
  "$PHP_BIN" -r '
  $planPath = $argv[1];
  $workspace = $argv[2];
  $workspaceReal = realpath($workspace);
  if ($workspaceReal === false || ! is_dir($workspaceReal)) {
      fwrite(STDERR, json_encode([
          "ok" => false,
          "error" => "workspace_not_found",
          "message" => "Workspace not found: ".$workspace,
      ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
      exit(2);
  }

  $raw = file_get_contents($planPath);
  $plan = json_decode($raw ?: "", true);
  if (! is_array($plan)) {
      fwrite(STDERR, json_encode([
          "ok" => false,
          "error" => "invalid_json",
          "message" => json_last_error_msg(),
      ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
      exit(3);
  }

  $files = $plan["files_to_modify"] ?? null;
  if (! is_array($files) || $files === []) {
      fwrite(STDERR, json_encode([
          "ok" => false,
          "error" => "missing_files_to_modify",
          "message" => "Plan must include a non-empty files_to_modify array.",
      ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
      exit(4);
  }

  $tests = $plan["tests"] ?? [];
  $warnings = [];
  if (! is_array($tests) || $tests === []) {
      $warnings[] = "tests array is empty; final completion should remain needs_review until tests are declared and run.";
  }

  $checked = [];
  foreach ($files as $path) {
      if (! is_string($path) || trim($path) === "") {
          fwrite(STDERR, json_encode([
              "ok" => false,
              "error" => "invalid_path",
              "message" => "files_to_modify must contain only non-empty strings.",
          ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
          exit(5);
      }

      $candidate = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : $workspaceReal.DIRECTORY_SEPARATOR.$path;
      $parent = is_dir($candidate) ? $candidate : dirname($candidate);
      $parentReal = realpath($parent);
      if ($parentReal === false) {
          fwrite(STDERR, json_encode([
              "ok" => false,
              "error" => "parent_not_found",
              "message" => "Parent directory does not exist for path: ".$path,
          ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
          exit(6);
      }

      $absolute = $parentReal.DIRECTORY_SEPARATOR.basename($candidate);
      if ($absolute !== $workspaceReal && ! str_starts_with($absolute, $workspaceReal.DIRECTORY_SEPARATOR)) {
          fwrite(STDERR, json_encode([
              "ok" => false,
              "error" => "path_outside_workspace",
              "message" => "Path outside workspace: ".$path,
              "workspace" => $workspaceReal,
          ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
          exit(7);
      }

      $checked[] = [
          "path" => $path,
          "absolute" => $absolute,
          "exists" => file_exists($absolute),
      ];
  }

  echo json_encode([
      "ok" => true,
      "workspace" => $workspaceReal,
      "plan" => [
          "intent" => is_scalar($plan["intent"] ?? null) ? (string) $plan["intent"] : null,
          "files_to_modify_count" => count($files),
          "tests_declared_count" => is_array($tests) ? count($tests) : 0,
          "side_effects_declared_count" => is_array($plan["side_effects"] ?? null) ? count($plan["side_effects"]) : 0,
      ],
      "checked_paths" => $checked,
      "warnings" => $warnings,
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  ' "$PLAN" "$WORKSPACE"
)"

set +e
quality_json="$(
  cd "$ATLAS_SERVER_ROOT"
  "$PHP_BIN" artisan atlas:cli:quality --workspace="$WORKSPACE" --json
)"
quality_exit=$?
set -e

"$PHP_BIN" -r '
$validation = json_decode($argv[1], true);
$quality = json_decode($argv[2], true);
$qualityExit = (int) $argv[3];
if (! is_array($validation)) {
    fwrite(STDERR, "Error: validator output was not valid JSON.".PHP_EOL);
    exit(8);
}
if (! is_array($quality)) {
    $quality = [
        "ok" => false,
        "status" => "failed",
        "error" => "quality_output_invalid",
        "exit_code" => $qualityExit,
        "raw_output_excerpt" => mb_substr($argv[2], 0, 2000),
    ];
}
$ok = (bool) ($validation["ok"] ?? false) && (bool) ($quality["ok"] ?? false) && $qualityExit === 0;
echo json_encode([
    "ok" => $ok,
    "validation" => $validation,
    "quality" => $quality,
    "quality_exit_code" => $qualityExit,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($ok ? 0 : 9);
' "$validation_json" "$quality_json" "$quality_exit"
