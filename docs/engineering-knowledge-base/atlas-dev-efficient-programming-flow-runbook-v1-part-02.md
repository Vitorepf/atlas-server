---
id: atlas-dev-efficient-programming-flow-runbook-v1-part-02
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow Runbook v1 · Parte 2
status: active
category: programming
priority: 104
summary: Recorte focado do runbook Atlas Dev Efficient Programming Flow v1: 6.2 PRs Sugeridos ate 7.1 Objetivo.
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
graph_id: atlas-dev-efficient-programming-flow-runbook-v1-part-02
graph_title: Atlas Dev Efficient Programming Flow Runbook v1 Parte 2
graph_world: atlas
graph_layer: module
graph_kind: runbook
graph_parent: atlas-dev-efficient-programming-flow-runbook-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-02.md
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
# Atlas Dev Efficient Programming Flow Runbook v1 · Parte 2

## Resumo

Este recorte preserva uma parte operacional do runbook Atlas Dev Efficient Programming Flow v1: 6.2 PRs Sugeridos ate 7.1 Objetivo.

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

