#!/usr/bin/env bash
# Atlas Forge Rivals Real Battery Operator Harness · verify chain
# - Roda toda a verification chain canonica em ordem
# - Falhas esperadas (worktrees nao provisionados; external_rivals blocked; etc)
#   nao abortam a chain · sao logadas como `expected_blocked`
# - Saida nao-zero apenas se um teste real quebra (`tests_failed`,
#   `docs_health_drift`, `architecture_violation`, `git_whitespace_dirty`).

set -uo pipefail
cd /Users/vitorepf/develop/Atlas/atlas-server
PHP=/opt/homebrew/bin/php
EXIT=0

run_required() { # comando que precisa exit 0
  local label="$1" ; shift
  echo "▸ $label"
  if ! "$@" ; then
    echo "✗ $label FAILED (required)"
    EXIT=1
  fi
}

run_canon() { # comando cujo exit nao-zero pode ser canon (external_rivals blocked, worktrees missing, etc)
  local label="$1" ; shift
  echo "▸ $label"
  "$@" || echo "  · $label returned non-zero (canon: blocker honesto preservado)"
}

# Tests: estes precisam passar
run_required "tests rivals/forge/fairclaude" \
  env PYTHONDONTWRITEBYTECODE=1 \
  "$PHP" artisan test tests/Feature/Ai/Programming --filter='Rivals|ForgeNativeRivals|AtlasForge|FairClaudePolicy'

# Canon-blocking ok: worktrees missing default
run_canon "doctor" \
  "$PHP" artisan atlas:engineering:benchmark:rivals-harness doctor --json --strict

# Canon-blocking ok: full-smoke encadeia + reporta honestamente
run_canon "full-smoke" \
  "$PHP" artisan atlas:engineering:benchmark:rivals-harness full-smoke --json

# Audit: invariants do harness · precisa estar `available`
run_required "audit invariants" \
  "$PHP" artisan atlas:engineering:benchmark:rivals-harness audit --json

# Completion audit: `status=blocked` esperado enquanto external_rivals_certification
# permanece blocked. Apenas validamos que o script roda — nao exigimos exit 0.
run_canon "completion audit" \
  "$PHP" artisan atlas:programming:completion-audit --json

# Docs health: PRECISA passar (sem deriva canonica)
run_required "docs health" \
  "$PHP" artisan atlas:engineering:knowledge docs-health --json

# Architecture validate: PRECISA passar
run_required "architecture validate" \
  "$PHP" artisan atlas:ai:architecture-validate --json

# Whitespace check: PRECISA passar
run_required "git diff --check" git diff --check

if [ $EXIT -eq 0 ]; then
  echo "✓ verify chain complete · all required gates green"
else
  echo "✗ verify chain finished with $EXIT required-gate failure(s)"
fi
exit $EXIT
