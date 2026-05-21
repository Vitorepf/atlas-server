---
id: learning-proposals
type: engineering_knowledge
title: Learning Proposals
status: active
category: kernel
priority: 87
summary: Transforma memoria, metricas, AP-99, qualidade e outcome em propostas de melhoria revisaveis.
tags: [atlas, kernel, learning, proposals]
capabilities: [learning_proposals, self_improvement]
decisions:
  - Learning nao altera comportamento critico sem proposta, review e politica.
maintenance:
  - Atualizar quando sinais de aprendizado ou curadoria evoluirem.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/research-self-improvement/continuous-self-improvement-loop.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: learning-proposals
graph_title: Learning Proposals
graph_world: atlas
graph_layer: gear
graph_kind: step
graph_parent: atlas-ai-kernel-pipeline
graph_status: active
graph_source: repo
human_name: Learning Proposals
canonical_name: Learning Proposals
technical_name: learning-proposals
cartography_type: step
canonical_source: docs/engineering-knowledge-base/system-graph/learning-proposals.md
owner: atlas-kernel
repo_paths:
  - docs/engineering-knowledge-base/system-graph/learning-proposals.md
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/research-self-improvement/continuous-self-improvement-loop.md
allowed_changes:
  - Adicionar sinais e criterios de proposta.
forbidden_changes:
  - Promover aprendizado automatico sem review quando houver impacto critico.
depends_on:
  - evidence-ledger
  - evidence-loop
flows_to:
  - output-renderer
unlocks:
  - output-renderer
governs:
  - self-improvement
evidence:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/research-self-improvement/continuous-self-improvement-loop.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Mostrar learning proposals reais no Atlas Code sem autoaplicar mudancas criticas.
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
# Learning Proposals

## Resumo

Learning Proposals transforma resultados e sinais de execucao em propostas de melhoria.

## Papel no Atlas

Ele cria aprendizado auditavel sem permitir que o sistema mude comportamento critico sem governanca.

## Onde Se Encaixa

Pai: `atlas-ai-kernel-pipeline`. Depende de `evidence-ledger` e `evidence-loop`, alimenta `output-renderer`.

## Contratos

Entrada: eventos, metricas, outcomes e erros. Saida: proposta com justificativa, risco e acao sugerida. Invariante: proposta nao e aplicacao automatica.

## Fluxo

Evidence Ledger registra. Learning avalia padroes e cria proposta. Output Renderer apresenta ao humano ou sistema.

## Regras para IA

IA deve separar sugestao, decisao e aplicacao. Aprendizado sem evidencia nao deve virar canon.

## Escopo de Implementacao

Permitido: propostas, rankings e curadoria. Proibido: mudar policy critica sem review.

## Dependencias

- `evidence-ledger`
- `evidence-loop`
- `continuous-self-improvement-loop`

## Evidencias

- `docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md`
- `docs/engineering-knowledge-base/research-self-improvement/continuous-self-improvement-loop.md`

## Riscos

- Aprendizado virar mutacao silenciosa.
- Proposta baseada em sinal fraco.

## Exemplos

Se Sonnet superar Opus em uma classe de tarefa, o Atlas pode propor ajuste de default com benchmark.

## Proximas Acoes

Conectar propostas ao painel Learn do Atlas Code.
