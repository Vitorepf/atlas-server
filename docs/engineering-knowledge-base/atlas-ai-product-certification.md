# Atlas AI · Product Certification

> Schema: `atlas.ai.product_certification.v1`
> Service: `App\Services\Ai\Product\AtlasAiProductCertificationService`
> Command: `php artisan atlas:ai:product-certify`
> Test: `tests/Feature/Ai/Product/AtlasAiProductCertificationServiceTest.php`
> Status: introduced 2026-05-19

## O que esta certificação cobre

É a **cert de produto end-to-end do Atlas AI**: prova que o produto está montado canônicamente atravessando todas as superfícies (Mobile, Desktop, Server, Forge) com os contratos certos.

A cert NÃO é uma claim de superioridade nem um benchmark — é uma cert de *plumbing*: o caminho

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
```

está intacto.

### 12 checks canônicos (cada um carrega evidence: paths + booleans)

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
| `ready`   | todos os 12 checks `passed` | 0 |
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
