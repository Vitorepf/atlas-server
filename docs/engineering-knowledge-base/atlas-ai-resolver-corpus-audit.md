---
id: atlas-ai-resolver-corpus-audit
type: engineering_knowledge
title: Atlas AI Resolver Corpus Audit
status: active
category: architecture
priority: 99
summary: Auditoria do corpus resolver-o-que-vale-a-pena, separando documentos que devem virar autoridade canonica, material de referencia e ideias futuras para a arquitetura mae do Atlas AI.
tags:
  - atlas-ai
  - resolver-corpus
  - architecture
  - policy-profile
  - super-tool-runtime
  - atlas-decide
capabilities:
  - resolver_corpus_governance
  - policy_profile_architecture
  - decision_receipt_governance
  - super_tool_runtime_governance
  - domain_profile_orchestration
decisions:
  - O corpus resolver-o-que-vale-a-pena contem especificacoes estruturantes e nao deve ser tratado como rascunho descartavel.
  - Domain Profile / Flow Profile e a arquitetura mae correta para dominios grandes do Atlas AI.
  - Atlas Decide deve ser o compilador operacional que emite Decision Receipt; ele nao executa fluxos de dominio.
  - Super Tool Runtime e Core do Atlas AI, nao uma dependencia exclusiva do Forge ou do Engineering Harness.
  - Atlas Programming deve ser especializacao do modelo Domain Profile, com AtlasProgrammingOrchestrator como autoridade do dominio.
maintenance:
  - Atualizar este documento quando novos arquivos da pasta resolver-o-que-vale-a-pena forem promovidos, arquivados ou descartados.
  - Nao implementar fluxo novo de programming, forge, tools runtime, policy ou decide sem checar os documentos classificados como P0.
related_paths:
  - resolver-o-que-vale-a-pena/docs/specs/2026-05-03-atlas-domain-profile-orchestration-architecture.md
  - resolver-o-que-vale-a-pena/docs/specs/2026-05-02-atlas-decide-final-architecture.md
  - resolver-o-que-vale-a-pena/docs/specs/2026-05-03-atlas-programming-product-architecture.md
  - resolver-o-que-vale-a-pena/root-md/Atlas_AI_Harness_Super_Tool_Runtime_Core.md
  - resolver-o-que-vale-a-pena/root-md/Atlas_Gaps_Achamos_Nao_Esquecer.md
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
  - docs/engineering-knowledge-base/atlas-ai-architecture-audit.md
---

# Atlas AI Resolver Corpus Audit

Esta auditoria existe porque a pasta `resolver-o-que-vale-a-pena` contem
documentos antigos, densos e parcialmente sobrepostos. Alguns sao fonte de
verdade arquitetural. Outros sao historico, produto, plano ou backlog futuro.

A regra deste documento:

```text
Nada importante fica perdido no corpus.
Nada vira canonico sem classificacao.
Nada compete com a Knowledge Base sem ser promovido ou arquivado.
```

## Classificacao

| Nivel | Significado |
|---|---|
| P0 | Deve influenciar a arquitetura mae agora. |
| P1 | Deve ser usado como referencia de implementacao ou roadmap proximo. |
| P2 | Ideia futura ou dominio ainda imaturo. |
| Archive | Historico util, mas nao deve mandar na arquitetura atual. |

## P0 - Promovidos Para Arquitetura Canonica

Os itens abaixo eram marcados como "a promover" na auditoria original. A
promocao foi executada em docs canonicos menores; os arquivos do resolver
permanecem source material com redirect.

### Domain Profile Orchestration

Arquivo:

```text
resolver-o-que-vale-a-pena/docs/specs/2026-05-03-atlas-domain-profile-orchestration-architecture.md
```

Decisao promovida para `atlas-ai-operating-system.md`,
`atlas-ai-pipeline.md` e `atlas-ai-core-vs-domain.md`:

```text
Domain != flow.
Profile != model preset.
```

Um domain e uma vertical:

- `programming`;
- `finance`;
- `personal_development`;
- `self_improvement`;
- `research`;
- `security`;
- `operations`;
- `curation` como conceito historico, hoje coberto por `self_improvement` ate
  haver Curator dedicado.

Um flow/profile e uma forma operacional dentro do domain:

- `programming.dev`;
- `programming.forge`;
- `programming.qa`;
- `programming.security`;
- `finance.portfolio_analysis`;
- `personal_development.weekly_review`.

Implicacao:

```text
Atlas AI Core
-> Domain Profile
-> Flow Profile
-> Policy
-> Decide
-> Domain Orchestrator
-> Domain Runtime
```

Isso evita que `atlas dev`, `atlas forge`, `atlas fix` e futuros fluxos virem
produtos concorrentes. Eles passam a ser entradas para profiles do mesmo domain.

### Atlas Decide Final Architecture

Arquivo:

```text
resolver-o-que-vale-a-pena/docs/specs/2026-05-02-atlas-decide-final-architecture.md
```

Decisao promovida para `atlas-ai-kernel-architecture.md`,
`atlas-ai-operating-system.md` e `atlas-ai-pipeline.md`:

```text
Atlas Decide e o compilador operacional do Atlas AI.
```

Ele nao deve ser apenas seletor de provider/modelo. Ele deve compilar:

- intent;
- risk;
- autonomy;
- context strategy;
- eligible providers;
- model policy;
- tool policy;
- execution graph;
- gates;
- fallback;
- evidence requirements;
- receipt.

Invariante:

```text
Decisao sem receipt nao existe.
```

E em programacao:

```text
Sem evidencia, nao declarar resolved.
```

### Atlas Programming Product Architecture

Arquivo:

```text
resolver-o-que-vale-a-pena/docs/specs/2026-05-03-atlas-programming-product-architecture.md
```

Decisao promovida para `domains/programming.md`,
`atlas-ai-operating-system.md`, `engineering-blueprint.md` e
`super-tool-runtime-core.md`:

```text
Atlas Programming e uma especializacao do Domain Profile model.
```

Fluxos canonicos:

- `programming.dev`;
- `programming.debug`;
- `programming.refactor`;
- `programming.qa`;
- `programming.security`;
- `programming.forge`.

Autoridade correta:

```text
AtlasAiPolicyService
-> Atlas Decide
-> Domain Orchestrator Router
-> AtlasProgrammingOrchestrator
-> Engineering Harness / Super Tool Runtime / Provider Executor
-> Evidence / Gates / Repair / Memory
```

Implicacao para produto:

- `atlas dev` e o cockpit diario de programacao;
- `atlas forge` e intensidade maxima;
- `atlas fix` e repair intent dentro de Programming;
- comandos avancados podem existir, mas nao devem ser obrigatorios para o
  melhor fluxo normal.

### Super Tool Runtime Core

Arquivo:

```text
resolver-o-que-vale-a-pena/root-md/Atlas_AI_Harness_Super_Tool_Runtime_Core.md
```

Decisao promovida para `super-tool-runtime-core.md`,
`programming-power-tools-catalog.md` e `atlas-ai-runtime-packets.md`:

```text
Super Tool Runtime e Core do Atlas AI.
```

Ele nao e uma lista de ferramentas. Ele e o control-plane que transforma tools
locais em execucao auditavel.

Componentes canonicos:

- Tool Registry;
- Installation Detector;
- Tool Policy Engine;
- Tool Planner;
- Tool Executor;
- Result Normalizer;
- Evidence Store;
- Control Mapper;
- Finding Store;
- Reporter;
- Learning Loop.

Principios a carregar para os docs canonicos:

- provider e motor, nao autoridade;
- tool failing is evidence;
- sandbox antes de autonomia;
- JSON canonico antes de stdout humano;
- normalized result antes de model;
- aprendizado volta para memoria e benchmark;
- a melhor tool vira rotina invisivel do Atlas.

Implicacao:

```text
atlas dev tambem deve usar Super Tool Runtime quando o risco/tarefa pedir.
Forge usa mais, mas nao possui o runtime.
```

## P1 - Referencia De Implementacao / Roadmap Proximo

| Documento | Uso |
|---|---|
| `Atlas_Engineering_Harness_Runner_Plano_Profissional.md` | Referencia para amadurecer o harness como runtime pesado do domain Programming. |
| `Atlas_AI_Memory_Context_Core_Open_Brain.md` | Referencia para manter memoria/contexto como Core e nao provider-local. |
| `Atlas_AI_Sessoes_Compactacao_Continuidade.md` | Referencia para `atlas continue`, session state e continuidade entre providers/surfaces. |
| `Atlas_AI_Skill_System_v1.md` | Referencia para skills como capacidade governada, nao prompt solto. |
| `Atlas_CLI_Produto_Final_Roadmap_7_Pontos.md` | Produto/CLI; deve ser compatibilizado com profiles e surfaces. |
| `Atlas_Engineering_Blueprint_7_Itens_Plano_Implementacao.md` | Referencia de DoD e qualidade para Programming/Forges. |
| `resolver-o-que-vale-a-pena/docs/atlas-ai-telemetry-quality-efficiency-implementation.md` | Referencia para medir qualidade, custo, continuidade e eficiencia. |

## P2 - Dominios Promovidos E Ideias Grandes

### Personal Development

Arquivo:

```text
resolver-o-que-vale-a-pena/root-md/Atlas_Gaps_Achamos_Nao_Esquecer.md
```

Este documento nao e arquitetura de programming, mas prova que
`personal_development` nao pode ser tratado como chat motivacional.

Ele aponta para um domain grande com:

- metricas cognitivas;
- revisao periodica;
- hipoteses pessoais e N-of-1;
- sinais de energia, foco, sono e comportamento digital;
- deteccao de dependencia cognitiva;
- curadoria semanal;
- privacy gates fortes.

Status canonico atual:

`personal_development` ja foi promovido para dominio implemented/ready com
runtime isolado, 10 flows, gates privados e contrato non-clinical. Este trecho
permanece como origem historica do racional e como filtro para nao reduzir o
dominio a chat motivacional.

Decisao:

```text
Personal Development deve seguir Domain Profile/Flow Profile com runtime,
evidence, privacy, memory projection e gates proprios.
```

## Nova Arquitetura Canonica Resultante

O fluxo macro deve ser:

```text
Surface Input
-> Atlas.Input
-> Atlas.Intent
-> Domain Profile Resolver
-> Flow Profile Resolver
-> AtlasAiPolicyService
-> Atlas Decide
-> Domain Orchestrator Router
-> Domain Orchestrator
-> Domain Runtime / Super Tool Runtime / Provider Executor
-> Gates
-> Repair or Escalation
-> Evidence Packet
-> Learning / Memory
-> Surface Output
```

## Modelo De Policy/Profile

`Profile` responde:

```text
Que tipo de fluxo operacional e este?
```

Exemplos:

- `programming.dev`;
- `programming.forge`;
- `programming.qa`;
- `finance.market_research`;
- `personal_development.weekly_review`.

`Policy` responde:

```text
Quais regras este fluxo deve seguir agora?
```

Inclui:

- provider/modelo permitidos;
- autonomia;
- budget;
- tools permitidas;
- sandbox;
- contexto maximo;
- gates obrigatorios;
- fallback;
- evidence minima;
- privacy;
- approval requirements.

`AtlasAiPolicyService` deve resolver policy por camadas:

```text
global settings
-> domain profile
-> flow profile
-> surface policy
-> task risk
-> session override
```

## Ordem Profissional De Promocao

1. Promover `Domain Profile / Flow Profile` para os docs fundadores e specs de
   dominio em `docs/engineering-knowledge-base/domains/`.
2. Definir `AtlasAiPolicyService` como camada obrigatoria entre settings e Decide.
3. Padronizar `Decision Receipt` como contrato de saida do Decide.
4. Criar `Domain Orchestrator Router` como fronteira entre Decide e execucao.
5. Tratar `AtlasProgrammingOrchestrator` como especializacao de domain,
   documentada em `domains/programming.md`.
6. Mover toda execucao de tools para `Atlas.Tools` / Super Tool Runtime.
7. Fazer `atlas dev`, `atlas forge`, `atlas fix` e `atlas continue` serem surfaces/aliases para profiles.
8. Classificar o restante do corpus como P1, P2 ou Archive.

## Anti-Padroes Encontrados Pelo Corpus

- comando escolhendo provider por conta propria;
- settings globais tentando representar politicas especializadas;
- Harness como produto final em vez de runtime pesado;
- Tool Runtime preso ao Forge;
- Decide executando fluxo de dominio;
- profile tratado como nome de modelo;
- repair fora do fluxo principal;
- evidencias diferentes para o mesmo resultado;
- docs importantes vivendo fora da Knowledge Base sem classificacao.
