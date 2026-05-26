---
title: Atlas ACOP → ACRS Reflexive Streaming Bridge
slug: atlas-acop-acrs-reflexive-bridge
status: building
risk_level: medium
graph_parent: atlas-cognition-operating-system
depends_on:
  - atlas-cognition-operating-system
authority_class: bridge
forbidden_changes:
  - mutate_acrs_directly
  - silent_signal_emission
schema:
  - atlas.acop_to_acrs.signal.v1
---

# Atlas ACOP → ACRS Reflexive Streaming Bridge

Closes the canonical loop where AtlasContextObservabilityPlaneService (ACOP) signals — context_quality, retrieval_latency, leak_risk, freshness_drift, cost_pressure — feed AtlasContextRankingSystemService (ACRS) so ranking weights adapt to observed health.

## Signal kinds canônicos
- `context_quality`
- `retrieval_latency`
- `leak_risk`
- `freshness_drift`
- `cost_pressure`

## Severities
`low | medium | high`

## API
```php
emit(array $input): array
listSignals(int $limit=100): array
summary(): array  // by_kind × severity tally
```

## Storage
`storage/atlas/acop_to_acrs/signals.jsonl` (append-only)

## CLI
`php artisan atlas:acop-acrs:bridge --action=emit --kind=X --severity=Y`
