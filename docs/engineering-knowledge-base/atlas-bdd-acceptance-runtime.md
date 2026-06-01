---
id: atlas-bdd-acceptance-runtime
type: engineering_knowledge
title: Atlas BDD Acceptance Runtime
slug: atlas-bdd-acceptance-runtime
status: building
risk_level: medium
authority_class: runtime
category: programming-governance
priority: 88
summary: Runtime de acceptance BDD/Gherkin para transformar criterios humanos em cenarios executaveis, sem substituir PHPUnit/Pest e sem permitir pass automatico para steps sem definicao.
tags:
  - atlas-ai
  - programming
  - bdd
  - acceptance
  - governance
capabilities:
  - bdd_acceptance_runtime
  - executable_acceptance_layer
  - gherkin_scenario_compilation
  - honest_step_result_reporting
  - programming_completion_gate_bridge
decisions:
  - BDD e uma camada de acceptance executavel; nao substitui unit, feature, integration ou browser tests.
  - Step sem definition registrada deve retornar pending_definition e nunca conta como passed.
  - Execution report so pode retornar pass quando nao houver failed, pending_definition ou skipped.
  - Constitutional Kernel e Autonomy Admission governam execucao de cenarios.
  - CLI atlas:bdd existe para compile, execute, report e listagens; integracao obrigatoria com ProgrammingCompletionGate permanece building ate haver bridge/teste registrados.
maintenance:
  - Atualizar quando AtlasBddAcceptanceRuntimeService, CLI atlas:bdd ou ProgrammingCompletionGate mudarem.
  - Nao declarar active enquanto command, tests e completion gate bridge nao estiverem provados.
  - Manter este doc alinhado com Programming Governance, Forge contracts e Execution Doctrine.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Services/Ai/Programming/Bdd/AtlasBddAcceptanceRuntimeService.php
  - app/Console/Commands/AtlasBddCommand.php
  - app/Services/Ai/Programming/Governance/Gates/ProgrammingCompletionGate.php
  - docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system-runbook.md
  - docs/engineering-knowledge-base/atlas-execution-doctrine-product-delivery-system.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-bdd-acceptance-runtime
graph_title: Atlas BDD Acceptance Runtime
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-programming-governance-system
graph_status: building
graph_source: repo
owner: programming-governance
repo_paths:
  - docs/engineering-knowledge-base/atlas-bdd-acceptance-runtime.md
  - app/Services/Ai/Programming/Bdd/AtlasBddAcceptanceRuntimeService.php
allowed_changes:
  - Evoluir command atlas:bdd apenas junto com testes focados.
  - Integrar reportFor(workItemId) ao ProgrammingCompletionGate com opt-in documentado.
  - Adicionar step definitions canonicas apenas via registry.
depends_on:
  - atlas-programming-governance-system
  - atlas-constitutional-kernel
  - atlas-autonomy-admission
forbidden_changes:
  - auto_pass_undefined_steps
  - aggregate_winner_claim
  - silent_test_skip
  - replace_phpunit_or_pest_with_bdd
flows_to:
  - programming.dev
  - forge
  - programming-completion-gate
unlocks:
  - executable-acceptance-layer
  - gherkin-acceptance-reports
governs:
  - bdd-scenarios
  - bdd-step-results
  - acceptance-execution-reports
evidence:
  - app/Services/Ai/Programming/Bdd/AtlasBddAcceptanceRuntimeService.php
  - app/Console/Commands/AtlasBddCommand.php
evidence_refs:
  - symbol: AtlasBddAcceptanceRuntimeService
  - command: atlas:bdd
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test --filter=AtlasBddAcceptanceRuntime"
  - "php artisan atlas:bdd --action=report --json"
next_actions:
  - Adicionar testes unitarios para compile, pending_definition, pass honesto e report agregado.
  - Adicionar teste de command para compile/report/list.
  - Integrar ProgrammingCompletionGate apenas com contrato opt-in e teste de regressao.
requires_evidence: true
schema:
  - atlas.bdd.scenario.v1
  - atlas.bdd.step_result.v1
  - atlas.bdd.execution_report.v1
---

# Atlas BDD Acceptance Runtime

## Resumo

Atlas BDD Acceptance Runtime canoniza a camada de acceptance executavel para criterios humanos em formato Gherkin. O runtime service e o command `atlas:bdd` ja existem, mas o fluxo permanece `building` ate teste de command e bridge com completion gate estarem provados.

## Papel no Atlas

O papel desta camada e transformar criterios de aceite em cenarios verificaveis sem trocar a fonte de verdade: docs canonicos, task packets, manifests e testes continuam donos dos contratos. BDD aqui e uma ponte de acceptance, nao um segundo runner principal.

## Onde Se Encaixa

Fica dentro de Programming Governance, subordinado a Execution Doctrine e Forge contracts. Ele consome criterios produzidos por SDD/task packets e pode alimentar `ProgrammingCompletionGate` quando o owner exigir acceptance executavel.

## Contratos

- `atlas.bdd.scenario.v1` descreve feature, scenario, tags e steps normalizados.
- `atlas.bdd.step_result.v1` registra o resultado honesto de cada step.
- `atlas.bdd.execution_report.v1` agrega o resultado de execucao.
- `pending_definition` nunca vira `passed`.
- `overall=pass` exige zero failed, zero pending_definition e zero skipped.

## Fluxo

1. Operador ou SDD fornece criterios em linguagem de negocio.
2. Compiler converte Gherkin em scenario canonico.
3. Step registry resolve callbacks permitidos.
4. Executor consulta Constitutional Kernel e Autonomy Admission.
5. Report append-only registra pass, fail ou incomplete.

## Regras para IA

- Nao crie step runner paralelo a PHPUnit, Pest, browser tests ou Forge gates.
- Nao declare BDD ativo enquanto testes de command e completion bridge nao estiverem provados.
- Nao use BDD para inflar aceite quando houver steps sem definicao.
- Leia `atlas-canonical-glossary-and-naming.md` antes de introduzir novos nomes de fluxo.

## Escopo de Implementacao

Escopo atual: `AtlasBddAcceptanceRuntimeService` com compile, registerStep, execute, listScenarios, listExecutions e report; command `atlas:bdd` para compile, execute, report e listagens. Fora do escopo atual: UI, auto-geracao por provider e enforcement obrigatorio em todo work item.

## Dependencias

- `AtlasConstitutionalKernelService`
- `AtlasAutonomyAdmissionService`
- `ProgrammingCompletionGate`
- Programming Governance runbook
- Forge Operating System runbook
- Execution Doctrine

## Evidencias

- `app/Services/Ai/Programming/Bdd/AtlasBddAcceptanceRuntimeService.php`
- `app/Console/Commands/AtlasBddCommand.php`
- Este documento canonico em `docs/engineering-knowledge-base/atlas-bdd-acceptance-runtime.md`

## Riscos

- Uma IA pode tratar BDD como substituto de testes reais.
- Uma IA pode declarar completion com `pending_definition`.
- Completion bridge pode ser documentada antes de existir runtime provado.
- Step definitions genericas demais podem mascarar falha de comportamento.

## Exemplos

Um scenario com step sem definition deve gerar `overall=incomplete`. Um scenario com todos os callbacks retornando `true` pode gerar `overall=pass`, desde que Kernel e Admission nao bloqueiem.

## Proximas Acoes

1. Adicionar teste unitario para compile e envelopes.
2. Adicionar teste unitario para `pending_definition`.
3. Adicionar teste unitario para pass honesto com callbacks registrados.
4. Adicionar teste focado para `AtlasBddCommand`.
5. So depois integrar `ProgrammingCompletionGate` com report opt-in.

## Por que existe

`executable_acceptance_layer` esta declarado nos runbooks Atlas (Programming Governance + Forge contracts + Execution Doctrine). O service `AtlasBddAcceptanceRuntimeService` cria o primitivo operacional inicial para compilar Gherkin, executar callbacks registrados e emitir reports honestos.

Este documento ainda esta `building`: ele canoniza o boundary, o service e o command existente, mas nao declara o fluxo completo ativo ate existir teste de command e consumo provado pelo `ProgrammingCompletionGate`.

## Princípio de não-duplicação

| Conceito | Fonte canon |
|----------|-------------|
| `acceptance_criteria` field em packets | `AtlasDevTaskPacket`, `AtlasSddTask`, `AtlasProgrammingActionManifest` (mantidos intactos) |
| Pétreo validation | `AtlasConstitutionalKernelService` |
| Autonomy admission | `AtlasAutonomyAdmissionService` |
| Evidence ledger pattern | append-only JSONL local-first (mesmo padrão dos outros 14 services Patamar 4) |
| Step execution | NÃO duplicamos test runners existentes (phpunit, pest). BDD aqui é **especificação executável de acceptance**, NÃO substituto de unit tests. |

## Conceito

BDD em Atlas é o **bridge entre spec humana e verification automática**:

```
Operador (linguagem natural)
  ↓
SDD Spec Compiler gera `acceptance_criteria` no packet
  ↓
BDD Compiler converte criteria → cenários Gherkin canônicos
  ↓
Step Definition Registry mapeia padrões → callbacks
  ↓
BDD Executor roda cenários, registra step results
  ↓
Acceptance Report agrega + emite envelope
  ↓
Programming Governance Completion Gate consome report
```

Quando um step não tem definição registrada → marca `pending_definition` honestamente. Não infla pass, não silently skip.

## API

```php
compile(string $gherkin): array      // atlas.bdd.scenario.v1
execute(string $scenarioId, array $context = []): array  // atlas.bdd.execution_report.v1
registerStep(string $pattern, callable $callback, string $kind = 'given|when|then'): void
listScenarios(): array
listExecutions(): array
report(): array                       // aggregate honest counts
```

### Scenario envelope

```json
{
  "schema_version": "atlas.bdd.scenario.v1",
  "scenario_id": "scn_<sha8>",
  "feature": "Atlas Dev workspace patch",
  "name": "Operator commits a small fix",
  "tags": ["@dev", "@workspace"],
  "steps": [
    {"kind":"given","text":"a workspace task is created","step_id":"stp_..."},
    {"kind":"when","text":"the operator approves","step_id":"stp_..."},
    {"kind":"then","text":"the receipt is signed","step_id":"stp_..."}
  ],
  "scenario_hash": "sha256:..."
}
```

### Step result

```json
{
  "schema_version": "atlas.bdd.step_result.v1",
  "step_id": "stp_...",
  "kind": "given|when|then|and|but",
  "text": "...",
  "status": "passed|failed|pending_definition|skipped",
  "duration_ms": 0,
  "error": null
}
```

### Execution report

```json
{
  "schema_version": "atlas.bdd.execution_report.v1",
  "report_id": "rpt_...",
  "scenario_id": "scn_...",
  "executed_at": "ISO",
  "total_steps": N,
  "passed": N,
  "failed": N,
  "pending_definition": N,
  "skipped": N,
  "overall": "pass|fail|incomplete",
  "kernel_decision": "...",
  "admission_decision": "...",
  "step_results": [...],
  "report_hash": "sha256:...",
  "claim_policy": {
    "benchmark_claim_allowed": false,
    "rivals_claim_allowed": false,
    "auto_pass_undefined": false
  }
}
```

## Gates obrigatórios

1. **Constitutional Kernel** sobre `change_kind=bdd_scenario_execute`
2. **Autonomy Admission** sobre execução autônoma
3. **`auto_pass_undefined` = false** hardcoded — step sem definition NUNCA conta como pass

## Honestidade enforced

- `overall=pass` exige `failed=0 AND pending_definition=0 AND skipped=0`
- `overall=incomplete` quando há pending_definition > 0
- `overall=fail` quando há failed > 0
- Nunca infla resultado

## Storage

- `storage/atlas/bdd/scenarios.jsonl` — append-only
- `storage/atlas/bdd/executions.jsonl` — append-only

## CLI

Status: command registrado; teste de command ainda deve ser adicionado antes de promover o fluxo para `active`.

```
php artisan atlas:bdd --action=compile --gherkin="..." [--json]
php artisan atlas:bdd --action=execute --scenario-id=scn_... [--json]
php artisan atlas:bdd --action=report [--json]
php artisan atlas:bdd --action=list-scenarios [--json]
php artisan atlas:bdd --action=list-executions [--json]
```

## Como Programming Governance consome

O `ProgrammingCompletionGate` pode opcionalmente requerer um BDD execution_report com `overall=pass` para fechar work_item. Esta bridge permanece building ate existir metodo/contrato provado, por exemplo `AtlasBddAcceptanceRuntimeService::reportFor(workItemId)`, e teste de completion gate cobrindo o consumo.

## Não-objetivos

- Não substitui unit tests (phpunit).
- Não executa código arbitrário — só callbacks pré-registrados via `registerStep`.
- Não claim de aggregate winner.
- Não promove `pending_definition` para `passed` automaticamente.
