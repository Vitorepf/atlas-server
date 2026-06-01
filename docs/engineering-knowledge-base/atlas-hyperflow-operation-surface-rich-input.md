---
id: atlas-hyperflow-operation-surface-rich-input
type: engineering_knowledge
title: Operacao Atlas Hyperflow Surface Rich Input
status: active
category: atlas-ai
priority: 90
summary: Detalhes extraidos da Operacao Atlas Hyperflow sobre Surface Plane, Rich Input compartilhado e integracao Desktop Hyperflow.
tags:
  - atlas-ai
  - hyperflow
  - desktop
  - rich-input
capabilities:
  - atlas_desktop_ai_surface_plane
  - atlas_rich_input_runtime
  - hyperflow_desktop_integration
decisions:
  - Atlas Desktop AI e surface do Hyperflow, nao decisora.
  - Rich Input e runtime compartilhado por Atlas AI, Forge e mobile quando aterrissar.
maintenance:
  - Atualize quando Desktop Surface, Rich Input ou contrato anti-regressao mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-hyperflow-operation-surface-rich-input
graph_title: Operacao Atlas Hyperflow Surface Rich Input
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-hyperflow-operation
graph_status: active
graph_source: repo
human_name: Operacao Atlas Hyperflow Surface Rich Input
canonical_name: Operacao Atlas Hyperflow Surface Rich Input
technical_name: atlas-hyperflow-operation-surface-rich-input
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-hyperflow-operation-surface-rich-input.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-hyperflow-operation-surface-rich-input.md
allowed_changes:
  - Refinar contratos de surface/rich-input preservando o Hyperflow pai.
forbidden_changes:
  - Fazer surface decidir flow localmente.
  - Duplicar runtime de rich-input por surface.
depends_on:
  - atlas-hyperflow-operation
flows_to:
  - atlas-hyperflow-operation
unlocks:
  - atlas_ai_as_primary_engineering_interface
governs:
  - atlas.hyperflow.surface_rich_input
evidence:
  - docs/engineering-knowledge-base/atlas-hyperflow-operation-surface-rich-input.md
evidence_refs:
  - symbol: AtlasDesktopHyperflowIntegrationCertificationService
  - test: AtlasDesktopHyperflowIntegrationCertificationServiceTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia esta doc antes de mexer em Surface Plane ou Rich Input do Hyperflow.
ai_usage_notes:
  - Esta doc detalha a surface; o Hyperflow pai decide objetivo e gates.
quality_gates:
  - docs-health-pass
failure_modes:
  - Surface virar decisora e driblar Router Runtime.
observability_signals:
  - docs_health_status
next_actions:
  - Manter testes anti-regressao alinhados ao contrato.
line_limit: 520
---
# Operacao Atlas Hyperflow Surface Rich Input

## Resumo

Esta doc filha guarda os detalhes de Desktop Surface, Rich Input compartilhado e integracao Hyperflow para manter `atlas-hyperflow-operation` como mapa executivo.

## Papel no Atlas

Garantir que a surface humana nao vire decisora local e que input rico seja capability compartilhada, nao duplicada por produto.

## Onde Se Encaixa

Atlas AI Surface -> Hyperflow -> Router Runtime. Rich Input alimenta a surface, mas nao decide flow.

## Contratos

- Pai: `atlas-hyperflow-operation`.
- Surface renderiza decisoes do backend.
- Rich Input e modulo compartilhado.

## Fluxo

1. Usuario envia input na Desktop Surface.
2. Rich Input normaliza anexos.
3. Backend Hyperflow/Router decide flow.
4. Desktop renderiza a decisao real.

## Regras para IA

- Nao mover decisao de flow para UI.
- Nao duplicar `attachments/` por surface.
- Nao tratar Dev/Forge/TEOS como default da Desktop AI.

## Escopo de Implementacao

Surface Plane, Rich Input compartilhado e contrato anti-regressao Desktop Hyperflow.

## Detalhes Extraidos

## Atlas Desktop AI Surface Plane

Atlas Desktop AI (`app_surface=atlas_desktop_ai`,
`surface_id=atlas_desktop_ai`, codigo em
`atlas-desktop/apps/desktop/src/surfaces/atlas-ai`) e SURFACE do Hyperflow,
nunca decisora.

Contrato anti-regressao (testes `AtlasAiDesktopHyperflowAntiRegressionTest`
no backend e `atlasAiHyperflowContract.test.ts` no desktop):

1. Default de `composerMode` e `composerTask` e `auto`. A Desktop NUNCA abre
   como `programming/dev` por default.
2. `routing_domain=auto` + `routing_task=auto` + `flow_id=auto` quando o
   operador nao escolhe dominio explicito; o backend Router Runtime decide.
3. Modos primeira-classe expostos: `auto, general, conversation,
   operational, programming, research, finance, marketing, strategy,
   personal_development, cyber, automation`.
4. Politica de runtime programming (`capability_profile=atlas_programming`,
   `permission_policy`, `engineering_run_id` etc.) so entra no payload
   quando `mode==='programming'` explicito.
5. `flowIdForMode(mode, task)` nunca retorna `programming.dev` para modos
   nao-programming. Dev/Forge/TEOS sao destinos do dominio programming, nao
   estado principal da Desktop.
6. Atlas Dev plan-only (`/ai/interactions/atlas-dev/plan`) so dispara quando
   `mode==='programming' && task in {dev, debug}`. Fallback legado
   (`AtlasDevPlanUnavailableError`) aparece como fallback, nao como caminho
   principal.
7. `/ai/interactions` retorna trace cujo `payload.atlas_ai_router` carrega
   `flow_id`, `flow_origin`, `command_intent`, `routing_reason`,
   `routing_confidence`, `handoff_payload` (com `surface_id`,
   `workspace_present`, `intent_kernel`, `compounding_memories`) e
   `alternative_flow_ids`. A Desktop renderiza essa decisao real, nao
   inferencia local.

Atlas Code (`surface_id=atlas_code`) e surface diferente — promove para
`atlas_forge` independente do prompt. Dev e Forge mantem boundary
dual-core; nenhum dos dois e o estado inicial da Atlas Desktop AI.

## Atlas Unified Rich Input Runtime

Atlas Rich Input e a capability **compartilhada** que rege ingestao de
imagens, PDFs, arquivos de texto/codigo e URLs ricas. Codigo canon em
`atlas-desktop/apps/desktop/src/surfaces/atlas-ai/attachments/` com
barrel publico em `attachments/index.ts`.

Contrato anti-duplicacao (testes
`atlasAiRichInputRuntime.test.ts`):

1. Modulo unico: imagens, PDFs, texto/codigo e URLs ricas passam pelos
   processadores canon (`processImage`, `processPdf`, `classifyUrl`,
   `fetchUrlMetadata`, `chunkedUploadAsset`, `useAtlasAiAttachments`).
2. Nenhuma surface pode shippar `attachments/` propria. O teste de
   regressao escaneia `apps/desktop/src/surfaces/*` e falha se outro
   surface duplicar a runtime.
3. Atlas Desktop AI e Atlas Code/Forge (e mobile, quando aterrissar)
   importam pelo barrel `atlas-ai/attachments` (`from '../atlas-ai/attachments'`).
   Adapter do Forge para Obras nao re-implementa o uploader — ele consome
   o mesmo hook + types e despacha o `UploadOutput` para a rota Forge
   apropriada.
4. `ATTACHMENT_LIMITS` (8 imgs, 4 PDFs, 20MB) sincronizam com as regras
   do `StoreAiInteractionRequest` no backend. Mudanca em qualquer lado
   exige update simultaneo do outro.
5. Evolucao das familias de rich-input (nova familia, novo metadata
   provider, novo limit) cai no modulo canon e beneficia automaticamente
   Atlas AI + Forge + mobile.

Out of scope (intencionalmente): provider invocation, benchmark/rivals,
TEOS continuity packs. Rich Input e capability de surface, ortogonal a
essas camadas.

## Atlas Desktop AI Hyperflow Integration Canon

Declaracoes canonicas auditaveis pela
`AtlasDesktopHyperflowIntegrationCertificationService`:

1. **Atlas Desktop AI e Surface Plane do Hyperflow.** A tela
   `surfaces/atlas-ai/*` nao decide flow nem domain: ela renderiza
   `trace.hyperflow` e `trace.hyperflow_runtime` produzidos pelo backend.
2. **Hyperflow/Router Runtime e autoridade de decisao.**
   `AtlasHyperflowEntryService` roda PRIMEIRO em `/ai/interactions` (antes do
   legado `AtlasAiRouterService`) e emite `atlas.ai.hyperflow_runtime.v1`
   com intent, domain, flow, runtime_mode, policy/evidence flags, dispatch
   status e dois receipts canonicos.
3. **Atlas Rich Input e capability compartilhada.** Imagens, PDFs,
   texto/codigo e URLs ricas passam por
   `atlas-desktop/apps/desktop/src/surfaces/atlas-ai/attachments/`
   (barrel publico). Backend valida via `rich_input.*` em
   `StoreAiInteractionRequest`. Limites (`ATTACHMENT_LIMITS`) sincronizados
   nos dois lados.
4. **Forge / Dev / mobile NAO duplicam input.** Forge adapter para Obras
   consome o mesmo barrel; Dev recebe o payload ja enriquecido pelo
   gateway; mobile (quando aterrissar) idem. Surface paralela com
   `attachments/` propria e regressao bloqueante.
5. **TEOS fica abaixo do dominio programming e fora desta entrega.**
   Continuity packs / freshness / replay sao camadas do TEOS-I1 sob
   `programming` (cita `atlas-temporal-engineering-operating-system.md`);
   nao entram em `/ai/interactions` nem na Surface Plane Atlas AI nesta
   missao.

Anti-regressao final: o default da Atlas Desktop AI e `auto/auto`. Qualquer
mudanca que reverter `defaultTaskForMode('auto') !== 'auto'` ou
`flowIdForMode('auto','auto') !== 'auto'` quebra a cert
`no_legacy_programming_dev_default` e bloqueia o gate.

## Dependencias

- `atlas-hyperflow-operation`.
- Router Runtime Enterprise Upgrade.

## Evidencias

- Esta doc filha.
- Testes anti-regressao citados no detalhe extraido.

## Riscos

- UI virar decisora.
- Rich Input duplicado criar divergencia de comportamento.

## Exemplos

Desktop abre em auto/auto; o backend decide se vira explain, research, debug, dev ou forge.

## Proximas Acoes

- Manter surface, rich-input e testes em sincronia com o Hyperflow.
