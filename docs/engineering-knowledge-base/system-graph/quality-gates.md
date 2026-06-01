---
id: quality-gates
type: engineering_knowledge
title: Quality Gates
status: active
category: kernel
priority: 97
summary: Gates que bloqueiam ou promovem execucao com base em testes, seguranca, regressao, SLO, visual QA e evidencia.
tags:
  - atlas
  - gates
  - qa
  - evidence
capabilities:
  - quality_gates
  - qa_evidence
  - promotion_gate
decisions:
  - Gates P0/P1 bloqueiam promocao quando falham.
  - Gate sem evidencia persistida nao deve promover estado.
maintenance:
  - Atualizar quando surgirem novos gates, thresholds ou taxonomias de falha.
related_paths:
  - docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md
  - docs/engineering-knowledge-base/engineering-blueprint-contracts.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: quality-gates
graph_title: Quality Gates
graph_world: atlas
graph_layer: gear
graph_kind: step
graph_parent: atlas-ai-kernel-pipeline
graph_status: active
graph_source: repo
human_name: Quality Gates
canonical_name: Quality Gates
technical_name: quality-gates
cartography_type: step
canonical_source: docs/engineering-knowledge-base/system-graph/quality-gates.md
owner: atlas-kernel
repo_paths:
  - docs/engineering-knowledge-base/system-graph/quality-gates.md
  - docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md
allowed_changes:
  - Adicionar gates e thresholds com criterio claro.
  - Ajustar severidade quando houver evidencia de falsos positivos/negativos.
forbidden_changes:
  - Rebaixar gate critico para warning sem justificativa.
  - Promover execucao sem evidencia persistida.
depends_on:
  - runtime-executor
flows_to:
  - repair-escalation
  - evidence-ledger
unlocks:
  - learning-proposals
governs:
  - qa
  - promotion
evidence:
  - docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md
evidence_refs:
  - symbol: AtlasQualityGatesService
  - command: atlas:aaeos:quality-gates
  - test: AtlasQualityGatesTest
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: critical
next_actions:
  - Exibir resultado de gates no Atlas Code com bloqueio real para falhas P0/P1.
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
# Quality Gates

## Resumo

Quality Gates verificam se uma execucao pode continuar, reparar ou promover estado. Eles transformam qualidade em contrato, nao em opiniao.

## Papel no Atlas

Eles protegem o Atlas contra mudancas sem teste, regressao visual, falha de seguranca, drift e evidencia insuficiente.

## Onde Se Encaixa

Pai: `atlas-ai-kernel-pipeline`. Recebe resultado do `runtime-executor` e alimenta `repair-escalation` e `evidence-ledger`.

## Contratos

Entrada: artefatos de execucao, comandos, logs, diffs e contexto do receipt. Saida: pass, fail, blocked, repair_required ou evidence_required.

## Fluxo

Runtime executa. Gates avaliam. Falha vai para Repair/Escalation; sucesso vira Evidence Ledger.

## Regras para IA

IA nao pode declarar pronto quando gate obrigatorio falhou ou nao rodou. Deve registrar impossibilidade quando nao conseguir executar.

## Escopo de Implementacao

Permitido: testes, scans, visual QA, SLO e classificacao de falhas. Proibido: bypass silencioso.

## Dependencias

- `runtime-executor`
- `engineering-blueprint-quality-gates`

## Evidencias

- `docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md`

## Riscos

- Gate virar checklist manual sem enforcement.
- Falso positivo bloquear trabalho valido.
- Falha critica ser tratada como aviso.

## Exemplos

Regressao visual em componente critico deve bloquear promocao ate repair ou aprovacao humana explicita.

## Proximas Acoes

Conectar painel Verify/Evidence do Atlas Code aos gates reais do backend.
