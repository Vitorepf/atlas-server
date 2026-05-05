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
| `atlas forge` | Entrar no Atlas AI Programming em intensidade harness/forge. |
| `atlas fix` | Ser alias ou atalho para repair dentro de Atlas AI Programming. |
| `atlas continue` | Retomar o mesmo fluxo com thread, memoria e estado. |
| `atlas ask/chat` | Entrar no Atlas AI Core para conversa, pesquisa ou tarefa geral. |
| App | Usar os mesmos fluxos via API, sem regras paralelas. |
| Worker | Executar decisoes ja tomadas pelo pipeline, sem reinventar politica. |

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
