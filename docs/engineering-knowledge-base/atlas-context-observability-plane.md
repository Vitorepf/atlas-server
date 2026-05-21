---
id: atlas-context-observability-plane
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Context Observability Plane
status: building
implementation_state: runtime_surface_read_model_ready
blocker: ACOP possui service read-only, command JSON, redaction tests e snapshot local; ainda falta API/UI e historico persistido.
category: context_retrieval_intelligence
priority: 93
summary: "Plano de observabilidade para retrieval/contexto: traces, fontes, misses, ruido, qualidade, custo, freshness e blockers."
tags: [atlas-ai, aucri, acop, observability, context-trace, control-plane]
capabilities: [context_observability, source_health, retrieval_trace, blocker_report]
decisions:
  - Observabilidade de contexto nao pode expor prompt, resposta ou texto cru.
  - Toda metrica precisa apontar para receipt ou trace auditavel.
  - ACOP e read model; nao decide retrieval diretamente.
maintenance:
  - Atualizar antes de mudar snapshots, metrics, redaction ou control-plane integration.
product_name: Atlas Context Observability Plane
runtime_acronym: ACOP
internal_product_name: Atlas Context Control Room
technical_runtime: AtlasContextObservabilityPlaneService
macro_layer: true
graph_id: atlas-context-observability-plane
graph_title: Atlas Context Observability Plane
graph_world: atlas
graph_parent: atlas-unified-context-retrieval-intelligence
graph_layer: module
graph_kind: module
graph_status: planned
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - docs/engineering-knowledge-base/atlas-retrieval-cost-latency-governor.md
  - app/Services/Ai/Context/AtlasContextObservabilityPlaneService.php
  - app/Console/Commands/AtlasContextObservabilityPlaneCommand.php
  - tests/Feature/Ai/Context/ContextObservabilityPlaneTest.php
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
allowed_changes:
  - Criar read models e comandos JSON para contexto.
  - Agregar metricas por flow, dominio, source e status.
forbidden_changes:
  - Expor prompt, resposta, PII ou texto cru sem redacao.
  - Criar dashboard sem fonte auditavel.
depends_on:
  - atlas-retrieval-cost-latency-governor
  - atlas-retrieval-feedback-loop
flows_to:
  - atlas-retrieval-privacy-trust-layer
unlocks: [context_audit, source_health_control_plane]
governs: [context_observability, retrieval_trace]
evidence:
  - docs/engineering-knowledge-base/atlas-context-observability-plane.md
  - app/Services/Ai/Context/AtlasContextObservabilityPlaneService.php
  - app/Console/Commands/AtlasContextObservabilityPlaneCommand.php
  - tests/Feature/Ai/Context/ContextObservabilityPlaneTest.php
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:context:observability --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Conectar snapshot ACOP ao Control Plane/API depois de ARPTL.
---

# Atlas Context Observability Plane

## Resumo

ACOP e a visibilidade operacional de AUCRI. Ele mostra por que um contexto foi
montado, quais fontes entraram, quais faltaram, onde houve ruido, quanto custou
e qual foi o efeito no resultado.

## Papel no Atlas

Sem ACOP, AUCRI vira caixa preta. Com ACOP, qualquer IA ou operador consegue
auditar retrieval por flow, dominio, surface, usuario, source e outcome.

## Onde Se Encaixa

Recebe eventos de AHRI, AARF, ACRS, ACFQ, ARFL, AREBA e ARCLG. Alimenta
Control Plane, readiness, certification e investigacoes de qualidade.

## Contratos

- `atlas.aucri.context_observability_plane.v1`
- `atlas.aucri.context_observability_snapshot.v1`
- `atlas.aucri.context_trace.v1`
- `atlas.aucri.context_source_health.v1`
- `atlas.aucri.context_blocker.v1`

Campos minimos: `trace_id`, `flow_id`, `source_type`, `source_ref_hash`,
`retrieval_stage`, `included`, `used`, `missed`, `noise`, `freshness`,
`authority`, `cost`, `latency`, `blockers`, `snapshot_hash`.

## Fluxo

1. Capturar receipts de cada etapa de contexto.
2. Normalizar sem texto cru.
3. Agregar por janela, flow, dominio e source.
4. Gerar blockers e warnings.
5. Expor command/API JSON para UI futura.
6. Linkar com certification.

## Regras para IA

- Nao diagnosticar problema de contexto sem consultar ACOP quando existir.
- Nao copiar conteudo sensivel para snapshot.
- Nao misturar metricas de qualidade com claim externo.
- Nao inferir uso de fonte sem receipt.

## Escopo de Implementacao

Implementado service read-only, command `atlas:context:observability --json`,
hash deterministico e tests de redacao/agregacao. Integracao visual/API entra
depois de ARPTL para herdar redaction/trust policy.

## Dependencias

Depende de receipts consistentes nos blocos anteriores e de ARPTL para redacao
segura.

## Evidencias

Evidencia atual: snapshot JSON, blockers, source health, trace refs, hash
deterministico e teste que prova que texto cru nao vaza.

## Riscos

- Observabilidade virar log bruto perigoso.
- Dashboard bonito sem capacidade de auditoria.
- Falta de FK direta entre trace e fonte reduzir precisao.
- Metricas agregadas esconderem falha critica.

## Exemplos

ACOP deve mostrar que uma resposta de Research usou 7 fontes, ignorou 2 por
stale, perdeu 1 fonte obrigatoria e degradou para warn.

## Proximas Acoes

1. Adicionar endpoint/API e bloco no Control Plane.
2. Persistir historico agregado quando ARPTL estiver ativo.
3. Integrar redaction policy completa via ARPTL.
4. Conectar source health a AKIF.
