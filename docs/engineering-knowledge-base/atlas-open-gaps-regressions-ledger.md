---
id: atlas-open-gaps-regressions-ledger
type: engineering_knowledge
title: Atlas — Ledger de Gaps, Regressões e Falhas Abertas (implementation-ready)
status: active
category: strategy
priority: 99
doc_schema: atlas_canonical_module_doc.v1
graph_layer: system
summary: "LEDGER VIVO de gaps/erros/falhas abertos do Atlas, com PROVA por entrada (file:line + evidência verificada, várias runtime-provadas), severity, broken_now, fix_approach, esforço — pra corrigir/implementar liso. RAIZ #1: commit cd018c6b3f 'defatoração grok 4.5' deletou 771 classes e deixou consumers VIVOS pendurados (5 quebras runtime-provadas de esforço BAIXO). RAIZ #2: gates que certificam CONFIANÇA-não-correção + fail-open swallows em checks de segurança/prova + telemetria que mente. Curado por gap-hunt contínuo (sweeps wrxgcbok3/wdtpxya7x/w5ejv9oq9)."
tags: [atlas-ai, gap-ledger, regressions, technical-debt, honesty-gates, silent-failure, review-cockpit, implementation-ready]
capabilities: [open_gap_tracking, regression_ledger, honesty_audit]
decisions:
  - Só entra o que tem PROVA (broken_now exige evidência real, idealmente runtime; dormente entra pelo valor destravável). Falso-alarme vai pra seção Z com o porquê.
  - Fix-order = broken_now que atinge VIVO/shipado (crítico) → honesty-gates em caminho vivo → alto destrave/baixo esforço → dead-loop cruft por último.
  - Regressão cd018c6b3f é botada — deletou dead-loop MAS pegou código VIVO junto. Fix por-caso restore(git checkout) vs finish-delete; NÃO restaurar o loop morto em massa.
maintenance:
  - Apendar por sweep; marcar status (ABERTO/EM-FIX/FECHADO+commit); nunca remover entrada fechada.
related_paths: [docs/engineering-knowledge-base/atlas-terminal-first-focus.md, docs/engineering-knowledge-base/atlas-autonomos-live-system.md]
graph_id: atlas-open-gaps-regressions-ledger
graph_title: Atlas Open Gaps Regressions Ledger
graph_world: atlas
graph_kind: runbook
graph_parent: atlas-ai-knowledge-governance-system
graph_status: active
graph_source: repo
owner: atlas-ai
human_name: Atlas Open Gaps Regressions Ledger
canonical_name: Atlas Open Gaps Regressions Ledger
technical_name: atlas-open-gaps-regressions-ledger
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-open-gaps-regressions-ledger.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-open-gaps-regressions-ledger.md
  - docs/engineering-knowledge-base/atlas-terminal-first-focus.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
allowed_changes:
  - Apendar gaps com prova; marcar ABERTO/EM-FIX/FECHADO+commit.
forbidden_changes:
  - Remover entrada fechada.
  - Entrar gap sem prova (broken_now exige evidencia).
depends_on:
  - atlas-ai-knowledge-governance-system
  - atlas-autonomos-live-system
flows_to:
  - atlas-terminal-work-charter
  - atlas-residual-elite-documentation-promotion-2026-07
unlocks:
  - implementation-ready-gap-queue
governs:
  - open-gap-tracking
  - regression-ledger
evidence:
  - docs/engineering-knowledge-base/atlas-open-gaps-regressions-ledger.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Seguir FIX-ORDER; fechar broken_now vivos antes de dead-loop cruft.
---

# Atlas — Ledger de Gaps, Regressões e Falhas Abertas

> **Propósito:** caçar+documentar com PROVA todo gap/erro/falha antes da hora de corrigir, pra implementação sair lisa.
> Status: **ABERTO** · **EM-FIX** · **FECHADO**(commit). Fonte: sweeps do gap-hunt (IDs citados). Companion: `atlas-terminal-first-focus.md`.

## Resumo

Ledger vivo de gaps/regressoes/falhas abertas com prova por entrada, severity e fix-order. Companion de `atlas-terminal-first-focus.md`. Naming: ver `docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md` (Dev/Forge).

## Papel no Atlas

Fonte operacional para corrigir com prova — nao e SoT de arquitetura; aponta owners e evidencia.

## Onde Se Encaixa

Knowledge governance → gap hunt → implementacao. Alimenta sessoes focadas (terminal charter) e Residual Elite residuals.

## Contratos

| Contrato | Regra |
|---|---|
| Entrada | so com prova |
| Status | ABERTO / EM-FIX / FECHADO+commit |
| Fix-order | broken_now vivo → honesty gates → alto destrave |

## Fluxo

1. Sweep encontra gap.
2. Entrada com prova + severity.
3. Fix por-caso.
4. Marcar FECHADO com commit; nunca apagar.

## Regras para IA

- Nao mass-restore o loop morto.
- Nao inventar gap sem evidencia.
- Preferir restore pontual de codigo VIVO vs finish-delete de dead-loop.

## Escopo de Implementacao

Inclui tracking e priorizacao de gaps. Nao inclui reimplementar ACDE ou promover mapa operacional a SoT.

## Dependencias

- Docs canonicos owners
- Evidence/runtime proofs citados por entrada

## Evidencias

Entradas com file:line / runtime proof nos sweeps citados no corpo do ledger.

## Riscos

Falso-alarme; confundir dead-loop com vivo; apagar historico fechado.

## Exemplos

Ver secao FIX-ORDER e tabelas GAP-* no corpo deste doc.

## Proximas Acoes

- Executar FIX-ORDER item a item com prova.
- Atualizar Residual Elite residuals quando status mudar.

## 🎯 FIX-ORDER DEFINITIVO (quando a hora de corrigir chegar)
1. **🔴 VOCÊ, urgente:** `GAP-SEC-01` — rotacionar chaves `.env` (Binance→ElevenLabs→Cursor→YouTube→ATLAS_TOKEN) + purgar histórico (filter-repo/BFG) + force-push.
2. **🔴 cd018 restores VIVOS** (git checkout, trivial, un-quebra a main de uma vez): `GAP-01` GitSubprocess (+24 callers, +8 SoftwareCompany GAP-DOM-02), `GAP-02` AtlasDeadCodeAnalyzer (→ HonestyGate+deadcode-check), `GAP-DOM-01` PressureLayerGuards/AtlasLoopWiredCallerService (→ pressure:*+`atlas:land`), `GAP-05` remover import venenoso Maestro (1 linha), `GAP-03` ScenarioExplorer (→ finance), `GAP-04` AAEL.
3. **🟠 Segurança/dinheiro/soberania** (baixo esforço): `GAP-SOV-01` sensitivity no gateway (local-first), `GAP-CYBER-01` rotear ofensivo fail-closed, `GAP-FIN-01` spot honrar o canônico, `GAP-MKT-03` PolicyComplianceGate no composer.
4. **🟠 Honesty/coverage** (evita o próximo cd018 silencioso): `GAP-30/31/32` fake-pass gates, `GAP-COV-01/02` teste do ramo de bloqueio, `GAP-WIP-01` vendor clonefile.
5. **🟡 Latente:** `GAP-RACE-01`, `GAP-WIR-01/02`, `GAP-MEM-01/02/03`, flags `GAP-06/07/08/GOV-01`.
6. **Decisão estrutural:** cd018 dead-loop (`GAP-17-23`) → **finish-delete** (não restaurar o cadáver).
7. **Oportunidade:** ligar bridges dormentes (cockpit `GAP-COCKPIT-01` + `GAP-09-16`).

## 🔴 A. Regressão-raiz `cd018c6b3f` "defatoração grok 4.5" — deletou 771 classes, deixou consumers VIVOS pendurados
> Outra sessão (grok 4.5) tentou de-bloatar o loop morto por DELEÇÃO em massa — mas pegou código VIVO/shipado junto e deixou 152 arquivos com refs penduradas. **Deletou inclusive o meu fix do LIMPA-BOA (AtlasDeadCodeAnalyzer, a817e22a22) 2 commits depois.** Valida a tese: de-confusão = MARCAR, não mass-delete. **Decisão do operador: restore por-caso (vivo) vs finish-delete (dead-loop).**

**5 quebras `broken_now` RUNTIME-PROVADAS, todas esforço BAIXO (corrigir primeiro):**

| ID | Sev | O quê | Prova | Fix |
|---|---|---|---|---|
| **GAP-01** | 🔴 crítico | `GitSubprocess` deletado → 24 callers vivos quebram, incl. `atlas:software-company-stewardship:live-cycle-audit` + o path ff-only merge (único merge real) | runtime: "Class GitSubprocess not found" @:743 | `git checkout cd018c6b3f^ -- app/Services/Ai/AutonomousEvolution/Support/GitSubprocess.php` (não toca os 24 callers) |
| **GAP-02** | 🔴 crítico | `AtlasDeadCodeAnalyzer` deletado → `atlas:code:deadcode-check` fatal **E** torna o gate de governança `AtlasEngineeringHonestyGate` (ativo, 8d94eeb) irresolvível | runtime: BindingResolutionException | `git checkout a817e22a22 -- <os 2 paths>` (versão já-corrigida-em-traits) |
| **GAP-03** | 🟠 alto | `AtlasEvolutionScenarioExplorer` deletado → `atlas:finance:strategy-evolve` fatal (**domínio finance shipado**, não loop morto) | runtime: not found @AppServiceProvider:670 | `git checkout cd018c6b3f^ -- <path>` |
| **GAP-04** | 🟠 alto | `AtlasAaelParallelLockManager`+`LockHandle` deletados → `atlas:aael:parallel` fatal (injeção no handle) | determinístico (mesmo mecanismo GAP-02/03) | `git checkout cd018c6b3f^ -- <2 paths>` |
| **GAP-05** | 🟠 alto | Import venenoso `use ...Maestro\Health\Maestro` (add por cd018c6b3f) @`AgentControlPlaneTaskQueueOrchestrator.php:33` sequestra resolução relativa @:1475 → **100% dos eventos give_back do músculo VIVO somem do behavior-ledger, silenciosamente** (engolido por catch fail-open @:1310) | import verificado + miner real existe | remover a linha 33 (1 linha; nem é usada) |

**Dano dead-loop (fatal só SE o loop morto rodar — MÉDIO; decidir restore vs finish-delete):**
- **GAP-17** `LoopExecutionDriver` bind → cadeia 100% deletada (`WorkspaceProviderLoopExecutionDriver` + 2 decorators + fallback `SeniorLoopExecutionDriver`, todos gone). AppServiceProvider:860-863.
- **GAP-18** ~6 singletons/factories HARD (sem rescue) resolvem classes deletadas (AtlasLoopTrinityContractEmitter, AtlasCortexCouncilTriangulator, AtlasLoopCycleReceiptLedger, AtlasLoopBenchmarkHarness, AtlasEvolutionScenarioExplorer, AtlasLoopRefillerRegistry). AppServiceProvider:356/370/491/508/670/285.
- **GAP-19** núcleo do loop via `new` sem rescue em **~152 arquivos** (AtlasLoopWiredCallerService/WorkShapeRouter/AtlasDeadCodeAnalyzer). Blast medido: `rg -lwf <771-gone> app/` = 152 (127 em AutonomousEvolution).
- **GAP-20** VOs deletados (ScopeProposal/ScopeOriginationReceipt/Verdict + AutopoieticScopeOriginationVerdict) instanciados por survivors na Autopoiesis.
- **GAP-21** VOs Aael/Execution + AuditTrail deletados (RedactedEvidence/InspectorSnapshot/DivergenceFact/DebuggerReceiptEntry, AuditTrail\AuditEvent, ExportManifest). ⚠️ cuidado: `App\Models\AuditEvent` é OUTRA classe VIVA.
- **GAP-22** 🟢 baixo/não-fatal: `config/atlas.php:90` executor_organ cita `AtlasLoopPatternSourceIntake::class` deletada (`::class` é string, não instancia).
- **GAP-23** 🟢 cluster DORMENTE-MORTO ACDE (7 órgãos 0-caller apontam pro loop morto) — **NÃO ligar; candidatos a deleção** (finish-delete).

## 🟠 B. Honesty-gates: certificam CONFIANÇA-não-correção + fail-open em check de segurança/prova (sweep w5ejv9oq9)
> O eixo mais perigoso pro M do Atlas — gate diz "passed" sem provar; erro vira verde silencioso.

| ID | Sev | O quê | Onde |
|---|---|---|---|
| **GAP-30** | 🟠 alto | `AtlasTaskCommitVerificationGate`: `realRun()` em erro de infra/timeout retorna `['ran'=>false,'ok'=>true]` → marca `task_tests='pass'` + `proof_strength='task_tests_proven'` **sem o runner ter rodado** (fake-proof no caminho VIVO do músculo) | `AtlasTaskCommitVerificationGate.php:118,216-227` |
| **GAP-31** | 🟠 alto | Fake-green honesty gate é engolido SÓ no dev-worker: evento diz 'blocked' mas o job grava `ai_job_succeeded` | `AiWorker.php:646-660` (→2168/2169) |
| **GAP-32** | 🟠 alto | `VoxReadinessService`: checks de SEGURANÇA/privacidade (raw-audio policy, confirmation gate) fabricam `count=0`+PASSED quando o probe de métrica falha | `VoxReadinessService.php:404,437` |
| **GAP-33** | 🟠 alto | `ToolRuntimeReadinessService`: carimba bridge de governança/evidência como 'passed' mesmo quando o bridge está indisponível | `ToolRuntimeReadinessService.php:145,161` (+ família) |
| **GAP-34** | 🟡 médio | `ProgrammingImplementationTruthGate` (anti-over-claim): vira PASSED silencioso em QUALQUER erro | `.../Gates/ProgrammingImplementationTruthGate.php:42` |
| **GAP-35** | 🟡 médio | `ProgrammingScopeGuardGate`: cross-check git-diff degrada pra `[]` em silêncio → enfraquece o guard de escopo | `.../Gates/ProgrammingScopeGuardGate.php:142` |
| **GAP-36** | 🟡 médio | `AtlasVerifiedExecutionCertificationService` (`atlas:aver:certify`): `passed`+certification_hash provando presença de STRING no fonte, não execução | `AtlasVerifiedExecutionCertificationService.php:64-76` (+~10 certs) |
| **GAP-37** | 🟡 médio | Served-cost row alimenta o ledger Maestro com `model='n/a'` + tokens FABRICADOS (contagem de arquivos) | `AtlasTaskServingService.php:558-573` |
| **GAP-38** | 🟢 baixo | `buildJudgeInput` fabrica `lane_results status='completed'`, actions=[] pra lanes que nunca rodaram | `MultiAgentLiveCycleExecutorService.php:534-540` |

## 🟡 C. Governança/flags default-OFF que deixam trabalho construído inerte
- **GAP-06** 🟠 `reflection_enabled` default OFF **contradiz o próprio comentário 'default ON'** → cérebro VIVO roda cego (sem série temporal de percepção). `config/atlas.php`.
- **GAP-07** 🟡 `causal_selector_enabled` OFF → seletor causal cai pro ordering puro (arco de aprendizado inerte).
- **GAP-08** 🟡 `adml_auto_activation` OFF → auto-ativação do ADML (live evidence loop) inerte.
- **GAP-GOV-01** 🟡 `enforce=OFF` (config:1168) + `cost_guard.hard_units=0` → `should_block` nunca dispara (governança mede, não governa).
- **GAP-GOV-02** 🟡 ledger 0 records de músculo (66/66 ai_provider_manager) → bypass-rate não-provado.

## 🟢 D. Dormentes VIVO-VIÁVEIS: bridges/spines 0-caller que fechariam o loop do autônomo (reuse, não construir)
> Padrão "the MISSING SPINE": produtor construído, 0 callers, aponta pro vivo. LIGAR destrava.
- **GAP-COCKPIT-01** ✅ **FECHADO 09/07** — produtor ligado: `AtlasTaskLandingReviewPublisher` (SelfConstruction/, pull-based sobre os receipts `.resolved.jsonl` do markResolved, dedupe por commit sha) + comando `atlas:task:review:publish` + veredito `task_landing_review_approve|reject` no `InboxActionRegistry` (reject NUNCA reverte; devolve `atlas:task:revert --task=...`). Provado no vivo: 3 landings reais viraram itens com diff_stat; idempotente; visível no `atlas:cli:inbox`. Teste: `tests/Feature/Ai/TaskLandingReviewCockpitTest.php`. O publisher dormente `AtlasLoopOperatorReviewMobilePublisher` foi SUPERSEDED (design espelhado; ficou acoplado à fila morta — candidato a sair com o morto). Bônus: restaurada a interface `Contracts/BroaderRegressionGateContract` (cd018 deletou com 4 consumidores vivos — QUALQUER inbox action fatalava ao resolver o registry).
- **GAP-09** 🟠 `AtlasExternalBrainToTaskFabricBridge` — espinha proposta-do-cérebro→task-pronta 0 callers.
- **GAP-10** 🟠 `AtlasExternalBrainOutcomeLearningToMaestroBridge` — fecha o Learning Loop (outcome→roteamento) 0 callers.
- **GAP-11** 🟠 `AtlasExternalBrainAutonomousSpine` — snapshot único de 6 órgãos + next_action, 0 callers.
- **GAP-12..16** 🟡 ProofFirstTaskEmitter, LocalClientFailureRecoveryTaskEmitter, MaestroHealthGateSeedCreditBridge, AutonomyRegressionTaskEmitter, CortexCapabilityGapMemoryBridge — todos 0 callers, vivo-viáveis.
- **GAP-COCKPIT-02** ✅ **FECHADO 09/07** — fallback default de `job_result` agora oferece `approve_job_result`/`reject_job_result` (handlers já existiam em `jobResultAction`; o `JobResultInboxEmitter` já os dava pra failed/high — o gap real era só o default). `job_status`/`completion` ficam read-only de propósito (nenhum handler de veredito aceita esses types; emitter vivo de `completion` não encontrado).
- **GAP-COCKPIT-04** ✅ **FECHADO 09/07** — gerador entregue: `atlas:task:review:deep <sha|task_packet_id>` (`AtlasTaskLandingDeepReviewService`) PRODUZ findings determinísticos da landing: scope vs allowed_files (receipt), forbidden_self_target (pétreo), `php -l` do conteúdo COMMITADO, presença de teste. Read-only; blocking em p0/p1. Provado no vivo (landing real → `no_test_touched`). Teto honesto: checks mecânicos, não review semântico — o recorder `atlas:review:deep` continua sendo onde findings semânticos (de IA/humano) são registrados. Teste: `tests/Unit/Ai/SelfConstruction/AtlasTaskLandingDeepReviewServiceTest.php`.
- **GAP-COCKPIT-03** ✅ **FECHADO 09/07** — feed cross-surface entregue dentro do `atlas:cli:cockpit` (seções: landings autônomas via receipts read-only + Dev jobs recentes + Forge obra ativa + review pendente com next-step).

## 🟡 E. Ergonomia CLI (situational awareness)
- **GAP-CLI-01** ✅ **FECHADO 09/07** — `atlas:cli:cockpit` (`AtlasCliCockpitCommand`): agregador read-only fail-open por seção (cérebro brain:summary, fila task:health, autonomia, landings recentes via `recentLandings()` do publisher, review pendente + next-step, Dev jobs, Forge obra), render `TerminalMarkdownRenderer`, `--json`. Provado no vivo (7/7 seções ok). Teste smoke: `AtlasCliCockpitCommandTest` (gotcha: Artisan::call aninhado sobrescreve o buffer do facade — usar BufferedOutput próprio).
- **GAP-CLI-02** 🟡 `atlas status`/dashboard cego pro motor autônomo (mitigado: o cockpit acima É a visão do motor; dashboard segue sem as seções).
- **GAP-CLI-03** 🟡→🟢 `TerminalMarkdownRenderer` agora em 3 comandos (cockpit adotou); adoção ampla segue opcional.

## ⏳ EM-FIX (outra sessão)
- **REG-01** proof-family namespace (TerminalLoopHealthDigest/OperationalProof) fatal — `task_52704584`.
- **REG-02** (constatado 09/07, pré-existente) `MobileGatewayTest` 7 falhas: 3× sheet 202→422 (chat/thread policy), 1× health `disabled`, 1× cursor flaky, 2× approve end-to-end da fila do loop MORTO retorna `read` (não-merged) — estas 2 antes nem rodavam (fatal `BroaderRegressionGateContract not found` até a restauração de 09/07; a restauração destapou a quebra seguinte do caminho morto). Testes de contrato do inbox vivos: 22/22 verdes.
- **NOTA 09/07:** L3/bypass do `AiProviderManager` está EM VOO por outro worker (uncommitted: `recordBypass` + coverage por surface) — não abrir trabalho paralelo ali até landar.

## 🔴 F. Wiper / Secrets / Sandbox-floor (sweep w81pifwtp — maioria GUARDADA; 2 abertos reais)
> Verificado guardado (NÃO são gaps): RefreshDatabase/migrate:fresh (phpunit.xml force sqlite + TestCase kill-switch + process-env pin = 3 camadas); `git reset --hard` main (merge-lock exclusivo + working-tree-clean); rm-rf/deleteDirectory (todos tmp/worktree/sandbox); DELETE/TRUNCATE (read-models regeneráveis dentro de transação ou escopados por workspace/mission/plan-id).

### GAP-SEC-01 — 🔴 .env backups vazados em origin/main; chaves FINANCEIRAS não rotacionadas · ABERTO · **AÇÃO #1**
- **7 arquivos** `.env.bak-*`/`.env.*-backup-*` foram commitados **E PUSHADOS** pra origin/main (privado). Untrack só tirou do HEAD; blobs recuperáveis via `git show <sha>:<file>` (commits `b9e6719357`, `e60e560fbe`).
- **Chaves expostas: Binance (FINANCEIRA), ElevenLabs, Cursor, YouTube, ATLAS_TOKEN — NÃO rotacionadas.**
- Fix (é TEU — não posso rotacionar/force-push): (1) **ROTACIONAR já, Binance primeiro**; (2) purgar histórico (`git filter-repo`/BFG) + force-push; (3) confirmar. Casa com memória `github-main-env-bak-secret-leak`.

### GAP-WIP-01 — 🟠 vendor-symlink wiper vector residual (8º/último provisioner) · ABERTO
- `AtlasLoopProposalMaterializer::materializeFull:129` **symlinka o vendor VIVO** no clone de re-prova do auto-merge (caminho autônomo 24/7). Se um passo rodar `composer dump-autoload`, envenena o `vendor/autoload.php` VIVO — o incidente Frankenstein.
- Único provisioner não-clonefile (os 7 irmãos usam `AtlasCloneDir::copy`; `linkRuntimeDeps:668` tem o comentário-aviso). Loop parado agora → armado, não disparando.
- Fix: 1-linha `if ($dep==='vendor') AtlasCloneDir::copy(...)`. **Arquivo pétreo (forbidden-self-target)** → via canal operador/git escopado, não pelo autônomo. Casa com `wiper-vector-vendor-clonefile` (o "1 PENDENTE").

### GAP-WIP-02 — 🟡 AutoMerge checa self-target só no target_path, commita o $changed inteiro · endurecimento
- `AtlasLoopAutoMergeService:335` (check só target_path) vs `:658` (`git add -- $changed`). Proposta multi-arquivo tocando um pétreo + target legítimo escaparia o check. Marcado guardado-upstream, mas assimetria real. Fix opcional: iterar o $changed completo no check. (loop parado → baixo.)

## 🟡 G. Cobertura de teste + wiring + races nos caminhos VIVOS (sweep wat0rur84)
> Buracos de teste em gates VIVOS = onde o próximo cd018 passa silencioso. VERIFICADOS cobertos (sem buraco): `AtlasTaskScopedCommitter` (prova de NÃO-`add -A` + forbidden_self_target), `AtlasTaskCommitVerificationGate` (todos os ramos block/allow), `AtlasBrainSeedQualityGate` (~10 casos de refuse). Claim de lease é atômico (flock LOCK_EX fail-closed) — verificado sólido.

### GAP-COV-01 — 🟠 `ProgrammingImplementationTruthGate`: ramo de BLOQUEIO (over-claim) tem ZERO teste
- Gate R4 ATIVO (bootstrap/providers.php:10, QUALITY_GATES 'implementation-truth'). Se o glue drift→block quebrar, over-claims passam SILENCIOSOS → docs mentem `implementation_state` (corrompe a governança que o CLAUDE.md trata como fundação). Ramo tb estruturalmente inalcançável em teste (code index vazio → early-skip). `.../Gates/ProgrammingImplementationTruthGate.php:50-71`. Fix: unit test com stub `drift_count>0`. baixo.

### GAP-COV-02 — 🟠 `AtlasBrainSeedCommand`: a fiação gate()→blocked (recusa packet-lixo) não tem teste de comando
- Só os 4 gates isolados são testados; a fiação no comando (packet reprovado→`status=blocked`, NÃO enfileira) não. Refactor que inverta uma condição → lixo enfileirado no serving VIVO, workers implementam lixo, 0 teste vermelho. `AtlasBrainSeedCommand.php:128-135`. Fix: command test com packet proxy/forbidden asserindo `counts['blocked']>=1` + `enqueued==0`. baixo.

### GAP-COV-03 — 🟡 `AtlasBrainSeedCommand`: regressão "dry-run não grava done-set" sem teste
- Se um refactor reintroduzir `recordDone()` no ramo dry-run, todo pipeline dry-run→seed-real produz ZERO enqueues silenciosamente (cada seed real vira `skipped_done_set`). `:137-147`. Fix: teste dry-run-então-seed-real-do-mesmo-alvo. baixo.

### GAP-RACE-01 — 🟡 `report()` commita no main ANTES de validar posse da lease (commit-before-validate)
- `AtlasTaskServingService:537` (commitScope) antes de `:553` (ownership). Worker com lease expirada + task já re-servida a outro ainda LANDA commit no main compartilhado (mesma árvore/escopo). Fix: exigir lease ativa+dona (`agent==clientId`, `queue_status=='claimed'`) ANTES do commitScope.

### GAP-WIR-01 — 🟡 Maestro closed-loop dead-fed: `AtlasMaestroOutcomeShapeLedger` 0 callers → learning inerte
- Flag `atlas.maestro.closed_loop.feedback_enabled` + chain completo + testes verdes existem, mas `record()` nunca é chamado → ligar a flag produz saída VAZIA pra sempre (operador acha que o closed-loop aprende; não aprende). `:35`. Fix: chamar `record()` no report/markResolved/reportGiveBack do serving.

### GAP-WIR-02 — 🟢 `AtlasBrainScopeFlagAuditor` (detector "cérebro roda cego") nunca invocado
- Pegaria o GAP-06 (master ON + reflection OFF) se ligado. `Brain/AtlasBrainScopeFlagAuditor.php:21`. Fix: chamar `audit()` no `AtlasBrainHealthDoctorCommand`.

## 🔴 H. Domínios shipados + soberania cross-domain (sweep ww6c3iein — widen)
> Verificado ok: 48 superfícies de comando de domínio, ZERO fatal no --help; domínios shipados (Marketing/VentureFoundry) roteiam via AiProviderManager (governança coberta). Mas 2 gaps NOVOS de dinheiro/soberania:

### GAP-FIN-01 — 🟡 spot-exec não honra o kill-switch CANÔNICO (defesa-em-profundidade) · **CORRIGIDO de 🔴 (over-claim meu, verify-the-verifier)**
- **Sweep 5 disse "ordem Binance passa desprotegida" — FALSO, eu exagerei.** Verifiquei na fonte: `SpotExecGate::checkOrder` (SpotExecGate.php:36-74) é robustamente fail-closed — 7 condições AND: `ATLAS_SPOT_EXEC_LIVE_ENABLED` + `ATLAS_SPOT_EXEC_ARMED` + `--confirm` + arquivo STOP ausente + allowlist(BTC/ETH) + `max_order_usd` + `daily_cap_usd`. **Nenhuma ordem passa sem o spot ter sido explicitamente armado.**
- **Gap REAL (menor):** o spot usa switches PRÓPRIOS e NÃO checa o canônico `FinanceDomainCanon::liveTradingBlocked()` (= `atlas.finance.live_trading_allowed===false`, default blocked) que só o poly honra (`PolyExecGate:42`). Logo desligar o canônico NÃO é master-kill universal: o spot continua se seus próprios switches estiverem ON. Cenário: emergency-stop no canônico esperando travar tudo, mas o spot tem switch próprio.
- Fix: adicionar `FinanceDomainCanon::liveTradingBlocked()` ao SpotExecGate (1-linha) → canônico vira master-kill dos 2 paths. **Severity real: 🟡 (consistência de kill-switch), não 🔴.** baixo.

### GAP-SOV-01 — 🟠 soberania: dado private/sensitive (saúde/finanças) pode ir pra IA externa · viola local-first pétreo
- `external_ai_policy=block_private_sensitive` (config:419-436/397-414) p/ domínios 'saude'(sensitive)/'financas'/'blackink'/'atlas'(private) **NÃO é aplicada no funil de chat/IA** quando `payload.privacy` está ausente. `AiGatewayService.php:2638`. Fix: derivar sensitivity no gateway (o funil) antes de `guardPrivacyAllowsAi`. **Viola "sensitive/secret/cyber não saem da máquina".** medio-alto.

### GAP-DOM-01 — 🟠 cd018 quebrou a Pressure Layer: pressure:guard/preland FATAL + `atlas:land` producer SILENCIOSAMENTE morto
- Root único: `EngineeringKernel/PressureLayerGuards.php:65` ctor injeta `AtlasLoopWiredCallerService` (deletada cd018, non-nullable). `atlas:pressure:guard`+`atlas:pressure:preland` fatalizam 100%. **PIOR:** `AtlasLandCommand.php:84` (autoridade de landing) chama `observeLandedSlice()` dentro de `try/catch(Throwable)` → grava ZERO no outcome ledger a CADA land, gate Goal 4 faminto, **sem erro visível** (feature Goal 3.5 silenciosamente morta no caminho load-bearing). Fix: nullable + rescue bind (padrão AppServiceProvider:574). baixo.

### GAP-PERM-01 — 🟡 `AiPermissionEngine` observe-only: workspace-cert + write/danger não bloqueiam
- `AiPermissionEngineSupport.php:347` sempre `allowed:true`; job write/danger fora das allowedRoots ou provider não-codex sem sandbox = só 'observation'. Fix: se é enforcement, `allowed=false` fora das roots em write/danger. medio.

### GAP-DOM-02 — 🟡 GitSubprocess blast no domínio SoftwareCompany (8 serviços stewardship crasham) — mesmo root GAP-01
- `n-company-stewardship:*` fatalizam em `GitSubprocess::run()`. O fix único de GAP-01 (restaurar GitSubprocess) conserta os 24 consumidores (8 domínio + 16 core).
### GAP-MKT-01/02 — 🟢 `marketing:spy` nome engana (lê HTML local, não busca); `keyword-os` exit-0 com feed caído (fail-soft mascara erro real)

## 🟠 I. Enforcement observe-only + integridade da memória (sweep wsvmqb58l)
> VERIFICADO enforce-correto (NÃO são gaps): UnsafeCommandPolicy (exit 126), SpotExecGate (ver FIN-01), PolyExecGate, HermesAcpPermissionGate (default-deny), AtlasOrganismActuationGate (propose-only selado), CanonicalWorktreeWriteGuard, VoxExecutionGate, AuthorizedBugBountyIntakeService, BridgePagePolicyGuard — todos fail-closed e wired.

### GAP-CYBER-01 — 🟠 matriz de recusa ofensiva do domínio Cyber é CÓDIGO MORTO + readiness certifica por string-match
- `Cyber/CyberRuntimeService.php:27-66` (FORBIDDEN_OFFENSIVE_VERBS/refuseOffensive/isForbiddenOffensive) NUNCA é invocado em `driveDefensiveReview`; `CyberReadinessService:116` certifica com `str_contains($source,...)`. Domínio dual-use: a blocklist de capacidade destrutiva é só telemetria; `forbidden_techniques` da RoE nunca enforced. **Cert-theater / falsa segurança.** Fix: rotear ações por `isForbiddenOffensive()` fail-closed no entrypoint + trocar o check da readiness por teste de COMPORTAMENTO. medio.

### GAP-MKT-03 — 🟡 `PolicyComplianceGate` (risco suspensão Google Ads) tem ZERO callers — copy afiliada publica sem ele
- `MarketingDomain/Content/PolicyComplianceGate.php` (termos Rx, endosso celebridade, cura-milagre) só é usado em teste. O pipeline vivo `BridgePageComposerService` roda `BridgePagePolicyGuard` que NÃO cobre esses gatilhos. Domínio afiliado ATIVO. Fix: chamar `PolicyComplianceGate::scan` no composer/VSL, bloquear `suspension_risk`. baixo.

### GAP-PERM-02 — 🟡 `AtlasLoopPermissionLevelEnforcer` ("único chokepoint" documentado) não é chamado por nenhum actuator
- `AutonomousEvolution/Permissions/AtlasLoopPermissionLevelEnforcer.php` promete pin-to-READ com master OFF, mas nenhum merge/write actuator chama `assert()`. Dead-safety-claim (loop morto → exposição limitada, mas a claim mente). Fix: chamar no actuator OU corrigir o docstring.

### GAP-MEM-01 — 🟡 projeção umbrella `CLAUDE.md` é FOTO VELHA — injeta decisão ARQUIVADA 'smoke test' como [decision][global]
- `Atlas/CLAUDE.md:27` (gerador `AtlasProviderProjectionService:723`). Todo provider lê como canônico (confirmado: está no system prompt desta sessão). Fix: rodar o detector stale (`atlas memory projection inspect --stale`) em hook pós-mudança de memória.

### GAP-MEM-02 — 🟡 recall() braço VERBATIM seleciona candidatos CEGO à query (mesmo bug obra17, não corrigido no verbatim)
- `AtlasHybridMemoryRetrievalService:248` — o fix WO-17 (query-aware) foi só no braço registry; o verbatim é só escopo+`orderByDesc(recorded_at)`. Fix: espelhar WO-17 no verbatim (`relevantForContext` lê `context['query']`). (braço registry verificado OK.)

### GAP-MEM-03 — 🟢 projeção provider-safe não filtra entradas 'thin' (title==summary); classificador existe mas não aplicado
- `AtlasProviderProjectionService:729/840`. Consome o orçamento de linhas com duplicatas. Fix: pular thin em `providerSafeEntries`. baixo.

## Residual Elite 2026-07-08 — residuals após Obras 4–9 (docs promovidos 2026-07-09)

> Source material: `storage/app/atlas/elite-compaction/OBRA{4..9}-FINAL-RECEIPT-*.json`.
> Promoção canônica: `atlas-residual-elite-documentation-promotion-2026-07.md`.
> Não reabrir como “não implementado” o que os receipts marcam DONE; estes IDs são o que ficou PARTIAL/OPEN/deferred.

| ID | Sev | Status | O quê | Owner / prova |
|---|---|---|---|---|
| **GAP-RE-MEM-03** | 🟡 | FECHADO | thin `title==summary` filtrado em `providerSafeEntries` | memory-core-runbook · CLOSEOUT-100 |
| **GAP-RE-MEM-06** | 🟡 | FECHADO | govern scan backfill + auto-relation code-path peers (`related`) | memory-core-runbook · CLOSEOUT-100 |
| **GAP-RE-MEM-07** | 🟡 | FECHADO | `MemoryHealthCompositePolicy` pesos recalibrados (honesty/rationale/density ↑) | AHRI + memory quality · CLOSEOUT-100 |
| **GAP-RE-DEV-06** | 🟡 | FECHADO | `AtlasContextRuntime::compose()` no gateway path antes do certify | programming-governance · CLOSEOUT-100 |
| **GAP-RE-DEV-09** | 🟡 | FECHADO | MissionCertification: `evidence_non_trivial` + `claim_aligned_with_work_orders` | mission-foundation · CLOSEOUT-100 |
| **GAP-RE-DEV-10** | 🟡 | FECHADO | repair contract promovido p/ `Programming\Repair`; AiWorker/Dev/Forge consomem | programming-governance · CLOSEOUT-100 |
| **GAP-RE-FORGE-04** | 🟡 | FECHADO | Forge HTTP entry points gravam `route_decision` via recorder/DualCore | programming-forge-flow · CLOSEOUT-100 |
| **GAP-RE-FORGE-06** | 🟡 | FECHADO | `engineering_kernel.forge_execution_gate_enforcing` declarado (default OFF) | programming-forge-flow · CLOSEOUT-100 |
| **GAP-RE-AUT-08..10** | 🟢 | DEFERRED | ACDE clustered corpses (~630) — keep-list safe, OUT do closeout | evolution-loop-acde-runtime · OBRA6/7 |
| **GAP-RE-RAG-07** | 🟡 | FECHADO | `indexStaleFraction()` + probe KIND_AGRN_REINDEX | ASEF/AHRI · CLOSEOUT-100 |
| **GAP-RE-RAG-08** | 🟡 | FECHADO | freshness_tick linker bounded no AURG sync | AURG · CLOSEOUT-100 |
| **GAP-RE-RAG-09** | 🟡 | FECHADO | deep memory adapter path em AHRI `report()` | AHRI · CLOSEOUT-100 |
| **GAP-RE-RAG-10** | 🟡 | FECHADO | AKIF `normalize()` → packet registry + `claims.indexed` | AKIF · CLOSEOUT-100 |
| **GAP-RE-OB-03** | 🟢 | FECHADO | MCP tier tools `_brief` / `_timeline` / `_get_full` físicos | open-brain-mcp · CLOSEOUT-100 |
| **GAP-RE-OB-05** | 🟢 | OUT | ATER/ACRS injection em app/mobile — fora do escopo closeout | open-brain-context-injection |
| **GAP-RE-SC-05/06** | 🟡 | FECHADO | CodexSection `setMother`/`__call` + Schema import + teste invocável | self-construction-os · CLOSEOUT-100 |
| **GAP-RE-DECIDE-01** | 🟡 | PARTIAL | checklist escrito; flags OFF até live_outcomes ≥ critério | decide-meta-learning · DECIDE-01-FLIP-CHECKLIST |
| **GAP-RE-GOV-MUSCLE** | 🟡 | FECHADO | provider coverage 138/138 governed, bypass 0 | constitutional-kernel · CLOSEOUT-100 |
| **GAP-RE-TEOS-ACOS** | 🟡 | PARTIAL | mint soak ran; pipeline_score~8.68; green receipts 0/30 | ACOS + TEOS · CLOSEOUT-100 |
| **GAP-RE-MCP-02** | 🟢 | PARTIAL | CLI SoT 67 tools; Cursor native transport = restart operador | open-brain-mcp · CLOSEOUT-100 |
| **GAP-RE-HERMES** | 🟢 | INVENTORY | Hermes mesh/kanban full OS fora de escopo (OUT) | hermes-executive-runtime · OBRA9 |
| **GAP-RE-MISSION-HTTP** | 🟢 | PARTIAL | checklist escrito; `kernel_http_integration` permanece OFF | mission-foundation · MISSION-HTTP-FLIP-CHECKLIST |

## Z. Refutados / falso-alarme (não re-abrir)
- Maestro Exceptions (UnknownSchemaVersion/SchemaDowngradeRefused/InvalidProvider): `use` aponta pra path deletado MAS as classes foram INLINADAS nos survivors → maestro:schema/bid OK.
- `AtlasEngineeringStringListNormalizer` em ProbeRunner: só import pendurado (0 uso no corpo) → não autoloaded, não fataliza.
- `User` model class (auth.php/seeder) em auth.php/seeder: nunca existiu no git, não é regressão do cd018c6b3f (`::class` string compile-time).
- Binds rescue()-wrapped (AppServiceProvider 574/592/594/617/618/629/636/638): resolvem classe deletada MAS degradam pra null, NÃO fatalizam.
- `EmitsAcdeLoopDeprecation` em EliteCompactionWavePruner:386: dentro de template string (codegen), sem trait real.

---
*Sweeps: census wrxgcbok3 · broken-on-main wdtpxya7x (23 findings, raiz cd018c6b3f) · honesty w5ejv9oq9 (9 findings; curador falhou, hunters recuperados do journal). Próximo eixo: cobertura de teste nos caminhos vivos + segurança/secrets.*
