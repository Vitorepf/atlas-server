---
id: evidence-ledger
type: engineering_knowledge
title: Evidence Ledger
status: active
category: kernel
priority: 97
summary: Ledger append-only que registra decisoes, execucoes, gates, custos, traces, learning signals e auditoria.
tags:
  - atlas
  - evidence
  - ledger
  - audit
capabilities:
  - evidence_ledger
  - audit_trail
  - replay
decisions:
  - Tudo que executa deve voltar como evidencia ou proposta.
  - Evidence Ledger e registro factual, nao resumo narrativo.
maintenance:
  - Atualizar quando schema de evento, replay, retention ou read model mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
  - docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: evidence-ledger
graph_title: Evidence Ledger
graph_world: atlas
graph_layer: gear
graph_kind: step
graph_parent: atlas-ai-kernel-pipeline
graph_status: active
graph_source: repo
human_name: Evidence Ledger
canonical_name: Evidence Ledger
technical_name: evidence-ledger
cartography_type: step
canonical_source: docs/engineering-knowledge-base/system-graph/evidence-ledger.md
owner: atlas-kernel
repo_paths:
  - docs/engineering-knowledge-base/system-graph/evidence-ledger.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
allowed_changes:
  - Evoluir eventos, retention e read models com compatibilidade auditavel.
forbidden_changes:
  - Usar ledger como memoria subjetiva.
  - Apagar ou reescrever evidencia sem politica explicita.
depends_on:
  - quality-gates
  - decision-receipt
flows_to:
  - learning-proposals
  - evidence-loop
unlocks:
  - atlas-decide
  - learning-proposals
governs:
  - audit
  - telemetry
  - learning-signals
evidence:
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
evidence_refs:
  - symbol: AtlasEvidenceLedgerContractService
  - command: atlas:aaeos:evidence-ledger-contract
  - test: AtlasEvidenceLedgerContractTest
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: critical
next_actions:
  - Conectar eventos do Atlas Code e Cartografia live-doc ao Ledger quando houver execucao real.
visual_tags:
  - module
  - module
  - system-graph

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok
---
# Evidence Ledger

## Resumo

Evidence Ledger e o registro factual do que o Atlas decidiu, executou, testou, reparou e aprendeu. Ele deve ser append-only e auditavel.

## Papel no Atlas

Ele fecha o ciclo de confianca. Sem evidencia, o Atlas nao sabe se melhorou, se falhou ou se so contou uma historia bonita.

## Onde Se Encaixa

Pai: `atlas-ai-kernel-pipeline`. Recebe gates, runtime e receipts; alimenta learning e feedback para `atlas-decide`.

## Contratos

Entrada: eventos de decisao, execucao, gate, custo, latencia, erro e repair. Saida: trilha auditavel, read models e sinais de aprendizado.

## Fluxo

Decision Receipt cria contrato. Runtime executa. Quality Gates verificam. Evidence Ledger registra. Learning Proposals interpretam.

## Regras para IA

IA deve diferenciar evidencia factual de interpretacao. Comentario de agente nao substitui evento, teste, path, run ou receipt.

## Escopo de Implementacao

Permitido: eventos append-only, read models, replay, auditoria e retention. Proibido: reescrever historico sem politica.

## Dependencias

- `quality-gates`
- `decision-receipt`
- `atlas-ai-telemetry-evidence-performance`

## Evidencias

- `docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md`

## Riscos

- Ledger virar log solto sem schema.
- Retention destruir informacao necessaria.
- Learning usar evento sem qualidade.

## Exemplos

Um gate de teste deve registrar comando, status, duracao, saida relevante e trace ligado ao receipt.

## Proximas Acoes

Definir read model de evidence por Obra para Atlas Code.
