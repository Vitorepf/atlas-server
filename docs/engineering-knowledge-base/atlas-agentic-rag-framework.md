---
id: atlas-agentic-rag-framework
type: engineering_knowledge
title: Atlas Agentic RAG Framework
status: building
implementation_state: building_read_only_cross_domain_plan_gap_critic_sufficiency_gate
blocker: AARF possui runtime read-only cross-domain sobre AHRI; ainda falta virar mandatory gate em todos os flows e persistir receipts.
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
  - app/Services/Ai/Context/AtlasAgenticRagFrameworkService.php
  - app/Console/Commands/AtlasAgenticRagFrameworkCommand.php
  - tests/Feature/Ai/Context/AgenticRagFrameworkTest.php
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
graph_status: building
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
  - app/Services/Ai/Context/AtlasAgenticRagFrameworkService.php
  - app/Console/Commands/AtlasAgenticRagFrameworkCommand.php
  - tests/Feature/Ai/Context/AgenticRagFrameworkTest.php
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test tests/Feature/Ai/Context/AgenticRagFrameworkTest.php"
  - "php artisan atlas:context:agentic-rag --query='corrigir bug no repo com teste falhando' --task-type=debug --domain=developer --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Integrar AARF como gate mandatory nos flows AUCRI/Atlas AI quando ACRS e ACFQ existirem.
  - Persistir receipts de plano/critic quando ACOP estiver pronto.
---

# Atlas Agentic RAG Framework

## Resumo

AARF e o bloco 3 da AUCRI. Ele transforma retrieval em processo agentico:
define fontes obrigatorias, itera busca, critica lacunas e decide se o contexto
permite execucao.

## Papel no Atlas

Impedir que flows trabalhem com contexto incompleto. Programming ja tem uma
base forte; AARF agora generaliza a decisao inicial para outros dominios via
AHRI read-only, sem chamar provider e sem gravar estado.

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
5. Rodar segunda busca read-only quando so faltarem fontes opcionais.
6. Passar/degradar/bloquear.

## Regras para IA

- Nao fingir fonte obrigatoria.
- Nao degradar quando risco exige fail-closed.
- Nao pular critic.

## Escopo de Implementacao

Runtime atual:

- `AtlasAgenticRagFrameworkService::plan()` monta required/optional sources,
  chama AHRI, roda critic e sufficiency gate.
- `atlas:context:agentic-rag` expoe a surface CLI com `--json`.
- `AgenticRagFrameworkTest` cobre pass/degraded/blocked e comando.

Ainda falta integrar como mandatory gate de execucao real depois de ACRS/ACFQ.

## Dependencias

AHRI, ACIE, APCR, Memory, Evidence.

## Evidencias

Planos com required sources, missing sources, gap critic e sufficiency gate.
Evidencia local atual: comando `atlas:context:agentic-rag` e testes focados.

## Riscos

Bloqueio excessivo, fonte obrigatoria mal definida, critic fraco, segunda
busca read-only insuficiente sem ACRS/ACFQ.

## Exemplos

Finance exige fonte recente e autoridade; programming exige code/docs/tests.

## Proximas Acoes

1. Ligar AARF ao ACRS para ranking auditavel.
2. Ligar AARF ao ACFQ para freshness/quality fail-closed.
3. Promover de read-only planning para gate padrao quando receipts ACOP existirem.
