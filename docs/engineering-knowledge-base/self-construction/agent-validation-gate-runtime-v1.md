---
id: atlas-self-construction-agent-validation-gate-runtime-v1
type: engineering_knowledge
title: Atlas Self-Construction Agent Validation Gate Runtime v1
status: active
category: architecture
priority: 85
summary: Canonical read-only validation gate runtime for Self-Construction agent outputs. Defines 10 gates plus a 7-service dry-run pipeline (catalog/plan/evaluator/repository/classifier/repair builder/certification) that never executes real commands.
tags:
  - atlas-ai
  - self-construction
  - validation
  - gates
capabilities:
  - self_construction_agent_validation_gate_runtime_v1
  - agent_validation_gate_catalog
  - agent_validation_gate_plan_builder
  - agent_validation_gate_dry_run_evaluator
  - agent_validation_gate_result_repository
  - agent_validation_gate_failure_classifier
  - agent_validation_gate_repair_recommendation_builder
  - agent_validation_gate_certification
decisions:
  - Validation runs in dry-run only; no real command is executed by these services.
  - Catalog freezes 10 gates; the plan, evaluator, classifier, recommender and certification all consume those 10.
  - Certification record is hashable and inert; it never authorizes runtime, dispatch, provider call, token spend, ledger write or self-programming.
maintenance:
  - Update before adding/removing a gate, changing severity, blocking policy or repair recovery class.
related_paths:
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md
  - docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md
  - docs/engineering-knowledge-base/self-construction/scope-validator-contract.md
  - app/Services/Ai/SelfConstruction/AgentValidationGateCatalog.php
  - app/Services/Ai/SelfConstruction/AgentValidationGatePlanBuilder.php
  - app/Services/Ai/SelfConstruction/AgentValidationGateDryRunEvaluator.php
  - app/Services/Ai/SelfConstruction/AgentValidationGateResultRepository.php
  - app/Services/Ai/SelfConstruction/AgentValidationGateFailureClassifier.php
  - app/Services/Ai/SelfConstruction/AgentValidationGateRepairRecommendationBuilder.php
  - app/Services/Ai/SelfConstruction/AgentValidationGateCertificationService.php
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 520
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-self-construction-agent-validation-gate-runtime-v1
graph_title: Atlas Self-Construction Agent Validation Gate Runtime v1
graph_world: atlas
graph_layer: gear
graph_kind: contract
graph_parent: atlas-ai-self-construction-os
graph_status: active
graph_source: repo
human_name: Atlas Self-Construction Agent Validation Gate Runtime v1
canonical_name: Atlas Self-Construction Agent Validation Gate Runtime v1
technical_name: atlas-self-construction-agent-validation-gate-runtime-v1
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/self-construction/agent-validation-gate-runtime-v1.md
repo_paths:
  - docs/engineering-knowledge-base/self-construction/agent-validation-gate-runtime-v1.md
allowed_changes:
  - Atualizar este doc quando uma gate, severity, blocking, recovery, evidence ou service shape mudar.
forbidden_changes:
  - Declarar runtime real, dispatch, provider call ou self-programming sem evidence verificavel e gates verdes.
depends_on:
  - atlas-ai-documentation-operating-system
flows_to:
  - atlas-cartography
unlocks:
  - ai-safe-implementation-context
governs:
  - self-construction
evidence:
  - tests/Feature/Ai/SelfConstruction/AgentValidationGateCatalogTest.php
  - tests/Feature/Ai/SelfConstruction/AgentValidationGatePlanBuilderTest.php
  - tests/Feature/Ai/SelfConstruction/AgentValidationGateDryRunEvaluatorTest.php
  - tests/Feature/Ai/SelfConstruction/AgentValidationGateResultRepositoryTest.php
  - tests/Feature/Ai/SelfConstruction/AgentValidationGateFailureClassifierTest.php
  - tests/Feature/Ai/SelfConstruction/AgentValidationGateRepairRecommendationBuilderTest.php
  - tests/Feature/Ai/SelfConstruction/AgentValidationGateCertificationServiceTest.php
required_tests:
  - "php artisan test --filter AgentValidationGate"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - gear
  - contract
  - self-construction
ai_entrypoints:
  - Antes de tratar qualquer output de agente como entregue, percorra Catalog -> Plan -> DryRunEvaluator -> Classifier -> RepairRecommendation -> Certification.
ai_usage_notes:
  - Nenhum dos servicos executa comando real. Use synthetic inputs para simular gates antes de promover.
quality_gates:
  - "php artisan test --filter AgentValidationGate"
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Confundir status `available` em certificacao com permissao de dispatch real.
  - Tratar gate `warn` como bloqueante ou gate `pass` como autorizacao de completion.
observability_signals:
  - runtime_safety_all_false=true em catalog, plan, evaluation, classification, recommendation e certification
next_actions:
  - Manter este doc sincronizado com os 7 servicos e seus testes.
---
# Atlas Self-Construction Agent Validation Gate Runtime v1

## Resumo

Runtime read-only de validacao para outputs de agentes do Self-Construction OS. Define 10 gates canonicas e expoe um pipeline de 7 servicos que planeja, avalia em dry-run, armazena, classifica falhas, recomenda reparos e certifica resultado, sem executar comandos reais e sem autorizar dispatch.

## Papel no Atlas

Quando um agente (humano ou IA) declara "trabalho pronto", esses servicos respondem a pergunta "esta pronto segundo quais gates?" sem precisar rodar nada de verdade. O artefato final e uma certification record hashavel que pode entrar em release dossier, replay diff ou auditoria.

## Onde Se Encaixa

Sob `atlas-ai-self-construction-os`. Complementa Agent Control Plane: o Control Plane decide se ha permissao para dispatch; esta runtime decide se o output passou pelas gates antes da decisao de dispatch existir. Nao substitui Scope Validator nem o Evidence Ledger.

## Contratos

### Os 10 Gates Canonicas

| id | type | severity | blocking | requires_command | command shape |
|---|---|---|---|---|---|
| `php_lint` | lint | high | yes | yes | `php -l` |
| `unit_tests` | test | critical | yes | yes | `phpunit --testsuite Unit` |
| `focused_tests` | test | high | yes | yes | `phpunit --filter <focused_filter>` |
| `docs_health` | docs | high | yes | yes | `php artisan atlas:engineering:knowledge docs-health --json` |
| `architecture_validate` | architecture | high | yes | yes | `php artisan atlas:ai:architecture-validate --json` |
| `diff_check` | vcs | medium | yes | yes | `git diff --check` |
| `scope_check` | scope | critical | yes | no | `internal:scope_validator` |
| `evidence_check` | evidence | high | yes | no | `internal:evidence_validator` |
| `continuation_summary_check` | continuation | medium | no | no | `internal:continuation_summary_validator` |
| `rollback_plan_check` | rollback | critical | yes | no | `internal:rollback_plan_validator` |

### Servicos

1. **`AgentValidationGateCatalog`** — congela as 10 gates, calcula `catalog_hash`, expoe filtros por tipo, severity, blocking e requires_command. Schema: `atlas.self_construction.agent_validation_gate_catalog.v1`.
2. **`AgentValidationGatePlanBuilder`** — recebe `context { allowed_files, forbidden_files, changed_files, requested_gates, focused_filter }` e emite um plano ordenado (`CANONICAL_ORDER` = scope_check primeiro, unit_tests por ultimo). Cada run carrega dependencias, skip_unless, abort_on_failure e o command renderizado. Schema: `atlas.self_construction.agent_validation_gate_plan.v1`.
3. **`AgentValidationGateDryRunEvaluator`** — recebe um plano e um mapa `synthetic_inputs[gate_id] = { status, evidence_artifact, detail }` e devolve um result set deterministico com `overall_status` em `passed | passed_with_warnings | failed | inconclusive | all_skipped | empty`. Quando uma gate bloqueante falha, as gates subsequentes sao automaticamente marcadas `skip` com motivo `previous_blocking_gate_failed`. Schema: `atlas.self_construction.agent_validation_gate_dry_run.v1`.
4. **`AgentValidationGateResultRepository`** — armazena result sets em memoria, preserva ordem de insercao, suporta `find/has/all/latest/forget/clear`, expoe `digest()` com `digest_hash` para snapshot tests. Schema: `atlas.self_construction.agent_validation_gate_result_repository.v1`.
5. **`AgentValidationGateFailureClassifier`** — converte cada result em uma classificacao canonica (`category`, `severity`, `recovery_class`, `requires_human_review`, `signal`). Status `pass`/`skip` sao mapeados para `non_failure`. Status `unknown` vira `inconclusive_signal` e a severity sobe um passo. Schema: `atlas.self_construction.agent_validation_gate_failure_classification.v1`.
6. **`AgentValidationGateRepairRecommendationBuilder`** — para cada classificacao falha, emite `steps`, `allowed_files`, `forbidden_ops` (`no_provider_call`, `no_token_spend`, `no_ledger_write`, `no_dispatch_runtime`, `no_self_programming`, `no_pointer_mutation`, `no_real_command_execution`), `evidence_needed` e `escalation` (`retry_inside_same_session` ou `human_review_before_retry`). Schema: `atlas.self_construction.agent_validation_gate_repair_recommendation.v1`.
7. **`AgentValidationGateCertificationService`** — orquestra os outros seis e emite um payload com `status` em `available | available_with_warnings | inconclusive | blocked | invariant_violation | empty`, `summary`, hashes encadeados (`catalog_hash`, `plan_hash`, `evaluation_hash`, `classification_hash`, `recommendation_hash`, `certification_hash`), invariantes (17 checks) e `next_action`. Schema: `atlas.self_construction.agent_validation_gate_certification.v1`.

### Invariantes do Certification Record

- `catalog_has_ten_gates`
- `plan_runtime_safety_all_false`
- `evaluation_runtime_safety_all_false`
- `classification_runtime_safety_all_false`
- `recommendation_runtime_safety_all_false`
- `no_real_command_executed`
- `no_provider_call`
- `no_token_spend`
- `no_dispatch_runtime`
- `no_ledger_write`
- `no_self_programming`
- `no_pointer_mutation`
- `plan_ordered_runs_consistent_with_gate_ids`
- `evaluation_counts_total_matches_evaluations`
- `classification_total_matches_evaluations`
- `recommendation_total_matches_classifications`
- `completion_blocked_until_human_ack`

Qualquer invariante false vira `status: invariant_violation`. Todos os payloads incluem o bloco `runtime_safety` com todas as flags `false`.

## Fluxo

```
context + synthetic_inputs
    -> Catalog (10 gates + hash)
    -> PlanBuilder (ordered plan + hash)
    -> DryRunEvaluator (result set + hash)
    -> ResultRepository (optional, in-memory)
    -> FailureClassifier (categories + hash)
    -> RepairRecommendationBuilder (recommendations + hash)
    -> CertificationService (folded payload + hash)
    -> human operator inspects certification_id
```

A certificacao nao autoriza nenhuma acao real; e somente leitura.

## Regras para IA

- Nunca trate `status: available` como dispatch authorization. Authorization e dever do Agent Control Plane.
- Nunca trate `status: available_with_warnings` como pass silencioso. Warnings devem aparecer no resumo entregue ao operador.
- Nunca substitua dry-run por execucao real fora destes services. Quem precisa rodar o gate de verdade roda fora do pipeline e injeta o resultado como synthetic_input.
- Nunca passe synthetic inputs sem `evidence_artifact`. O evaluator preserva o artifact para auditoria.
- Nunca remova `scope_check` ou `rollback_plan_check` do plano sob alegacao de "trabalho seguro" — gates criticas e bloqueantes.

## Escopo de Implementacao

Mudancas pertencem aos 7 services em `app/Services/Ai/SelfConstruction/AgentValidationGate*`, aos 7 tests em `tests/Feature/Ai/SelfConstruction/AgentValidationGate*` e a este doc. Nao integrar com `AtlasAiSelfConstructionCommand`, `AgentControlPlane*` ou `routes/api.php` sem decisao explicita.

## Dependencias

- `AgentValidationGateCatalog` nao depende de nada externo aos 10 gates.
- `AgentValidationGatePlanBuilder` consome o Catalog.
- `AgentValidationGateDryRunEvaluator` consome o plano (struct).
- `AgentValidationGateResultRepository` e standalone (in-memory).
- `AgentValidationGateFailureClassifier` consome um result do evaluator.
- `AgentValidationGateRepairRecommendationBuilder` consome uma classificacao + result + context.
- `AgentValidationGateCertificationService` consome todos os anteriores.

## Evidencias

- 7 test files em `tests/Feature/Ai/SelfConstruction/AgentValidationGate*Test.php`.
- Cada test file cobre constants, runtime_safety_all_false, hash stability e logical correctness.
- Total de testes verdes: pode ser verificado com `php artisan test --filter AgentValidationGate`.
- Doc segue `atlas_canonical_module_doc.v1` com todas as secoes obrigatorias.

## Riscos

- Falsa sensacao de seguranca: o pipeline e dry-run, nunca prova que o gate real passou na maquina. So prova que o agente entregou um output consistente com o synthetic_input declarado.
- Sub-uso: se nenhum operador atribuir synthetic_inputs com verdade, o `available` vira ruido. O contrato declara isso explicitamente nos invariantes.
- Drift: se um gate novo aparecer no Self-Construction OS sem entrar no Catalog, a certification ficara desatualizada.

## Exemplos

```php
use App\Services\Ai\SelfConstruction\AgentValidationGateCertificationService;

$svc = new AgentValidationGateCertificationService;
$out = $svc->certify(
    context: [
        'allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
        'forbidden_files' => ['routes/api.php'],
        'changed_files' => ['app/Foo.php'],
        'focused_filter' => 'FooTest',
    ],
    syntheticInputs: [
        'scope_check' => ['status' => 'pass', 'evidence_artifact' => 'no_forbidden_file_touched'],
        'rollback_plan_check' => ['status' => 'pass', 'evidence_artifact' => 'plan_attached'],
        'evidence_check' => ['status' => 'pass', 'evidence_artifact' => 'hash_attached'],
        'continuation_summary_check' => ['status' => 'skip', 'evidence_artifact' => 'no_followup'],
        'php_lint' => ['status' => 'pass', 'evidence_artifact' => 'no_syntax_error_message'],
        'diff_check' => ['status' => 'pass', 'evidence_artifact' => 'no_whitespace_or_conflict_marker'],
        'docs_health' => ['status' => 'pass', 'evidence_artifact' => 'docs_health_status_ok'],
        'architecture_validate' => ['status' => 'pass', 'evidence_artifact' => 'architecture_validate_status_ok'],
        'focused_tests' => ['status' => 'pass', 'evidence_artifact' => 'focused_filter_green'],
        'unit_tests' => ['status' => 'pass', 'evidence_artifact' => 'all_unit_tests_green'],
    ],
);

assert($out['status'] === 'available');
assert($out['runtime_safety']['runtime_safety_all_false'] === true);
```

## Proximas Acoes

- Adicionar uma surface CLI/HTTP futura `atlas:ai:self-construction --validation-gate-certify --json` sob um signed receipt — fora do escopo deste pacote.
- Integrar com Release Dossier apenas apos um operador humano assinar a primeira certification de uma sprint real.
- Reavaliar gates `warn` quando docs-health canonical violations forem reduzidas (atualmente fonte de muitos warns).
