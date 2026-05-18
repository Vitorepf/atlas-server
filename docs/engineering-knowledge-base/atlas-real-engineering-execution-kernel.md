---
id: atlas-real-engineering-execution-kernel
type: engineering_knowledge
title: Atlas Real Engineering Execution Kernel
status: active
category: atlas-ai
priority: 100
summary: Camada v2 do Autonomous Engineering OS que executa engenharia real em worktree isolado, aplica patch no sandbox, seleciona testes por impacto, repara falhas, promove para Forge, empacota delivery e importa benchmark real Claude Code/Codex sem claim falsa.
tags:
  - atlas-ai
  - real-execution
  - engineering-kernel
  - worktree
  - delivery-pack
capabilities:
  - worktree_execution_sandbox
  - patch_execution
  - test_impact_engine
  - repair_autonomy
  - forge_handoff
  - delivery_pack
  - rivals_shadow_benchmark
  - external_rivals_evidence_import
decisions:
  - Real Execution Kernel so roda depois do Autonomous OS certificar preflight.
  - Toda execucao real inicia em worktree/sandbox isolado.
  - Patch precisa passar scope guard antes de delivery.
  - Test impact e repair devem deixar receipts.
  - Benchmark contra Claude Code/Codex comeca shadow e so vira evidencia full apos Provider Arena real importado.
  - `--scope=kernel` certifica executor interno; `--scope=full` exige benchmark externo real.
maintenance:
  - Atualize este doc antes de alterar contratos, persistencia, comando ou certificacao do Real Execution Kernel.
  - Nao trocar shadow benchmark por claim de superioridade.
  - Nao liberar `scope=full` sem evidence pack externo com provider call real.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-engineering-operating-system.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-real-engineering-execution-kernel
graph_title: Atlas Real Engineering Execution Kernel
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-autonomous-engineering-operating-system
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-real-engineering-execution-kernel.md
allowed_changes:
  - Refinar sandbox, patch executor, test impact, repair e delivery conforme o runtime amadurecer.
forbidden_changes:
  - Executar no workspace principal sem isolamento.
  - Declarar benchmark shadow como prova contra Claude Code/Codex.
  - Certificar delivery sem patch, teste e evidence refs.
depends_on:
  - atlas-autonomous-engineering-operating-system
  - atlas-compounding-engineering-intelligence
  - atlas-forge-operating-system
flows_to:
  - atlas_dev
  - atlas_forge
  - atlas_compounding_engineering_intelligence
unlocks:
  - atlas_real_engineering_execution_runtime
governs:
  - atlas_ai.real_engineering_execution_kernel
evidence:
  - docs/engineering-knowledge-base/atlas-real-engineering-execution-kernel.md
required_tests:
  - "php artisan test tests/Unit/Ai/RealExecution"
  - "php artisan test tests/Feature/Ai/AtlasRealEngineeringExecutionKernelTest.php"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia Resumo, Papel no Atlas, Contratos, Fluxo, Regras para IA, Riscos e Definition of Done antes de implementar.
quality_gates:
  - autonomous-preflight-passed
  - worktree-ready
  - patch-scope-guard-passed
  - impact-tests-passed
  - repair-recorded-when-needed
  - delivery-pack-ready
  - rivals-false-claim-blocked
  - external-rivals-benchmark-executed-for-full-scope
  - certification-passed
failure_modes:
  - Patch aplicado fora do sandbox.
  - Test impact vira lista estatica sem relacao com patch.
  - Repair altera escopo sem novo plano.
  - Forge handoff perde evidence refs.
  - Benchmark shadow vira claim falsa.
observability_signals:
  - worktree_id
  - patch_run_id
  - test_run_id
  - repair_attempt_id
  - delivery_pack_id
  - benchmark_id
  - external_benchmark_gate
  - certification_hash
next_actions:
  - Trocar sandbox patch por git worktree fisico quando o operador habilitar execucao real no repo.
line_limit: 520
---
# Atlas Real Engineering Execution Kernel

## Resumo

O Real Engineering Execution Kernel e o salto v2 do Autonomous Engineering OS.
Ele pega uma meta ja roteada, planejada e certificada pelo Autonomous OS e cria
uma execucao real controlada: worktree isolado, patch no sandbox, teste por
impacto, repair autonomo quando necessario, delivery pack, benchmark shadow e
importacao de benchmark externo real Claude Code/Codex.

## Papel no Atlas

Este kernel transforma o Atlas AI de orquestrador auditavel em executor de
engenharia. Ele nao substitui Forge: quando o escopo e grande, longo ou
enterprise, ele cria handoff auditavel para Forge continuar como Obra.

## Onde Se Encaixa

```text
Goal
-> Autonomous Engineering OS preflight
-> isolated worktree/sandbox
-> patch executor
-> test impact engine
-> repair autonomy
-> delivery pack
-> rivals shadow benchmark
-> optional external Provider Arena evidence import
-> real execution certification
```

## Contratos

- `atlas.ai.real_execution.worktree.v1`
- `atlas.ai.real_execution.patch_run.v1`
- `atlas.ai.real_execution.test_run.v1`
- `atlas.ai.real_execution.repair_attempt.v1`
- `atlas.ai.real_execution.forge_handoff.v1`
- `atlas.ai.real_execution.delivery_pack.v1`
- `atlas.ai.real_execution.rivals_benchmark.v1`
- `atlas.ai.real_execution.certification.v1`

## Fluxo

1. Rodar Autonomous OS como preflight obrigatorio.
2. Criar worktree/sandbox isolado.
3. Aplicar patch dentro do escopo permitido.
4. Registrar scope guard, diff summary e patch hash.
5. Selecionar testes por impacto do patch.
6. Registrar test run e output excerpt.
7. Se falhar, criar repair attempt, reconsultar contexto e rerodar teste.
8. Promover para Forge quando `promotion_target` for `atlas_forge`.
9. Criar delivery pack certificado.
10. Criar rivals benchmark shadow contra Claude Code/Codex sem claim falsa.
11. Para `scope=full`, importar evidence pack real do Forge Provider Arena.
12. Certificar o runtime real.

## Regras Para IA

- Nao executar sem preflight Autonomous OS passado.
- Nao tocar workspace principal nesta fase.
- Nao aceitar patch fora de `allowed_paths`.
- Nao criar delivery pack sem test evidence.
- Nao chamar shadow benchmark de prova.
- Nao aceitar benchmark externo sem `external_provider_call=true`,
  `provider_tokens_spent=true`, arms Claude/Codex e evidence refs/paths.
- Nao converter empate/human review em claim de superioridade.
- Nao marcar completo sem certification hash e evidence refs.

## Escopo De Implementacao

- Persistencia para worktree, patch, teste, repair, Forge handoff, delivery,
  rivals benchmark e certification.
- Service `AtlasRealEngineeringExecutionKernelService`.
- Command `atlas:ai:real-engineering-kernel`.
- Tests unitarios e feature end-to-end.
- Readiness, control-plane, certify e import-external-benchmark.

## Dependencias

- Autonomous Engineering OS.
- Router Runtime.
- Compounding Engineering Intelligence.
- Forge OS para obras.
- Docs-health e Pint.

## Evidencias

O delivery so e valido quando contem worktree receipt, patch hash, test hash,
delivery hash, rivals benchmark hash e certification hash. `scope=full` tambem
exige refs/paths do Provider Arena real.

## Riscos

- Sandbox pode mascarar diferencas do repo real.
- Teste simulado nao substitui CI real.
- Handoff para Forge precisa carregar hashes suficientes.
- Benchmark shadow nao mede vencedor real.
- Benchmark real pode retornar empate/human review; isso satisfaz evidencia de
  execucao comparavel, mas nao autoriza claim 100x.

## Exemplos

```bash
php artisan atlas:ai:real-engineering-kernel run \
  --goal="implemente um smoke real de engenharia com evidencia" \
  --json
```

Para certificar apenas o kernel interno:

```bash
php artisan atlas:ai:real-engineering-kernel certify --scope=kernel --json
```

Para certificar o escopo completo:

```bash
php artisan atlas:ai:real-engineering-kernel certify --scope=full --json
```

Para importar benchmark real externo:

```bash
php artisan atlas:ai:real-engineering-kernel import-external-benchmark \
  --evidence-file=/path/to/evidence_pack.json \
  --json
```

Para validar repair:

```bash
php artisan atlas:ai:real-engineering-kernel run \
  --goal="implemente um smoke real com repair" \
  --test-status=failed \
  --json
```

## Definition Of Done

- Readiness passa.
- Run cria worktree, patch, test run, delivery pack, benchmark e certification.
- Test failure cria repair attempt e rerun passed.
- Goal enterprise cria Forge handoff.
- Control plane mostra estado agregado.
- `certify --scope=kernel` passa sem blockers.
- `certify --scope=full` passa somente apos benchmark externo real importado.
- Docs-health, Pint e testes impactados passam.

## Proximas Acoes

1. Migrar `sandbox_worktree` para git worktree fisico quando houver policy de escrita real.
2. Conectar test impact a comandos reais do repo.
3. Integrar evidence pack com PR/delivery surface.
