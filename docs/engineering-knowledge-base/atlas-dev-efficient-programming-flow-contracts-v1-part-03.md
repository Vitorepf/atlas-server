---
id: atlas-dev-efficient-programming-flow-contracts-v1-part-03
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow Contracts v1 · Parte 3
status: active
category: programming
priority: 104
summary: Recorte focado de Atlas Dev Efficient Programming Flow Contracts v1: 4.3 MiniProgrammingSpec.
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
graph_id: atlas-dev-efficient-programming-flow-contracts-v1-part-03
graph_title: Atlas Dev Efficient Programming Flow Contracts v1 Parte 3
graph_world: atlas
graph_layer: module
graph_kind: contract
graph_parent: atlas-dev-efficient-programming-flow-contracts-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-03.md
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
# Atlas Dev Efficient Programming Flow Contracts v1 · Parte 3

## Resumo

Este recorte preserva uma parte focada de Atlas Dev Efficient Programming Flow Contracts v1: 4.3 MiniProgrammingSpec.

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
### 4.3 MiniProgrammingSpec

Behavior contract. **Obrigatorio para todo write**. Nasce do `CompactSDD` + retrieval, e a fonte canonica do que se espera do patch.

#### Schema

```yaml
MiniProgrammingSpec:
  schema_version: atlas.dev.mini_programming_spec.v1
  run_id: string
  compact_sdd_hash: string
  goal: string                    # 1-2 frases, imperativo
  non_goals: list                 # explicitamente fora de escopo
  canonical_context:              # docs/arquivos referenciados, nao texto bruto
    - kind: doc|file|symbol|test
      ref: string
      reason: string
  expected_behavior:              # comportamento observavel apos patch
    - description: string
      observable_by: test|cli|api|ui|log
  assumptions:
    - text: string
      confidence: confirmed|inference|hypothesis|blocking
  expected_files: list            # path absoluto, ate 6
  allowed_files: list             # superset de expected_files
  forbidden_files: list           # ex: vendor/*, node_modules/*, .env
  acceptance_criteria:            # cada criterio deve ser verificavel
    - id: string
      description: string
      verification: test|grep|cli_command|manual_review
      verification_ref: string|null
  verification_plan:
    profile: php_laravel|ts_react|generic_no_test
    commands: list                # comandos especificos derivados do profile
    no_test_reason: string|null   # obrigatorio se profile=generic_no_test
  rollback_or_containment: string # como reverter se algo der errado
  completion_criteria: list       # quando o run pode parar
  provider_safe: true
  mini_spec_hash: string
```

#### Invariants

1. `goal` nao vazio, max 240 chars, imperativo.
2. `non_goals` nao vazio para risk `>= R2`.
3. `canonical_context` exige pelo menos 1 ref se `task_kind != question`.
4. `expected_files` subset de `allowed_files`.
5. `forbidden_files` e `allowed_files` sao disjuntos.
6. `acceptance_criteria` nao vazio para todo write (`mode in patch, repair`).
7. Cada `acceptance_criteria.verification` tem `verification_ref` quando aplicavel (path do teste, comando, doc).
8. `verification_plan.profile` deve coincidir com `compact_sdd.verification_profile`.
9. `verification_plan.no_test_reason` so e valido para profile `generic_no_test`.
10. `assumptions[].confidence = blocking` impede progredir; runtime deve resolver antes.

#### Identidade

- Chave: `(run_id, mini_spec_hash)`.
- Hash: `mini_spec_hash`. Recalcula a cada mudanca.

#### Exemplo Valido (Repair R2)

```yaml
schema_version: atlas.dev.mini_programming_spec.v1
run_id: "0192b5d2-..."
compact_sdd_hash: "feedcafe..."
goal: "Corrigir AtlasCliDevWorkflowServiceTest::test_workspace_resolution para passar com git root null"
non_goals:
  - "nao mudar API publica do WorkflowService"
  - "nao mexer em outros testes"
canonical_context:
  - kind: file
    ref: "app/Services/Ai/Cli/AtlasCliDevWorkflowService.php"
    reason: "alvo do bug"
  - kind: test
    ref: "tests/Unit/AtlasCliDevWorkflowServiceTest.php"
    reason: "teste falhando"
expected_behavior:
  - description: "test_workspace_resolution passa com git root null sem disparar exception"
    observable_by: test
assumptions:
  - text: "git root nullable e caso valido e nao bug downstream"
    confidence: inference
expected_files:
  - "app/Services/Ai/Cli/AtlasCliDevWorkflowService.php"
allowed_files:
  - "app/Services/Ai/Cli/AtlasCliDevWorkflowService.php"
forbidden_files:
  - "vendor/*"
  - "node_modules/*"
acceptance_criteria:
  - id: ac_1
    description: "teste passa"
    verification: test
    verification_ref: "tests/Unit/AtlasCliDevWorkflowServiceTest.php::test_workspace_resolution"
verification_plan:
  profile: php_laravel
  commands:
    - "composer test -- --filter=AtlasCliDevWorkflowServiceTest::test_workspace_resolution"
  no_test_reason: null
rollback_or_containment: "git checkout app/Services/Ai/Cli/AtlasCliDevWorkflowService.php"
completion_criteria:
  - "teste passa"
  - "nenhum outro teste regrediu (composer test)"
provider_safe: true
mini_spec_hash: "babecafe..."
```

#### Exemplos Invalidos

| Caso | Motivo |
| --- | --- |
| `goal: ""` | Vazio |
| `expected_files` inclui path que nao esta em `allowed_files` | Violacao de invariant 4 |
| `allowed_files` e `forbidden_files` se sobrepoem | Violacao de invariant 5 |
| `verification_plan.profile: php_laravel` + `no_test_reason: "nao tem teste"` | Violacao de invariant 9 |
| `assumptions[0].confidence: blocking` + downstream cria task_contract | Violacao de invariant 10 |

#### PHP DTO Signature

```php
final class MiniProgrammingSpec
{
    public function __construct(
        public readonly string $runId,
        public readonly string $compactSddHash,
        public readonly string $goal,
        public readonly array $nonGoals,
        public readonly array $canonicalContext,
        public readonly array $expectedBehavior,
        public readonly array $assumptions,
        public readonly array $expectedFiles,
        public readonly array $allowedFiles,
        public readonly array $forbiddenFiles,
        public readonly array $acceptanceCriteria,
        public readonly VerificationPlan $verificationPlan,
        public readonly string $rollbackOrContainment,
        public readonly array $completionCriteria,
        public readonly string $miniSpecHash,
    ) {}

    public function hasBlockingAssumption(): bool { /* invariant 10 */ }
    public function schemaVersion(): string { return 'atlas.dev.mini_programming_spec.v1'; }
}
```

---

