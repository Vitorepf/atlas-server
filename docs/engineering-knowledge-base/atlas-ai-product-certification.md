---
id: atlas-ai-product-certification
type: engineering_knowledge
title: Atlas AI Product Certification
status: active
category: certification
priority: 92
summary: Certificacao end-to-end do produto Atlas AI atravessando Mobile, Desktop, Server, Forge, Control Plane, Agent Control Plane, governanca externa, Company Runtime e capability evolution.
tags:
  - atlas-ai
  - certification
  - product
capabilities:
  - product_certification
decisions:
  - Product certification prova plumbing e runtime governance interno, nao superioridade ou benchmark.
  - A cert deve cobrir a meta macro atual: Desktop UX operacional, Agent Control Plane como base de subagentes/metagentes, execucao externa governada, empresa autonoma interna e capabilities usadas/melhoradas por outcome.
  - AP-695 e o contrato de auditoria da meta macro; esta doc e a especificacao operacional da cert.
maintenance:
  - Atualizar quando checks de produto, rich input, presentation contract, Control Plane, Company Runtime ou capability evolution mudarem.
related_paths:
  - docs/ap/AP-695-product-runtime-governance-certification-contract.md
  - docs/engineering-knowledge-base/atlas-ai-runtime-readiness.md
  - docs/engineering-knowledge-base/atlas-ai-evolution-lineage-and-target-state.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - docs/engineering-knowledge-base/atlas-intelligence-factory-os.md
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-product-certification
graph_title: Atlas AI Product Certification
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-ai-canonical-architecture-index
graph_status: active
graph_source: repo
human_name: Atlas AI Product Certification
canonical_name: Atlas AI Product Certification
technical_name: atlas-ai-product-certification
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-ai-product-certification.md
owner: product-certification
repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-product-certification.md
allowed_changes:
  - Atualizar checks quando surfaces ou contracts mudarem.
forbidden_changes:
  - Declarar benchmark ou superioridade a partir desta cert.
depends_on:
  - atlas-ai-runtime-readiness
flows_to:
  - atlas-code
unlocks:
  - product-certification
governs:
  - product-runtime-readiness
evidence:
  - docs/engineering-knowledge-base/atlas-ai-product-certification.md
required_tests:
  - "php artisan atlas:ai:product-certify --json"
requires_evidence: true
risk_level: medium
next_actions:
  - Manter checks de plumbing sincronizados.
---
# Atlas AI · Product Certification

## Resumo

Certifica o caminho de produto end-to-end sem claim de superioridade.

## Papel no Atlas

Provar plumbing entre surfaces, rich input, Hyperflow, Forge e presentation
contract, alem de provar que a meta macro atual tem evidence de produto:
Desktop Control Plane, Agent Control Plane, external execution governance,
autonomous company runtime interno e capability usage/evolution loop.

## Onde Se Encaixa

Dentro da readiness macro do runtime Atlas AI.

## Contratos

Checks critical precisam passar para status ready.

Contrato AP: `docs/ap/AP-695-product-runtime-governance-certification-contract.md`.

## Fluxo

Executa service/command de certificacao e retorna hash deterministico.

## Regras para IA

Nao usar este certificado como benchmark externo.

## Escopo de Implementacao

Certificacao de produto, runtime governance e evidence refs.

## Dependencias

Mobile, Desktop, Server, Forge, Hyperflow, rich input canon, Control Plane,
Agent Control Plane, AEMOR, Intelligence Factory e Engineering Company Runtime.

## Evidencias

Command JSON, tests e certification_hash.

## Riscos

Confundir product runtime governance ready com qualidade comparativa externa.

## Exemplos

`php artisan atlas:ai:product-certify --json --strict`.

## Proximas Acoes

Atualizar a lista de checks quando surfaces mudarem.

> Schema: `atlas.ai.product_certification.v1`
> Service: `App\Services\Ai\Product\AtlasAiProductCertificationService`
> Command: `php artisan atlas:ai:product-certify`
> Test: `tests/Feature/Ai/Product/AtlasAiProductCertificationServiceTest.php`
> Status: introduced 2026-05-19

## O que esta certificação cobre

É a **cert de produto end-to-end do Atlas AI**: prova que o produto está
montado canônicamente atravessando as superfícies e runtimes relevantes
(Mobile, Desktop, Server, Forge, Control Plane, Agent Control Plane, AEMOR,
Intelligence Factory e Engineering Company Runtime) com os contratos certos.

A cert NÃO é uma claim de superioridade nem um benchmark. Ela certifica
plumbing e runtime governance interno:

```
Atlas AI Surface
  → Universal Composer (@atlas/rich-input-canon)
    → atlas.rich_input.payload.v1
      → POST /ai/interactions
        → AtlasHyperflowEntryService (5 stages)
          → Router · Intent Kernel · Specialist Flow
            → atlas_dev / atlas_forge / atlas_research / etc handoff
              → Presentation Contract (corpo limpo)
              → Context · Trace · Audit (técnico)
                → Control Plane runtime governance
                  → Agent Control Plane / Agentic Workcell / Company Runtime / capability evolution
                    / external governance
```

está intacto. A certificação também emite scorecards versionados para execução
assistida e workforce agentivo:

- `atlas.ai.assisted_execution_scorecard.v1`
- `atlas.ai.agentic_workforce_scorecard.v1`

### 27 checks canônicos (cada um carrega evidence: paths + booleans)

| # | id | severity | invariante |
|---|---|---|---|
| 1 | `hyperflow_v2_entry` | critical | `AtlasHyperflowEntryService` existe com pipeline 5-stage (IntentKernel/DomainRouter/FlowRouter/RuntimeDispatch/DecisionReceipt) |
| 2 | `ai_interactions_preserves_rich_input` | critical | `AiInteractionController` extrai `rich_input_payload`, merge no `payload`, passa pro Hyperflow **antes** de rodar; `StoreAiInteractionRequest` valida schema + shape canônica |
| 3 | `universal_composer_canon_present` | critical | `packages/atlas-rich-input-canon` existe; `ATLAS_RICH_INPUT_PAYLOAD_SCHEMA='atlas.rich_input.payload.v1'`; builders e tipos exportados |
| 4 | `mobile_uses_canon` | critical | `atlas-app/lib/richInput/*` re-exporta `@atlas/rich-input-canon`; `lib/api/client.ts` usa builder + envia rich_input_payload para `/ai/interactions` |
| 5 | `desktop_uses_canon` | critical | `atlas-desktop` barrel re-exporta canon; `useHyperflowRuntime` hook presente |
| 6 | `forge_accepts_canon` | critical | `ForgeIntakeService::normalizeRichInputPayload` defaults schema `atlas.rich_input.payload.v1`; `AtlasCodeWorkController` aceita rich_input; `forge_work_intake_ready=false` por default (não liga só pelo anexo) |
| 7 | `presentation_contract` | critical | `projectPresentation` existe em mobile (`atlas-app/lib/atlasAi/`) e desktop (`atlas-desktop/.../surfaces/atlas-ai/`); ambos separam `SOURCE_REFS`/`UNCERTAINTY` em `metadata.sections`; **wired** no Turn Model (mobile) e Conversation (desktop) |
| 8 | `context_trace_audit_consumes_technical_data` | critical | `AtlasAiContextPanel` (desktop) consome view-model do Hyperflow; `AtlasAiResponseAudit` consome `Record<string, string[]>`; `AtlasAiContextSheet` (mobile) presente |
| 9 | `specialist_flows_registered` | critical | `FlowRouterService` + `AtlasHyperflowSpecialistFlowsReadinessService` presentes; `RouterRuntimeCanon` declara os specialist flow_ids |
| 10 | `routing_anti_regression_tests_present` | critical | `AtlasAiDesktopHyperflowAntiRegressionTest` cobre: research ≠ programming.dev, finance ≠ atlas_dev, programming → atlas_dev, Obra → atlas_forge; `AtlasAiInteractionHyperflowEntryTest` cobre 6+ cenários E2E reais via gateway mock |
| 11 | `forge_strips_raw_text_to_hash_and_derives_context_refs` | critical | `ForgeIntakeService` faz `sha256` do conteúdo de URL/text_block e deriva `context_refs` a partir do `source_manifest` |
| 12 | `no_attachment_path_still_works` | **warn** | testes desktop cobrem fluxo sem anexo (não-blocker) |
| 13 | `desktop_control_plane_runtime_governance_ux` | critical | Desktop Control Plane renderiza runtime governance: unsafe external execution, receipt gaps, signature coverage e policy blocked-by-default/manual-handoff |
| 14 | `agent_control_plane_runtime_standard` | critical | Agent Control Plane possui task packets, claim/leases, multi-agent loop certification, context/evidence receipts e runtime safety flags sem dispatch livre |
| 15 | `agentic_workcell_runtime` | critical | AAWR/Atlas Cognitive Workcell emite roster operacional, topologia, task graph, context packs, schedule, verification, outcome learning, org patterns, control-plane e comandos |
| 16 | `governed_external_execution_control_plane` | critical | Control Plane rastreia unsafe execution, receipt bindings, pending approval como operator queue e bloqueia runtime status quando execução externa insegura aparece |
| 17 | `internal_autonomous_company_runtime_claim_gate` | critical | Engineering Company Runtime só permite claim interna quando roles têm agent task packets; external superiority e benchmark continuam bloqueados |
| 18 | `capability_usage_and_evolution_loop` | critical | Control Plane, AEMOR e Intelligence Factory conectam capability used events, outcome, evolution candidates e certification registry |
| 19 | `code_intelligence_automatic_gate` | critical | Code Intelligence vira gate automático antes de contexto de programação pesado |
| 20 | `verified_context_execution_loop` | critical | AVCEL valida contexto antes da execução e produz loop/certificado sem provider |
| 21 | `assisted_execution_quality` | critical | AAEQ envelope conecta AEDPDS, AUCRI/ACMF, AREG e feedback AEMOR |
| 22 | `execution_doctrine_product_delivery_system` | critical | AEDPDS/APDR runtime, gate, Dev/Forge, receipts e outcome memory certificados |
| 23 | `context_memory_quality` | critical | AUCRI/ACMF certifica qualidade de contexto, 18 blocos, corpus e memory fabric |
| 24 | `runtime_efficiency_governor` | critical | AREG governa fast/standard/forge/blocked paths, budgets, outcomes e replay |
| 25 | `aemor_runtime` | critical | AEMOR registra episódios, outcomes, judgment, replay e aprendizado com evidência |
| 26 | `runtime_ux_operational` | critical | Runtime UX expõe AEDPDS, contexto, AREG e AEMOR sem vazar prompt cru |
| 27 | `autonomous_evolution_loop` | critical | AAEL integra bridge de execução assistida, promoção governada e claim policy |

## Como rodar

```bash
# Human view (default)
php artisan atlas:ai:product-certify

# Canonical JSON envelope (para receipts/audit)
php artisan atlas:ai:product-certify --json

# Strict mode: exit 1 se status ≠ ready (CI gate)
php artisan atlas:ai:product-certify --json --strict
```

Ou via teste:

```bash
vendor/bin/phpunit tests/Feature/Ai/Product/
```

## Semântica de status

| status | quando | exit em `--strict` |
|---|---|---|
| `ready`   | todos os 27 checks `passed` | 0 |
| `partial` | algum check `warn` falhou, mas nenhum `critical` | 1 |
| `blocked` | algum check `critical` falhou | 1 |

`certification_hash` = `sha256(canonical_json(payload \ generated_at))` — o mesmo estado de árvore produz hash idêntico (replay-friendly).

## E2E scenarios de roteamento

Esta cert **não roda** cenários — ela aponta para os testes que rodam, em `evidence_refs[]`:

| Cenário | Teste autoritativo |
|---|---|
| Prompt ambíguo (`auto/auto`) | `AtlasAiInteractionHyperflowEntryTest` (várias) |
| Research | `AtlasAiInteractionHyperflowEntryTest::test_research_intent_emits_atlas_research_with_evidence_required` |
| Finance | `AtlasAiInteractionHyperflowEntryTest::test_finance_intent_emits_atlas_plan_with_policy_and_evidence_required` |
| Marketing | `AtlasAiInteractionHyperflowEntryTest::test_marketing_intent_emits_atlas_plan_with_policy_required` |
| Programming + workspace | `AtlasAiInteractionHyperflowEntryTest::test_programming_intent_with_workspace_emits_atlas_dev_hyperflow_envelope` |
| Obra ambíguo → Forge | `AtlasAiInteractionHyperflowEntryTest::test_atlas_code_obra_ambiguous_prompt_uses_surface_contract_for_forge_hyperflow` |
| Desktop programming explícito | `AtlasAiInteractionHyperflowEntryTest::test_desktop_ai_explicit_programming_bad_prompt_routes_to_programming_handoff` |
| URL/YouTube + rich_input_payload preservado | `AtlasAiInteractionHyperflowEntryTest::test_rich_input_payload_is_preserved_for_hyperflow_gateway_options` |
| Composer → request → view-model → presentation → audit (desktop) | `atlas-desktop/.../desktopRuntimeFinalCertification.test.ts` |
| Resposta contaminada com `SOURCE_REFS`/`UNCERTAINTY` | `atlas-desktop/.../presentationContract.test.ts` |
| source_manifest → context_refs, raw text → hash | `tests/Feature/AtlasCode/AtlasCodeWorkRichInputTest.php` + `ForgeIntakeServiceTest.php` |

## O que fica de fora (escopo explícito)

- **Benchmark / rivals / external_rivals_certification** — esta cert NÃO destrava nem chama `atlas:forge:rivals` nem `external_rivals_certification`. `claims.runs_rivals = false`, sempre.
- **Provider invocation** — cert nunca chama Claude/Codex/Gemini. `claims.invokes_provider = false`.
- **Claim de superioridade** — esta cert NÃO afirma "Atlas substitui Claude Code/Codex". `claims.declares_superiority = false`. (Para a claim de substituição, ver `AtlasAiHyperflowCertificationService` — outra cert, com gate externo dedicado.)
- **TEOS** — `claims.declares_teos = false`.
- **Persistência** — cert é read-model puro. `writes = false`.

## Meta macro coberta

Esta certificação agora cobre a fase atual da evolução do Atlas:

- Desktop UX operacional via Control Plane runtime governance;
- Agent Control Plane como base governada para subagentes/metagentes;
- Agentic Workcell como roster operacional/topologias/organização agentiva;
- execução externa governada, sem free-run;
- empresa autônoma interna via Engineering Company Runtime;
- capabilities realmente usadas e melhoradas por AEMOR/Intelligence Factory;
- benchmark/rivals não executados.

Ela ainda não declara a meta global completa sozinha. Ela é o gate de produto
que obriga esses pontos a ficarem visíveis e auditáveis.
