---
id: atlas-verified-execution-runtime
type: engineering_knowledge
title: Atlas Verified Execution Runtime
status: active
category: autonomous-intelligence
priority: 100
summary: Define o AVER, Atlas Verified Execution Runtime, a camada que transforma execucao de software em unidade auditavel com safety gate, command ledger, diff ledger, test ledger, repair cycle, rollback plan, certified execution e control plane.
implementation_state: runtime_implemented_l1_to_l10
macro_layer: true
product_name: Atlas Verified Execution Runtime
runtime_acronym: AVER
internal_product_name: Atlas Execution Cockpit
technical_runtime: AtlasVerifiedExecutionRuntimeService
tags:
  - atlas-ai
  - aver
  - verified-execution
  - command-ledger
  - patch-verification
  - certified-execution
capabilities:
  - execution_contract
  - command_ledger
  - diff_ledger
  - test_ledger
  - repair_cycle
  - certified_execution
decisions:
  - O nome canonico/produto e Atlas Verified Execution Runtime.
  - O acronimo tecnico obrigatorio e AVER.
  - O nome interno de experiencia/superficie e Atlas Execution Cockpit.
  - O runtime tecnico canonico e AtlasVerifiedExecutionRuntimeService.
  - AVER e a camada de verificacao executavel abaixo do AWEOS e acima de comandos/patches/testes.
  - AVER nao chama provider diretamente, nao executa efeitos externos perigosos e nao roda benchmark.
  - Completion de execucao exige command ledger, diff ledger, test ledger, evidence refs e certified execution.
maintenance:
  - Atualizar quando AWEOS, Dev, Forge, PatchVerifier, RepairExecutor ou Control Plane mudarem contrato.
  - Nao criar outro executor verificado paralelo sem compatibilidade AVER.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-work-execution-os.md
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-architecture.md
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-verified-execution-runtime
graph_title: Atlas Verified Execution Runtime
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-autonomous-work-execution-os
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-verified-execution-runtime.md
allowed_changes:
  - Criar runtime, persistencia, comandos, certificacao, testes e read models AVER.
  - Integrar AVER em AWEOS, Hyperflow e Control Plane sem bypass dos runtimes existentes.
forbidden_changes:
  - Executar comandos destrutivos sem gate explicito.
  - Persistir comando cru como prova primaria quando hash e excerpt bastam.
  - Declarar execution certified sem command, diff, test e evidence refs.
  - Chamar provider, rodar rivals ou benchmark dentro do AVER.
depends_on:
  - atlas-autonomous-work-execution-os
  - atlas-execution-memory-outcome-runtime
  - atlas-programming-superiority-architecture
flows_to:
  - atlas_dev
  - atlas_forge
  - atlas_ai_control_plane
unlocks:
  - atlas-execution-cockpit
  - certified-command-execution
governs:
  - command_execution
  - patch_verification
  - test_verification
  - repair_cycles
  - certified_executions
evidence:
  - docs/engineering-knowledge-base/atlas-verified-execution-runtime.md
  - app/Services/Ai/VerifiedExecution/AtlasVerifiedExecutionRuntimeService.php
  - app/Services/Ai/VerifiedExecution/AtlasVerifiedExecutionCertificationService.php
required_tests:
  - php artisan test tests/Feature/Ai/VerifiedExecution
  - php artisan atlas:aver:certify --json --strict
next_actions:
  - Alimentar AVER com execucoes reais de Atlas Dev e Forge e expor Execution Cockpit no Control Plane.
requires_evidence: true
risk_level: high
line_limit: 520
---

# Atlas Verified Execution Runtime

## Resumo

AVER e o runtime que impede que o Atlas "pareca ter feito" sem prova. Ele cria
um contrato de execucao, roda comandos seguros, registra hashes, verifica patch,
roda teste, abre repair cycle quando falha e so certifica quando ha evidencia
minima verificavel.

```text
AWEOS -> AVER -> safety gate -> command ledger -> diff ledger -> test ledger -> repair -> certified execution
```

## Papel no Atlas

AVER e infraestrutura interna. Ele nao substitui Atlas Dev, Atlas Forge ou
AWEOS. Ele e o trilho auditavel que esses fluxos usam para provar que uma acao
foi segura, testada e certificada.

## Onde Se Encaixa

```text
Atlas AI / Hyperflow
  -> AWEOS
     -> AVER
        -> safety gate
        -> command ledger
        -> diff ledger / PatchVerifier
        -> test ledger
        -> RepairExecutor
        -> certified execution
        -> Control Plane
```

## Contratos

Schemas canonicos:

- `atlas.aver.execution.v1`
- `atlas.aver.command_ledger.v1`
- `atlas.aver.diff_ledger.v1`
- `atlas.aver.test_ledger.v1`
- `atlas.aver.repair_cycle.v1`
- `atlas.aver.certified_execution.v1`
- `atlas.aver.control_plane.v1`
- `atlas.aver.certification.v1`

Uma execucao certificada exige:

- `execution_contract`
- `safety_gate`
- `command_ledger` aprovado
- `diff_ledger` aprovado
- `test_ledger` aprovado
- `rollback_plan`
- `evidence_refs`
- `certification_hash`

## Fluxo

1. Recebe objetivo, dominio, flow e workspace.
2. Gera `execution_contract` com hashes e requisitos.
3. Aplica safety gate para plano e comandos.
4. Executa somente comandos allowlisted.
5. Persiste excerpts e hashes, nunca output bruto como contrato.
6. Verifica patch via `ProgrammingPatchVerifier`.
7. Roda teste focado e cria `test_ledger`.
8. Se falhar, cria repair cycle via `ProgrammingRepairExecutor`.
9. Certifica somente se command, diff e test estiverem verdes.
10. Publica estado em `atlas:aver` e Control Plane.

## Regras para IA

- Nunca usar AVER para comando destrutivo.
- Nunca executar deploy, cloud, sudo, git reset ou rm -rf por padrao.
- Nunca declarar certificado sem evidence refs.
- Nunca esconder falha de teste: falha vira repair cycle ou blocker.
- Nunca reverter mudancas de usuario fora do proprio patch.
- Nunca chamar provider, benchmark ou rivals dentro do AVER.
- Sempre preferir comandos focados antes de suites longas.
- Sempre manter rollback plan de mudancas proprias.

## Escopo de Implementacao

Implementado:

- Persistencia AVER em seis tabelas.
- Runtime `AtlasVerifiedExecutionRuntimeService`.
- Certificacao `AtlasVerifiedExecutionCertificationService`.
- Comandos `atlas:aver` e `atlas:aver:certify`.
- Integracao AWEOS como sidecar `verified_execution`.
- Secao `verified_execution` no Atlas AI Control Plane.
- Testes de safety, command, diff, test, repair, certification, CLI e control plane.

Fora do escopo:

- Execucao externa sem aprovacao humana.
- Benchmark contra rivais.
- UI pesada do Execution Cockpit.

## Dependencias

- `AtlasAutonomousWorkExecutionService`
- `ProgrammingPatchVerifier`
- `ProgrammingRepairExecutor`
- `AtlasAemorRuntimeService`
- `AtlasAiControlPlaneService`

## Evidencias

Evidencia minima para considerar AVER pronto:

- `php artisan test tests/Feature/Ai/VerifiedExecution`
- `php artisan atlas:aver:certify --json --strict`
- `php artisan atlas:ai:control-plane runtime --json`
- `php artisan atlas:engineering:knowledge docs-health --json`
- `git diff --check`

## Riscos

- Allowlist permissiva demais pode virar execucao perigosa.
- Allowlist restritiva demais pode bloquear trabalho real.
- Excerpts podem vazar texto sensivel se o comando imprimir segredo.
- Certificacao shape-only seria insuficiente sem fixture cycle.
- Repair cycle pode planejar correcao sem aplicar patch real.

Mitigacao:

- Comandos destrutivos bloqueados por padrao.
- Completion exige ledgers verdes.
- Control Plane nao mostra comando cru nem objetivo cru.
- Fixture cycle roda comando real seguro.
- AEMOR recebe outcome apenas como sidecar, sem quebrar execucao.

## Exemplos

```bash
php artisan atlas:aver fixture-cycle --json
php artisan atlas:aver control-plane --hours=24 --json
php artisan atlas:aver:certify --json --strict
```

Exemplo de comando seguro:

```bash
php artisan atlas:aver run-command --command="php artisan test --filter=FooTest" --cwd=/repo --json
```

## Proximas Acoes

1. Ligar execucoes reais de Atlas Dev ao AVER command ledger.
2. Ligar milestones Forge ao AVER certified execution.
3. Expor Execution Cockpit leve no Desktop/Mobile.
4. Ampliar allowlist com policy por workspace.
5. Adicionar trace_id direto aos ledgers quando schema de trace permitir.
