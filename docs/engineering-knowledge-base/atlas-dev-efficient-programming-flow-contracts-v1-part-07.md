---
id: atlas-dev-efficient-programming-flow-contracts-v1-part-07
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow Contracts v1 · Parte 7
status: active
category: programming
priority: 104
summary: Recorte focado de Atlas Dev Efficient Programming Flow Contracts v1: 6.3 FailureCapsule ate 7.1 FastPathTelemetry.
tags:
  - atlas-dev
  - split-doc
  - cartography-readable
capabilities:
  - atlas_documentation_split
  - atlas_cartography_readable_docs
decisions:
  - Este recorte preserva uma parte operacional do documento maior sem ampliar responsabilidade do índice canônico.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar junto com docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md quando o documento dono mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-efficient-programming-flow-contracts-v1-part-07
graph_title: Atlas Dev Efficient Programming Flow Contracts v1 Parte 7
graph_world: atlas
graph_layer: module
graph_kind: contract
graph_parent: atlas-dev-efficient-programming-flow-contracts-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-07.md
allowed_changes:
  - Atualizar somente a parte descrita neste recorte.
forbidden_changes:
  - Adicionar nova responsabilidade que pertença ao índice ou a outro recorte.
depends_on:
  - atlas-dev-efficient-programming-flow-contracts-v1
flows_to:
  - atlas-dev-efficient-programming-flow-contracts-v1
unlocks:
  - atlas_cartography_readable_documentation
governs:
  - atlas.documentation.split_docs
evidence:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Manter este recorte alinhado ao índice canônico e ao contrato de documentação.
---
# Atlas Dev Efficient Programming Flow Contracts v1 · Parte 7

## Resumo

Este recorte preserva uma parte focada de Atlas Dev Efficient Programming Flow Contracts v1: 6.3 FailureCapsule ate 7.1 FastPathTelemetry.

## Papel no Atlas

Mantém detalhe canônico fora do índice principal para que a cartografia e o modal humano continuem legíveis.

## Onde Se Encaixa

É filho canônico de `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md` e deve ser lido apenas quando a pessoa precisar deste detalhe.

## Contratos

Segue o documento dono, o glossário canônico e o modelo obrigatório de documentação do Atlas.

## Fluxo

Índice canônico → recorte focado → decisão, implementação ou revisão correspondente.

## Regras para IA

Não inferir responsabilidade nova. Não misturar patamar, versão, fonte, risco, regra ou prova. Preservar backlink para o índice.

## Escopo de Implementacao

Este arquivo só guarda o detalhe extraído do documento maior.

## Dependencias

Depende do índice `atlas-dev-efficient-programming-flow-contracts-v1` e da documentação canônica relacionada.

## Evidencias

A evidência de origem é o documento principal e o `docs-health` verde depois da divisão.

## Riscos

Risco principal: alguém editar este recorte como se fosse novo dono de fluxo, duplicando contrato.

## Exemplos

Os exemplos abaixo são o conteúdo extraído, preservado sem perda semântica.

## Proximas Acoes

Atualizar este recorte quando a parte correspondente mudar e rodar docs-health.

## Conteudo Extraido
### 6.3 FailureCapsule

Input deterministico para repair. Uma por tentativa. Contem erro real, nao "falhou de novo".

#### Schema

```yaml
FailureCapsule:
  schema_version: atlas.dev.failure_capsule.v1
  run_id: string
  task_contract_hash: string
  attempt_index: integer
  gate: string                    # ex: verification_gate, scope_guard_light
  command: string|null
  exit_code: integer|null
  primary_error_excerpt: string   # max 4kb, suficiente para erro principal
  full_error_log_path: string|null # path para log completo se relevante
  failing_test: string|null
  diff_hash: string|null
  changed_files: list
  failure_signature: string       # hash estavel para detectar repeticao
  decision: retry|stop|escalate
  should_have_escalated: boolean|null  # preenchido post-hoc por revisor
  escalation_signal_delta: list   # quais sinais teriam disparado escalada
  post_hoc_reviewer: string|null
  post_hoc_reviewed_at: string|null  # ISO 8601
  provider_safe: true
  capsule_hash: string
```

#### Invariants

1. `failure_signature` e estavel sobre o mesmo erro: `sha256(gate + "::" + normalize(primary_error_excerpt))`. Repeticao detecta loop.
2. `decision = retry` exige `attempt_index < task_contract.repair_policy.max_attempts`.
3. `decision = escalate` exige preenchimento de `escalation_signal_delta`.
4. `primary_error_excerpt` nunca vazio quando `exit_code != 0` ou `failing_test != null`.
5. `should_have_escalated`, `post_hoc_reviewer`, `post_hoc_reviewed_at` sao null no nascimento; preenchidos depois por revisor (nao apaga, so add).
6. `capsule_hash` ignora os 4 campos post-hoc (eles podem mudar sem invalidar a capsule).

#### Identidade

- Chave: `(run_id, attempt_index)`.

#### Exemplo Valido (Tentativa 1 Falhou)

```yaml
schema_version: atlas.dev.failure_capsule.v1
run_id: "0192b5d2-..."
task_contract_hash: "ace5beef..."
attempt_index: 1
gate: verification_gate
command: "composer test -- --filter=AtlasCliDevWorkflowServiceTest::test_workspace_resolution"
exit_code: 1
primary_error_excerpt: |
  FAIL  Tests\Unit\AtlasCliDevWorkflowServiceTest
   ⨯ test workspace resolution
   InvalidArgumentException: workspace must not be null
   at app/Services/Ai/Cli/AtlasCliDevWorkflowService.php:78
full_error_log_path: "storage/atlas-dev/receipts/<run_id>/failure_capsule.1.log"
failing_test: "tests/Unit/AtlasCliDevWorkflowServiceTest.php::test_workspace_resolution"
diff_hash: "deadc0de..."
changed_files:
  - "app/Services/Ai/Cli/AtlasCliDevWorkflowService.php"
failure_signature: "sha256:verification_gate::InvalidArgumentException::workspace_null"
decision: retry
should_have_escalated: null
escalation_signal_delta: []
post_hoc_reviewer: null
post_hoc_reviewed_at: null
provider_safe: true
capsule_hash: "abadcafe..."
```

#### PHP DTO Signature

```php
final class FailureCapsule
{
    public function __construct(
        public readonly string $runId,
        public readonly string $taskContractHash,
        public readonly int $attemptIndex,
        public readonly string $gate,
        public readonly ?string $command,
        public readonly ?int $exitCode,
        public readonly string $primaryErrorExcerpt,
        public readonly ?string $fullErrorLogPath,
        public readonly ?string $failingTest,
        public readonly ?string $diffHash,
        public readonly array $changedFiles,
        public readonly string $failureSignature,
        public readonly string $decision,
        public readonly ?bool $shouldHaveEscalated,
        public readonly array $escalationSignalDelta,
        public readonly ?string $postHocReviewer,
        public readonly ?string $postHocReviewedAt,
        public readonly string $capsuleHash,
    ) {}

    public function withPostHocReview(string $reviewer, bool $shouldHaveEscalated, array $delta): self { /* monotonic append */ }
    public function schemaVersion(): string { return 'atlas.dev.failure_capsule.v1'; }
}
```

---

### 6.4 EscalationDecision

Quando o fast path para e gera preview para Forge.

#### Schema

```yaml
EscalationDecision:
  schema_version: atlas.dev.escalation_decision.v1
  run_id: string
  task_contract_hash: string
  triggered_at: string            # ISO 8601
  target: forge|obra_candidate
  reasons: list                   # quais condicoes acionaram
  signals:
    file_count: integer
    layers_touched: integer
    risk_keywords: list
    context_required_chars: integer|null
    thread_messages: integer|null
    prior_failure_count: integer
  score: integer                  # 0-10
  human_action_required: boolean
  preview_artifact_path: string|null  # path do Forge promotion preview
  was_correct: boolean|null       # post-hoc
  post_hoc_reviewer: string|null
  post_hoc_reviewed_at: string|null
  provider_safe: true
  decision_hash: string
```

#### Invariants

1. `target = forge` exige `score >= 7` OU `risk_level >= R4`.
2. `target = obra_candidate` exige `score >= 4`.
3. `reasons` nao vazio.
4. `human_action_required = true` quando `target = forge`.
5. `was_correct`, `post_hoc_reviewer`, `post_hoc_reviewed_at` sao null no nascimento; append-only depois.

#### Identidade

- Chave: `(run_id, decision_hash)`.

#### PHP DTO Signature

```php
final class EscalationDecision
{
    public function __construct(
        public readonly string $runId,
        public readonly string $taskContractHash,
        public readonly string $triggeredAt,
        public readonly string $target,
        public readonly array $reasons,
        public readonly EscalationSignals $signals,
        public readonly int $score,
        public readonly bool $humanActionRequired,
        public readonly ?string $previewArtifactPath,
        public readonly ?bool $wasCorrect,
        public readonly ?string $postHocReviewer,
        public readonly ?string $postHocReviewedAt,
        public readonly string $decisionHash,
    ) {}

    public function schemaVersion(): string { return 'atlas.dev.escalation_decision.v1'; }
}
```

---

## 7. Camada Telemetria

### 7.1 FastPathTelemetry

Sinal operacional desde dia 1. Permite inspecionar runs sem benchmark.

#### Schema

```yaml
FastPathTelemetry:
  schema_version: atlas.dev.fast_path_telemetry.v1
  run_id: string
  workspace_hash: string
  task_kind: question|patch|repair|review|frontend|risky
  risk_level: R0|R1|R2|R3|R4|R5
  prompt_projection_hash: string
  contract_completeness_status: passed|failed|needs_review
  doc_tiers_selected: list
  gates_activated: list
  provider: claude_cli
  model: string
  provider_calls: integer
  repair_attempts: integer
  cost_estimate_usd: number|null
  wall_time_ms: integer|null
  completion_state: passed|needs_review|failed|blocked|escalate_forge|no_patch_needed
  escalation_triggered: boolean
  escalation_was_correct: boolean|null
  receipt_persisted: boolean
  error_ledger_written: boolean
  provider_safe: true
  telemetry_hash: string
```

#### Invariants

1. Emitido **uma vez por run**, no fim (mesmo em `blocked` ou `failed`).
2. `receipt_persisted = false` indica bug operacional, nao falha legitima.
3. `error_ledger_written = true` quando `completion_state in (failed, needs_review, escalate_forge)`.
4. `escalation_was_correct` e null ate revisao post-hoc.
5. `telemetry_hash` calculado excluindo `escalation_was_correct` (post-hoc nao invalida telemetria do run).

#### Identidade

- Chave: `(run_id)`.

#### PHP DTO Signature

```php
final class FastPathTelemetry
{
    public function __construct(
        public readonly string $runId,
        public readonly string $workspaceHash,
        public readonly string $taskKind,
        public readonly string $riskLevel,
        public readonly string $promptProjectionHash,
        public readonly string $contractCompletenessStatus,
        public readonly array $docTiersSelected,
        public readonly array $gatesActivated,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $providerCalls,
        public readonly int $repairAttempts,
        public readonly ?float $costEstimateUsd,
        public readonly ?int $wallTimeMs,
        public readonly string $completionState,
        public readonly bool $escalationTriggered,
        public readonly ?bool $escalationWasCorrect,
        public readonly bool $receiptPersisted,
        public readonly bool $errorLedgerWritten,
        public readonly string $telemetryHash,
    ) {}

    public function schemaVersion(): string { return 'atlas.dev.fast_path_telemetry.v1'; }
}
```

---

