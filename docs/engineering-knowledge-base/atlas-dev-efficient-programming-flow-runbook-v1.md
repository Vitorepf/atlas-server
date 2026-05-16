---
id: atlas-dev-efficient-programming-flow-runbook-v1
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow Runbook v1
status: active
category: programming
priority: 105
summary: Runbook de implementacao do Atlas Dev Efficient Programming Flow. Sequencia de fatias com paths absolutos, signatures, fixtures, DoD operacional e ordem dentro da fatia. Doc filho do contrato principal e do contracts. Nao contem regras de medicao, benchmark, oraculos ou Rivals — sao trabalho de outra equipe.
tags:
  - atlas-dev
  - efficient-programming-flow
  - runbook
  - implementation
  - slices
  - dod
capabilities:
  - atlas_dev_implementation_runbook
  - fatia_0_schemas
  - fatia_1_context_layer
  - fatia_1_5_runtime_quality_foundations
  - fatia_2_plan_only_pipeline
  - fatia_3_one_call_receipts
  - fatia_4_repair_loop
  - fatia_5_surface_wireup
decisions:
  - Implementacao segue ordem rigida 0 -> 1 -> 1.5 -> 2 -> 3 -> 4 -> 5. Fatia nao comeca sem DoD da anterior verde.
  - Cada fatia tem DoD operacional verificavel por testes mecanicos.
  - Reuso obrigatorio dos services existentes (AtlasCliDevWorkflowService, AtlasProgrammingOrchestrator, KernelPipelineDevPlanBuilder, etc.). Nao criar paralelos.
  - Persistencia local em `storage/atlas-dev/receipts/<run_id>/` ate Fatia 5.
  - Provider lock `claude_cli` + Sonnet sem fallback durante todo o fluxo.
  - Esta equipe constroi ate Fatia 5; outra equipe assume medicao/Rivals.
  - Surface inicial completa e Atlas AI Desktop Mac (`surface_id=atlas_desktop_ai`); CLI/App/API entram como paridade depois.
maintenance:
  - Atualize este runbook quando ordem de fatias mudar, quando uma fatia ganhar/perder PR, ou quando DoD mudar.
  - Nao adicione passos de medicao competitiva, baterias, oraculos ou Rivals.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-index.md
  - docs/engineering-knowledge-base/atlas-dev-glossary.md
  - docs/engineering-knowledge-base/atlas-dev-policy.md
  - docs/engineering-knowledge-base/atlas-dev-patamares.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - ../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/AtlasAiSurface.tsx
  - ../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/contract.ts
  - ../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/client.ts
  - app/Services/Ai/Surface/Adapters/AtlasDesktopAiSurfaceAdapter.php
  - app/Services/Ai/Programming/AtlasDevRuntimeService.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-efficient-programming-flow-runbook-v1
graph_title: Atlas Dev Efficient Programming Flow Runbook v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-dev-efficient-programming-flow-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
allowed_changes:
  - Adicionar PR novo dentro de fatia existente, com paths, signatures e DoD.
  - Detalhar fixtures e edge cases conforme implementacao avance.
forbidden_changes:
  - Pular fatia ou pular DoD.
  - Criar fatia de medicao/benchmark/Rivals/Opus challenge.
  - Inserir prompt artesanal em qualquer fatia; prompt vem de ProviderPromptProjection.
depends_on:
  - atlas-dev-efficient-programming-flow-v1
  - atlas-dev-efficient-programming-flow-contracts-v1
flows_to:
  - atlas_cli_dev
  - atlas_ai_chat
  - atlas_desktop_ai
  - atlas_app
unlocks:
  - atlas_dev_efficient_flow_runtime
governs:
  - atlas_dev.implementation.slices
  - atlas_dev.implementation.dod
evidence:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md
next_actions:
  - Comecar Fatia 0 (schemas DTOs read-only) com PR 0.1.
required_tests:
  - "php artisan test tests/Unit/Ai/Programming/AtlasDev"
  - "php artisan test tests/Feature/AtlasDev"
requires_evidence: true
risk_level: high
line_limit: 2150
---
# Atlas Dev Efficient Programming Flow Runbook v1

## Resumo

Este documento e o runbook de implementacao do Atlas Dev Efficient Programming Flow.

## Papel no Atlas

Traduz o contrato principal e o anexo de schemas em fatias implementaveis com DoD verificavel.

## Onde Se Encaixa

Fica como doc filho do contrato principal e dos contratos de schema, guiando execucao por PR/fatia.

## Contratos

Respeita os contratos de schema, hash, provider prompt projection, verification receipt e escalation decision.

## Fluxo

Executa Fatia 0 -> 1 -> 1.5 -> 2 -> 3 -> 4 -> 5, sem pular DoD.

## Regras para IA

IA deve seguir a ordem de fatias, preservar worktree, reutilizar services existentes e nao introduzir benchmark/Rivals neste fluxo.

## Escopo de Implementacao

Escopo: orientar implementacao do Atlas Dev Efficient Flow; nao define medicao competitiva nem substitui o contrato principal.

## Dependencias

Depende do contrato principal, do anexo de schemas, do AtlasCliDevWorkflowService e dos sistemas de contexto/programacao existentes.

## Evidencias

Evidencias esperadas: testes unitarios/feature por fatia, receipts locais e DoD operacional verde.

## Riscos

Risco principal: criar driver paralelo ou pular gates. Mitigacao: reuso obrigatorio e DoD sequencial.

## Exemplos

Os exemplos operacionais aparecem nas secoes de cada fatia abaixo.

## Proximas Acoes

Comecar pela Fatia 0, com DTOs read-only e testes de schema.

## 1. Resumo

Este runbook traduz o contrato canonico (`atlas-dev-efficient-programming-flow-v1.md`) e os schemas (`atlas-dev-efficient-programming-flow-contracts-v1.md`) em **sequencia executavel de fatias**. Cada fatia tem:

- objetivo concreto;
- arquivos a criar/editar com paths absolutos;
- signatures dos services/classes;
- ordem interna de implementacao (PRs sugeridos);
- testes obrigatorios;
- **DoD operacional** verificavel mecanicamente.

Ordem das fatias **e rigida**:

```text
Fatia 0  -> Schemas (DTOs read-only)
Fatia 1  -> Context Layer (Discovery + Tier Selector + Open Brain Projection)
Fatia 1.5 -> Runtime Quality Foundations (Prompt Projection + Telemetry + Error Ledger + Persistence)
Fatia 2  -> Plan-only Pipeline (CompactSDD + MiniSpec + LightTaskContract end-to-end, sem provider)
Fatia 3  -> One-call Sonnet + ScopeGuard + VerificationReceipt
Fatia 4  -> Repair Loop (FailureCapsule + retry policy)
Fatia 5  -> Atlas AI Desktop Mac wire-up + EscalationDecision + surface parity
```

Fatia nao comeca sem DoD anterior verde. Apos Fatia 5, esta equipe **encerra**. Medicao/Rivals e responsabilidade de outra equipe.

A surface de produto inicial e a aba `Atlas AI` do aplicativo Mac. O Atlas AI Router decide o fluxo antes do core. Quando o pedido cai neste contrato, o payload canonico usa `surface_id=atlas_desktop_ai`, `atlas_mode=programming`, `flow_id=atlas_dev`, `flow_origin=atlas_ai_router|direct`, `command_intent=fix|explain|research|test|refactor|review|debug|plan|conversation|null` e `programming_harness.workspace_required=true`.

Atlas Dev Efficient sucede o `programming.dev` classico apenas quando as flags canônicas em `config/atlas_dev.php` estiverem ligadas (`atlas_dev.efficient.plan_enabled=true` e/ou `atlas_dev.efficient.run_enabled=true`, env `ATLAS_DEV_EFFICIENT_PLAN_ENABLED` / `ATLAS_DEV_EFFICIENT_RUN_ENABLED`), workspace presente e surface suportada. Em produção, ambas iniciam `false`; ligar Plan primeiro, depois Run, conforme §15.1. Caso contrário, o fluxo `programming.dev` clássico continua.

### 1.1 Entrega Vertical Desktop-First

As fatias acima sao a ordem de dependencias de engenharia. A entrega de produto e vertical:

| Marco | Entrega | Fatias envolvidas | Valor visivel |
| --- | --- | --- | --- |
| 1 | Foundation backend | 0 + 1 + 1.5 | artefatos persistidos, sem UI |
| 2 | Plan-only no Atlas AI Desktop | 2 + adapter desktop plan | tabs Contexto/Plano preenchidas |
| 3 | One-call no Atlas AI Desktop | 3 + endpoint run + stream | diff/tests/receipt visiveis |
| 4 | Repair no Atlas AI Desktop | 4 + stream repair | attempts e failure capsule visiveis |
| 5 | Surface parity | 5 | CLI/App/API usam o mesmo core |

Desktop-first nao muda a arquitetura. O core continua surface-agnostic: recebe `OperationEnvelope` e retorna `PlanOnlyResult|PatchResult`. Apenas `AtlasDev/Surface/` conhece Desktop, CLI, App ou API.

> **Framing canonico (nao confundir):** o produto e **Atlas AI** (a aba `Atlas AI` do desktop, futuras surfaces CLI/App/API). O **core** entregue por este runbook e **Atlas Dev**, fluxo especializado de desenvolvimento em workspace dentro do Atlas AI. Atlas Dev **nao** absorve responsabilidades do **Atlas AI Router** — quem decide se um pedido vai para Atlas Dev, Research, Explain, Debug, Review, Conversation ou Forge e o Router, antes do `OperationEnvelope`. O runbook entrega o fluxo workspace-bound; o Router e construido em outro contrato e nao deve aparecer como dependencia de implementacao das Fatias 0-5.

## 2. Pre-Requisitos Globais

Antes de qualquer PR:

1. PHP 8.4+ via Homebrew (`/opt/homebrew/bin/php`) ativo em `atlas-server`.
2. `composer install` rodado.
3. Banco de dev resetado se houver migration pendente (`php artisan migrate:fresh` quando autorizado).
4. `git status` limpo no `atlas-server/` ou worktree dedicada.
5. Acesso de leitura/escrita em `atlas-server/storage/atlas-dev/`.
6. Branch atual identificada e protegida (PR contra `main`).

## 3. Reuso Obrigatorio (Mapa)

Os services abaixo **ja existem** e devem ser **evoluidos**, nunca duplicados.

| Service / artefato existente | Path | Como sera reusado |
| --- | --- | --- |
| `AtlasCliDevWorkflowService` | `app/Services/Ai/Cli/AtlasCliDevWorkflowService.php` | Driver principal do fluxo. Recebera as fases novas em metodos compostos. (Decisao locked.) |
| `AtlasProgrammingOrchestrator` | `app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php` | `sessionPlan()` continua montando `programming_orchestration_contract`. Novas fatias plugam pre/pos. |
| `KernelPipelineDevPlanBuilder` | `app/Services/Ai/Programming/KernelPipelineDevPlanBuilder.php` | Scaffold do pipeline. Novos artefatos (CompactSDD etc.) anexam ao `dev_execution_plan`. |
| `AtlasDevRuntimeService` | `app/Services/Ai/Programming/AtlasDevRuntimeService.php` | Continua aplicando runtime em payloads de surfaces. Ganha exposicao dos novos hashes. |
| `AtlasDesktopAiSurfaceAdapter` | `app/Services/Ai/Surface/Adapters/AtlasDesktopAiSurfaceAdapter.php` | Surface primaria do produto. Mapeia `atlas_desktop_ai` para flows de programacao e capacidades de contexto/workspace. |
| Atlas AI Desktop contract | `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/contract.ts` | Payload canonico da aba Atlas AI. Deve passar apenas raw intent + modo/tarefa/workspace; nao injeta prompt artesanal. |
| Skill `dev-quality-gate` | `app/Skills/DevQualityGate/...` | Continua sendo gate pos-execucao. `verification_gate` do Atlas Dev se acopla a ela. |
| `repair_execution_contract` | gerado pelo orchestrator | `FailureCapsule` formaliza o que ja flui informalmente. |
| Open Brain context injection | services em `app/Services/Ai/OpenBrain/...` | `OpenBrainProgrammingProjection` consome a saida deles. |
| Code Intelligence | services em `app/Services/Ai/CodeIntelligence/...` | `CodeDiscoveryManifest` consome a saida deles. |

Adicoes **nao** podem:

- Substituir nenhum item acima.
- Criar `AtlasDevWorkflowServiceV2` ou similar.
- Introduzir um segundo driver com mesma funcao.

## 4. Estrutura De Pastas (Locked)

Todo codigo novo do Atlas Dev Efficient Flow vive sob:

```text
atlas-server/app/Services/Ai/Programming/AtlasDev/
  Schemas/                  # DTOs read-only (Fatia 0)
    Components/             # value objects internos
    Contracts/              # interfaces comuns
  Discovery/                # Code Discovery + Tier Selector + Open Brain projection (Fatia 1)
  PromptProjection/         # Provider prompt builder (Fatia 1.5)
  Telemetry/                # FastPathTelemetry emitter + ErrorLedger writer (Fatia 1.5)
  Persistence/              # Receipt writer/reader (Fatia 1.5)
  Pipeline/                 # Orchestrador de fases (Fatia 2)
  Provider/                 # Adaptador para claude_cli locked (Fatia 3)
  Gate/                     # ScopeGuard + VerificationGate + CompletionStateGate (Fatia 3)
  Repair/                   # FailureCapsule builder + RepairOrchestrator (Fatia 4)
  Escalation/               # EscalationDecisionEngine (Fatia 5)
  Surface/                  # Adapters thin; unica pasta autorizada a conhecer Desktop/CLI/App/API
```

Testes correspondentes:

```text
atlas-server/tests/Unit/Ai/Programming/AtlasDev/
  Schemas/
  Discovery/
  PromptProjection/
  Telemetry/
  Persistence/
  Pipeline/
  Gate/
  Repair/
  Escalation/
atlas-server/tests/Feature/AtlasDev/
  EndToEndPlanOnlyTest.php   # Fatia 2
  EndToEndOneCallTest.php    # Fatia 3
  EndToEndRepairTest.php     # Fatia 4
  EndToEndSurfaceTest.php    # Fatia 5
```

## 5. Padroes De Codigo

### 5.1 DTOs (Camada Schema)

- `final class`;
- `public readonly` em todos os campos;
- construtor recebe todos os campos;
- `schemaVersion(): string` constante;
- `toCanonicalArray(): array` com chaves ordenadas alfabeticamente recursivo;
- `toJson(): string` usa `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`;
- `hash(): string` `sha256` sobre `toJson()` sem o proprio campo de hash;
- **nao** valida invariants no construtor.

### 5.2 Validators

- Vivem em `Schemas/Validators/<Dto>Validator.php`.
- Recebem o DTO + contexto (DTOs upstream) e retornam `ValidationResult` (struct: `valid: bool`, `violations: list<string>`).
- Nao lancam excecao; retornam estrutura.
- Sao puros: mesma entrada = mesma saida.

### 5.3 Persisters

- Vivem em `Persistence/<Dto>Persister.php`.
- Metodos:
  - `persist(<Dto> $dto): string` retorna path absoluto;
  - `read(string $runId): <Dto>` recupera por run;
  - `exists(string $runId): bool`.
- Path: `storage/atlas-dev/receipts/<run_id>/<artifact>.json`.
- Pretty JSON (`JSON_PRETTY_PRINT`).
- Atomic write: tmpfile + rename.

### 5.4 Services Operacionais

- Constructor injection (Laravel container).
- Sem estado mutavel (services sao stateless; estado fica nos DTOs).
- Logs estruturados via `Log::channel('atlas_dev')` (canal novo).
- Excecoes domain-specific em `AtlasDev\Exceptions\` (ex: `BlockingAmbiguityException`, `ScopeViolationException`).

### 5.5 Testes

- `php artisan test --filter=<class>` deve passar isoladamente.
- Cada DTO tem: round-trip serializacao, hash determinismo, hash muda quando campo muda, hash ignora campo `<entity>_hash`.
- Cada validator tem: caso valido + N casos invalidos.
- Feature tests usam fixtures em `tests/Fixtures/AtlasDev/`.

---

## 6. Fatia 0 — Schemas (DTOs Read-Only)

### 6.1 Objetivo

Construir os **14 DTOs** read-only descritos no contracts doc, com:

- serializacao canonica;
- hashing determinístico;
- testes de invariants;
- zero acoplamento com runtime.

Saida: codigo PHP que serializa/deserializa schemas. Sem provider, sem pipeline.

### 6.2 PRs Sugeridos

#### PR 0.1 — Components e Contract Interface

Arquivos a criar:

```text
app/Services/Ai/Programming/AtlasDev/Schemas/Contracts/AtlasDevSchemaContract.php
app/Services/Ai/Programming/AtlasDev/Schemas/Components/GitState.php
app/Services/Ai/Programming/AtlasDev/Schemas/Components/SurfaceContext.php
app/Services/Ai/Programming/AtlasDev/Schemas/Components/Preflight.php
app/Services/Ai/Programming/AtlasDev/Schemas/Components/ContextBudget.php
app/Services/Ai/Programming/AtlasDev/Schemas/Components/VerificationPlan.php
app/Services/Ai/Programming/AtlasDev/Schemas/Components/RepairPolicy.php
app/Services/Ai/Programming/AtlasDev/Schemas/Components/ProviderLock.php
app/Services/Ai/Programming/AtlasDev/Schemas/Components/Budget.php
app/Services/Ai/Programming/AtlasDev/Schemas/Components/Truncation.php
```

Interface:

```php
namespace App\Services\Ai\Programming\AtlasDev\Schemas\Contracts;

interface AtlasDevSchemaContract
{
    public function schemaVersion(): string;
    public function toCanonicalArray(): array;
    public function toJson(): string;
    public function hash(): string;
    public function isProviderSafe(): bool;
}
```

Testes:

```text
tests/Unit/Ai/Programming/AtlasDev/Schemas/Components/GitStateTest.php
... (idem por componente)
```

DoD do PR 0.1:

- [x] Interface `AtlasDevSchemaContract` definida com 5 metodos.
- [x] 9 components value objects implementados como `final class`.
- [x] Cada component tem teste de construcao, igualdade, serializacao.
- [x] `composer test --filter=AtlasDev/Schemas/Components` verde.

#### PR 0.2 — Camada Plano (4 DTOs)

Arquivos a criar:

```text
app/Services/Ai/Programming/AtlasDev/Schemas/OperationEnvelope.php
app/Services/Ai/Programming/AtlasDev/Schemas/CompactSdd.php
app/Services/Ai/Programming/AtlasDev/Schemas/MiniProgrammingSpec.php
app/Services/Ai/Programming/AtlasDev/Schemas/LightTaskContract.php
```

Signatures: ver contracts doc secao 4.

Validators a criar:

```text
app/Services/Ai/Programming/AtlasDev/Schemas/Validators/OperationEnvelopeValidator.php
app/Services/Ai/Programming/AtlasDev/Schemas/Validators/CompactSddValidator.php
app/Services/Ai/Programming/AtlasDev/Schemas/Validators/MiniProgrammingSpecValidator.php
app/Services/Ai/Programming/AtlasDev/Schemas/Validators/LightTaskContractValidator.php
```

Validator interface:

```php
namespace App\Services\Ai\Programming\AtlasDev\Schemas\Validators;

final class ValidationResult
{
    public function __construct(
        public readonly bool $valid,
        public readonly array $violations,
    ) {}
}

interface SchemaValidator
{
    public function validate(object $dto, array $context = []): ValidationResult;
}
```

Testes obrigatorios por DTO:

1. **Construcao com campos validos** -> objeto criado.
2. **Round-trip JSON** -> `fromArray(toCanonicalArray()) == original`.
3. **Hash determinismo** -> mesma entrada produz mesmo hash em duas execucoes.
4. **Hash muda quando campo muda** -> mudar 1 campo => hash diferente.
5. **Hash ignora `<entity>_hash`** -> dois objetos identicos com hashes diferentes no campo `<entity>_hash` produzem o **mesmo** hash recalculado.

Testes obrigatorios por Validator:

1. Cenario valido completo -> `valid = true, violations = []`.
2. Cada invariant listado no contracts doc tem **um teste de violacao** que retorna `valid = false` com o motivo certo.

Exemplo (CompactSdd invariant 1: R4|R5 forca mode=escalate_preview):

```php
public function test_r4_with_patch_mode_is_invalid(): void
{
    $sdd = $this->makeCompactSdd(['risk_level' => 'R4', 'mode' => 'patch']);
    $result = (new CompactSddValidator())->validate($sdd);
    $this->assertFalse($result->valid);
    $this->assertContains('R4|R5 requires mode=escalate_preview', $result->violations);
}
```

DoD do PR 0.2:

- [x] 4 DTOs da Camada Plano implementados.
- [x] 4 validators implementados.
- [x] `OperationEnvelopeValidator` cobre os 4 `surface_id` canonicos: `atlas_desktop_ai`, `atlas_cli_dev`, `atlas_app`, `atlas_api_interaction`.
- [x] Para cada DTO, 5 testes de DTO + N testes de validator (N = quantidade de invariants no contracts doc).
- [x] `composer test --filter=AtlasDev/Schemas/(OperationEnvelope|CompactSdd|MiniProgrammingSpec|LightTaskContract)` verde.

#### PR 0.3 — Camada Contexto (4 DTOs)

Arquivos a criar:

```text
app/Services/Ai/Programming/AtlasDev/Schemas/ContextRetrievalPlan.php
app/Services/Ai/Programming/AtlasDev/Schemas/CodeDiscoveryManifest.php
app/Services/Ai/Programming/AtlasDev/Schemas/OpenBrainProgrammingProjection.php
app/Services/Ai/Programming/AtlasDev/Schemas/ProviderPromptProjection.php
```

Components adicionais:

```text
app/Services/Ai/Programming/AtlasDev/Schemas/Components/PromptSections.php
app/Services/Ai/Programming/AtlasDev/Schemas/Components/QualityChecks.php
```

Validators:

```text
app/Services/Ai/Programming/AtlasDev/Schemas/Validators/ContextRetrievalPlanValidator.php
app/Services/Ai/Programming/AtlasDev/Schemas/Validators/CodeDiscoveryManifestValidator.php
app/Services/Ai/Programming/AtlasDev/Schemas/Validators/OpenBrainProgrammingProjectionValidator.php
app/Services/Ai/Programming/AtlasDev/Schemas/Validators/ProviderPromptProjectionValidator.php
```

Atencao especial em **CodeDiscoveryManifest**:

- Validator deve verificar `is_file()` real para cada `likely_files[].path`. Para testes, usar VFS ou path fixture controlado.
- Test fixture sob `tests/Fixtures/AtlasDev/CodeDiscovery/`.

Atencao especial em **ProviderPromptProjection**:

- Validator chama 6 quality checks (ver contracts doc secao 5.4).
- Teste deve cobrir cada `quality_check` falhando individualmente.

DoD do PR 0.3:

- [x] 4 DTOs da Camada Contexto implementados.
- [x] 2 components novos implementados.
- [x] 4 validators implementados.
- [x] Teste de path validation real em CodeDiscoveryManifestValidator (cria arquivo tmp e valida).
- [x] 6 testes de quality_check failure em ProviderPromptProjectionValidator.
- [x] `composer test --filter=AtlasDev/Schemas/(ContextRetrievalPlan|CodeDiscovery|OpenBrain|ProviderPrompt)` verde.

#### PR 0.4 — Camada Receipt (4 DTOs)

Arquivos a criar:

```text
app/Services/Ai/Programming/AtlasDev/Schemas/ScopeGuardReceipt.php
app/Services/Ai/Programming/AtlasDev/Schemas/VerificationReceipt.php
app/Services/Ai/Programming/AtlasDev/Schemas/FailureCapsule.php
app/Services/Ai/Programming/AtlasDev/Schemas/EscalationDecision.php
```

Components adicionais:

```text
app/Services/Ai/Programming/AtlasDev/Schemas/Components/ScopeBaseline.php
app/Services/Ai/Programming/AtlasDev/Schemas/Components/ScopeObserved.php
app/Services/Ai/Programming/AtlasDev/Schemas/Components/ScopeContractView.php
app/Services/Ai/Programming/AtlasDev/Schemas/Components/RepairSummary.php
app/Services/Ai/Programming/AtlasDev/Schemas/Components/CostSummary.php
app/Services/Ai/Programming/AtlasDev/Schemas/Components/CompletionSummary.php
app/Services/Ai/Programming/AtlasDev/Schemas/Components/EscalationSummary.php
app/Services/Ai/Programming/AtlasDev/Schemas/Components/EscalationSignals.php
```

Validators:

```text
app/Services/Ai/Programming/AtlasDev/Schemas/Validators/ScopeGuardReceiptValidator.php
app/Services/Ai/Programming/AtlasDev/Schemas/Validators/VerificationReceiptValidator.php
app/Services/Ai/Programming/AtlasDev/Schemas/Validators/FailureCapsuleValidator.php
app/Services/Ai/Programming/AtlasDev/Schemas/Validators/EscalationDecisionValidator.php
```

Atencao especial em **VerificationReceiptValidator**:

- Invariant 1: `completion.status = passed` exige todos os gates required passados + scope_guard passado + tests ou no_patch_reason.
- Invariant 2: `passed` + `honesty_flags` nao vazio = invalido.

Atencao especial em **FailureCapsuleValidator**:

- `decision = retry` exige `attempt_index < max_attempts` do task_contract no contexto.
- `failure_signature` deve ser determinístico.

DoD do PR 0.4:

- [x] 4 DTOs da Camada Receipt implementados.
- [x] 8 components novos implementados.
- [x] 4 validators implementados com todos os invariants cobertos.
- [x] `composer test --filter=AtlasDev/Schemas/(ScopeGuard|Verification|FailureCapsule|EscalationDecision)` verde.

#### PR 0.5 — Camada Telemetria (2 DTOs)

Arquivos a criar:

```text
app/Services/Ai/Programming/AtlasDev/Schemas/FastPathTelemetry.php
app/Services/Ai/Programming/AtlasDev/Schemas/FastPathErrorLedgerEntry.php
```

Component:

```text
app/Services/Ai/Programming/AtlasDev/Schemas/Components/ObservedSignals.php
```

Validators:

```text
app/Services/Ai/Programming/AtlasDev/Schemas/Validators/FastPathTelemetryValidator.php
app/Services/Ai/Programming/AtlasDev/Schemas/Validators/FastPathErrorLedgerEntryValidator.php
```

DoD do PR 0.5:

- [x] 2 DTOs da Camada Telemetria implementados.
- [x] `ObservedSignals` component implementado.
- [x] 2 validators implementados.
- [x] Teste de append-only em FastPathErrorLedgerEntry (apos `reviewer_signed=true`, mutacao falha).
- [x] `composer test --filter=AtlasDev/Schemas/(FastPathTelemetry|FastPathErrorLedger)` verde.

### 6.3 DoD Operacional Da Fatia 0

A fatia esta verde quando **tudo** abaixo passa:

1. `composer test --filter=AtlasDev/Schemas` retorna 0 falhas.
2. `composer lint` ou `vendor/bin/pint --test app/Services/Ai/Programming/AtlasDev/` retorna 0 issues.
3. PHPStan/Larastan no nivel do projeto sem erros novos em `AtlasDev/Schemas/`.
4. Code coverage minimo de 95% em `Schemas/` (`vendor/bin/phpunit --coverage-text --filter=AtlasDev/Schemas`).
5. `php artisan atlas:engineering:knowledge index-code` reconhece os novos DTOs e nao quebra.
6. Doc de provider-safe memory atualizado se necessario (provavelmente nao para PRs internos read-only).
7. **Sem chamadas a `claude_cli`, sem provider real, sem benchmark, sem Rivals.**

### 6.4 Fixtures Sugeridas

```text
tests/Fixtures/AtlasDev/
  envelopes/
    valid_repair_r2.json
    valid_question_r0.json
    invalid_dirty_worktree.json
  compact_sdd/
    valid_repair_r2.json
    valid_patch_r3.json
    invalid_r4_with_patch_mode.json
  mini_spec/
    valid_repair_r2.json
    invalid_no_acceptance.json
  task_contract/
    valid_repair_r2.json
    invalid_fallback_allowed.json
  scope_guard/
    valid_clean.json
    valid_with_pre_existing.json
    invalid_forbidden_touch.json
  verification_receipt/
    valid_passed.json
    valid_needs_review.json
    invalid_passed_with_honesty_flag.json
  failure_capsule/
    valid_retry.json
    valid_escalate.json
```

---

## 7. Fatia 1 — Context Layer

### 7.1 Objetivo

Implementar a camada de **descobrimento de contexto** sem provider real. Saida: dado um `OperationEnvelope`, o sistema produz `ContextRetrievalPlan`, `CodeDiscoveryManifest`, `OpenBrainProgrammingProjection` validos.

### 7.2 PRs Sugeridos

#### PR 1.1 — DocContextTierSelector

Arquivo:

```text
app/Services/Ai/Programming/AtlasDev/Discovery/DocContextTierSelector.php
```

Signature:

```php
namespace App\Services\Ai\Programming\AtlasDev\Discovery;

use App\Services\Ai\Programming\AtlasDev\Schemas\ContextRetrievalPlan;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;

final class DocContextTierSelector
{
    public function select(
        OperationEnvelope $envelope,
        CompactSdd $compactSdd,
    ): ContextRetrievalPlan;
}
```

Regras (alinhadas a tabela 11.1 do contrato principal):

- `core` sempre se `task_kind != question`;
- `code_intelligence` sempre se `workspace_resolved = true`;
- `sdd` se `task_kind in (patch, repair)` ou `risk_level >= R2`;
- `interface` se `task_kind = frontend` ou surface `atlas_desktop_ai|atlas_app|atlas_code`;
- `forge` se `risk_level >= R4`;
- `obras` se thread >= 12 mensagens (heuristica) ou se compact_sdd indicar continuidade.

Budget chars do plan vem da tabela 11.2 mapeada por mode.

DoD do PR 1.1:

- [x] 7 cenarios de selecao cobertos por teste (1 por task_kind + edge cases).
- [x] `select()` e puro: mesma entrada = mesma saida.
- [x] Output passa por `ContextRetrievalPlanValidator`.

#### PR 1.2 — CodeDiscoveryEngine

Arquivo:

```text
app/Services/Ai/Programming/AtlasDev/Discovery/CodeDiscoveryEngine.php
```

Signature:

```php
final class CodeDiscoveryEngine
{
    public function __construct(
        private readonly CodeIntelligenceService $codeIntelligence,  // existente
        private readonly RipgrepRunner $rg,                          // existente ou novo wrapper
    ) {}

    public function discover(
        OperationEnvelope $envelope,
        CompactSdd $compactSdd,
    ): CodeDiscoveryManifest;
}
```

Algoritmo (ordem):

1. extrair simbolos provaveis do `normalized_intent` (regex de identificadores PHP/TS, nomes Camel/Snake);
2. consultar `CodeIntelligenceService` por simbolos -> arquivos candidatos com confidence;
3. validar paths via `is_file()` no workspace;
4. confirmar matches via `rg` para reforcar/derrubar confidence;
5. listar testes relacionados via convencao (`tests/Unit/<Class>Test.php`, `tests/Feature/...`);
6. derivar `forbidden_files` por padroes (vendor/*, node_modules/*, .env*);
7. preencher `missing_refs` quando confidence < strong_inference.

Limites:

- Paths nao validados nunca entram em `likely_files` (decisao locked).
- Se nenhum simbolo extraido E nenhum hit via grep, `confidence = blocking_ambiguity` e marca `missing_refs`.

DoD do PR 1.2:

- [x] Cenario 1: intent com simbolo claro -> manifest com `confidence = strong_inference` e 1+ `likely_files` validos.
- [x] Cenario 2: intent vago -> `confidence = blocking_ambiguity` + `missing_refs` nao vazio.
- [x] Cenario 3: intent menciona path inexistente -> path vira `missing_refs`, nao `likely_files`.
- [x] Output passa por `CodeDiscoveryManifestValidator`.
- [x] Fixture com workspace temporario criado em `tests/Fixtures/AtlasDev/CodeDiscovery/workspace_a/`.

#### PR 1.3 — OpenBrainProjectionAdapter

Arquivo:

```text
app/Services/Ai/Programming/AtlasDev/Discovery/OpenBrainProjectionAdapter.php
```

Signature:

```php
final class OpenBrainProjectionAdapter
{
    public function __construct(
        private readonly OpenBrainContextInjector $injector,  // existente
    ) {}

    public function projectFor(
        OperationEnvelope $envelope,
        CompactSdd $compactSdd,
        ContextRetrievalPlan $plan,
    ): OpenBrainProgrammingProjection;
}
```

Regras:

- consume Open Brain via adapter existente;
- monta projection com `schema_version: atlas.open_brain.programming_projection.v1` (alinhado ao schema upstream);
- `mode: programming` sempre;
- `objective_hash = sha256(normalized_intent)`;
- respeita budget de chars do plan;
- registra `missing_sources` se `required_sources` faltar;
- aplica filtros provider-safe (decisao do contrato principal secao 11).

DoD do PR 1.3:

- [x] Cenario 1: budget caber -> `truncation.truncated = false`.
- [x] Cenario 2: budget estourado -> `truncation.truncated = true` + `reasons`.
- [x] Cenario 3: required_source faltando -> retorna projection com `missing_sources` populado E sinaliza para o pipeline escalar.
- [x] Output passa por `OpenBrainProgrammingProjectionValidator`.

### 7.3 DoD Operacional Da Fatia 1

- `composer test --filter=AtlasDev/Discovery` verde.
- Lint zero issues na pasta `Discovery/`.
- Para um workspace fixture conhecido + envelope fixture, a saida e **byte-identica** entre duas execucoes (determinismo).
- Logs no canal `atlas_dev` registram tier selection com motivos.
- **Sem provider call. Sem prompt. Sem patch.**

---

## 8. Fatia 1.5 — Runtime Quality Foundations

### 8.1 Objetivo

Garantir que, ao chegar na Fatia 3 (provider real), exista **fundacao operacional** completa:

- Prompt nao improvisado (`ProviderPromptProjection`);
- Telemetria desde o run 1;
- Error ledger desde o run 1;
- Persistencia local de receipts.

Isto **nao** e medicao competitiva — e engenharia de runtime auditavel.

### 8.2 PRs Sugeridos

#### PR 1.5.1 — ProviderPromptBuilder

Arquivo:

```text
app/Services/Ai/Programming/AtlasDev/PromptProjection/ProviderPromptBuilder.php
resources/views/atlas_dev/provider_prompt.md.blade.php
```

Signature:

```php
final class ProviderPromptBuilder
{
    public function build(
        OperationEnvelope $envelope,
        CompactSdd $compactSdd,
        MiniProgrammingSpec $miniSpec,
        LightTaskContract $taskContract,
        CodeDiscoveryManifest $discovery,
        OpenBrainProgrammingProjection $projection,
    ): ProviderPromptProjection;
}
```

Algoritmo:

1. compor `sections` a partir dos artefatos upstream;
2. derivar `operating_rules` (lista fixa de regras Atlas Dev provider-safe);
3. ligar `mini_spec_ref` e `task_contract_ref` por hash + path;
4. listar `context_refs` da projection;
5. listar `allowed_files`/`forbidden_files` do task contract;
6. listar `expected_tests` do mini_spec;
7. listar `acceptance_criteria` do mini_spec;
8. listar `stop_conditions` + `escalation_conditions` do task contract;
9. definir `output_contract` (diff em formato unified, lista de changed_files, razao se no_patch_needed);
10. renderizar `rendered_prompt_text` via Blade template provider-safe;
11. rodar 6 quality checks;
12. gerar `prompt_projection_hash`.

**Rendered prompt text** (obrigatorio): builder renderiza prompt em Markdown estavel via `resources/views/atlas_dev/provider_prompt.md.blade.php`. Provider adapter nunca aceita prompt string fora de `ProviderPromptProjection`.

DoD do PR 1.5.1:

- [x] Cenario completo (todos artefatos validos) -> projection com `quality_checks.all_passed = true`.
- [x] Cenario com mini_spec faltando acceptance_criteria -> `no_missing_required_sections = false`.
- [x] Cenario com conflict allowed/forbidden -> `no_conflicting_file_rules = false`.
- [x] Cenario sintetico tentando injetar "rivals"/"benchmark" no intent -> `no_hidden_benchmark_instruction = false`.
- [x] `rendered_prompt_text` nao vazio e hash muda quando o template/sections mudam.
- [x] Output passa por `ProviderPromptProjectionValidator`.

#### PR 1.5.2 — Telemetry Emitter

Arquivo:

```text
app/Services/Ai/Programming/AtlasDev/Telemetry/TelemetryEmitter.php
```

Signature:

```php
final class TelemetryEmitter
{
    public function emit(
        OperationEnvelope $envelope,
        ?VerificationReceipt $receipt,
        ?EscalationDecision $escalation,
        TelemetryContext $context,
    ): FastPathTelemetry;
}
```

`TelemetryContext` carrega contadores que o pipeline coleta durante o run (gates_activated, provider_calls, wall_time_ms, etc.).

Emit **uma vez por run** ao final, mesmo em `blocked` ou `failed`.

DoD do PR 1.5.2:

- [x] Cenario passed -> telemetria com `completion_state=passed`, `error_ledger_written=false`.
- [x] Cenario failed -> telemetria com `error_ledger_written=true`.
- [x] Cenario blocked -> telemetria emitida com state correto.
- [x] Output passa por `FastPathTelemetryValidator`.

#### PR 1.5.3 — Error Ledger Writer

Arquivo:

```text
app/Services/Ai/Programming/AtlasDev/Telemetry/ErrorLedgerWriter.php
```

Signature:

```php
final class ErrorLedgerWriter
{
    public function record(
        string $runId,
        VerificationReceipt $receipt,
        ?EscalationDecision $escalation,
    ): ?FastPathErrorLedgerEntry;
}
```

- Retorna `null` se `completion_state = passed` e nenhum erro foi observado.
- Detecta `missed_escalation` heuristicamente:
  - `completion_state in (failed, needs_review)` AND `escalation.recommended = false` AND signals observados sugeriam escalada -> `should_have_escalated = true` na entrada (mas continua null ate revisor humano assinar).
- Append-only.

DoD do PR 1.5.3:

- [x] Cenario passed limpo -> retorna null.
- [x] Cenario failed -> entrada criada com `actual_failure_mode` derivado.
- [x] Cenario heuristica missed_escalation -> entrada com sinais populados, `should_have_escalated=null` (revisor preenche depois).
- [x] Append-only enforced: tentativa de mutar entrada com `reviewer_signed=true` falha.

#### PR 1.5.4 — Receipt Persister

Arquivos:

```text
app/Services/Ai/Programming/AtlasDev/Persistence/ReceiptStorage.php
app/Services/Ai/Programming/AtlasDev/Persistence/<Dto>Persister.php   # uma por DTO persistivel
```

Signature do storage:

```php
final class ReceiptStorage
{
    public function pathFor(string $runId, string $artifact): string;
    public function ensureRunDir(string $runId): string;
    public function writeJson(string $path, array $payload): void;   // atomic
    public function readJson(string $path): array;
    public function exists(string $path): bool;
}
```

Path canon: `storage/atlas-dev/receipts/<run_id>/<artifact>.json`.

Atomic write: escreve em `<path>.tmp`, fsync, rename. Falha mid-write nao deixa arquivo parcial.

DoD do PR 1.5.4:

- [x] Cada DTO persistivel tem persister dedicado.
- [x] Round-trip persist/read e identico.
- [x] Atomicidade verificada com teste que simula crash entre write e rename.
- [x] Permissoes corretas (`0640` em arquivos, `0750` em diretorios).
- [x] `storage/atlas-dev/` adicionado a `.gitignore` apos primeiro commit dos paths.

### 8.3 DoD Operacional Da Fatia 1.5

- `composer test --filter=AtlasDev/(PromptProjection|Telemetry|Persistence)` verde.
- Para um envelope fixture + artefatos upstream, gerar `ProviderPromptProjection` produz output byte-identico em duas execucoes (determinismo).
- Quality checks bloqueiam quando intent contem palavras-chave proibidas ("rivals", "benchmark", "opus challenge").
- `storage/atlas-dev/receipts/<run_id>/` e criado e populado.
- **Sem provider call real.**

---

## 9. Fatia 2 — Plan-Only Pipeline

### 9.1 Objetivo

Montar pipeline end-to-end **ate task_contract_ready**, sem chamar provider. Saida: JSON plan-only auditavel.

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

#### PR 2.4 — CLI command `atlas:dev:plan`

Arquivo:

```text
app/Console/Commands/AtlasDevPlanCommand.php
```

Signature:

```php
final class AtlasDevPlanCommand extends Command
{
    protected $signature = 'atlas:dev:plan
                            {intent : intent text}
                            {--workspace= : absolute workspace path, defaults to git root}
                            {--surface=atlas_cli_dev}
                            {--constraints=* : user constraints}
                            {--json : output JSON}';
}
```

Comportamento:

- chama `AtlasDevFastPathOrchestrator.planOnly()`;
- output humano por default (resumo + hashes + routing decision);
- output JSON com tudo se `--json`.

DoD do PR 2.4:

- [x] `php artisan atlas:dev:plan "corrija teste X" --json` retorna JSON valido.
- [x] Comando registrado no `Kernel.php` console.
- [x] `php artisan list | grep atlas:dev:plan` mostra o comando.
- [x] Help (`php artisan atlas:dev:plan --help`) documenta flags.

### 9.3 DoD Operacional Da Fatia 2

- `composer test --filter=AtlasDev/Pipeline` + `tests/Feature/AtlasDev/EndToEndPlanOnlyTest.php` verdes.
- Comando `atlas:dev:plan` rodando em workspace real (`atlas-server`) produz artefatos validos.
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
  "workspace": "/abs/workspace",
  "raw_intent": "texto do composer",
  "user_constraints": [],
  "policy_hints": {
    "open_brain_mode": "auto",
    "task_kind_override": null
  },
  "thread_id": null,
  "previous_run_id": null
}
```

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

### 10.2 PRs Sugeridos

#### PR 3.1 — Provider Adapter (Sonnet Locked)

Arquivos:

```text
app/Services/Ai/Programming/AtlasDev/Provider/SonnetClaudeCliAdapter.php
```

Signature:

```php
final class SonnetClaudeCliAdapter
{
    public function __construct(
        private readonly ClaudeCliGateway $gateway,  // existente
    ) {}

    public function executeOneCall(
        ProviderPromptProjection $promptProjection,
        LightTaskContract $taskContract,
    ): ProviderCallResult;
}

final class ProviderCallResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $rawResponse,
        public readonly ?string $diffOrNull,
        public readonly array $changedFiles,
        public readonly ?int $tokensIn,
        public readonly ?int $tokensOut,
        public readonly ?float $costEstimateUsd,
        public readonly int $wallTimeMs,
        public readonly array $errors,
    ) {}
}
```

Comportamento:

- envia prompt via `ClaudeCliGateway`;
- enforce `task_contract.provider_lock.fallback_allowed = false` (nao retentar com outro modelo);
- mede wall time;
- parsea resposta para extrair diff (formato unified) e lista de changed_files;
- se resposta nao tiver diff E task pediu patch -> `errors` populado.

DoD do PR 3.1:

- [x] Adapter sob teste com gateway mock retorna result valido.
- [x] Tentativa de fallback bloqueada (asserir excecao).
- [x] Diff parser cobre 3 formatos comuns (unified, contextual com `+++`/`---`, e response sem diff).

#### PR 3.2 — ScopeGuard

Arquivo:

```text
app/Services/Ai/Programming/AtlasDev/Gate/ScopeGuard.php
```

Signature:

```php
final class ScopeGuard
{
    public function check(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderCallResult $callResult,
    ): ScopeGuardReceipt;
}
```

Algoritmo:

1. capturar baseline (`git status` + `git diff` antes da execucao — passado via context, nao recapturar aqui);
2. aplicar diff observado;
3. comparar changed_files vs allowed/watched/forbidden;
4. detectar pre_existing_changes do usuario e marcar preserve;
5. classificar violations;
6. emitir ScopeGuardReceipt com status.

DoD do PR 3.2:

- [x] Diff toca allowed -> `status = passed`.
- [x] Diff toca forbidden -> `status = failed`.
- [x] Diff toca watched -> `status = needs_review`.
- [x] Diff toca > max_files_changed -> `status = failed`.
- [x] Pre-existing changes do usuario preservadas -> registradas.

#### PR 3.3 — VerificationGate

Arquivo:

```text
app/Services/Ai/Programming/AtlasDev/Gate/VerificationGate.php
```

Signature:

```php
final class VerificationGate
{
    public function __construct(
        private readonly VerificationCommandRunner $runner,
    ) {}

    public function run(
        LightTaskContract $taskContract,
        ProviderCallResult $callResult,
        ScopeGuardReceipt $scopeReceipt,
    ): VerificationGateResult;
}

final class VerificationGateResult
{
    public function __construct(
        public readonly array $tests,       // test execution records
        public readonly array $gates,       // gate records
        public readonly string $aggregateStatus,  // passed | failed | needs_review
        public readonly array $honestyFlags,
    ) {}
}
```

Comportamento:

- itera `task_contract.validation_commands`;
- executa cada um via `VerificationCommandRunner` (timeout default 5min, stream stdout para log persistido);
- aggregate status:
  - todos ok -> passed;
  - algum failed -> failed;
  - alguns passed + missing reason -> needs_review;
- honesty_flags: `test_skipped_no_reason`, `partial_test_run`, etc.

DoD do PR 3.3:

- [x] Comando passa -> `tests[].ok = true`.
- [x] Comando falha -> `tests[].ok = false` + log persistido.
- [x] `no_test_reason` quando profile generic e nenhum comando.
- [x] Honesty flags emitidas quando aplicavel.

#### PR 3.4 — CompletionStateGate

Arquivo:

```text
app/Services/Ai/Programming/AtlasDev/Gate/CompletionStateGate.php
```

Signature:

```php
final class CompletionStateGate
{
    public function decide(
        LightTaskContract $taskContract,
        ScopeGuardReceipt $scopeReceipt,
        VerificationGateResult $verificationResult,
        ProviderCallResult $callResult,
    ): CompletionDecision;
}

final class CompletionDecision
{
    public function __construct(
        public readonly string $status,    // passed | needs_review | failed | blocked | escalate_forge | no_patch_needed
        public readonly array $honestyFlags,
        public readonly array $residualRisks,
    ) {}
}
```

Regras (alinhadas a invariants do VerificationReceipt):

- `passed` exige scope passed + verification passed + zero honesty flags;
- `needs_review` se honesty flags presentes mas evidence minima coberta;
- `failed` se scope failed ou verification failed sem repair possivel;
- `escalate_forge` se risk subiu para R4+ durante execucao;
- `no_patch_needed` se call_result.diff_or_null = null E task era question/review;
- `blocked` se preflight quebrou ou ambiguidade chegou ate aqui.

DoD do PR 3.4:

- [x] 6 cenarios cobertos (1 por completion_state).
- [x] `passed` com honesty_flags rejeitado.

#### PR 3.5 — ReceiptComposer e PipelineOrchestrator (Patch Mode)

Arquivos:

```text
app/Services/Ai/Programming/AtlasDev/Pipeline/ReceiptComposer.php
```

`AtlasDevFastPathOrchestrator` ganha metodo:

```php
public function patch(
    string $surfaceId,
    string $workspace,
    string $rawIntent,
    array $userConstraints = [],
): PatchResult;

final class PatchResult
{
    public function __construct(
        public readonly OperationEnvelope $envelope,
        public readonly ProviderCallResult $callResult,
        public readonly ScopeGuardReceipt $scopeReceipt,
        public readonly VerificationGateResult $verificationResult,
        public readonly VerificationReceipt $verificationReceipt,
        public readonly FastPathTelemetry $telemetry,
    ) {}
}
```

Algoritmo (extends planOnly):

1. tudo do planOnly;
2. se `routing_decision = atlas_dev_fast_path` E `mode = patch`:
   a. `SonnetClaudeCliAdapter.executeOneCall()`;
   b. `ScopeGuard.check()`;
   c. `VerificationGate.run()`;
   d. `CompletionStateGate.decide()`;
   e. `ReceiptComposer.compose()` -> VerificationReceipt;
   f. persistir tudo;
   g. emitir telemetria.

DoD do PR 3.5:

- [x] Feature test `tests/Feature/AtlasDev/EndToEndOneCallTest.php` com gateway mock retorna `completion_state = passed`.
- [x] Cenario com diff fora de escopo retorna `completion_state = failed`.
- [x] Cenario "no_patch_needed" suportado.
- [x] Receipt persistido com todos os hashes e refs validos.

#### PR 3.6 — CLI command `atlas:dev:run`

Arquivo:

```text
app/Console/Commands/AtlasDevRunCommand.php
```

Signature:

```php
protected $signature = 'atlas:dev:run
                        {intent}
                        {--workspace=}
                        {--surface=atlas_cli_dev}
                        {--plan-only : skip provider call}
                        {--json}';
```

DoD do PR 3.6:

- [x] `php artisan atlas:dev:run "corrija teste X"` executa one-call + receipt.
- [x] Flag `--plan-only` desvia para planOnly().
- [x] Sem flag, executa patch.

### 10.3 DoD Operacional Da Fatia 3

- `composer test --filter=AtlasDev` verde (todas subpastas).
- Feature tests Fatia 2 + Fatia 3 verdes.
- Comando `atlas:dev:run` testado em **um caso real seguro** (typo em comment, R1) com Sonnet via `claude_cli` real, **com sucesso**.
- VerificationReceipt persistido + telemetria gravada.
- **Sem fallback. Sem council. Sem repair (ainda).**

### 10.4 Marco 3 — One-Call Visivel No Atlas AI Desktop

Backend:

```text
POST /ai/interactions/atlas-dev/run
GET  /ai/interactions/atlas-dev/runs/{run_id}/stream
GET  /ai/interactions/atlas-dev/runs/{run_id}
```

`run` exige:

```json
{
  "run_id": "uuid-v7",
  "task_contract_hash": "sha256",
  "confirmation_token": "single-use-token-issued-by-plan",
  "operator_confirmed": true
}
```

Sem `operator_confirmed=true`, `task_contract_hash` valido e `confirmation_token` single-use, o backend rejeita a execucao. Plan-only e run ficam separados para impedir provider call acidental.

Confirmation token (GO P0):

- Emitido por `POST /ai/interactions/atlas-dev/plan` apenas quando `routing_decision = atlas_dev_fast_path`.
- Implementação canônica = **DB + HMAC**. Não há mais filesystem store: o legado `ConfirmationTokenStore` foi removido (F-05).
- Persistido como `token_hash` HMAC-SHA256 (chave = APP_KEY base64) em `atlas_dev_confirmation_tokens` com colunas `run_id`, `task_contract_hash`, `surface_id`, `token_hash`, `issued_at`, `used_at`, `expires_at`.
- TTL = 5min (configurável via `atlas_dev.confirmation_token.ttl_seconds`).
- Vinculado a `(run_id, task_contract_hash)`: token emitido para um plan não redime outro.
- Invalidado no primeiro `consume()` atômico (DB unique constraint protege contra corrida).
- Pré-requisito: APP_KEY base64 com ≥32 bytes. Sem isso, Plan/Run falham fechado com `ATLAS_DEV_KEY_MISSING` (500).
- Feature test cobre 400 sem `operator_confirmed`, 422 com `task_contract_hash` invalido, 403 com token ausente/invalido/expirado/reutilizado/contract-mismatch.
- Migrations obrigatórias antes de habilitar Run: `atlas_dev_confirmation_tokens` + `atlas_dev_run_index`.

Streaming policy locked (GO P0):

- Modo atual: **snapshot-replay-then-close**. Backend escreve, em ordem deterministica, um `phase:` por artefato ja persistido + `receipt:` final (se houver) + `stream_closed:` marker, depois fecha. Sem long-lived keepalive nesta fase.
- REST status fallback é a fonte de verdade e o contrato de retomada: cliente que perdeu SSE, recarregou o Desktop ou não suporta stream consulta `GET /runs/{run_id}` (campos `completion_state`, `has_receipt`, `routing`, `persisted_artifact_refs`, `workspace_label`/`workspace_hash`).
- Receipt persistido (DB + filesystem) é a fonte de verdade; SSE e polling apenas transportam snapshots/fases.
- Live async (tail de log de execução) é evolução futura — o contrato do cliente fica estável assumindo `stream_closed` como fim canônico.

SSE minimo:

```text
phase: executing
phase: scope_guarding
phase: verifying
test_started
test_finished
phase: complete
receipt
```

Frontend:

| Path | Mudanca |
| --- | --- |
| `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/client.ts` | adicionar `runAtlasDevPlan()` e stream |
| `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/components/AtlasAiLiveActivity.tsx` | mostrar fases do run |
| `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/components/rich-artifacts/AtlasAiDiff.tsx` | renderizar diff do result |
| `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/components/AtlasAiToolReceipts.tsx` | renderizar verification receipt |

DoD do Marco 3:

- [x] Botao executar aparece somente apos plan-only valido.
- [x] Stream mostra fases sem travar conversa.
- [x] Fallback REST recupera status/receipt quando SSE falha ou o operador recarrega a tela.
- [x] Diff, tests e receipt aparecem na mensagem/thread.
- [x] Provider lock Sonnet/Claude CLI confirmado no receipt.

---

## 11. Fatia 4 — Repair Loop

### 11.1 Objetivo

Implementar repair barato baseado em `FailureCapsule`, com limites por R-level. Mesma provider/model, sem fallback.

### 11.2 PRs Sugeridos

#### PR 4.1 — FailureCapsuleBuilder

Arquivo:

```text
app/Services/Ai/Programming/AtlasDev/Repair/FailureCapsuleBuilder.php
```

Signature:

```php
final class FailureCapsuleBuilder
{
    public function buildFromVerification(
        LightTaskContract $taskContract,
        VerificationGateResult $verificationResult,
        int $attemptIndex,
    ): FailureCapsule;
}
```

Algoritmo:

1. identificar primeiro gate failed;
2. extrair `primary_error_excerpt` (max 4kb) do log;
3. truncar para preservar erro principal;
4. computar `failure_signature = sha256(gate + "::" + normalize(error))`;
5. decidir `retry|stop|escalate`:
   - retry se `attempt_index < max_attempts` E nao e mesma signature repetida;
   - escalate se signature repetida OU diff growing OU new scope;
   - stop se max_attempts atingido.

DoD do PR 4.1:

- [x] Failure capsule com excerpt nao vazio quando ha erro.
- [x] failure_signature determinístico para mesmo erro.
- [x] Decision retry/stop/escalate cobertos.

#### PR 4.2 — RepairOrchestrator

Arquivo:

```text
app/Services/Ai/Programming/AtlasDev/Repair/RepairOrchestrator.php
```

Signature:

```php
final class RepairOrchestrator
{
    public function attempt(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $originalPrompt,
        VerificationGateResult $previousResult,
        FailureCapsule $capsule,
    ): RepairAttemptResult;
}

final class RepairAttemptResult
{
    public function __construct(
        public readonly ProviderCallResult $callResult,
        public readonly ScopeGuardReceipt $scopeReceipt,
        public readonly VerificationGateResult $verificationResult,
        public readonly FailureCapsule $newCapsule,  // null se passed
        public readonly string $decision,  // continue | stop | escalate
    ) {}
}
```

Comportamento:

- gera repair prompt baseado em `originalPrompt + capsule`;
- envia para mesma provider/model (sonnet via claude_cli);
- roda scope guard + verification gate;
- atualiza receipt acumulando attempts;
- se mesma signature aparece -> decision = escalate;
- respeita `repair_policy.abort_on_same_signature_twice`.

DoD do PR 4.2:

- [x] Repair que vira green -> `decision = continue`, `newCapsule = null`.
- [x] Repair que falha mesma signature 2x -> `decision = escalate`.
- [x] Repair que excede max_attempts -> `decision = stop`.
- [x] Diff growing -> `decision = escalate`.

#### PR 4.3 — Integracao no Pipeline

`AtlasDevFastPathOrchestrator.patch()` ganha loop:

```php
$attempt = 0;
$maxAttempts = $taskContract->repairPolicy->maxAttempts;
while ($attempt < $maxAttempts && $completionState === 'failed') {
    $capsule = $this->capsuleBuilder->buildFromVerification(...);
    $persist($capsule);
    $repairResult = $this->repairOrchestrator->attempt(...);
    $persist($repairResult);
    if ($repairResult->decision === 'continue') break;
    if ($repairResult->decision === 'escalate') break;
    $attempt++;
}
```

DoD do PR 4.3:

- [x] Feature test `tests/Feature/AtlasDev/EndToEndRepairTest.php` cobre:
  - cenario "first attempt fail, repair pass";
  - cenario "two fails same signature -> escalate";
  - cenario "first pass without repair".
- [x] Receipt final tem `repair.attempt_count` correto.
- [x] Todas as capsules persistidas como `failure_capsule.<n>.json`.

### 11.3 DoD Operacional Da Fatia 4

- `composer test --filter=AtlasDev/Repair` verde.
- Feature test repair verde.
- Receipt com loop de repair persiste corretamente.
- Telemetria registra `repair_attempts > 0` quando aplicavel.
- **Repair nunca muda provider. Nunca expande escopo silenciosamente.**

### 11.4 Marco 4 — Repair Visivel No Atlas AI Desktop

SSE ganha eventos:

```text
repair_planned
repair_executing
repair_finished
```

Frontend:

| Path | Mudanca |
| --- | --- |
| `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/components/AtlasAiLiveActivity.tsx` | segmento `repair · tentativa N/M` |
| `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/components/AtlasAiToolReceipts.tsx` | failure capsule expandivel |
| `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/components/AtlasAiQualityBadge.tsx` | badges `repair`, `needs_review`, `failed_closed` |

DoD do Marco 4:

- [x] Desktop mostra cada attempt em tempo real.
- [x] Failure capsule aparece sem vazar prompt interno.
- [x] Mesma signature duas vezes gera escalation preview, nao retry infinito.

---

## 12. Fatia 5 — Surface Parity + EscalationDecision

### 12.1 Objetivo

Completar paridade nas surfaces CLI Dev, App e API depois que Desktop ja provou plan-only, run e repair. Implementar `EscalationDecision` engine e ligar o botao `promover` do Desktop a preview, sem criar Obra automaticamente.

Surface inicial locked:

| Camada | Path | Papel |
| --- | --- | --- |
| Desktop UI | `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/AtlasAiSurface.tsx` | Workbench principal do operador: conversa, composer, plano e side panel |
| Desktop payload | `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/contract.ts` | Constroi payload `atlas_desktop_ai` com modo/tarefa/workspace |
| Desktop client | `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/client.ts` | Envia `/ai/interactions` e acompanha trace/thread |
| Backend surface adapter | `app/Services/Ai/Surface/Adapters/AtlasDesktopAiSurfaceAdapter.php` | Declara capacidades e mapeia tarefas para `programming.*` |
| Runtime atual | `app/Services/Ai/Programming/AtlasDevRuntimeService.php` | Normaliza payload programming e injeta `atlas_dev_runtime` |

O adapter desktop deve receber intencao crua, workspace e selecoes de UX. Ele nao recebe prompt final, nao monta `ProviderPromptProjection` no frontend e nao permite prompt customizado da surface.

### 12.2 PRs Sugeridos

#### PR 5.1 — EscalationDecisionEngine

Arquivo:

```text
app/Services/Ai/Programming/AtlasDev/Escalation/EscalationDecisionEngine.php
```

Signature:

```php
final class EscalationDecisionEngine
{
    public function evaluate(
        OperationEnvelope $envelope,
        CompactSdd $compactSdd,
        CodeDiscoveryManifest $discovery,
        ?VerificationReceipt $receipt = null,
        ?array $failureCapsules = null,
    ): ?EscalationDecision;
}
```

Algoritmo:

1. coletar sinais (file_count, layers_touched, risk_keywords, context_required_chars, thread_messages, prior_failure_count);
2. calcular escalation_score (0-10) por heuristica documentada no contrato principal secao 16;
3. decidir target:
   - score >= 7 OU risk_level >= R4 -> `forge`;
   - score >= 4 -> `obra_candidate`;
   - senao -> retorna null (sem escalada).
4. preencher reasons e human_action_required.

DoD do PR 5.1:

- [x] 3 cenarios cobertos: sem escalada, obra_candidate, forge.
- [x] Score determinístico.

#### PR 5.2 — Integrar Escalation no Pipeline

`AtlasDevFastPathOrchestrator` ganha:

```php
private function maybeEscalate(...): ?EscalationDecision
```

Chamado:

- antes da provider call se R4+ no CompactSdd;
- depois de cada repair attempt;
- antes de retornar PatchResult.

DoD do PR 5.2:

- [x] Cenario R4 -> escalation antes de provider call, sem patch.
- [x] Cenario repair gerando escalate -> EscalationDecision persistido.
- [x] EscalationDecision aparece no VerificationReceipt.

#### PR 5.3 — Evoluir AtlasCliDevWorkflowService

Arquivo:

```text
app/Services/Ai/Cli/AtlasCliDevWorkflowService.php   # editar
```

Adicionar:

```php
public function runAtlasDevEfficient(
    string $rawIntent,
    string $workspace,
    array $options = [],
): mixed;  // PatchResult | PlanOnlyResult
```

Que delega para `AtlasDevFastPathOrchestrator`.

DoD do PR 5.3:

- [x] Service existente nao quebra (testes existentes passam).
- [x] Novo metodo testado.
- [x] `AtlasCliDevCommand` ganha flag `--efficient` para usar o novo path (default permanece comportamento atual ate desbloqueio).

#### PR 5.4a — Atlas AI Desktop Escalation Preview

Arquivo:

```text
app/Services/Ai/Programming/AtlasDev/Surface/AtlasDesktopAiAdapter.php # editar
```

Adicionar endpoint/acao:

```text
POST /ai/interactions/atlas-dev/runs/{run_id}/escalation-preview
```

DoD do PR 5.4a:

- [x] Botao `promover` cria preview Forge via `DevToForgePromotionService`.
- [x] Preview exige acao humana; nenhuma Obra e criada automaticamente.
- [x] Durante run ativo, botao `promover` fica indisponivel.

#### PR 5.4b — Surface Adapters De Paridade

Arquivos:

```text
app/Services/Ai/Programming/AtlasDev/Surface/AtlasCliDevAdapter.php
app/Services/Ai/Programming/AtlasDev/Surface/AtlasAppAdapter.php
app/Services/Ai/Programming/AtlasDev/Surface/AtlasApiInteractionAdapter.php
```

Cada adapter mapeia payload da surface -> `runAtlasDevEfficient()` E formata o resultado de volta para o tipo de resposta da surface.

DoD do PR 5.4b:

- [x] Adapters CLI/App/API implementados.
- [x] Feature test `tests/Feature/AtlasDev/EndToEndSurfaceTest.php` cobre Desktop first + 3 surfaces de paridade.

#### PR 5.5 — Doc Atualizacao + Cert

- Marcar status do `atlas-dev-efficient-programming-flow-v1.md` para `building` (de `draft`).
- Atualizar `atlas-dev-flow-map-and-product-options-v1.md` com referencia ao runtime real.
- Registrar `decision_locked` no doc principal: `atlas_dev_efficient_flow_runtime` -> `available`.
- Adicionar cert local (engenharia, nao competitiva):
  - `atlas:engineering:cert atlas-dev-efficient-flow --register-invariants`;
  - 10-15 invariants (todos os DoDs de fatia) listados.

DoD do PR 5.5:

- [x] Doc principal atualizado.
- [x] Doc caderno atualizado.
- [x] Cert local registrado (`available`).
- [x] **Memoria provider-safe atualizada** se necessario.

### 12.3 DoD Operacional Da Fatia 5

- Atlas AI Desktop Mac responde usando o fluxo novo como surface primaria.
- CLI Dev, App e API respondem usando o fluxo novo como paridade.
- `composer test --filter=AtlasDev` + features verde.
- Comando `atlas:dev:run` em produzao local funciona em todas as surfaces.
- EscalationDecision aparece corretamente em receipts R4+.
- **Esta equipe termina aqui.** Codigo entregue, contrato `available`. Medicao = outra equipe.

---

## 13. Risk Register De Implementacao

| Risco | Severidade | Mitigacao |
| --- | --- | --- |
| Duplicar service em vez de evoluir | alta | Code review enforces reuse map (secao 3). Cert verifica que nao ha `AtlasDevWorkflowServiceV2`. |
| Quality check do prompt nao detectar contaminacao | alta | Testes explicitos de injection ("rivals", "benchmark", "opus") + cert. |
| Receipt nao persistido por crash mid-write | media | Atomic write (tmpfile + rename), verificado em PR 1.5.4. |
| Fallback escondido para outro modelo durante repair | alta | `provider_lock.fallback_allowed=false` enforced em adapter, testado. |
| Validators rejeitando casos validos por bug | media | Cada validator tem case valido completo no teste antes dos cases invalidos. |
| Hashing nao determinístico (chaves nao ordenadas) | alta | Round-trip test em todo DTO. |
| Discovery alucinar path | alta | `is_file()` check enforced + path nao validado vai para missing_refs. |
| Surface adapter quebrar surfaces existentes | media | Feature tests por surface + manter caminho antigo ate `--efficient` ser opt-in. |
| Outro agente sobrescrever este doc enquanto equipe trabalha | media | Doc bem versionado; PRs nao devem editar este runbook em paralelo. |

## 14. Testing Strategy (Engenharia, Nao Competitiva)

Esta secao define **testes mecanicos** que validam o codigo. Nao define benchmark, scoring, oraculos competitivos ou avaliacao Sonnet vs Opus — isso e de outra equipe.

### 14.1 Unit Tests

- Cobertura minima: 95% nas pastas `AtlasDev/`.
- Cada DTO: 5 testes obrigatorios (ver secao 5.5).
- Cada validator: 1 valid + N invariants.
- Cada service: cenario feliz + 2-3 edge cases.

### 14.2 Feature Tests

- 4 end-to-end (plan-only, one-call, repair, surface).
- Gateway mock que simula `claude_cli` retornando diff valido/invalido controladamente.
- Workspace fixture em `tests/Fixtures/AtlasDev/workspace_atlas_server_clone/` (mini-replica de estrutura).

### 14.3 Manual Smoke (uma vez por fatia)

Apos cada fatia ficar verde em CI, **smoke test manual**:

- Fatia 0: nao tem; e so codigo de schema.
- Fatia 1: rodar `php artisan atlas:dev:plan "test"` em modo dry e inspecionar JSON.
- Fatia 1.5: rodar plan + inspecionar `storage/atlas-dev/receipts/<run_id>/`.
- Fatia 2: rodar plan-only em workspace real.
- Fatia 3: rodar one-call com Sonnet real em caso seguro (typo em comentario).
- Fatia 4: induzir failure (mexer no teste) e rodar repair.
- Fatia 5: cada surface (CLI, Desktop, App, API) executando o fluxo.

Smoke test **nao** e medicao. E sanidade operacional.

### 14.4 Memoria Provider-Safe E Cert

Ao final de cada fatia, atualizar memoria do Atlas (`php artisan atlas:memory:upsert ...`) com:

- decisao de implementacao da fatia;
- evidence path do PR;
- estado da fatia (`completed`).

E cert engineering:

- `php artisan atlas:engineering:cert atlas-dev-efficient-flow --slice=<n> --status=passed`.

## 15. Definicoes E Glossary

- **Fast path**: caminho default Atlas Dev, baixo custo, governanca compacta.
- **Heavy path**: Forge, governanca pesada, evidence/replay/topology.
- **R-level**: classificacao de risco R0-R5; define gates minimos e estado maximo sem Forge.
- **Mode**: `read_only | plan_only | patch | repair | escalate_preview`.
- **Receipt**: payload deterministico que prova o que aconteceu (ScopeGuardReceipt, VerificationReceipt).
- **Capsule**: `FailureCapsule`, input para repair.
- **DoD**: definition of done; lista verificavel mecanicamente.
- **Provider lock**: contrato que impede troca de provider/model durante run.
- **Hash de identidade**: sha256 sobre JSON canonical, identifica artefato de forma estavel.

## 15.1 Seção Operacional GO (P0/P1 fechados)

Estado canônico do fluxo apos fechamento dos P0/P1. Operador habilita Atlas Dev em produção/staging seguindo a checklist abaixo.

### 15.1.1 Config canônica

- Arquivo único: `config/atlas_dev.php`. `config/atlas.php` **não é fonte** do fluxo.
- Chaves canônicas:
  - `atlas_dev.efficient.plan_enabled` (env `ATLAS_DEV_EFFICIENT_PLAN_ENABLED`) — default `false`.
  - `atlas_dev.efficient.run_enabled` (env `ATLAS_DEV_EFFICIENT_RUN_ENABLED`) — default `false`.
  - `atlas_dev.efficient.desktop_enabled` (env `ATLAS_DEV_EFFICIENT_DESKTOP_ENABLED`, quando presente) — gate da surface Desktop.
  - `atlas_dev.confirmation_token.ttl_seconds` — TTL do confirmation_token (default 300s).
  - `atlas_dev.receipts_path` — diretório de receipts no filesystem.
- Desktop envia o slug do Projeto selecionado; o endpoint Plan resolve esse slug por `config/atlas_projects.php` para `workspace_path` existente antes de chamar o core. CLI/App/API podem enviar path absoluto diretamente. Slug sem path acessivel falha 422 e nao gera token de run.

### 15.1.2 Pré-requisitos antes de habilitar

1. **APP_KEY**: base64 com ≥32 bytes. Sem isso, Plan/Run falham fechado com `ATLAS_DEV_KEY_MISSING` (500). Rotacionar via `php artisan key:generate` e confirmar comprimento decodificado ≥32 bytes.
2. **Migrations obrigatórias** (já em `database/migrations/`):
   - `atlas_dev_confirmation_tokens` — tokens DB+HMAC, single-use, vinculados a `(run_id, task_contract_hash)`.
   - `atlas_dev_run_index` — cache de status/completion_state usado pelo Show REST e por dashboards.
   Verificar com `php artisan migrate:status | grep atlas_dev`.
3. **Storage**: `storage/atlas-dev/receipts/` precisa existir e ser gravável pelo processo PHP.
4. **Surface adapter**: para Desktop, `surface_id = atlas_desktop_ai` é canônico; `flow_id = atlas_dev`; `flow_origin = atlas_ai_router` (vindo do Router) ou `direct` (chamadas técnicas).

### 15.1.3 Habilitar Plan e Run

Ordem segura:

1. Subir backend com migrations aplicadas + APP_KEY válida.
2. `ATLAS_DEV_EFFICIENT_PLAN_ENABLED=true` (Plan zero-provider, seguro habilitar primeiro).
3. Confirmar smoke: `php artisan atlas:dev:smoke --json` retorna `routing.kind` esperado e `persisted_artifact_refs` relativos.
4. `ATLAS_DEV_EFFICIENT_RUN_ENABLED=true` apenas depois de Plan verde em produção.
5. Para a surface Desktop: `ATLAS_DEV_EFFICIENT_DESKTOP_ENABLED=true` (quando o flag existir no ambiente).

Desabilitação rápida: setar a flag correspondente para `false` — o controller retorna `ATLAS_DEV_PLAN_DISABLED` / `ATLAS_DEV_RUN_DISABLED` (503) sem efeitos colaterais.

### 15.1.4 Confirmation token (DB+HMAC, single-use)

- Plan emite token plaintext **apenas uma vez** na response (campo `confirmation.token`); nunca persistido em plaintext.
- DB guarda `token_hash = hmac_sha256(APP_KEY, plaintext)`.
- TTL = `atlas_dev.confirmation_token.ttl_seconds` (default 300s).
- Vinculo `(run_id, task_contract_hash)` enforced: token de outro plan é rejeitado com 403 `CONFIRMATION_TOKEN_CONTRACT_MISMATCH`.
- Single-use via marca atômica no DB; reutilização rejeita 403 `CONFIRMATION_TOKEN_ALREADY_CONSUMED`.
- APP_KEY ausente/curta → 500 `ATLAS_DEV_KEY_MISSING` (fail-closed).

### 15.1.5 Run exige operator_confirmed + token

Body obrigatório:

```json
{ "run_id": "...", "task_contract_hash": "...", "confirmation_token": "...", "operator_confirmed": true }
```

Erros canônicos:

- `operator_confirmed` ausente, falsy, truthy-string ou `1` → 400 `OPERATOR_NOT_CONFIRMED`.
- `task_contract_hash` mismatch → 422 `TASK_CONTRACT_HASH_MISMATCH`.
- `confirmation_token` ausente/inválido/expirado/consumido/contract-mismatch → 403 `CONFIRMATION_TOKEN_*`.

### 15.1.6 Stream snapshot-close + REST fallback

- `GET /runs/{run_id}/stream` faz **snapshot-replay-then-close**: replay deterministico de `phase:`, `receipt:` (se houver) e `stream_closed:`, depois fecha. Não há long-lived keepalive nesta fase.
- Cliente assume `stream_closed` como fim canônico; para qualquer estado intermediário ou retomada, consulta `GET /runs/{run_id}` (REST, fonte de verdade).
- Live async (tail de log) é evolução futura — o contrato do cliente já está estável.

### 15.1.7 Path redaction nas respostas HTTP (F-04 fechado)

- Toda response HTTP usa:
  - `workspace_label` = basename do workspace (sem `/Users/...`);
  - `workspace_hash` = identifier provider-safe;
  - `persisted_artifact_refs` = `receipts/<run_id>/<file>` (refs relativos, não paths absolutos);
  - `persisted_receipt_refs` no Run response (idem).
- Paths absolutos permanecem **só** internamente (storage, discovery, telemetria local).
- Desktop e telemetria/Sentry não devem logar paths absolutos desnecessários.

### 15.1.8 Run index (REST fallback rápido)

- Tabela `atlas_dev_run_index` espelha o estado por `run_id`: `surface_id`, `workspace_hash`, `flow_id`, `flow_origin`, `command_intent`, `completion_state`, hashes, timestamps.
- Show REST consulta primeiro o índice para reduzir IO no filesystem; receipts JSON continuam fonte de verdade.

### 15.1.9 Identidade do fluxo (intake invariants)

- Atlas AI = **produto** (entrypoint do usuário); Atlas Dev = **fluxo** workspace-dev.
- `flow_id = atlas_dev` sempre.
- `flow_origin` aceito: `atlas_ai_router` (payload vindo do Atlas AI Router) ou `direct` (chamadas técnicas/CLI).
- `command_intent` opcional, preenchido quando o payload já trouxer `routing_task/slash/mode` resolvido pelo Router.

### 15.1.10 Checklist de release

- [ ] APP_KEY base64 ≥32 bytes confirmada.
- [ ] Migrations `atlas_dev_confirmation_tokens` e `atlas_dev_run_index` aplicadas.
- [ ] `storage/atlas-dev/receipts/` gravável.
- [ ] Flags `plan_enabled` ligada e validada antes de `run_enabled`.
- [ ] Smoke: Plan retorna `confirmation.token` (fast_path), `persisted_artifact_refs` relativos, sem `/Users/` na response.
- [ ] Show retorna `workspace_label`/`workspace_hash` e `persisted_artifact_refs`; sem path absoluto.
- [ ] Run rejeita corretamente truthy-string, hash mismatch, token reutilizado.
- [ ] Stream emite `stream_closed` final; cliente cai em REST se a conexão for cortada.

## 16. Sequencia De Trabalho Recomendada Por Agente IA

Quando uma IA implementadora pegar este runbook:

1. Ler `atlas-dev-efficient-programming-flow-v1.md` (contrato principal) para tese.
2. Ler `atlas-dev-efficient-programming-flow-contracts-v1.md` (schemas) para artefatos.
3. Voltar a este runbook para sequencia.
4. Comecar pela **Fatia 0**, PR 0.1, sem pular nada.
5. Cada PR deve ter:
   - branch dedicada `atlas-dev-efficient-flow/fatia-<n>-pr-<x>`;
   - test-driven: escrever teste antes do codigo quando possivel;
   - DoD operacional do PR verde;
   - revisao humana antes do merge.
6. Marcar fatia como `completed` na memoria do Atlas apos DoD da fatia inteira.
7. Atualizar este runbook se alguma decisao mudar (sempre via PR).
8. **Nao** comecar Fatia 5 antes de Fatia 4 verde.
9. **Nao** introduzir benchmark, oraculos, Rivals ou Opus challenge em nenhum PR.

Apos Fatia 5 verde, esta equipe **transfere** o fluxo para a equipe de medicao com:

- doc atualizado;
- cert local `available`;
- conjunto de fixtures e smoke tests;
- runs de exemplo persistidos em `storage/atlas-dev/receipts/`.

A equipe de medicao desenha benchmark, oraculos e Rivals em outro contrato. Aqui termina.
