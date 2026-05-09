---
id: legacy-cleanup-handoff-checklist
type: engineering_knowledge
title: Legacy Cleanup Handoff Checklist
status: active
category: documentation-governance
priority: 85
summary: Checklist for finishing an Atlas legacy cleanup session or PR with enough evidence for the next AI.
tags:
  - atlas
  - documentation
  - handoff
capabilities:
  - documentation_quality_gate
  - legacy_documentation_cleanup
decisions:
  - Cleanup handoff must report files changed, source material preserved and validation commands.
maintenance:
  - Keep this checklist short and executable.
related_paths:
  - docs/engineering-knowledge-base/legacy-documentation-cleanup-plan.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
---

# Legacy Cleanup Handoff Checklist

## Before Final Message

- [ ] State the cleanup wave executed.
- [ ] List active docs changed.
- [ ] List archived source material created or used.
- [ ] List redirects or canonical replacements added.
- [ ] Confirm no runtime files were changed, or explain why runtime was in scope.
- [ ] Confirm no personal/vault material was promoted without review.

## Required Validation

```bash
atlas engineering knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
git diff --check -- docs/engineering-knowledge-base
atlas engineering knowledge sync --prune --json
atlas engineering knowledge index-code --prune --workspace=/Users/vitorepf/develop/Atlas/atlas-server --json
```

## Final Report Shape

Use this shape:

```md
Concluido:
- compactei X;
- preservei Y;
- criei Z child docs.

Validacao:
- docs-health: ok;
- architecture-validate: ok;
- diff check: ok;
- sync/index: ok.

Restante:
- N docs split_required.
```

## Stop Conditions

Stop and report if:

- canonical authority conflicts with another doc;
- a source contains sensitive personal material;
- a delete candidate still has live references;
- validation fails in a way unrelated to the cleanup.
