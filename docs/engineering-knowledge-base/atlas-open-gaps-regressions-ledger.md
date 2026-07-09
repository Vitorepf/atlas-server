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
---
# Atlas — Ledger de Gaps, Regressões e Falhas Abertas

> **Propósito:** caçar+documentar com PROVA todo gap/erro/falha antes da hora de corrigir, pra implementação sair lisa.
> Status: **ABERTO** · **EM-FIX** · **FECHADO**(commit). Fonte: sweeps do gap-hunt (IDs citados). Companion: `atlas-terminal-first-focus.md`.

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
- **GAP-COCKPIT-01** 🟠 landings do autônomo não emitem item de review (o produtor; publisher `AtlasLoopOperatorReviewMobilePublisher` existe, 0 callers, aponta pro morto → repontar). *Maior destrave.*
- **GAP-09** 🟠 `AtlasExternalBrainToTaskFabricBridge` — espinha proposta-do-cérebro→task-pronta 0 callers.
- **GAP-10** 🟠 `AtlasExternalBrainOutcomeLearningToMaestroBridge` — fecha o Learning Loop (outcome→roteamento) 0 callers.
- **GAP-11** 🟠 `AtlasExternalBrainAutonomousSpine` — snapshot único de 6 órgãos + next_action, 0 callers.
- **GAP-12..16** 🟡 ProofFirstTaskEmitter, LocalClientFailureRecoveryTaskEmitter, MaestroHealthGateSeedCreditBridge, AutonomyRegressionTaskEmitter, CortexCapabilityGapMemoryBridge — todos 0 callers, vivo-viáveis.
- **GAP-COCKPIT-02/03/04** approve/reject nos itens do músculo (`AtlasInboxService.php:614`); agregação cross-surface; `atlas:review:deep` recorder→gerador.

## 🟡 E. Ergonomia CLI (situational awareness)
- **GAP-CLI-01** 🟡 sem cockpit único do motor vivo (brain+fila+landings+saúde num comando; read-models já existem).
- **GAP-CLI-02** 🟡 `atlas status`/dashboard cego pro motor autônomo (só AiJob/traces/chat).
- **GAP-CLI-03** 🟢 `TerminalMarkdownRenderer` usado por 2 de 874 comandos.

## ⏳ EM-FIX (outra sessão)
- **REG-01** proof-family namespace (TerminalLoopHealthDigest/OperationalProof) fatal — `task_52704584`.

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

## Z. Refutados / falso-alarme (não re-abrir)
- Maestro Exceptions (UnknownSchemaVersion/SchemaDowngradeRefused/InvalidProvider): `use` aponta pra path deletado MAS as classes foram INLINADAS nos survivors → maestro:schema/bid OK.
- `AtlasEngineeringStringListNormalizer` em ProbeRunner: só import pendurado (0 uso no corpo) → não autoloaded, não fataliza.
- `App\Models\User` em auth.php/seeder: nunca existiu no git, não é regressão do cd018c6b3f (`::class` string compile-time).
- Binds rescue()-wrapped (AppServiceProvider 574/592/594/617/618/629/636/638): resolvem classe deletada MAS degradam pra null, NÃO fatalizam.
- `EmitsAcdeLoopDeprecation` em EliteCompactionWavePruner:386: dentro de template string (codegen), sem trait real.

---
*Sweeps: census wrxgcbok3 · broken-on-main wdtpxya7x (23 findings, raiz cd018c6b3f) · honesty w5ejv9oq9 (9 findings; curador falhou, hunters recuperados do journal). Próximo eixo: cobertura de teste nos caminhos vivos + segurança/secrets.*
