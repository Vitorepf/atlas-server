---
id: atlas-ai-operating-system
type: engineering_knowledge
title: Atlas AI Operating System
status: active
category: architecture
priority: 100
summary: Arquitetura macro do Atlas AI como sistema operacional de orquestracao, com dominios, pipeline comum, regras anti-duplicacao e fronteiras entre dev, forge, decide, memoria, tools e evolucao automatica.
tags:
  - atlas-ai
  - orchestration
  - architecture
  - programming
  - personal-development
  - finance
  - self-improvement
capabilities:
  - atlas_ai_operating_system
  - domain_flow_registry
  - unified_capability_pipeline
  - anti_duplication_governance
  - programming_pipeline
  - personal_development_pipeline
  - finance_pipeline
  - self_evolution_pipeline
decisions:
  - Atlas AI e o nome da inteligencia de orquestracao do Atlas.
  - Comandos, telas e workers sao superficies; a inteligencia vive em fluxos canonicos reutilizaveis.
  - Nenhuma capacidade horizontal pode existir apenas em uma superficie especifica quando e util ao sistema inteiro.
  - Domain Profile / Flow Profile e a forma canonica de representar dominios e fluxos operacionais.
  - AtlasAiPolicyService resolve politicas por global, domain, flow, surface, risco e session override.
  - Atlas Decide compila a decisao operacional e emite Decision Receipt; ele nao deve virar fluxo de produto separado.
  - Atlas Decide escolhe automaticamente o melhor provider/modelo permitido por tarefa; provider/modelo especifico passado pelo operador e override auditado.
  - Atlas Dev e Atlas Forge sao intensidades do mesmo fluxo de programacao, nao produtos concorrentes.
  - Super Tool Runtime e Core do Atlas AI; Forge usa mais, mas nao possui sozinho essa capacidade.
  - Desenvolvimento pessoal, programacao, financas e evolucao automatica devem ter harnesses proprios quando maturarem, mas todos seguem o mesmo pipeline de capacidades.
maintenance:
  - Atualizar este documento antes de criar comando, harness, fluxo ou capability nova em Atlas AI.
  - Ao detectar duplicacao entre comandos, mover a capacidade para uma camada comum antes de expandir comportamento.
  - Todo fluxo novo deve declarar dominio, entrada canonica, pipeline, gates, memoria, evidencias e ownership.
  - Rode atlas engineering knowledge sync --prune e index-code --prune depois de alterar este documento.
related_paths:
  - app/Services/Ai/AtlasDecideService.php
  - app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php
  - app/Services/Ai/AiGatewayService.php
  - app/Services/Ai/AiPromptBuilder.php
  - app/Services/Ai/AtlasOpenBrainContextInjectionService.php
  - app/Services/Engineering/EngineeringHarnessRunnerService.php
  - app/Services/Engineering/EngineeringContextPackService.php
  - app/Services/Tools/AtlasToolGateService.php
  - docs/engineering-knowledge-base/START_HERE.md
  - docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - docs/atlas-cli-5x-claude-code-plan.md
---

# Atlas AI Operating System

Atlas AI e a inteligencia de orquestracao do Atlas.

Ele nao e um comando, uma tela, um provider ou um prompt. Atlas AI e o sistema
que entende a intencao do operador, escolhe o fluxo certo, monta contexto,
aplica politica, executa com ferramentas, valida, repara, registra evidencia e
aprende.

## Problema Que Este Documento Resolve

O Atlas cresceu com muitas capacidades fortes:

- Atlas Decide;
- Atlas Dev;
- Atlas Forge / Engineering Harness;
- Open Brain e memoria;
- Code Intelligence;
- Tool Runtime;
- Quality Gate;
- Repair Loop;
- Engineering Blueprint;
- benchmarks e telemetria;
- anexos, imagens e multimodalidade;
- app, CLI, workers e APIs.

O risco e cada capacidade entrar em apenas uma superficie. Exemplo ruim:
imagem existir em `atlas ask`, mas nao existir no `atlas dev`; repair existir em
`atlas fix`, mas nao no cockpit; context pack existir no Forge, mas nao no Dev.

A regra macro e: capacidade horizontal pertence ao Atlas AI, nao a um comando.

## Nome Da Inteligencia

O nome canonico da camada e:

```text
Atlas AI
```

Nomes de subcamadas:

| Nome | Papel |
|---|---|
| Atlas AI Core | Pipeline comum, dominio, intent, contexto, politica, executor, gates, repair, evidencia e memoria. |
| Atlas AI Programming | Fluxo completo de programacao: dev, forge, fix, review, refactor, QA, tests, release e benchmarks. |
| Atlas AI Personal Development | Dominio implemented/ready para desenvolvimento pessoal privado, nao clinico e plan-only: reflexao, rotina, foco, energia, aprendizado, objetivos e recuperacao. |
| Atlas AI Finance | Dominio implemented/ready para analise financeira enterprise review-only: pesquisa, risco, portfolio, tese, macro, earnings, noticias, compliance, backtest e forge sem execucao de mercado. |
| Atlas AI Curator | Fluxo de curadoria e evolucao automatica dos proprios processos do Atlas. |

## Superficies Nao Sao Fluxos

Comandos e telas sao apenas entradas:

| Superficie | Deve fazer |
|---|---|
| `atlas dev` | Entrar no Atlas AI Programming em modo cockpit/programacao. |
| `atlas forge` | Entrar no Atlas AI Programming como `programming.forge`, com `forge_contract`, Engineering Harness e evidencia obrigatoria. |
| `atlas fix` | Ser alias fino para `atlas dev --repair`, com `fix_contract`, `--plan-only` auditavel e flow `programming.repair`. |
| `atlas continue` | Retomar o mesmo fluxo por `atlas:cli:dev`, preservando thread, memoria, profile, intent, modelo e Open Brain em `resume_contract`. |
| `atlas ask/chat` | Entrar no Atlas AI Core para conversa, pesquisa ou tarefa geral. |
| App | Usar os mesmos fluxos via API, sem regras paralelas. |
| Worker | Executar decisoes ja tomadas pelo pipeline, sem reinventar politica. |

O `SurfaceAdapterRegistry` canoniza aliases humanos antes de qualquer selecao:
`atlas ask/chat` entram por `atlas_cli_chat`; `atlas dev/fix/continue` entram
por `atlas_cli_dev`; `atlas forge` entra por `atlas_cli_forge`; `atlas_api`
entra por `atlas_api_interaction`. Alias e conveniencia de UX, nao produto
paralelo.

Quando Forge usa a implementacao interna de `atlas:cli:dev`, o Kernel Pipeline
ainda preserva `surface_id=atlas_cli_forge`. O executor pode ser compartilhado;
a identidade da entrada nao pode ser apagada.
O `forge_contract` nasce em `ProgrammingSurfaceContractFactory`, nao no command,
porque ele e contrato do dominio Programming com a surface Forge.
O `programming_chat_contract` tambem nasce nessa factory, mantendo chat dev,
dispatch e Kernel Pipeline sob o mesmo contrato de Programming.
O `fix_contract` nasce nessa factory tambem: `atlas fix` preserva
`surface=atlas_cli_fix` como origem auditavel, mas declara
`canonical_surface=atlas_cli_dev`, flow `programming.repair` e runtime
`dev_repair_executor`.

`atlas continue` preserva `surface=atlas_cli_continue` apenas como origem de
UX, mas o `resume_contract` declara `canonical_surface=atlas_cli_dev` e
`target_surface=atlas_cli_dev`. Esse contrato tambem nasce em
`ProgrammingSurfaceContractFactory`, nao no command, para que retomar um fluxo
nunca crie runtime paralelo nem shape proprio fora do dominio Programming.

`atlas dev` tambem publica `model_selection_contract` no preflight e no
`dev_execution_plan`: a autoridade continua sendo `atlas_decide`, o modo fica
em `auto_best_allowed`, `auto_best_available` ou `manual_override`, e qualquer
provider/modelo passado pelo operador vira override auditavel.

`atlas:ai:chat --dev` publica o mesmo tipo de contrato no payload do job com
surface `atlas_ai_chat`. Assim, interativo, one-shot e chat de programacao
mantem a mesma autoridade de selecao: a surface pode receber preferencia do
operador, mas nao decide modelo por conta propria.

O shape desse contrato pertence ao Kernel/Decision em
`ModelSelectionContractFactory`. Surfaces nao devem duplicar schema, modos ou
autoridade; elas apenas pedem o contrato compartilhado para sua surface.

Se uma capacidade e boa para mais de uma superficie, ela deve viver em uma
camada comum.

## Domain Profile E Flow Profile

O Atlas AI usa profiles para evitar que comandos virem produtos separados.

```text
Domain Profile = vertical operacional
Flow Profile   = modo de trabalho dentro da vertical
```

Exemplos:

| Domain Profile | Flow Profiles |
|---|---|
| `programming` | `programming.dev`, `programming.forge`, `programming.qa`, `programming.security`, `programming.refactor` |
| `finance` | `finance.market_research`, `finance.portfolio_analysis`, `finance.risk_review` |
| `personal_development` | `personal_development.daily_review`, `personal_development.weekly_review`, `personal_development.focus_plan` |
| `self_improvement` | `self_improvement.docs_drift_review`, `self_improvement.capability_gap_scan`, `self_improvement.proposal_generation` |
| `curation` | Conceito historico; usar `self_improvement.*` ate existir Curator dedicado. |

Profile nao e modelo. Profile pode escolher modelo, tools, gates e autonomia,
mas um provider/modelo nunca deve definir o fluxo.

O contrato operacional desses profiles e exposto por `atlas:ai:domains`,
`GET /ai/domains` e pela tool MCP/Open Brain `atlas_domain_catalog`. O catalogo
inclui scorecard de onboarding por dominio, contadores
`ready`/`scaffold`/`executable_incomplete` e filtro
`--onboarding-status`/`onboarding_status`; dominio novo entra primeiro como
scaffold auditavel e so vira pronto quando passa nas fases de charter, profile,
context, orchestrator, runtime, gates, learning, surface e maturity gate.

## Pipeline Canonico

Todo dominio do Atlas AI deve seguir este pipeline:

```text
input
  -> domain resolution
  -> intent classification
  -> domain profile resolution
  -> flow profile resolution
  -> context assembly
  -> policy resolution
  -> Atlas Decide decision receipt
  -> executor selection
  -> execution
  -> validation gates
  -> repair or escalation
  -> evidence packet
  -> memory and learning
  -> operator-facing summary
```

Nenhum dominio deve pular gates, evidencia ou memoria quando a tarefa e
operacionalmente relevante.

## Camadas Horizontais

Estas capacidades pertencem ao Atlas AI Core e devem ser reutilizadas por todos
os dominios quando fizer sentido:

| Camada | Responsabilidade |
|---|---|
| Intent | Classificar tarefa, risco, dominio, complexidade e tipo de acao. |
| Context | Montar contexto com repo, memoria, knowledge, code refs, arquivos e historico. |
| Policy | Decidir provider, modelo, permissao, budget, privacidade, gates e autonomia. |
| Decide | Compilar profile, policy, contexto e risco em provider/modelo/grafo/fallback/evidence contract. |
| Tools | Super Tool Runtime: registry, planner, policy, executor, normalizer, evidence store, reporter e learning. |
| Memory | Recuperar contexto, registrar aprendizado, governar privacidade e qualidade. |
| Validation | Rodar testes, quality scan, release gate, review gate, score e blockers. |
| Repair | Transformar falha em proxima tentativa estruturada com criterios de parada. |
| Evidence | Gerar pacote final: diff, testes, artifacts, gates, riscos, trace e decisoes. |
| Evolution | Detectar lacunas do proprio processo e propor melhorias com curadoria. |

## Dominios Principais

### Atlas AI Programming

Objetivo: ser o sistema operacional de programacao do Atlas.

Escopo minimo:

- implementacao;
- fix/repair;
- review;
- refactor;
- testes;
- QA;
- visual/e2e;
- banco e migrations;
- release;
- benchmarks;
- memoria de engenharia;
- code intelligence;
- tool gates;
- patch artifacts;
- regressao e rollback.

Pipeline especifico:

```text
mensagem do operador
  -> programming intent
  -> profile: programming.dev/programming.forge/programming.qa/...
  -> programming context pack
  -> AtlasAiPolicyService
  -> Atlas Decide / Decision Receipt
  -> executor: simple provider, dev_repair_executor ou engineering_harness
  -> provider/tool/runtime execution
  -> quality matrix
  -> repair loop
  -> final evidence packet
  -> memory delta / engineering learning
```

`atlas dev` e `atlas forge` devem usar este mesmo pipeline.

Diferença:

- `atlas dev`: cockpit principal e entrada diaria.
- `atlas forge`: intensidade harness/enterprise para tarefas maiores.
- `atlas fix`: atalho de repair dentro do mesmo pipeline.

Status implementado: `atlas:cli:dev` anexa um `kernel_pipeline` comum ao
`dev_execution_plan` no cockpit interativo e no one-shot com prompt. A diferenca
entre os dois modos passa a ser apenas `input_mode=interactive|one_shot` e a
forma de captura do input; ambos carregam o mesmo stage order, flow
`programming.dev`/`programming.forge`, slot manifest e guards de execucao antes
de chegar ao `atlas:ai:chat`. Esse contrato compacto e montado por
`KernelPipelineDevPlanBuilder`, nao por cada surface separadamente. A execucao
real ainda fica nos caminhos legados protegidos ate a migracao do runtime, com
`provider_execution_allowed=false` e `runtime_execution_allowed=false` no
contrato do kernel.

`atlas:ai:chat --dev` tambem preserva esse contrato. Quando recebe um
`--dev-plan` legado sem `kernel_pipeline`, ele anexa um plano scaffold seguro
com `surface=atlas_ai_chat` usando o mesmo builder; quando recebe o plano novo
do `atlas:cli:dev`, ele mantem o binding original. Isso impede que a fronteira
CLI -> chat volte a criar dois fluxos invisiveis.
O payload do chat tambem carrega `programming_chat_contract`, que liga
explicitamente a surface `atlas_ai_chat` ao `AtlasProgrammingOrchestrator`, ao
`programming_flow`, ao executor escolhido e ao `kernel_pipeline` aceito pelo
guard.

Essa fronteira tambem e validada por `KernelPipelinePlanGuard`: se uma surface
ou sessao antiga enviar stage order, hash canonico, schema ou flags de execucao
adulteradas, o chat falha fechado em preflight com
`atlas_kernel_pipeline_contract_violation` e nao cria job. O contrato e
auditavel, mas ainda scaffold-safe ate a migracao do runtime real. As allowlists
de surfaces, commands, flows e input modes ficam em `KernelPipelineContract`; o
shape obrigatorio de `kernel_pipeline_contract` tambem vem desse contrato. O
builder valida o plano e o contrato que acabou de criar antes de devolver para a
surface. Quando um plano declarado ja vem com `kernel_pipeline`, o chat chama
`assertValidPlanAndContract()` e rejeita tambem contrato ausente, origem nao
allowlisted ou metadados que tentem devolver decisao/execucao para a surface.

O guard grava `KERNEL_PIPELINE_ACCEPTED` e `KERNEL_PIPELINE_REJECTED` no
Evidence Ledger via `KernelPipelineAuditService` quando a tabela esta
disponivel. Isso permite auditar quais surfaces estao usando o contrato
corretamente e detectar drift sem depender de log textual, sem duplicar a
logica em CLI/API. O payload guarda hashes, binding de surface, origem do
`kernel_pipeline_contract` e bloqueios de execucao, mas nao guarda prompt bruto.

`atlas:ai:pipeline` e `POST /ai/pipeline` tambem participam dessa auditoria:
modo `plan` e apenas inspecao, mas `--execute`/`execute=true` grava
`KERNEL_PIPELINE_ACCEPTED` via `KernelPipelineAuditService`, com
`emitter_stage=atlas.ai_pipeline.scaffold`, e retorna `ledger_event` no payload.
Assim ate a ponte scaffold do `atlas.run` fica replayavel sem abrir provider ou
runtime real, e a logica de auditoria nao fica duplicada entre CLI e API.

No Data Plane, `AiWorker` tambem aplica `KernelPipelineRuntimeGuard` antes de
resolver provider. Se um job de programacao carrega `dev_execution_plan` ou
`kernel_pipeline`, o worker valida plano e `kernel_pipeline_contract` juntos; se
o contrato estiver ausente/adulterado, a tentativa falha com
`kernel_pipeline_contract_violation`, emite stream event de policy e registra
`KERNEL_PIPELINE_REJECTED` no Evidence Ledger. Quando o contrato e valido, o
worker registra `KERNEL_PIPELINE_ACCEPTED` com
`emitter_stage=atlas.ai_worker.kernel_pipeline_runtime_guard` antes de abrir o
provider. O proprio `KernelPipelineRuntimeGuard` normaliza o plano auditavel e
o contexto de ledger para o worker nao duplicar semantica de evidencia. Isso
fecha o caminho legado sem forcar ainda a migracao completa do runtime.

Esses eventos tambem ja possuem read model operacional. `atlas:ai:ledger
<envelope> --kernel --json` e `GET /ai/ledger/{envelope}?kernel=1` mostram se
o contrato foi aceito ou rejeitado por envelope, com surface, flow, input mode e
violacoes. `atlas:ai:kernel-pipeline-report --hours=24 --json` e
`GET /ai/kernel-pipeline/report?hours=24` mostram o mesmo contrato por janela,
com filtros por status, surface, flow, input mode e emitter stage. `GET
/ai/observability` inclui `kernel_pipeline` agregando accepted/rejected,
surfaces, emitter stages, flows, input modes, violations e eventos recentes. Isso transforma o
contrato `atlas.run` em sinal visivel para operador, dashboard, Curator e
Self-Improvement, em vez de deixar a arquitetura-mae escondida dentro do payload
do job.

### Atlas AI Personal Development

Objetivo: orquestrar desenvolvimento pessoal com rigor de sistema, nao apenas
chat motivacional.

Escopo atual implemented/ready:

- reflexao;
- revisao diaria;
- revisao semanal;
- design de habitos;
- plano de foco;
- plano de aprendizado;
- revisao de energia;
- decomposicao de objetivos;
- plano de recuperacao;
- `personal_development.forge`.

Limites obrigatorios:

- privado por default;
- linguagem operacional e nao clinica;
- sem diagnostico psicologico;
- sem tratamento medico;
- sem mutacao automatica de calendario, tarefas ou sistemas externos.

Pipeline canonico:

```text
sinal ou pedido pessoal
  -> personal intent
  -> context pack pessoal
  -> privacy and consent gate
  -> plan policy
  -> action plan
  -> review checkpoint
  -> follow-up
  -> memory update
  -> evolution recommendation
```

Esse dominio usa runtime plan-only. Sucesso pessoal deve ser avaliado por
evidencias, aderencia, revisao humana, privacy e safety, nao por mutacao
automatica de sistemas pessoais.

### Atlas AI Finance

Objetivo: fluxo enterprise para financas, mercado, risco e operacao.

Escopo atual implemented/ready:

- pesquisa de mercado;
- risk review;
- portfolio analysis;
- trade thesis review;
- macro review;
- earnings review;
- news impact;
- compliance review;
- backtest plan;
- `finance.forge`.

Limites obrigatorios:

- review-only;
- autonomia baixa por default;
- sem ordens de mercado;
- sem broker execution;
- sem rebalanceamento ou transferencia;
- sem recomendacao automatica personalizada como instrucao executavel.

Pipeline canonico:

```text
pedido financeiro ou sinal de mercado
  -> finance intent
  -> data provenance gate
  -> market context pack
  -> risk and compliance policy
  -> analysis or review packet
  -> human approval checkpoint
  -> post-decision review
  -> evidence and audit trail
  -> memory update
```

Regra: qualquer dado financeiro temporariamente instavel precisa de fonte,
timestamp, provenance e gate de risco. Finance nunca deve gerar payload de ordem
ou executar acao de mercado.

### Atlas AI Curator

Objetivo: evoluir o proprio Atlas sem virar caos automatico.

Escopo:

- detectar duplicacao;
- detectar capacidade solta;
- propor refactors de fluxo;
- manter docs canonicos;
- promover learnings;
- criar issues/propostas;
- medir se mudancas melhoraram resultado;
- prevenir regressao de arquitetura.

Pipeline:

```text
evidencia de uso ou falha
  -> classify process gap
  -> propose improvement
  -> check docs and ownership
  -> implement or create proposal
  -> run gates
  -> update knowledge base
  -> measure outcome
```

Curadoria automatica pode propor e preparar mudancas, mas alteracoes de alto
risco precisam de gates e revisao.

## Atlas Decide

Atlas Decide nao e um produto separado. Ele e a camada de decisao dentro do
Atlas AI Core.

Responsabilidades:

- escolher provider/modelo;
- escolher o melhor modelo permitido para a tarefa por padrao;
- registrar override manual de provider/modelo quando o operador pedir;
- publicar `provider_selection.selection_mode` com vocabulario fechado:
  `auto_best_allowed`, `auto_best_available` ou `manual_override`;
- publicar `model_selection_authority=atlas_decide`, deixando claro que
  comando, surface, provider e tool nao sao autoridade de modelo;
- estimar risco e complexidade;
- aplicar politica de dominio;
- decidir executor;
- propagar contratos;
- registrar decisao auditavel.

Nao deve:

- duplicar fluxo de dev;
- tratar override manual como novo produto interno;
- executar provider diretamente sem contrato;
- substituir context assembly;
- substituir validation/repair.

## Regras Anti-Duplicacao

1. Se uma feature e util para mais de uma superficie, ela pertence ao Core.
2. Se uma decisao muda provider/modelo/permissao/gate, ela passa por Policy.
3. Se uma tarefa altera estado real, ela precisa de Evidence Packet.
4. Se um fluxo faz codigo, ele passa por Atlas AI Programming.
5. Se um fluxo usa memoria sensivel, ele passa por privacy/provider-safety.
6. Se uma ferramenta roda localmente, ela passa pelo Tool Runtime quando houver
   registry/normalizer aplicavel.
7. Se um gate existe no Forge e e relevante para Dev, Dev deve consumir a mesma
   capacidade ou declarar por que nao.
8. Comandos alias nao podem implementar logica propria quando o fluxo canonico
   existe.

## Modelo De Ownership

| Camada | Owner canonico |
|---|---|
| Dominio e intent | Atlas AI Core |
| Provider/model/policy | Atlas Decide + policy profiles |
| Prompt/contexto | Context assembly + Open Brain |
| Programacao | Atlas AI Programming |
| Harness de engenharia | Engineering Harness |
| Memoria | Memory Core / Open Brain |
| Ferramentas | Super Tool Runtime |
| Evidencia | Evidence packet por dominio |
| Documentacao | Engineering Knowledge Base |

## Roadmap De Organizacao

### Fase 1 - Nomear e congelar arquitetura

- criar este documento;
- atualizar START_HERE e README;
- declarar Atlas AI como camada de orquestracao;
- parar de criar comandos com logica propria quando existe fluxo comum.

### Fase 2 - Unificar Programming

- criar `AtlasProgrammingPipeline`;
- mover intent, context, policy, executor, gates, repair e evidence para essa
  camada;
- fazer `atlas dev`, `atlas forge`, `atlas fix` e `atlas continue` chamarem o
  mesmo pipeline;
- remover duplicacao residual em comandos e worker.

### Fase 3 - Capability Registry

- registrar capacidades horizontais: image attachments, files, tools, memory,
  code intelligence, gates, repair, telemetry;
- cada superficie declara o que suporta via registry;
- teste deve falhar quando uma capability horizontal aparece em uma superficie
  e fica ausente nas outras sem justificativa.

Status implementado: `SurfaceCapabilityParityService` cruza o Capability
Registry com `SurfaceAdapterRegistry`. Capabilities de input, memoria, contexto,
tools e Human Knowledge precisam aparecer nas surfaces operacionais equivalentes
(`atlas_cli_dev/chat/forge`, app, API, worker, MCP read-only e AtlasVault) via
`SurfaceCapability`; se o registry disser que uma surface suporta algo que o
adapter real nao sustenta, ou se faltar regra de paridade, `atlas:ai:architecture-validate`
falha. O estado esperado e paridade valida com `skipped=[]`; skip nao e warning,
e quebra de contrato. O payload tambem expoe esse contrato como
`kernel.static_scan.ap33_surface_capability_parity`, para que a familia de
anti-patterns AP fique completa no validador central.

Status adicional: `AtlasCapabilityRegistry` agora exige cobertura completa de
surface por capability. Toda capability precisa declarar cada surface conhecida
como `required`, `optional` ou `not_supported` com motivo; omissao ou classificacao
duplicada falha o build e aparece em
`kernel.static_scan.ap34_capability_surface_coverage`. `atlas_vault` e surface
formal, mas nao recebe `memory_recall`, `context_compose` nem `tools_runtime`;
ele e workspace humano governado e projection gerenciada, nao fonte operacional
crua.

Status adicional: `SurfaceCapabilityParityService` agora tambem exige cobertura
do outro lado da matriz. Todo adapter registrado em `SurfaceAdapterRegistry`
precisa aparecer no mapa operacional de paridade; adapter novo sem mapeamento
falha o build e aparece em
`kernel.static_scan.ap35_surface_adapter_parity_map_coverage`.

Status adicional: o read model do Kernel Pipeline agora publica `health`
canonico no replay service: status, taxa de rejeicao, thresholds, reasons e
`review_required`. Ledger CLI, `atlas:ai:kernel-pipeline-report`,
Observability e Self-Improvement consomem esse mesmo campo; se alguem recriar
heuristica paralela ou esconder o health, o validador falha em
`kernel.static_scan.ap36_kernel_pipeline_health_read_model`.

Status adicional: `atlas:ai:architecture-validate --json` publica
`kernel.static_scan.summary` com total de APs, quantos passaram, quantos
falharam, chaves validas, chaves falhas e total de violacoes. Esse resumo e o
contrato para App, CI, dashboard e Curator mostrarem health arquitetural sem
copiar manualmente a lista de APs.

Status adicional: a validacao arquitetural agora tem service compartilhado e
API propria. `AtlasAiArchitectureValidationService` monta o payload unico;
`atlas:ai:architecture-validate` apenas renderiza e
`GET /ai/architecture/validate` expoe o mesmo contrato para App, dashboard,
automacoes e Curator. O static scanner protege isso como
`kernel.static_scan.ap37_architecture_validation_surface`.

Status adicional: Observability agora publica `architecture_validation`, um
resumo compacto do mesmo contrato arquitetural para App/dashboard/Curator. Os
read models de SLO, Repair e Kernel Pipeline sao calculados antes da validacao
arquitetural para que a propria auditoria nao polua a janela operacional. O
static scanner protege isso como
`kernel.static_scan.ap38_architecture_validation_observability`.

Status adicional: a paridade do contrato tambem virou AP dedicado. O
`AtlasAiArchitectureValidationService` e a fonte unica do payload; API e CLI
devem provar em teste que renderizam o mesmo contrato estavel, e nao uma copia
manual. O static scanner protege isso como
`kernel.static_scan.ap39_architecture_validation_contract_parity`.

Status adicional: Open Brain/MCP agora tambem tem a tool read-only
`atlas_architecture_validate`. Ela consulta o mesmo
`AtlasAiArchitectureValidationService`, publica summary ou payload completo e
declara `writes=false`, dando ao Curator e a outras IAs um caminho provider-safe
para auditar a arquitetura sem shellar CLI. O static scanner protege isso como
`kernel.static_scan.ap40_architecture_validation_mcp_tool`.

### Fase 4 - Evidence Packet Unico

- padronizar pacote final por dominio;
- Programming deve sempre poder responder: o que mudou, por que, como validou,
  quais riscos sobraram, quais memorias/evidencias foram criadas.

### Fase 5 - Personal Development e Finance

- manter docs canonicos de dominio alinhados ao registry;
- evoluir harnesses proprios sem romper os limites review-only/plan-only;
- ampliar gates de privacidade, risco, medida e evidencia;
- nao misturar esses dominios com Programming.

### Fase 6 - Curadoria Evolutiva

- transformar falhas reais em propostas;
- detectar duplicacao automaticamente;
- manter docs e code intelligence sincronizados;
- medir impacto de melhorias.

## Definition Of Done Para Um Fluxo Atlas AI

Um fluxo so esta maduro quando:

- tem dominio e owner claro;
- usa o pipeline canonico;
- tem contexto e memoria governados;
- tem policy profile;
- tem executor definido;
- tem gates;
- tem repair/escalation;
- gera evidence packet;
- registra aprendizado quando aplicavel;
- tem docs canonicos;
- tem testes que impedem regressao de duplicacao.

## Decisao Final

Atlas AI deve ser tratado como um sistema operacional de orquestracao.

O objetivo nao e ter muitos comandos. O objetivo e ter uma inteligencia central
que usa todas as capacidades certas no momento certo, em qualquer superficie,
com evidencia, memoria, reparo e evolucao continua.
