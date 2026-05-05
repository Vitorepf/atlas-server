---
id: atlas-ai-architecture-audit
type: engineering_knowledge
title: Atlas AI Architecture Audit
status: active
category: architecture
priority: 99
summary: Analise rigorosa da documentacao canonica do Atlas AI, identificando capacidades existentes, verdades concorrentes, duplicacoes, lacunas de orquestracao e plano para reorganizar o sistema em pipelines bem definidos.
tags:
  - atlas-ai
  - architecture
  - audit
  - orchestration
  - programming
  - documentation
capabilities:
  - architecture_audit
  - flow_consolidation
  - anti_duplication_governance
  - programming_pipeline
  - context_pack_governance
  - tool_runtime_governance
decisions:
  - O problema principal do Atlas nao e falta de capacidades, e falta de orquestracao unificada.
  - Atlas AI Operating System deve ser a raiz conceitual; os demais documentos devem se encaixar abaixo dele.
  - O corpus resolver-o-que-vale-a-pena contem specs P0 que refinam a arquitetura mae.
  - Domain Profile / Flow Profile deve ser adotado como modelo canonico para dominios e fluxos.
  - AtlasAiPolicyService e a camada faltante entre settings, overrides, Decide, providers, tools e gates.
  - Atlas AI Programming precisa virar pipeline unico consumido por dev, forge, fix, continue, app e worker.
  - Capacidades horizontais devem ter registry comum e testes de cobertura por superficie.
maintenance:
  - Atualizar esta auditoria quando um fluxo macro for reorganizado ou uma duplicacao relevante for removida.
  - Nao usar este documento como substituto dos docs especializados; ele e um mapa de consolidacao.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md
  - docs/engineering-knowledge-base/START_HERE.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - docs/atlas-cli-5x-claude-code-plan.md
  - docs/atlas-cli-final-product.md
---

# Atlas AI Architecture Audit

Esta auditoria cruza a documentacao canonica do Atlas para entender por que a
estrutura ficou espalhada e como reorganizar o sistema sem jogar fora
capacidades boas.

## Corpus Avaliado

Documentos centrais lidos nesta auditoria:

| Documento | Papel |
|---|---|
| `atlas-ai-operating-system.md` | Raiz macro: Atlas AI como sistema operacional de orquestracao. |
| `atlas-ai-resolver-corpus-audit.md` | Auditoria do corpus resolver-o-que-vale-a-pena e promocao de specs P0. |
| `START_HERE.md` / `README.md` | Entrada canonica da Knowledge Base. |
| `atlas-ai-memory-context-core-open-brain.md` | Arquitetura longa de memoria, contexto e Open Brain. |
| `open-brain-context-injection.md` | Injecao automatica de memoria/contexto em CLI/app. |
| `context-pack.md` | Documento legado avaliado na auditoria; contrato vivo esta em Open Brain/Memory/Core docs. |
| `code-intelligence.md` | Indice operacional docs->codigo. |
| `engineering-blueprint.md` | Disciplina de contrato, blueprint, task, QA, review e gates. |
| `engineering-blueprint-quality-gates.md` | Regras objetivas de pronto. |
| `super-tool-runtime-core.md` | Registry, policy, executor, normalizer, evidence store e gates de tools. |
| `programming-power-tools-catalog.md` | Catalogo de ferramentas de programacao pesada e anti-duplicacao. |
| `capability-matrix.md` | Matriz legada avaliada na auditoria; status vivo esta em Blueprint Maturity, Super Tool Runtime e Programming Power Tools. |
| `atlas-cli-5x-claude-code-plan.md` | Plano de produto para superar Claude Code por harness, contexto, gates e repair. |
| `atlas-cli-fair-claude-benchmark.md` | Protocolo cientifico Fair Claude. |
| `atlas-cli-final-product.md` | Superficie terminal final. |
| `atlas-ai-telemetry.md` / performance docs | Medicao de qualidade, custo, continuidade e evolucao. |
| `paste-image-setup.md` e planos paste-image | Exemplo concreto de capability horizontal que nasceu presa a superficie. |

Corpus complementar classificado:

| Documento | Classificacao | Papel |
|---|---|---|
| `resolver-o-que-vale-a-pena/docs/specs/2026-05-03-atlas-domain-profile-orchestration-architecture.md` | P0 | Modelo mae de Domain Profile / Flow Profile. |
| `resolver-o-que-vale-a-pena/docs/specs/2026-05-02-atlas-decide-final-architecture.md` | P0 | Decide como compilador operacional com Decision Receipt. |
| `resolver-o-que-vale-a-pena/docs/specs/2026-05-03-atlas-programming-product-architecture.md` | P0 | Programming como especializacao do modelo de domain profiles. |
| `resolver-o-que-vale-a-pena/root-md/Atlas_AI_Harness_Super_Tool_Runtime_Core.md` | P0 | Super Tool Runtime como control-plane core de tools/evidencia. |
| `resolver-o-que-vale-a-pena/root-md/Atlas_Gaps_Achamos_Nao_Esquecer.md` | P2 | Sinais para o futuro domain Personal Development. |

## Diagnostico Executivo

O Atlas ja tem a maior parte das pecas de um sistema muito mais forte que um
CLI direto de modelo. O problema real e que as pecas foram implementadas por
frentes diferentes e algumas entraram como feature local de comando, em vez de
capability central do Atlas AI.

Sintoma principal:

```text
capacidade boa
  -> nasce em um comando/tela/service especifico
  -> outro fluxo nao usa
  -> nova correcao duplica logica
  -> metadata/gates/repair ficam diferentes
  -> operador sente que o produto e inconsistente
```

Conclusao:

```text
Nao falta ambicao.
Nao falta documentacao.
Nao falta ferramenta.
Falta um pipeline canonico que obrigue todas as superficies a passarem pelos mesmos contratos.
```

## Verdades Canonicas Que Ja Existem

### 1. Atlas e a superficie; providers sao motores

`atlas-cli-final-product.md` e `atlas-cli-5x-claude-code-plan.md` concordam que
Claude, Codex, Gemini e futuros modelos sao motores substituiveis. O valor do
Atlas esta em memoria, contexto, decisao, gates, repair, telemetria e
continuidade.

Implicacao arquitetural: provider adapter nao pode ser dono de fluxo.

### 2. Memoria pertence ao Atlas, nao ao provider

`atlas-ai-memory-context-core-open-brain.md` define que Postgres, Vault, traces,
verbatim, knowledge e context packs sao o cerebro do Atlas. Arquivos de provider
como `CLAUDE.md`/`AGENTS.md` sao projecoes ou adaptadores.

Implicacao: todo fluxo importante deve pedir contexto ao Atlas, nao ao provider.

### 3. Context Pack e artefato, nao texto improvisado

Memory Core, Open Brain e Context Pack docs repetem a mesma regra: contexto
deve ser pequeno, rastreavel, provider-safe, com hash e refs.

Implicacao: `atlas dev`, `forge`, `continue`, app e worker devem consumir o
mesmo contrato de contexto.

### 4. Engenharia precisa de contrato antes de codigo

Engineering Blueprint define:

```text
Product Intent
-> Blueprint
-> Task Contract
-> Context Pack
-> Harness Run
-> QA/Review/Postgres Gates
-> Memory Delta
```

Implicacao: tarefas medias/dificeis no `atlas dev` nao podem continuar sendo
apenas prompt bruto com quality gate basico.

### 5. Tools precisam ser governadas e evidenciadas

Super Tool Runtime define registry, policy, approval, executor, normalizer,
evidence store e gates. Programming Power Tools define autoridade por grupo
para evitar duplicacao de scanners e findings.

Implicacao: Dev nao deve ter seu proprio quality model simples quando ja existe
Tool Evidence Gate capaz de servir o sistema inteiro.

### 6. 5x contra Claude Code vem de harness, nao de modelo

Docs 5x e Fair Claude deixam claro: o Atlas vence reduzindo intervencao humana
com contexto, gates, repair, final packet e replay.

Implicacao: `atlas dev` precisa produzir evidence packet e repair capsule
profissionais, nao apenas resposta final.

### 7. Profile nao e preset de modelo

O corpus resolver deixa claro que `programming.dev`, `programming.forge`,
`programming.qa` e `programming.security` sao flow profiles. Eles podem impor
modelos, tools e gates, mas nao sao a mesma coisa que "Claude Opus" ou
"Codex high".

Implicacao: o Atlas precisa de `AtlasAiPolicyService` para resolver policy por
camadas antes de Decide e antes de execucao.

### 8. Decide deve emitir receipt, nao virar executor

Atlas Decide deve compilar intencao, risco, contexto, provider/modelo, grafo,
fallback, gates e evidencia minima em um Decision Receipt. O Domain
Orchestrator executa.

Implicacao: `AtlasDecideService` nao deve absorver Programming, Finance ou
Personal Development. Ele entrega contrato para o orchestrator certo.

## Onde A Bagunca Aparece

### A. Superficie virou fluxo

Exemplos:

- `atlas dev` interativo vs one-shot ja tiveram caminhos diferentes.
- `/fix` existia como comando separado antes de entrar no cockpit.
- imagem nasceu como preocupacao de `atlas ask/chat/dev`, mas o design ficou no
  `AiChatCommand`, nao em um registry horizontal de input multimodal.
- `atlas forge` tem Harness completo; `atlas dev` tem parte do contexto e parte
  do repair, mas nem sempre os mesmos gates.

Regra de correcao: comando so deve capturar input e chamar pipeline canonico.

### B. Existem tres conceitos de contexto

1. `AiContextPackBuilder` / `AiPromptBuilder`.
2. `AtlasOpenBrainContextInjectionService`.
3. `EngineeringContextPackService`.

Eles nao sao necessariamente errados, mas precisam de fronteira formal:

| Camada | Deve virar |
|---|---|
| AiContextPackBuilder | Contexto base de conversa/tarefa. |
| OpenBrainInjection | Politica de memoria/knowledge/code refs para prompt real. |
| EngineeringContextPack | Contexto de engenharia profundo para Programming/Harness. |

Problema atual: o Dev pode receber Open Brain, mas nao necessariamente o mesmo
Engineering Context Pack rico do Harness.

### C. Existem gates em niveis diferentes

| Gate | Onde aparece |
|---|---|
| Quality basico | `AtlasCliQualityService`: git status, diff, teste. |
| Engineering controls | Harness Runner. |
| Tool Evidence Gate | Super Tool Runtime. |
| Release Gate | Tool Release Gate / CLI release. |
| Blueprint gates | QA, review, Postgres, acceptance. |
| Telemetry health | AI telemetry/performance engine. |

Problema: nao existe ainda uma `Programming Quality Matrix` que escolha e
orquestre esses gates por tipo de tarefa.

### D. Repair existe em mais de uma semantica

Docs 5x querem repair capsule estruturada. Programming Orchestrator ja tem
repair contract. Worker tem native repair. Harness tem replay/attempts.

Problema: falta uma taxonomia unica de falha e uma capsule unica de repair para
Dev e Forge.

### E. Atlas Decide ainda nao e claramente "policy layer"

Os docs dizem que Atlas Decide escolhe provider/modelo/policy/risco. Mas a
arquitetura macro precisa impedir que Decide vire outro fluxo paralelo.

Regra: Decide retorna decisao; pipeline executa.

### F. Documentacao e muito rica, mas sem mapa de consolidacao

Antes do `atlas-ai-operating-system.md`, os docs especializados eram bons, mas
faltava uma raiz que dissesse:

- qual dominio manda;
- qual pipeline todos seguem;
- onde capabilities horizontais vivem;
- quando uma feature e duplicacao.

## Mapa De Capacidades E Dono Canonico

| Capacidade | Dono canonico proposto | Observacao |
|---|---|---|
| Domain/intent | Atlas AI Core | Deve classificar programming, personal, finance, curator etc. |
| Provider/model/policy | Atlas Decide | Decide, nao executa. |
| Policy profiles | AtlasAiPolicyService | Resolve global settings, domain, flow, surface, risco e session override. |
| Domain/flow profiles | Atlas Profile Resolver | Separa vertical operacional de fluxo especifico. |
| Contexto base | Context Assembly | Une task, conversa, workspace e policy. |
| Memoria/Open Brain | Memory Context Core | Provider-safe, auditado, com qualidade. |
| Engineering context | Atlas AI Programming | Reutiliza EngineeringContextPack mesmo fora do Harness pesado. |
| Programacao | Atlas AI Programming Pipeline | Unifica dev, forge, fix, continue. |
| Harness pesado | Engineering Harness | Executor de intensidade alta, nao produto separado. |
| Tools | Super Tool Runtime | Registry, planner, policy, executor, normalizer, evidence, reporter e learning. |
| Gates de programacao | Programming Quality Matrix | Orquestra basic quality, tool gate, blueprint gates e release gates. |
| Repair | Programming Repair Loop | Taxonomia e capsule unica. |
| Evidence final | Evidence Packet | Contrato por dominio. |
| Telemetria | Atlas AI Telemetry | Mede qualidade, custo, continuidade e learning. |
| Evolucao | Atlas AI Curator | Detecta lacunas, duplicaçoes e recomenda melhorias. |

## Arquitetura Reorganizada Recomendada

### Nivel 1 - Atlas AI Core

Responsavel por:

- domain resolution;
- intent classification;
- policy handoff para Atlas Decide;
- capability registry;
- context contract base;
- evidence packet contract base;
- privacy/provider-safety comum;
- telemetry hooks.

### Nivel 2 - Domain Pipelines

Cada dominio implementa o pipeline canonico:

```text
input
-> intent
-> context
-> policy
-> executor
-> validation
-> repair/escalation
-> evidence
-> memory
```

Dominios planejados:

- `Atlas AI Programming`;
- `Atlas AI Personal Development`;
- `Atlas AI Finance`;
- `Atlas AI Curator`.

### Nivel 3 - Surfaces

Superficies chamam pipelines:

| Superficie | Chama |
|---|---|
| `atlas dev` | `AtlasProgrammingPipeline` |
| `atlas forge` | `AtlasProgrammingPipeline` com intensidade harness |
| `atlas fix` | `AtlasProgrammingPipeline` com intent repair |
| `atlas continue` | Pipeline original + state resume |
| app AI programming | `AtlasProgrammingPipeline` |
| worker | Executor selected pelo pipeline |

## Atlas AI Programming: Pipeline Alvo

```text
raw input
  -> ProgrammingIntentClassifier
  -> ProgrammingTaskContractBuilder
  -> ProgrammingContextCompiler
  -> AtlasDecide policy/profile
  -> ProgrammingExecutorSelector
  -> executor:
       simple_provider
       dev_repair_executor
       engineering_harness
  -> ProgrammingQualityMatrix
  -> ProgrammingRepairLoop
  -> ProgrammingEvidencePacket
  -> MemoryDelta / EngineeringLearning
```

### Responsabilidades por classe

| Classe proposta | Responsabilidade |
|---|---|
| `AtlasProgrammingPipeline` | Orquestrar o fluxo completo. |
| `ProgrammingIntentClassifier` | Detectar implementacao, bugfix, repair, review, refactor, db, ui, security, harness. |
| `ProgrammingTaskContractBuilder` | Transformar prompt solto em mini contrato ou consumir task contract existente. |
| `ProgrammingContextCompiler` | Unir repo profile, Open Brain, EngineeringContextPack, code refs, likely files e tests. |
| `ProgrammingExecutorSelector` | Escolher simple/dev_repair/harness com policy, risk, harnessability e operator intent. |
| `ProgrammingQualityMatrix` | Escolher gates por task type e avaliar resultado. |
| `ProgrammingRepairLoop` | Gerar repair capsule e controlar iteracoes. |
| `ProgrammingEvidencePacketBuilder` | Produzir final packet auditavel. |

## Prioridades De Arrumacao

### P0 - Parar a expansao da bagunca

Antes de adicionar features novas:

- comandos novos nao podem ter logica de negocio propria;
- toda capability horizontal precisa de owner;
- docs novos devem apontar para o pipeline que consomem.

### P1 - Criar `AtlasProgrammingPipeline`

Esta e a primeira refatoracao real.

Objetivo: `atlas dev`, `forge`, `fix`, `continue` e app programming devem
passar por uma entrada comum.

DoD:

- one-shot e cockpit usam o mesmo planner;
- repair nao sai do fluxo;
- harness e escalacao, nao produto paralelo;
- context compiler unico;
- dispatch metadata igual entre dev e forge.

### P2 - Criar `ProgrammingContextCompiler`

Unificar:

- Ai context pack;
- Open Brain injection;
- Engineering context pack;
- code intelligence refs;
- tool evidence refs;
- likely files;
- validation commands.

DoD:

- o prompt real recebe hash/contexto efetivo;
- nao duplica Open Brain;
- Dev recebe contexto de engenharia quando a tarefa e media/dificil;
- Harness continua recebendo contexto completo.

### P3 - Criar `ProgrammingQualityMatrix`

Unificar gates por tipo de tarefa:

- bugfix: teste focado + diff check;
- refactor: typecheck/lint/suite relevante;
- UI: visual smoke + screenshot/e2e;
- DB: migration/schema + Postgres review;
- security: secret/security/dependency scan;
- release: release gate.

DoD:

- `unverified` nunca vira `passed`;
- Tool Evidence Gate entra quando aplicavel;
- Blueprint gates entram quando ha task contract;
- final packet mostra o que rodou e o que ficou pendente.

### P4 - Criar `ProgrammingRepairCapsule`

Unificar repair:

- objetivo original;
- contrato;
- acceptance criteria;
- comando/gate que falhou;
- erro principal;
- diff relevante;
- arquivos alterados;
- arquivos fora de escopo;
- hipotese de causa;
- instrucao de menor correcao.

DoD:

- repair do worker e harness consomem a mesma capsule;
- historico compacto;
- stop rules claras;
- metadata comparavel.

### P5 - Capability Registry horizontal

Registrar capabilities como:

- image attachments;
- file attachments;
- Open Brain;
- code intelligence;
- tool evidence;
- repair;
- quality matrix;
- telemetry;
- permissions;
- checkpoints.

DoD:

- se `atlas chat` suporta imagem e `atlas dev` deveria suportar, teste acusa;
- superficies declaram suporte;
- aliases nao implementam logica duplicada.

## Principais Riscos

| Risco | Consequencia | Mitigacao |
|---|---|---|
| Criar mais um service sem migrar comandos | Mais uma camada solta | Comecar migrando `atlas dev`/`forge` para pipeline. |
| Fazer pipeline grande demais de uma vez | Refactor arriscado | Introduzir facades pequenas com testes de contrato. |
| Misturar Fair Claude com Atlas normal | Benchmark invalido e produto pior | Policy mode explicito e opt-in. |
| Transformar docs em promessa nao implementada | Confusao operacional | Capability matrix deve distinguir implemented/base/partial/doc. |
| Tool gates lentos no dev interativo | UX ruim | Tiers T0-T3 e quality matrix por risco. |
| Context pack gigante | Modelo pior/custo alto | Budget, refs compactas e excluded candidates. |

## Ordem Recomendada De Implementacao

1. Criar interfaces/DTOs do pipeline sem mudar comportamento.
2. Extrair intent atual de `AiChatCommand` para `ProgrammingIntentClassifier`.
3. Extrair `programmingMessagePlan` para `AtlasProgrammingPipeline::plan`.
4. Fazer `AtlasCliDevCommand`, `AiChatCommand`, `AtlasCliFixCommand` e
   `AtlasCliContinueCommand` chamarem o pipeline.
5. Adicionar `ProgrammingContextCompiler` usando o que ja existe.
6. Integrar harnessability na escolha de executor.
7. Substituir quality basico por `ProgrammingQualityMatrix` progressiva.
8. Unificar repair capsule.
9. Padronizar evidence packet.
10. Criar tests de anti-duplicacao por capability/surface.

## Criterio De Sucesso Da Reorganizacao

O Atlas esta organizado quando uma tarefa de programacao media/dificil puder
ser explicada por um unico trace:

```text
o que o usuario pediu
-> qual dominio/intencao foi escolhido
-> qual contexto entrou e por que
-> qual policy/provider/executor foi escolhido
-> quais ferramentas e gates rodaram
-> se houve repair, por que e com qual capsule
-> qual evidencia final prova ou bloqueia o resultado
-> qual memoria/aprendizado foi proposto
```

Se essa historia depender de saber qual comando especifico foi usado, a
arquitetura ainda esta baguncada.
