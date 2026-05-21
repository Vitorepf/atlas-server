---
id: context-builder
type: engineering_knowledge
title: Context Builder
status: active
category: kernel
priority: 91
summary: Monta contexto curado usando Open Brain, Memory Core, Engineering KB e inteligencia contextual.
tags: [atlas, kernel, context]
capabilities: [context_builder, open_brain, memory_context]
decisions:
  - Contexto deve ser compilado e rastreavel; nao deve ser despejo bruto de docs.
maintenance:
  - Atualizar quando fontes ou regras de contexto mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: context-builder
graph_title: Context Builder
graph_world: atlas
graph_layer: gear
graph_kind: step
graph_parent: atlas-ai-kernel-pipeline
graph_status: active
graph_source: repo
human_name: Context Builder
canonical_name: Context Builder
technical_name: context-builder
cartography_type: step
canonical_source: docs/engineering-knowledge-base/system-graph/context-builder.md
owner: atlas-kernel
repo_paths:
  - docs/engineering-knowledge-base/system-graph/context-builder.md
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
allowed_changes:
  - Ajustar fontes e regras de selecao de contexto.
forbidden_changes:
  - Injetar contexto sem origem ou sem limites.
depends_on:
  - domain-profile-flow
  - hks
flows_to:
  - policy-profile
unlocks:
  - policy-profile
governs:
  - context-pack
evidence:
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: critical
next_actions:
  - Expor context pack real no Atlas Code para auditoria humana.
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
# Context Builder

## Resumo

Context Builder compila o contexto que a IA deve receber para executar com seguranca e qualidade.

## Papel no Atlas

Ele substitui busca improvisada por contexto curado, rastreavel e adequado ao dominio e a Obra.

## Onde Se Encaixa

Pai: `atlas-ai-kernel-pipeline`. Recebe de `domain-profile-flow`, consulta `hks` e alimenta `policy-profile`.

## Contratos

Entrada: dominio, Obra, intent e fontes autorizadas. Saida: context pack com fontes, resumo e limites. Invariante: toda fonte deve ser rastreavel.

## Fluxo

Seleciona docs, memoria e contexto relevante. Compila uma versao consumivel antes da policy e decisao.

## Regras para IA

IA deve citar ou preservar referencias de contexto quando tomar decisao de implementacao.

## Escopo de Implementacao

Permitido: ranking, resumo e compactacao. Proibido: esconder origem ou misturar fonte nao autorizada.

## Dependencias

- `domain-profile-flow`
- `hks`
- `open-brain-context-injection`

## Evidencias

- `docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md`
- `docs/engineering-knowledge-base/open-brain-context-injection.md`

## Riscos

- Contexto demais reduzir precisao.
- Contexto errado gerar implementacao coerente mas falsa.

## Exemplos

Uma Obra de refatoracao deve receber docs canonicos do modulo, caminhos reais e regras de gates.

## Proximas Acoes

Mostrar context pack no painel de spec do Atlas Code.
