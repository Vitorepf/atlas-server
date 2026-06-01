---
id: atlas-trust-ledger-canonical
type: engineering_knowledge
title: Atlas Trust Ledger Canonical
status: active
category: atlas-ai
priority: 100
summary: Doc dedicado ao Trust Ledger do Atlas. Registra append-only o historico de promocoes, demote, evidencia falha, evidencia sucesso, signature events e self-construction approvals. Pontuacao Trust Ledger 0-1 e usada para gating de autonomia L4+.
tags:
  - atlas-ai
  - trust-ledger
  - append-only
  - autonomy-gating
  - signature-events
capabilities:
  - trust_ledger_append_only
  - autonomy_gating_score
  - history_audit
decisions:
  - Trust Ledger e append-only e canonico para gating de autonomia.
  - Score < 0.7 sustained bloqueia promocao L4+.
maintenance:
  - Atualize ao mudar score formula, adicionar event kind.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomy-ladder-promotion-runbook.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-ai-self-improvement-ladder.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-trust-ledger-canonical
graph_title: Atlas Trust Ledger Canonical
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-agentic-engineering-os
graph_status: active
graph_source: repo
human_name: Atlas Trust Ledger Canonical
canonical_name: Atlas Trust Ledger Canonical
technical_name: atlas-trust-ledger-canonical
cartography_type: ledger
canonical_source: docs/engineering-knowledge-base/atlas-trust-ledger-canonical.md
owner: atlas-ai
product_name: Atlas Trust Ledger Canonical
internal_product_name: Atlas Trust Ledger
runtime_acronym: ATL
technical_runtime: atlas.trust_ledger
repo_paths:
  - docs/engineering-knowledge-base/atlas-trust-ledger-canonical.md
allowed_changes:
  - Refinar score, adicionar event kind, ajustar threshold.
forbidden_changes:
  - Permitir update no ledger (must be append-only).
  - Permitir promote L4+ com score < 0.7.
depends_on:
  - atlas-evidence-certification-runtime
flows_to:
  - atlas-autonomy-ladder-promotion-runbook
unlocks:
  - autonomy-gating-runtime
governs:
  - atlas_ai.trust_ledger
evidence:
  - docs/engineering-knowledge-base/atlas-trust-ledger-canonical.md
evidence_refs:
  - symbol: AtlasTrustLedgerCanonicalService
  - command: atlas:aaeos:trust-ledger-canonical
  - test: AtlasTrustLedgerCanonicalTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - ledger
  - trust
quality_gates:
  - append-only
  - score-formula-defined
  - threshold-l4-plus-defined
failure_modes:
  - Update no ledger.
  - Score sem evidence.
observability_signals:
  - trust_ledger_score
  - trust_ledger_event_count
next_actions:
  - Implementar `AtlasTrustLedgerService` append-only.
---
# Atlas Trust Ledger Canonical

## Resumo

Trust Ledger append-only para gating de autonomia.

## Papel no Atlas

Self-Improvement Ladder cita Trust Ledger; este doc o canoniza.

## Onde Se Encaixa

```text
atlas-evidence-certification-runtime (receipts)
  +-- atlas-trust-ledger-canonical (este doc, score history)
       +-- atlas-autonomy-ladder-promotion-runbook (uses score)
```

## Contratos

### Event schema (`atlas.trust_ledger.event.v1`)

```text
{
  "schema": "atlas.trust_ledger.event.v1",
  "event_id": "<uuid>",
  "kind": "promotion|demote|cert_pass|cert_fail|self_construction_approved|signature_breach|gate_pass|gate_fail|incident_resolved|drift_detected",
  "actor": {"kind":"agent|operator|system","id":"..."},
  "target": {"kind":"obra|department|intent|ladder","id":"..."},
  "weight": <float>,
  "evidence_hashes": ["sha256:..."],
  "at": "<iso8601>"
}
```

### Score formula

```text
score(t) = sigmoid( sum( weight_i * sign_i for i in events_window(90d)) / norm)

onde:
  sign_i = +1 para success kinds, -1 para failure kinds
  weight_i da tabela de pesos
  norm = sum(|weight_i|)
```

### Tabela de pesos

| Kind | Weight | Sign |
|------|--------|------|
| cert_pass | 1.0 | + |
| cert_fail | 1.5 | - |
| promotion | 0.5 | + |
| demote | 1.0 | - |
| self_construction_approved | 1.5 | + |
| signature_breach | 3.0 | - |
| gate_pass | 0.2 | + |
| gate_fail | 0.5 | - |
| incident_resolved | 0.8 | + |
| drift_detected | 0.5 | - |

### Thresholds

| Score | Effect |
|-------|--------|
| >=0.95 | L7 elegivel |
| >=0.90 | L6 elegivel |
| >=0.80 | L5 elegivel |
| >=0.70 | L4 elegivel |
| 0.50-0.70 | L3 max |
| <0.50 | freeze runtime + Architect review |

## Fluxo

```mermaid
flowchart LR
  Event[runtime event]
  Event --> Append[append-only ledger]
  Append --> Score[recompute score]
  Score --> Gate{gate decision}
  Gate -->|score>=threshold| Promote[allow promotion]
  Gate -->|score<threshold| Block[block promotion]
```

## Regras para IA

- Ledger NUNCA update; sempre append.
- Score recomputado a cada evento + 1x dia agendado.
- Promote L4+ exige score do dia.

## Escopo de Implementacao

`AtlasTrustLedgerService` com `append()` e `score()`. Banco append-only.

## Dependencias

Evidence Cert Runtime, Autonomy Ladder Runbook (T2.1).

## Evidencias

Comando: `atlas:trust:status --json`. Replay determinístico.

## Riscos

Update silencioso (mitigacao: hash chain), score volatilidade (mitigacao: rolling 90d).

## O que este doc NAO e

Nao e Decision Receipt v2 (eventos individuais); e historico agregado.

## Exemplos

3 cert_fail em 7 dias -> score cai de 0.85 para 0.72 -> L4 ainda elegivel mas L5 promocao bloqueada.

## Proximas Acoes

1. Implementar service.
2. Hash chain integrado.
3. Dashboard cockpit zona 12.
