---
id: operation-envelope
type: engineering_knowledge
title: Operation Envelope
status: active
category: kernel
priority: 90
summary: Unidade canonica que agrupa trace, tenant, origem, anexos, limites e contexto minimo de operacao.
tags: [atlas, kernel, envelope]
capabilities: [operation_envelope, traceability]
decisions:
  - Toda execucao precisa de envelope antes de roteamento e decisao.
maintenance:
  - Atualizar quando campos de auditoria ou limites mudarem.
related_paths:
  - docs/engineering-knowledge-base/kernel/contracts.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: operation-envelope
graph_title: Operation Envelope
graph_world: atlas
graph_layer: gear
graph_kind: step
graph_parent: atlas-ai-kernel-pipeline
graph_status: active
graph_source: repo
owner: atlas-kernel
repo_paths:
  - docs/engineering-knowledge-base/system-graph/operation-envelope.md
  - docs/engineering-knowledge-base/kernel/contracts.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
allowed_changes:
  - Evoluir campos do envelope com compatibilidade.
forbidden_changes:
  - Executar operacao sem trace e origem.
depends_on:
  - atlas-input
flows_to:
  - intent-routing
unlocks:
  - intent-routing
governs:
  - traceability
evidence:
  - docs/engineering-knowledge-base/kernel/contracts.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: critical
next_actions:
  - Alinhar envelope com payload de Decision Receipt v2.
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
# Operation Envelope

## Resumo

Operation Envelope e a unidade canonica que torna uma operacao rastreavel antes de qualquer decisao.

## Papel no Atlas

Ele junta input, origem, tenant, trace, anexos, limites e metadados minimos para o Kernel trabalhar com auditabilidade.

## Onde Se Encaixa

Pai: `atlas-ai-kernel-pipeline`. Recebe de `atlas-input` e alimenta `intent-routing`.

## Contratos

Entrada: input canonico. Saida: envelope com trace, source, limites, anexos e identidade operacional. Invariante: sem envelope nao ha execucao.

## Fluxo

Input vira envelope; envelope segue para roteamento de intencao e depois para contexto, policy e decisao.

## Regras para IA

IA deve exigir envelope antes de plano ou execucao. Falta de trace e bloqueio, nao detalhe opcional.

## Escopo de Implementacao

Permitido: campos de auditoria, limites e anexos. Proibido: esconder dados fora do envelope.

## Dependencias

- `atlas-input`
- `kernel/contracts`

## Evidencias

- `docs/engineering-knowledge-base/kernel/contracts.md`
- `docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md`

## Riscos

- Operacao sem origem gerar evidencia inutil.
- Envelope crescer e virar contexto inteiro.

## Exemplos

Uma conversa no Atlas Code vira envelope com obra, thread, origem desktop, anexos e limite de autonomia.

## Proximas Acoes

Definir versao do envelope usada nos endpoints do Atlas Code.
