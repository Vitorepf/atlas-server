---
id: atlas-dev-efficient-programming-flow-contracts-v1-part-06
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow Contracts v1 · Parte 6
status: active
category: programming
priority: 104
summary: Recorte focado de Atlas Dev Efficient Programming Flow Contracts v1: 6.1 ScopeGuardReceipt ate 6.2 VerificationReceipt.
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
graph_id: atlas-dev-efficient-programming-flow-contracts-v1-part-06
graph_title: Atlas Dev Efficient Programming Flow Contracts v1 Parte 6
graph_world: atlas
graph_layer: module
graph_kind: contract
graph_parent: atlas-dev-efficient-programming-flow-contracts-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-06.md
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
# Atlas Dev Efficient Programming Flow Contracts v1 · Parte 6

## Resumo

Este recorte preserva uma parte focada de Atlas Dev Efficient Programming Flow Contracts v1: 6.1 ScopeGuardReceipt ate 6.2 VerificationReceipt.

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
### 6.1 ScopeGuardReceipt

Diff observado vs escopo declarado. Bloqueia completion quando ha violacao.

#### Schema

```yaml
ScopeGuardReceipt:
  schema_version: atlas.dev.scope_guard_receipt.v1
  run_id: string
  task_contract_hash: string
  baseline:
    git_status_before: string     # output do git status pre-execucao
    git_diff_before_hash: string|null
  observed:
    git_diff_hash: string|null
    changed_files: list
    changed_files_count: integer
    file_diffs:
      - path: string
        added: integer
        removed: integer
        file_hash_after: string
  scope_contract:
    allowed_files: list
    watched_files: list
    forbidden_files: list
    expected_max_files: integer
  violations:
    - kind: forbidden_touch|watched_touch|unexpected_touch|exceeded_max_files|pre_existing_change
      path: string|null
      detail: string
  status: passed|failed|needs_review
  status_reason: string
  user_pre_existing_changes:      # mudancas que ja estavam no worktree
    - path: string
      preserved: boolean
  provider_safe: true
  receipt_hash: string
```

#### Invariants

1. `changed_files_count = len(changed_files)`.
2. Qualquer `violations[].kind = forbidden_touch` forca `status = failed`.
3. `violations[].kind = unexpected_touch` forca `status = needs_review` (nao failed).
4. `violations[].kind = exceeded_max_files` forca `status = failed`.
5. `user_pre_existing_changes` deve ser preservado; `preserved: false` em qualquer item forca `status = failed`.
6. `status = passed` exige `violations` vazio.
7. `receipt_hash` calculado sobre todo o payload (exclui o proprio).

#### Identidade

- Chave: `(run_id, receipt_hash)`.

#### PHP DTO Signature

```php
final class ScopeGuardReceipt
{
    public function __construct(
        public readonly string $runId,
        public readonly string $taskContractHash,
        public readonly ScopeBaseline $baseline,
        public readonly ScopeObserved $observed,
        public readonly ScopeContractView $scopeContract,
        public readonly array $violations,
        public readonly string $status,
        public readonly string $statusReason,
        public readonly array $userPreExistingChanges,
        public readonly string $receiptHash,
    ) {}

    public function isBlocking(): bool { return $this->status === 'failed'; }
    public function schemaVersion(): string { return 'atlas.dev.scope_guard_receipt.v1'; }
}
```

---

### 6.2 VerificationReceipt

Receipt final. Consolida gates, testes, custo, completion state, escalation.

#### Schema

```yaml
VerificationReceipt:
  schema_version: atlas.dev.verification_receipt.v1
  run_id: string
  task_contract_hash: string
  workspace_hash: string
  task_kind: question|patch|repair|review|frontend|risky
  risk_level: R0|R1|R2|R3|R4|R5
  provider: claude_cli
  model: string                     # ex: "claude-sonnet-4-6"
  context_pack_hash: string
  prompt_projection_hash: string
  scope_guard_receipt_hash: string|null
  diff_hash: string|null
  changed_files: list
  file_hashes: map                  # path -> sha256 do conteudo apos
  evidence_refs:                    # paths absolutos para storage + ref opcional do ledger Governance
    - kind: diff|test_log|lint_log|screenshot|manual_review|no_patch_reason
      path: string
      hash: string
      governance_ledger_ref: string|null
  gates:
    - name: string                  # ex: scope_guard_light
      status: passed|failed|needs_review|skipped|waived
      required: boolean
      evidence_ref: string|null
      fresh: boolean                # roda nesta run, nao cache
      waiver_reason: string|null
  tests:
    - command: string
      ok: boolean
      exit_code: integer
      duration_ms: integer
      output_hash: string
      output_path: string|null      # path do log persistido
  repair:
    attempt_count: integer
    failure_capsule_refs: list      # paths dos failure_capsule.json
    converted_to_green: boolean
  cost:
    provider_calls: integer
    tokens_in: integer|null
    tokens_out: integer|null
    estimated_cost_usd: number|null
    wall_time_ms: integer|null
  completion:
    status: passed|needs_review|failed|blocked|escalate_forge|no_patch_needed
    honesty_flags: list             # ex: ["test_skipped_no_reason", "scope_expanded"]
    residual_risks: list
  escalation:
    recommended: boolean
    target: null|forge|obra_candidate
    reasons: list
    decision_ref: string|null       # path do EscalationDecision se gerado
  provider_safe: true
  receipt_hash: string
```

#### Invariants

1. `completion.status = passed` exige:
   - todos `gates[].required = true` com `status = passed`;
   - `scope_guard_receipt.status = passed`;
   - se houver `tests`, pelo menos um com `ok = true` ou um `evidence_refs[].kind = no_patch_reason`.
2. `completion.status = passed` proibe `honesty_flags` nao vazio.
3. `completion.status = needs_review` se houver `honesty_flags` mas evidencia minima presente.
4. `completion.status = escalate_forge` forca `escalation.recommended = true` E `escalation.target != null`.
5. `provider` e `model` registrados literalmente; nao mascarar fallback.
6. `repair.attempt_count <= task_contract.repair_policy.max_attempts`.
7. `gates[].fresh = true` para todo gate `required = true` (nao confiar em cache).
8. `cost.provider_calls >= 1` exceto em `mode = read_only`.

#### Identidade

- Chave: `(run_id, receipt_hash)`.

#### Exemplo Valido (Patch R2 Passed)

```yaml
schema_version: atlas.dev.verification_receipt.v1
run_id: "0192b5d2-..."
task_contract_hash: "ace5beef..."
workspace_hash: "a1b2c3..."
task_kind: repair
risk_level: R2
provider: claude_cli
model: "claude-sonnet-4-6"
context_pack_hash: "c0ffee..."
prompt_projection_hash: "f00dface..."
scope_guard_receipt_hash: "ace0..."
diff_hash: "deadc0de..."
changed_files:
  - "app/Services/Ai/Cli/AtlasCliDevWorkflowService.php"
file_hashes:
  "app/Services/Ai/Cli/AtlasCliDevWorkflowService.php": "abcd1234..."
evidence_refs:
  - kind: diff
    path: "storage/atlas-dev/receipts/<run_id>/diff.patch"
    hash: "deadc0de..."
    governance_ledger_ref: "atlas_engineering_evidence:123"
  - kind: test_log
    path: "storage/atlas-dev/receipts/<run_id>/test.log"
    hash: "1234abcd..."
    governance_ledger_ref: "atlas_engineering_evidence:124"
gates:
  - name: scope_guard_light
    status: passed
    required: true
    evidence_ref: "storage/atlas-dev/receipts/<run_id>/scope_guard_receipt.json"
    fresh: true
    waiver_reason: null
  - name: verification_gate
    status: passed
    required: true
    evidence_ref: "storage/atlas-dev/receipts/<run_id>/test.log"
    fresh: true
    waiver_reason: null
tests:
  - command: "composer test -- --filter=AtlasCliDevWorkflowServiceTest::test_workspace_resolution"
    ok: true
    exit_code: 0
    duration_ms: 1342
    output_hash: "1234abcd..."
    output_path: "storage/atlas-dev/receipts/<run_id>/test.log"
repair:
  attempt_count: 0
  failure_capsule_refs: []
  converted_to_green: false
cost:
  provider_calls: 1
  tokens_in: 3420
  tokens_out: 412
  estimated_cost_usd: 0.018
  wall_time_ms: 8431
completion:
  status: passed
  honesty_flags: []
  residual_risks: []
escalation:
  recommended: false
  target: null
  reasons: []
  decision_ref: null
provider_safe: true
receipt_hash: "ledgerend..."
```

#### Exemplos Invalidos

| Caso | Motivo |
| --- | --- |
| `completion.status: passed` + `honesty_flags: ["scope_expanded"]` | Violacao de invariant 2 |
| `completion.status: passed` + `gates[].status: failed` (required true) | Violacao de invariant 1 |
| `repair.attempt_count: 3` + `task_contract.max_attempts: 1` | Violacao de invariant 6 |
| `completion.status: escalate_forge` + `escalation.target: null` | Violacao de invariant 4 |
| `provider: claude_cli` + na realidade rodou Codex | Violacao de invariant 5 (mascaramento) |

#### PHP DTO Signature

```php
final class VerificationReceipt
{
    public function __construct(
        public readonly string $runId,
        public readonly string $taskContractHash,
        public readonly string $workspaceHash,
        public readonly string $taskKind,
        public readonly string $riskLevel,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $contextPackHash,
        public readonly string $promptProjectionHash,
        public readonly ?string $scopeGuardReceiptHash,
        public readonly ?string $diffHash,
        public readonly array $changedFiles,
        public readonly array $fileHashes,
        public readonly array $evidenceRefs,
        public readonly array $gates,
        public readonly array $tests,
        public readonly RepairSummary $repair,
        public readonly CostSummary $cost,
        public readonly CompletionSummary $completion,
        public readonly EscalationSummary $escalation,
        public readonly string $receiptHash,
    ) {}

    public function isPassed(): bool { return $this->completion->status === 'passed'; }
    public function schemaVersion(): string { return 'atlas.dev.verification_receipt.v1'; }
}
```

---

