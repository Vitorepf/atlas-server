# CONSOLIDATION-MAP — atlas-server GOD Debulk

> Peça-mestra da lane ARQUITETURA. Uma linha por bloco. Vereditos: KEEP | FUSE→destino | QUARANTINE | KILL.
> Status: pending (sem análise) · proposed (veredito com evidência, aguarda operador p/ FUSE/KILL) · approved · closed.
> Cluster = hipótese de análise conjunta (gêmeos analisados JUNTOS); não é veredito.
> Universo: 119 blocos Ai + 8 non-Ai + 2 pseudo-blocos root = 129 linhas. Contador oficial: N/129.

## app/Services/Ai (119)

| Bloco | LOC | Files | Cluster | Veredito | Destino | Evidência | Status |
|---|---|---|---|---|---|---|---|
| SelfConstruction | 398524 | 1210 | ENG-CORE | KEEP (reestrutura interna) | — | blueprint SelfConstructionReadiness.md draft; MotherCommand ~1.100 rotas wired; 167 consumidores; A1-SC-0001..0043 | proposed |
| Aaeos | 142211 | 346 | ENG-OS-GEMEAS | KEEP (vivo ~10k) + QUARANTINE 132k | purga da Quarantine = obra separada | 93% do bloco é cemitério VERIFICADO (307 files/132.098 LOC); ⚠ CORREÇÃO ARCH: Brain vivo importa 1 classe da Quarantine (AtlasSourceConnectorsAndCaptureService) — re-home antes de purgar; não é gêmeo do Stewardship (dependência unidirecional, conceitos disjuntos) | proposed |
| Programming | 131892 | 543 | ENG-CORE | KEEP (absorve ProgrammingRuntime) | — | dono vivo: 229 arquivos ext, ~90 cmds, 2 providers; AtlasDev 46.8k/262 é o executor Dev; 0 consumo de AEOS/AutonomousEngineering VERIFICADO | recorded |
| SoftwareCompanyStewardship | 116399 | 280 | ENG-OS-GEMEAS | KEEP | — | dono do operate-path (29+ cmds, schedule wired, AreaFocus=249 files); steward que fiscaliza o Aaeos — substrato+steward, não gêmeas | recorded |
| AutonomousEvolution | 109588 | 639 | AUTONOMOS | KEEP vivo (297 files) + QUARANTINE órfãos (244 provados) | archive/ físico, reversível | CORRIGIDO pelo comandante: lista materializada em acde-orphans.csv — 244 órfãos/33.9k LOC (45%, não 61%); prova=0 refs VIVAS (todo cadáver tem teste-espelho); keep-list 27/27 na zona viva; 19 classes AAEL adjudicadas FORA (outro produto); blueprint QuarantineACDE.md | proposed |
| Holding | 44389 | 6 | DOMINIOS | KEEP + SPLIT | — | monólito 22.8k já com plano de split (Task 5 do plano-mestre); 10 ext | proposed |
| Kernel | 41474 | 159 | GOVERNANCA-QUALIDADE | KEEP + SPLIT em blueprint | 3 produtos: Decision-runtime · Evidence-ledger · Architecture-linter | guarda-chuva VERIFICADO: Architecture=26.031 LOC (63%) é linter AP1..AP141; AtlasEvidenceLedger 1.096 LOC/150 callers intocável; Decision 51 callers | proposed |
| MarketingDomain | 26853 | 180 | DOMINIOS | KEEP | — | backbone canônico: 29 ext, command + seeder→DomainRuntime | recorded |
| AgenticEngineeringOs | 26649 | 17 | ENG-CORE | QUARANTINE godfile + FUSE | AutonomousOs (com AutonomousEngineering), APÓS split do godfile | 81% do bloco = AtlasUniversalGatesEvaluator 21.6k com SÓ 3 callers ext VERIFICADO (3ª casa de gates, sub-vivo p/ o tamanho); bloqueador: split antes de fuse | proposed |
| Rivals | 20724 | 70 | DOMINIOS | KEEP kernel + QUARANTINE 1.0 | Adapters/External 2.176 LOC + NativeResultNormalizer | kernel Core/Support vivo (Arena agendada + atlas:rivals 2.0 gated + EngineeringKernel); ⚠ CORREÇÃO ARCH: NativeResultNormalizer tem TESTE (não é rg=0) → QUARANTINE, kill=classe+teste com OK do operador | proposed |
| Context | 20248 | 65 | MEMORIA-CONTEXTO | KEEP | — | espinha retrieval: 150 ext, 31 cmds; destino do re-home AcosMax em subnamespace Context\Retrieval | recorded |
| Finance | 17631 | 100 | DOMINIOS | KEEP | — | backbone: 24 ext, ~11 commands | recorded |
| Vox | 16103 | 39 | SUPERFICIES | KEEP | — | estágio 3 do pipeline de voz (intenção→execução): 50 ext, 6 cmds, 4 controllers | recorded |
| EngineeringKernel | 14293 | 110 | ENG-CORE | KEEP | — | chão pétreo: 136 arquivos ext (o mais consumido do cluster); fonte única de roles; NÃO fundir com Programming (2 chãos ortogonais) | recorded |
| AcosMax | 14223 | 51 | COGNICAO | FUSE-parcial | Aemor+Context+Cognition | grab-bag 51 files, 0 providers VERIFICADO; envelope→Aemor, embedding/RAGX→Context, cockpit/series→Cognition | proposed |
| Hermes | 13507 | 53 | TRANSPORTE | KEEP | — | 16 callers prod, 4 cmds, controller, driver no AiProviderManager; único transport verboo | recorded |
| Cognition | 13420 | 52 | COGNICAO | KEEP | — | 118 refs ext, 27 cmds; autoridade-mãe ACOS (scorecard+immune+gates) | recorded |
| SelfImprovement | 11333 | 22 | APRENDIZADO | KEEP | — | closed-loop de governança isolado: 66 arquivos ext, 0 cross-ref com Compounding, modelos próprios | recorded |
| Foundry | 11331 | 47 | FOUNDRY | KEEP spine + QUARANTINE Frontier | IF funde aqui (Capability Foundry) | spine 3.1k vivo (gap-finder invocado por Stewardship); Frontier 5.1k default-off VERIFICADO (frontier_mode config); Rsi/ sai p/ bloco Rsi | proposed |
| Product | 10211 | 34 | FOUNDRY | KEEP | — | 36 callers; injeção viva no AiInteractionController (mode=programming); fábrica de ENTREGA de software | recorded |
| Cli | 9618 | 30 | SUPERFICIES | KEEP | — | superfície L2 (investimento): 51 ext, 18 cmds, REPL | recorded |
| AtlasDecide | 9583 | 33 | ROTEAMENTO | KEEP + QUARANTINE parcial | sub-cluster CapabilityMarket→quarentena | único decisor de provider (LiveOutcomeFeedback=23 ext); CapabilityRouteLifecycle 0 refs app VERIFICADO | proposed |
| Mobile | 8925 | 22 | SUPERFICIES | KEEP | — | superfície irmã: 64 ext, 14 cmds, 10 controllers | recorded |
| Cognitive | 8848 | 65 | COGNICAO | KEEP + RENAME | Learning | cross-ref c/ Cognition = 0 VERIFICADO; 73 refs, 11 cmds; runtime de aprendizagem — colisão é de NOME | proposed |
| LongHorizon | 8678 | 23 | MISSAO | KEEP | — | camada TEOS advisory-only (0 decompositores, 0 mutação); 15 cmds, Canon=18 callers | recorded |
| Telemetry | 8622 | 35 | GOVERNANCA-QUALIDADE | KEEP | — | 56 refs, 10 cmds, 2 jobs agendados; dono das métricas/custo/drift | recorded |
| VentureFoundry | 8117 | 39 | FOUNDRY | KEEP + RENAME (colisão "Foundry") | — | heartbeat agendado VERIFICADO (console.php:153 atlas:venture review-cycle); domínio negócios, não código | proposed |
| WorkspaceIntelligence | 8059 | 17 | MEMORIA-CONTEXTO | KEEP | — | 43 ext, 4 controllers HTTP, 6 cmds; órgão vivo com superfície própria | recorded |
| Compounding | 7321 | 40 | APRENDIZADO | KEEP (núcleo, absorve Learning) | — | dono canônico de AiLearningProposal (service+applier); 103 callers, schedule wired | recorded |
| Memory | 7238 | 34 | MEMORIA-CONTEXTO | KEEP | — | 75 arquivos ext, 17 cmds, bindings; órgão dominante de memória durável | recorded |
| RealExecution | 6431 | 8 | EXEC-INFRA | KEEP | — | 22 callers ext, 5 cmds, job; núcleo de delivery patch→test→certify | recorded |
| Publishing | 6254 | 2 | DOMINIOS | KEEP | — | superfície mínima sadia (2 ext); blob editorial 5.5k é dado | recorded |
| Reality | 5620 | 7 | REALITY | KEEP + RENAME→RealityGraph | — | AURG grafo fundido memory+code+docs, 60 ext; Strategic/Sandbox tocam o grafo em 0 refs — cluster era falso positivo de nome | proposed |
| Voice | 5363 | 12 | SUPERFICIES | KEEP | — | estágio 1 (áudio realtime LiveKit/TTS): 5+ rotas HTTP vivas | recorded |
| Mission | 5115 | 25 | MISSAO | KEEP | — | ortogonal VERIFICADO (cross-ref domínio=0); 414/428 callers eram só MissionCanonicalHash → extrair p/ Support (proposed); piso "nunca executa provider" | proposed |
| ControlPlane | 4904 | 13 | CONTROL-PLANE | KEEP | — | agregador de certificação (13 callers na entrada); NÃO colide com SelfConstruction/ControlPlane (domínios disjuntos, colisão só de nome) | recorded |
| Obra | 4787 | 19 | MISSAO | KEEP | — | motor de materialização Forge; Obra→RealExecution 6 refs (caller, não callee); 9 cmds, seams no provider; handoff mission_type=obra→atlas_forge (linha 279 VERIFICADA) | recorded |
| Domain | 4695 | 22 | DOMINIOS | FUSE | camada-app dos 4 domínios que o chamam | 22 arquivos de indireção, só 4 entrypoints reais | proposed |
| Governance | 4687 | 16 | GOVERNANCA-QUALIDADE | KEEP | — | 113 refs (44 prod-Ai); kernel constitucional fail-closed + admit() como entrada única do stack | recorded |
| RouterRuntime | 3778 | 14 | ROTEAMENTO | KEEP | — | motor de flow: Canon=18, HyperflowEntry=16 callers ext; owner do "que flow atende" | recorded |
| OperatorIntelligence | 3665 | 19 | APRENDIZADO | KEEP | — | perfil-máquina do operador: 27 ext, controller, job, 5 cmds | recorded |
| ProgrammingRuntime | 3657 | 12 | ENG-CORE | FUSE | Programming (Programming/Runtime) | 10 callers ext re-pontáveis por import; 1 dono de "programação" em vez de 2 vizinhos | proposed |
| Runtime | 2879 | 14 | RUNTIME | KEEP | — | 12 callers ext + 2 cmds; executor REAL de tools (file/shell/git/test) | recorded |
| Autonomy | 2775 | 10 | AUTONOMOS | KEEP | — | toda classe ≥1 caller (auto-apply, ladder, digest); ⚠ flag: AutonomyLadderRuntimeService duplicado em Stewardship/AreaFocusLoop — investigar dedupe | recorded |
| Router | 2764 | 10 | ROTEAMENTO | FUSE | RouterRuntime | roteia FLOW não provider (0 imports AiProviderManager VERIFICADO); IntentKernel legado 0 ext callers; facade fica fina | proposed |
| Organism | 2647 | 24 | COGNICAO | KEEP | — | 12 refs ext, singleton provider; propose-only por construção | recorded |
| Aemor | 2444 | 5 | COGNICAO | KEEP | — | 41 refs ext, 14 cmds; sink de outcome Dev/Forge/Autônomos | recorded |
| Compression | 2327 | 14 | MEMORIA-CONTEXTO | KEEP | — | biblioteca genérica standalone; consumidor cross-domínio Engineering\CodeGraph VERIFICADO; fundir criaria dep espúria | recorded |
| RuntimeEfficiency | 2269 | 4 | RUNTIME | KEEP | — | 13 callers ext, binding provider, 5 cmds; governor path/custo | recorded |
| Arena | 2231 | 7 | GOVERNANCA-QUALIDADE | KEEP | — | medição viva: controller + drain command operados | recorded |
| ToolRuntime | 2080 | 15 | RUNTIME | FUSE | Runtime | mock governado (invocation "no external side effects" VERIFICADO); 2 callers; fusão = execução real GOVERNADA c/ receipt | proposed |
| Surface | 2079 | 15 | SUPERFICIES | KEEP | — | âncora terminal-first: registry de 11 adapters + capability-parity | recorded |
| Evidence | 2054 | 17 | GOVERNANCA-QUALIDADE | KEEP | — | workflow de certs/claims sobre Eloquent (25 refs); NÃO é o ledger canônico (esse mora em Kernel/Evidence) | recorded |
| AutonomousEngineering | 1897 | 4 | ENG-CORE | FUSE | AutonomousOs (com AEOS) | seam-partner VERIFICADO (AutonomousWorkExecutionOs produz envelope que AtlasAutonomousEngineeringService consome, L18); 8 callers ext; não é OS, é code-intelligence | proposed |
| AutomationDomain | 1869 | 16 | DOMINIOS | KEEP | — | vivo por 1 fio (command) — vigiar | recorded |
| Cyber | 1742 | 14 | DOMINIOS | KEEP | — | domínio canônico, fio único; sem overlap com Security | recorded |
| RuntimeBoundary | 1739 | 23 | RUNTIME | KEEP (recluster→GATEWAY-PYTHON) | — | 27 callers ext (Telemetry/Context/Semantic); ponte FFI Python — só compartilha o sufixo | recorded |
| AgenticWorkcell | 1699 | 3 | EXEC-INFRA | KEEP | — | 8 callers ext, binding WorkcellAdapter; concern paralelismo próprio | recorded |
| DomainRuntime | 1574 | 10 | DOMINIOS | KEEP | — | DONO da infra de domínio: 16 consumidores (todos os seeders) | recorded |
| Strategy | 1527 | 13 | DOMINIOS | KEEP | — | domínio canônico: 15 ext | recorded |
| Policy | 1474 | 10 | GOVERNANCA-QUALIDADE | KEEP | — | fonte de verdade do léxico (31 refs, 12 prod); base do stack | recorded |
| Skills | 1450 | 8 | SKILLS | KEEP | — | infra de skill packs: provider binding, 3 cmds, consumido por Hermes/Cli/Aemor/PromptBuilder | recorded |
| Patamar4 | 1428 | 6 | MISC | KEEP | — | obra recente 100% viva (SchedulerHealth=7) | recorded |
| Support | 1413 | 12 | MISC | KEEP | — | backbone: DatabaseTableAvailability=496 callers, normalizers 260/144 | recorded |
| ValueObjects | 1370 | 7 | MISC | KEEP | — | DTOs vivos (AiTaskRequest=17) | recorded |
| NightShift | 1348 | 2 | EXEC-INFRA | KEEP | — | 2 classes, ambas com 2 callers | recorded |
| ResearchDomain | 1291 | 10 | DOMINIOS | KEEP | — | 2 ext (command + ScoreCard) | recorded |
| ContextIntelligence | 1205 | 5 | MEMORIA-CONTEXTO | FUSE | Context (Context\Intelligence) | dependência 1-via VERIFICADA (só docblocks no reverso); ciclo construir→avaliar→certificar num dono | proposed |
| Scheduling | 1190 | 6 | EXEC-INFRA | KEEP | — | scheduler CLI coeso, todos os 6 vivos (StopConditionGate=3 via Hermes) | recorded |
| AgentGovernance | 1159 | 12 | GOVERNANCA-QUALIDADE | KEEP + RECLASSIFICAR (fleet-ops) | — | é a babá/reconciler de fleet (atlas:agents:on/off), 0 callers no pipeline de permissão — rótulo errado, não fusão | recorded |
| AutonomousWorkExecution | 1152 | 2 | AUTONOMOS | KEEP | — | AWEOS: 5 callers no Service incl. Hyperflow e VerifiedExecution | recorded |
| SelfDirectedEvolution | 1114 | 3 | AUTONOMOS | KEEP | — | frontier curation-inbox; 8 callers nos 2 principais (Foundry/Frontier, AreaFocusLoop, NightShift) | recorded |
| StrategicReality | 1080 | 2 | REALITY | FUSE-colocação | RealitySandbox (AutonomousReality) | RealitySandbox já injeta (L13/L48 VERIFICADO); manter classes/aliases — 5 entrypoints por classe | proposed |
| Learning | 1067 | 1 | APRENDIZADO | FUSE | Compounding | escreve AiLearningProposal DIRETO ignorando o serviço canônico (maybeGenerateProposal ~L797 VERIFICADO); review() duplica lifecycle; 4 callers; ⚠ SEQUÊNCIA: esta fusão libera o nome p/ RENAME Cognitive→Learning | proposed |
| SpecialistFlows | 1052 | 13 | SKILLS | QUARANTINE | — | routers com 0 instanciação em produção VERIFICADO (minha busca: 0 refs até em testes); substituto vivo = RouterRuntime; KILL após remover testes órfãos | proposed |
| VerifiedExecution | 1044 | 2 | EXEC-INFRA | FUSE | RealExecution | ledger paralelo VERIFICADO: 6 models AtlasAver* vs 8 AiRealExecution*; 9 callers; fusão = linhagem única de evidência | proposed |
| Concerns | 1027 | 3 | MISC | KEEP | — | traits vivos (RunsCliProcesses=10) | recorded |
| OperatorApproval | 1017 | 4 | GOVERNANCA-QUALIDADE | KEEP + dedup | importar PolicyCanon::RISK_LEVELS | RISK_LEVELS copiado VERIFICADO (3 files); camada acima da PolicyCanon por design | proposed |
| RealitySandbox | 1003 | 2 | REALITY | KEEP (absorve StrategicReality) | — | simulate/counterfactual/projectRisk; 5 callers vivos | recorded |
| IntelligenceFactory | 982 | 2 | FOUNDRY | FUSE | Foundry (Capability Foundry) | duplica capability_gap sob schema paralelo, 0 cross-import VERIFICADO; advise() vivo no Hyperflow L171 VERIFICADO — fusão = identidade única de gap servindo build/buy/borrow E auto-evolução | proposed |
| PersistentContext | 977 | 2 | MEMORIA-CONTEXTO | FUSE | Context (ContextRuntimeService) | falso amigo: é runtime de CONTEXTO; 12 ext; coeso mas mal-alocado | proposed |
| EngineeringCompany | 909 | 2 | ENG-OS-GEMEAS | KEEP | — | autoridade do roster 22 roles (EngineeringKernel::OFFICIAL_ROLES aponta aqui); 8 schemas DB + selo HMAC; teste do patamar FALHA para mover | recorded |
| StrategicOperatingSystem | 884 | 1 | DOMINIOS | QUARANTINE | fundir em Strategy se coberto | 1 fio, 0 reuso; condição: provar que Strategy cobre a função antes de retirar | proposed |
| RuntimeReadiness | 857 | 1 | RUNTIME | KEEP | — | 4 callers ext incl. HTTP controller próprio | recorded |
| Caching | 851 | 6 | TRANSPORTE | KEEP + RENAME sugerido (cost-governance) | — | CachingAiProvider vivo no AiProviderManager:229 VERIFICADO; é governança de custo, não cache | recorded |
| Analysis | 835 | 6 | UNITARIO | KEEP | — | painel de juízes coeso, 1 entrada de comando | recorded |
| PersonalDevelopment | 787 | 6 | APRENDIZADO | KEEP | — | domínio de coaching (DomainOrchestrator), não é modelo do operador; 7 ext | recorded |
| ConversationOps | 736 | 2 | SUPERFICIES | KEEP | — | health/handoff/janitor de conversa; 3 callers prod | recorded |
| Teos | 723 | 2 | COGNICAO | KEEP | — | 19 refs ext, 4 cmds; layering I3/I4 limpo | recorded |
| Capture | 719 | 2 | SUPERFICIES | KEEP | — | observabilidade do capture-inbox (não é mídia); 2 cmds | recorded |
| CrossDomain | 706 | 2 | DOMINIOS | KEEP | — | mesh inter-domínio, 15 ext | recorded |
| Rsi | 701 | 6 | COGNICAO | KEEP + consolidar | Rsi único (absorve Foundry/Rsi + AreaFocusLoop/Rsi) | 3 namespaces RSI VERIFICADOS; wired em AutonomousEvolutionSessionService | proposed |
| Provider | 675 | 10 | ROTEAMENTO | FUSE | AiProviderManager | registry paralela de drivers VERIFICADA (2 catálogos); drivers CLI 0 ext callers; fusão = catálogo único = pipe único | proposed |
| AtlasForge | 665 | 4 | FORGE | FUSE vivos + KILL mortos | Programming/Forge | coordinator(10 callers)+replay(2) movem; 2 classifiers rg=0 VERIFICADO DUPLO morrem; dissolve o dir | proposed |
| Attachments | 649 | 2 | SUPERFICIES | KEEP | — | ingestão real de mídia: 3 controllers + rotas + job + AiPromptBuilder | recorded |
| OpenBrain | 622 | 3 | TRANSPORTE | KEEP | — | latency ledger 7 callers prod + recall policy com caller prod re-provado | recorded |
| DualCore | 620 | 5 | COGNICAO | KEEP | — | 36 HTTP controllers VERIFICADO; boundary route_decision vivo | recorded |
| Reconciliation | 613 | 1 | MEMORIA-CONTEXTO | KEEP | — | runtime autônomo vivo (11 ext, 2 cmds); absorve Cartography | recorded |
| Brain | 591 | 3 | AUTONOMOS | KEEP | — | distinto de AE/Brain (namespaces disjuntos VERIFICADO): journal/diário de memória, 4-5 callers cada | recorded |
| Knowledge | 585 | 3 | MEMORIA-CONTEXTO | KEEP | — | dono canônico de ingestão, 7 ext; remover shim class_alias morto (VERIFICADO); QUARANTINE do fabric dormente em Context | proposed |
| Compaction | 492 | 4 | TRANSPORTE | KEEP | — | tier L2 semântico; AiCompactionService (L1) DEPENDE deste bloco; distinto de Compression | recorded |
| CognitiveMemory | 466 | 2 | MEMORIA-CONTEXTO | KEEP | — | working-set efêmero canônico, 5 ext; remover shim class_alias morto (VERIFICADO) | proposed |
| Search | 456 | 2 | MEMORIA-CONTEXTO | KEEP | — | micro mas load-bearing: hot path AiToolRuntime + AiPromptBuilder | recorded |
| VerifiedContextExecution | 442 | 1 | EXEC-INFRA | FUSE | RuntimeEfficiency | 2 callers; 100% das deps são do governor | proposed |
| Cartography | 388 | 1 | MEMORIA-CONTEXTO | FUSE | Reconciliation (DocCanonicalScanner) | owner de topo com 1 classe; scanner de canonicidade de docs; 5 ext | proposed |
| SoftwareCompany | 364 | 1 | ENG-OS-GEMEAS | FUSE | SoftwareCompanyStewardship/ProductMode | 1 arquivo VERIFICADO; projeção read-only; callers já são Stewardship+controller; zero-behavior-change | proposed |
| Gateway | 357 | 2 | ROTEAMENTO | KEEP | — | preflight com 5 callers ext; concern isolado | recorded |
| Operator | 323 | 4 | SUPERFICIES | QUARANTINE | — | 0 callers de produção VERIFICADO (só 4 testes AcosMax); morto vestido de teste verde | proposed |
| Transcription | 304 | 1 | SUPERFICIES | KEEP | — | estágio 2: gate fail-closed puro, 1 caller load-bearing (WhisperTranscriber) | recorded |
| HumanSurface | 290 | 1 | SUPERFICIES | KEEP | — | classificador de ambiguidade VIVO no IntentKernelService VERIFICADO | recorded |
| Forge | 287 | 2 | FORGE | KILL | — | 2 classes com rg=0 em app+tests VERIFICADO DUPLO (--no-ignore); Forge OS real mora em Programming/Forge | proposed |
| Tasks | 284 | 1 | EXEC-INFRA | KEEP | — | read-model fino, 1 caller | recorded |
| Mcp | 283 | 1 | TRANSPORTE | KEEP | (opcional: mover junto do AtlasOpenBrainMcpService) | registry de tiers, 3-4 callers prod VERIFICADO; não é 2º MCP | recorded |
| Tokens | 275 | 2 | TRANSPORTE | KEEP | — | primitivo de cost-unit consumido por AiProviderManager/Guard/AUCRI; limpar class_alias shim | recorded |
| RuntimeReleaseGate | 263 | 1 | RUNTIME | FUSE | RuntimeReadiness | wrapper puro — docblock "delega 100%" VERIFICADO; 2 callers ext | proposed |
| Streaming | 167 | 1 | TRANSPORTE | KEEP | — | parser JSONL do codex; 1 caller vivo (CodexCliProvider) | recorded |
| Instrumentation | 155 | 1 | GOVERNANCA-QUALIDADE | QUARANTINE | FUSE→Telemetry se tocar | 1 arquivo/155 LOC, 1 command único consumidor; rg≠0 → não KILL | proposed |
| MemoryGovernance | 148 | 3 | MEMORIA-CONTEXTO | FUSE | Memory (Memory\Governance) | consumidor único VERIFICADO (só AtlasMemoryGovernanceService); 3 scorers puros | proposed |
| Security | 55 | 1 | DOMINIOS | KEEP | — | util VIVO: PromptInjectionScanner com 2 callers em fronteira de confiança VERIFICADO — NÃO simplificar | recorded |
| (root singles Ai/*.php) | — | 95 | ROOT-SINGLES | RE-HOME (não é bloco — resíduo de migração) | ~90 files → owner-folders homônimos existentes; raiz fica com ~5 pipes canônicos (AiWorker, AiGatewayService, AiProviderManager, AiPromptBuilder, AiCompactionService) | 0 KILL; 1 QUARANTINE: ToneFilter dead-on-arrival (0 callers prod VERIFICADO — cabear ou remover c/ teste) | proposed |

## app/Services non-Ai (8) + root

| Bloco | LOC | Files | Cluster | Veredito | Destino | Evidência | Status |
|---|---|---|---|---|---|---|---|
| Engineering | 72133 | 144 | NON-AI-ENG | KEEP (re-estruturar interno) | subpastas DocumentationReality/ + Harness/ | espinha de code-intelligence do AOBG VERIFICADO (CodeGraph→Blackboard/Onboarding); 130 ext, 67 cmds, 183 tests | recorded |
| AtlasCode | 11288 | 33 | NON-AI-ENG | KEEP | — | superfície de produto: 21 controllers; camada distinta de Engineering (4↔3 cross-refs) | recorded |
| Semantic | 5385 | 19 | NON-AI | KEEP (recebe Vault) | — | 40% já é código de vault; unificar leitura Obsidian | recorded |
| Tools | 4309 | 14 | NON-AI | KEEP | — | tool-exec de agente; 0 cross-ref com Ai/ToolRuntime VERIFICADO (planos distintos) | recorded |
| MacAgent | 1686 | 1 | NON-AI | KEEP | — | host-agent Mac, 5 callers | recorded |
| Vault | 1446 | 7 | NON-AI | FUSE | Semantic | 1 caller externo VERIFICADO (CartographyController); frontmatter TRIPLICADO VERIFICADO (3 parsers); ganho: -2 parsers -1 stack I/O | proposed |
| Digital | 1235 | 7 | NON-AI | KEEP | — | bounded-context Rize isolado | recorded |
| Bitacula | 541 | 1 | NON-AI | KEEP | — | catálogo de behavior, 3 callers | recorded |
| (root singles Services/*.php) | — | 15 | ROOT-SINGLES | KEEP (fora do escopo Ai) | opcional: agrupar Capture/ e Bitacula/ | todos com callers de produção; domínio de negócio corretamente na raiz | recorded |

## Fases seguintes do universo (fora de app/Services — entram após blocos)

app/Console (937 commands) · app/Http · app/Models (407) · app/Jobs · app/Providers · app/Support · app/Enums · tests/ · config/ · routes/ · database/ · scripts/ — cobertos na fase 4 da ordem de varredura.
