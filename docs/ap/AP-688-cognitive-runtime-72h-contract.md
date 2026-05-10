---
id: AP-688
title: Cognitive Runtime 72h Contract
status: proposed
owner: atlas-ai-cognitive-runtime
area: cognitive-runtime
summary: Formaliza memoria governada, busca de contexto, sessoes longas de 72h, compactacao automatica e auditoria cognitiva como frente P0 do Atlas.
---

# AP-688 Cognitive Runtime 72h Contract

## Objective

Transformar o Cognitive Runtime em capacidade verificavel: o Atlas deve manter
alta qualidade decisoria por 72 horas de trabalho real, com memoria governada,
retrieval preciso, compactacao auditavel, handoff seguro e evidence suficiente
para replay.

## Scope

Inclui:

- long-session snapshot;
- cognitive audit packet;
- compaction quality gate;
- retrieval benchmark;
- failure-mode matrix;
- runbook operacional;
- telemetria read-only para score cognitivo.

Nao inclui:

- memoria paralela;
- provider-owned memory;
- auto-aplicacao de policy;
- embedding externo sem AP propria;
- daemon novo;
- runtime executor novo.

## Required Contracts

| Contrato | Owner doc |
|---|---|
| Lei mae | `docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md` |
| Schemas e packets | `docs/engineering-knowledge-base/cognitive-runtime/schemas-and-packets.md` |
| Benchmark de retrieval | `docs/engineering-knowledge-base/cognitive-runtime/retrieval-benchmark.md` |
| Runbook | `docs/engineering-knowledge-base/cognitive-runtime/runbook.md` |
| Failure modes | `docs/engineering-knowledge-base/cognitive-runtime/failure-modes.md` |

## Acceptance Criteria

- Snapshot de sessao longa tem schema, estados e campos obrigatorios.
- Audit packet mede ganho, dano, drift, repeticao, contaminacao e custo.
- Compactacao bloqueia continuidade quando evidence, hot files, policy ou privacy ficam ambiguos.
- Retrieval benchmark mede precision@k, missed critical context, stale context use e contamination.
- Runbook declara comandos, criterios de parada e investigacao.
- Architecture validation e docs-health permanecem verdes.

## DoD

Esta AP fica `ready` somente quando uma sessao dogfood de engenharia de longa
duracao puder produzir snapshot, compactacao, handoff e audit packet
reproduziveis, sem depender de chat bruto e sem violar Kernel/Policy/Receipt.

## Validation

```bash
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
php artisan atlas:engineering:knowledge sync --prune --json
php artisan atlas:engineering:knowledge index-code --prune --json
git diff --check
```
