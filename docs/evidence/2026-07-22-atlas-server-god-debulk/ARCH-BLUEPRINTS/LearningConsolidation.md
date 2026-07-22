# ARCH BLUEPRINT — LearningConsolidation

> status: draft-v2 (SOBREVIVEU ao verify adversarial 2026-07-22; 3 emendas aplicadas — evidências verificadas pelo comandante)
> EMENDA QUASE-FATAL (path-literals que a prova por namespace NÃO pega): config/atlas.php:112 tem 'app/Services/Ai/AcosMax' como VALOR VIVO (roots da lane aaeos_acos) — pós-M3 fail-open silencioso; scanner L6630 + AtlasRuntimeLanguageBoundaryReportService:36 têm 'Services/Ai/Cognitive' como root de scan — pós-M2 perda de cobertura silenciosa; 3 ap-checks (AP-168/169/170, scanner L6165/6255/6359) exigem patch no MESMO commit do M2; AtlasAaeosAcosLaneScope:23 + teste congelam o path do AcosMax.
> REGRA NOVA (vale p/ M2 e M3): todo movimento inclui PATH-LITERAL SWEEP (rg "Services/Ai/(Cognitive|Learning|AcosMax)" como STRING em app config tests) e a prova de completude passa a medir AMBAS as formas (namespace E path). Emendas menores: Elev20sDeadSeriesRegistryTest usa FQCN-string (reescrever, não re-apontar); consumidor extra DevOutcomeMemoryService na sub-onda M3-A; docblock da migration ai_learning_signals citado por honestidade.
> data: 2026-07-22
> obra: GOD Debulk / cluster APRENDIZADO+COGNIÇÃO
> insumos: CONSOLIDATION-MAP (AcosMax FUSE-parcial · Cognitive KEEP+RENAME · Learning FUSE)

## 1. Contexto provado

| Bloco | LOC/files | Papel provado |
|---|---|---|
| Learning/ | 1.067/1 | Loop coleta 11 fontes → `AiLearningSignal` e **escreve `AiLearningProposal` DIRETO** via `updateOrCreate` (L822 VERIFICADO), hash sha256 ad-hoc, sem CaptureQualityGate; `review()` (L248-280) duplica lifecycle mutando o model direto |
| Compounding/ | 7.321/40 | Dono canônico: `propose()` (ALLOWED_KINDS, evidence_refs, CompoundingHash, QualityGate) + `approve/reject/markApplied` com guards + Applier governado; consumido por `atlas:ai:apply-learning` e applier autônomo |
| Cognitive/ | 8.848/65 | Ciência da aprendizagem (SRL, ProductiveFailure, WorkedExamples, Dreyfus, Harness…), 11 subpastas, 138 consumidores, 11 commands; Cognition→Cognitive = só 2 imports pontuais VERIFICADO (SurpriseGate, ImmuneCalibration) |
| AcosMax/ | 14.223/51 | Grab-bag, 0 providers; consumo em commands atlas:acos/flywheel, Watchdog checks, UniversalGatesEvaluator; 117 test files |

**A duplicação (mov. 1)**: dois writers do MESMO model com regras divergentes — criação (`updateOrCreate` ad-hoc vs `propose()` governado), decisão (`review()` fail-soft por fora do caminho auditado vs `approve/reject` com guards), aplicação (inexistente vs Applier). Callers do loop: 4 verificados (Command, ControlPlane L186, RuntimeReadiness probe L675-710, 13 testes de characterization JÁ existentes). Serialização de FQCN em config/migrations: zero (provado; só comentário em config/atlas.php L317). Precedente de shim: CognitiveMemory class_alias.

## 2. MOVIMENTO 1 — FUSE Learning ⇒ Compounding

| Peça | Sufixo | Responsabilidade | Teto |
|---|---|---|---|
| `Compounding\AtlasLearningSignalScanner` | Scanner | corpo de coleta movido (collect/list/controlPlaneSummary + 6 coletores + classifyRisk); único writer de `AiLearningSignal`; NÃO escreve proposal — delega | ≤950 |
| `AtlasLearningProposalService::proposeFromSignal()` | Service (existente) | corpo de `maybeGenerateProposal` movido VERBATIM (mesmo hash, mesmo updateOrCreate — zero mudança de comportamento); vira o ÚNICO writer de proposal fora do lifecycle | +120 |
| `AtlasLearningProposalService::reviewById()` | Service (existente) | adapter fino → `approve/reject` canônicos, traduzindo p/ o array fail-soft atual (output do CLI byte-idêntico); o `review()` do loop MORRE | +60 |

Nota de honestidade: unificar hash/quality-gate mudaria dedup/payload ⇒ FORA desta obra (follow-up próprio). Ganho pétreo: um dono de arquivo, um lifecycle de decisão.
Re-apontamento: Command (collect/list→Scanner, review→reviewById), ControlPlane (ctor→Scanner), RuntimeReadiness (probe→Scanner, chaves inalteradas), teste movido p/ Compounding. Shim class_alias datado no lugar do loop (≤1 ciclo).

## 3. MOVIMENTO 2 — RENAME Cognitive ⇒ Learning

Pré-condição: mov. 1 esvazia o namespace Learning. Rename mecânico em 1 commit atômico: `git mv Cognitive Learning` (subnamespaces preservados: SRL/ProductiveFailure/PredictiveFailure/WorkedExample/PersonalWorkedExample/Dreyfus/Failure/Pattern/ClaimCoherence/Staleness/Harness) + sed de import nos 138 consumidores (incl. AppServiceProvider L107, HarnessStatusController, 4 Kernel Gates, 2 Cognition, Applier, AiPromptBuilder, 45 tests). Nomes de CLASSE não mudam. **11 commands com assinatura congelada** (atlas:srl · atlas:predict · atlas:harness · atlas:dreyfus · atlas:failure ×3 · atlas:pattern · atlas:productive-failure · atlas:worked-example · atlas:cognition:predictive-code-intelligence-gate). Compat: 1 arquivo de alias LAZY (spl_autoload prefix-match Cognitive→Learning), datado ≤1 ciclo.

## 4. MOVIMENTO 3 — Dissolução do AcosMax (51 files → 3 destinos)

- **A — Envelopes ⇒ `Aemor\Envelope\`** (6 files: OutcomeEnvelope*, adapters). Prova: `AtlasEngineeringOutcomeRecorder:245` e `AtlasCompoundingOutcomeEvaluator:46` chamam `OutcomeEnvelopeBridge->project(...)`; dono do domínio outcome é Aemor.
- **B — índice/embedding/RAGX ⇒ `Context\Retrieval\`** (12 files: AsefChunkIndex, *EmbeddingCoverage, RagxChainMechanism, JinaV3DualRead, GatedCorpusCandidateMiner, RecallGapAggregator…). Prova: imports de Context/Semantic embedding foundation.
- **C — programa Acos ⇒ `Cognition\AcosProgram\`** (~33 files: Cockpit, MeasureSeries, ObraRetro, Lote2 (9 callers), Windows, Flywheel, Promotions, portfolio/bets…). Prova: Cognition já hospeda AtlasAcos*Service; Watchdog checks e commands consomem. ⚠ débito nomeado (NÃO consertar nesta obra): Cockpit importa 2 Commands (back-reference serviço→command) — vai para a wave EXECUTE.
- Cross-cutting: `AtlasUniversalGatesEvaluator` consome os 3 grupos — só troca de `use` nas 3 sub-ondas, nunca no mesmo PR que mudança de lógica. Shim lazy AcosMax datado; 117 test files re-apontados por sub-onda.

## 5. Sequenciamento pétreo (1→2→3)
1→2: o rename ocupa o namespace que o loop habita (senão nasce bifronte — a doença Cognition×Cognitive de novo); e Harness já consome o Compounding (VERIFICADO) — o rename chega a um dono único.
2→3: churn disjunto (mov. 3 edita Watchdog/commands; fazer antes força rebase duplo no god-file). Mov. 3 = maior blast radius (117 tests) → por último, em 3 sub-ondas revertíveis.

## 6. Teste do patamar por movimento
| Mov | Congela antes | Prova depois |
|---|---|---|
| 1 | 13 testes verdes + snapshot --json de collect/list/review + bloco learning do controlPlaneSummary | mesmos cenários verdes no novo home; snapshots byte-idênticos; rg writers de proposal fora de Compounding = 0 |
| 2 | php artisan list nas 11 assinaturas + suíte completa | assinaturas idênticas; rg `Ai\Cognitive\` = 0 fora do alias; autoload limpo |
| 3 | suíte dos 117 tests + snapshots dos commands acos/flywheel/promotions | snapshots byte-idênticos por sub-onda; rg AcosMax = 0 ao fim; Watchdog verde |

## 7. Ordem de migração
M1a freeze → M1b Scanner+proposeFromSignal+reviewById (corpos verbatim) + 4 callers + shim → M2a freeze → M2b git mv + sed 138 + alias lazy (1 commit atômico) → M3-A envelopes → M3-B retrieval → M3-C programa Acos + deletar pasta → ciclo seguinte: remover os 3 shims.

## 8. Riscos
| Risco | Mitigação |
|---|---|
| "Melhorar" proposeFromSignal na mudança (hash/gate) muda dedup | regra pétrea: corpo verbatim; unificação = follow-up |
| reviewById divergir do array fail-soft do CLI | snapshot congela cada branch de erro antes |
| FQCN antigo serializado em fila viva | alias lazy cobre; drenar filas antes do deploy M2/M3; zero em config/migrations provado |
| god-file tocado por M2 e 3 sub-ondas | só `use`; ordem 2→3 evita rebase duplo |
| churn de 117 tests mascarar regressão | sub-ondas com suíte verde entre cada; teste move junto com a classe |
| probe memory_learning_loop apontar classe morta | re-apontado no MESMO commit de M1b (fail-closed acusa se esquecer) |
