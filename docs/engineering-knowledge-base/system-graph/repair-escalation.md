---
id: repair-escalation
type: engineering_knowledge
title: Repair Escalation
status: active
category: kernel
priority: 89
summary: Corrige, reexecuta, pede review ou bloqueia quando gates, runtime ou evidencia falham.
tags: [atlas, kernel, repair, escalation]
capabilities: [repair_loop, escalation]
decisions:
  - Repair retorna por policy, receipt e decide; nao reexecuta por impulso.
maintenance:
  - Atualizar quando novos tipos de falha ou reparo forem suportados.
related_paths:
  - docs/engineering-knowledge-base/domains/programming-repair-contract.md
  - docs/engineering-knowledge-base/kernel/failure-domain-taxonomy.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: repair-escalation
graph_title: Repair Escalation
graph_world: atlas
graph_layer: gear
graph_kind: step
graph_parent: atlas-ai-kernel-pipeline
graph_status: active
graph_source: repo
owner: atlas-kernel
repo_paths:
  - docs/engineering-knowledge-base/system-graph/repair-escalation.md
  - docs/engineering-knowledge-base/domains/programming-repair-contract.md
  - docs/engineering-knowledge-base/kernel/failure-domain-taxonomy.md
allowed_changes:
  - Adicionar tipos de falha e rotas de reparo.
forbidden_changes:
  - Reexecutar falha critica sem receipt ou escalonamento.
depends_on:
  - quality-gates
  - runtime-executor
flows_to:
  - evidence-ledger
unlocks:
  - evidence-ledger
governs:
  - repair-loop
evidence:
  - docs/engineering-knowledge-base/domains/programming-repair-contract.md
  - docs/engineering-knowledge-base/kernel/failure-domain-taxonomy.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: critical
next_actions:
  - Exibir repair attempts reais no painel Verify do Atlas Code.
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
# Repair Escalation

## Resumo

Repair Escalation decide como o Atlas reage quando execucao, gates ou evidencia falham.

## Papel no Atlas

Ele evita loops cegos. Falhas viram reparo controlado, reexecucao, pedido de review humano ou bloqueio.

## Onde Se Encaixa

Pai: `atlas-ai-kernel-pipeline`. Recebe de `quality-gates` e `runtime-executor`, alimenta `evidence-ledger`.

## Contratos

Entrada: falha, severidade, evidencia e policy. Saida: acao de reparo, escalonamento ou bloqueio. Invariante: falha critica nao passa silenciosa.

## Fluxo

Gate falha. Repair classifica, tenta correcoes permitidas ou escala para review. Resultado vira evidencia.

## Regras para IA

IA deve tratar reparo como nova decisao auditavel quando alterar escopo, risco ou autonomia.

## Escopo de Implementacao

Permitido: classificacao de falhas e tentativas controladas. Proibido: loop infinito ou bypass de gate.

## Dependencias

- `quality-gates`
- `runtime-executor`
- `programming-repair-contract`

## Evidencias

- `docs/engineering-knowledge-base/domains/programming-repair-contract.md`
- `docs/engineering-knowledge-base/kernel/failure-domain-taxonomy.md`

## Riscos

- Reparar sintomas e esconder causa.
- Escalar tarde demais.

## Exemplos

Falha visual regressiva pode pedir screenshot before/after e review humano.

## Proximas Acoes

Persistir repair attempts como eventos visiveis na Obra.
