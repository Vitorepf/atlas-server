---
title: Atlas ACMF Schema Evolution Proposer
slug: atlas-cognitive-memory-fabric-schema-evolution
status: building
risk_level: high
graph_parent: atlas-constitutional-kernel
depends_on:
  - atlas-constitutional-kernel
  - atlas-autonomy-admission
authority_class: proposer
forbidden_changes:
  - auto_apply_schema_migration
  - propose_without_kernel_gate
  - silent_schema_bump
schema:
  - atlas.acmf.schema_proposal.v1
---

# Atlas ACMF Schema Evolution Proposer

Detecta schemas existentes e propõe v+1 quando triggers canônicos disparam. Proposals são **append-only** + **requires_human_approval=true** sempre. Nunca aplica migration sozinho.

## Triggers canônicos
- `operator_request` (explícito)
- `frontmatter_drift` (doc declara versão diferente do service constant)
- `extension_pressure` (schema referenciado mais de EXTENSION_PRESSURE_THRESHOLD vezes)

## Gates
- Constitutional Kernel sobre `change_kind=schema_evolution`
- Autonomy Admission sobre o mesmo

## API
```php
propose(array $input): array
listProposals(): array
```

## Storage
`storage/atlas/acmf/schema_proposals.jsonl`

## CLI
`php artisan atlas:acmf:schema-evolution --action=propose --input-json='{"current_schema":"atlas.X.v1","trigger":"operator_request"}'`
