---
id: surface-domain-catalog-integration-plan
type: engineering_knowledge
title: Surface Domain Catalog Integration Plan
category: engineering
status: draft
priority: 70
summary: Mapa e plano incremental para fazer CLI, API, app, mobile e MCP consumirem o Atlas AI domain catalog sem duplicar contratos centrais.
tags:
  - atlas-ai
  - surface
  - domain-catalog
  - integration-plan
capabilities:
  - surface_domain_catalog_integration
  - domain_catalog_surface_parity
decisions:
  - Este plano e draft e nao deve competir com o Domain Catalog Service ou Canonical Architecture Index.
  - Surfaces devem consumir o catalogo canonico de dominios e flows sem inventar listas locais divergentes.
maintenance:
  - Promover partes deste plano para specs canonicas quando forem implementadas.
  - Verificar CLI, API, app, mobile e MCP antes de marcar como implemented.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/domains/README.md
  - app/Services/Ai/Kernel/Domain/AtlasAiDomainCatalogService.php
  - app/Console/Commands/AtlasAiDomainsCommand.php
---

# Surface Domain Catalog Integration Plan

## Objetivo

Fazer as surfaces do Atlas exibirem e usarem o mesmo catalogo canonico de dominios e flows publicado por `AtlasAiDomainCatalogService`, `atlas:ai:domains` e `GET /ai/domains`, sem alterar contratos centrais nesta fase.

Este plano e deliberadamente surface-first: mapeia consumo atual, duplicacoes e PRs pequenos para integrar escolha de domain/flow, maturity/onboarding, executor preference, safety/autonomy e capability boundaries.

## Fonte Canonica Atual

- Registry: `App\Services\Ai\AtlasDomainProfileRegistry`.
- Catalog read model: `App\Services\Ai\Kernel\Domain\AtlasAiDomainCatalogService`.
- CLI: `atlas:ai:domains`.
- API: `GET /ai/domains`.
- Validator: `atlas:ai:architecture-validate`.
- Onboarding scorecard: `AtlasDomainOnboardingScorecard`.
- Capability boundary: `AtlasCapabilityRegistry` + `config/atlas_ai.php`.

Campos ja expostos para surfaces:

- `domains[].id`, `label`, `default_flow`, `orchestrator`, `orchestrator_maturity`, `runtime_family`.
- `domains[].autonomy_default`, `background_allowed`, `flow_count`, `onboarding`.
- `flows[].id`, `domain_id`, `label`, `runtime`, `orchestrator`, `orchestrator_maturity`.
- `flows[].autonomy`, `background_allowed`, `destructive_requires_approval`, `executor_preference`.
- `summary.ready_domains`, `summary.executable_incomplete_domains`.
- `validation.valid/errors/warnings`.

## Surface Map

| Surface | Estado atual | O que deveria consumir do catalogo |
| --- | --- | --- |
| CLI catalog | `atlas:ai:domains` ja le `AtlasAiDomainCatalogService` e mostra dominios, flows, maturity, runtime, autonomy e onboarding. JSON e a melhor fonte para scripts. | Manter como read model operacional. Proximo passo: adicionar modo compacto por domain/flow e labels de safety/executor, sem mudar contrato. |
| CLI chat/dev/decide | `atlas:ai:chat`, `atlas dev`, `atlas forge`, `atlas:ai:decide` usam `mode`, `programming_profile`, `atlas_workflow_mode`, policy profiles e `AtlasProgrammingOrchestrator`. | Resolver `--domain`/`--flow` para profile canonico antes de montar payload. Quando o modo for `dev/debug/review`, preferir flow `programming.*` em vez de inferir por strings locais. |
| API catalog | `GET /ai/domains` ja expoe o catalogo com filtros `domain`, `flow`, `maturity`. | Ser a fonte principal de app/mobile para picker, badges e readiness. |
| API interaction | `POST /ai/interactions` aceita `payload` generico, `agent_slug`, provider e source, mas nao valida explicitamente domain/flow no request. | Receber `payload.domain_id` e `payload.flow_id` vindos do catalogo; opcionalmente previewar policy via `/ai/policies/preview` antes de enfileirar. |
| App settings | `atlas-app/components/sheets/SettingsSheet.tsx` ja consome `/ai/policies/profiles`, mostra flows, executor, autonomy, approval, background, gates, context/memory/skill/tool policy. | Reusar `GET /ai/domains` como read model para readiness/onboarding e deixar `/ai/policies/profiles` para edicao/policy preview. |
| App AI routing sheet | `atlas-app/components/console/StatusRouting.tsx` e `RoutingSheet.tsx` hardcodam modos, tarefas, dominios e executors. | Popular domain/flow picker a partir de `/ai/domains`. Mapear modo visual para flow canonico: `programming -> programming.dev|review|repair`, `operational -> operations.diagnostic`, `general -> general.answer`; expor `self_improvement.*` como ready quando a surface suportar seus controles, e manter `marketing.*` como scaffold/catalog-ready ate runtime/orchestrator proprio. |
| Mobile gateway/thread | `MobileThreadController` usa defaults read-only (`mobile_operational_read`, `permission_mode=read`). `DiscussionBootstrapper` promove discussao com `atlas_full_access`, `permission_mode=danger`, `operational_diagnostic`. | Trocar defaults por flow canonico (`operations.diagnostic` para discussao operacional; `programming.*` somente quando aprovado). Mostrar safety/autonomy antes de elevar runtime. |
| MCP/Open Brain | `AtlasOpenBrainMcpService` declara tools diretamente e expoe `atlas_capabilities`, mas nao publica domain catalog nem usa flow readiness para tool availability. | Adicionar tool read-only futuro `atlas_domain_catalog` ou incluir resumo de domains em `atlas_capabilities`. Tools write/execution devem declarar domain/flow permitido e capability registry correspondente. |

## Duplicacoes e Hardcodes Encontrados

1. App routing domain list hardcoded:
   - `ROUTING_DOMAIN_OPTIONS` usa `auto`, `atlas`, `vault-curador`, `saude`, `blackink`, `financas`.
   - Isso pertence a Business Context/Product Domain, nao ao Atlas AI domain catalog.
   - Risco: usuario escolhe "programacao", mas a payload ainda carrega `domain: atlas` em vez de `domain_id: programming`.

2. App routing mode/task model hardcoded:
   - `general`, `operational`, `programming` sao modos de UX, nao domains canonicos.
   - `dev/debug/review/plan` sao tarefas de UX, nao flows canonicos.
   - Risco: surfaces continuam criando mapeamentos locais divergentes.

3. Executor choices hardcoded:
   - App apresenta `auto`, `claude_cli`, `codex_cli`, `gemini_cli`, `claude_codex`.
   - Catalogo expoe `flows[].executor_preference` como `simple_provider_execution`, `dev_repair_executor`, `engineering_harness`, `domain_runtime`, `domain_forge_runtime`.
   - Risco: UI mistura provider com executor/runtime.

4. Mobile capability profiles hardcoded:
   - `mobile_operational_read`, `atlas_full_access`, `atlas_programming`, `permission_mode=danger/read`.
   - Risco: elevacao de runtime sem exibir autonomy/safety do flow.

5. MCP tool list e capability inventory separados:
   - `AtlasOpenBrainMcpService::tools()` declara tools diretamente.
   - `AtlasCapabilityRegistry` declara surfaces/capabilities em config.
   - Risco: MCP publicar tool que nao existe no registry, ou registry nao refletir o MCP real.

6. API interaction payload livre:
   - `StoreAiInteractionRequest` valida o envelope, mas nao conhece `domain_id`/`flow_id`.
   - Risco: clients mandam `atlas_workflow_mode`, `routing_domain` ou `agent_slug` como substitutos informais do catalogo.

## Integração Proposta

## Implementação Nesta Etapa

- Criado `DomainCatalogSurfaceSelectionService` como adapter de surface para traduzir UX mode/task/domain em `domain_id` e `flow_id` canonicos.
- Surface adapters formais agora declaram `surface_id`, capabilities, attachment kinds, hint keys e `supportedDomainFlowHints()` quando podem aceitar domain/flow do catalogo.
- `supportedDomainFlowHints()` tem vocabulario fechado (`SurfaceDomainFlowHintKey`) e compliance forte: keys desconhecidas, listas malformadas, default flow fora de `supported_flow_ids` ou `task_flow_map` fora dos flows suportados falham nos testes.
- `DomainCatalogSurfaceSelectionService` valida dominio e flow contra `AtlasAiDomainCatalogService`, exige que surfaces registradas declarem suporte antes de aceitar selecao explicita (`surface_domain_flow_selection_not_supported`), respeita limites declarados pela surface (`surface_domain_not_supported`, `surface_flow_not_supported`), retorna diagnostico claro (`domain_not_found`, `flow_not_found`, `domain_flow_mismatch`) para selecoes invalidas, e so usa fallback seguro para mapeamento implicito de UX quando o catalogo permitir.
- `atlas_cli_dev` mapeia tarefas de programacao para `programming.dev`, `programming.review` e `programming.repair`; `atlas_cli_forge` prefere `programming.forge` para tarefas `forge`, `heavy`, `build` e `plan`.
- `atlas:ai:domains` ganhou preview opt-in com `--select`, `--surface`, `--mode`, `--task` e `--routing-domain`.
- `POST /ai/interactions` agora enriquece payloads de surface com `domain_catalog_selection`, `domain_id`, `flow_id`, `surface_id`, safety e executor preference quando o payload traz sinais de roteamento.
- MCP/Open Brain ganhou tool read-only `atlas_domain_catalog`, com filtros `domain`, `flow`, `maturity` e `onboarding_status`.
- App ganhou types/fetcher para `GET /ai/domains` e adapter `selectAtlasAiDomainFlow`, sem alterar UI.
- Testes cobrem API catalog, API interaction, CLI, architecture validate, adapter PHP, adapter TS e MCP.

Essas mudancas nao alteram os contratos centrais do catalogo, nao mexem em routes/config/registry e deixam UI/mobile visual prontos para consumir o mesmo read model em PR separado.

### 1. Escolher domain/flow

Criar um adaptador por surface, nao no registry central:

- Input: UX mode/task/domain/executor atual.
- Input canonico recomendado: `domain_catalog_selection` com `surface_id`, `ux.mode`, `ux.task`, `ux.product_domain`, `domain.id` e `flow.id`; campos planos `domain_id` e `flow_id` continuam aceitos e vencem envelopes antigos.
- Output: `domain_id`, `flow_id`, `surface_id`, `selection_source`, `operator_override`.
- Fonte: `AtlasAiDomainCatalogService` no server e `GET /ai/domains` nas clients.
- Surface hints: adapters podem declarar defaults e `task_flow_map`, mas o selector sempre valida o resultado contra o catalogo canonico.
- Surface hints sao declarativos e auditaveis: `default_domain_id`, `default_flow_id`, `supported_domain_ids`, `supported_flow_ids`, `task_flow_map`, `prefer_default_flow` e flags de aceitacao passam por compliance antes de virarem contrato de surface.
- Surface registrada que nao declara `domain_flow_selection` nao aceita `domain_id` ou `flow_id` explicitos; o selector retorna `surface_domain_flow_selection_not_supported` em vez de tratar a escolha como override valido.
- Quando `supported_domain_ids` ou `supported_flow_ids` existem, eles restringem o que a surface aceita expor. O catalogo continua sendo a fonte de verdade, e o selector retorna `status=unresolved` se uma escolha canonica nao estiver declarada pela surface.
- O resultado inclui `surface_hints.supported_capabilities` para UI/API/MCP exibirem badges e diagnosticarem limites da surface; isso nao autoriza provider, memoria ou runtime diretamente.
- Aliases legados sao resolvidos antes da selecao: `atlas_cli` aponta para `atlas_cli_dev` e `atlas_api` aponta para `atlas_api_interaction`, evitando surface desconhecida e bypass de limites formais.
- Fallback: permitido apenas para selecao implicita de UX. `domain_id`/`flow_id` explicitos invalidos retornam `status=unresolved` com `error.code` e nao fazem fallback silencioso.

Mapeamento inicial recomendado:

| UX | Flow canonico |
| --- | --- |
| `general/direct` | `general.answer` |
| `general/plan` ou `general/review` | `research.quick` quando contexto pedir pesquisa, senao `general.answer` |
| `operational/review` | `operations.diagnostic` |
| `programming/dev` | `programming.dev` |
| `programming/debug` | `programming.repair` |
| `programming/review` | `programming.review` |
| `programming/plan` | `programming.dev` com autonomy/gates conservadores |
| `atlas_cli_forge/heavy` | `programming.forge` |
| marketing entrypoints | `marketing.strategy`, `marketing.campaign`, `marketing.copywriting` etc. |
| self-improvement scheduled/manual | `self_improvement.*` |

Payload surface recomendado:

```json
{
  "domain_id": "programming",
  "flow_id": "programming.repair",
  "surface_id": "atlas_app",
  "selection_source": "operator",
  "catalog_schema_version": 1
}
```

### 2. Mostrar maturity/onboarding

Usar `domains[].orchestrator_maturity` e `domains[].onboarding` como read-only badges:

- `ready 9/9`: habilitado sem aviso.
- `executable_incomplete`: habilitado com "em consolidacao".
- `scaffold/planned`: visivel em settings/catalog, oculto no picker padrao ou marcado como experimental.

Estado canonico atual:

- Ready/implemented: `programming`, `finance`, `personal_development`,
  `self_improvement`.
- Scaffold/catalog-ready: `marketing`, `research`, `health`, `learning`,
  `writing`, `qa`, `security`, `operations`, `background`, `general`.
- Marketing nao deve ser liberado no picker principal como dominio pronto ate
  existir runtime/orchestrator proprio.

Para app/mobile:

- Picker mostra label e flow count.
- Detail/Settings mostra onboarding phases, missing phases e catalog source.
- Nenhuma surface deve reimplementar scorecard.

### 3. Mostrar executor preference

Separar tres conceitos na UI:

- Provider: `claude_cli`, `codex_cli`, `gemini_cli`, `claude_codex`.
- Executor preference: `simple_provider_execution`, `dev_repair_executor`, `engineering_harness`, `domain_runtime`.
- Runtime/safety: `runtime`, `autonomy`, `destructive_requires_approval`, `background_allowed`.

No app, "Atlas decide" deve significar provider auto; "executor" deve vir do flow e ser exibido como consequencia do flow escolhido. Override de provider nao deve trocar flow.

### 4. Mostrar safety/autonomy

Surface deve sempre derivar safety do flow:

- `autonomy` / `autonomy_default`: badge de escopo.
- `destructive_requires_approval`: aviso antes de acao destrutiva.
- `background_allowed`: se pode rodar por automacao.
- `onboarding.status`: se pronto para usuario comum.

Mobile especifico:

- Thread normal fica read-only.
- "Discutir com Atlas" pode promover para runtime completo apenas se flow escolhido permitir e a UI mostrar a elevacao.
- Para programacao, exigir workspace explicito e confirmacao antes de `permission_mode=danger`.

### 5. Impedir surface chamar capability diretamente fora do registry

Sem mexer em contratos centrais agora, estabelecer a regra de PR:

- Toda surface que expor uma acao deve apontar para `surface_id` de `AtlasCapabilityRegistry`.
- Toda tool/action nova deve ter capability id e test_suite.
- MCP `tools()` deve ter teste que compara nomes/annotations com registry ou com manifest de adapter.
- App/mobile devem consumir capabilities via API read model, nao manter authority local alem do fallback visual.

Fase futura pequena:

- Criar read model surface-safe: `GET /ai/surfaces/{surface}/capabilities`.
- Ou incluir em `GET /ai/domains` um bloco `surface_readiness`, se o contrato central for aprovado.

## PRs Pequenos Recomendados

1. App client read model:
   - Concluido com types/fetcher para `GET /ai/domains`.
   - Sem mudar UI; apenas client/adapter e teste de parsing.

2. App routing adapter:
   - Concluido com `lib/atlasAiDomainCatalog.ts`, espelhando `DomainCatalogSurfaceSelectionService`.
   - Coberto por `scripts/atlas-ai-domain-catalog.test.ts`.

3. Routing sheet catalog-aware:
   - Trocar `ROUTING_DOMAIN_OPTIONS` por opcoes derivadas do catalogo para AI routing.
   - Manter Business Contexts/Product Domains (`blackink`, `saude`, `financas`) no fluxo de captura/contexto, nao no AI domain picker.

4. API interaction metadata:
   - Concluido no controller de interaction antes do gateway.
   - Testado que o gateway recebe `domain_id`, `flow_id`, `surface_id` e `domain_catalog_selection`.

5. CLI flow selection:
   - `atlas:ai:domains --select` ja cobre preview de selection.
   - Adicionar `--domain`/`--flow` em `atlas:ai:chat` e/ou `atlas:ai:decide`.
   - Renderizar flow selecionado, maturity/onboarding e executor preference no preview.

6. Mobile safety affordance:
   - Mostrar flow/autonomy/approval no sheet antes de elevar para runtime completo.
   - Bloquear `danger` se flow nao permitir ou workspace estiver ausente.

7. MCP catalog read tool:
   - Concluido com `atlas_domain_catalog` read-only.
   - Testado que retorna os mesmos ready domains e fields minimos de `GET /ai/domains`.

8. Capability boundary test:
   - Testar que cada MCP tool e cada app-exposed runtime action tem capability ou not_supported reason.

## Riscos

- Confusao entre Business Contexts/Product Domains (`blackink`, `saude`, `financas`) e AI domains (`programming`, `marketing`, `self_improvement`). O plano separa contexto de negocio/captura de routing AI.
- Misturar provider e executor preference. Provider e escolha de modelo; executor preference e estrategia operacional do flow.
- Expor scaffold domains como se fossem prontos. O picker principal deve priorizar `onboarding.status=ready`.
- Dar autonomia alta no mobile sem confirmacao. Mobile deve tratar `danger` como elevacao explicita.
- MCP drift: tools podem crescer fora do registry se nao houver teste comparativo.

## Definition of Done por Surface

- Surface busca ou recebe catalogo via read model.
- Surface consegue selecionar `domain_id` e `flow_id`.
- Surface mostra maturity/onboarding, executor preference e safety/autonomy.
- Surface preserva selection metadata em payload/trace/job.
- Surface nao chama runtime/capability sem capability id reconhecido ou not_supported reason.
- Adapter com `domain_flow_selection` deve expor `supportedDomainFlowHints()` e passar no compliance report.
- Flow explicito inexistente deve retornar diagnostico (`flow_not_found`) e preservar ausencia de patch canonico.
- Teste cobre pelo menos um ready domain (`programming`) e um flow executor-specific (`programming.repair` ou `programming.forge`).
