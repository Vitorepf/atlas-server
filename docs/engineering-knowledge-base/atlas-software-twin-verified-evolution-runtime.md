---
id: atlas-software-twin-verified-evolution-runtime
type: engineering_knowledge
title: Atlas Software Twin & Verified Evolution Runtime
status: active
category: code-intelligence
priority: 100
summary: Define o limite tecnico superior da inteligencia de codigo do Atlas: ACIR como base, ASTR como gemeo vivo do software e AVEOR como camada que controla evolucao verificada para reduzir erro de IA antes, durante e depois de patches.
tags:
  - atlas-ai
  - code-intelligence
  - software-twin
  - verified-evolution
  - ai-safety
  - patch-governance
capabilities:
  - software_twin_runtime
  - verified_evolution_runtime
  - agent_change_safety
  - patch_simulation
  - impact_graphrag
  - context_boundary_contract
  - execution_learning
decisions:
  - Nome canonico/produto da camada twin: Atlas Software Twin Runtime.
  - Acronimo tecnico da camada twin: ASTR.
  - Nome interno de experiencia/superficie da camada twin: Atlas Living System Twin.
  - Runtime tecnico atual da camada twin: AtlasSoftwareTwinRuntimeService.
  - Nome canonico/produto da camada superior: Atlas Verified Evolution Runtime.
  - Acronimo tecnico da camada superior: AVEOR.
  - Nome interno de experiencia/superficie da camada superior: Atlas Change Safety Kernel.
  - Runtime tecnico atual da camada superior: AtlasVerifiedEvolutionRuntimeService.
  - O acronimo AVER ja pertence a Atlas Verified Execution Runtime; nao reutilizar AVER para Verified Evolution.
  - ASTR entende o sistema vivo; AVEOR decide, limita, simula, verifica e aprende com mudancas.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar quando ACIR, ACRUI, ADRS, AURC, AVER, AWEOS, AEMOR ou Atlas Dev/Forge mudarem contrato.
  - Rodar docs-health apos alteracoes.
related_paths:
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
  - docs/engineering-knowledge-base/atlas-universal-reality-cartography.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-verified-execution-runtime.md
  - docs/engineering-knowledge-base/atlas-autonomous-work-execution-os.md
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
  - app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php
  - app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php
  - app/Services/Engineering/AtlasSoftwareTwinVerifiedEvolutionCertificationService.php
  - app/Console/Commands/AtlasSoftwareTwinCommand.php
  - app/Console/Commands/AtlasVerifiedEvolutionCommand.php
  - app/Console/Commands/AtlasSoftwareTwinVerifiedEvolutionCertifyCommand.php
  - tests/Feature/Engineering/AtlasSoftwareTwinVerifiedEvolutionRuntimeServiceTest.php
  - app/Services/Engineering/EngineeringCodeIntelligenceService.php
  - app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-software-twin-verified-evolution-runtime
graph_title: Atlas Software Twin & Verified Evolution Runtime
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: engineering-code-intelligence-index
graph_status: active
graph_source: repo
human_name: Atlas Software Twin & Verified Evolution Runtime
canonical_name: Atlas Software Twin & Verified Evolution Runtime
technical_name: atlas-software-twin-verified-evolution-runtime
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-software-twin-verified-evolution-runtime.md
owner: code-intelligence
repo_paths:
  - docs/engineering-knowledge-base/atlas-software-twin-verified-evolution-runtime.md
allowed_changes:
  - Refinar o limite tecnico superior de ACIR, ASTR e AVEOR.
  - Dividir esta especificacao em docs filhas quando o escopo crescer ou novos runtimes forem extraidos.
forbidden_changes:
  - Declarar ASTR ou AVEOR prontos para mutacao autonoma sem boundary, AVER, AEMOR, testes e evidence.
  - Reutilizar o acronimo AVER para Verified Evolution.
  - Permitir mudanca de codigo sem boundary, prova e rollback quando AVEOR estiver ativo.
depends_on:
  - engineering-code-intelligence-index
  - atlas-code-reality-usage-intelligence
  - atlas-documentation-reality-system
  - atlas-universal-reality-cartography
  - atlas-verified-execution-runtime
  - atlas-execution-memory-outcome-runtime
flows_to:
  - atlas_dev
  - atlas_forge
  - aweos
  - aemor
unlocks:
  - living-software-twin
  - verified-ai-change-control
  - provider-safe-patch-boundaries
governs:
  - code-intelligence
  - ai-implementation-safety
  - patch-evolution
evidence:
  - docs/engineering-knowledge-base/atlas-software-twin-verified-evolution-runtime.md
  - app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php
  - app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php
  - app/Services/Engineering/AtlasSoftwareTwinVerifiedEvolutionCertificationService.php
  - tests/Feature/Engineering/AtlasSoftwareTwinVerifiedEvolutionRuntimeServiceTest.php
evidence_refs:
  - symbol: AtlasSoftwareTwinRuntimeService
  - command: atlas:software-twin
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test tests/Feature/Engineering/AtlasSoftwareTwinVerifiedEvolutionRuntimeServiceTest.php"
  - "php artisan atlas:software-twin-verified-evolution:certify --json --strict"
requires_evidence: true
risk_level: critical
visual_tags:
  - system
  - code-intelligence
  - software-twin
  - verified-evolution
ai_entrypoints:
  - Leia este doc antes de transformar ACIR em runtime de software twin, safety kernel ou evolucao verificada.
ai_usage_notes:
  - ASTR/AVEOR possuem runtime, comandos, testes e certificacao; isso nao autoriza mutacao autonoma fora de boundary, AVER e AEMOR.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test tests/Feature/Engineering/AtlasSoftwareTwinVerifiedEvolutionRuntimeServiceTest.php"
  - "php artisan atlas:software-twin-verified-evolution:certify --json --strict"
failure_modes:
  - IA confunde indice de codigo com entendimento causal do sistema.
  - IA usa ASTR como permissao para editar sem proof gate.
  - IA duplica AVER existente ao implementar AVEOR.
  - Mudanca passa em teste local mas quebra doc, cartografia ou runtime real.
observability_signals:
  - code intelligence freshness
  - ACRUI reality audit status
  - ADRS acceptance status
  - AVER certified execution status
  - AEMOR outcome quality score
next_actions:
  - Manter ASTR read-only como twin vivo com comandos `atlas:software-twin`.
  - Manter AVEOR como safety kernel que prepara boundary/proof/execution contract sem autorizar mutacao direta.
  - Integrar evolucoes com AVER, AEMOR e AWEOS apenas por gates certificados.
---
# Atlas Software Twin & Verified Evolution Runtime
## Resumo
Esta e a especificacao do limite tecnico superior da area de inteligencia de codigo do Atlas.
O objetivo nao e apenas indexar codigo. O runtime atual impede que uma IA entre em uma sessao zerada, entenda errado, edite arquivo errado, duplique fluxo, quebre arquitetura ou declare pronto sem prova, sempre sem autorizar mutacao direta fora de boundary e AVER.
Camadas:
| Camada | Nome | Papel |
|---|---|---|
| ACIR | Atlas Code Intelligence Runtime | entende codigo, simbolos, rotas, comandos, testes e docs |
| ACRUI | Atlas Code Reality & Usage Intelligence | prova uso real, estado estacionado, legado, duplicacao e risco |
| ADRS | Atlas Documentation Reality System | governa fonte de verdade documental |
| AURC | Atlas Universal Reality Cartography | mostra a verdade visualmente para humano/IA |
| ASTR | Atlas Software Twin Runtime | cria o gemeo vivo do sistema |
| AVEOR | Atlas Verified Evolution Runtime | controla evolucao segura em cima do twin |
| AVER | Atlas Verified Execution Runtime | executa comandos/patches/testes com ledgers e certificado |
Regra de naming: `AVER` ja existe e significa Atlas Verified Execution Runtime.
Para a camada superior de evolucao verificada, o acronimo canonico e `AVEOR`.
## Papel no Atlas

ACIR reduz erro estrutural.
ASTR reduz erro de entendimento.
AVEOR reduz erro de decisao e execucao.
AVER prova a execucao concreta.
AEMOR aprende com o resultado.

O salto real acontece quando o Atlas deixa de perguntar "quais arquivos ler?" e passa a responder:

```text
Qual mudanca pode acontecer, por quem, em quais limites, com qual contexto,
qual risco, qual prova, qual rollback e qual aprendizado?
```

## Onde Se Encaixa

```text
ADRS/Documentation OS
  -> ACIR code intelligence
  -> ACRUI operational reality
  -> ASTR software twin
  -> AVEOR verified evolution
  -> AVER verified execution
  -> AEMOR outcome learning
  -> AURC human/AI cartography
```

Atlas Dev e Forge devem consumir ASTR/AVEOR como infraestrutura de orientacao, nao como produto separado. ASTR/AVEOR nao substituem provider, IDE, teste, Control Plane ou AVER; eles controlam contexto, boundary e prova antes que a IA execute trabalho.

## Contratos

| Camada | Nome canonico | Acronimo | Superficie | Runtime tecnico |
|---|---|---|---|---|
| Code intelligence | Atlas Code Intelligence Runtime | ACIR | Atlas Living Code Map | `AtlasCodeIntelligenceRuntimeService` |
| Software twin | Atlas Software Twin Runtime | ASTR | Atlas Living System Twin | `AtlasSoftwareTwinRuntimeService` |
| Verified evolution | Atlas Verified Evolution Runtime | AVEOR | Atlas Change Safety Kernel | `AtlasVerifiedEvolutionRuntimeService` |
| Verified execution existente | Atlas Verified Execution Runtime | AVER | Atlas Execution Cockpit | `AtlasVerifiedExecutionRuntimeService` |

Schemas alvo: `atlas.code_intelligence_runtime.v1`, `atlas.software_twin.v1`,
`atlas.software_twin.snapshot.v1`,
`atlas.verified_evolution.v1`, `atlas.verified_evolution.boundary_contract.v1`,
`atlas.verified_evolution.proof_plan.v1`, `atlas.verified_evolution.execution_contract.v1`,
`atlas.verified_evolution.scope_drift_watch.v1`, `atlas.verified_evolution.patch_simulation.v1`,
`atlas.verified_evolution.outcome_bridge.v1`, `atlas.verified_evolution.quality_score.v1`.

## ACIR Produto-Final

ACIR em estado final deve entregar:

- index incremental e checkpointed;
- parser registry para PHP, TS, TSX, JS, JSX, Markdown, config e migrations;
- symbol graph;
- route/command/job/event/migration/test graph;
- doc-code link graph;
- ownership resolver;
- freshness gate;
- context pack compiler;
- impact analyzer;
- duplicate/dead/stationed-code detector;
- provider-safe projection;
- cartography projection;
- quality score.

ACIR ainda e mapa tecnico. Ele nao deve decidir sozinho se uma mudanca e segura.

## ASTR: Atlas Software Twin Runtime

Contratos:

| Campo | Valor |
|---|---|
| Nome canonico / produto | Atlas Software Twin Runtime |
| Acronimo tecnico | ASTR |
| Nome interno de experiencia / superficie | Atlas Living System Twin |
| Runtime tecnico atual | `AtlasSoftwareTwinRuntimeService` |
| Schema alvo | `atlas.software_twin.v1` |

ASTR cria um modelo vivo do software combinando:

- codigo real;
- docs canonicos;
- testes;
- rotas;
- comandos;
- jobs;
- migrations;
- traces;
- receipts;
- evidence packs;
- runtime usage;
- bugs e patches passados;
- ownership;
- cartografia;
- riscos;
- contexto por agente.

ASTR responde:

- o que isso faz;
- quem chama;
- quem depende;
- onde esta documentado;
- qual teste prova;
- qual surface usa;
- qual fluxo quebra se mudar;
- qual runtime observa;
- qual historico de patch existe;
- qual area esta stale, shadow, headless ou ativa.

## Blocos ASTR

| # | Bloco | Saida |
|---:|---|---|
| 1 | Software Twin Graph | grafo unico de codigo, docs, testes, runtime e evidence |
| 2 | Impact GraphRAG | contexto causal bounded/local via Code Intelligence, sem engine paralela |
| 3 | Runtime Usage Lens | diferenca entre existe, usado, shadow, estacionado e morto |
| 4 | Feature Lineage Tracker | origem doc->prompt->patch->teste->runtime |
| 5 | Ownership & Boundary Resolver | dono real, camada e area permitida |
| 6 | Test Proof Resolver | quais testes provam qual comportamento |
| 7 | Documentation Binding Engine | doc owner obrigatoria por area relevante |
| 8 | Risk Propagation Model | impacto provavel de mudanca |
| 9 | Context Compiler Feed | fontes minimas para agentes e subagentes |
| 10 | Cartography Feed | projecao para universo->empresa->sistema->fluxo->componente |
| 11 | Drift Radar | divergencia entre twin, docs, codigo e runtime |
| 12 | Twin Quality Score | score de cobertura, freshness, causalidade e prova |

ASTR e read-only por padrao. Ele pode recomendar, mas nao autoriza patch sozinho.

## AVEOR: Atlas Verified Evolution Runtime

Contratos:

| Campo | Valor |
|---|---|
| Nome canonico / produto | Atlas Verified Evolution Runtime |
| Acronimo tecnico | AVEOR |
| Nome interno de experiencia / superficie | Atlas Change Safety Kernel |
| Runtime tecnico atual | `AtlasVerifiedEvolutionRuntimeService` |
| Schema alvo | `atlas.verified_evolution.v1` |

AVEOR fica acima do ASTR. Ele usa o twin para controlar mudanca antes, durante e
depois da execucao.

ASTR responde:

```text
Como o sistema funciona e o que esta mudanca afeta?
```

AVEOR responde:

```text
Essa mudanca pode acontecer agora? Quem deve fazer? Em quais arquivos? Com quais
limites? Como provar? Como reparar? O que aprender depois?
```

## Blocos AVEOR

| # | Bloco | Saida |
|---:|---|---|
| 1 | Intent Lock | objetivo fechado, escopo e anti-ambiguidade |
| 2 | Reality Lookup | consulta ASTR, ACRUI, ADRS, AURC e AEMOR |
| 3 | Change Boundary Contract | arquivos permitidos, proibidos e sensiveis |
| 4 | Architecture Law Gate | bloqueio de violacoes de arquitetura canonica |
| 5 | Patch Simulation Runtime | impacto provavel antes de editar |
| 6 | Agent Assignment Planner | decide humano, Codex, Claude, Gemini ou subagente |
| 7 | Context Envelope Compiler | contexto minimo com provas, riscos e proibicoes |
| 8 | Continuous Scope Drift Watch | detecta quando a IA sai do escopo |
| 9 | Proof Plan Resolver | testes, comandos, docs-health e runtime checks obrigatorios |
| 10 | AVER Execution Bridge | envia execucao concreta para Atlas Verified Execution Runtime |
| 11 | Repair/Rollback Strategy | plano de correcao ou reversao de patch proprio |
| 12 | Outcome Learning Bridge | envia resultado para AEMOR |
| 13 | Human Escalation Gate | pede humano quando risco, conflito ou custo passar limite |
| 14 | Evolution Quality Score | nota final da mudanca e confianca do sistema |

## Fluxo

```text
task
-> ADRS resolve fonte de verdade
-> ACIR localiza codigo/testes/docs
-> ACRUI classifica realidade operacional
-> AURC projeta mapa humano/IA
-> ASTR monta gemeo vivo e impacto causal
-> AVEOR trava objetivo e boundary
-> agente executa dentro do envelope
-> AVER registra comandos, diffs, testes e certificado
-> AEMOR aprende outcome
-> ADRS/ACRUI/AURC atualizam verdade e visualizacao
```

## Escopo de Implementacao

Escopo ACIR:

- index incremental;
- relation graph;
- freshness gate;
- owner/test/doc resolver;
- provider-safe context projection.

Escopo ASTR:

- software twin graph read-only;
- causal dependency model;
- runtime usage lens;
- feature lineage;
- risk propagation;
- snapshot persistido;
- cartography feed.

Escopo AVEOR:

- intent lock;
- boundary contract;
- proof plan;
- execution contract para AVER;
- scope drift watch;
- patch simulation;
- AEMOR bridge;
- human escalation;
- quality score.

Fora do escopo:

- substituir AVER;
- editar codigo sem execution bridge;
- rodar benchmark/rivals;
- autorizar delete sem ACRUI quarantine;
- declarar mutacao autonoma pronta sem boundary, AVER, AEMOR, testes e evidence.

## Reducao De Erro

Estimativa qualitativa:

| Estado | Risco comum |
|---|---|
| Provider puro | entende errado por contexto incompleto |
| ACIR | acha arquivos melhores, mas ainda pode errar causalidade |
| ASTR | entende sistema, mas ainda pode permitir execucao ruim |
| ASTR + AVEOR + AVER | limita, simula, executa, prova e aprende |

Meta tecnica: reduzir a maior parte dos erros de IA causados por contexto
incompleto, arquivo errado, duplicacao, escopo aberto, teste ausente e claims
sem prova. Nao prometer 100%; sempre declarar confidence e evidence.

## Regras para IA

1. Nunca tratar ASTR como autorizacao para patch; ASTR e read-only por padrao.
2. Nunca tratar AVEOR como executor direto; AVEOR prepara boundary, proof plan e contrato para AVER.
3. Nunca reutilizar `AVER` para Verified Evolution; use `AVEOR`.
4. Nunca permitir patch sem `Change Boundary Contract`.
5. Nunca considerar tarefa pronta sem `Proof Plan Resolver` e AVER quando houver execucao.
6. Nunca usar cartografia como fonte primaria; ela e projection.
7. Nunca declarar codigo morto sem ACRUI quarantine policy.
8. Nunca passar contexto gigante quando Context Envelope Compiler puder reduzir.
9. Sempre separar Atlas plataforma de repos/projetos externos operados pelo Atlas.
10. Sempre registrar gaps em vez de inferir realidade nao provada.

## Dependencias

| Dependencia | Uso |
|---|---|
| ADRS | fonte de verdade documental e owner docs |
| ACIR | codigo, simbolos, rotas, comandos, testes e freshness |
| ACRUI | realidade operacional, codigo estacionado, legado e quarantine |
| AURC | visualizacao humana/IA do twin e dos boundaries |
| AVER | execucao verificada de comandos, diffs, testes e certificacao |
| AEMOR | memoria de outcomes, falhas e estrategias |
| AWEOS | orquestracao de trabalho autonomo quando existir |

## Evidencias

Evidencia minima para esta especificacao:

```bash
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:documentation-reality acceptance --strict --json
php artisan atlas:code-reality reality-audit --json
php artisan atlas:aver:certify --json --strict
php artisan test tests/Feature/Engineering/AtlasSoftwareTwinVerifiedEvolutionRuntimeServiceTest.php
php artisan atlas:software-twin-verified-evolution:certify --json --strict
```

Evidencia runtime atual para ASTR/AVEOR:

- `atlas:software-twin` entrega twin, impact, context-envelope, quality-score e snapshot.
- `atlas:verified-evolution` entrega intent-lock, boundary-contract, proof-plan, execution-contract, drift-watch, patch-simulation, outcome-bridge, quality-score e evolution-envelope.
- Testes cobrem boundary, proof plan, AVER bridge, drift watch, patch simulation e AEMOR outcome bridge.

## Riscos

| Risco | Controle |
|---|---|
| confundir ASTR com autorizacao de patch | ASTR read-only por padrao |
| duplicar AVER existente | AVEOR usa AVER como bridge, nao como substituto |
| twin stale orientar IA errado | freshness gate e drift radar |
| boundary permissivo demais | architecture law gate e human escalation |
| contexto minimo pobre | proof plan exige fontes e testes |
| cartografia parecer fonte primaria | ADRS declara Cartografia como projection |

## Exemplos

Exemplo de tarefa YouTube:

```text
AVEOR deve travar objetivo, consultar ASTR para descobrir gateway/job/trace/UI,
consultar ACRUI para status mobile/desktop, limitar arquivos permitidos, exigir
teste de ingestion/status sync e enviar execucao concreta para AVER.
```

Exemplo de codigo possivelmente morto:

```text
ASTR pode apontar baixa reachability, mas AVEOR nao autoriza delete. O fluxo
deve ir para ACRUI deletion-preflight e quarantine humano.
```

## Sequencia Operacional

1. Consultar ACIR/ACRUI/ADRS/AURC para realidade e owner.
2. Rodar ASTR `impact` ou `context-envelope` para entender dependencias.
3. Rodar AVEOR `boundary-contract` e `proof-plan` antes de qualquer patch.
4. Enviar execucao concreta ao AVER quando houver mudanca.
5. Enviar outcome ao AEMOR quando houver resultado.
6. Atualizar docs/ACRUI/AURC somente com evidence.

## Definition Of Done

ASTR pronto exige:
- grafo vivo consultavel;
- owner docs por area;
- links codigo->teste->doc;
- classificacao de runtime usage;
- freshness e drift status;
- impacto causal basico;
- comandos read-only;
- testes com fixtures reais.

AVEOR pronto exige:
- intent lock;
- boundary contract;
- proof plan;
- simulation report;
- bridge com AVER;
- bridge com AEMOR;
- scope drift watch;
- human escalation;
- score final;
- testes de bloqueio e sucesso.

## Proximas Acoes
1. Manter ASTR/AVEOR certificados por teste e comando antes de claims.
2. Expandir docs filhas somente quando o runtime crescer; manter AVER como executor verificado e Cartografia como projection.
