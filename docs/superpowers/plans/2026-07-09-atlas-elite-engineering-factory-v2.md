# Atlas Elite Engineering Factory v2 Implementation Plan

> **For agentic workers:** execute este plano por packets TDD. Use subagent-driven development quando disponível; caso contrário, execute inline em lotes pequenos com review entre commits. Nenhum packet pode ser marcado como concluído sem teste, evidência e commit próprios.

**Goal:** Transformar Atlas Dev, Forge e Autônomos em três regimes operacionais da mesma fábrica de engenharia elite, todos executados pelo mesmo Engineering Kernel real e fail-closed.

**Architecture:** Aprofundar o `EliteExecutorKernel` existente, sem criar runtime v3. O Kernel será um Module profundo com Interface pequena; Dev, Forge e Autônomos adaptam presença do operador, duração e autoridade, mas não duplicam provider, workspace, evidência, repair ou release.

**Tech Stack:** PHP 8.4+, Laravel 13, PostgreSQL 16, SQLite hermético para testes, queues Laravel, Git worktrees, Atlas Evidence Ledger, DecisionReceipt, PHPUnit, Pint e PHPStan.

**Execution override (operator 2026-07-09):** implementar na `main` local (sem worktree dedicado). Sem push, sem merge remoto, sem deploy, sem cutover produtivo. Preservar WIP Rivals concorrente.

## Global Constraints

- Docs canônicos no repo governam implementação.
- Atlas memory e Context Pack devem ser consultados antes de mudanças arquiteturais.
- Não construir shell ou UI própria.
- Não criar runtime v3 paralelo.
- Não criar lifecycle ou ledger paralelo sem provar incapacidade do existente.
- Loop/ACDE antigo está morto; o sistema vivo é Brain `atlas:brain:*` + Muscle `atlas:task:*`.
- Não remover classes por prefixo `AtlasLoop*`; remover apenas após reachability comprovada.
- Não declarar Constitution Gate implementado antes de prova comportamental.
- Não declarar 10×, 50× ou 100× sem benchmark pareado.
- Budget policy: `unbounded_quality_first`.
- Custo monetário é medido, mas não bloqueia por si só.
- Segurança, autoridade, integridade e ausência de progresso continuam hard-stops.
- Nenhum sandbox pode usar `vendor` compartilhado por symlink.
- Nenhum processo pode executar `migrate:fresh`, `migrate:refresh` ou `db:wipe`.
- Testes usam SQLite `:memory:` ou Postgres efêmero dedicado; nunca banco vivo.
- Migrations produtivas são forward-only e aditivas até a remoção da fachada v1.
- Writers novos emitem somente v2.
- Fachada v1 traduz para v2; não possui executor, outcome ou dual-write próprios.
- Shadow de produção é somente replay/read-only; nunca duplica provider ou mutação.
- Cutover produtivo é integral para Dev, Forge e Autônomos.
- Canários e validação são graduais por modo em sandbox/staging.
- Rollback ocorre pelo artefato N−1 compatível, não reativando executor v1.
- Push, merge e deploy de produção exigem autorização externa a este plano.
- Trabalho Rivals concorrente permanece congelado para esta obra.

---

## 1. Definições independentes de pronto

### 1.1 `cutover_ready`

Requer simultaneamente:

- todos os P0 confirmados fechados;
- Engineering Kernel executando um write run real;
- coverage de 100% das surfaces mutativas;
- zero bypass de provider, workspace, evidence ou release;
- Dev, Forge e Autônomos adaptados ao mesmo Kernel;
- migrations aditivas aplicadas e compatíveis com N−1;
- fachada v1 traduzindo e escrevendo somente v2;
- canários isolados dos três modos;
- rollback realmente exercitado;
- outcome instrumentation ativa;
- docs health, arquitetura e suítes obrigatórias verdes.

### 1.2 `operationally_certified`

Estados independentes:

- `observed_24h`
- `observed_7d`
- `observed_30d`
- `observed_90d`
- `observed_150d`

Uma janela não decorrida permanece `pending_time_window`; isso não autoriza fingir resultado.

### 1.3 `comparatively_proven`

Requer:

- workloads pareados;
- snapshots e critérios equivalentes;
- adjudicação cega;
- outcomes de 30 dias;
- intervalo de confiança de 95%;
- baseline concorrente;
- baseline humano para claim mundial.

Não existe booleano genérico `certified=true` que misture os três níveis.

---

## 2. Arquitetura e Interfaces

### 2.1 Contratos públicos iniciais

Começar com apenas três contratos públicos:

1. `ExecutionOrder`
2. `AcceptanceBundle` v2, aprofundando o tipo existente
3. `EngineeringOutcome`

Não criar outro `EvidenceBundle`.

Adicionar tipos internos somente quando uma seam real exigir adapters diferentes.

Interface target:

```php
final class EliteExecutorKernel
{
    public function execute(ExecutionOrder $order): EngineeringOutcome;
}
```

`ExecutionOrder` deve ser readonly, versionado e conter:

- schema version;
- run ID e modo;
- spec e critérios congelados;
- hashes recomputáveis;
- workspace e baseline;
- allowed/forbidden scope;
- operator contract;
- authority/DecisionReceipt;
- provider/model route;
- tool permissions;
- evidence profile;
- release policy;
- idempotency key;
- `budget_posture=unbounded_quality_first`.

`AcceptanceBundle` v2 deve conter por construção:

- critérios brutos;
- criteria hash e frozen hash;
- context sufficiency;
- hashes dos arquivos alterados;
- provider execution receipt;
- comandos reais, exit codes, timeouts e assertions;
- repair proof;
- replay da falha;
- regression lock;
- security;
- mutation quando aplicável;
- NFR;
- judges independentes;
- rollback posture.

`EngineeringOutcome` deve admitir:

- `released`
- `completed_read_only`
- `held`
- `blocked`
- `refused`
- `reverted`
- `release_uncertain`

Write run sem release aplicado nunca termina aprovado.

### 2.2 Ports internos

Aprofundar progressivamente:

```php
ProviderPort::invoke(ProviderInvocationRequest): ProviderInvocationResult
WorkcellExecutor::execute(AdmittedWorkcell): WorkcellExecution
ReceiptLedger::append(Receipt): ReceiptRef
ReceiptLedger::replay(RunKey): ReceiptChain
MergeActuator::act(AuthorizedMergeAction): EffectReceipt
BudgetMeter::measure(ResourceUsage): ResourceSnapshot
```

- `AiProviderManager` é a única rota para providers reais.
- Normalizador de array não pode fingir ser ProviderPort.
- Task serving não pode fingir executar workcell.
- MergeActuator não decide autoridade; apenas executa ação já autorizada.
- BudgetMeter mede; não define política.

### 2.3 Pre-actuation hard-stop

Nenhum land, commit, merge, push, deploy, canary ou revert pode entrar no MergeActuator sem `AuthorizedMergeAction` imutável, emitido pelo Governor e persistido com sucesso.

Fluxo obrigatório:

```text
prepare → act → settle
```

`prepare`:

- recomputa authority;
- verifica revogação e validade;
- verifica scope;
- verifica candidate/tree hash;
- verifica evidence;
- verifica rollback posture;
- persiste receipt;
- exige status `ok|already_recorded`;
- verifica replay íntegro.

`act`:

- revalida nonce;
- receipt ref;
- expected base/tree;
- lease;
- fencing token;
- executa um único efeito idempotente.

`settle`:

- persiste effect receipt;
- falha pós-efeito vira `release_uncertain`;
- dispara sentinel, rollback ou quarentena;
- nunca marca `resolved`.

`unbounded_quality_first` não aumenta autoridade.

### 2.4 Coverage obrigatório

O coverage atual de Kernel routing não prova execução Engineering Kernel.

Coverage v2 precisa correlacionar:

```text
mode
surface
run_id
execution_order_hash
provider_receipt
workspace_delta_hash
acceptance_receipt
release_receipt
terminal_outcome
```

- Census estático lista toda surface capaz de mutação.
- Evento runtime prova que a execução passou pelo Kernel.
- Coverage começa em observe.
- Antes do cutover, enforce exige 100%.
- Git, provider ou release direto fora da allowlist bloqueia arquitetura.

---

## 3. Persistência

### 3.1 Reutilização obrigatória

Reusar e aprofundar:

- `atlas_ledger_events` como timeline/evidência comum;
- Atlas Evidence Ledger como prova canônica;
- `ai_run_outcomes` como projeção fail-closed;
- `atlas_engineering_runs` como identidade de execução;
- DecisionReceipt e ReleaseDecision ledgers existentes;
- tabelas Forge e Self-Construction existentes.

Não criar tabelas separadas de eventos ou outcomes por modo.

### 3.2 Outcomes

`ai_run_outcomes` não pode:

- assumir `passed` quando status estiver ausente;
- atribuir scores favoráveis por default;
- auto-promover learning sem observação.

Observações 0h/24h/7d/30d/90d/150d são eventos no Evidence Ledger e materializam projeções reconstruíveis.

Backfill histórico usa `legacy_unproven` ou `unknown`.

### 3.3 Estado transacional

Eventos não substituem:

- leases;
- fencing;
- heartbeat;
- optimistic version;
- retry schedule;
- reservas atômicas.

Criar `atlas_task_scope_reservations` se o baseline confirmar ausência de tabela transacional equivalente:

- `id` UUID;
- `canonical_path`;
- `run_id`;
- `mode`;
- `lease_owner`;
- `lease_token`;
- `baseline_hash`;
- `state`;
- `version`;
- `lease_expires_at`;
- `released_at`;
- timestamps;
- índice parcial único de `canonical_path` quando `state=active`.

Estender tabelas Forge e Self-Construction apenas com campos demonstrados pelos testes de recovery.

---

## 4. Matriz P0 inicial

Cada linha começa `REVALIDATE`, porque main pode ter avançado. Se um teste já estiver verde, marcar `RESOLVED_ON_BASELINE` e não editar desnecessariamente.

| ID | Finding | Owner | Teste-alvo | Estado |
|---|---|---|---|---|
| P0-01 | `vendor` symlink residual em sandbox | Foundation | `AtlasCloneDirWiperVectorTest` + Materializer | CONFIRMED_OPEN |
| P0-02 | comandos destrutivos de DB permitidos/sugeridos | Foundation | destructive-command policy test | CONFIRMED_OPEN |
| P0-03 | sete consumers de `RuntimeFlagsShared` sem import correto | SelfConstruction | focused ControlPlane suite | CONFIRMED_OPEN |
| P0-04 | DecisionReceipt hash/canonicalização incompletos | Kernel | `DecisionReceiptRuntimeGuardTest` | REVALIDATE |
| P0-05 | architecture route/onboarding/docs/schema blockers | Kernel | architecture validate/readiness | REVALIDATE |
| P0-06 | Dev pode apagar WIP dentro do scope | Dev | `PipelineRunExecutorTest` | CONFIRMED_OPEN |
| P0-07 | mandatory planning stages fail-open | Dev | fast-path stage/plan tests | CONFIRMED_OPEN |
| P0-08 | certification ocorre antes do repair proof | Dev | sovereign floor/executor tests | CONFIRMED_OPEN |
| P0-09 | GET mutativo, erro cru, lease e repair cap | Dev | HTTP/lease/repair tests | CONFIRMED_OPEN |
| P0-10 | learning Dev fabrica scores/auto-promoção | Dev | outcome-memory tests | CONFIRMED_OPEN |
| P0-11 | simulação Forge avança estado real | Forge | cycle service tests | CONFIRMED_OPEN |
| P0-12 | Forge aceita apenas um gate aprovado | Forge | completion gate tests | CONFIRMED_OPEN |
| P0-13 | conclusão prematura/reservation/continuation drift | Forge | long-horizon tests | CONFIRMED_OPEN |
| P0-14 | dois ciclos Forge concorrentes/test double “Live” | Forge | caller and architecture tests | CONFIRMED_OPEN |
| P0-15 | fila Autônomos oculta work acima de 500 | Autônomos | queue index tests | CONFIRMED_OPEN |
| P0-16 | `served` queima alvo no done-set | Autônomos | brain next→seed tests | CONFIRMED_OPEN |
| P0-17 | verdict pós-commit ignorado | Autônomos | serving service tests | CONFIRMED_OPEN |
| P0-18 | release ledger incompleto/defaults favoráveis | Shared | commit-governance tests | CONFIRMED_OPEN |
| P0-19 | native worker/daemon continuam dry-run ou callbacks | Autônomos | native worker/daemon tests | CONFIRMED_OPEN |
| P0-20 | bypass coverage e hard-stop pré-Merge ausentes | Kernel | coverage/pre-land tests | CONFIRMED_OPEN |

### Task 0 baseline evidence (2026-07-09, HEAD `3da23625f1`, main ahead origin by 3)

- Operator override: **no dedicated worktree**; edits on local `main`.
- `git rev-list --left-right --count main...origin/main` => `3 0` => baseline = `main`.
- Checkout-fonte dirty with concurrent Rivals + other WIP — **do not revert/overwrite**.
- Self-construction packet `AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001` claimed then released (`wrong_scope_for_elite_factory_v2`).
- Placement/bootstrap returned `gate_status=blocked` (duplicate_review collision on generic query); proceeding under operator override on local main.
- P0-01: `AtlasCloneDirWiperVectorTest` **9 passed**, but explicitly excludes `AtlasLoopProposalMaterializer.php` which still `@symlink` vendor/.env at L126–130 → **CONFIRMED_OPEN**.
- P0-03: Reflection fails `Trait ControlPlane\RuntimeFlagsShared not found`; trait lives in `Support\RuntimeFlagsShared` — 7 consumers missing import → **CONFIRMED_OPEN**.
- P0-06: `PipelineRunExecutor::revertWorkspaceChanges` still runs `git checkout -- <allowed>` + `git clean -fd -- <allowed>` on source workspace → **CONFIRMED_OPEN** (WIP inside scope wiped).
- P0-11/14: `safe_simulation` + dual cycle classes still present (`ForgeWorkPacketExecutionCycleService` + `Execution/ForgeWorkPacketExecutionCycle`) → **CONFIRMED_OPEN**.
- P0-20: `EngineeringKernel/Coverage/` absent; `MergeActuator` only exposes `revert()` → **CONFIRMED_OPEN**.
- `atlas_task_scope_reservations` migration: **absent**.
- Materializer suite: 2 failed / 2 passed (`GitSubprocess` missing + symlink path).
- PipelineRunExecutorTest: 17 failed / 5 passed on current dirty tree (broader than P0-06 alone).

Nenhum contrato v2 começa enquanto houver P0 `CONFIRMED_OPEN`.

---

## 5. Protocolo de cada packet

Todo packet segue:

1. Confirmar finding no HEAD.
2. Registrar owner e allowed files.
3. Escrever teste vermelho.
4. Executar teste e registrar falha esperada.
5. Implementar menor correção real.
6. Executar teste focado.
7. Executar testes vizinhos.
8. Rodar `git diff --check`.
9. Atualizar esta matriz e evidence refs.
10. Criar commit local atômico.

Commits não misturam Kernel e múltiplos modos.

Tasks 0–8 são sequenciais.

Depois da seam Kernel estável, Dev, Forge e Autônomos podem avançar em packets paralelos, sem editar simultaneamente Kernel ou `AppServiceProvider.php`.

---

### Task 0: Baseline, plan e ownership

**Files:**

- Create: `docs/superpowers/plans/2026-07-09-atlas-elite-engineering-factory-v2.md`
- Read: `AGENTS.md`
- Read: `docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md`
- Read: os quatro audits de 2026-07-09

**Produces:**

- baseline commit on local main (operator override: no worktree);
- matriz P0 revalidada;
- ownership map;
- lista exata de testes e migrations.

- [x] Confirmar branch/worktree limpos. *(override: dirty main; no worktree; isolated commits only for this plan)*
- [x] Rodar bootstrap, placement e context pack.
- [x] Rodar `git status`, migration status, route list e testes de baseline. *(partial: status + focused P0 probes; arch-validate still running)*
- [x] Atualizar cada P0 para `CONFIRMED_OPEN`, `RESOLVED_ON_BASELINE` ou `BLOCKED`. *(P0-04/05 still REVALIDATE pending test/arch output)*
- [x] Registrar resultados exatos no plano.
- [ ] Commitar somente o plano e baseline:

```bash
git add docs/superpowers/plans/2026-07-09-atlas-elite-engineering-factory-v2.md
git commit -m "docs: add Atlas elite engineering v2 execution plan"
```

Gate: nenhuma edição de runtime antes da matriz P0 estar revalidada.

---

### Task 1: Coverage v2 e bloqueio de novos bypasses

**Files:**

- Modify: `app/Services/Ai/Kernel/Architecture/KernelRoutingCoverageReport.php`
- Create: `app/Services/Ai/EngineeringKernel/Coverage/EngineeringExecutionCoverage.php`
- Create: `app/Services/Ai/EngineeringKernel/Coverage/EngineeringExecutionSurfaceRegistry.php`
- Test: `tests/Feature/Ai/Kernel/KernelRoutingCoverageReportTest.php`
- Create test: `tests/Feature/Ai/EngineeringKernel/EngineeringExecutionCoverageTest.php`
- Create test: `tests/Feature/Architecture/EngineeringKernelBypassRegressionTest.php`

**Interface:**

```php
EngineeringExecutionCoverage::record(array $event): void
EngineeringExecutionCoverage::report(): array
```

Usar `atlas_ledger_events`; não criar tabela de coverage.

- [ ] Escrever teste provando que `kernel_routed=true` sem provider/evidence/release não conta como coverage v2.
- [ ] Escrever registry com todas as surfaces mutativas confirmadas.
- [ ] Registrar hashes de order/provider/workspace/evidence/release/outcome.
- [ ] Adicionar static guard contra provider/Git/release fora da allowlist.
- [ ] Começar em observe; fornecer enforce para o gate final.
- [ ] Rodar testes focados e arquitetura.
- [ ] Commit:

```bash
git commit -am "feat: add end-to-end engineering kernel coverage"
```

Gate: nenhum novo bypass entra enquanto a obra progride.

---

### Task 2: Piso anti-wiper

**Files:**

- Modify: `app/Services/Ai/AutonomousEvolution/AtlasLoopProposalMaterializer.php`
- Modify: `app/Services/Engineering/EngineeringWorkspaceService.php`
- Modify: `app/Services/Ai/RealExecution/AtlasRepoVerifiedDeliveryService.php`
- Create: `app/Services/Ai/EngineeringKernel/Safety/DestructiveDatabaseCommandPolicy.php`
- Modify: `app/Services/Ai/AiPermissionEngineSupport.php`
- Modify: `app/Services/Ai/Programming/AtlasDev/Gate/UnsafeCommandPolicy.php`
- Modify: `app/Services/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeSchedulerManifest.php`
- Modify: `app/Services/Ai/Programming/Governance/ProgrammingSpecCompiler.php`
- Test: `tests/Feature/Ai/AtlasCloneDirWiperVectorTest.php`
- Test: `tests/Unit/Ai/AutonomousEvolution/AtlasLoopProposalMaterializerTest.php`
- Create test: `tests/Unit/Ai/EngineeringKernel/Safety/DestructiveDatabaseCommandPolicyTest.php`

**Invariantes:**

- `vendor` nunca é symlink;
- `.env` de sandbox é hermético;
- candidate autoload resolve candidate code;
- `migrate:fresh`, `migrate:refresh`, `db:wipe` e variantes são recusados;
- subprocessos de teste recebem `APP_ENV=testing`, SQLite, `DB_URL=''`, cache/session/queue não persistentes.

- [ ] Reproduzir symlink residual em teste vermelho.
- [ ] Substituir por `AtlasCloneDir::copy`.
- [ ] Adicionar sentinel provando carregamento do candidate.
- [ ] Centralizar denylist destrutiva.
- [ ] Remover sugestões destrutivas do compiler.
- [ ] Rodar testes anti-wiper.
- [ ] Commit:

```bash
git commit -am "fix: eliminate remaining Atlas wiper vectors"
```

Gate: nenhum provisioner conhecido usa `vendor` symlink.

---

### Task 3: P0 de Foundation e Kernel

**Files:**

- Modify: sete `AgentDispatchPlanner*` consumers conforme baseline
- Modify: `app/Services/Ai/Kernel/Decision/DecisionReceiptHash.php`
- Modify somente se ainda necessário: `routes/api.php`
- Modify somente se ainda necessário: owner docs/onboarding
- Add forward repair migration somente se schema drift for confirmado
- Tests: DecisionReceipt, ControlPlane, architecture validate/readiness

- [ ] Confirmar cada failure antes de editar.
- [ ] Corrigir imports de `RuntimeFlagsShared`.
- [ ] Implementar canonicalização determinística e `JSON_THROW_ON_ERROR`.
- [ ] Revalidar rota Kernel Pipeline dentro do grupo autenticado.
- [ ] Corrigir onboarding e docs blockers atuais.
- [ ] Reparar schema somente por migration forward idempotente.
- [ ] Rodar architecture validate/readiness e docs health.
- [ ] Commit por finding independente; não agrupar correções não relacionadas.

Gate: P0-03, P0-04 e P0-05 fechados.

---

### Task 4: P0 Atlas Dev

**Files:**

- Modify: `app/Http/Controllers/AtlasDev/Support/PipelineRunExecutor.php`
- Modify: `app/Services/Ai/Programming/AtlasDev/Pipeline/AtlasDevFastPathOrchestrator.php`
- Modify: `app/Http/Controllers/AtlasDev/PlanController.php`
- Modify: `app/Http/Controllers/AtlasDev/ShowController.php`
- Modify: `app/Http/Controllers/AtlasDev/RunController.php`
- Modify: `app/Services/Ai/EngineeringKernel/AcceptanceBundle.php`
- Test: `tests/Unit/Ai/Programming/AtlasDev/Http/PipelineRunExecutorTest.php`
- Test: `tests/Feature/Ai/Programming/AtlasDev/Http/PipelineRunExecutorHttpSmokeTest.php`
- Test: Dev gate/sovereign floor suites

**Invariantes:**

- checkout-fonte permanece byte-identical até release;
- retries resetam somente sandbox;
- mandatory planning failure bloqueia;
- GET nunca escreve;
- erro externo é redigido;
- heartbeat diferencia lento de morto;
- repair attempts nunca excedem cap;
- learning não fabrica scores nem auto-promove.

- [ ] Testar tracked/untracked/rename/delete/binário/concurrent change.
- [ ] Implementar baseline real e three-way application.
- [ ] Classificar stages como advisory ou required.
- [ ] Mover certification para depois do repair proof.
- [ ] Completar AcceptanceBundle.
- [ ] Corrigir controllers, leases e repair count.
- [ ] Tornar learning proposal-only.
- [ ] Rodar Dev suites e commit por slice.

Gate: P0-06 até P0-10 fechados.

---

### Task 5: P0 Atlas Forge

**Files:**

- Modify: `app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php`
- Retire/migrate callers: `app/Services/Ai/Programming/Forge/Execution/ForgeWorkPacketExecutionCycle.php`
- Modify: `app/Services/Ai/Programming/Forge/ForgeLongHorizonStateService.php`
- Modify: reservation/continuation services identificados no baseline
- Test: `tests/Feature/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleServiceTest.php`
- Test: `tests/Feature/Ai/Programming/Forge/Execution/ForgeWorkPacketExecutionCycleTest.php`
- Test: `tests/Feature/Ai/Programming/Forge/ForgeLongHorizonStateServiceTest.php`

**Invariantes:**

- simulação não satisfaz packet, milestone, dependency ou completion;
- todos os gates aplicáveis precisam passar;
- Obra só termina em estado terminal real;
- reservation expirada não bloqueia;
- continuation é idempotente;
- existe um único ciclo canônico;
- test double não se chama “Live”.

- [ ] Reescrever testes que atualmente esperam simulação avançar produção.
- [ ] Corrigir gate aggregation.
- [ ] Corrigir state completion.
- [ ] Corrigir reservation e continuation.
- [ ] Migrar callers para o ciclo canônico.
- [ ] Remover/deprecar implementação concorrente.
- [ ] Rodar Forge suites e commit por slice.

Gate: P0-11 até P0-14 fechados.

---

### Task 6: P0 Atlas Autônomos

**Files:**

- Modify: `app/Services/Ai/SelfConstruction/TaskQueue/TaskQueueRegistryIndexStore.php`
- Modify: `app/Console/Commands/AtlasBrainNextCommand.php`
- Modify: `app/Console/Commands/AtlasBrainSeedCommand.php`
- Modify: `app/Services/Ai/SelfConstruction/AtlasTaskServingService.php`
- Modify: `app/Services/Ai/SelfConstruction/Governance/AtlasTaskCommitGovernanceChain.php`
- Modify: `app/Services/Ai/EngineeringKernel/Adapters/AtlasAutonomosGateAdapter.php`
- Modify: `app/Services/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerClaimExecuteReportCycle.php`
- Modify: `app/Console/Commands/AtlasSelfConstructionRuntimeDaemonCommand.php`
- Test: queue, serving, governance, native worker e daemon suites

**Invariantes:**

- nenhuma task viva é ocultada;
- limite afeta apenas histórico terminal;
- `served` não queima target;
- post-commit red impede settle;
- release receipt exige evidence e rollback;
- missing evidence é unknown/block;
- worker produtivo nunca começa green;
- apply mode não usa dry-run;
- daemon produtivo não depende de callbacks de teste.

- [ ] Testar mais de 500 tasks vivas.
- [ ] Corrigir done-set states.
- [ ] Tornar verdict pós-commit obrigatório.
- [ ] Corrigir payload e falha de ledgers.
- [ ] Remover defaults favoráveis.
- [ ] Ligar worker e daemon a implementações reais.
- [ ] Rodar suites e commit por slice.

Gate: P0-15 até P0-19 fechados.

---

### Task 7: Hard-stop compartilhado antes de efeitos

**Files:**

- Create: `app/Services/Ai/EngineeringKernel/AuthorizedMergeAction.php`
- Modify: `app/Services/Ai/EngineeringKernel/MergeActuator.php`
- Modify: `app/Services/Ai/EngineeringKernel/Adapters/TaskLaneMergeActuatorAdapter.php`
- Modify: `app/Services/Ai/SelfConstruction/Governance/AtlasTaskMergeActuator.php`
- Modify: `app/Services/Ai/SelfConstruction/Governance/AtlasTaskCommitGovernanceChain.php`
- Modify: Dev/Forge merge adapters
- Test: `tests/Feature/Ai/EngineeringKernel/PreLandSeamTest.php`
- Test: `tests/Unit/Ai/SelfConstruction/Governance/AtlasTaskMergeActuatorTest.php`
- Test: governance fail-closed suites

- [ ] Testar ledger indisponível ⇒ zero efeito.
- [ ] Testar authority stale/revogada ⇒ zero efeito.
- [ ] Testar candidate hash adulterado ⇒ zero efeito.
- [ ] Testar rollback posture ausente ⇒ zero efeito.
- [ ] Implementar prepare/act/settle.
- [ ] Implementar `release_uncertain`.
- [ ] Garantir que Dev, Forge e Autônomos usam a mesma Interface.
- [ ] Commit:

```bash
git commit -am "feat: enforce pre-actuation release authority"
```

Gate: P0-20 fechado.

---

### Task 8: Kernel v2 vertical e real

**Files:**

- Create: `app/Services/Ai/EngineeringKernel/ExecutionOrder.php`
- Create: `app/Services/Ai/EngineeringKernel/EngineeringOutcome.php`
- Modify: `app/Services/Ai/EngineeringKernel/AcceptanceBundle.php`
- Modify: `app/Services/Ai/EngineeringKernel/EliteExecutorKernel.php`
- Modify: `app/Services/Ai/EngineeringKernel/ProviderPort.php`
- Modify: `app/Services/Ai/EngineeringKernel/WorkcellExecutor.php`
- Modify: `app/Services/Ai/EngineeringKernel/ReceiptLedger.php`
- Modify: `app/Services/Ai/EngineeringKernel/BudgetMeter.php`
- Replace/demote shallow adapters
- Create tests under `tests/Unit/Ai/EngineeringKernel/`
- Create E2E fixture test under `tests/Feature/Ai/EngineeringKernel/`

- [ ] Criar os três contratos públicos.
- [ ] Preservar readers/methods v1 somente atrás do tradutor.
- [ ] Implementar primeira fatia read-only.
- [ ] Implementar provider real via `AiProviderManager`.
- [ ] Implementar workcell em sandbox isolado.
- [ ] Implementar evidence/repair/replay/regression.
- [ ] Implementar governed release no fixture repo.
- [ ] Testar crash e reconciliação.
- [ ] Exigir coverage completo da fatia.
- [ ] Commitar cada fatia vertical separadamente.

Gate: Kernel executa provider→workspace→evidence→release→outcome real.

---

### Task 9: Atlas Dev v2

**Primary Interface:**

```php
AtlasDevExecutionService::plan(DevIntent): DevPlan
AtlasDevExecutionService::run(ConfirmedDevRun): DevRunResult
```

**Requirements:**

- HTTP, CLI, Desktop, Mission e Senior Loop delegam à mesma façade.
- Qualquer complexidade técnica pode permanecer Dev.
- Duração seleciona interactive/durable.
- Risco aumenta isolamento e prova, não reduz capacidade.
- Workcells usam sandboxes independentes.
- Integração é serial.
- Ownership overlap bloqueia/replaneja.
- Handoff Forge é idempotente.
- Learning continua proposal-only.
- Medir operator questions, overrides, cancelamentos, handoffs e active time.

Gate:

- zero WIP perdido;
- zero Kernel bypass;
- paridade entre surfaces;
- canários low/mixed/R5 em sandbox;
- write run aprovado sempre possui release.

---

### Task 10: Atlas Forge v2

**Primary Interface:**

```php
ForgeObraRuntime::commission(ForgeCommissioning): ForgeObraSnapshot
ForgeObraRuntime::tick(ForgeObraId, ForgeTickBudget): ForgeTickResult
ForgeObraRuntime::control(ForgeObraId, ForgeControlCommand): ForgeObraSnapshot
ForgeObraRuntime::snapshot(ForgeObraId): ForgeObraSnapshot
```

**Requirements:**

- `AiForgeIntake` é a identidade operacional canônica.
- Commissioning produz uma autoridade.
- Supervisor/jobs/reaper usam leases, heartbeat e fencing.
- Provider usa start/poll/cancel/heartbeat.
- Packets executam pelo Kernel.
- Workers nunca tocam main.
- Integration branch é serial.
- Retry/fallback/replan são automáticos.
- Pause/drain/cancel/orphan recovery funcionam em qualquer estágio.
- Simulação usa projeção separada.
- Snapshot é reconstruível por eventos.

Persistência:

- estender `ai_forge_long_horizon_states`;
- estender `ai_forge_work_packet_execution_cycles`;
- não criar outro outcome/event ledger.

Gate:

- Obras reais de 1, 3 e 10 packets;
- crash em cada fronteira;
- zero efeito duplicado;
- soak de 24h iniciado;
- zero confirmação rotineira após commissioning.

---

### Task 11: Atlas Autônomos v2

Ciclo fechado:

```text
Brain
→ Proposal Arena
→ Spec Court
→ atomic reservation
→ Task Fabric
→ native workcell
→ Kernel
→ Governor
→ release/canary
→ outcome
→ learning
→ Brain
```

**Requirements:**

- arena compara 2–3 propostas;
- ranking por alavancagem, simplificação, recorrência, verificabilidade e blast radius;
- proposta sem finding/baseline/delta/rollback/teste é recusada;
- `next→seed` é idempotente;
- scope reservation é transacional;
- native worker chama provider real;
- supervisor/fleet mede processos e heartbeats reais;
- primary+fallback provider;
- indisponibilidade total pausa/retry sem corrupção;
- nenhuma sessão externa conta como worker;
- Constitution Gate usa authority existente, nonce, replay defense, validade e revogação;
- Autônomos não autoeleva autoridade;
- ação fora da constituição replana/quarentena e continua outro trabalho.

Gate:

- mais de 500 tasks vivas visíveis;
- zero scope collision;
- zero sessão humana;
- kill/restart seguro;
- release e rollback exercitados em staging;
- soak 24h/7d iniciado.

---

### Task 12: Outcomes e aprendizagem causal

**Files:**

- Modify: `app/Models/AiRunOutcome.php`
- Modify: evaluator/recorder de `ai_run_outcomes`
- Modify: Atlas Evidence Ledger event types
- Modify: Dev/Forge/Autônomos outcome adapters
- Tests: outcome default, unknown, replay, observation windows

**Requirements:**

- missing status nunca vira passed;
- missing source nunca vira score favorável;
- outcome imediato não prova outcome temporal;
- observações usam release hash;
- learning só autoaplica routing/memory/policy reversível em experimento;
- mudança de código volta como task normal;
- backfill histórico é `legacy_unproven`.

Gate: outcome instrumentation pronta antes do cutover.

---

### Task 13: Compatibilidade e corte integral

**Requirements:**

- um único runtime v2 no artefato novo;
- fachada v1 traduz request/response para v2;
- writers somente v2;
- nenhuma provider invocation duplicada;
- migrations compatíveis com N−1;
- kill switch por modo significa stop/drain/quarantine, nunca fallback v1;
- canários por modo somente staging/sandbox;
- quatro readiness manifests verdes;
- rollback por redeploy N−1;
- migrations destrutivas proibidas durante a janela de rollback.

Readiness manifests:

- Kernel
- Dev
- Forge
- Autônomos

Gate: `cutover_ready`.

Não executar o corte real sem autorização explícita.

---

### Task 14: Certificação temporal e Rivals

Após `cutover_ready`:

- iniciar 24h, 7d, 30d, 90d e 150d conforme aplicável;
- registrar uptime, orphan state, rollback, regressão, incidente, operador e custo;
- reconciliar trabalho Rivals existente antes de editar seus arquivos;
- executar campanhas pareadas;
- acompanhar 30 dias;
- calcular IC 95%.

Claim 10× exige simultaneamente:

- time-to-accepted-release ≤ 0,1×;
- accepted throughput/operator-hour ≥ 10×;
- escaped defect/rework ≤ 0,1×;
- segurança/NFR/manutenção não inferiores;
- limite inferior do IC sustentando o claim.

Gate: `operationally_certified` e depois `comparatively_proven`.

---

### Task 15: Remoção v1

Somente na versão posterior ao cutover e após:

- zero uso v1 observado;
- rollback window encerrada;
- replay/export validados;
- artefato N−1 não mais necessário;
- migrations destrutivas revisadas.

Remover:

- fachada v1;
- legacy execution paths;
- adapters sem caller;
- classes comprovadamente inalcançáveis.

Não usar remoção por prefixo `AtlasLoop*`.

---

## 6. Gates e comandos finais

Use `/opt/homebrew/bin/php` quando necessário.

Por packet:

```bash
/opt/homebrew/bin/php artisan test <teste-focado>
git diff --check
```

Após cada subsistema:

```bash
/opt/homebrew/bin/php artisan test <diretório-do-subsistema>
vendor/bin/pint --test <paths-tocados>
vendor/bin/phpstan analyse <paths-tocados>
```

Antes de `cutover_ready`:

```bash
/opt/homebrew/bin/php artisan test
/opt/homebrew/bin/php artisan atlas:ai:architecture-validate --json
/opt/homebrew/bin/php artisan atlas:ai:architecture-readiness --json
/opt/homebrew/bin/php artisan atlas:engineering:knowledge docs-health --json
/opt/homebrew/bin/php artisan migrate:status
git diff --check
git status --short
```

Também:

- sincronizar Engineering Knowledge;
- reindexar Code Intelligence;
- regenerar provider projections;
- executar DecisionReceipt replay;
- executar Evidence Ledger replay;
- produzir coverage v2;
- produzir os quatro readiness manifests.

## 7. Formato de atualização deste plano

Depois de cada packet, adicionar:

```text
Packet:
Commit:
Files:
Red test:
Green tests:
Architecture/docs/schema gates:
Evidence refs:
Remaining blockers:
Next packet:
```

Não substituir evidência por percentuais subjetivos.

## 8. Critério de honestidade final

Nunca escrever “implementação completa” quando houver:

- teste vermelho;
- route/schema/doc blocker;
- provider simulado;
- callback de teste em produção;
- evidence ausente;
- ledger error;
- coverage abaixo de 100%;
- rollback não exercitado;
- janela temporal ainda não decorrida.

Use estados precisos:

- `implemented_not_cutover_ready`
- `cutover_ready`
- `operational_certification_pending`
- `operationally_certified`
- `comparative_proof_pending`
- `comparatively_proven`

---

## Packet log

### Packet: Task 0 — baseline + plan materialization

Commit: *(pending)*
Files: `docs/superpowers/plans/2026-07-09-atlas-elite-engineering-factory-v2.md`
Red test: n/a (docs-only packet)
Green tests: `AtlasCloneDirWiperVectorTest` 9 passed (does not cover Materializer symlink)
Architecture/docs/schema gates: bootstrap/placement `gate_status=blocked` (duplicate_review); arch-validate in flight
Evidence refs: HEAD `3da23625f1`; main ahead origin `3 0`; Materializer L126–130 symlink; RuntimeFlagsShared wrong namespace; MergeActuator revert-only
Remaining blockers: 18+ P0 `CONFIRMED_OPEN`; P0-04/05 still REVALIDATE
Next packet: Task 1 (Coverage v2) after Task 0 commit — then Task 2/3 P0 fixes; **no Task 8 until zero CONFIRMED_OPEN**
