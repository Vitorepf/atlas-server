---
id: atlas-dev-efficient-programming-flow-runbook-v1-part-06
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow Runbook v1 · Parte 6
status: active
category: programming
priority: 104
summary: Recorte focado do runbook Atlas Dev Efficient Programming Flow v1: 10.2 PRs Sugeridos ate 10.3 DoD Operacional Da Fatia 3.
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
graph_id: atlas-dev-efficient-programming-flow-runbook-v1-part-06
graph_title: Atlas Dev Efficient Programming Flow Runbook v1 Parte 6
graph_world: atlas
graph_layer: module
graph_kind: runbook
graph_parent: atlas-dev-efficient-programming-flow-runbook-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-06.md
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
# Atlas Dev Efficient Programming Flow Runbook v1 · Parte 6

## Resumo

Este recorte preserva uma parte operacional do runbook Atlas Dev Efficient Programming Flow v1: 10.2 PRs Sugeridos ate 10.3 DoD Operacional Da Fatia 3.

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

