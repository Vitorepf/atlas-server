---
id: atlas-canonical-glossary-and-naming
type: engineering_knowledge
title: Atlas Canonical Glossary And Naming
status: active
category: documentation
priority: 99
summary: Fonte unica de nomes, definicoes, aliases permitidos/proibidos e regras de uso para Atlas, Atlas AI, Atlas Dev, Atlas Forge, Atlas Code, Obra, Mission, WorkOrder, Work Packet, Domain, Flow, Specialist Flow, Company Runtime, Runtime, Harness, Tool Runtime, Provider, Agent, Router, Kernel, Intent, Context Pack, RAG Gate, Evidence, Receipt, Certification, Readiness, Compounding, Memory, World Model e Control Plane. Impede que docs futuras parecam runtime atual, que naming proliferation crie duplicacao de servicos e que IAs implementem no lugar errado.
human_summary: Dicionario oficial dos nomes do Atlas para impedir sinonimos confusos, duplicacao e implementacao no lugar errado.
human_what: Glossario canonico de produtos, runtimes, aliases, termos permitidos, termos proibidos e relacoes.
human_purpose: Fazer IA e humano usarem o mesmo nome para a mesma coisa, sem criar sistemas paralelos por confusao.
human_input: Recebe termos novos, termos legados, nomes tecnicos, nomes humanos, aliases e conflitos de nomenclatura.
human_output: Entrega nome permitido, nome proibido, substituto canonico e regra de uso para docs e codigo.
human_change_when: Mexa antes de promover novo nome canonico ou quando uma auditoria achar proliferacao de nomes.
human_block_when: Bloqueie quando uma IA criar novo termo sem glossario, inverter relacao Dev/Forge ou tratar visao futura como runtime pronto.
tags:
  - atlas
  - glossary
  - naming
  - governance
  - documentation
capabilities:
  - canonical_glossary
  - naming_governance
  - dev_forge_disambiguation
  - domain_flow_runtime_harness_disambiguation
  - prohibited_naming_registry
  - ai_implementation_guard
decisions:
  - Atlas possui UMA fonte de nomes; conflitos resolvem-se aqui.
  - Atlas Dev e Atlas Forge sao nucleos paralelos, nunca pai/filho nem sub-flows um do outro.
  - Domain, Flow, Runtime e Harness sao conceitos disjuntos; usar como sinonimos quebra arquitetura.
  - Patamar, versao, camada e fonte sao conceitos separados; Cartografia e IA nunca podem tratar um como sinonimo do outro.
  - Aliases legados sao tolerados, mas nao podem nascer novos.
  - Doc de visao futura NUNCA deve ser interpretado como runtime atual sem checar `graph_status`/`status`.
  - Toda camada macro estrutural deve declarar Nome canonico/produto, Acronimo tecnico, Nome interno de experiencia/superficie e Runtime tecnico; omitir qualquer um e violacao de naming.
maintenance:
  - Atualize este doc antes de promover um termo novo a nivel canonico (P0/P1).
  - Nao apagar termo legado; mover para secao "Nomes Proibidos / Deprecados" com substituto.
  - Quando audit identificar novo cluster de naming proliferation, abrir entrada aqui antes de qualquer cleanup de codigo.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-cleanup-inventory.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-domain-company-runtimes.md
  - docs/engineering-knowledge-base/domains/domain-routing-governance.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-dev-forge-relationship-critical-audit.md
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-canonical-module-doc-v1.md
  - docs/engineering-knowledge-base/atlas-cartography-nomenclature-contract.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming-teos-long-horizon.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-canonical-glossary-and-naming
graph_title: Atlas Canonical Glossary And Naming
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-ai-canonical-architecture-index
graph_status: active
graph_source: repo
human_name: Atlas Canonical Glossary And Naming
canonical_name: Atlas Canonical Glossary And Naming
technical_name: atlas-canonical-glossary-and-naming
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
owner: documentation-operating-system
repo_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
allowed_changes:
  - Adicionar termo novo com definicao, aliases, regras e exemplos.
  - Mover termo legado para a secao "Nomes Proibidos / Deprecados" sem apagar.
  - Refinar regras Dev vs Forge ou Domain/Flow/Runtime/Harness conforme novos contratos canonicos.
forbidden_changes:
  - Apagar termo canonico ja referenciado por outra doc.
  - Inverter relacao Atlas Dev <-> Atlas Forge (nunca pai/filho).
  - Promover doc de visao futura como runtime atual sem checar status.
  - Tratar versao, release, schema, camada ou arquivo-fonte como patamar canonico.
  - Tratar Atlas Vox V0/V3/V4/V6 como patamares canonicos fora da Escada Vox.
depends_on:
  - atlas-canonical-cleanup-inventory
  - atlas-ai-canonical-architecture-index
  - atlas-dual-core-engineering-system
  - atlas-dev-forge-relationship-critical-audit
flows_to:
  - atlas-ai-documentation-operating-system
  - atlas-cartography
unlocks:
  - ai-safe-naming-resolution
  - prohibition-of-runtime-from-future-doc
governs:
  - atlas.glossary
  - atlas.naming
evidence:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
ai_entrypoints:
  - Leia Termos Canonicos antes de criar classe/servico novo cujo nome bata com algum termo registrado.
  - Leia "Dev vs Forge" antes de mover codigo entre AtlasDev e AtlasForge.
  - Leia "Domain vs Flow vs Runtime vs Harness" antes de criar diretorio ou namespace novo.
  - Leia "Patamar vs Versao vs Camada vs Fonte" antes de preencher Cartografia, modal, graph_layer, versao ou relacao de maturidade.
  - Leia "Regras para IA" antes de propor doc com `status: active`.
ai_usage_notes:
  - Este doc nao executa; valida nomenclatura. Use junto com `atlas-ai-canonical-architecture-index.md` para resolver autoridade.
quality_gates:
  - docs-health-passes
  - new-term-cites-source
  - prohibited-alias-respected
failure_modes:
  - Termo novo criado sem entrada aqui — risco alto de duplicacao downstream.
  - Termo legado renomeado sem deixar alias — quebra links e provedor projection.
  - Doc de visao tratada como runtime atual — IA implementa contra contrato planned.
  - IA confunde patamar com versao/camada/fonte — Cartografia passa a mentir sobre maturidade real.
  - Atlas Vox V4/V6 tratado como patamar canonico — IA mistura escada Vox com patamar do Atlas inteiro.
observability_signals:
  - docs-health-status
  - canonical-glossary-coverage
next_actions:
  - Quando novo termo entrar, adicionar entrada ANTES de criar codigo correspondente.
  - Quando audit apontar novo cluster de naming, registrar aqui na secao "Nomes Proibidos / Deprecados" ou "Aliases Tolerados".
line_limit: 560
---
# Atlas Canonical Glossary And Naming
## Resumo
Fonte unica de nomes do Atlas. 30 termos canonicos com definicao, regras de
uso e aliases. Tres secoes especiais: Dev vs Forge, Domain/Flow/Runtime/
Harness, Nomes Proibidos. Toda IA futura DEVE consultar antes de criar
classe, servico, namespace ou doc.
## Papel no Atlas
Atlas cresceu rapidamente; auditorias identificaram naming proliferation
(8 nomes em torno de "Forge", 5 de "Router", 45 services com "Certification")
e risco de futuras IAs (1) implementarem no lugar errado, (2) duplicarem
servicos, (3) tratarem doc de visao como runtime atual. Este glossario
elimina ambiguidade SEM apagar nenhuma doc/codigo.
## Onde Se Encaixa
Camada `Layer 0.5` — documentation operating system. Fica entre o `Canonical
Architecture Index` (autoridade entre layers/docs) e os docs canonicos por
modulo. Conflitos sobre QUAL doc decide um conceito sobem ao Index; conflitos
sobre QUAL nome usa um conceito ficam aqui.
## Contratos
- Toda nova classe/servico/namespace cujo nome bata com termo desta tabela exige citacao do termo no PR.
- Toda camada macro nova DEVE declarar `macro_layer: true` e quatro nomes:
  `product_name` (nome canonico/produto), `runtime_acronym` (acronimo tecnico),
  `internal_product_name` (nome interno de experiencia/superficie) e
  `technical_runtime` (nome tecnico de implementacao/runtime). Docs-health
  bloqueia macro marcada sem os quatro campos.
- Todo alias proibido NAO pode aparecer em codigo, doc canonica nova ou
  resposta final de IA — apenas em arquivo legado preservado.
- Toda doc com `status: active` que use termo desta tabela deve respeitar
  a definicao canonica.
## Fluxo
```text
Nome aparece em roadmap / chat / doc / classe nova
-> Existe termo canonico aqui? -> Sim: usar exatamente como definido.
                                  Nao: propor entrada antes de codificar.
-> Esta em "Nomes Proibidos"? -> Sim: usar substituto.
-> Conflita com Domain/Flow/Runtime/Harness? -> resolver pela tabela.
```
## Patamar vs Versao vs Camada vs Fonte

Contrato detalhado: `atlas-cartography-nomenclature-contract.md`.

Resumo obrigatorio:

- Patamar = salto de capacidade/maturidade.
- Versao = revisao, schema, fase ou release da mesma superficie/contrato.
- Camada = localizacao visual/conceitual no grafo.
- Fonte = arquivo canonico onde a verdade vive.
- `Self-Construction OS` -> `Self-Programming OS` e patamar.
- `Atlas Vox V0/V3/V4/V6` sao versoes/degraus da Escada Vox, nao patamares
  canonicos do Atlas inteiro por padrao.

## Termos Canonicos

Cada entrada usa o formato compacto: status · definicao · use/nao · relacao ·
exemplos ✓/✗ · aliases ok/proibidos.

### Atlas

- `active`. O produto inteiro, o operador (humano), o repositorio.
- Use: identidade, marca, repo, manifesto, visao. Nao use: como sinonimo de Atlas AI.
- Relacao: Atlas AI < Atlas. Inclui Vault, Cartografia, Desktop, AI.
- ✓ "o Atlas opera multi-dominio". ✗ "Atlas decidiu o modelo" (use Atlas AI).
- Aliases: ok={Atlas, plataforma Atlas}; proibido={Atlas AI como sinonimo}.

### Atlas AI

- `active`. Sistema operacional de inteligencia autonoma multi-dominio: Kernel + Mission Mode + Domain Company Runtimes + Tool Economy + Evidence + Control Plane.
- Use: cerebro/produto AI, Autonomous Intelligence OS. Nao use: como "Claude wrapper" nem "Atlas Code".
- Relacao: ver `atlas-autonomous-intelligence-operating-system.md`. Pai de Atlas Dev, Atlas Forge e Domain Runtimes.
- ✓ "Atlas AI decide o flow". ✗ "Atlas AI = wrapper de Claude Code".
- Aliases: ok={Atlas AI, Autonomous Intelligence OS, AI OS}; proibido={Atlas AI = produto isolado de programacao}.

### Atlas Dev

- `active`. Nucleo de programacao leve/medio: patch, repair, refactor lite, code-gen, mini-spec, review pequeno, debug.
- Use: minutos a poucas horas, escopo definido, sem SDD nem multiagente. Nao use: para Obra (use Atlas Forge).
- Relacao: irmao paralelo do Atlas Forge. NUNCA pai/filho. Ver Dev vs Forge.
- ✓ "Atlas Dev corrigiu typo em /healthz". ✗ "Atlas Dev refatorou toda a auth" (era pra promover).
- Aliases: ok={Atlas Dev}; proibido={Mini Forge, Forge leve, Dev Atlas, Atlas Dev = subsystem do Forge}.

### Atlas Forge

- `active`. Nucleo de programacao pesado, multiagente, multi-provider, com Obras persistentes, SDD obrigatoria, packets, evidence normalization, integration queue, release gate.
- Use: scope grande, multi-ciclo, multi-arquivo, SDD, enterprise. Nao use: para passo dentro do Dev.
- Relacao: irmao paralelo do Atlas Dev. Tem Obras. Pode ser chamado direto pelo Router OU por handoff vindo do Dev.
- ✓ "Forge orquestra refatoracao de 14 arquivos". ✗ "Forge executa patch do Dev".
- Aliases: ok={Atlas Forge, Forge OS}; proibido={Dev pesado, Atlas Code Forge = Atlas Forge}.

### Atlas Code

- `active`. Superficie/UX dentro do Atlas Desktop dedicada a programacao. Cabine operacional. NAO e runtime.
- Use: UX, painel Code, rotas `/atlas-code/*`, fluxo de assinatura/observacao. Nao use: como Atlas Dev/Forge.
- Relacao: superficie que CONSOME Atlas Dev e Atlas Forge. Audita Kernel via Cartografia.
- ✓ "Atlas Code mostra a Obra ativa". ✗ "Atlas Code refatorou o codigo" (use Atlas Dev/Forge).
- Aliases: ok={Atlas Code, Code Surface, cabine Code}; proibido={Atlas Code = Atlas Dev, Atlas Code = Atlas Forge}.

### Code Forge / Atlas Code Forge

- `legacy alias tolerado`. UX facade DENTRO do Atlas Code que aparece quando operador interage com Obras Forge no Desktop.
- Use: tela do Forge no Atlas Code (`AtlasCodeForge*Service`, `/works/{project}/forge/*`). Nao use: como sinonimo de Atlas Forge.
- Relacao: subset de UX do Atlas Code; consome Atlas Forge. Cluster 1 do audit.
- ✓ "Atlas Code Forge fast path emite preview". ✗ "Atlas Code Forge orquestra multiagente" (use Atlas Forge).
- Aliases: ok={Atlas Code Forge, Code Forge surface}; proibido={Code Forge = Forge OS, Atlas Code Forge como novo nucleo}.

### Obra

- `active`. Unidade produtiva persistente no Atlas Forge: charter, scope, work packets, evidence pack, release gate, certification.
- Use: trabalho longo, multi-ciclo, com SDD, entrega versionada, dossie. Nao use: para tarefa pequena (use task/WorkOrder).
- Relacao: vive no Forge. Tem `mission_type='obra'`. Decomposta em Work Packets.
- ✓ "abrir Obra de migracao do auth". ✗ "Obra para ajustar typo".
- Aliases: ok={Obra, Atlas Obra}; proibido={Obra = WorkOrder, Obra = Mission generica}.

### Mission

- `active`. Contrato canonico do Mission Foundation (Meta 1). Top-level wrapper que classifica trivial/task/mission/obra com DoD, autonomy_level, risk_level, lifecycle. Modelo `AiMission`.
- Use: pedido nao-trivial com auditabilidade + lifecycle + certification. Nao use: como sinonimo de Obra (Obra e o `mission_type` mais pesado).
- Relacao: pai canonico de Objectives + Work Orders. Persistido em `ai_missions`.
- ✓ "criar Mission a partir do prompt HTTP". ✗ "Mission e a unidade pesada" (Obra e).
- Aliases: ok={Mission, AiMission, Mission Foundation}; proibido={Mission = Obra, Mission = WorkOrder, Mission = DailyMission (modelo disjunto do Personal Dev)}.

### WorkOrder

- `active`. Unidade executavel canonica do Mission Foundation: `expected_artifacts`, `expected_tests`, `risk_notes`, `rollback_plan`, `receipt_hash`. Modelo `AiWorkOrder`.
- Use: escopo executavel auditavel ligado a Mission. Nao use: como Work Packet (Forge) — niveis diferentes.
- Relacao: filho de Objective; pai de Receipts/Artifacts via Evidence Runtime.
- ✓ "Mission decompoe em 1 Objective + 1 WorkOrder". ✗ "WorkOrder = Obra" (Obra agrega varios).
- Aliases: ok={WorkOrder, AiWorkOrder}; proibido={WorkOrder = Work Packet, WorkOrder = WorkItem (Programming Governance legacy)}.

### Work Packet

- `active`. Unidade granular interna do Atlas Forge para distribuicao multi-agente. Modelo `AtlasCodeWorkPacket`. Cada Obra → N Work Packets executaveis em paralelo.
- Use: dentro de Forge, distribuicao paralela, scope/collision map. Nao use: para tarefa Dev (use WorkOrder).
- Relacao: filho de Obra. Cluster 5 audit. Ver `atlas-forge-operating-system.md`.
- ✓ "Forge divide Obra em 4 Work Packets". ✗ "Work Packet = WorkOrder canonico".
- Aliases: ok={Work Packet, AtlasCodeWorkPacket}; proibido={Work Packet = WorkOrder, Work Packet = WorkItem}.

### Domain

- `active`. "Empresa digital" do Atlas AI: programming, research, finance, marketing, cyber, strategy, personal_development, automation, operations. Cada uma com Manifest (Meta 2), departments, capabilities, flow_profiles, policy_profile, quality_gates.
- Use: setor com regras proprias (finance tem compliance, cyber tem RoE, programming tem SDD). Nao use: para flow novo dentro de Domain existente.
- Relacao: pai de Flows. Sob Mission. Ver `atlas-domain-company-runtimes.md` + `atlas-domain-runtime-contract.md`.
- ✓ "promover Cyber a Department stage". ✗ "criar domain frontend separado de programming".
- Aliases: ok={Domain, Domain Runtime, Domain Company Runtime}; proibido={Domain = Flow, Domain = Capability, "domain frontend/mobile/security defensive"}.

### Flow

- `active`. Caminho operacional especifico dentro de Domain. Em programming: `atlas_dev`, `programming.repair`, `programming.review`, `programming.qa`, `programming.security`, `programming.forge`. Cada flow tem `flow_profile` + `required_gates`.
- Use: pipeline distinto dentro de Domain existente. Nao use: para criar Domain novo quando flow resolve.
- Relacao: filho do Domain. Ver `atlas-ai-router-runtime-enterprise-upgrade.md`.
- ✓ "adicionar flow `programming.security`". ✗ "criar domain security" (Cyber e Domain; security defensive e flow).
- Aliases: ok={Flow, Flow Profile, Flow Route}; proibido={Flow = Domain, Flow = Runtime}.

### Specialist Flow

- `active`. Flow de alta especializacao com contrato `specialist_flow_runtime.v1` + receipt (`required_evidence`, `forbidden_actions`, `execution_mode`, `side_effect_policy`).
- Use: flow precisa governance explicita por receipt. Nao use: para flow simples.
- Relacao: subtype de Flow com contrato mais rico. Persistido em `ai_specialist_flow_executions`.
- ✓ "atlas_debug e Specialist Flow". ✗ "Specialist Flow = nova Domain".
- Aliases: ok={Specialist Flow, Flow Runtime, Specialist Flow Runtime}; proibido={Specialist Flow = Provider, = Agent}.

### Company Runtime

- `active`. Forma plugavel pela qual um Domain opera como "empresa digital" (charter + departments + workflows + gates + artifacts + metrics + certification).
- Use: domain amadurece Stage 3+ (Department). Nao use: como sinonimo generico de Domain.
- Relacao: especializacao operacional do Domain. Cada Company Runtime tem manifest canonico.
- ✓ "Software Company Runtime em Stage 3". ✗ "Company Runtime = container".
- Aliases: ok={Company Runtime, Domain Company Runtime}; proibido={Company Runtime = Domain generico}.

### Runtime

- `active` (SOBRECARREGADO — sempre qualifique).
- Definicao: implementacao executavel de um contrato. Niveis: Tool Runtime (Meta 5), Router Runtime (Meta 6), Domain Runtime (Meta 2), Mission Runtime (Meta 1).
- Use: SEMPRE qualifique. Nao use: solto. Cluster 6 audit.
- ✓ "Domain Runtime Meta 2". ✗ "criar um Runtime para X" (qual nivel?).
- Aliases: ok={Tool Runtime, Router Runtime, Domain Runtime, Mission Runtime}; proibido={Runtime solto}.

### Harness

- `active`. Arnes operacional que executa contrato com observabilidade: planning, intake, gates, evidence, repair, learning. Ex.: Engineering Harness, Programming Harness.
- Use: "como executar com prova" dentro de Domain/Flow. Nao use: como sinonimo de Domain (o que) nem Tool Runtime (registry).
- Relacao: complementa Domain/Flow com infra de execucao auditavel.
- ✓ "Engineering Harness consome Tool Runtime". ✗ "Harness = Domain".
- Aliases: ok={Harness, Engineering Harness, Programming Harness}; proibido={Harness = Domain, = Runtime sem qualificar}.

### Tool Runtime

- `active` (Meta 5). Registry + invocation + receipt para tools (browser, terminal, GitHub, API, lints, SAST). Tabelas `ai_tool_*`. Services em `app/Services/Ai/ToolRuntime/`.
- Use: registrar tool, invocar com policy gate + receipt, evolution loop. Nao use: como Tool Economy (politica de decisao).
- Relacao: consome Policy (Meta 3) e emite Evidence (Meta 4).
- ✓ "Tool Runtime recusa external_action sem allow". ✗ "Tool Runtime decide se cria ferramenta" (isso e Tool Economy).
- Aliases: ok={Tool Runtime, Atlas Tool Runtime}; proibido={Tool Runtime = Tool Economy, = Tool Builder}.

### Provider

- `active`. Motor externo de modelo (Claude CLI, Codex CLI, Gemini CLI). Tem driver, prompt projection, fallback policy, capacity profile.
- Use: execucao concreta de LLM, drivers em `app/Services/Ai/Provider/`. Nao use: como Agent.
- Relacao: invocado por Specialist Flow / Atlas Dev / Atlas Forge. Selecionado por Atlas Decide.
- ✓ "Provider claude_cli selecionado". ✗ "Provider = Atlas Dev".
- Aliases: ok={Provider, LLM Provider, AI Provider}; proibido={Provider = Agent, = Atlas}.

### Agent

- `active`. Papel especializado dentro de Domain/Flow (Reviewer, Repairer, Planner, Scout, Senior). Sempre subordinado a runtime — NUNCA livre.
- Use: behaviour contract de papel especifico. Nao use: como sinonimo de Provider.
- Relacao: rodado por Specialist Flow Runtime. Behaviour em `atlas-ai-agent-behavior-contract.md`.
- ✓ "Review Agent recusa diff sem teste". ✗ "Agent = wrapper de Claude".
- Aliases: ok={Agent, Specialist Agent}; proibido={Agent = Provider, Agent solto sem runtime pai}.

### Router

- `active` (SOBRECARREGADO — cluster 2, sempre qualifique). 5+ Routers: `AtlasAiRouterService` (legacy provider/flow), `RouterRuntime/FlowRouterService` + `DomainRouterService` + `RuntimeDispatchService` (Meta 6 canonico), `AiIntentRouter` (legacy intent), `Programming\Sdd\IntentRouter` (SDD), `Context\ContextRetrievalRouter` (Memory/RAG).
- Use: SEMPRE qualifique. Nao use: solto.
- Relacao: cluster 2 audit `:235-251`. Doc desambiguador P3 pendente.
- ✓ "FlowRouterService decide flow_id". ✗ "Router decidiu" (qual?).
- Aliases: ok={Atlas AI Router (legacy), Router Runtime (Meta 6), Intent Router, Context Router}; proibido={Router solto}.

### Kernel

- `active` (SOBRECARREGADO — sempre qualifique). DOIS Kernels: **Mission Kernel** (`Mission/`, Meta 1, canonico) e **Legacy Pipeline Guards** (`Kernel/Pipeline|Decision|Evidence|Repair|Slo`, em deprecation).
- Use: SEMPRE qualifique. Nao use: solto. Ver `atlas-aiworker-kernel-integration-adr.md:279-282`.
- Relacao: Mission Kernel substitui Legacy Pipeline (ADR Phase 7).
- ✓ "Mission Kernel cria AiMission". ✗ "Kernel decidiu" (qual?).
- Aliases: ok={Mission Kernel, Atlas AI Kernel (= Mission Kernel), Legacy Pipeline Guards}; proibido={Kernel solto, Kernel = Atlas AI}.

### Intent

- `active`. Classificacao do tipo de pedido — programming, research, debug, explain, plan, conversation, financial, cyber, marketing, strategy. Output do `IntentKernelService`.
- Use: rotear prompt ao Domain certo. Nao use: como Flow (Intent classifica; Flow executa).
- Relacao: input do DomainRouter e FlowRouter (Meta 6).
- ✓ "intent=programming confidence 0.85". ✗ "intent = atlas_dev" (atlas_dev e flow).
- Aliases: ok={Intent, intent_type, Router Intent}; proibido={Intent = Flow, = Domain}.

### Context Pack

- `active`. Packet versionado com codigo + docs + memoria + receipts + skills ativadas que o provider recebe. Hash determinant. Ver `atlas-ai-memory-context-core-open-brain.md`.
- Use: input final ao provider apos retrieval/RAG/Code Intelligence. Nao use: como prompt cru.
- Relacao: produzido por Memory/Open Brain + Code Intelligence. Tem `context_pack_hash`.
- ✓ "Context Pack sha256:abc". ✗ "Context Pack = prompt do usuario".
- Aliases: ok={Context Pack, Context Bundle}; proibido={Context Pack = Prompt, = Skill}.

### RAG Gate

- `planned` (parte de Hyperflow). Gate que decide quando/como rodar retrieval-augmented generation antes do flow. Exige `source_ref` obrigatorio em pesquisa/explain.
- Use: pesquisa profunda, grounded answer, explain technical. Nao use: como sinonimo de RAG (RAG e tecnologia; RAG Gate e governanca).
- Relacao: chamado por Specialist Flow Runtime. Consome Context Pack + Memory.
- ✓ "RAG Gate exige >=2 sources". ✗ "RAG Gate substitui retrieval" (governa retrieval).
- Aliases: ok={RAG Gate, Retrieval Gate}; proibido={RAG = RAG Gate generico}.

### Evidence

- `active` (Meta 4). Prova auditavel: receipts, claims, artifacts, source_refs, gate_runs, test_results, operator_decisions, blockers, audit_events. Tabelas `ai_evidence_*`.
- Use: registrando acao com prova reproduzivel. Nao use: como "log" (Evidence e estruturado/hash-stable).
- Relacao: pai de Receipt + Certification. Ver `atlas-evidence-certification-runtime.md`.
- ✓ "Evidence Pack passa Certification". ✗ "Evidence = log do provider".
- Aliases: ok={Evidence, Evidence Pack, Evidence Runtime}; proibido={Evidence = Log, = Receipt sozinho}.

### Receipt

- `active` (SOBRECARREGADO — sempre qualifique). Tipos: `Evidence\ReceiptService` (Meta 4 universal), `ToolReceiptService` (Meta 5), `DecisionReceiptService` (Meta 6 router), `AiDecisionReceipt` (legacy projection), 12+ `SelfConstruction\*HumanCompletionReceipt*` (agent completion).
- Use: SEMPRE qualifique. Nao use: como sinonimo de Evidence (Receipt e UM tipo).
- Relacao: cluster 4 audit. Sempre filho de Evidence ou Decision.
- ✓ "Tool Receipt v1". ✗ "Receipt confirma" (qual?).
- Aliases: ok={Receipt + qualifier}; proibido={Receipt solto}.

### Certification

- `active` (SOBRECARREGADO — 45 services, cluster 3). Gate que prova prontidao baseado em evidencia. Niveis: `Evidence\CertificationRuntimeService` (universal Meta 4), `Mission\MissionCertificationService` (Meta 1), `AtlasForgeContinuumCertificationService` (37 invariants), `AtlasCodeEnterpriseCertificationService` (Code surface), 30+ em SelfConstruction.
- Use: SEMPRE qualifique. Nao use: como sinonimo de Readiness (Certification e hard gate; Readiness e probe).
- Relacao: cluster 3 audit `:253-269`. Doc desambiguador pendente.
- ✓ "Mission Certification passed". ✗ "Certification passou" (qual?).
- Aliases: ok={Mission Cert, Evidence Cert, Forge Continuum Cert, Atlas Code Enterprise Cert}; proibido={Certification solto}.

### Readiness

- `active`. Probe read-only que verifica se runtime tem tabelas + models + services + canon enums + tolerant bridges. NAO bloqueia execucao.
- Use: smoke probe pre-deploy, control plane, sanity check. Nao use: como gate de execucao (advisory).
- Relacao: precede Certification. Cada Meta tem `*ReadinessService`.
- ✓ "Tool Runtime Readiness: 25/25 passed". ✗ "Readiness bloqueia execucao" (use Certification).
- Aliases: ok={Readiness, Readiness Probe}; proibido={Readiness = Certification, = Hard Gate}.

### Compounding

- `active`. Patamar acima do Hyperflow — cada execucao gera aprendizado verificavel que melhora Router/RAG/specialist flows/Dev/Forge/benchmarks. Outcome evaluation, learning distillation, compounding memory, heuristic evolution, temporal certification.
- Use: melhoria estrutural pos-execucao, learning operacional governado. Nao use: como sinonimo de Memory (Compounding produz; Memory armazena).
- Relacao: consome Evidence + Outcome receipts. Ver `atlas-compounding-engineering-intelligence.md`.
- ✓ "Compounding gera benchmark do bug repetido". ✗ "Compounding = log do trace".
- Aliases: ok={Compounding, Compounding Engineering Intelligence, Compounding Memory}; proibido={Compounding = Learning solto}.

### Memory

- `active`. Camada governada de retencao: Memory Registry (Postgres), Verbatim Store, Engineering KB, Code Intelligence, Provider Projection, Cognitive Immune Learning Kernel (gate de promocao).
- Use: persistencia de decisoes, learnings, contextos. Nao use: como Cache (Memory e governado). Nao confundir com AtlasVault (Human Knowledge Surface).
- Relacao: alimentada por Compounding; consumida por Context Pack.
- ✓ "Memory promove learning candidate". ✗ "Memory = cache do thread".
- Aliases: ok={Memory, Atlas Memory, Memory Core, Open Brain (= MCP surface)}; proibido={Memory = AtlasVault, = Cache}.

### World Model

- `planned/future`. Representacao governada do mundo externo (mercado, dominios, fontes, concorrentes) que alimenta Research/Strategy/Marketing/Finance Domain Runtimes.
- Use: Domain Runtimes que precisam de "mundo" externo (research, strategy, finance). Nao use: para Programming (opera sobre repo).
- Relacao: dependencia de Company Runtimes nao-Programming. Ver `atlas-autonomous-intelligence-operating-system.md`.
- ✓ "Strategy consome World Model". ✗ "World Model = AtlasVault".
- Aliases: ok={World Model, Atlas World Model}; proibido={World Model = Memory, = Context Pack}.

### Control Plane

- `active`. Estado vivo + auditavel do Autonomous Intelligence OS: missoes abertas, dominios ativos, ferramentas, custos, safety gates, evidence, blockers, outcomes, next actions, certifications. Read-only snapshot.
- Use: observabilidade global, painel operacional, debug cross-runtime. Nao use: como executor.
- Relacao: consome events/receipts. Ver `atlas-autonomous-control-plane.md`.
- ✓ "Control Plane mostra 3 missoes blocked". ✗ "Control Plane decidiu retry" (nao executa).
- Aliases: ok={Control Plane, Atlas Control Plane, Autonomous Control Plane}; proibido={Control Plane = Router, = Executor}.

## Termos TEOS / Long-Horizon
Os termos TEOS/Long-Horizon vivem em `docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming-teos-long-horizon.md`: long_horizon, temporal_truth, event_sourced_timeline, continuation_pack, compaction_receipt, replay_manifest e continuity_certification.

## Dev vs Forge

| Dimensao | Atlas Dev | Atlas Forge |
| --- | --- | --- |
| Peso | leve/medio | pesado |
| Tempo | minutos a poucas horas | horas a dias, multi-ciclo |
| Escopo | patch/repair/refactor lite/code-gen/mini-spec | refactor de subsistema, migracao, novo produto, SDD obrigatoria |
| Provider | single-provider | multi-provider (Claude/Codex/Gemini) |
| Spec | mini-spec quando risco>baixo | SDD obrigatoria sempre |
| Unidade | task/WorkOrder | Obra/Work Packet |
| Persistencia | run_id + verification_receipt | Obra persistente + delivery pack |
| Quem decide | Router escolhe Dev quando score baixo | Router escolhe Forge quando score>=7 OR risk>=R4 OR sdd_required |

**Invariantes:** (1) Dev NAO e mini Forge — nao desce a WorkPackets, nao decompoe Obra, nao roda multi-agente. (2) Forge NAO e subflow do Dev — tem identidade propria, pode ser chamado direto pelo Router. (3) Dev pode promover para Forge via `atlas.dev_to_forge.escalation_packet.v1` (handoff honesto). Operador aprova via Atencao. (4) Forge NAO promove para Dev — patch leve interno nao vira Dev. (5) Router decide; nunca Dev->Forge sem packet.

## Domain vs Flow vs Runtime vs Harness

| Conceito | Escopo | Quando criar |
| --- | --- | --- |
| Domain | empresa digital inteira (charter + departments + capabilities) | problema cabe em setor com regras proprias E setor nao existe |
| Flow | caminho operacional dentro de Domain | pipeline distinto dentro de Domain existente |
| Specialist Flow | flow com contrato richer + receipt | flow precisa governance hard |
| Capability | habilidade atomica de Domain | unidade de Domain Manifest Meta 2 |
| Runtime | implementacao executavel | sempre qualificar Tool/Router/Domain/Mission |
| Harness | arnes operacional com observabilidade | Domain/Flow precisa planning + gates + evidence + repair + learning como unidade |

**Quando NAO criar nada novo:** se cabe como flow/profile/capability de Domain existente, NAO crie Domain novo. Frontend e specialist profile DENTRO de programming, NAO domain. Security defensive e flow de programming (`programming.security`), NAO domain — Cyber Security e Domain so quando ofensivo/autorizado.

## Nomes Proibidos / Deprecados

| Nome | Por que proibido | Substituto |
| --- | --- | --- |
| `Runtime` solto | cluster 6 ambiguo | Tool/Router/Domain/Mission Runtime |
| `Router` solto | cluster 2 ambiguo | qualificar pelo escopo |
| `Receipt` solto | cluster 4 ambiguo | qualificar (Tool/Evidence/Decision) |
| `Certification` solto | cluster 3 (45 services) | qualificar (Mission/Evidence/Forge Continuum/Code Enterprise) |
| `Kernel` solto | dois Kernels co-existem | Mission Kernel OU Legacy Pipeline Guards |
| `Mini Forge` / `Forge leve` | quebra invariante Dev vs Forge | Atlas Dev (quando leve) |
| `Atlas Code Forge = Atlas Forge` | UX vs sistema | qualificar surface vs OS |
| `WorkItem` para Mission/Forge | colide com Programming Governance legacy | WorkOrder (Mission) OU Work Packet (Forge) |
| `domain frontend/mobile` | colide com Domain canonico Meta 2 | specialist profile DENTRO de programming |
| `DailyMission` confundido com `Mission` | modelos disjuntos | nunca usar como sinonimos |
| `Cockpit` solto | marketing sem definicao | painel especifico (Obra Command Center, ...) |
| `Hyperflow` como runtime atual | ainda planned | "Operacao Atlas Hyperflow" (projeto) |

## Regras para IA

1. **Antes de criar classe/servico/namespace** cujo nome bata com termo aqui, leia a entrada. Se nao existir, propor entrada nova ANTES do codigo.
2. **Nunca use termo proibido novo** em doc com `status: active`. Aliases legados sao tolerados em codigo legado apenas.
3. **Sempre qualifique termos sobrecarregados** (Runtime, Router, Receipt, Certification, Kernel) na primeira ocorrencia.
4. **Nunca trate `graph_status: planned/future/draft` como runtime atual.** Verifique antes de implementar. ADRs (`*-adr.md`) sao design only.
5. **Para criar Domain novo:** consulte `domains/domain-routing-governance.md` (Domain Creation Gate). Default = NAO criar.
6. **Para mover codigo entre AtlasDev/* e AtlasForge*Service*:** consulte Dev vs Forge. Mistura proibida.
7. **Para criar Router/Receipt/Certification novo:** valide nao-duplicacao com `atlas-canonical-cleanup-inventory.md`.
8. **Nao apague termo deprecado** — mova para "Nomes Proibidos" com substituto.
9. **Quando audit identificar novo cluster** de naming proliferation, abrir entrada aqui ANTES de qualquer cleanup de codigo.
10. **Camada macro sem os 4 nomes obrigatorios deve ser bloqueada** ate declarar: produto, acronimo, superficie e runtime tecnico.
11. **Resposta final cita o termo canonico**, nao alias proibido. Se citar alias legado para esclarecer, marcar `(legado: substituto canonico = X)`.

## Escopo de Implementacao

Doc nao implementa codigo. Edita-se quando: termo novo entra (P0/P1); audit identifica naming proliferation novo; aliases mudam; termo deprecado descontinuado. Validacao: `docs-health` cobre estrutura + line limit. Conteudo governado por revisao humana.

## Dependencias

`atlas-canonical-cleanup-inventory.md` (clusters), `atlas-ai-canonical-architecture-index.md` (autoridade), `atlas-domain-company-runtimes.md` + `domains/domain-routing-governance.md` (Domain), `atlas-dual-core-engineering-system.md` + `atlas-dev-forge-relationship-critical-audit.md` (Dev/Forge), `atlas-programming-superiority-*` (Dev/Forge atual), `atlas-autonomous-intelligence-operating-system.md` (Atlas AI), `atlas-canonical-module-doc-v1.md` (schema).

## Evidencias

Cluster 1-6 em `atlas-canonical-cleanup-inventory.md:209-289`. Dev vs Forge invariantes em `atlas-dual-core-engineering-system.md:166-171` + `atlas-dev-forge-relationship-critical-audit.md:443-446`. Domain Creation Gate em `domains/domain-routing-governance.md`. 5 Routers / 45 Certifications / 4 mecanismos Dev->Forge confirmados via `grep`/`rg` no audit.

## Riscos
Glossario envelhecer ou termo legado voltar sem contexto; mitigacao: registrar termo novo antes do codigo e manter aliases proibidos com substituto.

## Exemplos
`AtlasCodeForgeFastPathV2` exige checar Cluster 1; "Router decidiu" precisa qualificar qual Router.

## Proximas Acoes
Registrar Cluster 7+, linkar futuro disambiguator, atualizar RAG Gate quando Hyperflow virar runtime ativo e mover legacy permission/evidence/router para proibidos quando ADR Phase 7 deprecar.
## Definition of Done
Vivo enquanto termos novos sao checados contra o glossario, termos proibidos nao aparecem em docs active novos e audit confirma reducao de duplicacao por cluster.
