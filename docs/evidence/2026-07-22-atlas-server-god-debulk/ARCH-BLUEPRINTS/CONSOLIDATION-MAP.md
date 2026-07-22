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
| Programming | 131892 | 543 | ENG-CORE | — | — | — | pending |
| SoftwareCompanyStewardship | 116399 | 280 | ENG-OS-GEMEAS | KEEP | — | dono do operate-path (29+ cmds, schedule wired, AreaFocus=249 files); steward que fiscaliza o Aaeos — substrato+steward, não gêmeas | recorded |
| AutonomousEvolution | 109588 | 639 | AUTONOMOS | — | — | — | pending |
| Holding | 44389 | 6 | DOMINIOS | — | — | — | pending |
| Kernel | 41474 | 159 | GOVERNANCA-QUALIDADE | — | — | — | pending |
| MarketingDomain | 26853 | 180 | DOMINIOS | — | — | — | pending |
| AgenticEngineeringOs | 26649 | 17 | ENG-CORE | — | — | — | pending |
| Rivals | 20724 | 70 | DOMINIOS | — | — | — | pending |
| Context | 20248 | 65 | MEMORIA-CONTEXTO | KEEP | — | espinha retrieval: 150 ext, 31 cmds; destino do re-home AcosMax em subnamespace Context\Retrieval | recorded |
| Finance | 17631 | 100 | DOMINIOS | — | — | — | pending |
| Vox | 16103 | 39 | SUPERFICIES | — | — | — | pending |
| EngineeringKernel | 14293 | 110 | ENG-CORE | — | — | — | pending |
| AcosMax | 14223 | 51 | COGNICAO | FUSE-parcial | Aemor+Context+Cognition | grab-bag 51 files, 0 providers VERIFICADO; envelope→Aemor, embedding/RAGX→Context, cockpit/series→Cognition | proposed |
| Hermes | 13507 | 53 | TRANSPORTE | — | — | — | pending |
| Cognition | 13420 | 52 | COGNICAO | KEEP | — | 118 refs ext, 27 cmds; autoridade-mãe ACOS (scorecard+immune+gates) | recorded |
| SelfImprovement | 11333 | 22 | APRENDIZADO | — | — | — | pending |
| Foundry | 11331 | 47 | FOUNDRY | KEEP spine + QUARANTINE Frontier | IF funde aqui (Capability Foundry) | spine 3.1k vivo (gap-finder invocado por Stewardship); Frontier 5.1k default-off VERIFICADO (frontier_mode config); Rsi/ sai p/ bloco Rsi | proposed |
| Product | 10211 | 34 | FOUNDRY | KEEP | — | 36 callers; injeção viva no AiInteractionController (mode=programming); fábrica de ENTREGA de software | recorded |
| Cli | 9618 | 30 | SUPERFICIES | — | — | — | pending |
| AtlasDecide | 9583 | 33 | ROTEAMENTO | KEEP + QUARANTINE parcial | sub-cluster CapabilityMarket→quarentena | único decisor de provider (LiveOutcomeFeedback=23 ext); CapabilityRouteLifecycle 0 refs app VERIFICADO | proposed |
| Mobile | 8925 | 22 | SUPERFICIES | — | — | — | pending |
| Cognitive | 8848 | 65 | COGNICAO | KEEP + RENAME | Learning | cross-ref c/ Cognition = 0 VERIFICADO; 73 refs, 11 cmds; runtime de aprendizagem — colisão é de NOME | proposed |
| LongHorizon | 8678 | 23 | MISSAO | KEEP | — | camada TEOS advisory-only (0 decompositores, 0 mutação); 15 cmds, Canon=18 callers | recorded |
| Telemetry | 8622 | 35 | GOVERNANCA-QUALIDADE | — | — | — | pending |
| VentureFoundry | 8117 | 39 | FOUNDRY | KEEP + RENAME (colisão "Foundry") | — | heartbeat agendado VERIFICADO (console.php:153 atlas:venture review-cycle); domínio negócios, não código | proposed |
| WorkspaceIntelligence | 8059 | 17 | MEMORIA-CONTEXTO | KEEP | — | 43 ext, 4 controllers HTTP, 6 cmds; órgão vivo com superfície própria | recorded |
| Compounding | 7321 | 40 | APRENDIZADO | — | — | — | pending |
| Memory | 7238 | 34 | MEMORIA-CONTEXTO | KEEP | — | 75 arquivos ext, 17 cmds, bindings; órgão dominante de memória durável | recorded |
| RealExecution | 6431 | 8 | EXEC-INFRA | KEEP | — | 22 callers ext, 5 cmds, job; núcleo de delivery patch→test→certify | recorded |
| Publishing | 6254 | 2 | DOMINIOS | — | — | — | pending |
| Reality | 5620 | 7 | REALITY | — | — | — | pending |
| Voice | 5363 | 12 | SUPERFICIES | — | — | — | pending |
| Mission | 5115 | 25 | MISSAO | KEEP | — | ortogonal VERIFICADO (cross-ref domínio=0); 414/428 callers eram só MissionCanonicalHash → extrair p/ Support (proposed); piso "nunca executa provider" | proposed |
| ControlPlane | 4904 | 13 | CONTROL-PLANE | — | — | — | pending |
| Obra | 4787 | 19 | MISSAO | KEEP | — | motor de materialização Forge; Obra→RealExecution 6 refs (caller, não callee); 9 cmds, seams no provider; handoff mission_type=obra→atlas_forge (linha 279 VERIFICADA) | recorded |
| Domain | 4695 | 22 | DOMINIOS | — | — | — | pending |
| Governance | 4687 | 16 | GOVERNANCA-QUALIDADE | — | — | — | pending |
| RouterRuntime | 3778 | 14 | ROTEAMENTO | KEEP | — | motor de flow: Canon=18, HyperflowEntry=16 callers ext; owner do "que flow atende" | recorded |
| OperatorIntelligence | 3665 | 19 | APRENDIZADO | — | — | — | pending |
| ProgrammingRuntime | 3657 | 12 | ENG-CORE | — | — | — | pending |
| Runtime | 2879 | 14 | RUNTIME | KEEP | — | 12 callers ext + 2 cmds; executor REAL de tools (file/shell/git/test) | recorded |
| Autonomy | 2775 | 10 | AUTONOMOS | — | — | — | pending |
| Router | 2764 | 10 | ROTEAMENTO | FUSE | RouterRuntime | roteia FLOW não provider (0 imports AiProviderManager VERIFICADO); IntentKernel legado 0 ext callers; facade fica fina | proposed |
| Organism | 2647 | 24 | COGNICAO | KEEP | — | 12 refs ext, singleton provider; propose-only por construção | recorded |
| Aemor | 2444 | 5 | COGNICAO | KEEP | — | 41 refs ext, 14 cmds; sink de outcome Dev/Forge/Autônomos | recorded |
| Compression | 2327 | 14 | MEMORIA-CONTEXTO | KEEP | — | biblioteca genérica standalone; consumidor cross-domínio Engineering\CodeGraph VERIFICADO; fundir criaria dep espúria | recorded |
| RuntimeEfficiency | 2269 | 4 | RUNTIME | KEEP | — | 13 callers ext, binding provider, 5 cmds; governor path/custo | recorded |
| Arena | 2231 | 7 | GOVERNANCA-QUALIDADE | — | — | — | pending |
| ToolRuntime | 2080 | 15 | RUNTIME | FUSE | Runtime | mock governado (invocation "no external side effects" VERIFICADO); 2 callers; fusão = execução real GOVERNADA c/ receipt | proposed |
| Surface | 2079 | 15 | SUPERFICIES | — | — | — | pending |
| Evidence | 2054 | 17 | GOVERNANCA-QUALIDADE | — | — | — | pending |
| AutonomousEngineering | 1897 | 4 | ENG-CORE | — | — | — | pending |
| AutomationDomain | 1869 | 16 | DOMINIOS | — | — | — | pending |
| Cyber | 1742 | 14 | DOMINIOS | — | — | — | pending |
| RuntimeBoundary | 1739 | 23 | RUNTIME | KEEP (recluster→GATEWAY-PYTHON) | — | 27 callers ext (Telemetry/Context/Semantic); ponte FFI Python — só compartilha o sufixo | recorded |
| AgenticWorkcell | 1699 | 3 | EXEC-INFRA | KEEP | — | 8 callers ext, binding WorkcellAdapter; concern paralelismo próprio | recorded |
| DomainRuntime | 1574 | 10 | DOMINIOS | — | — | — | pending |
| Strategy | 1527 | 13 | DOMINIOS | — | — | — | pending |
| Policy | 1474 | 10 | GOVERNANCA-QUALIDADE | — | — | — | pending |
| Skills | 1450 | 8 | SKILLS | — | — | — | pending |
| Patamar4 | 1428 | 6 | MISC | — | — | — | pending |
| Support | 1413 | 12 | MISC | — | — | — | pending |
| ValueObjects | 1370 | 7 | MISC | — | — | — | pending |
| NightShift | 1348 | 2 | EXEC-INFRA | — | — | — | pending |
| ResearchDomain | 1291 | 10 | DOMINIOS | — | — | — | pending |
| ContextIntelligence | 1205 | 5 | MEMORIA-CONTEXTO | FUSE | Context (Context\Intelligence) | dependência 1-via VERIFICADA (só docblocks no reverso); ciclo construir→avaliar→certificar num dono | proposed |
| Scheduling | 1190 | 6 | EXEC-INFRA | — | — | — | pending |
| AgentGovernance | 1159 | 12 | GOVERNANCA-QUALIDADE | — | — | — | pending |
| AutonomousWorkExecution | 1152 | 2 | AUTONOMOS | — | — | — | pending |
| SelfDirectedEvolution | 1114 | 3 | AUTONOMOS | — | — | — | pending |
| StrategicReality | 1080 | 2 | REALITY | — | — | — | pending |
| Learning | 1067 | 1 | APRENDIZADO | — | — | — | pending |
| SpecialistFlows | 1052 | 13 | SKILLS | — | — | — | pending |
| VerifiedExecution | 1044 | 2 | EXEC-INFRA | FUSE | RealExecution | ledger paralelo VERIFICADO: 6 models AtlasAver* vs 8 AiRealExecution*; 9 callers; fusão = linhagem única de evidência | proposed |
| Concerns | 1027 | 3 | MISC | — | — | — | pending |
| OperatorApproval | 1017 | 4 | GOVERNANCA-QUALIDADE | — | — | — | pending |
| RealitySandbox | 1003 | 2 | REALITY | — | — | — | pending |
| IntelligenceFactory | 982 | 2 | FOUNDRY | FUSE | Foundry (Capability Foundry) | duplica capability_gap sob schema paralelo, 0 cross-import VERIFICADO; advise() vivo no Hyperflow L171 VERIFICADO — fusão = identidade única de gap servindo build/buy/borrow E auto-evolução | proposed |
| PersistentContext | 977 | 2 | MEMORIA-CONTEXTO | FUSE | Context (ContextRuntimeService) | falso amigo: é runtime de CONTEXTO; 12 ext; coeso mas mal-alocado | proposed |
| EngineeringCompany | 909 | 2 | ENG-OS-GEMEAS | KEEP | — | autoridade do roster 22 roles (EngineeringKernel::OFFICIAL_ROLES aponta aqui); 8 schemas DB + selo HMAC; teste do patamar FALHA para mover | recorded |
| StrategicOperatingSystem | 884 | 1 | DOMINIOS | — | — | — | pending |
| RuntimeReadiness | 857 | 1 | RUNTIME | KEEP | — | 4 callers ext incl. HTTP controller próprio | recorded |
| Caching | 851 | 6 | TRANSPORTE | — | — | — | pending |
| Analysis | 835 | 6 | UNITARIO | — | — | — | pending |
| PersonalDevelopment | 787 | 6 | APRENDIZADO | — | — | — | pending |
| ConversationOps | 736 | 2 | SUPERFICIES | — | — | — | pending |
| Teos | 723 | 2 | COGNICAO | KEEP | — | 19 refs ext, 4 cmds; layering I3/I4 limpo | recorded |
| Capture | 719 | 2 | SUPERFICIES | — | — | — | pending |
| CrossDomain | 706 | 2 | DOMINIOS | — | — | — | pending |
| Rsi | 701 | 6 | COGNICAO | KEEP + consolidar | Rsi único (absorve Foundry/Rsi + AreaFocusLoop/Rsi) | 3 namespaces RSI VERIFICADOS; wired em AutonomousEvolutionSessionService | proposed |
| Provider | 675 | 10 | ROTEAMENTO | FUSE | AiProviderManager | registry paralela de drivers VERIFICADA (2 catálogos); drivers CLI 0 ext callers; fusão = catálogo único = pipe único | proposed |
| AtlasForge | 665 | 4 | FORGE | — | — | — | pending |
| Attachments | 649 | 2 | SUPERFICIES | — | — | — | pending |
| OpenBrain | 622 | 3 | TRANSPORTE | — | — | — | pending |
| DualCore | 620 | 5 | COGNICAO | KEEP | — | 36 HTTP controllers VERIFICADO; boundary route_decision vivo | recorded |
| Reconciliation | 613 | 1 | MEMORIA-CONTEXTO | KEEP | — | runtime autônomo vivo (11 ext, 2 cmds); absorve Cartography | recorded |
| Brain | 591 | 3 | AUTONOMOS | — | — | — | pending |
| Knowledge | 585 | 3 | MEMORIA-CONTEXTO | KEEP | — | dono canônico de ingestão, 7 ext; remover shim class_alias morto (VERIFICADO); QUARANTINE do fabric dormente em Context | proposed |
| Compaction | 492 | 4 | TRANSPORTE | — | — | — | pending |
| CognitiveMemory | 466 | 2 | MEMORIA-CONTEXTO | KEEP | — | working-set efêmero canônico, 5 ext; remover shim class_alias morto (VERIFICADO) | proposed |
| Search | 456 | 2 | MEMORIA-CONTEXTO | KEEP | — | micro mas load-bearing: hot path AiToolRuntime + AiPromptBuilder | recorded |
| VerifiedContextExecution | 442 | 1 | EXEC-INFRA | FUSE | RuntimeEfficiency | 2 callers; 100% das deps são do governor | proposed |
| Cartography | 388 | 1 | MEMORIA-CONTEXTO | FUSE | Reconciliation (DocCanonicalScanner) | owner de topo com 1 classe; scanner de canonicidade de docs; 5 ext | proposed |
| SoftwareCompany | 364 | 1 | ENG-OS-GEMEAS | FUSE | SoftwareCompanyStewardship/ProductMode | 1 arquivo VERIFICADO; projeção read-only; callers já são Stewardship+controller; zero-behavior-change | proposed |
| Gateway | 357 | 2 | ROTEAMENTO | KEEP | — | preflight com 5 callers ext; concern isolado | recorded |
| Operator | 323 | 4 | SUPERFICIES | — | — | — | pending |
| Transcription | 304 | 1 | SUPERFICIES | — | — | — | pending |
| HumanSurface | 290 | 1 | SUPERFICIES | — | — | — | pending |
| Forge | 287 | 2 | FORGE | — | — | — | pending |
| Tasks | 284 | 1 | EXEC-INFRA | — | — | — | pending |
| Mcp | 283 | 1 | TRANSPORTE | — | — | — | pending |
| Tokens | 275 | 2 | TRANSPORTE | — | — | — | pending |
| RuntimeReleaseGate | 263 | 1 | RUNTIME | FUSE | RuntimeReadiness | wrapper puro — docblock "delega 100%" VERIFICADO; 2 callers ext | proposed |
| Streaming | 167 | 1 | TRANSPORTE | — | — | — | pending |
| Instrumentation | 155 | 1 | GOVERNANCA-QUALIDADE | — | — | — | pending |
| MemoryGovernance | 148 | 3 | MEMORIA-CONTEXTO | FUSE | Memory (Memory\Governance) | consumidor único VERIFICADO (só AtlasMemoryGovernanceService); 3 scorers puros | proposed |
| Security | 55 | 1 | DOMINIOS | — | — | — | pending |
| (root singles Ai/*.php) | — | 95 | ROOT-SINGLES | — | — | — | pending |

## app/Services non-Ai (8) + root

| Bloco | LOC | Files | Cluster | Veredito | Destino | Evidência | Status |
|---|---|---|---|---|---|---|---|
| Engineering | 72133 | 144 | NON-AI-ENG | — | — | — | pending |
| AtlasCode | 11288 | 33 | NON-AI-ENG | — | — | — | pending |
| Semantic | 5385 | 19 | NON-AI | — | — | — | pending |
| Tools | 4309 | 14 | NON-AI | — | — | — | pending |
| MacAgent | 1686 | 1 | NON-AI | — | — | — | pending |
| Vault | 1446 | 7 | NON-AI | — | — | — | pending |
| Digital | 1235 | 7 | NON-AI | — | — | — | pending |
| Bitacula | 541 | 1 | NON-AI | — | — | — | pending |
| (root singles Services/*.php) | — | 15 | ROOT-SINGLES | — | — | — | pending |

## Fases seguintes do universo (fora de app/Services — entram após blocos)

app/Console (937 commands) · app/Http · app/Models (407) · app/Jobs · app/Providers · app/Support · app/Enums · tests/ · config/ · routes/ · database/ · scripts/ — cobertos na fase 4 da ordem de varredura.
