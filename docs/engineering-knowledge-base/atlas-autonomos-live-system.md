---
id: atlas-autonomos-live-system
type: engineering_knowledge
title: Atlas Autônomos — O Sistema VIVO (cérebro + músculo) e o keep-list AtlasLoop*
status: active
category: autonomous-evolution
priority: 100
doc_schema: atlas_canonical_module_doc.v1
graph_layer: system
summary: "SISTEMA VIVO de auto-evolução do Atlas (NÃO o loop/ACDE, que é MVP morto). É o AUTÔNOMOS: cérebro externo cria tasks (atlas:brain:next origina+projeta spec author≠judge → atlas:brain:seed gate-and-enqueue, seed-gate refusa ~50%) e músculo externo implementa (atlas:task next → provider → commit ESCOPADO na main via SelfConstruction/AtlasTaskScopedCommitter, git add -- <arquivos> nunca add -A nunca merge). Já produziu 4.841 landings. Músculo mora em app/Services/Ai/SelfConstruction/, cérebro em app/Services/Ai/AutonomousEvolution/Brain/. O AutonomousEvolution/ raiz (~600 AtlasLoop*) é ACDE-MORTO — EXCETO 26 classes AtlasLoop* reusadas pelo vivo, listadas aqui (keep-list). Corretivo de docs/loop-canonical-definition.md; corrige atlas-evolution-loop-*.md que descreviam o morto como vivo."
tags:
  - atlas-ai
  - autonomos
  - self-construction
  - autonomous-evolution
  - brain
  - task-serving
  - live-system
  - keep-list
capabilities:
  - external_brain_task_origination
  - external_muscle_task_implementation
  - scoped_commit_on_main
  - atlasloop_live_keeplist
decisions:
  - O "Loop" (AutonomousEvolution/ACDE) é MVP morto; o sistema vivo é o Autônomos (cérebro atlas:brain + músculo atlas:task). Não mirar o master switch do loop como meta.
  - Todos os agentes (inclusive o autônomo) trabalham na MESMA branch (main local) e commitam SÓ os próprios arquivos escopados (git add -- <files>). NÃO existe branch-and-merge no caminho comum; o único git merge --no-ff é obra grande.
  - 26 classes com prefixo AtlasLoop* são LEGADO ACDE porém REUSADAS pelo vivo — não aposentar pelo prefixo. rg --no-ignore > 0 sempre; deletar viola o piso.
maintenance:
  - Atualizar quando o keep-list mudar (nova classe AtlasLoop* passa a ser consumida por SelfConstruction/Brain/comando vivo, ou uma some) ou quando os comandos vivos brain/task mudarem.
related_paths:
  - app/Services/Ai/SelfConstruction/AtlasTaskScopedCommitter.php
  - app/Services/Ai/AutonomousEvolution/Brain
  - app/Console/Commands/AtlasBrainNextCommand.php
  - app/Console/Commands/AtlasBrainSeedCommand.php
  - app/Console/Commands/AtlasTaskCommand.php
---
# Atlas Autônomos — o sistema VIVO (cérebro + músculo)

> **⚰️ Correção pétrea:** o "Loop" (`app/Services/Ai/AutonomousEvolution/` raiz, ACDE) **MORREU** — foi
> o MVP fracassado. O sistema **VIVO** de auto-evolução do Atlas é o **AUTÔNOMOS** descrito aqui.
> Se um doc antigo (`atlas-evolution-loop-*.md`, `atlas-autonomous-evolution-loop.md`, etc.) apresenta o
> loop como motor vivo 24/7, ele está desatualizado — este doc + `loop-canonical-definition.md` governam.

## Resumo

O Autônomos é a arquitetura **cérebro externo + músculo externo** que evolui o Atlas de forma autônoma e
**já entregou 4.841 landings** na main. Não é aspiração: roda hoje.

## Papel no Atlas

Originar trabalho de evolução de alto valor (cérebro) e implementá-lo com prova (músculo), commitando na
main local de forma escopada, sem depender do operador no caminho normal.

## Arquitetura viva (as duas metades)

### 1. CÉREBRO — cria as tasks (`app/Services/Ai/AutonomousEvolution/Brain/`, ~96 classes `AtlasBrain*`)
- `atlas:brain:next` (`AtlasBrainNextCommand`) — **origina + projeta a spec** da evolução; author≠judge
  (quem escreve não julga); escreve só em `docs/` + ledger, nunca código.
- `atlas:brain:seed` (`AtlasBrainSeedCommand`) — **gate-and-enqueue**: valida e enfileira a task no disco
  de serving dedicado. O **seed-gate refusa ~50%** = filtro de qualidade. Scope `autonomous` = master ON.
- Meta-originadores externos (ex.: Codex-como-cérebro) também originam tasks.

### 2. MÚSCULO — implementa as tasks (`app/Services/Ai/SelfConstruction/`)
- `atlas:task next` (`AtlasTaskCommand`) — worker puxa a próxima task claimable.
- O **provider** (Codex/Hermes/…) implementa dentro do escopo da task.
- **Commit ESCOPADO na main** via `SelfConstruction/AtlasTaskScopedCommitter`:
  `git add -- <só os arquivos da task>` (**NUNCA** `git add -A`) + `git commit` na branch atual (=main).
  **NÃO é merge.** O único `git merge --no-ff` real do autônomo é o `AtlasLoopObraAutoMergeService` (só obra grande).
- Prova: **4.841 commits `atlas-task` landados na main**.

## Onde o vivo vs. o morto moram

| Camada | Caminho | Estado |
|---|---|---|
| Músculo vivo | `app/Services/Ai/SelfConstruction/**` | **VIVO** |
| Cérebro vivo | `app/Services/Ai/AutonomousEvolution/Brain/**` (`AtlasBrain*`) | **VIVO** |
| Comandos vivos | `atlas:brain:*`, `atlas:task:*` | **VIVO** |
| Loop ACDE | `app/Services/Ai/AutonomousEvolution/` **raiz** (~600 `AtlasLoop*`/`AtlasEvolution*`) | **MORTO** — exceto o keep-list abaixo |
| Superfície de comando ACDE | 335 comandos `atlas:loop:*` | **MORTO** — os comandos VIVOS são `atlas:brain:*` / `atlas:task:*` |

## Keep-list: 26 classes `AtlasLoop*` VIVAS (NÃO aposentar pelo prefixo)

**Regra:** o prefixo `AtlasLoop*` grita "loop morto", mas estas 26 classes são **reusadas pelo caminho vivo**
(SelfConstruction/, Brain/, ou um comando `atlas:brain`/`atlas:task`). Deletá-las quebra o autônomo.
`rg --no-ignore -w <Classe>` sempre retorna consumidor vivo → o piso "rg=0 antes de deletar" já as protege;
esta lista é o sinal antecipado pra a IA nem começar. (Verificado por varredura de referência direta; ver
memória `loop-morto-autonomos-vivo`.)

| Classe | Arquivo | Consumidor vivo (exemplo) |
|---|---|---|
| AtlasLoopHarnessGuard | AutonomousEvolution/AtlasLoopHarnessGuard.php | sandbox floor — Brain/*, cmds brain/task, SelfConstruction/AtlasTaskScopedCommitter |
| AtlasLoopMasterSwitch | AutonomousEvolution/AtlasLoopMasterSwitch.php | Brain/AtlasBrainMasterSwitch; SelfConstruction/AtlasTaskServingSwitch |
| AtlasLoopScopeComprehensionModel | AutonomousEvolution/Discovery/AtlasLoopScopeComprehensionModel.php | Brain/*; SelfConstruction/AtlasTaskBrainReplenisher |
| AtlasLoopScopeComprehensionModelBuilder | AutonomousEvolution/Discovery/AtlasLoopScopeComprehensionModelBuilder.php | cmd AtlasBrainNextCommand; AtlasTaskBrainReplenisher |
| AtlasLoopScopeComprehensionQuery | AutonomousEvolution/Discovery/AtlasLoopScopeComprehensionQuery.php | cmd AtlasTaskReplenishCommand; AtlasTaskBrainReplenisher |
| AtlasLoopRefillerPayloadNormalizer | AutonomousEvolution/Discovery/Supply/AtlasLoopRefillerPayloadNormalizer.php | SelfConstruction/TaskQueue/* |
| AtlasLoopCortexRoleTokenSemanticDisambiguator | AutonomousEvolution/AtlasLoopCortexRoleTokenSemanticDisambiguator.php | SelfConstruction/AtlasTaskBrainReplenisher |
| AtlasLoopComprehensionCadenceService | AutonomousEvolution/Discovery/AtlasLoopComprehensionCadenceService.php | SelfConstruction/AtlasTaskServingService |
| AtlasLoopProposalPromotionGate | AutonomousEvolution/AtlasLoopProposalPromotionGate.php | SelfConstruction/AtlasSelfConstructionPromotionExecutorService |
| AtlasLoopSiblingTestResolver | AutonomousEvolution/Discovery/AtlasLoopSiblingTestResolver.php | SelfConstruction/AtlasTaskServingService |
| AtlasLoopGiveBackToReplenisherFeedback | AutonomousEvolution/Feedback/AtlasLoopGiveBackToReplenisherFeedback.php | SelfConstruction/AtlasTaskBrainReplenisher |
| AtlasLoopLossObserverService | AutonomousEvolution/AtlasLoopLossObserverService.php | SelfConstruction/AtlasSelfConstructionToolGapBridgeService |
| AtlasLoopComprehensionOriginator | AutonomousEvolution/AtlasLoopComprehensionOriginator.php | SelfConstruction/Quaternity/DialogueToPackets/CortexGroundingSnapshot |
| AtlasLoopProjectionOutcomeLedger | AutonomousEvolution/AtlasLoopProjectionOutcomeLedger.php | SelfConstruction/Maestro/ProviderLearning/AtlasMaestroProviderPerformanceLedger |
| AtlasLoopOriginationPipeline | AutonomousEvolution/AtlasLoopOriginationPipeline.php | Brain/*; cmd AtlasBrainNextCommand |
| AtlasLoopAmbitionLeapProposer | AutonomousEvolution/AtlasLoopAmbitionLeapProposer.php | Brain/AtlasBrainEvolutionLevelClassifier |
| AtlasLoopAutoArchitectureProposalService | AutonomousEvolution/AtlasLoopAutoArchitectureProposalService.php | Brain/AtlasBrainEvolutionDocAuthor |
| AtlasLoopAutoMergeService | AutonomousEvolution/AtlasLoopAutoMergeService.php | SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeStallToTaskConverter |
| AtlasLoopComprehensionGroundingGate | AutonomousEvolution/Verify/AtlasLoopComprehensionGroundingGate.php | Brain/AtlasBrainEvolutionDocAuthor |
| AtlasLoopContractGapScanner | AutonomousEvolution/AtlasLoopContractGapScanner.php | cmd AtlasBrainContractGapsCommand |
| AtlasLoopFrontierGapModel | AutonomousEvolution/AtlasLoopFrontierGapModel.php | Brain/AtlasBrainEvolutionLevelClassifier |
| AtlasLoopHeavyWorkSelector | AutonomousEvolution/Discovery/AtlasLoopHeavyWorkSelector.php | Brain/AtlasBrainEvolutionLevelClassifier |
| AtlasLoopLearningAppendService | AutonomousEvolution/AtlasLoopLearningAppendService.php | Brain/AtlasBrainReflectionStream |
| AtlasLoopMergeActuator | AutonomousEvolution/Constitution/AtlasLoopMergeActuator.php | SelfConstruction/AgentControlPlaneClaimLeaseRepository (**pétreo** — não editar o arquivo) |
| AtlasLoopPatternLearningLedger | AutonomousEvolution/Pattern/AtlasLoopPatternLearningLedger.php | Brain/AtlasBrainCausalEffectGate |
| AtlasLoopRefillerSupplyLaneCoordinator | AutonomousEvolution/Consolidation/AtlasLoopRefillerSupplyLaneCoordinator.php | SelfConstruction/AgentControlPlaneMultiAgentLoopProbeRunner |

As demais ~574 classes `AtlasLoop*` sob `AutonomousEvolution/` (fora de `Brain/`) são **DEAD-ONLY** (sem
referência viva) — mas ainda assim exigem o piso `rg --no-ignore = 0` + backstop de suíte antes de qualquer
retirada, e alerta de retirada transitiva (uma DEAD-ONLY pode ser puxada por uma VIVA). Não deletar em massa.

## Regras para IA

1. Ao falar/implementar "autonomia do Atlas" = falar do **Autônomos** (brain+task-serving), não do loop/ACDE.
2. NÃO mirar "ligar o master switch do loop" (`ATLAS_LOOP_MASTER_ENABLED`) como meta — é o cadáver.
3. Antes de deletar/renomear qualquer `AtlasLoop*`, rode `rg --no-ignore -w <Classe>`; se ≠ 0, **PARE** — e cheque este keep-list.
4. Commit do autônomo = **escopado na main** (git add -- <arquivos>), nunca `git add -A`, nunca push, nunca merge (exceto obra).

## Evidencias

4.841 commits `atlas-task` na main; seed-gate refuse-rate ~50%; keep-list verificado por varredura de
referência direta (SelfConstruction + Brain + comandos brain/task) em 2026-07-08.

## Riscos

Confundir loop-morto com autônomo-vivo (mirar meta errada); deletar `AtlasLoop*` viva pelo prefixo;
dizer "auto-merge" quando o caminho é commit escopado.

## Referências

`docs/loop-canonical-definition.md` (régua histórica/legado), memórias `loop-morto-autonomos-vivo`,
`loop-commits-scoped-main-not-merge`, `brain-seed-hub`, `constitution-gate-sev1-selfedit-safety`.
