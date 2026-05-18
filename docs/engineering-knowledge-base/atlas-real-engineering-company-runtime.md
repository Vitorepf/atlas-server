---
id: atlas-real-engineering-company-runtime
type: engineering_knowledge
title: Atlas Real Engineering Company Runtime
status: active
category: atlas-ai
priority: 100
summary: Camada acima do Real Engineering Execution Kernel que opera como uma organizacao autonoma de engenharia com papeis, review independente, QA, release, benchmark continuo, receipts e certificacao.
tags:
  - atlas-ai
  - engineering-company
  - autonomous-engineering
  - real-execution
capabilities:
  - company_runtime_orchestration
  - internal_engineering_roles
  - independent_review_gate
  - qa_gate
  - release_pack
  - continuous_benchmark
decisions:
  - Company Runtime nao substitui Real Execution Kernel; ele governa o kernel.
  - Toda entrega precisa de papel senior_engineer, reviewer independente, QA e release manager.
  - Claim amplo de superioridade continua bloqueado sem bateria maior e review humano.
maintenance:
  - Atualize este doc antes de alterar papeis, persistencia, comando ou certificacao.
  - Nao remover independent review ou QA gate para acelerar delivery.
related_paths:
  - docs/engineering-knowledge-base/atlas-real-engineering-execution-kernel.md
  - docs/engineering-knowledge-base/atlas-autonomous-engineering-operating-system.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-real-engineering-company-runtime
graph_title: Atlas Real Engineering Company Runtime
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-real-engineering-execution-kernel
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-real-engineering-company-runtime.md
allowed_changes:
  - Adicionar ciclos, papeis e gates desde que preservem receipts e certificacao.
forbidden_changes:
  - Entregar sem review independente.
  - Entregar sem QA gate.
  - Declarar Autonomous Software Company sem bateria multi-caso e auditoria humana.
depends_on:
  - atlas-real-engineering-execution-kernel
  - atlas-autonomous-engineering-operating-system
flows_to:
  - atlas_dev
  - atlas_forge
  - atlas_real_engineering_execution_kernel
unlocks:
  - atlas_real_engineering_company_runtime
governs:
  - atlas_ai.real_engineering_company_runtime
evidence:
  - docs/engineering-knowledge-base/atlas-real-engineering-company-runtime.md
required_tests:
  - "php artisan test tests/Unit/Ai/EngineeringCompany"
  - "php artisan test tests/Feature/Ai/AtlasRealEngineeringCompanyRuntimeTest.php"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
quality_gates:
  - roles-recorded
  - real-execution-completed
  - independent-review-passed
  - qa-passed
  - release-pack-ready
  - benchmark-recorded
  - certification-passed
failure_modes:
  - Reviewer vira o mesmo papel do implementador.
  - QA aceita delivery sem test evidence.
  - Runtime declara claim amplo com um unico caso.
  - Forge promotion perde handoff evidence.
observability_signals:
  - engagement_id
  - cycle_id
  - role_run_id
  - review_id
  - qa_run_id
  - release_pack_id
  - benchmark_id
  - certification_hash
next_actions:
  - Evoluir de single-cycle para multi-cycle real.
  - Suportar multiplos worktrees por papel.
  - Conectar release pack a PR/cockpit.
line_limit: 520
---
# Atlas Real Engineering Company Runtime

## Resumo

O Company Runtime governa o Real Engineering Execution Kernel como uma equipe
tecnica autonoma. Ele organiza o trabalho em papeis, executa via kernel real,
faz review independente, roda QA, empacota release, registra benchmark continuo
e certifica a entrega com receipts.

## Papel No Atlas

Ele e o patamar acima do executor. O Real Execution Kernel aplica patch e testa;
o Company Runtime decide quem fez o que, se a entrega deve passar, se deve ir
para Forge e quais evidencias sustentam o delivery.

## Onde Se Encaixa

```text
Goal
-> Engineering Company engagement
-> company cycle
-> internal roles
-> Real Engineering Execution Kernel
-> independent review
-> QA gate
-> release pack
-> benchmark memory
-> company certification
```

## Contratos

- `atlas.ai.engineering_company.engagement.v1`
- `atlas.ai.engineering_company.cycle.v1`
- `atlas.ai.engineering_company.role_run.v1`
- `atlas.ai.engineering_company.review.v1`
- `atlas.ai.engineering_company.qa_run.v1`
- `atlas.ai.engineering_company.release_pack.v1`
- `atlas.ai.engineering_company.benchmark.v1`
- `atlas.ai.engineering_company.certification.v1`

## Papeis

- `product_intent_owner`
- `architect`
- `planner`
- `senior_engineer`
- `debugger`
- `independent_reviewer`
- `qa_test_engineer`
- `release_delivery_manager`
- `learning_memory_manager`

Cada papel deve persistir `role_run` com responsabilidades, output, evidence
refs e hash.

## Fluxo

1. Criar engagement.
2. Criar cycle com plano de companhia.
3. Rodar Product/Architect/Planner.
4. Delegar execucao real ao Real Execution Kernel.
5. Registrar Senior Engineer e Debugger.
6. Rodar review independente.
7. Rodar QA gate.
8. Criar release pack.
9. Registrar benchmark continuo.
10. Certificar Company Runtime.

## Regras Para IA

- Nao entregar sem review independente passado.
- Nao entregar sem QA passado.
- Nao chamar benchmark continuo de prova de superioridade ampla.
- Promover para Forge quando o Real Execution Kernel indicar handoff.
- Preservar evidence refs de cada papel.

## Escopo de Implementacao

- Persistencia para engagement, cycle, role runs, review, QA, release,
  benchmark e certification.
- Service `AtlasRealEngineeringCompanyRuntimeService`.
- Command `atlas:ai:engineering-company`.
- Tests unitarios e feature end-to-end.
- Readiness, control-plane e certify.

## Dependencias

- Real Engineering Execution Kernel.
- Autonomous Engineering OS.
- Atlas Forge para obras grandes.
- Provider Arena/Rivals para benchmark continuo.
- Docs-health e Pint.

## Evidencias

O delivery so e valido quando contem role hashes, review hash, QA hash, release
hash, benchmark hash e certification hash. O papel `senior_engineer` precisa
referenciar evidence do Real Execution Kernel.

## Riscos

- Single-cycle pode parecer companhia completa sem cobrir trabalhos longos.
- Review independente pode virar formalidade se nao bloquear falhas.
- Benchmark continuo pode ser interpretado como claim amplo indevido.
- Release pack pode perder contexto do Forge handoff.

## Comandos

```bash
php artisan atlas:ai:engineering-company readiness --json
php artisan atlas:ai:engineering-company run --goal="..." --json
php artisan atlas:ai:engineering-company control-plane --json
php artisan atlas:ai:engineering-company certify --json
```

## Exemplos

```bash
php artisan atlas:ai:engineering-company run \
  --goal="execute um smoke company runtime com review e QA" \
  --json
```

Para validar bloqueio de review:

```bash
php artisan atlas:ai:engineering-company run \
  --goal="execute smoke com review bloqueando" \
  --review-status=failed \
  --json
```

## Definition Of Done

- Readiness passa.
- Run cria engagement, cycle, nove role runs, review, QA, release, benchmark e certification.
- Review falho bloqueia delivery.
- QA falho bloqueia delivery.
- Real Execution Kernel executa o patch/test evidence.
- Certify passa sem blockers para entrega interna.
- Docs-health, Pint e testes impactados passam.

## Proximas Acoes

1. Evoluir de single-cycle para multi-cycle real.
2. Suportar multiplos worktrees por papel.
3. Adicionar review humano opcional para desempate de benchmark.
4. Conectar release pack a PR/cockpit.
