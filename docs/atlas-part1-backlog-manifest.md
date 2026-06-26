# Atlas — Manifesto do Backlog da PARTE 1 (refatoração + Checkpoint A)

> **A GARANTIA não é o "foco" do cérebro — é ESTE manifesto.** Ele converte "Parte 1 pronta"
> de JULGAMENTO ("o cérebro diz que terminou") em FATO ("cada item tem packet seedado").
> Você nunca precisa confiar no foco de nenhuma IA — você CHECA a cobertura desta lista.
> Não-100% = não acabou. Qualquer IA (ou o operador) pode fechar o gap.
>
> Enumeração determinística (grep/wc, 25/06). Re-medir ao vivo antes de usar (nomes/contagens envelhecem).

## Como isto te dá a garantia (2 travas, nenhuma depende de "foco")
1. **"Qualquer IA pega e resolve"** = trava de CÓDIGO: todo packet seedado passou o gate `self_sufficient`
   do `AtlasTaskPacketQualityInspector` (objetivo FQCN, allowed_files completos+disjuntos, acceptance
   rodável, zero pétreo). Se passou, está especificado pra worker frio. Give-back devolve o resto → re-autora.
2. **"Lista COMPLETA da Parte 1"** = trava de MANIFESTO: a lista abaixo é finita e checável. Completude =
   cobertura. O cérebro (ou qualquer IA) só termina quando cobertura = 100%.

---

## A) GOD-CLASSES a SPLITAR — o GROSSO (22 classes >1000L)
Cada uma → vários packets de fatia encadeados (delegador byte-idêntico, assinatura pública intacta).
Estimativa: ~100-150 packets de split no total. **DONE quando cada classe tem suas fatias seedadas.**

| ✓ | Linhas | Classe |
|---|---|---|
| ☐ | 104222 | AtlasSelfConstructionReadinessService (o MONSTRO — obra multi-wave sozinha) |
| ☐ | 2726 | AgentControlPlaneMultiAgentLoopCertificationService |
| ☐ | 2386 | AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService |
| ☐ | 2206 | AtlasLoopQueueRefiller (split JÁ iniciado — continuar) |
| ☐ | 2165 | AgentControlPlaneChainIntegrityAuditService |
| ☐ | 2130 | AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService |
| ☐ | 2088 | AtlasLoopCampaignSupervisor |
| ☐ | 1587 | AgentControlPlaneTaskQueueOrchestrator ⚠️ core da esteira — cuidado extra |
| ☐ | 1553 | AgentControlPlaneTerminalLoopHealthDigestService |
| ☐ | 1473 | AtlasLoopAutoMergeService |
| ☐ | 1380 | AtlasLoopIntentVerifierFactory |
| ☐ | 1373 | AtlasLoopTaskGrinder |
| ☐ | 1352 | AtlasSelfConstructionOsCompletionAuditService |
| ☐ | 1239 | AtlasLoopObraExecutionAdapter |
| ☐ | 1203 | AgentControlPlaneTaskPacketQueueRepository ⚠️ core da esteira |
| ☐ | 1181 | AgentControlPlaneTerminalLoopOperationalProofService |
| ☐ | 1150 | AgentControlPlaneTaskAutoReplenishmentService |
| ☐ | 1146 | AgentControlPlaneClaimLeaseRepository ⚠️ core da esteira |
| ☐ | 1129 | AgentControlPlaneTerminalWorkerBootstrapService |
| ☐ | 1074 | AtlasEvolutionScenarioExplorer |
| ☐ | 1073 | AtlasLoopSemanticImplementationCertifier |
| ☐ | 1047 | AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService |

## B) DEAD-CODE a DELETAR (só dead-weight PROVADO 0-ref)
- ☐ Generated/ scratch shells (3 candidatos: BatteryRunnerSentinelTest, ParkLedger, TerritorySafetyClassifier) — cada um grep-0-ref ao vivo.
- **NÃO incluir os ~124 órfãos 0-ref que são organs-a-LIGAR** (Oracle/Gate/Harness/ControlPlane) — deletar destrói a arquitetura. Ver [[loop-orphans-are-unwired-organs-not-deadcode]].

## C) DEDUP — JUDGMENT-GATED (NÃO é "dedup os 144 cegamente)
⚠️ A maioria é **cert-risk / baixo-valor**: `stableHash` (144), `normalizeForHash` (19) alimentam hashes de certificação — dedup byte-idêntico é crítico e o ganho é baixo. **NÃO expandir cego.**
- ☐ `recursivelyKsort` (30 cópias) — o cluster mais limpo/seguro: helper + waves de troca byte-idêntica. **Esse vale.**
- `ksortRecursive` (96), `stableHash` (144): só se provar ganho real sem cert-risk. **Default = DEFERIR.**

## D) CHECKPOINT A — milestones concretos (worker-able)
- ☐ self-heal: repair-blocked aposenta repeated_give_back≥7 via organ (wire #1 — JÁ seedado).
- ☐ irmãos do disk-gotcha (comandos resolvendo queue/lease pelo container em vez de AtlasTaskServingStack).
- ☐ retire-verb governado (blocked→cancelled auditado).
- ☐ wiring dos organs COMPLETOS do roadmap (decisão de cluster do operador — A/B/C-D) — CADA organ lido ao vivo antes; PULAR incompletos (ex.: HiddenPoisonDetector).
- ☐ build do brain-harness (ver [docs/atlas-brain-harness-build-spec.md]) — 4 worker packets já seedados; núcleo = lead.

---

## ESTIMATIVA Parte 1 ≈ 130-200 packets
Dominado pelos splits de god-class (~100-150). Dead-code pequeno. Dedup só recursivelyKsort (~10). Checkpoint A ~15-25.

## CHECK DE COBERTURA (rode pra ver o gap — esta é a garantia em ação)
```
# god-classes >1000L restantes:
find app/Services/Ai/AutonomousEvolution app/Services/Ai/SelfConstruction -name '*.php' | xargs wc -l | awk '$1>1000 && $2!="total"' | wc -l
# packets de split já na fila (ajustar prefixo conforme o autor):
grep -rl '"objective".*[Ss]plit' storage/app/atlas/task-serving/atlas_serving/**/task-queue/task_*.json | wc -l
```
**Parte 1 está PRONTA quando: toda god-class tem fatias seedadas + recursivelyKsort deduped + dead-code deletado + milestones do Checkpoint A autorados.** Isso é um FATO checável — não a palavra de nenhuma IA.
