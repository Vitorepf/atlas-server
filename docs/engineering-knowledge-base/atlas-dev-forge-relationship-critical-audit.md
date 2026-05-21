---
id: atlas-dev-forge-relationship-critical-audit
type: engineering_knowledge
title: Atlas Dev x Atlas Forge Relationship Critical Audit
status: active
category: programming
priority: 90
summary: Auditoria crítica (READ-ONLY) sobre a relação Atlas Dev ↔ Atlas Forge — identidade canônica vs implementação real. Veredicto principal — os dois núcleos existem e são tecnicamente independentes em código, mas os 2 schemas canônicos do contrato dual-core não estão implementados, e há 4 mecanismos paralelos de escalação Dev→Forge no código que não concordam entre si.
tags:
  - atlas-dev
  - atlas-forge
  - dual-core
  - escalation
  - programming
  - 2026-05-18
capabilities:
  - dev_forge_audit
  - escalation_mechanism_comparison
  - canonical_contract_vs_code
  - naming_confusion_mapping
decisions:
  - Dev e Forge existem como sistemas completos em código (138 files Dev + ~75 files Forge); identidade respeitada no nível de import.
  - Os 2 schemas canônicos `atlas.dual_core.route_decision.v1` e `atlas.dev_to_forge.escalation_packet.v1` NÃO existem em código.
  - Há 4 mecanismos paralelos de escalação Dev→Forge que não compartilham schema.
  - Recomendação principal — implementar os 2 schemas dual-core antes de qualquer outro trabalho na relação Dev↔Forge.
maintenance:
  - Regenerar quando schemas dual-core forem implementados.
  - Atualizar quando o feature flag `atlas_dev_efficient_plan_enabled` for promovido em produção.
  - Atualizar quando os drivers reais Claude/Codex/Gemini CLI saírem de `provider_driver_missing`.
related_paths:
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-dev-index.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
  - docs/engineering-knowledge-base/atlas-dev-patamares.md
  - docs/engineering-knowledge-base/atlas-dev-policy.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
  - docs/engineering-knowledge-base/atlas-forge-live-execution-e2e-v1.md
  - docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md
  - docs/engineering-knowledge-base/atlas-architecture-critical-judgment-report.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-forge-relationship-critical-audit
graph_title: Atlas Dev x Atlas Forge Relationship Critical Audit
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-dual-core-engineering-system
graph_status: active
graph_source: repo
human_name: Atlas Dev x Atlas Forge Relationship Critical Audit
canonical_name: Atlas Dev x Atlas Forge Relationship Critical Audit
technical_name: atlas-dev-forge-relationship-critical-audit
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-dev-forge-relationship-critical-audit.md

owner: programming

repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-forge-relationship-critical-audit.md

allowed_changes:
  - Atualizar quando schemas dual-core forem implementados em código.
  - Atualizar quando os 4 mecanismos paralelos de escalação forem consolidados.

forbidden_changes:
  - Declarar Dev como feature interna do Forge.
  - Declarar Forge como mero executor subordinado ao Dev.
  - Suavizar veredicto sem evidência de schemas dual-core em código.

depends_on:
  - atlas-dual-core-engineering-system
  - atlas-architecture-critical-judgment-report
  - atlas-full-architecture-understanding-report

flows_to:
  - atlas-dual-core-engineering-system
  - atlas-dev-index
  - atlas-forge-continuum-os

unlocks:
  - dual-core-schema-implementation-plan
  - escalation-mechanism-consolidation-plan

governs:
  - dev_forge_relationship_judgment

evidence:
  - app/Services/Ai/Programming/AtlasDev/Escalation/EscalationDecisionEngine.php
  - app/Services/Ai/Programming/AtlasDev/Escalation/ForgePromotionPreviewBuilder.php
  - app/Services/Ai/Programming/Kernel/AtlasForgeHandoffAdapter.php
  - app/Services/Ai/Programming/AtlasForgeRuntimeDispatchService.php
  - app/Services/Ai/Programming/AtlasForgeLiveExecutionService.php
  - app/Services/Ai/Programming/AtlasCodeForgeFastPathService.php
  - app/Services/Ai/Programming/AtlasCodeObraCommandCenterService.php
  - app/Http/Controllers/AtlasCodeForgeRuntimeDispatchController.php
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

next_actions:
  - ✅ 2026-05-18 — schema `atlas.dual_core.route_decision.v1` implementado (`app/Services/Ai/DualCore/DualCoreRouteDecisionService.php` + `AiDualCoreRouteDecision` model + `ai_dual_core_route_decisions` table + 11 testes em `DualCoreRouteDecisionServiceTest`).
  - Implementar schema `atlas.dev_to_forge.escalation_packet.v1` (atualmente 0 matches).
  - Consolidar os 4 mecanismos paralelos de escalação em um único path canônico.
  - Reconciliar doc status `future` em atlas-forge-operating-system{,-contracts,-runbook}.md vs código em produção.
  - Promover Dev de "fatia 5 em construção" para produção via feature flag `atlas_dev_efficient_plan_enabled`.
  - Decidir configuração de drivers reais (claude_cli/codex_cli/gemini_cli) ou explicitar que Forge é fixture-only.

---
# Atlas Dev x Atlas Forge Relationship Critical Audit

## Resumo
**Veredicto curto:** os dois núcleos **existem como sistemas técnicos completos
e independentes em código** (Dev: 138 files / 20.7k LOC / 15 submódulos
organizados; Forge: ~75 files entre AtlasForge* / AtlasCodeForge* /
ForgeRivals/ com 30-40 endpoints HTTP), e o **contrato canônico
dual-core é excelente em design** (`atlas-dual-core-engineering-system.md`,
status:active, priority:100, line_limit:900). Forge NÃO importa nenhum
serviço de Dev (verificado: `rg "use.*AtlasDev" app/Services/Ai/Programming/AtlasForge*.php`
→ 0 matches). A **identidade** é respeitada.

**Mas a relação Dev ↔ Forge está rota em duas dimensões críticas:**

1. **Os dois únicos schemas canônicos** que materializariam a relação —
   `atlas.dual_core.route_decision.v1` e `atlas.dev_to_forge.escalation_packet.v1`,
   declarados nos blocos JSON de `atlas-dual-core-engineering-system.md:247-258` e
   `:279-301` — **não existem em código** (`rg` em todo o `app/` retorna 0
   matches para cada).
2. **Existem 4 mecanismos paralelos** de escalação/promoção Dev→Forge que
   **não compartilham schema nem source-of-truth**.

A doc canônica (`atlas-dual-core-engineering-system.md:67-72`) proíbe
explicitamente fundir Dev e Forge ou declarar um como subordinado do outro.
O código respeita isso no nível de import; o **vacuum de schemas e a
proliferação de mecanismos paralelos é o oposto disso na prática**: cada
componente assume um contrato local incompatível com os outros.

## Papel no Atlas
Este relatório existe para:

1. **Diferenciar identidade preservada (boa) de relação implementada (rota)**.
2. **Mapear as 4 mecânicas paralelas de escalação** para que o operador
   decida qual sobrevive.
3. **Quantificar maturidade real** dos dois núcleos via inspeção de classes,
   testes e endpoints HTTP — não via contagem agregada.
4. **Recomendar a próxima ordem** com risco mínimo: implementar primeiro o
   contrato canônico antes de novas features.

Não substitui `atlas-dual-core-engineering-system.md` (que é a lei); este
doc apenas reporta discrepâncias e recomenda ordem de implementação.

## Onde Se Encaixa
Filho de `atlas-dual-core-engineering-system` no graph; cruzado com
`atlas-architecture-critical-judgment-report` (Fase 2) que estabeleceu o
veredicto mais amplo de que o Kernel canônico Mission/Router não recebe
tráfego de produção. Este doc afina o foco para Dev↔Forge.

## Contratos
Os 2 schemas que **DEVERIAM** governar a relação Dev↔Forge
(`atlas-dual-core-engineering-system.md`):

**`atlas.dual_core.route_decision.v1`** (campos canônicos, linhas 247-258):
`route` (dev|forge|dev_to_forge), `reason`, `intent_summary`,
`ambiguity_level`, `risk_level`, `expected_duration`,
`modules_touched_estimate`, `sdd_required`, `evidence_required`,
`operator_visible`.

**`atlas.dev_to_forge.escalation_packet.v1`** (linhas 279-301):
`source`, `target`, `intent`, `dev_interpretation`, `why_escalated`,
`workspace`, `evidence_refs` (plan, senior_loop_audit, senior_loop_execution,
verification_receipt, error_ledger, failure_capsules), `known_risks`,
`open_questions`, `recommended_forge_mode`.

**Estado real:**

| Schema | Documentado | Em código | Tabela DB | Teste |
|--------|-------------|-----------|-----------|-------|
| `atlas.dual_core.route_decision.v1` | ✅ | ✅ `DualCoreRouteDecisionService` + `AiDualCoreRouteDecision` (2026-05-18) | ✅ `ai_dual_core_route_decisions` | ✅ `DualCoreRouteDecisionServiceTest` (11 cases) |
| `atlas.dev_to_forge.escalation_packet.v1` | ✅ | ❌ 0 matches | ❌ | ❌ |
| `atlas.dev.forge_promotion_preview.v1` | ❌ não canônico | ✅ `ForgePromotionPreviewBuilder` | n/a (artifact path) | ✅ |
| `atlas.dev.senior_engineer_loop_audit.v1` | ✅ runbook | ✅ `SeniorEngineerLoopAudit` | n/a (JSON file) | ✅ |
| `AiDomainHandoff` (Meta 2) | ✅ outro doc | ✅ `DomainHandoffService::emit` | ✅ `ai_domain_handoffs` | ✅ |

Contratos auxiliares (Dev) **TODOS implementados** como classes em
`app/Services/Ai/Programming/AtlasDev/Schemas/` (14 DTOs canônicos:
OperationEnvelope, CompactSdd, MiniProgrammingSpec, LightTaskContract,
ContextRetrievalPlan, CodeDiscoveryManifest, OpenBrainProgrammingProjection,
ProviderPromptProjection, ScopeGuardReceipt, VerificationReceipt,
FailureCapsule, EscalationDecision, FastPathTelemetry,
FastPathErrorLedgerEntry).

Contratos Forge (per `atlas-forge-continuum-os.md` e
`atlas-forge-live-execution-e2e-v1.md`) implementados:
`atlas.code.forge_fast_path.v1`, `atlas.code.obra_command_center.v1`,
forge `live_execution` packet, `provider_invocation` envelope, 37
invariants em `AtlasForgeContinuumCertificationService`.

## Fluxo
**Fluxo declarado** (`atlas-dual-core-engineering-system.md:166-171`):
```
Atlas AI Router
-> decide Dev, Forge ou Dev -> Forge
-> registra motivo
-> entrega ao nucleo correto
-> preserva evidence compativel
```

**Fluxo real em produção (traçado via código):**

```
Surface (Desktop/CLI/API)
-> [path A: AiWorker legacy] AiProviderManager → AtlasProgrammingOrchestrator
-> [path B: Atlas Dev novo]  HTTP /ai/interactions/atlas-dev/{plan,run,stream}
                              → AtlasDev/PlanController / RunController
                              → AtlasDevFastPathOrchestrator
                              → (se escalar) AtlasDev/Escalation/
                                   → ForgePromotionPreviewBuilder
                                   → preview_artifact_path
                                   → operador aprova manualmente
                                   → ... 3 caminhos diferentes de promoção
-> [path C: Atlas Code Forge] HTTP /works/{project}/forge/{fast-path,live-executions,...}
                              → AtlasCodeForge* services
                              → AtlasForgeRuntimeDispatchService (NÃO Meta 6 RuntimeDispatchService)
                              → AtlasForgeProviderInvocationService (dry-run default)
                              → drivers (atlas-local ok; claude/codex/gemini = provider_driver_missing)
```

Nenhum desses 3 paths passa pelo `RouterRuntime/FlowRouterService` canônico
(Meta 6). Atlas Decide não é invocado por nenhum deles.

## Regras para IA
Invariantes do doc canônico `atlas-dual-core-engineering-system.md` (`forbidden_changes` + `regras para IA`):

1. **Dev não é Forge mini** (linhas 26, 414-417).
2. **Forge não é gerente do Dev** (linhas 26, 414-417).
3. **Nada fundido em runtime único** (linha 70).
4. **Sem escalação silenciosa**: packet + receipt + motivo auditáveis (linha 71).
5. **Dev não executa Obra longa** sem promover para Forge (linha 72).
6. **Evidence pode ser compartilhada**; identidade, runtime, UX, sucesso continuam separados (linha 32).
7. **Forge não depende de Dev** para ser completo (linhas 161, 221).

**Estado de enforcement:**

| Invariante | Enforced? |
|-----------|-----------|
| 1. Dev não é Forge mini | ✅ código separado em `AtlasDev/` |
| 2. Forge não é gerente do Dev | ✅ Forge não importa AtlasDev |
| 3. Nada fundido | ✅ identidade preservada |
| 4. Sem escalação silenciosa | ⚠️ **parcial** — `ForgePromotionPreviewBuilder` exige operador aprovar via Attention queue, **mas** packet canônico v1 não emitido; `AtlasForgeHandoffAdapter::promote` no Kernel emite `AiDomainHandoff` com `forge_escalation: $reason` (diferente schema) |
| 5. Dev não executa Obra | ⚠️ `EscalationDecisionEngine` força promoção se score≥7 ou risk≥R4; mas não há gate que IMPEÇA Dev de seguir; comentário interno reconhece "Atlas Dev NEVER auto-creates an Obra; the human accepts via the Attention queue" |
| 6. Evidence compartilhada | ❌ schemas Dev (`*.json` em `storage/atlas-dev/receipts/`) e Forge (`AiCertification`, `AiEvidencePack`) NÃO se cruzam por campo `evidence_refs`; `shared_evidence` doc-only |
| 7. Forge independente | ✅ `rg "use.*AtlasDev" app/Services/Ai/Programming/AtlasForge*.php` → 0 |

## Escopo de Implementacao
Esta auditoria é **leitura pura**. Não altera código, não cria runtime, não
cria Company Runtime. Cobre:

- 8 docs `atlas-dev-*.md` lidas em profundidade.
- 8 docs `atlas-forge-*.md` lidas em profundidade + 20 docs
  `atlas-forge-rivals-*.md` contabilizadas (cap por escopo).
- Doc canônica `atlas-dual-core-engineering-system.md` lida integralmente.
- Inspeção de `app/Services/Ai/Programming/AtlasDev/` (138 files / 15 subdirs).
- Inspeção dos ~26 arquivos `AtlasForge*.php` flat em `Programming/`.
- Inspeção do `app/Services/Ai/Programming/ForgeRivals/` (~50 services).
- Inspeção dos 6 `AtlasCodeForge*.php` + `AtlasCodeObraCommandCenterService.php`.
- Inspeção dos comandos CLI `atlas:cli:dev`, `atlas:dev:senior-loop:*`,
  `atlas:forge:*`, `atlas:cli:fix`, etc.
- Inspeção de 13 controllers HTTP em `app/Http/Controllers/AtlasDev/` + `AtlasCode*`.
- 38+ testes Feature/Unit em `tests/*/Programming/AtlasDev/` (cobertura
  Pipeline, Escalation, Gate, Provider, Repair, SeniorLoop).

**Não cobre** (por escopo): pesquisa exaustiva nos 20 docs Rivals,
verificação de cada um dos 30-40 endpoints Forge, inspeção do Desktop UI,
verificação de cada um dos 37 invariants do
`AtlasForgeContinuumCertificationService`.

## Dependencias
Análise depende dos seguintes itens não verificados nesta auditoria:

- **AP/spec de migração AiWorker → Kernel canônico**: existe? Se sim,
  Patamar A6 (Adaptive Engine) ganha caminho. Hoje `AtlasDev/Runtime` aceita
  `decision_mode: atlas_decide` (`AtlasDevRuntimeService:213-226`) mas a
  invocação real de Atlas Decide vive fora de AtlasDev/.
- **Driver real config para Claude/Codex/Gemini CLI**: hoje retorna
  `provider_driver_missing` (`AtlasForgeProviderInvocationDriverRouter`).
  Política sobre quando habilitar não está nesta auditoria.
- **External Rivals certification flag**: project memory diz BLOCKED por
  design; não validado direto.
- **Atlas Code Obra Command Center** vs **Forge Cockpit Desktop UI**: não
  verifiquei o consumidor frontend; apenas o service backend.

## Evidencias
### Resposta às 13 perguntas

**1. O que Atlas Dev é hoje (doc + código)?**
Fluxo especializado de programação workspace-bound dentro do Atlas AI
(`atlas-dev-index.md:103`). Em código: 138 files / 20.7k LOC em 15 submódulos
(`AtlasDev/{Pipeline,Schemas,Gate,Provider,Repair,Discovery,PromptProjection,SeniorLoop,Surface,Persistence,Escalation,Security,Telemetry,Runtime,RunIndex}`).
Orchestrator real: `AtlasDevFastPathOrchestrator`. `AtlasDevRuntimeService`
é intake adapter. HTTP: 7 rotas `/ai/interactions/atlas-dev/*` + 11 controllers.
CLI: `atlas:cli:dev [--efficient]`, `atlas:cli:fix/continue/final` + 8+
especializados (`atlas:dev:senior-loop:*`, `:readiness`, `:smoke`, `:desktop:*`).
38+ testes Feature/Unit behavior-level.

**2. O que Atlas Forge é hoje (doc + código)?**
Sistema de engenharia pesada por Obras (`atlas-forge-continuum-os.md`). Em
código é **4 sub-sistemas wedged**:
- (a) **Ghost** OS original: parent docs `status: future`, sem código equivalente.
- (b) **Live Runtime** (active): ~26 files `AtlasForge*.php` flat — provider invocation (9), governance/execution (4), topology/fallback (3), certification (3), CLI drivers (3 stubs `provider_driver_missing`).
- (c) **Rivals battery**: 44+ services em `ForgeRivals/` + 20 docs `atlas-forge-rivals-*.md` (Rivals:governance ~2.75:1).
- (d) **UX Facade**: 6 `AtlasCodeForge*` + `AtlasCodeObraCommandCenter` (read-model 8-fase). 30-40 endpoints HTTP + `/works/{project}/state` com 25+ projeções.

**3. Intenção canônica vs implementação real?**
| Aspecto | Doc canônica | Código |
|---------|-------------|--------|
| Dev identidade | sistema completo rápido | ✅ implementado, 85% completo |
| Forge identidade | sistema completo pesado | ⚠️ multiple sub-sistemas, drivers reais bloqueados |
| Boundary contract | `route_decision.v1` schema | ❌ 0 matches |
| Escalation packet | `escalation_packet.v1` schema | ❌ 0 matches; 4 mecanismos paralelos no lugar |
| Forge independente | sim | ✅ verificado |
| Dev sem auto-Obra | sim | ✅ enforced via Attention queue |
| Evidence compartilhada | refs cruzadas | ❌ schemas locais não casam |
| Router upstream decide | sim | ❌ produção HTTP usa paths legados, não Meta 6 Router |

**4. Confusões de arquitetura, naming ou responsabilidade?**
- **Naming proliferation Forge** (8 nomes co-existem): Atlas Forge / Atlas Code Forge / Atlas Code Forge Fast Path / Atlas Code Obra Command Center / Atlas Forge Continuum OS / ForgeRivals / Forge Workspace / Obra.
- Doc status invertido (parents `future`, children `active`).
- Forge code flat em `Programming/` vs Dev subdir organizado — assimetria.
- AtlasDev tem 2 modos (legacy vs `--efficient`) com mesmo prefixo de comando.
- 4 mecanismos paralelos de escalação Dev→Forge (ver Q9).
- `AtlasForgeRuntimeDispatchService` ≠ Meta 6 `RouterRuntime\\RuntimeDispatchService`.
- `AtlasForgeContinuumCertificationService` vs `AtlasForgeRuntimeCertificationService` com boundary não óbvia.

**5. Doc atual deixa claro quando usar Dev vs Forge?**
**SIM, no doc canônico** (`atlas-dual-core-engineering-system.md:226-237`):
matriz com 10 sinais → rota padrão + motivo. Exemplos:
- "Bug pequeno/local" → Dev
- "Mudança em muitos módulos" → Forge
- "Trabalho de dias/semanas/meses" → Forge
- "Alto risco, dados, segurança, billing, auth, compliance" → Forge
- "Dev detecta escopo expandindo" → Dev → Forge

Doc também enumera **regras NEGATIVAS** para a IA (`:411-419`):
- "Não diga 'Forge manda pacotes para Dev'"
- "Não diga 'Dev é uma versão leve do Forge'"
- "Não diga 'Forge é só para tarefas que Dev não consegue'"

**6. Código atual respeita essa separação?**
**Parcialmente.**
- ✅ Forge não importa AtlasDev (verificado).
- ✅ Dev não cria Obra automática (gate via Attention queue).
- ✅ Identidade de runtime/UX/sucesso preservada.
- ❌ Schema `route_decision.v1` não existe — ninguém pode auditar a decisão "Dev vs Forge" na forma canônica.
- ❌ Schema `escalation_packet.v1` não existe — escalação não tem packet canônico.
- ⚠️ Há 3 mecanismos de promoção concorrentes (ver §Riscos), nenhum é o canônico.

**7. Gaps Atlas Dev:**
- Fatia 5 (Surface Parity) em progresso — Desktop 95%, CLI 80%, App 60%.
- Feature flag `atlas_dev_efficient_plan_enabled` não ON em produção.
- A2-A5 `planejado`; A6 (Adaptive Engine) e A7 (Continuous Learning) `futuro`.
- A6 exige Atlas Decide invocado por AtlasDev — hoje só normalizado, não invocado.
- Provider lock fixo em Claude Sonnet por policy P11 — sem multi-provider.
- Coexistência legacy↔efficient com mesmo prefixo `atlas:cli:dev`.
- 138 files sem doc index navegável dos 15 submódulos.

**8. Gaps Atlas Forge:**
- Doc status inversão (parents `future` vs código em produção, 30-40 endpoints).
- Drivers reais (`claude_cli`/`codex_cli`/`gemini_cli`) retornam `provider_driver_missing`; só `atlas-local` mock executa.
- Rivals (44 services / 20 docs) fora do completion loop; sem ponte learning→policy.
- Code flat em `Programming/` (26 files misturados com 30+ genéricos), sem subdir `AtlasForge/`.
- Dois certification services (`Continuum`/37 invariants vs `Runtime` simples) com boundary não óbvia.
- `AtlasForgeRuntimeDispatchService` ≠ `RouterRuntime\\RuntimeDispatchService` (Meta 6); Router canônico ignorado em produção.

**9. Gaps integração Dev ↔ Forge ↔ Atlas AI Router:**

a. **Schemas dual-core inexistentes** — `atlas.dual_core.route_decision.v1` (0 matches), `atlas.dev_to_forge.escalation_packet.v1` (0 matches).

b. **4 mecanismos paralelos de escalação/promoção Dev→Forge no código** (todos para fins similares, todos com campos diferentes):

   | # | Local | Schema/output | Gatilho |
   |---|-------|---------------|---------|
   | 1 | doc canônica | `atlas.dev_to_forge.escalation_packet.v1` | doc-only, 0 código |
   | 2 | `AtlasDev/Escalation/ForgePromotionPreviewBuilder` | `atlas.dev.forge_promotion_preview.v1` (intent_summary, changed_files, context_refs, workspace_hash, thread_id) | score≥7 OR risk≥R4 |
   | 3 | `App\Services\AtlasCode\DevToForgePromotionService` | (thread_id, workspace_slug) DB-bound AiThread/AiMessage/AtlasProject | HTTP `/dev-to-forge/threads/{thread}/promote` |
   | 4 | `Kernel/AtlasForgeHandoffAdapter::promote` | `AiDomainHandoff` model via Meta 2 `DomainHandoffService::emit` (mission_id, work_order_id, context_pack, expected_output, evidence_refs, receipt_hash) | `mission_type==obra` OR heurística `ProgrammingDomainKernelCanon::shouldEscalateToForge` |

   Comentário em `ForgePromotionPreviewBuilder.php:20-27` reconhece a
   lacuna: AtlasCode promotion consome `(thread_id, workspace_slug)` mas
   Atlas Dev fast path tem `run_id`. **Time já documentou o gap; permanece
   aberto.**

c. **Router canônico Meta 6 (`FlowRouterService`/`DomainRouterService`) não recebe HTTP** — produção Atlas Dev usa rotas próprias `/ai/interactions/atlas-dev/*`; produção Atlas Code Forge usa `/works/{project}/forge/*` → `AtlasForgeRuntimeDispatchService`. Nenhum dos dois invoca Atlas Decide ou Router canônico.

d. **Evidence cross-reference doc-only** — Dev grava JSONs em `storage/atlas-dev/receipts/<run_id>/`; Forge grava `AiCertification`/`AiEvidencePack`. Não há `evidence_refs` linkando os dois.

e. **Sem teste E2E Dev → Escalation → Forge intake** com schemas canônicos (já que não existem).

**10. Relação ideal entre Dev e Forge:**
Doutrina canônica já bem definida em
`atlas-dual-core-engineering-system.md:158-178`. Síntese:
`Atlas AI Router (Meta 6) → route_decision.v1 (dev|forge|dev_to_forge) →
núcleo correto → se escalar, escalation_packet.v1 (honest handoff) →
Forge intake (SDD/Obra/work_packets) → completion`. Princípios: dois
núcleos completos sem fusão; Router decide; escalação com packet+receipt+motivo
auditáveis; Forge pode usar Dev taticamente (não regra); evidence via refs;
operador sempre vê núcleo, motivo, risco, evidence.

**11. "Forge recebe pacotes do Dev" é correto?**
**INCOMPLETA — e enganosa se virar regra geral.** Per
`atlas-dual-core-engineering-system.md:412-417`:
> "Não diga 'Forge manda pacotes para Dev' como regra geral. Diga 'Forge
> pode usar Dev taticamente quando fizer sentido'."

A descrição correta é:
- Forge **é completo por si só**; resolve Obras que nascem Obras.
- Dev pode promover para Forge **quando detectar Obra** (escalação honesta).
- Forge pode opcionalmente emitir work packets compatíveis com Dev para
  execução tática curta — interoperabilidade, não dependência.
- Dev não é "fila de entrada" do Forge; eles têm entradas independentes via
  Atlas AI Router.

**12. Qual implementação vem primeiro para reduzir risco?**
**Implementar `atlas.dual_core.route_decision.v1`** como schema executável
(classe + persistência mínima). Menor delta com maior redução de risco:
sem ele, os 4 mecanismos paralelos continuarão divergindo e o operador não
tem visibilidade auditável da decisão Dev↔Forge. Custo pela doc: 4-6h MVP
(linha 390). Não exige refatorar runtime/Forge/Dev — adiciona camada
ortogonal. Logo após, `atlas.dev_to_forge.escalation_packet.v1` (6-8h) e
consolidar os 3 mecanismos paralelos de promoção em um único path emissor
desse packet.

**13. Recomendações priorizadas:**

| # | Pri | Ação | Custo |
|---|-----|------|-------|
| 1 | **P0** | Implementar schema `atlas.dual_core.route_decision.v1` (classe + tabela mínima) | 4-6h |
| 2 | **P0** | Implementar `atlas.dev_to_forge.escalation_packet.v1`; substituir `forge_promotion_preview.v1` | 6-8h |
| 3 | **P1** | Consolidar 4 mecanismos de escalação em path único (recomendado: Kernel `AtlasForgeHandoffAdapter` adaptado para emitir packet v1) | 12-18h |
| 4 | **P1** | Reconciliar doc status `future` em `atlas-forge-operating-system{,-contracts,-runbook}.md` | 4-6h |
| 5 | **P1** | Decisão de produto: drivers reais (claude/codex/gemini) OU explicitar Forge fixture-only | — |
| 6 | **P2** | Mover `AtlasForge*.php` para `app/Services/Ai/Programming/AtlasForge/` subdir | 6-10h |
| 7 | **P2** | Promover Atlas Dev: flipar feature flag em produção; certificar Fatia 5 Desktop | 1-2 sprints |
| 8 | **P2** | Wire Atlas AI Router Meta 6 como entrada única HTTP | depende 1-3 |
| 9 | **P3** | Bridge Rivals → completion loop (learning compounding) | 16-24h |
| 10 | **P3** | Teste E2E `tests/Feature/Ai/Programming/DualCore/EndToEndDevToForgeTest.php` | 8-12h |
| 11 | **P3** | Doc desambiguador de naming Forge (tabela de responsabilidades) | 4-6h |
| 12 | **P3** | Evidence cross-reference Dev↔Forge via `evidence_refs` | 6-8h |

## Riscos
1. **Schemas canônicos ausentes (P0)**: cada commit afasta a relação da doc.
2. **Doc status inversão Forge (P1)**: nova IA lê parent `future` e perde evidência factual.
3. **`AtlasForgeRuntimeDispatchService` ≠ canônico Meta 6 (P1)**: 2 fontes de verdade para "qual provider invocar?".
4. **Drivers reais bloqueados (P1)**: Forge é fixture-only; promessa multi-provider não realizada.
5. **Naming proliferation Forge (P2)**: 8 nomes para conceitos próximos — UX confusa.
6. **AtlasDev legacy vs efficient coexistem (P2)**: dois caminhos com mesmo prefixo de comando.
7. **Rivals fora do completion loop (P3)**: medição sem retroalimentação.
8. **Evidence Dev (JSON local) vs Forge (DB) não cruzam (P3)**: `shared_evidence` doc-only.

## Exemplos
**Trace real do gap canônico** — operador roda `atlas:cli:dev "refatora
billing engine"`:

1. `AtlasCliDevCommand` (com `--efficient`) → `AtlasDevFastPathOrchestrator`.
2. Pipeline: intake → classify → risk (R4!) → spec → route.
3. `RoutingDecisionEngine` decide `forge_promotion_preview` (R4 ≥ R4).
4. `ForgePromotionPreviewBuilder` emite schema local
   `atlas.dev.forge_promotion_preview.v1` (NÃO o canônico
   `atlas.dev_to_forge.escalation_packet.v1`).
5. Preview vai para Attention queue; operador aprova → HTTP
   `/dev-to-forge/threads/{thread}/promote` → `DevToForgePromotionService`
   (thread+workspace, não run_id).
6. Forge cria Obra → WorkItem → `AtlasForgeHandoffAdapter` emite
   `AiDomainHandoff` (terceiro schema diferente).
7. **Schema canônico `route_decision.v1` e `escalation_packet.v1` nunca
   persistidos.**

Resultado: relação funciona ad-hoc, auditável apenas via 4 tabelas/artifacts.
Sem 1 query única respondendo "por que foi rota dev_to_forge?".

## Proximas Acoes
**Ordem recomendada (próximas 3 sprints):**

**Sprint 1 (P0 — destravar fundação):**
1. Implementar `atlas.dual_core.route_decision.v1`
   (item 1 da tabela §Evidencias #13).
2. Implementar `atlas.dev_to_forge.escalation_packet.v1` (item 2).
3. Teste de smoke: `atlas:dual-core:route-decision:smoke`.

**Sprint 2 (P1 — consolidar e clarear):**
4. Consolidar 4 mecanismos paralelos em 1 (item 3).
5. Reconciliar doc status Forge parents (item 4).
6. Decisão de produto sobre drivers reais (item 5).

**Sprint 3 (P2 — promover e organizar):**
7. Mover AtlasForge* para subdir (item 6).
8. Promover Atlas Dev em produção (item 7).
9. Wire Atlas AI Router como entrada única HTTP (item 8).

**Backlog (P3):**
10. Rivals → completion loop (item 9).
11. E2E canônico (item 10).
12. Doc desambiguador (item 11).
13. Evidence cross-reference (item 12).

**Gates de fechamento (2026-05-18):**
- `php artisan atlas:engineering:knowledge docs-health --json` → confirmar 0
  violations, este doc abaixo do limite.
- `git diff --check` no atlas-server.
- Nenhuma doc canônica alterada por este relatório.
