---
id: atlas-context-freshness-quality-gate
type: engineering_knowledge
title: Atlas Context Freshness Quality Gate
status: building
implementation_state: building_read_only_gate_runtime
blocker: ACFQ possui gate read-only sobre ACRS; ainda falta persistir receipts e virar mandatory gate no context pack final.
category: intelligence-runtime
priority: 98
summary: Doc filha AUCRI para gate de freshness e qualidade: bloqueia contexto velho, contraditorio, sem autoridade, sem fonte obrigatoria ou com privacidade inadequada.
tags: [atlas-ai, aucri, acfq, freshness, quality-gate]
capabilities: [freshness_gate, context_quality, contradiction_detection, required_source_gate]
decisions:
  - Contexto stale deve bloquear ou exigir nova retrieval pass em tarefas de risco.
maintenance:
  - Atualizar quando policies de freshness ou autoridade mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - app/Services/Ai/Context/AtlasContextFreshnessQualityGateService.php
  - app/Console/Commands/AtlasContextFreshnessQualityGateCommand.php
  - tests/Feature/Ai/Context/ContextFreshnessQualityGateTest.php
  - app/Services/Ai/LongHorizon/Gate/LongHorizonContextFreshnessGate.php
  - app/Services/Ai/LongHorizon/TimeAwareWorldModelService.php
doc_schema: atlas_canonical_module_doc.v1
macro_layer: true
product_name: Atlas Context Freshness & Quality Gate
runtime_acronym: ACFQ
internal_product_name: Atlas Freshness Gate
technical_runtime: AtlasContextFreshnessQualityGateService
graph_id: atlas-context-freshness-quality-gate
graph_title: Atlas Context Freshness Quality Gate
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-unified-context-retrieval-intelligence
graph_status: building
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-context-freshness-quality-gate.md
allowed_changes:
  - Definir policies por dominio e risco.
forbidden_changes:
  - Permitir contexto expirado em decisao sensivel sem review.
depends_on: [atlas-context-ranking-system]
flows_to: [atlas-retrieval-feedback-loop]
unlocks: [fresh_context_execution, stale_context_blocking]
governs: [context_freshness, context_quality_gate]
evidence:
  - docs/engineering-knowledge-base/atlas-context-freshness-quality-gate.md
  - app/Services/Ai/Context/AtlasContextFreshnessQualityGateService.php
  - app/Console/Commands/AtlasContextFreshnessQualityGateCommand.php
  - tests/Feature/Ai/Context/ContextFreshnessQualityGateTest.php
required_tests:
  - "php artisan test tests/Feature/Ai/Context/ContextFreshnessQualityGateTest.php"
  - "php artisan atlas:context:freshness-quality --query='debug repo with tests' --task-type=debug --domain=developer --json"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Persistir freshness quality receipts quando ACCR/ACOP estiverem prontos.
---

# Atlas Context Freshness Quality Gate

## Resumo

ACFQ e o bloco 5 da AUCRI. Ele valida se o contexto ranqueado por ACRS e
atual, confiavel, autorizado e suficiente antes de execucao.

## Papel no Atlas

Evitar que o Atlas use memoria antiga, doc obsoleta, edge superseded ou fonte
sem autoridade.

## Onde Se Encaixa

```text
AHRI -> AARF -> ACRS -> ACFQ -> context pack final
```

## Contratos

- `atlas.aucri.context_freshness_quality_gate.v1`
- `atlas.aucri.freshness_quality_report.v1`
- `atlas.aucri.context_quality_gate.v1`
- `atlas.aucri.contradiction_report.v1`

## Fluxo

1. Executar ACRS e receber refs selecionadas.
2. Verificar freshness, autoridade, score e provider safety.
3. Bloquear contradicao explicita ou fonte obrigatoria ausente.
4. Escalar warning para block em risco alto.
5. Emitir pass, degraded/refresh ou blocked.

## Regras para IA

- Nao transformar unknown freshness em current.
- Nao ignorar contradiction.
- Nao desbloquear high risk sem evidence.

## Escopo de Implementacao

Runtime read-only `AtlasContextFreshnessQualityGateService` e comando
`atlas:context:freshness-quality`. Persistencia de receipts, ACOP e context pack
mandatory ficam para blocos posteriores.

## Dependencias

ACRS, TEOS, TimeAwareWorldModel, APCR/ACIE.

## Evidencias

Evidencias atuais:

- service: `AtlasContextFreshnessQualityGateService`;
- command: `atlas:context:freshness-quality`;
- tests: `ContextFreshnessQualityGateTest`;
- schemas: gate, freshness report, quality gate e contradiction report.

## Riscos

Bloqueio demais, policy frouxa, datas inventadas, warning tratado como pass.

## Exemplos

Debug com fontes exigidas passa. Ranking com fonte obrigatoria cortada por budget
bloqueia. Contradicao explicita bloqueia sem vazar texto cru.

## Proximas Acoes

1. Persistir receipt ACFQ quando ACOP/ACCR existirem.
2. Integrar stale/superseded edges.
