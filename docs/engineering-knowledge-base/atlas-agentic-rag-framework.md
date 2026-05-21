---
id: atlas-agentic-rag-framework
type: engineering_knowledge
title: Atlas Agentic RAG Framework
status: planned
implementation_state: planned_child_architecture_not_current_runtime
blocker: AARF existe forte em Programming, mas ainda nao como framework cross-domain AUCRI.
category: intelligence-runtime
priority: 99
summary: Doc filha AUCRI para RAG agentico cross-domain: decompor objetivo, planejar fontes obrigatorias, iterar busca, criticar lacunas e bloquear execucao sem contexto suficiente.
tags: [atlas-ai, aucri, aarf, agentic-rag, retrieval-planner]
capabilities: [agentic_rag, gap_critic, source_plan, sufficiency_gate]
decisions:
  - AARF deve reutilizar o Programming Agentic RAG, nao reimplementar em paralelo.
  - Agentic RAG profissional exige plano, iteracao, critic, receipts e gate.
maintenance:
  - Atualizar quando required sources por dominio mudarem.
related_paths:
  - docs/engineering-knowledge-base/domains/programming-agentic-rag-professional-spec.md
  - docs/engineering-knowledge-base/domains/programming-professional-rag-operating-standard.md
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - app/Services/Ai/Programming/ProgrammingRetrievalPlanner.php
doc_schema: atlas_canonical_module_doc.v1
macro_layer: true
product_name: Atlas Agentic RAG Framework
runtime_acronym: AARF
internal_product_name: Atlas Retrieval Planner
technical_runtime: AtlasAgenticRagFrameworkService
graph_id: atlas-agentic-rag-framework
graph_title: Atlas Agentic RAG Framework
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-unified-context-retrieval-intelligence
graph_status: planned
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-agentic-rag-framework.md
allowed_changes:
  - Criar planners por dominio e critic reutilizavel.
forbidden_changes:
  - Chamar busca unica de Agentic RAG.
  - Remover fail-closed de fontes obrigatorias.
depends_on: [atlas-hybrid-retrieval-infrastructure, atlas-context-intelligence-engine]
flows_to: [atlas-context-ranking-system, atlas-context-freshness-quality-gate]
unlocks: [cross_domain_agentic_rag, mandatory_source_planning]
governs: [agentic_rag, required_sources]
evidence:
  - docs/engineering-knowledge-base/atlas-agentic-rag-framework.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Extrair padroes do ProgrammingRetrievalPlanner para framework cross-domain.
---

# Atlas Agentic RAG Framework

## Resumo

AARF e o bloco 3 da AUCRI. Ele transforma retrieval em processo agentico:
define fontes obrigatorias, itera busca, critica lacunas e decide se o contexto
permite execucao.

## Papel no Atlas

Impedir que flows trabalhem com contexto incompleto. Programming ja tem uma
base forte; AARF generaliza para outros dominios.

## Onde Se Encaixa

```text
objective -> source plan -> AHRI retrieval -> gap critic -> sufficiency
```

## Contratos

- `atlas.aucri.agentic_rag_plan.v1`
- `atlas.aucri.required_sources.v1`
- `atlas.aucri.gap_critic_report.v1`
- `atlas.aucri.context_sufficiency_gate.v1`

## Fluxo

1. Classificar domain/flow/risk.
2. Gerar required sources.
3. Chamar AHRI.
4. Avaliar misses/noise/contradicoes.
5. Rodar segunda busca se necessario.
6. Passar/degradar/bloquear.

## Regras para IA

- Nao fingir fonte obrigatoria.
- Nao degradar quando risco exige fail-closed.
- Nao pular critic.

## Escopo de Implementacao

Planners por dominio, shared critic, tests de fail-closed e integração com
APCR/ACIE.

## Dependencias

AHRI, ACIE, APCR, Memory, Evidence.

## Evidencias

Planos com required sources, missing sources e receipts.

## Riscos

Bloqueio excessivo, fonte obrigatoria mal definida, critic fraco.

## Exemplos

Finance exige fonte recente e autoridade; programming exige code/docs/tests.

## Proximas Acoes

1. Mapear required sources por dominio.
2. Criar service cross-domain.
3. Manter Programming como referencia inicial.
