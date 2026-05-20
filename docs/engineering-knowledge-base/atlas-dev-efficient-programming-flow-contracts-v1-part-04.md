---
id: atlas-dev-efficient-programming-flow-contracts-v1-part-04
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow Contracts v1 · Parte 4
status: active
category: programming
priority: 104
summary: Recorte focado de Atlas Dev Efficient Programming Flow Contracts v1: 4.4 LightTaskContract ate 5.1 ContextRetrievalPlan.
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
graph_id: atlas-dev-efficient-programming-flow-contracts-v1-part-04
graph_title: Atlas Dev Efficient Programming Flow Contracts v1 Parte 4
graph_world: atlas
graph_layer: module
graph_kind: contract
graph_parent: atlas-dev-efficient-programming-flow-contracts-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-04.md
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
# Atlas Dev Efficient Programming Flow Contracts v1 · Parte 4

## Resumo

Este recorte preserva uma parte focada de Atlas Dev Efficient Programming Flow Contracts v1: 4.4 LightTaskContract ate 5.1 ContextRetrievalPlan.

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
### 4.4 LightTaskContract

Execution contract. Define o que o agente pode fazer, onde, com quais tools, e como recuperar se falhar.

#### Schema

```yaml
LightTaskContract:
  schema_version: atlas.dev.light_task_contract.v1
  run_id: string
  task_id: string                # UUID v7, separado de run_id
  spec_hash: string              # = mini_spec_hash
  owner: atlas_dev_sonnet
  allowed_tools: list            # ex: [read, write, grep, run_test]
  blocked_actions:
    - production_write
    - migration_apply
    - secret_access
    - broad_refactor
    - council_invoke
    - forge_invoke_direct
  allowed_files: list            # copiado de mini_spec
  watched_files: list            # arquivos que nao sao allowed mas se mudarem geram needs_review
  forbidden_files: list          # copiado de mini_spec
  max_files_changed: integer
  validation_commands: list      # copiado de verification_plan.commands
  evidence_required:
    - diff_hash
    - changed_files
    - test_output_hash|no_test_reason
    - scope_guard_receipt
    - verification_receipt
  repair_policy:
    max_attempts: integer        # depende do R-level
    same_provider: true
    requires_failed_gate_output: true
    abort_on_same_signature_twice: true
  escalation_on:
    - same_signature_failure_twice
    - diff_grew_without_progress
    - new_scope_appeared
    - test_failure_requires_architecture
    - context_required_exceeds_budget
    - risk_escalated_to_r4_or_above
  provider_lock:
    provider: claude_cli
    model_family: sonnet
    fallback_allowed: false
  policy_profile:                # Kernel estagio 9 (Policy / Profile)
    autonomy_level: assist|auto_with_confirmation|auto
    privacy_class: public|internal|confidential|restricted
    cost_budget_usd: number|null  # null = sem teto explicito; default por R-level
    sandbox_required: boolean    # true para R3+, false R0-R2
    decision_mode: manual_override|auto_best_allowed|auto_best_available  # casa com Kernel estagio 10
  provider_safe: true
  task_contract_hash: string
```

#### Invariants

1. `task_id` e UUID v7 distinto de `run_id`.
2. `spec_hash` deve corresponder a um `MiniProgrammingSpec` valido com mesmo `run_id`.
3. `allowed_files` e `forbidden_files` herdados literalmente de `MiniProgrammingSpec`.
4. `watched_files` e disjunto de `allowed_files` e `forbidden_files`.
5. `max_files_changed` <= 6 no fast path. Acima disso vira R4.
6. `repair_policy.max_attempts` segue tabela R-level (secao 9 do contrato principal).
7. `provider_lock.fallback_allowed = false` (decisao locked 2026-05-16). Mudar para true exige bump major.
8. `blocked_actions` deve incluir pelo menos: `production_write`, `migration_apply`, `secret_access`, `broad_refactor`.
9. Qualquer tool em `allowed_tools` que toque write deve coincidir com permission no envelope.
10. `policy_profile.autonomy_level = auto` exige `business_context.environment in (dev, staging)`. Production sempre exige `auto_with_confirmation` minimo.
11. `policy_profile.privacy_class = restricted` proibe envio de excerpts crus ao provider; apenas refs por hash.
12. `policy_profile.sandbox_required = true` exige Tool Runtime executar comandos isolados; verification gate falha se sandbox nao disponivel.
13. `policy_profile.decision_mode = manual_override` e o default atual (provider_lock fixo). `auto_best_allowed` requer Atlas Decide ativado para Programming; `auto_best_available` ignora budget e e fora do escopo desta fase.

#### Identidade

- Chave: `(run_id, task_id)`.
- Hash: `task_contract_hash`.

#### Exemplo Valido (Repair R2)

```yaml
schema_version: atlas.dev.light_task_contract.v1
run_id: "0192b5d2-..."
task_id: "0192b5d2-3001-7c4f-..."
spec_hash: "babecafe..."
owner: atlas_dev_sonnet
allowed_tools: [read, write, grep, run_test]
blocked_actions:
  - production_write
  - migration_apply
  - secret_access
  - broad_refactor
  - council_invoke
  - forge_invoke_direct
allowed_files:
  - "app/Services/Ai/Cli/AtlasCliDevWorkflowService.php"
watched_files: []
forbidden_files:
  - "vendor/*"
  - "node_modules/*"
max_files_changed: 1
validation_commands:
  - "composer test -- --filter=AtlasCliDevWorkflowServiceTest::test_workspace_resolution"
evidence_required:
  - diff_hash
  - changed_files
  - test_output_hash
  - scope_guard_receipt
  - verification_receipt
repair_policy:
  max_attempts: 1
  same_provider: true
  requires_failed_gate_output: true
  abort_on_same_signature_twice: true
escalation_on:
  - same_signature_failure_twice
  - diff_grew_without_progress
  - new_scope_appeared
provider_lock:
  provider: claude_cli
  model_family: sonnet
  fallback_allowed: false
provider_safe: true
task_contract_hash: "ace5beef..."
```

#### Exemplos Invalidos

| Caso | Motivo |
| --- | --- |
| `max_files_changed: 12` + `risk_level: R2` | Violacao de invariant 5 |
| `provider_lock.fallback_allowed: true` | Violacao de invariant 7 |
| `blocked_actions` sem `secret_access` | Violacao de invariant 8 |
| `allowed_files` discordando de `mini_spec.allowed_files` | Violacao de invariant 3 |

#### PHP DTO Signature

```php
final class LightTaskContract
{
    public function __construct(
        public readonly string $runId,
        public readonly string $taskId,
        public readonly string $specHash,
        public readonly array $allowedTools,
        public readonly array $blockedActions,
        public readonly array $allowedFiles,
        public readonly array $watchedFiles,
        public readonly array $forbiddenFiles,
        public readonly int $maxFilesChanged,
        public readonly array $validationCommands,
        public readonly array $evidenceRequired,
        public readonly RepairPolicy $repairPolicy,
        public readonly array $escalationOn,
        public readonly ProviderLock $providerLock,
        public readonly string $taskContractHash,
    ) {}

    public function allowsWrite(): bool { /* check allowed_tools */ }
    public function schemaVersion(): string { return 'atlas.dev.light_task_contract.v1'; }
}
```

---

## 5. Camada Contexto

### 5.1 ContextRetrievalPlan

Output deterministico do `DocContextTierSelector`. Lista tiers + budget + sources requeridas.

#### Schema

```yaml
ContextRetrievalPlan:
  schema_version: atlas.dev.context_retrieval_plan.v1
  run_id: string
  compact_sdd_hash: string
  tiers_selected: list           # subset de [core, code_intelligence, sdd, interface, forge, obras]
  budget:
    max_chars: integer
    reserved_for_core: integer
    reserved_for_code_intelligence: integer
  required_sources: list         # refs que nao podem faltar
  optional_sources: list
  excluded_sources: list         # explicitamente excluidos
  selection_reasons:             # por que cada tier entrou
    - tier: string
      reason: string
  provider_safe: true
  plan_hash: string
```

#### Invariants

1. `tiers_selected` segue regras da tabela 11.1 do contrato principal.
2. `core` esta em `tiers_selected` se `task_kind != question`.
3. `code_intelligence` esta em `tiers_selected` se `workspace_resolved = true`.
4. `forge` em `tiers_selected` implica `risk_level >= R4`.
5. `required_sources` so vazio se `task_kind = question` em modo read-only.
6. `budget.reserved_for_core + budget.reserved_for_code_intelligence <= budget.max_chars`.

#### Identidade

- Chave: `(run_id, plan_hash)`.

#### Exemplo Valido

```yaml
schema_version: atlas.dev.context_retrieval_plan.v1
run_id: "0192b5d2-..."
compact_sdd_hash: "feedcafe..."
tiers_selected: [core, code_intelligence, sdd]
budget:
  max_chars: 12000
  reserved_for_core: 3000
  reserved_for_code_intelligence: 6000
required_sources:
  - "atlas-dev-efficient-programming-flow-v1.md#section-9"
  - "code_intelligence://symbol/AtlasCliDevWorkflowService"
optional_sources:
  - "atlas-programming-governance-system.md"
excluded_sources:
  - "atlas-forge-operating-system.md"  # task nao e Forge
selection_reasons:
  - tier: core
    reason: "task_kind != question"
  - tier: code_intelligence
    reason: "workspace presente, codigo envolvido"
  - tier: sdd
    reason: "task_kind = repair, multi-arquivo possivel"
provider_safe: true
plan_hash: "c0ffee..."
```

#### PHP DTO Signature

```php
final class ContextRetrievalPlan
{
    public function __construct(
        public readonly string $runId,
        public readonly string $compactSddHash,
        public readonly array $tiersSelected,
        public readonly ContextBudget $budget,
        public readonly array $requiredSources,
        public readonly array $optionalSources,
        public readonly array $excludedSources,
        public readonly array $selectionReasons,
        public readonly string $planHash,
    ) {}

    public function schemaVersion(): string { return 'atlas.dev.context_retrieval_plan.v1'; }
}
```

---

