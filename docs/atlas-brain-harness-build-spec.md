# Atlas Brain Harness — Build Spec (READY-but-NOT-RUN)

> Gêmeo-cognição do harness de executor. O operador cola **UMA** linha e sai; o cérebro
> (provedor frontier, default Codex) fica na função por uma janela bounded (~5h) achando
> evoluções/patamares, documentando e criando tasks — **sem ser chamado a cada passo e sem
> conseguir fingir que terminou.** Fica DESLIGADO até o operador ter token Codex + ligar a flag.
>
> Origem: workflow de design 6-agentes aterrado em `AutonomousEvolution`/ACDE + vet adversarial.
> Veredito: **APROVADO COM OS FIXES OBRIGATÓRIOS** (sem o FIX 1, REPROVADO — vira fazenda de proxy).

## 1. Princípio pétreo (o que resolve o fake-finish)
**O for-loop vive no CÓDIGO (PHP), NUNCA no modelo.** O modelo só responde à **fase corrente**;
ele **não decide** se continua — o código decide. Ninguém pergunta "terminou?"; o código entrega a
próxima fase. Isso é mais forte que o "NEVER STOP" de prompt (o Stop-hook que falhou — só cutucava).
STOP é **externo** (relógio/custo/kill/dry-provado), nunca escolha do modelo.

## 2. Interface (um comando, cola e sai)
```
php artisan atlas:brain:evolve --scope=loop --max-runtime-minutes=300 --max-cycles=0 --provider= --dry-run --json
```
- `--scope` loop|cortex|maestro → mapeia pra `scopeRoot`.
- Gated por `AtlasBrainMasterSwitch` (`ATLAS_BRAIN_MASTER_ENABLED`, **default FALSE**, fail-closed) +
  preflight do provider brain. Sem flag/token → emite `brain_provider_unavailable`, **zero ciclos**.

## 3. Máquina de 8 fases (PHP possui o loop; cada fase avança só com artefato máquina-checado)
- **S0 SELECT-TARGET** — `AtlasLoopScopeComprehensionModelBuilder->build(repoRoot, scopeRoot)` escolhe um bloco FRESCO (exclui o done-set da janela).
- **S1 COMPREHEND** — grounding ratio **LOCAL 1.0** (símbolo inventado = FALHA → re-prompt).
- **S2 ANALYZE** — findings citando path real.
- **S3 UNDERSTAND** — 4-tupla objective/how/why/value via `AtlasLoopVaguenessPreScreener` (rejeita vago).
- **S4 ORIGINATE+CLASSIFY** — `AtlasLoopComprehensionOriginator->originate()` (writer **injetado**, brain-routed) propõe a evolução; rótulo do modelo é **advisory**; `AtlasBrainEvolutionLevelClassifier` recomputa **determinístico**: `REJECTED_PROXY` se termo-proxy; `PATAMAR` se cruza >1 fronteira + magnitude alta ou docStatedGap; `EVOLUCAO` se single-component.
- **S5 DOCUMENT** — seção datada em `docs/loop-evolution-journal/<scope>.md`; re-roda o grounding gate nos símbolos escritos.
- **S6 SEED-TASKS** — `AtlasLoopOriginationPipeline->produce()` → `AtlasBrainTaskSpecTranslator` emite o spec EXATO do `seed-gov-lanes` (allowed_files/acceptance vindos das **obligations**, nunca inventados); roda `isForbiddenSelfTarget` e descarta doomed; seeda no disco **`atlas_serving`** via `AtlasTaskServingStack`.
- **S7 COMMIT-CYCLE** — `AtlasBrainCycleProgressVerdict`: o ciclo só **CONTA** se doc-no-disco **+** packet `enqueued` **SEM** proxy/vago/não-runnable **+** citações grounded; senão **zero progresso** + consome `no-progress budget`. Cycle receipt no ledger jsonl; alimenta `priorAttempts` do próximo bloco.
- **STOP (dono do PHP):** `time_budget` | `cost_budget` | `max_cycles` | `kill_switch` | `scope_dry_proven` (via `AtlasBrainScopeDryProbe`: dry só com M consecutivos + zero gaps novos; **fila cheia nunca é dry**). Per-cycle timeout 900s → ciclo NULL; heartbeat via progress-callback. **PROIBIDO:** bom-ponto / contexto-cheio / continuo-depois / pergunta-e-espera / dry-auto-declarado.

## 4. Os 8 FIXES OBRIGATÓRIOS (do vet adversarial)
1. **FURO-MÃE / FIX 1 (sem ele REPROVADO):** `blind_orphan_wiring_proxy`/`vague_objective`/`acceptance_not_runnable` são **ADVISORY** no inspector global → o contador é gameável por proxy (reward-hack Sakana/DGM/Cursor). `AtlasBrainCycleProgressVerdict` torna esses **FATAIS-PARA-PROGRESSO** no contador do cérebro (advisory global, binding brain-side). Proxy = zero ciclo.
2. **Writer injetado:** resolver provider via `--provider ?? config('atlas.provider_defaults.brain_default')='codex_cli'` roteado pelo `AtlasForgeProviderInvocationDriverRouter` (nunca pinar). O `liveWriter` atual lê `atlas.loop.default_provider` — errado pro brain. Preflight `isConfigured` senão `brain_provider_unavailable`.
3. **Grounding 1.0 LOCAL** na janela do cérebro (citação inventada = FALHA + re-prompt). Global fica 0.5.
4. **Pré-seed `isForbiddenSelfTarget`** em cada `allowed_files`; descarta doomed antes de seedar. Adicionar os 3 arquivos novos core a `FORBIDDEN_SELF_TARGETS` (`AtlasLoopHarnessGuard`).
5. **Per-run cost cap** (`stop_reason=cost_budget_reached`) + per-cycle timeout 900s (think travado → ciclo NULL); heartbeat de dentro do writer.
6. **`scope_dry_proven` calibrado** (M=3 consecutivos + zero gaps grounded novos + probe diz dry, não unknown_blocked). Fila cheia ≠ dry.
7. **Patamar como MÉTRICA no digest** (flag `no_level_jump_surfaced_this_window` + priorAttempts), **não** enforce (forçar patamar inexistente = fake).
8. **Multi-file honesto:** readiness declara que packets multi-file podem não drenar pelo swarm single-file; digest reporta `blocks_seeded` vs `blocks_drained`.

## 5. REUSO (≈90% já existe — não reinventar)
- Skeleton de janela bounded (lock/lease/heartbeat/ledger/budget/crash-resume): `Campaign/AtlasLoopCampaignSupervisor.php` + `SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php`.
- Substrate grounded: `Discovery/AtlasLoopScopeComprehensionModelBuilder.php` → `AtlasLoopScopeComprehensionModel`.
- Originador writer≠judge: `AtlasLoopComprehensionOriginator.php` (writer injetável).
- Gate anti-alucinação: `Verify/AtlasLoopComprehensionGroundingGate.php`.
- Criar tasks: `AtlasTaskSeedGovLanesCommand.php` → `AtlasTaskServingStack` (disco dedicado).
- Gate anti-proxy/pétreo: `SelfConstruction/AtlasTaskPacketQualityInspector.php`.
- Math patamar-vs-evolução: `Discovery/AtlasLoopHeavyWorkSelector.php` + `AtlasLoopAmbitionLeapProposer.php` + `AtlasLoopFrontierGapModel.php`.
- Doc-artefato/digest: `AtlasLoopAutoArchitectureProposalService.php` + `AtlasLoopLeapReceiptLedger.php` + `AtlasLoopMorningDigestService.php`.
- Anti-vacuidade: `Discovery/AtlasLoopVaguenessPreScreener.php`. Fecha ask-human: `AtlasLoopAbstainAndAsk.php` (`proceedOnGroundedNovelty=true` LOCAL).
- Provider/ledger: `config('atlas.provider_defaults.brain_default')`, `AiProviderManager::get()`, `Support/AppendOnlyJsonlStore.php`.

## 6. Build plan (9 passos)
1. **`AtlasBrainMasterSwitch`** (clone de `AtlasLoopMasterSwitch`, KEY `ATLAS_BRAIN_MASTER_ENABLED`, default FALSE, parse-direto-.env). Adicionar ele + harness + comando a `FORBIDDEN_SELF_TARGETS`. Key em `config/atlas.php` sob bloco `brain`. **[LEAD — pétreo]**
2. **`Brain/AtlasBrainEvolutionLevelClassifier`** — `classify()` puro compondo HeavyWorkSelector+AmbitionLeapProposer+FrontierGapModel. **[WORKER]**
3. **`Brain/AtlasBrainTaskSpecTranslator`** — OriginationPipeline.produce() → spec exato do seed (allowed_files das obligations, nunca inventado; id=sha(objective+snapshot)). **[WORKER]**
4. **`Brain/AtlasBrainEvolutionDocAuthor`** — anexa seção datada no journal + re-roda grounding gate. **[WORKER]**
5. **`Brain/AtlasBrainEvolutionHarness`** — o condutor: for-loop, máquina S0..S7, validate-per-state + re-prompt-on-invalid, deadline/stop-file/no-progress-budget, callables injetados (testável SEM provider). Reusa lock/lease/heartbeat/ledger do CampaignSupervisor. **[LEAD/ENGENHEIRO — núcleo de governança interligado]**
6. **`AtlasBrainEvolveCommand`** — entry fino; checa master switch + preflight provider; instancia o harness. **[LEAD — pétreo]**
7. **`Brain/AtlasBrainScopeDryProbe`** — agrega M-consecutivos-dry; nunca dry sem confirmação. **[WORKER]**
8. **Wiring de provider** — `--provider ?? brain_default=codex_cli` via router (nunca pinar); execução dos packets fica Hermes-native. **[LEAD — config/wiring]**
9. **Testes frozen** (provam a propriedade anti-fake mecanicamente, SEM provider real): (a) conductor com state-callables FAKES avança só em artefato válido / re-prompta inválido / para só em deadline/dry/kill / done-set exclui repetição; (b) integração fake-block → doc real + seed-gov-lanes --dry-run com counts; (c) classifier rejeita proxy-delta; (d) master-switch OFF ⇒ no-op byte-idêntico. **[WORKER, por classe]**

## 7. author≠judge + pétreo + gating
- O cérebro autora e escreve **só** em `docs/` e na fila serving — **nunca** app/commit/merge. Gates determinísticos julgam. O swarm Hermes-native (separado) implementa, com commit-verification re-rodando testes reais.
- Os 3 arquivos core novos (master switch, harness, comando) entram em `FORBIDDEN_SELF_TARGETS` (o cérebro nunca se religa nem edita o próprio gate).
- **GATED ON:** token Codex (sem ele zero ciclos) · flip de `ATLAS_BRAIN_MASTER_ENABLED` · calibração de M + cost-cap numa soak · validação do handoff multi-file pro swarm · riqueza do ScopeComprehensionModel em cortex/maestro.

## 8. Divisão de trabalho do build
- **WORKER packets (risk medium):** passos 2, 3, 4, 7 + testes frozen (passo 9) — classes novas isoladas compondo organs existentes, cada uma com seu teste.
- **LEAD/ENGENHEIRO (risk high — não-worker):** passos 1, 5, 6, 8 — master switch + condutor + comando + wiring de provider são organs de governança interligados (pétreo), multi-file, que o grind single-file não constrói bem (ver `loop-cannot-deliver-on-own-mature-code`). Construir como obra coordenada.
