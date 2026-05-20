---
id: atlas-dev-efficient-programming-flow-runbook-v1-part-04
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow Runbook v1 · Parte 4
status: active
category: programming
priority: 104
summary: Recorte focado do runbook Atlas Dev Efficient Programming Flow v1: 8.2 PRs Sugeridos ate 9.1 Objetivo.
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
graph_id: atlas-dev-efficient-programming-flow-runbook-v1-part-04
graph_title: Atlas Dev Efficient Programming Flow Runbook v1 Parte 4
graph_world: atlas
graph_layer: module
graph_kind: runbook
graph_parent: atlas-dev-efficient-programming-flow-runbook-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-04.md
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
# Atlas Dev Efficient Programming Flow Runbook v1 · Parte 4

## Resumo

Este recorte preserva uma parte operacional do runbook Atlas Dev Efficient Programming Flow v1: 8.2 PRs Sugeridos ate 9.1 Objetivo.

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

