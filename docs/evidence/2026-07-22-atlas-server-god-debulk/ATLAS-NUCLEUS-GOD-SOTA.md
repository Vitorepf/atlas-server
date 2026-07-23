# ATLAS NUCLEUS — GOD / SOTA  
## Especificação completa do núcleo extremo do atlas-server

> **Status:** `design-v2` (expansão completa) · 2026-07-23  
> **Lane:** ARQUITETURA / elevação (norte-star de **forma final**)  
> **Escopo:** **100% do corpus** — app · tests · docs · config · routes · database · scripts · bin · bootstrap · public · resources · archive  
> **Insumos:** INTENT 1–69 · COMPLETE A–G · FILESYSTEM-100 (478 buckets) · CONSOLIDATION-MAP (129/129) · ARCH-BLUEPRINTS (7) · pipeline · knowledge-governance · autonomos-live · terminal-first · CODEMAP  
> **Relação:** este doc manda no **destino**; blueprints mandam no **como mover monstro**; META-FINDINGS/EXEC mandam na **fila de arquivo**.  
> **Não colide** com executores Claude/Codex em paths claimed.  
> **Mapa visual:** `ATLAS-NUCLEUS-MAP.html`  
> **Sofisticação GOD/SOTA:** lógica elevada + abstraída + simples de manter (sobretudo por IA) — não floresta, não monólito, não vanity.

---

# PARTE A — FILOSOFIA E LEIS

## A0. Tese

**Atlas Server é um núcleo pequeno e perfeito** que orquestra continuidade, intenção, contexto, memória, política, decisão, execução, prova e aprendizado — com providers como **motores substituíveis**, superfícies (CLI/HTTP/mobile/voice/MCP) como **coroa fina**, e todo o resto **morto, fundido ou em quarentena**.

```text
Provider capability
  ×  Nucleus (12 órgãos · pipeline único · contratos honestos · proof-first)
  =  Atlas output
```

O produto **não** é “120 pastas Ai”. O produto é **um cérebro com poucos órgãos load-bearing**, cada um com:

1. **uma** façade pública  
2. grafo **one-way** Policy / Projector / Runtime / Gateway  
3. contrato que **cabe no contexto** de qualquer IA  
4. characterization dos entrypoints  
5. zero gêmea com o mesmo job  

### A0.1 O que “máximo” significa

| Dimensão | Máximo ≠ | Máximo = |
|---|---|---|
| Código | Mais camadas | **Menos superfície de verdade** com mesma capacidade |
| Sofisticação | Código “esperto” | **Simplicidade elevada** (branch mínima, nome honesto) |
| Abstração | Wrapper farm | **Invariante nomeada** ou 2º consumidor real |
| Otimização | Micro-tuning cego | Fail-closed cedo · bound de scan · um ledger · um pipe |
| Manutenção IA | Docs de 400k | ≤3 hops · CODEMAP · hot ≤800 · any ≤2000 |
| Escala | N-ésima OS | **Domínios como plugins** do núcleo |

### A0.2 Analogia nativa (herdada)

Mesmo espírito do GOD restructure do atlas-native (Swift): vocabulário fechado, densidade, honesty rename, SPLIT before fuse, CODEMAP verdadeiro, zero produto na missão de estrutura, goal até cancelar. Server = Laravel/PHP no **repo inteiro**, sem big-bang PHP→Swift.

---

## A1. Axiomas pétreos (GOD / SOTA)

| # | Axioma | Teste operacional |
|---|---|---|
| 1 | **Simplicidade elevada** | Após mudança: hops ↓ ou iguais; branches ↓; capacidade ≥ |
| 2 | **Abstração real** | Extrair só com 2º consumidor **ou** invariante nomeada; senão inline |
| 3 | **Des-abstração** | Wrapper sem decisão/validação/tradução = **delete** |
| 4 | **Um conceito = um owner** | Uma capability → uma pasta → uma façade → zero gêmea pública |
| 5 | **Policy ≠ I/O ≠ Projection ≠ Mutation** | Policy 0 I/O · Gateway 0 decisão · Projector 0 write · Runtime único write + envelope |
| 6 | **Fail-closed default** | Campo/autoridade ausente → `blocked` + violação tipada; proibido `*_ready` mentiroso |
| 7 | **Agent-optimal** | Achar ≤3 hops · hot ≤800 · any ≤2000 · CODEMAP `Class::method` |
| 8 | **Prova > feeling** | Red→green ou golden F0; vanity `pass++` proibido |
| 9 | **Núcleo extremo** | Se não é load-bearing no pipeline vivo → **não** é N |
| 10 | **Provider-safe** | Nada sensível em logs/projections/MCP out |
| 11 | **Autoral vs read-model** | docs repo > evidence ledger > Postgres KB/CodeGraph > vault > AGENTS > chat |
| 12 | **Keep-list Autônomos** | Não deletar `AtlasLoop*` vivo por prefixo |
| 13 | **SPLIT before fuse** | Nunca fuse cego em monstro |
| 14 | **Terminal-first** | Sem casca própria; moat = verificação/review |
| 15 | **Main local only** | Commits escopados; sem merge no caminho comum |

### A1.1 Anti-GOD (lista expandida)

1. Split mecânico → floresta `*Invoker*GateInvoker`  
2. Fuse cego → godfile 2k–5k novo  
3. 5ª OS layer com job de outra  
4. File-count / LOC isolado como vitória  
5. Feature/produto na missão de estrutura  
6. Matar keep-list Loop por prefixo  
7. Casca/IDE própria como plataforma  
8. Tri-sincronização Section ↔ Mother ↔ FLAG_METHOD  
9. `project*` / status que muta  
10. Dois ledgers para o mesmo ato  
11. Duas registries de provider  
12. Bypass do pipeline no hot path  
13. Fuse por **nome** (Cognition×Cognitive)  
14. Quarantine sem re-home de fio vivo  
15. Scanner pin a monstro sem migrar token no mesmo commit  
16. Elevação no path claimado por outro engine  
17. Section >2k chamada de “progresso” sem 2ª quebra ≤1500  
18. `setMother` / `__call` / Reflection como capability  
19. Auto-promote de learning hostil  
20. Goal Done cedo / residual pass vanity  

---

## A2. Métricas de vitória (falsificáveis)

### A2.1 Núcleo GOD fechado (todos obrigatórios)

1. ≤ **12 órgãos** com façade no CODEMAP + hops ≤3  
2. **0** PHP em perímetro N >2000; hot façade/command/controller ≤800  
3. **1** ProviderPipe · **1** EvidenceLedger de execução · **1** Decide  
4. Pipeline único sem bypass no hot path  
5. CLI ≤ **12 famílias**; commands thin  
6. Cada `config/atlas*.php` ≤800  
7. Coroa zero lógica de domínio  
8. Operate-path `atlas:loop:*` = 0; archive fora do runtime PSR-4  
9. Characterization verde dos 12 órgãos + smoke hot paths `<5 min`  
10. COMPLETE A–G = 10/10 no perímetro N (coroa/long-tail com gates próprios)  
11. CONSOLIDATION-MAP 100% com coluna **Órgão N\*** + status closed/recorded  
12. Keep-list Autônomos intacta e documentada  

### A2.2 Scoreboard contínuo (medir semanal)

| Métrica | Baseline missão | Alvo núcleo |
|---|---:|---:|
| Godfiles Ai >5k | ~14 | 0 |
| Godfiles Ai >2k | ~40 | 0 |
| Max LOC single PHP em N | ~30k | ≤2000 |
| Hot façades >800 | dezenas | 0 |
| Pastas Ai top-level | ~120 | ≤12 órgãos + Domains + Support |
| Commands | ~910 | famílias ≤12 · thin |
| ACDE órfãos no path vivo | 244+ | 0 |
| Registry provider paralela | 2 | 1 |
| Ledgers delivery paralelos | 2 (AVER + Real) | 1 lineage |
| setMother / __call cross | presente Readiness | 0 |

---

## A3. Três anéis (modelo universal)

Tudo no server cai em **exatamente um** anel. Path novo sem anel = `DEBTS unmapped`.

```text
Q QUARENTENA  archive/ · default-off · peels · gêmeos · theater · ACDE morto
      ▲ reentrada só com prova de vida + owner + 2º consumidor + OK se FUSE/KILL
C COROA       CLI · HTTP · Mobile · Voice · MCP · Jobs thin · MacAgent · Capture I/O
      ▲ só chama façades N*; zero domínio
N NÚCLEO      12 órgãos · pipeline · contracts · proof
```

| Anel | Pode | Não pode | Teste de admissão |
|---|---|---|---|
| **N** | Decidir, lembrar, executar com receipt, provar, aprender (proposal) | Conhecer iOS/LiveKit/Blade/repl UI | “Sem isto o pipeline quebra?” |
| **C** | Auth, parse, stream, render, enqueue | Policy/decide/evidence própria | “Só traduz ↔ N?” |
| **Q** | Existir quieto | Import em operate-path N/C | “rg produção = 0 ou default-off?” |

### A3.1 Fluxo entre anéis

```text
Human/IA externa
  → C (auth + parse + present)
    → N (pipeline completo)
      → Provider (motor) / Postgres / FS
    ← Decision Receipt + Evidence + Output envelope
  ← C (render / stream / push)
Q nunca no caminho feliz
```

---

# PARTE B — PIPELINE ÚNICO (ESPINHA)

## B1. Ordem macro (imutável)

```text
Input
→ Intent
→ Domain
→ Domain Profile
→ Flow Profile
→ Context
→ Policy
→ Decide
→ Executor
→ Gate
→ Repair / Escalation
→ Evidence
→ Learning
→ Output
```

## B2. Etapa → órgão → pergunta → invariante

| Etapa | Órgão | Pergunta | Invariante |
|---|---|---|---|
| Input | N1 + C | O que entrou, de onde, com o quê? | Origem, surface, workspace, anexos preservados |
| Intent | N2 | O operador quer o quê? | Confiança ou motivo da classificação |
| Domain | N2 | Qual vertical? | Explícito se muta estado real |
| Domain Profile | N2 | Qual SO vertical? | Explícito em runs operacionais |
| Flow Profile | N2 | Qual fluxo dentro do domínio? | Ex.: `programming.dev` |
| Context | N3 (+N4) | O que entra no motor? | Hash, budget, refs; top-K honesto |
| Policy | N5 | O que é permitido? | Provider/modelo/budget/tools/gates registrados |
| Decide | N6 | Qual plano de execução? | **Decision Receipt** antes de exec relevante |
| Executor | N7+N8+N9(+N10) | Quem roda com que intensidade? | Não escolhe própria política |
| Gate | N9+N11+domain | O que prova sucesso? | Evidência antes de “ok” |
| Repair | N9+domain | Corrigir, escalar ou bloquear? | Capsule estruturada, sem prompt improvisado |
| Evidence | N11 | O que foi feito/medido? | Append-only via API tipada |
| Learning | N12 | O que vira proposta? | Nunca auto-promove sensível |
| Output | C | O que o operador vê? | Sanitizado; provider-safe |

## B3. Profiles (exemplos canônicos)

```text
programming.dev
programming.forge
programming.qa
programming.autonomos_task
finance.market_research
personal_development.weekly_review
marketing.content_plan
cyber.scope_review
atlas.memory.recall
atlas.open_brain.context_pack
```

Domain plugins **só** customizam etapas; **não** reordenam o macro.

## B4. Envelope mínimo de request (núcleo)

Todo run operacional relevante carrega (conceitualmente):

```text
{
  schema_version,
  surface,                 # cli|http|mobile|voice|mcp|job
  workspace_id,
  thread_id?, session_id?,
  intent: { label, confidence, rationale },
  domain, domain_profile, flow_profile,
  context: { hash, budget_chars, refs[] },
  policy: { provider_allow[], tools[], budget, autonomy, gates[] },
  decision_receipt_id?,    # obrigatório pré-exec relevante
  runtime_write_performed?,
  evidence_refs[],
  privacy_class
}
```

---

# PARTE C — OS 12 ÓRGÃOS (DETALHE TOTAL)

Convenções por órgão:

- **Absorve:** pastas/blocos atuais que convergem aqui  
- **FUSE/KILL/Q:** ações do CONSOLIDATION-MAP  
- **Façade alvo:** nome de capability (não precisa existir ainda)  
- **API pública:** famílias de método estáveis  
- **Collaborators:** Policy / Projector / Runtime / Gateway  
- **Hot classes hoje:** âncoras de código atual  
- **Blueprint:** se monstro  
- **Done órgão:** critérios falsificáveis  
- **Não é:** confusões comuns  

---

## N1 · Continuity

| | |
|---|---|
| **Pergunta** | Quem é a sessão, thread, workspace, operador? |
| **Absorve** | ConversationOps · (parcial) DualCore boundary de sessão · AOBG workspace activation · modelos ai_threads/ai_sessions |
| **Coroa vizinha** | Mobile pairing tokens · HTTP session headers · CLI workspace cwd |
| **Façade** | `ContinuityRuntime` |
| **API** | `ensureSession` · `resolveThread` · `activateWorkspace` · `packSessionContext` · `projectHandoff` |
| **Collaborators** | Projector: session state read · Runtime: create/update session · Gateway: DB |
| **Hot hoje** | `AiThreadResolver` · `AiSessionManager` · `AiSessionStateService` · `AtlasAobgWorkspaceOnboardingService` |
| **Done** | Uma API de thread/session; 0 lógica de provider dentro; characterization de ensure/resolve |
| **Não é** | Memory durável (N4) · Intent (N2) |

---

## N2 · Intent & Domain

| | |
|---|---|
| **Pergunta** | O que quer? Qual vertical e fluxo? |
| **Absorve** | Router · RouterRuntime · HumanSurface · Domain · DomainRuntime · DomainProfiles · DualCore route_decision (boundary) · SpecialistFlows→Q |
| **FUSE** | Router → RouterRuntime · Domain/ indireção → camadas-app dos domínios |
| **Q** | SpecialistFlows · CapabilityMarket (via Decide parcial) |
| **Façade** | `IntentDomainRuntime` |
| **API** | `classifyIntent` · `resolveDomain` · `resolveDomainProfile` · `resolveFlowProfile` · `projectRoute` |
| **Hot hoje** | `AtlasAiIntentKernelService` · `AiIntentRouter` · `RouterRuntime/*` · `AtlasDomainProfileRegistry` · `HumanSurface/*` |
| **Done** | 1 motor de flow; IntentKernel legado sem callers ext; profiles explícitos em runs ops |
| **Não é** | Escolha de provider (N6) · Execução (N7–N9) |

### N2.1 Domínios como plugins (não OS)

| Domínio | Bloco atual | Seed / infra |
|---|---|---|
| Programming | Programming (+Runtime) · Product · EngineeringKernel roles | DomainRuntime seeder |
| Finance | Finance | DomainRuntime |
| Marketing | MarketingDomain · Publishing | DomainRuntime |
| Cyber | Cyber · Security util | DomainRuntime |
| Strategy / Research | Strategy · ResearchDomain · LongHorizon(TEOS advisory) | DomainRuntime |
| Personal | PersonalDevelopment | DomainRuntime |
| Automation | AutomationDomain | 1 fio — vigiar |
| Holding / Venture | Holding · VentureFoundry | domain/business — não muscle de código |
| Rivals | Rivals kernel 2.0 | medição; adapters 1.0 → Q |
| CrossDomain | CrossDomain mesh | plugin mesh |

**Lei:** domínio novo = onboarding contract + OWNERSHIP row + 2º consumidor; **proibido** nova pasta “OS”.

---

## N3 · Context Fabric

| | |
|---|---|
| **Pergunta** | O que entra no motor com budget e hash? |
| **Absorve** | Context · ContextIntelligence · PersistentContext · Compression · Compaction · Search · CognitiveMemory · OpenBrain context pack/injection/file-context · WorkspaceIntelligence · Reality (AURG graph read) · Engineering CodeGraph/KB (read model) · AcosMax embedding/RAGX (re-home) |
| **FUSE** | ContextIntelligence → Context · PersistentContext → Context |
| **Façade** | `ContextFabric` |
| **API** | `packContext` · `rankRefs` · `projectFileDelta` · `expandHandle` · `projectBudget` |
| **Hot hoje** | `AtlasOpenBrainContextPackService` · `AiContextPackBuilder` · `AiContextSnapshotRecorder` · `AtlasOpenBrainFileContextService` · `EngineeringCodeIntelligenceService` |
| **Leis** | top-K honesto · provider-bound · expand on demand · feedback demote noise |
| **Done** | 1 pack builder path; snapshot hash estável; 0 dump raw sensível |
| **Não é** | Memory promote (N4) · Prompt final assembly pode colar N7/AiPromptBuilder |

---

## N4 · Memory Core

| | |
|---|---|
| **Pergunta** | O que é verdade durável / recall / promoção? |
| **Absorve** | Memory · MemoryGovernance · Knowledge · Reconciliation · Cartography · CognitiveMemory (working set) · Brain (journal) · Semantic/Vault (leitura humana via C, promote via N4) |
| **FUSE** | MemoryGovernance → Memory · Cartography → Reconciliation · Vault → Semantic |
| **Façade** | `MemoryCore` |
| **API** | `record` · `recall` · `proposeDelta` · `promote` (gated) · `projectPrivacy` · `maintain` · `scorecard` |
| **Hot hoje** | `AtlasMemoryRegistryService` · `AtlasHybridMemoryRetrievalService` · `AtlasMemoryContextComposer` · `AtlasMemoryPrivacyService` · Knowledge ingestion |
| **Hierarquia de verdade** | docs repo > evidence (N11) > KB/CodeGraph > vault > projections > chat |
| **Done** | 1 registry; privacy fail-closed; promote nunca raw secret |
| **Não é** | Learning proposal genérica de run (N12) — mas promove candidatos de N12 |

---

## N5 · Policy Plane

| | |
|---|---|
| **Pergunta** | O que é permitido (budget, tools, gates, autonomia)? |
| **Absorve** | Policy · Governance · OperatorApproval · Runtime budget · Security (PromptInjectionScanner) · AgentGovernance reclass fleet-ops (não permission path) |
| **Façade** | `PolicyPlane` |
| **API** | `composeEffectivePolicy` · `authorize` · `assertBudget` · `admit` · `projectRisk` |
| **Hot hoje** | `AtlasAiPolicyService` · `AtlasEffectivePolicyComposer` · `AiPermissionEngine` · `PolicyCanon` |
| **Lei** | Policy **pura** (0 I/O). Dedup `RISK_LEVELS` → PolicyCanon |
| **Done** | 1 admit path; 0 cópia de RISK_LEVELS; characterization authorize/deny |
| **Não é** | Decide provider (N6) · Gate de qualidade de delivery (N9/N11) |

---

## N6 · Decide

| | |
|---|---|
| **Pergunta** | Qual provider/modelo/grafo/fallback e evidence contract? |
| **Absorve** | AtlasDecide · (CapabilityMarket → Q) · partes Decision de Kernel |
| **Façade** | `AtlasDecide` (nome canônico) |
| **API** | `decide` → **Decision Receipt** · `refreshReceipt` · `projectDecision` |
| **Hot hoje** | `AtlasDecideService` · `AiDecisionReceiptRefreshService` · Kernel Decision/* |
| **Lei** | Decide **não** executa domínio. Override manual = exceção registrada. Auto-best dentro do allowlist de N5 |
| **Done** | 0 exec sem receipt em hot path; CapabilityMarket quarantined |
| **Não é** | AiProviderManager.run (N7) |

---

## N7 · Provider Pipe

| | |
|---|---|
| **Pergunta** | Como o motor é invocado **uma** vez, com health e cost? |
| **Absorve** | AiProviderManager · AiGatewayService · AiWorker · Provider/ · Caching · Hermes · Streaming · Tokens · root pipes · Skills (packs de prompt) · Compaction L1 acoplada ao prompt |
| **FUSE** | Provider registry → Manager único (blueprint ProviderPipeUnification) |
| **Façade** | `ProviderPipe` |
| **API** | `run(Receipt, Prompt): ProviderResult` · `health` · `decorate` · `projectCost` |
| **Hot hoje** | `AiProviderManager` · `AiGatewayService` · `AiWorker` · `ClaudeCliProvider` · `CodexCliProvider` · `Hermes/*` · `AiPromptBuilder` |
| **Lei** | App **nunca** fala com Claude/Codex direto. **Um** catálogo de drivers. Consult seam causal (não cosmético) |
| **Blueprint** | ProviderPipeUnification |
| **Done** | 1 registry; Swarm/Forge passam pelo mesmo consult; cost facts honestos |
| **Não é** | Tool file/shell (N8) · Task serving (N10) |

---

## N8 · Tool & Host Execution

| | |
|---|---|
| **Pergunta** | file/shell/git/test e tools com receipt? |
| **Absorve** | Runtime · ToolRuntime · RuntimeEfficiency · VerifiedContextExecution · RuntimeReadiness · RuntimeReleaseGate · RuntimeBoundary (Python FFI) · Tools (non-Ai agent tools) · MacAgent (host C) |
| **FUSE** | ToolRuntime → Runtime · VerifiedContextExecution → RuntimeEfficiency · RuntimeReleaseGate → RuntimeReadiness |
| **Façade** | `ToolExecutionRuntime` |
| **API** | `execute(ToolInvocation): ToolResult` · `availableTools()` · `projectReadiness` |
| **Hot hoje** | `AiToolRuntime` · Runtime/* · RuntimeEfficiency/* |
| **Blueprint** | RuntimeExecution (parcial N8+N9) |
| **Lei** | Mock = modo de teste do **mesmo** pipe, não 2º produto |
| **Done** | 1 execute path; receipt em mutação; catalog 19 tools classificado |
| **Não é** | Provider LLM (N7) · Patch delivery certify (N9) |

---

## N9 · Delivery & Obra Execution

| | |
|---|---|
| **Pergunta** | Como patch/test/certify/obra/mission materializam mudança? |
| **Absorve** | RealExecution · VerifiedExecution · Obra · Mission · Programming · ProgrammingRuntime · Product · Foundry spine · AtlasForge vivos · AgenticWorkcell · NightShift · Scheduling · Tasks · AutonomousWorkExecution (AWEOS) · EngineeringCompany roster |
| **FUSE** | VerifiedExecution → RealExecution · ProgrammingRuntime → Programming · AtlasForge → Programming/Forge · SoftwareCompany → Stewardship/ProductMode · IntelligenceFactory → Foundry |
| **Q** | Foundry Frontier default-off · Forge/ rg=0 KILL |
| **Façade** | `DeliveryRuntime` |
| **API** | `runDelivery` · `runObra` · `certify` · `projectProgress` · `planRepair` |
| **Hot hoje** | `AtlasRealEngineeringExecutionKernelService` · AVER services · Obra/* · Programming/* · Product/* |
| **Blueprint** | RuntimeExecution F4 lineage |
| **Lei** | **Um** lineage de certificação; AVER vira modo/adapter |
| **Done** | goal_record_id correlação; 0 path-literal quebrado pós-move; Product certification façade ≤800 |
| **Não é** | Self-construction task queue (N10) — embora N10 use N9 |

---

## N10 · Engineering Muscle (Autônomos vivo)

| | |
|---|---|
| **Pergunta** | Como o Atlas evolui código com prova e commit escopado? |
| **Absorve** | SelfConstruction (serving, control plane, readiness) · AutonomousEvolution/**Brain** vivo · Autonomy · keep-list AtlasLoop* · SoftwareCompanyStewardship (fiscal) · Aaeos **vivo** (~10k) · SelfDirectedEvolution · AgenticEngineeringOs (pós split/fuse AutonomousOs) · AutonomousEngineering · ControlPlane (cert agregador — disjunto do SC ControlPlane) · EngineeringKernel (roles) |
| **Q** | ACDE órfãos · Aaeos Quarantine 132k (após re-home AtlasSourceConnectorsAndCaptureService) · atlas:loop operate-path |
| **Façade** | `EngineeringMuscle` (facades internas: Brain · TaskServing · ControlPlane · ReadinessProjector) |
| **API** | `brainNext` · `brainSeed` · `taskNext` · `report` · `projectReadiness` (RO) · `runRecovery` · `projectQueue` |
| **Hot hoje** | `AtlasTaskServingService` · `AgentControlPlane*` · `AtlasSelfConstructionReadinessService` (~29k) · Brain commands · `AtlasTaskScopedCommitter` |
| **Blueprint** | SelfConstructionReadiness · QuarantineACDE · EnterpriseExecutorUnification (parcial) |
| **Leis** | Brain origina; músculo implementa; **git add -- files** na main · `project*` nunca muta · setMother/__call = 0 · ignore AIP/RES na obra GOD-DEBULK |
| **Done** | Readiness mother ≤800 façade; 0 tri-sync; lease ownership fail-closed; ACDE operate=0 |
| **Não é** | Loop ACDE morto · factory de feature de produto |

### N10.1 Sub-órgãos internos (ainda N10, não novos anéis)

| Sub | Papel | Teto |
|---|---|---|
| Brain | origina + seed gate | ≤800 hot |
| TaskServing | claim/report/commit scoped | ≤1500 |
| ControlPlane | queue/lease/bootstrap | Policy/Projector/Runtime separados |
| Readiness | projeção de prontidão | Projector RO + Runtime writers nomeados |
| Stewardship | fiscaliza operate-path | não vira 2º muscle |
| Aaeos vivo | orquestração restante | sem cemitério |

---

## N11 · Evidence & Certification

| | |
|---|---|
| **Pergunta** | O que prova que aconteceu e passou? |
| **Absorve** | Kernel/Evidence · Evidence/ (workflow Eloquent) · Arena · Telemetry · Analysis · Kernel/Architecture linter → ArchitectureGov · Instrumentation→Q/Telemetry |
| **SPLIT** | KernelTriad: Decision-runtime (→N6) · Evidence-ledger (N11) · Architecture-linter (N11b folha) |
| **Façade** | `EvidenceLedger` · `ArchitectureGovernance` (folha) |
| **API** | `record*` · `verifyChain` · `projectScorecard` · `scanArchitecture` · `projectTelemetry` |
| **Hot hoje** | `AtlasEvidenceLedger` · hash-chain verifier · `KernelArchitectureStaticScanner` (já split audits) · Arena · Telemetry/* |
| **Blueprint** | KernelTriad |
| **Lei** | 0 write direto em ledger fora da API tipada; linter **não** é runtime |
| **Done** | chain integrity F0; scanner façade ≤400; Architecture em bloco próprio |
| **Não é** | Memory registry (N4) · Learning apply (N12) |

---

## N12 · Learning & Compounding

| | |
|---|---|
| **Pergunta** | O que vira proposta reutilizável sem auto-promover hostil? |
| **Absorve** | Compounding · Learning · SelfImprovement · OperatorIntelligence · Aemor · Cognition (ACOS scorecard/immune) · Cognitive→rename Learning runtime · Organism · Teos · Rsi · OpenBrain write-back · AcosMax cockpit/series (re-home Cognition) |
| **FUSE** | Learning → Compounding **antes** de rename Cognitive→Learning |
| **Façade** | `LearningPlane` |
| **API** | `propose` · `review` · `apply` (gated) · `recordOutcome` · `projectCurriculum` |
| **Hot hoje** | Compounding/* · `AtlasOpenBrainWriteBackService` · SelfImprovement/* · Aemor/* · Cognition/* |
| **Blueprint** | LearningConsolidation |
| **Lei** | entrada externa não-confiável; proposal-only; nunca merge main via AOBG |
| **Done** | 1 writer de AiLearningProposal; Cognitive renomeado; write-back só branch |
| **Não é** | Memory promote final (N4) — N12 propõe, N4/N5 autorizam |

---

# PARTE D — MAPA 129 BLOCOS → ÓRGÃO / ANEL

> Fonte: CONSOLIDATION-MAP. Coluna **Órgão** = destino final no Núcleo.  
> Anel: N = núcleo · C = coroa · Q = quarentena · D = domain plugin · I = infra/support · S = split multi-órgão.

## D1. app/Services/Ai

| Bloco | Anel | Órgão | Ação residual |
|---|---|---|---|
| SelfConstruction | N | **N10** | Blueprint Readiness; projector/runtime |
| Aaeos | N+Q | **N10** + Q 132k | Re-home 1 classe Brain; purgar cemitério |
| Programming | N | **N9** | Absorve ProgrammingRuntime |
| SoftwareCompanyStewardship | N | **N10** fiscal | Keep; ≠ evolution |
| AutonomousEvolution | N+Q | **N10** Brain + Q órfãos | Archive residual; keep-list |
| Holding | D | Domain Holding | SPLIT monólito |
| Kernel | S | **N6** Decision + **N11** Evidence + **N11b** Linter | KernelTriad |
| MarketingDomain | D | Domain Marketing | Keep |
| AgenticEngineeringOs | N | **N10** (pós fuse AutonomousOs) | Split UniversalGates first |
| Rivals | D+Q | Domain Rivals + Q 1.0 | Kernel 2.0 keep |
| Context | N | **N3** | Absorve CI + PersistentContext |
| Finance | D | Domain Finance | Keep |
| Vox | C | Coroa Voice/Intent | Estágio 3 → N2/N7 |
| EngineeringKernel | N | **N10**/roles + N9 | Chão de roles; não fundir Programming |
| AcosMax | S | **N12**+**N3**+**N11** | FUSE-parcial Aemor/Context/Cognition |
| Hermes | N | **N7** | Transport verboso |
| Cognition | N | **N12** | ACOS mãe |
| SelfImprovement | N | **N12** | Closed-loop governança |
| Foundry | N+Q | **N9** spine + Q Frontier | IF → Foundry |
| Product | N | **N9** | Entrega software |
| Cli | C | Coroa CLI | Thin + famílias |
| AtlasDecide | N | **N6** | Q CapabilityMarket |
| Mobile | C | Coroa Mobile | Thin |
| Cognitive | N | **N12** | RENAME → Learning runtime |
| LongHorizon | D/N | TEOS advisory (N2/N9 edge) | Não muta; não é executor |
| Telemetry | N | **N11** | Métricas/custo |
| VentureFoundry | D | Domain Venture | RENAME colisão Foundry |
| WorkspaceIntelligence | N | **N3** | Superfície própria OK |
| Compounding | N | **N12** | Absorve Learning |
| Memory | N | **N4** | Absorve MemoryGovernance |
| RealExecution | N | **N9** | Absorve AVER |
| Publishing | D | Domain Editorial | Keep |
| Reality | N | **N3** graph | RENAME RealityGraph |
| Voice | C | Coroa Voice | LiveKit/TTS |
| Mission | N | **N9** | Hash → Support |
| ControlPlane | N | **N10**/cert agregador | ≠ SC ControlPlane |
| Obra | N | **N9** | Materialização |
| Domain | D | fuse → domains | Indireção |
| Governance | N | **N5** | admit() |
| RouterRuntime | N | **N2** | Motor flow |
| OperatorIntelligence | N | **N12** | Perfil operador |
| ProgrammingRuntime | N | **N9** | FUSE→Programming |
| Runtime | N | **N8** | Tools reais |
| Autonomy | N | **N10** | Ladder/auto-apply; dedupe Stewardship |
| Router | N | **N2** | FUSE→RouterRuntime |
| Organism | N | **N12** | propose-only |
| Aemor | N | **N12** | Outcome sink |
| Compression | N | **N3** | Lib genérica |
| RuntimeEfficiency | N | **N8** | Governor |
| Arena | N | **N11** | Medição |
| ToolRuntime | N | **N8** | FUSE→Runtime |
| Surface | C | Coroa Surface registry | Terminal-first adapters |
| Evidence | N | **N11** | Workflow ≠ ledger canônico |
| AutonomousEngineering | N | **N10** | FUSE AutonomousOs |
| AutomationDomain | D | Domain | Vigiar 1 fio |
| Cyber | D | Domain | Keep |
| RuntimeBoundary | N/C | **N8**/Python bridge | FFI |
| AgenticWorkcell | N | **N9** | Paralelismo |
| DomainRuntime | D | Domain infra | Seeders |
| Strategy | D | Domain | Keep |
| Policy | N | **N5** | Léxico |
| Skills | N | **N7** packs | Prompt skills |
| Patamar4 | N | Support/N ops | Keep vivo |
| Support | I | Support | Backbone |
| ValueObjects | I | Support/VOs | DTOs |
| NightShift | N | **N9** | Keep |
| ResearchDomain | D | Domain | Keep |
| ContextIntelligence | N | **N3** | FUSE→Context |
| Scheduling | N | **N9**/ops | Keep |
| AgentGovernance | C/N | Fleet-ops (reclass) | Não permission |
| AutonomousWorkExecution | N | **N9**/N10 | AWEOS |
| SelfDirectedEvolution | N | **N10** | Frontier inbox |
| StrategicReality | N | **N3**/sandbox | FUSE→RealitySandbox |
| Learning | N | **N12** | FUSE→Compounding |
| SpecialistFlows | Q | Q | → RouterRuntime depois |
| VerifiedExecution | N | **N9** | FUSE→RealExecution |
| Concerns | I | Support | Traits |
| OperatorApproval | N | **N5** | Dedup RISK |
| RealitySandbox | N | **N3** | Counterfactual |
| IntelligenceFactory | N | **N9** Foundry | FUSE |
| PersistentContext | N | **N3** | FUSE→Context |
| EngineeringCompany | N | **N9**/roster | Keep |
| StrategicOperatingSystem | Q/D | Q ou Strategy | Condicional |
| RuntimeReadiness | N | **N8** | Absorve ReleaseGate |
| Caching | N | **N7** cost-gov | RENAME |
| Analysis | N | **N11** | Juízes |
| PersonalDevelopment | D | Domain | Coaching |
| ConversationOps | N | **N1** | Thread/session |
| Teos | N | **N12** | Layering |
| Capture | C | Coroa Capture obs | ≠ mídia Attachments |
| CrossDomain | D | Domain mesh | Keep |
| Rsi | N | **N12** | Consolidar namespaces |
| Provider | N | **N7** | FUSE→Manager |
| AtlasForge | N | **N9** | FUSE vivos; KILL mortos |
| Attachments | C | Coroa mídia | → prompt |
| OpenBrain | N | **N3**/N12 edge | Latency + MCP collab |
| DualCore | N | **N2**/N1 boundary | route_decision |
| Reconciliation | N | **N4** | Absorve Cartography |
| Brain | N | **N10**/journal | ≠ AE Brain |
| Knowledge | N | **N4** | Ingestão |
| Compaction | N | **N7**/N3 | L2 semântico |
| CognitiveMemory | N | **N3**/N4 working set | Keep |
| Search | N | **N3** | Hot path tools |
| VerifiedContextExecution | N | **N8** | FUSE→RuntimeEfficiency |
| Cartography | N | **N4** | FUSE→Reconciliation |
| SoftwareCompany | N | **N10** Stewardship | FUSE ProductMode |
| Gateway | N | **N7** | Preflight |
| Operator | Q | Q | 0 prod callers |
| Transcription | C | Coroa áudio gate | Whisper |
| HumanSurface | N | **N2** | Ambiguidade |
| Forge | Q | KILL | rg=0 |
| Tasks | N | **N9** read-model | Keep |
| Mcp | N | **N3** coroa MCP | Tiers |
| Tokens | N | **N7** | Cost unit |
| RuntimeReleaseGate | N | **N8** | FUSE→Readiness |
| Streaming | N | **N7** | JSONL codex |
| Instrumentation | Q | Q→Telemetry | 1 consumer |
| MemoryGovernance | N | **N4** | FUSE→Memory |
| Security | N | **N5** edge | PromptInjection |
| (root singles Ai/*.php) | S | **N7** pipes + re-home | 5 canônicos na raiz |

## D2. non-Ai Services

| Bloco | Anel | Órgão | Ação |
|---|---|---|---|
| Engineering | N read-model | **N3** (+N11 pins) | CodeGraph/KB; reestruturar interno |
| AtlasCode | C | Coroa Code HTTP | Review surface; chama N9/N10 |
| Semantic | C/N4 | Human knowledge | Absorve Vault |
| Tools | N | **N8** agent tools | Distinto de Ai ToolRuntime até fuse consciente |
| MacAgent | C | Host adapter | Keep |
| Vault | C | → Semantic | FUSE parsers |
| Digital | C/D | Rize personal | Bounded context |
| Bitacula | C/D | Behavior catalog | Keep |
| root Services/* | C/D | Capture/Project/… | Keep com callers |

## D3. Fora de Services (FILESYSTEM-100)

| Fatia | Anel | Lei GOD |
|---|---|---|
| app/Console (~910–937) | C | Thin → façade N*; ≤12 famílias |
| app/Http | C | Controllers ≤800; zero domínio |
| app/Models (~407) | I | Slim; honesty rename se mentir |
| app/Jobs | C | Enqueue only |
| app/Providers | I | Bindings por órgão N |
| app/Support | I | Primários |
| app/Enums · Observers · Logging | I | Mínimo |
| tests Unit/Feature | Prova | Mirror ownership N*; 0 file >2000 |
| docs/ekb | Mapa | ≤2 hops; archive quarantine |
| docs/ap · superpowers · goals… | Mapa/Q | LEGADO vs VIVO |
| config/ esp. atlas.php 5k+ | I | Split ≤800 por domínio/órgão |
| routes/ | C | Por domínio de superfície |
| database/migrations | I | Schema; 0 god migration lógica |
| scripts/ god-debulk · bin/atlas | I/C | Tooling + launcher |
| bootstrap/ public/ resources | I | Mínimo |
| archive/ | Q | Fora PSR-4 runtime |
| runtimes/ node python swift | C boundary | Language boundaries doc |

---

# PARTE E — COROA (SUPERFÍCIES COMPLETAS)

## E1. Famílias CLI alvo (≤12)

| Família | Exemplos | Órgão |
|---|---|---|
| 1. `ask` / `dev` | conversa, cockpit | N1–N7 |
| 2. `brain` | next, seed, … | N10 |
| 3. `task` | next, report | N10 |
| 4. `memory` | recall, maintain | N4 |
| 5. `open-brain` / `context-pack` | MCP, pack, file-context | N3 |
| 6. `decide` | receipts | N6 |
| 7. `engineering` / `code` | knowledge, graph, review | N3/N9/N11 |
| 8. `obra` / `deliver` | delivery | N9 |
| 9. `evidence` / `arena` / `telemetry` | prova | N11 |
| 10. `domain` | finance, marketing, … | D plugins |
| 11. `ops` / `doctor` / `health` | runtime | N8/N1 |
| 12. `vault` / `capture` / personal | human/personal | C |

Todo command fora de família = dívida de superfície.

## E2. HTTP / Mobile / Voice / MCP

| Superfície | Responsabilidade | Proibido |
|---|---|---|
| HTTP AtlasCode | review, forge UX, boot | reimplementar muscle |
| HTTP AI | interactions, threads, telemetry | chamar provider direto |
| Mobile v1 | inbox, push, pairing, mac remote | domínio no controller |
| Voice | LiveKit, TTS, turn | intent sem N2 |
| MCP Open Brain | pack, recall, claim, write-back | raw secret; auto-merge main |

## E3. Jobs

Thin: `ProcessAudioTranscription`, `DeliverAtlasMissionJob`, `AtlasCodeForgeLiveExecutionJob`, …  
Lógica sempre em N*; job só orquestra timeout/retry.

---

# PARTE F — QUARENTENA E MORTE

## F1. Classes de Q

| Classe | Critério | Ação |
|---|---|---|
| ACDE órfão | rg vivo = 0; keep-list fora | archive/ |
| Aaeos cemitério | 93% bloco; 1 fio vivo | re-home + archive |
| Theater command | não executa runtime real | delete/archive |
| Peel 1–2 callers | <~80 LOC same concern | fuse host ou delete |
| Gêmea façade | 2 entrypoints mesma capability | 1 façade + alias ≤1 ciclo |
| Default-off | config off, 0 prod | freeze Q |
| rg=0 duplo | app+tests | KILL c/ OK operador |
| Rivals 1.0 | adapters legados | Q |
| SpecialistFlows | 0 instanciação prod | Q |
| Operator block | só testes AcosMax | Q |
| ToneFilter | 0 prod | kill/cabear |

## F2. Keep-list (intocável por prefixo)

Ver `docs/engineering-knowledge-base/atlas-autonomos-live-system.md` — ~26–27 `AtlasLoop*` **reusadas pelo vivo**.  
Sempre `rg --no-ignore -w Classe` antes de qualquer move.

## F3. Reentrada de Q → N/C

Checklist:

1. Callers produção ≥1 **ou** schedule wired  
2. OWNERSHIP row  
3. Órgão N\* definido  
4. Characterization  
5. Se FUSE/KILL: OK operador no mapa  

---

# PARTE G — FORMA DE CÓDIGO (CANON)

## G1. Grafo one-way

```text
C: Command | Controller | Job | MCP tool
  → N: Façade
    → Policy (0 I/O)
    → Projector (0 write)
    → Evaluator / Scanner (0 write de domínio)
    → Runtime (ÚNICO write; envelope: runtime_write_performed, ids, idempotency)
      → Gateway / ProviderPipe / DB / FS
```

**Proibido:** setMother · __call cross · Reflection capability · method_exists feature flag · back-ref Section→Mother · Domain→Console.

## G2. Sufixos e famílias

**Sufixos OK:** Facade · Runtime · Service · Policy · Projector · Scanner · Evaluator · Gateway · Command · Provider · ValueObject  

**Proibidos:** Manager · Helper · Handler · Util · Section monstro · Invoker cascata  

**Métodos:** `decide*` · `pack*Context` · `rank*` · `certify*`/`evaluate*` · `project*` (RO real) · `run*` (mutação) · `scan*` · `recall*` · `authorize*` · `record*` · `propose*`

## G3. Densidade

| Tipo | Alvo | Soft | Hard |
|---|---|---|---|
| Hot façade/command/controller | 150–600 | 800 | **>800 split** |
| Runtime/Service | 150–800 | 1500 | **>2000** |
| Policy/Projector | 50–400 | 800 | **>1500** |
| Data catalog | <1500/família | — | tabela de dados |
| config file | ≤800 | — | split |
| test file | ≤800 pref | — | **>2000** |

## G4. Defatoração elite (playbook de elevação)

Para cada fatia **já landada** e **não claimed**:

1. Listar entrypoints públicos  
2. Characterization se faltar  
3. Matriz: quem escreve? quem só lê? quem decide?  
4. Extrair Policy / Projector / Runtime se misturados  
5. Colapsar peels same-concern  
6. Delete wrappers  
7. Bound scans + fail-closed  
8. Honesty rename  
9. CODEMAP update  
10. Commit `refactor(core): GOD-DEBULK nucleus N* …`  

**Pergunta de fusão:** o órgão novo faz o que os dois antigos **não** faziam? Senão rejeita.

## G5. Anti-padrão invoker forest → catálogo

```text
ANTES: 40 classes *Invoker*GateInvoker
DEPOIS: 1 Policy table (decl) + 1 Runtime dispatcher + N small Gate Policy objects se invariante real
```

---

# PARTE H — CONFIG, DADOS, PROVIDERS

## H1. config split alvo

`config/atlas.php` monólito →:

```text
config/atlas/
  nucleus.php          # flags núcleo
  continuity.php
  context.php
  memory.php
  policy.php
  decide.php
  provider_pipe.php
  tools.php
  delivery.php
  muscle.php           # self-construction / brain / task
  evidence.php
  learning.php
  domains/*.php
  surfaces/*.php       # cli, mobile, voice, mcp
  aobg.php
```

Cada arquivo ≤800. Bindings em Providers apontam para órgãos N.

## H2. Models

- Slim Eloquent  
- Escrita de ledger **só** via N11 API  
- Privacy fields respeitados em N4/N12  
- 0 god model >2000  

## H3. Pipes canônicos na raiz Ai (transição)

Enquanto re-home:

1. `AiGatewayService`  
2. `AiWorker`  
3. `AiProviderManager`  
4. `AiPromptBuilder`  
5. `AiCompactionService`  

Resto dos ~90 root singles → pastas donas (blueprint RootSinglesRehome).

---

# PARTE I — BLUEPRINTS → ÓRGÃOS

| Blueprint | Órgãos | Entrega núcleo |
|---|---|---|
| SelfConstructionReadiness | N10 | Projector RO + Runtime writers; 0 setMother |
| RuntimeExecution | N8+N9 | Tool real governado + lineage delivery |
| ProviderPipeUnification | N7 | 1 registry + consult causal |
| KernelTriad | N6+N11 | Decision / Ledger / Linter |
| LearningConsolidation | N12 | 1 proposal path; Cognitive rename |
| QuarantineACDE | N10+Q | órfãos archive; keep-list safe |
| RootSinglesRehome | N7+… | raiz só pipes |
| EnterpriseExecutorUnification | N9+N10 | executores enterprise unificados |

Monstro >5k **sem** blueprint approved = só TEST/BUGFIX/DELETE/tooling — nunca arquitetura inventada.

---

# PARTE J — ONDAS DE CONVERGÊNCIA (PROGRAMA NÚCLEO)

| Onda | Nome | Órgãos | Trabalho | Bloqueio |
|---|---|---|---|---|
| **K0** | Design | — | este doc + mapa HTML + scoreboard | — |
| **K1** | Pipe único | N6 N7 | ProviderPipe + Decide no hot path | blueprint ProviderPipe |
| **K2** | Exec lineage | N8 N9 | Runtime+Tool+Real+AVER | RuntimeExecution |
| **K3** | Proof spine | N11 | KernelTriad + chain | KernelTriad |
| **K4** | Context+Memory | N3 N4 | FUSEs MEM-CTX | mapa |
| **K5** | Muscle | N10 | Readiness GOD; ACDE residual | blueprint SC; **claim livre** |
| **K6** | Learning | N12 | Compounding único; write-back | LearningConsolidation |
| **K7** | Coroa thin | C | CLI famílias; Http; config split | N estável |
| **K8** | Domains plugins | D | onboarding; Holding split | — |
| **K9** | Elevação contínua | todos | defator peels pós-split | permanente |

**Claude/Codex:** continuam EXEC findings A1.  
**Elevação (Grok):** K9 em paths frios + K0 manutenção deste doc.

---

# PARTE K — RELAÇÃO COM GOD-DEBULK E ARTEFATOS

| Artefato | Manda em | Subordinado a |
|---|---|---|
| **ATLAS-NUCLEUS-GOD-SOTA** | forma final, órgãos, anéis | operador accepted |
| INTENT 1–69 | eixos de qualidade | Nucleus no perímetro N |
| COMPLETE A–G | score 10/10 | Nucleus primeiro perímetro |
| FILESYSTEM-100 | inventário paths | anel N/C/Q |
| CONSOLIDATION-MAP | veredito por bloco | Órgão N* (Parte D) |
| ARCH-BLUEPRINTS | como mover monstro | Nucleus destino |
| META-FINDINGS | bugs/actions arquivo | EXECUTE |
| EXEC-DEBTS/LEDGER | fila implementador | comandante ARCH |
| OWNERSHIP.md | owners pastas | alinhar a N1–N12 |
| CODEMAP.md | Class::method | preencher por órgão |
| Autonomos live doc | keep-list | N10 |
| Knowledge governance | hierarquia verdade | N4/N11/N12 |
| Terminal-first | coroa | C + N11 review |

```text
Núcleo (destino)
  → Mapa consolidação (veredito bloco)
    → Blueprint (movimento seguro)
      → Finding (defeito arquivo)
        → Commit escopado main
          → Elevação §G4 (peels→órgão)
```

---

# PARTE L — CODEMAP NÚCLEO (ESTADO + ALVO)

| Concern | Hoje (parcial) | Alvo |
|---|---|---|
| Continuity session | ConversationOps/* | `ContinuityRuntime::ensureSession` |
| Intent | `AtlasAiIntentKernelService::classify` | `IntentDomainRuntime::classifyIntent` |
| Flow route | RouterRuntime/* | `IntentDomainRuntime::resolveFlowProfile` |
| Pack context | OpenBrain pack + AiContextPackBuilder | `ContextFabric::packContext` |
| File delta | OpenBrainFileContext | `ContextFabric::projectFileDelta` |
| Memory recall | HybridMemoryRetrieval | `MemoryCore::recall` |
| Memory record | MemoryRegistry | `MemoryCore::record` |
| Policy | AtlasAiPolicyService | `PolicyPlane::composeEffectivePolicy` |
| Authorize | AiPermissionEngine | `PolicyPlane::authorize` |
| Decide | AtlasDecideService | `AtlasDecide::decide` |
| Provider run | AiProviderManager / Gateway / Worker | `ProviderPipe::run` |
| Tool execute | AiToolRuntime | `ToolExecutionRuntime::execute` |
| Delivery | RealExecution kernel | `DeliveryRuntime::runDelivery` |
| Brain next/seed | commands + Brain services | `EngineeringMuscle::brainNext/Seed` |
| Task next/report | TaskServing | `EngineeringMuscle::taskNext/report` |
| Readiness project | ReadinessService (god) | `ReadinessProjector::project` (RO) |
| Evidence record | AtlasEvidenceLedger | `EvidenceLedger::record` |
| Arch scan | KernelArchitectureStaticScanner | `ArchitectureGovernance::scan` |
| Learning propose | Compounding + Learning | `LearningPlane::propose` |
| Write-back | OpenBrainWriteBack | `LearningPlane::recordOutcome` (gated) |

---

# PARTE M — TESTES E PROVA

## M1. Pirâmide por órgão

| Tipo | O quê | Quando |
|---|---|---|
| Characterization | entrypoint público, schema envelope | antes de split/fuse |
| Contract | Decision Receipt, ToolResult, pack hash | N6 N7 N3 N8 |
| Fail-closed | lease alheio, budget estourado, missing field | N5 N10 |
| Golden F0 | byte snapshot de report estável | blueprints |
| Smoke hot | ask/dev, context-pack, task report dry | `<5 min` |
| Guard | god-debulk-audit + density | pre-commit missão |

## M2. Test monsters

0 file tests >2000. Mirror pasta N* em `tests/Unit|Feature/Ai/...`.

---

# PARTE N — RISCOS E FALHAS

| Risco | Sintoma | Mitigação |
|---|---|---|
| Colisão multi-engine | commit alheio no stage | pathspec; claim EXEC-DEBTS |
| Split → peels | hops ↑ | elevação G4 obrigatória |
| Fuse por nome | quebra coesão | CONSOLIDATION evidence only |
| Scanner pin | split vermelho falso | migrar token no mesmo commit |
| Quarantine cedo | quebra Brain 1 fio | re-home first |
| Status mentiroso | read_only + write | Policy/Projector/Runtime |
| Vanity métrica | LOC↓ sem capacidade | axioma 1 + pergunta fusão |
| Dispatcher Autônomos | goal blocked | anti-trap GOD-DEBULK AGENTS |

---

# PARTE O — GLOSSÁRIO CURTO

| Termo | Significado |
|---|---|
| Núcleo / N* | 12 órgãos load-bearing |
| Coroa / C | adapters de superfície |
| Quarentena / Q | morto/dormente fora do operate path |
| Façade | única entrada pública de capability |
| Decision Receipt | contrato N6 pré-execução |
| Provider Pipe | N7 · um catálogo de motores |
| Engineering Muscle | N10 · Autônomos vivo |
| Elevação | defatorar split mecânico → órgão simples |
| Peel | arquivo fino sem invariante própria |
| Godfile | PHP >2000 (hard) ou hot >800 |
| Keep-list | AtlasLoop* vivos intocáveis por prefixo |
| Operate path | comandos/runtime que o sistema usa de verdade |

---

# PARTE P — OPERAÇÃO DESTA LANE

```text
LOOP até cancel do operador:
  1. git status + EXEC-DEBTS claimed_paths (não tocar hot)
  2. Escolher: (a) atualizar este doc se mapa mudou · (b) elevar órgão frio · (c) scoreboard
  3. Aplicar G4 + axiomas A1
  4. Testes do pacote
  5. Commit escopado docs(core)|refactor(core)|test(core): GOD-DEBULK nucleus …
  6. Atualizar Parte L CODEMAP se entrypoint mudou
```

**Halt:** cancel · claim alheio · monstro >5k sem blueprint approved.

---

# PARTE Q — IMAGEM FINAL

```text
                 ┌────────────── COROA ──────────────┐
        CLI thin · HTTP · Mobile · Voice · MCP · Jobs
                 └───────────────┬───────────────────┘
                                 │
    ┌────────────────────────────▼────────────────────────────┐
    │                    NÚCLEO (12)                           │
    │  N1 Continuity     N2 IntentDomain    N3 ContextFabric   │
    │  N4 MemoryCore     N5 PolicyPlane     N6 Decide          │
    │  N7 ProviderPipe   N8 ToolExecution   N9 Delivery        │
    │  N10 Muscle        N11 Evidence       N12 Learning       │
    │                                                          │
    │  + Domains/* plugins     + Support/*                     │
    └─────────────┬──────────────────────────┬────────────────┘
                  │                          │
         Postgres + pgvector          Providers (motores)
         Evidence ledger              Claude · Codex · Hermes …
         CodeGraph / KB
                  │
             archive/ (Q quieto)
```

**Poder máximo = superfície mínima de verdade + contratos honestos + prova contínua + manutenção por IA sem arqueologia.**

---

# PARTE R — CHANGELOG DO DESIGN

| Versão | Data | Mudança |
|---|---|---|
| design-v1 | 2026-07-23 | 12 órgãos · 3 anéis · ondas · anti-padrões iniciais |
| design-v2 | 2026-07-23 | Expansão completa: pipeline detalhado · 12 órgãos full · mapa 129 blocos · coroa CLI 12 famílias · config split · blueprints map · CODEMAP · testes · riscos · glossário · corpus fora de Services · domínios plugins · playbook elevação |

---

# PARTE S — CONFIRMAÇÃO

| Pedido | Como este doc atende |
|---|---|
| Abranger **tudo** sempre | Partes D + D3 + FILESYSTEM-100 |
| Núcleo **extremo** | 12 órgãos; resto C/Q/D/I |
| GOD/SOTA simples elevado | Axiomas A1 + G1–G4 |
| Completo o bastante para executar | Contratos API · done · ondas · mapa bloco→órgão |
| Não atrapalhar Codex/Claude | Parte P |

---

*Fim design-v2. Próxima revisão: quando CONSOLIDATION-MAP fechar closed em >50% das linhas FUSE/KILL ou quando N10 Readiness façade cruzar ≤800.*
