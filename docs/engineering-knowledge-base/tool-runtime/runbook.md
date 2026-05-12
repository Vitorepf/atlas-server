---
id: atlas-tool-runtime-runbook
type: engineering_knowledge
title: Atlas Tool Runtime Runbook
status: active
category: maintenance
priority: 97
summary: Operational commands for doctor, authority, recipes, evidence, gates, approvals, waivers and release checks.
tags:
  - atlas
  - tools
  - runbook
capabilities:
  - tool_registry
  - tool_gates
  - finding_waivers
decisions:
  - Operators should prefer recipes and dry-run before new automated execution.
  - Approvals and waivers are audit records, not invisible bypasses.
maintenance:
  - Update when tool CLI/API contracts change.
related_paths:
  - docs/engineering-knowledge-base/tool-runtime/contracts.md
  - docs/engineering-knowledge-base/tool-runtime/evidence-gates.md
  - app/Console/Commands/AtlasToolsCommand.php
  - app/Http/Controllers/AtlasToolRuntimeController.php
---

# Atlas Tool Runtime Runbook

## Discovery

```bash
./bin/atlas tools doctor --workspace=<repo> --json
./bin/atlas tools list --json
./bin/atlas tools authority --json
./bin/atlas tools commands <tool> --workspace=<repo> --json
```

## Recipes And Runs

```bash
./bin/atlas tools run-recipe gitleaks --recipe=detect-redacted --workspace=<repo> --json
./bin/atlas tools run <tool> --workspace=<repo> --command=<bin> --command=--version --dry-run --json
```

Use `--tool-env=KEY=VALUE`; sensitive env keys are rejected. Use
`--output-limit`, `--max-execution-tier`, `--sandbox-mode`, `--privacy-level`,
`--task-type` and `--requires-provider-safe` to make policy explicit.

## Evidence And Gates

```bash
./bin/atlas tools evidence --workspace=<repo> --status=failed --json
./bin/atlas tools evidence-show --run-id=<id> --workspace=<repo> --json
./bin/atlas tools gate --workspace=<repo> --require-evidence --json
./bin/atlas tools gate --workspace=<repo> --max-age-minutes=240 --stale-blocks --latest-per-tool --json
./bin/atlas tools release-gate --workspace=<repo> --json
```

Evidence store writes fail closed when required tool runtime tables are missing.
Treat a missing run as unavailable infrastructure, not a skipped tool result.
Do not reconstruct artifacts or findings manually; restore the schema and rerun
the sensor or command.

## Approvals And Waivers

```bash
./bin/atlas tools approve codeql --workspace=<repo> --max-execution-tier=T2 --ttl-hours=24 --json
./bin/atlas tools policies --workspace=<repo> --json
./bin/atlas tools revoke codeql --workspace=<repo> --json
./bin/atlas tools waive-finding --finding-id=<id> --reason="false positive" --ttl-hours=24 --json
./bin/atlas tools revoke-finding-waiver --finding-id=<id> --reason="expired exception" --json
```

Approvals allow execution under guardrails. Waivers suppress specific findings
while preserving evidence.

## Engineering Sensors

```bash
./bin/atlas engineering security-scan --workspace=<repo> --profile=release --json
./bin/atlas engineering sbom --workspace=<repo> --profile=release --json
./bin/atlas engineering api-contract --workspace=<repo> --strict --json
```

## Validation

```bash
/opt/homebrew/bin/php artisan test --filter=AtlasToolRuntimeCoreTest
/opt/homebrew/bin/php artisan test --filter=AtlasEngineeringQualityScanCommandTest
/opt/homebrew/bin/php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge docs-health
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune
git diff --check
```
