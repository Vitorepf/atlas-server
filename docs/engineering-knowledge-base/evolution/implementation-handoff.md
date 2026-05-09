---
id: atlas-ai-evolution-implementation-handoff
type: engineering_knowledge
title: Evolution Implementation Handoff
status: active
category: roadmap
priority: 94
summary: Execution order, AP handoff and validation rules for implementing the Atlas AI evolution roadmap.
tags:
  - atlas-ai
  - implementation
  - ap
  - handoff
capabilities:
  - roadmap_handoff
  - architecture_validation
  - documentation_governance
decisions:
  - Implement evolution as APs and focused contracts.
  - Close one DoD before opening the next autonomy layer.
  - Run documentation and architecture validation after each roadmap change.
maintenance:
  - Keep AP status synchronized with this handoff.
  - Do not add implementation details to the parent roadmap.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-evolution-roadmap.md
  - docs/engineering-knowledge-base/evolution/README.md
  - docs/ap
---

# Evolution Implementation Handoff

## Canonical Order

| Order | Work |
|---|---|
| 1 | AP-99 Provider Performance Contract |
| 2 | AP-100 Context Pack Manifest Reflection |
| 3 | AP-101 Retrieval Router |
| 4 | Self-RAG / Self-Reflection Gate |
| 5 | Graph RAG explicit and observed relations |
| 6 | Tool Synthesis Sandbox |
| 7 | Zero-Click shadow mode |
| 8 | Personal longitudinal projections |
| 9 | Proactive Curator proposal loops |

## Agent Handoff

Every implementation agent must state:

1. AP or child doc being implemented;
2. existing contract extended;
3. files owned;
4. migrations or events added;
5. tests added;
6. validation commands run;
7. docs updated.

## Validation Commands

```bash
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge docs-health --json
atlas engineering knowledge sync --prune --json
atlas engineering knowledge index-code --prune --workspace=/Users/vitorepf/develop/Atlas/atlas-server --json
git diff --check
```

## Done Means

- No new split_required blocker.
- No parallel subsystem.
- Canonical index or README points to the new child doc.
- Evidence and Policy impact is explicit.
- Manual override and autonomy boundaries are documented.
