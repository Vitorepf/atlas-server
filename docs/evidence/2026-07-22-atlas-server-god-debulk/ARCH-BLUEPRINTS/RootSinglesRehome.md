# BLUEPRINT — RootSinglesRehome

> status: draft-v2 (SOBREVIVEU ao verify adversarial; 4 emendas aplicadas — evidências verificadas pelo comandante) · data: 2026-07-22 · obra: GOD Debulk · escopo: raiz de app/Services/Ai/

## 1. Contexto (verificado)

95 arquivos soltos na raiz convivendo com ~120 owner-folders existentes. Decisão: raiz final = **6 pipes canônicos**; **89 movers** re-homed. Re-home organizacional puro: nenhum nome de classe muda, nenhum comportamento muda.

Fatos verificados: zero colisões de basename (CONFIRMADO por amostra do comandante) · zero imports de root singles em config/routes/bootstrap/database · precedente de class_alias no repo (app/Models/AiExecutionPlan.php) · Larastan+Pint instalados · **RISCO Nº1 (invisível a sed): referências same-namespace sem `use`** — AiWorker usa AiConversationRecorder/AiProviderResult/FairClaudePolicy sem import (CONFIRMADO); AiGatewayService, AiPromptBuilder (AiPrompt), AiCompactionService (CompactionLossPolicy) idem. Cada grupo ADICIONA `use` nos pipes. Testes NÃO se movem (700+ flat, sem espelho estrito) — só sed de import.

## 2. FICAM na raiz (6)
AiWorker · AiGatewayService · AiProviderManager · AiPromptBuilder · AiCompactionService · AtlasDecideService (STAY neste ciclo — hub com dependentes; re-home dele = obra própria futura).

## 3. Mapa dos 89 movers (por grupo)

- **P — Provider/ (11)**: AiProvider (interface) · AiProviderResult · AiProviderChoiceBuilder/Exception/Resolver · AiProviderHandoffService · AiProviderHealthCheck/HealthService · AiProviderModelResolver · GeminiModelCatalog · FairClaudePolicy
- **C — Cli/ (5) + Hermes/ (1)**: ClaudeCliProvider · CodexCliProvider · GeminiCliProvider · MinimaxM27CliProvider · JarvisMlxProvider → Cli/; HermesCliProvider → Hermes/
- **X — Context/ (5) + ValueObjects/ (1) + Router/ (1)**: AiContextPackBuilder · AiContextSnapshotRecorder · AiConversationContextBuilder · AiConversationRecorder · AtlasDialecticTensionService → Context/; AiPrompt → ValueObjects/; AiIntentRouter → Router/
- **S — sessão/superfície (9)**: AiSessionManager/SessionStateService/ThreadDeletionService/ThreadResolver → ConversationOps/; AtlasFinalResponseSanitizer + AiSurfaceHandoffService → Surface/; AiExecutionPresentationState → HumanSurface/; AiStreamRecorder → Streaming/; AiInteractionSteeringService → ControlPlane/ (arbitrar: vs Instrumentation/)
- **M — Memory/ (12)**: AiMemoryDeltaProposer · AtlasHybridMemoryRetrievalService · AtlasMemoryContextComposer · Delta/LearningPromotion · Maintenance · Quality · Registry · ReviewQueue · Usage · AtlasVerbatimMemoryService · AtlasRecallUncertaintyMap; **MemoryGovernance/ (5)**: AtlasMemoryGovernanceService · MemoryPrivacyService · SourcePrivacyPolicy · MemoryHealthCompositePolicy · MemoryQualityStatusPolicy
- **O — OpenBrain/ (13)**: AtlasOpenBrain{Service, ContextExpansion, ContextFeedbackAutoQuarantineAdvisor, ContextInjectionBoundaryClassifier, ContextInjection, ContextPack, FileContext, Guard, MemoryProjectionSafetyGate, SessionCapture, WriteBack} + AtlasAobgBlackboardService + AtlasAobgWorkspaceOnboardingService; **Mcp/ (1)**: AtlasOpenBrainMcpService; **QUARANTINE (1)**: AtlasOpenBrainProviderSafeMemoryToneFilter → Aaeos/Quarantine/ (0 callers prod; namespace canônico de quarentena já em config/atlas_elite_compaction.php)
- **D — Policy/ (6)**: AtlasAiPolicyService · AtlasEffectivePolicyComposer · AtlasDomainProfilePolicyService · AtlasDomainProfileRegistry · AtlasAiRuntimeSettings · AiRuntimeBudgetService (movem juntos); **AtlasDecide/ (1)**: AiDecisionReceiptRefreshService; **Governance/ (3)**: AiPermissionDecision/Engine/EngineSupport
- **Pequenos (14)**: AiQualityActionService+Evaluator → Analysis/; AiTrace*Projection ×2 + AiWorkerLogger + AtlasProviderProjection{Service,AuditService,AuditPurgePolicy} → Instrumentation/; AiSkill+AiSkillStore → Skills/; AiCouncilCoordinator → Arena/; YouTubeKnowledgeIngestionService+YoutubeCanonicalProjection → Knowledge/; CompactionLossPolicy → Compaction/ (0 imports; `use` no pipe)

## 4. Blast radius medido
Top: AiProviderResult 47 callers · AiProvider 43 · AiProviderHealthCheck 34 · AtlasMemoryRegistryService 31 · AtlasOpenBrainMcpService 25 · MemoryPrivacy 24 · HybridMemoryRetrieval 23 · ContextPackService 20 · FairClaudePolicy 17. Total ~550 arquivos-caller (Provider concentra ~150). 62 classes ≤6 callers; 4 com 0 imports.

## 5. Mecânica (1 commit atômico por grupo)
1. `git mv` dos arquivos do grupo; 2. sed do `namespace` no movido; 3. sed de imports (use + FQCN inline) em app/tests; 4. **passo anti-armadilha same-namespace** (obrigatório): rg do basename nos arquivos da raiz E da pasta de origem → adicionar `use`; inverso p/ quem chega e referenciava a raiz sem use (ex.: JarvisMlxProvider→AiProvider); 5. corrigir strings dinâmicas do grupo (§7); 6. append no shim único; 7. gate.

**Shim único lazy datado**: `app/Services/Ai/Compat/RootSinglesLegacyAliases.php` (composer autoload.files) com spl_autoload_register + mapa classe-antiga→FQCN novo, class_alias sob demanda. `// REMOVE-BY: +1 ciclo`. Cobre payloads de queue em voo + strings escapadas.

**Gate por commit**: `composer dump-autoload -o` → `vendor/bin/phpstan analyse` (o ÚNICO que pega same-namespace esquecido) → suíte filtrada do grupo (verde ANTES e depois) → sweep residual `rg 'App.Services.Ai.<Classe>[^\\]'` = só o shim. Suíte completa ao fim de cada dia + fechamento.

## 6. Ordem (menor risco primeiro; Provider/Cli por último)
1 Knowledge(2) → 2 Arena(1) → 3 Compaction(1) → 4 Router(1) → 5 Streaming(1) → 6 Skills(2) → 7 Analysis(2) → 8 Governance(3) → 9 Surface+Human(3) → 10 ConversationOps(4) → 11 Instrumentation(6) → 12 Context+VO+CP+Decide(8) → 13 Policy(10) → 14 Memory+Gov(17, ~130 callers) → 15 OpenBrain+Mcp+Quarantine(15, ~105) → 16 **Provider(11, ~150)** → 17 **Cli+Hermes(6)**.
Racional: Provider antes de Cli — o commit Provider corrige os imports dos drivers; Cli vira mv puro. Grupos 1-11 = treino de mecânica com blast pequeno.

## 7. Strings dinâmicas — 8 hits REAIS (lista fechada, verificada por amostra)
1. `KernelArchitectureStaticScanner.php:226-228` — 3 FQCNs hardcoded (CONFIRMADO pelo comandante) → grupos 12/15/14
2. `Engineering/AtlasCodeRealityUsageIntelligenceService.php:4756,4795,4837` — 3 paths → 12/15/14
3. `AtlasEngineeringBenchmarkSeedCommand.php:190,230,332,366` — paths de drivers em forbidden_files → 17
4. `tests/Unit/Ai/AtlasOpenBrainProviderSafeMemoryToneFilterTest.php:91` — base_path do fonte → 15
5. `tests/Feature/Ai/AtlasOpenBrainContextPackServiceTest.php:2438-2449` — path + sym pinados → 15
6. idem `:1453…2620` + `TaskFacetExtractorTest.php:27` — FQCN strings → 15
7. `tests/Feature/Console/AtlasObraWorkOrderCommandTest.php:46-92` — path do Registry → 14
8. `tests/Feature/AtlasMemoryRegistryTest.php:522` — path do ContextPackBuilder → 12
Descartados com evidência: config/routes/database zero imports; jobs de queue usam method-injection (não serializam services).

## 8. EMENDAS do verify adversarial (obrigatórias)
1. **Strings que escaparam da lista de 8** (agora lista aberta com sweep obrigatório): BoundaryClassifierTest:200 (file_get_contents do fonte + assertStringNOTContains — vira VERDE-FANTASMA pós-move, verificado) → grupo 15 reescreve o teste; KernelBypassRegressionTest:247/259/458 (file_get_contents do McpService — quebra só na suíte completa) → grupo 15; scanner L213-215 (FQCN-strings dos 3 drivers CLI, além das L226-228 já listadas) → grupo 17; ContextPackServiceTest hits extras (2202/2236/2401/2632) → grupos 14/15.
2. **Gate do §5 corrigido**: o sweep residual ganha a variante PATH-form minúscula `rg 'app/Services/Ai/<Classe>\.php'` (o rg por namespace é cego a path-literal — lição recorrente da obra).
3. **Claim same-namespace era ilustrativo, não inventário**: medido — AiWorker referencia 19 movers sem use (não 3), AiGatewayService 13, AiProviderManager 8 (incl. os 6 drivers + AtlasAiRuntimeSettings), AiPromptBuilder 5, AtlasDecideService 2. O passo 4 (rg de basename por grupo) + larastan são o inventário REAL e obrigatório; a lista do §1 é amostra.
4. Guards/testes-guard fora dos grupos filtrados só caem na suíte completa — a suíte completa roda ao FIM DE CADA GRUPO pesado (14-17), não só fim de dia.

## 9. Arbitragens em aberto (não travam a ordem)
AiInteractionSteeringService → ControlPlane/ vs Instrumentation/ · AtlasAiRuntimeSettings+AiRuntimeBudgetService → Policy/ vs Runtime/.
