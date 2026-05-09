---
id: atlas-ai-cyber-recipes-promotion-runbook
type: engineering_knowledge
title: Atlas AI Cyber Recipes Promotion Runbook
status: scaffold
category: knowledge-base
priority: 80
summary: Operational checklist for promoting an individual cyber recipe into the canonical Super Tool Runtime.
tags:
  - atlas-ai
  - cyber-security
  - runbook
capabilities:
  - cyber_recipes_proposal
  - tool_runtime_registry
decisions:
  - A cyber recipe becomes real only after registry entry, wrapper, sandbox profile, tests and evidence validation.
maintenance:
  - Keep commands aligned with Super Tool Runtime CLI.
related_paths:
  - docs/engineering-knowledge-base/cyber-security/recipes-catalog.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/tool-runtime/contracts.md
  - docs/engineering-knowledge-base/cyber-security/refusal-matrix.md
---

# Atlas AI Cyber Recipes Promotion Runbook

## Promotion Steps

1. Confirm the tool does not already exist:

```bash
atlas tools list | grep <tool>
```

2. Create migration or seeder entry for `atlas_tool_definitions`.
3. Add recipe metadata with dry-run, sandbox, privacy, task type and authority group.
4. Implement wrapper class under the canonical Tool Runtime service area.
5. Add sandbox profile.
6. Add feature/unit tests for argv rendering, refusal/scope gates and evidence emission.
7. Run tool doctor and dry-run.
8. Update docs and registry references.

## Required Gates

| Gate | Requirement |
|---|---|
| Scope proof | Target must match approved scope. |
| Refusal matrix | Unsafe or unauthorized activity blocks before execution. |
| Decision Receipt | Runtime refuses without valid receipt. |
| Sandbox | Offensive recipes require sandbox. |
| Evidence | Run creates normalized evidence and ledger event. |
| Approval | Active exploit, C2, distributed scan and external MCP require extra approval. |

## Validation Commands

```bash
php artisan migrate
php artisan test tests/Feature/Ai/Tools
atlas tools doctor
atlas tools run-recipe <tool> --recipe=<name> --dry-run
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge docs-health --json
```

## Definition Of Done

- Registry entry exists.
- Wrapper renders argv safely.
- Scope/refusal tests pass.
- Sandbox profile exists.
- Evidence is normalized.
- Docs link to the recipe owner.
- No direct provider or raw MCP bypass exists.
