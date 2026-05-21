---
id: atlas-dev-efficient-programming-flow-runbook-v1-part-05
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow Runbook v1 · Parte 5
status: active
category: programming
priority: 104
summary: Recorte focado do runbook Atlas Dev Efficient Programming Flow v1: 9.2 PRs Sugeridos ate 10.1 Objetivo.
tags:
  - atlas-dev
  - efficient-programming-flow
  - runbook
  - split-doc
capabilities:
  - atlas_dev_implementation_runbook
  - atlas_dev_efficient_programming_flow
decisions:
  - Este recorte preserva uma parte operacional do runbook sem ampliar responsabilidade do indice canônico.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar junto com docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md quando o runbook Atlas Dev mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-efficient-programming-flow-runbook-v1-part-05
graph_title: Atlas Dev Efficient Programming Flow Runbook v1 Parte 5
graph_world: atlas
graph_layer: module
graph_kind: runbook
graph_parent: atlas-dev-efficient-programming-flow-runbook-v1
graph_status: active
graph_source: repo
human_name: Atlas Dev Efficient Programming Flow Runbook v1 Parte 5
canonical_name: Atlas Dev Efficient Programming Flow Runbook v1 Parte 5
technical_name: atlas-dev-efficient-programming-flow-runbook-v1-part-05
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-05.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-05.md
allowed_changes:
  - Atualizar somente a parte operacional descrita neste recorte.
forbidden_changes:
  - Adicionar nova responsabilidade que pertença ao índice ou a outro recorte.
depends_on:
  - atlas-dev-efficient-programming-flow-runbook-v1
flows_to:
  - atlas_dev_efficient_flow_runtime
unlocks:
  - atlas_dev_operational_execution
governs:
  - atlas_dev.implementation.slices
evidence:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Manter este recorte alinhado ao índice canônico e ao runbook operacional.
---
# Atlas Dev Efficient Programming Flow Runbook v1 · Parte 5

## Resumo

Este recorte preserva uma parte operacional do runbook Atlas Dev Efficient Programming Flow v1: 9.2 PRs Sugeridos ate 10.1 Objetivo.

## Papel no Atlas

Mantém o detalhe executável fora do índice principal para que a cartografia e o modal humano continuem legíveis.

## Onde Se Encaixa

É filho canônico de `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md` e deve ser lido apenas quando a pessoa precisar do detalhe desta fatia.

## Contratos

Segue o contrato do runbook principal, o glossário canônico e o modelo obrigatório de documentação do Atlas.

## Fluxo

Índice canônico → recorte focado → execução ou revisão da fatia correspondente.

## Regras para IA

Não inferir responsabilidade nova. Não misturar patamar, versão, fonte, risco, regra ou prova. Preservar backlink para o índice.

## Escopo de Implementacao

Este arquivo só guarda o detalhe operacional extraído do runbook maior.

## Dependencias

Depende do índice `atlas-dev-efficient-programming-flow-runbook-v1` e da documentação canônica relacionada.

## Evidencias

A evidência de origem é o runbook principal e o `docs-health` verde depois da divisão.

## Riscos

Risco principal: alguém editar este recorte como se fosse novo dono de fluxo, duplicando contrato.

## Exemplos

Os exemplos abaixo são o conteúdo operacional extraído, preservado sem perda semântica.

## Proximas Acoes

Atualizar este recorte quando a fatia correspondente do Atlas Dev mudar e rodar docs-health.

## Conteudo Extraido
### 9.2 PRs Sugeridos

#### PR 2.1 — Intake e Classifier

Arquivos:

```text
app/Services/Ai/Programming/AtlasDev/Pipeline/IntakeNormalizer.php
app/Services/Ai/Programming/AtlasDev/Pipeline/TaskClassifier.php
app/Services/Ai/Programming/AtlasDev/Pipeline/RiskLevelScorer.php
```

Signatures:

```php
final class IntakeNormalizer
{
    public function normalize(
        string $surfaceId,
        string $workspace,
        string $rawIntent,
        array $userConstraints = [],
    ): OperationEnvelope;
}

final class TaskClassifier
{
    public function classify(OperationEnvelope $envelope): TaskClassification;  // task_kind + intent_clarity_level
}

final class RiskLevelScorer
{
    public function score(
        OperationEnvelope $envelope,
        TaskClassification $classification,
        ?CodeDiscoveryManifest $discovery = null,
    ): string;  // R0..R5
}
```

Regras do classificador:

- Consultar placement canonico quando service disponivel (AtlasFeaturePlacement/Programming Placement). Se placement canonico divergir da heuristica, marcar `intent_clarity_level=low`, registrar `placement_conflict` e exigir confirmacao humana antes de write.
- `task_kind = question` se intent comeca com explicar/o que/onde/por que;
- `task_kind = repair` se intent contem corrija/fix/teste falhando/bug;
- `task_kind = patch` para mudanca local default;
- `task_kind = review` se intent contem revise/review;
- `task_kind = frontend` se intent contem tela/screenshot/UI/componente + surface frontend;
- `task_kind = risky` se intent contem auth/billing/migration/secret/production.

Regras do risk scorer (heuristica observavel):

- R5 se risky + multiagente/replay/audit;
- R4 se risky OU file count esperado > 5 OU >3 camadas;
- R3 se patch/repair + multi-arquivo;
- R2 se patch/repair + 1-2 arquivos;
- R1 se typo/docs/1 arquivo reversivel;
- R0 se question.

DoD do PR 2.1:

- [x] 6 cenarios de classification cobertos (1 por task_kind).
- [x] 6 cenarios de risk scoring cobertos (1 por R-level).
- [x] Edge case: intent ambiguo -> `intent_clarity_level = low` ou `blocking`.
- [x] Edge case: placement canonico diverge da heuristica -> `placement_conflict` + `intent_clarity_level=low`.

#### PR 2.2 — Spec Composer

Arquivo:

```text
app/Services/Ai/Programming/AtlasDev/Pipeline/SpecComposer.php
```

Signature:

```php
final class SpecComposer
{
    public function composeCompactSdd(
        OperationEnvelope $envelope,
        TaskClassification $classification,
        string $riskLevel,
    ): CompactSdd;

    public function composeMiniSpec(
        OperationEnvelope $envelope,
        CompactSdd $compactSdd,
        CodeDiscoveryManifest $discovery,
        OpenBrainProgrammingProjection $projection,
    ): MiniProgrammingSpec;

    public function composeTaskContract(
        OperationEnvelope $envelope,
        CompactSdd $compactSdd,
        MiniProgrammingSpec $miniSpec,
    ): LightTaskContract;
}
```

Regras:

- `composeCompactSdd` deriva budget chars da tabela 11.2;
- `composeMiniSpec` consome discovery para `expected_files`, `canonical_context`, `verification_plan.commands`;
- `composeTaskContract` herda allowed/forbidden de mini_spec, deriva `max_files_changed` por R-level (R1=1, R2=2, R3=5, etc.).

DoD do PR 2.2:

- [x] Composers produzem DTOs que passam validators.
- [x] Hashes calculados sao referenciados nos artefatos downstream (compact_sdd_hash em mini_spec, etc.).
- [x] Idempotencia: mesma entrada produz mesmo output byte-a-byte.

#### PR 2.3 — Pipeline Orchestrator (Plan-Only)

Arquivo:

```text
app/Services/Ai/Programming/AtlasDev/Pipeline/AtlasDevFastPathOrchestrator.php
```

Signature:

```php
final class AtlasDevFastPathOrchestrator
{
    public function planOnly(
        string $surfaceId,
        string $workspace,
        string $rawIntent,
        array $userConstraints = [],
    ): PlanOnlyResult;
}

final class PlanOnlyResult
{
    public function __construct(
        public readonly OperationEnvelope $envelope,
        public readonly CompactSdd $compactSdd,
        public readonly MiniProgrammingSpec $miniSpec,
        public readonly LightTaskContract $taskContract,
        public readonly CodeDiscoveryManifest $discovery,
        public readonly OpenBrainProgrammingProjection $projection,
        public readonly ContextRetrievalPlan $contextPlan,
        public readonly ProviderPromptProjection $promptProjection,
        public readonly string $routingDecision,  // read_only_answer | read_only_answer_no_provider | atlas_dev_fast_path | forge_promotion_preview | delegate_to_other_flow | blocked
        public readonly ?string $suggestedFlow,    // populado quando routingDecision=delegate_to_other_flow (ex: atlas_research, atlas_explain, atlas_debug)
        public readonly array $blockers,
    ) {}
}
```

Algoritmo:

1. IntakeNormalizer -> OperationEnvelope;
2. TaskClassifier -> classification;
3. RiskLevelScorer (sem discovery) -> risk preliminar;
4. SpecComposer.composeCompactSdd -> CompactSdd preliminar;
5. DocContextTierSelector -> ContextRetrievalPlan;
6. CodeDiscoveryEngine -> CodeDiscoveryManifest;
7. RiskLevelScorer (com discovery) -> risk final;
8. CompactSdd atualizado se risk mudou;
9. OpenBrainProjectionAdapter -> OpenBrainProgrammingProjection;
10. **Atalho read-only otimizado (Gap E)**: se `task_kind=question` + `discovery.confidence=confirmed_fact` + zero ambiguidade no normalized_intent, **resolve direto via CodeDiscoveryManifest sem chamar provider**. Retorna PlanOnlyResult com `routing_decision=read_only_answer_no_provider`, `cost.provider_calls=0`, e payload formatado pra surface;
11. **Atalho delegate (R-5)**: se `command_intent in (explain, research, debug standalone, conversation)` E `flow_origin=atlas_ai_router`, retorna PlanOnlyResult com `routing_decision=delegate_to_other_flow` + `suggested_flow`. Atlas Dev nao processa fora de desenvolvimento em workspace;
12. SpecComposer.composeMiniSpec -> MiniProgrammingSpec;
13. SpecComposer.composeTaskContract -> LightTaskContract;
14. ProviderPromptBuilder.build -> ProviderPromptProjection;
15. RoutingDecision baseado em risco, intent_clarity, discovery.confidence, command_intent;
16. Persistir tudo em `storage/atlas-dev/receipts/<run_id>/`;
17. retornar `PlanOnlyResult`.

DoD do PR 2.3:

- [x] Cenario "repair R2 com simbolo claro" -> `routing_decision = atlas_dev_fast_path`.
- [x] Cenario "task ambigua" -> `routing_decision = read_only_answer` ou blockers populados.
- [x] Cenario "task R4" -> `routing_decision = forge_promotion_preview`.
- [x] Cenario "task_kind=question com Discovery confidence=confirmed_fact + zero ambiguidade" -> resposta resolvida via `CodeDiscoveryManifest` sem chamar provider (Gap E: read-only otimizado). `cost.provider_calls = 0`.
- [x] Cenario "intent_clarity_level=blocking OU discovery.confidence=blocking_ambiguity" -> `routing_decision = blocked` com pergunta de esclarecimento (delegado a Atlas Conversation/Explain quando Atlas AI Router ativar).
- [x] Cenario "command_intent=explain OU debug OU research vindo do Atlas AI Router" -> `routing_decision = delegate_to_other_flow` retornando flow sugerido; Atlas Dev nao processa fora de desenvolvimento em workspace.
- [x] Todos os artefatos persistidos.
- [x] Feature test `tests/Feature/AtlasDev/EndToEndPlanOnlyTest.php` cobrindo 6 cenarios.

#### PR 2.4 — CLI parity via `atlas:cli:dev --efficient`

Arquivo:

```text
app/Console/Commands/AtlasCliDevCommand.php
app/Services/Ai/Cli/AtlasCliDevEfficientHandler.php
```

Signature relevante:

```php
atlas:cli:dev {task?}
  --efficient
  --yes
  --flow-origin=
  --command-intent=
  --json
```

Comportamento:

- `--efficient` chama o mesmo pipeline Atlas Dev Efficient usado pelo Desktop;
- sem `--yes`, imprime plan-only e para antes do provider;
- com `--yes`, consome `confirmation_token` e executa run confirmado;
- output humano por default; output JSON se `--json`;
- comando tecnico oculto `atlas:dev:debug:smoke` existe apenas para smoke local/debug e nao e contrato publico de CLI.

DoD do PR 2.4:

- [x] `php artisan atlas:cli:dev "corrija teste X" --efficient --json` retorna JSON valido sem provider call.
- [x] `php artisan atlas:cli:dev "corrija teste X" --efficient --yes --json` consome token e invoca executor.
- [x] Comando registrado no console existente.
- [x] Help (`php artisan atlas:cli:dev --help`) documenta flags `--efficient`, `--yes`, `--flow-origin`, `--command-intent`.

### 9.3 DoD Operacional Da Fatia 2

- `composer test --filter=AtlasDev/Pipeline` + `tests/Feature/AtlasDev/EndToEndPlanOnlyTest.php` verdes.
- Comando `atlas:cli:dev --efficient` rodando em workspace real (`atlas-server`) produz artefatos validos.
- Artefatos persistidos em `storage/atlas-dev/receipts/<run_id>/`.
- Telemetria emitida com `completion_state = no_patch_needed` (plan-only nao escreve).
- **Sem provider call. Sem patch.**

### 9.4 Marco 2 — Plan-Only Visivel No Atlas AI Desktop

Depois do DoD tecnico da Fatia 2, expor plan-only na surface primaria:

Backend:

```text
POST /ai/interactions/atlas-dev/plan
```

A rota deve ser registrada antes de `/ai/interactions/{trace}` em `routes/api.php`. Autenticacao usa o mesmo `X-Atlas-Token` do Atlas AI Desktop.

Request minimo:

```json
{
  "surface_id": "atlas_desktop_ai",
  "workspace": "atlas-server",
  "raw_intent": "texto do composer",
  "user_constraints": [],
  "policy_hints": {
    "open_brain_mode": "auto",
    "decision_mode": "atlas_decide",
    "task_kind_override": null
  },
  "surface_context": {
    "composer_mode": "programming",
    "composer_task": "dev",
    "provider_choice": null
  },
  "thread_id": null,
  "previous_run_id": null
}
```

No Desktop, `workspace` pode ser o slug do Projeto selecionado. O `PlanController`
resolve esse slug via `config/atlas_projects.php` para `workspace_path` real antes
de chamar o core. `policy_hints` fica no topo do body; o backend nao le
`policy_hints` dentro de `surface_context`.

Response:

```text
PlanOnlyResult canonical artifacts
+ ui_hints.panel_contexto
+ ui_hints.panel_plano
+ ui_hints.inline_indicators
```

Frontend:

| Path | Mudanca |
| --- | --- |
| `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/contract.ts` | incluir runtime policy `atlas_dev_efficient.plan_enabled` |
| `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/client.ts` | adicionar client `createAtlasDevPlan()` |
| `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/components/AtlasAiContextPanel.tsx` | renderizar `ui_hints.panel_contexto` |
| `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/components/AtlasAiPlanPanel.tsx` | renderizar `ui_hints.panel_plano` |

DoD do Marco 2:

- [x] Desktop envia intent programming para `/ai/interactions/atlas-dev/plan`.
- [x] Plano e Contexto aparecem antes de qualquer provider call.
- [x] `ui_hints` e apenas projecao; nenhuma decisao depende dele.
- [x] Modos Geral/Ops continuam usando `/ai/interactions` normal.

---

## 10. Fatia 3 — One-Call Sonnet + Receipts

### 10.1 Objetivo

Habilitar **uma chamada real** ao Sonnet via `claude_cli`, com scope guard determinístico, verification gate proporcional e VerificationReceipt completo.

