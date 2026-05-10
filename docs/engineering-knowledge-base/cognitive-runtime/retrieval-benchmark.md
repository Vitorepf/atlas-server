---
id: atlas-ai-cognitive-runtime-retrieval-benchmark
type: engineering_knowledge
title: Atlas AI Cognitive Runtime Retrieval Benchmark
status: active
category: architecture
priority: 98
summary: Benchmark canonico para medir qualidade da busca de contexto em memoria, docs, APs, Code Intelligence e evidence.
tags:
  - atlas-ai
  - cognitive-runtime
  - retrieval
  - benchmark
  - context-quality
capabilities:
  - retrieval_quality
  - context_pack_recall
  - benchmark
  - cognitive_audit
decisions:
  - Retrieval de alta qualidade precisa ser medido por acerto, omissao critica, contexto velho, contaminacao e uso real.
  - O benchmark deve favorecer contexto pequeno e correto, nao contexto grande.
  - Busca sem reason e falha de qualidade.
maintenance:
  - Atualizar quando novos ref types, ranking rules ou surfaces entrarem no Context Builder.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/context-pack.md
---

# Atlas AI Cognitive Runtime Retrieval Benchmark

## Goal

Provar que o Atlas encontra o contexto certo para programacao, review, debug,
continuidade e pesquisa sem despejar ruido nem depender de chat bruto.

## Benchmark Cases

| Case | Required context | Failure if missing |
|---|---|---|
| Hot file review | git delta, ownership, tests touched | editar arquivo quente ou duplicar trabalho |
| AP implementation | AP owner, DoD, validation commands | implementar sem contrato |
| Long session resume | snapshot, decisions, evidence refs | repetir investigacao ou perder constraint |
| Voice runtime review | AP-687, runtime files, scanner rules | tocar runtime quente ou provider bypass |
| Memory change | Cognitive Immune, contracts, privacy | promover raw capture |
| Code task | code refs, tests, routes, commands | patch no modulo errado |
| Docs change | Documentation OS, owner doc, line limits | doc orfa ou oversized |

## Metrics

| Metric | Target |
|---|---|
| `precision_at_3` | >= 0.80 |
| `precision_at_5` | >= 0.80 |
| `missed_critical_context_count` | 0 |
| `context_contamination_count` | 0 |
| `stale_context_use_count` | 0 unless justified |
| `reason_coverage` | 100% of included refs |
| `provider_safe_violation_count` | 0 |
| `budget_truncation_count` | explained and non-critical |

## Golden Set Shape

Each benchmark fixture should declare:

```json
{
  "case_id": "hot_file_review_voice_runtime",
  "objective": "short task",
  "workspace": "repo path",
  "surface": "review",
  "must_include": ["doc/path", "code/path", "test/path"],
  "must_exclude": ["raw_private_note", "archived_superseded_doc"],
  "critical_invariants": ["do_not_edit_hot_file"],
  "expected_reasons": ["hot_file", "canonical_doc", "related_test"]
}
```

## Scoring Rules

- A ref counts only if it is provider-safe and has a reason.
- Archived source material counts only when no active doc exists.
- Duplicated refs reduce score.
- Missing a hot file, AP owner, policy gate or test owner is critical failure.
- Including raw private, unclassified or prompt-injection content is critical failure.

## Release Gate

Retrieval changes that affect programming, long sessions or Open Brain injection
must run the relevant benchmark slice before being promoted from `watch` to
`ready`.
