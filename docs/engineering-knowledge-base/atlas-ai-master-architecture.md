---
id: atlas-ai-master-architecture
type: engineering_knowledge
title: Atlas AI Master Architecture
status: active
category: architecture
priority: 100
summary: Especificacao enterprise da arquitetura-mae do Atlas AI, definindo a inteligencia central, autoridade operacional, contratos canonicos, dominios, runtimes, evidence, learning, governanca e estrategia para superar Claude Code.
tags:
  - atlas-ai
  - master-architecture
  - enterprise-architecture
  - orchestration
  - policy-profile
  - atlas-decide
  - super-tool-runtime
  - programming
  - claude-code
capabilities:
  - atlas_ai_master_architecture
  - enterprise_orchestration
  - operational_intelligence
  - domain_profile_orchestration
  - policy_profile_governance
  - decision_receipt_governance
  - evidence_driven_execution
  - anti_duplication_governance
  - atlas_vs_claude_code_strategy
decisions:
  - A Tese do Multiplicador / Canal Unico (Layer -1, ver atlas-ai-thesis-multiplier-channel.md) e ponto fixo acima desta arquitetura. Todo plano, dominio, roadmap e estrategia desta Master Architecture deve ser auditado contra a pergunta-norte (multiplica ou compete? mantem gravidade natural ou cria fricca de escape?).
  - Atlas Rivals e o instrumento empirico que valida se o multiplicador esta positivo; multiplicador negativo e stop-the-line.
  - Atlas AI e a inteligencia operacional do Atlas, nao um chat, comando, provider ou harness isolado.
  - A arquitetura-mae e dividida em Control Plane, Domain Plane, Runtime Plane, Evidence Plane, Learning Plane, Human Knowledge Surface / Personal Knowledge Workspace e Surface Plane.
  - Toda tarefa operacional relevante deve passar por Profile Resolution, Policy Resolution, Decision Receipt, Domain Orchestrator, Runtime, Gates, Evidence e Learning.
  - Atlas Decide e o compilador operacional; ele decide e emite receipt, mas nao executa dominios.
  - Por padrao, Atlas Decide deve escolher o melhor provider/modelo permitido para a tarefa; override manual de modelo e excecao auditada.
  - Domain Orchestrators sao donos da semantica de execucao; Runtime executa; Gates julgam evidencia.
  - Super Tool Runtime e Core compartilhado, consumido por Dev, Forge, QA, Security, Finance, Personal Development e Curator.
  - Atlas vence Claude Code nao por um modelo melhor isolado, mas por memoria, contexto, ferramentas, gates, repair, evidencia, continuidade e aprendizado medido.
  - AtlasVault/Obsidian e a Human Knowledge Surface / Personal Knowledge Workspace: poderoso para escrita, pesquisa, revisao e identidade humana, mas nunca fonte operacional crua sem ingestao, classificacao, redacao e gates.
  - Novos dominios devem ser incorporados por contrato de domain onboarding, nao por improviso em comandos ou prompts.
  - Autoaprimoramento do Atlas e um dominio operacional proprio, com ciclos agendados, metricas, proposals, gates e aprovacao por risco.
maintenance:
  - Este documento e a raiz enterprise da arquitetura Atlas AI; atualizar antes de mudar autoridade macro, policy/profile, Decide, domain orchestrators ou runtime.
  - Qualquer novo comando, domain, runtime, tool, gate ou memoria operacional deve declarar onde entra nesta arquitetura.
  - Mudancas neste documento exigem atualizar START_HERE.md, README.md e, quando aplicavel, docs de pipeline/core/domain.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-vision.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md
  - docs/engineering-knowledge-base/atlas-ai-architecture-audit.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - docs/engineering-knowledge-base/obsidian-atlas-vault.md
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - docs/atlas-cli-5x-claude-code-plan.md
  - docs/atlas-cli-fair-claude-benchmark.md
---

# Atlas AI Master Architecture

Este documento define a arquitetura-mae do Atlas AI.

Ele responde uma pergunta simples e grande:

```text
Qual e a inteligencia que organiza, decide, executa, valida, aprende e evolui
todo o Atlas?
```

A resposta canonica:

```text
Atlas AI e uma inteligencia operacional evidence-driven.
```

Ele usa modelos como motores, memoria como continuidade, ferramentas como
sensores/atuadores, policy como lei, dominios como especializacao, gates como
criterio de verdade, evidence como auditoria e learning como evolucao.

## Tese Executiva

Claude Code e forte porque transforma conversa em alteracao de codigo com boa
experiencia. Atlas deve vencer em tarefas medias e dificeis porque nao depende
apenas da qualidade imediata de um modelo.

O diferencial do Atlas deve ser:

```text
modelo bom
+ memoria persistente
+ contexto verificavel
+ ferramentas reais
+ policy por risco
+ gates objetivos
+ repair estruturado
+ evidence packet
+ learning acumulado
+ continuidade entre sessoes
```

Em tarefas simples, Claude Code pode parecer igual ou mais rapido. Em tarefas
medias/dificeis, Atlas deve ser superior porque reduz erro silencioso,
retrabalho humano, regressao, perda de contexto e conclusoes sem prova.

## Principio De Produto

O operador nao deve precisar entender a arquitetura para receber o melhor fluxo.

Ele deve usar:

```bash
atlas dev
atlas forge
atlas ask
atlas continue
```

Mas internamente tudo passa pela mesma inteligencia:

```text
Atlas AI
```

Surfaces podem ser simples. O cerebro nao pode ser simples.

## Modelo Mental Enterprise

Atlas AI e dividido em sete planes:

```text
Atlas AI
├── Control Plane
├── Domain Plane
├── Runtime Plane
├── Evidence Plane
├── Learning Plane
├── Human Knowledge Surface / Personal Knowledge Workspace
└── Surface Plane
```

### Control Plane

Responsavel por decidir o que deve acontecer.

Inclui:

- input normalization;
- intent classification;
- domain/profile resolution;
- policy resolution;
- Atlas Decide;
- budget/autonomy/privacy;
- execution graph;
- approval requirements;
- decision receipt.

### Domain Plane

Responsavel por entender a semantica do trabalho.

Inclui:

- Programming;
- Finance;
- Personal Development;
- Research;
- Operations;
- Security;
- QA;
- Marketing;
- Curator.

Cada domain possui um orchestrator proprio.

Novo domain nao entra como prompt. Novo domain entra como produto operacional
com profile, policy, runtime, gates, evidence e learning.

### Runtime Plane

Responsavel por executar no mundo real.

Inclui:

- provider executor;
- Super Tool Runtime;
- Engineering Harness;
- domain-specific runtimes;
- workers;
- schedulers;
- browser/visual runtime;
- sandbox/worktree/docker runtime.

### Evidence Plane

Responsavel por provar o que aconteceu.

Inclui:

- tool runs;
- artifacts;
- findings;
- gates;
- scores;
- receipts;
- traces;
- waivers;
- final evidence packet.

### Learning Plane

Responsavel por transformar resultado em vantagem futura.

Inclui:

- memory deltas;
- benchmark updates;
- policy improvements;
- prompt/process curation;
- capability gap detection;
- documentation updates;
- anti-duplication findings.

### Human Knowledge Surface / Personal Knowledge Workspace

Responsavel por leitura, escrita, revisao, curadoria humana e memoria narrativa.

Inclui:

- Obsidian;
- AtlasVault;
- notas longas;
- pesquisa pessoal;
- mapas mentais;
- rascunhos de decisoes;
- revisao humana de memorias candidatas;
- managed notes geradas pelo Atlas;
- backlinks `atlas://`;
- promocao controlada para memoria operacional.

Esta surface/workspace e poderosa porque da ao Atlas continuidade humana,
contexto rico, material de pesquisa e superficie de revisao que nenhum chat
efemero possui. Mas ela nao e fonte operacional primaria. Antes de entrar no
Runtime, Open Brain ou provider context, uma nota precisa passar por ingestao,
classificacao, privacy/redaction, provider-safety, dedupe, evidence link e
aprovacao quando o risco exigir.

### Surface Plane

Responsavel por entrada e saida humana/sistema.

Inclui:

- CLI;
- app;
- API;
- workers;
- MCP;
- IDE integrations;
- automations.

Surface nao decide fluxo. Surface coleta input, passa hints e renderiza output.

## Arquitetura Canonica

```text
Surface Input
  -> Atlas.Input
  -> Atlas.Intent
  -> Atlas.ProfileResolver
  -> Atlas.ContextCompiler
  -> AtlasAiPolicyService
  -> Atlas Decide
  -> DomainOrchestratorRouter
  -> Domain Orchestrator
  -> Runtime Graph
  -> Gates
  -> Repair/Escalation
  -> Evidence Packet
  -> Learning
  -> Surface Output
```

Essa ordem e contrato. Se um fluxo pula uma etapa, ele precisa registrar motivo
auditavel.

## Autoridade Unica

O maior risco do Atlas e ter muitas pecas fortes tomando decisoes parciais.

Para evitar isso, a autoridade deve ser fixa:

| Decisao | Autoridade |
|---|---|
| O que entrou? | `Atlas.Input` |
| O que o operador quer? | `Atlas.Intent` |
| Qual dominio/flow? | `Atlas.ProfileResolver` |
| Que contexto entra? | `Atlas.ContextCompiler` |
| Quais regras valem? | `AtlasAiPolicyService` |
| Qual plano operacional? | `Atlas Decide` |
| Quem executa semanticamente? | `Domain Orchestrator` |
| Quais ferramentas rodam? | `Runtime Graph` + `Super Tool Runtime` |
| O que prova sucesso? | `Gate Matrix` |
| Como reparar? | `Domain Repair Loop` |
| O que fica registrado? | `Evidence Plane` |
| O que vira aprendizado? | `Learning Plane` |
| Como mostrar ao operador? | `Surface` |

Regra:

```text
Um conceito, uma autoridade.
```

## Control Plane

### Atlas.Input

Normaliza toda entrada:

- texto;
- imagem;
- audio;
- arquivo;
- clipboard;
- screenshot;
- path;
- URL;
- thread state;
- workspace;
- surface;
- operator metadata.

Contrato minimo:

```json
{
  "input_id": "uuid",
  "surface": "cli|app|api|worker|mcp",
  "workspace": "/path",
  "thread_id": "optional",
  "text": "user request",
  "attachments": [],
  "hints": {},
  "created_at": "iso8601"
}
```

Se paste-image existe, ele pertence aqui. Nao pertence ao `ask`, `dev` ou app
isoladamente.

### Atlas.Intent

Classifica intencao, risco e complexidade.

Campos minimos:

```json
{
  "primary_intent": "implement|debug|repair|review|research|decide|operate",
  "domain_hint": "programming|finance|personal_development|general",
  "risk": "low|medium|high|critical",
  "complexity": "simple|medium|hard|unknown",
  "state_change": true,
  "confidence": 0.82,
  "reasons": []
}
```

Intent nao executa. Intent prepara roteamento.

### Atlas.ProfileResolver

Resolve:

```text
Domain Profile + Flow Profile
```

Exemplos:

| Pedido | Domain Profile | Flow Profile |
|---|---|---|
| "corrija esse bug" | `programming` | `programming.dev` |
| "refatore esse modulo critico" | `programming` | `programming.refactor` |
| "rode o maximo de qualidade" | `programming` | `programming.forge` |
| "revise risco dessa tese" | `finance` | `finance.risk_review` |
| "organize minha semana" | `personal_development` | `personal_development.weekly_review` |

Profile nao e modelo. Profile e sistema operacional de trabalho.

### Atlas.ContextCompiler

Monta contexto com budget, fontes e hashes.

Fontes:

- conversa;
- workspace;
- Open Brain;
- Memory Core;
- Engineering Knowledge Base;
- Code Intelligence;
- docs relevantes;
- diff/git state;
- task/blueprint;
- tool evidence anterior;
- vault humano quando explicitamente permitido e provider-safe.

Contrato:

```json
{
  "context_pack_id": "uuid",
  "budget": {
    "tokens": 24000,
    "strategy": "compact|balanced|deep"
  },
  "refs": [],
  "hash": "sha256",
  "provider_safe": true,
  "omissions": []
}
```

Contexto sem refs vira prompt fraco. Contexto com refs vira capacidade
auditavel.

### AtlasAiPolicyService

Resolve a lei operacional da chamada.

Camadas:

```text
global settings
-> domain policy
-> flow policy
-> surface policy
-> workspace policy
-> task risk
-> session override
-> operator approval
```

Contrato:

```json
{
  "policy_id": "uuid",
  "domain": "programming",
  "flow": "programming.dev",
  "autonomy": "manual|suggest|semi_auto|auto_with_gates",
  "budget": "low|medium|high|release",
  "max_execution_tier": "T1",
  "allowed_providers": ["claude", "codex", "gemini"],
  "allowed_models": [],
  "model_selection_mode": "auto_best_allowed|auto_best_available|manual_override",
  "allowed_tools": [],
  "required_gates": [],
  "privacy": {
    "provider_safe_required": true,
    "external_ai_allowed": true
  },
  "approval_required": []
}
```

Policy nao e opcional. Sem policy, runtime nao roda tarefa operacional.

### Model Selection Policy

Por padrao, o Atlas nao deve pedir ao operador para escolher modelo. O Atlas
Decide deve saber escolher o melhor provider/modelo permitido para a tarefa.

Modos:

| Modo | Significado |
|---|---|
| `auto_best_allowed` | Escolhe o melhor modelo dentro da policy atual, respeitando budget, privacidade, surface, domain e flow. |
| `auto_best_available` | Escolhe o melhor modelo disponivel no sistema quando a policy permite maxima qualidade. |
| `manual_override` | Usa provider/modelo especifico pedido pelo operador, se nao violar policy dura. |

Regra:

```text
Auto e o padrao.
Override manual e excecao.
```

Exemplos:

```bash
atlas dev "corrija esse bug"
```

Usa `auto_best_allowed`.

```bash
atlas forge "refatore esse modulo critico"
```

Pode usar `auto_best_available` se o flow `programming.forge` e o budget
permitirem.

```bash
atlas dev --provider=claude --model=opus "corrija esse bug"
```

Usa `manual_override`, desde que a policy permita Claude/Opus naquele contexto.

Override manual deve ser registrado no Decision Receipt com:

- provider/modelo solicitado;
- se foi aceito, ajustado ou bloqueado;
- motivo;
- riscos;
- perda esperada em relacao ao auto-best, quando conhecida;
- policy que autorizou ou bloqueou.

Policy dura sempre vence override manual:

- privacidade;
- compliance;
- budget maximo;
- indisponibilidade do provider;
- surface proibida;
- domain proibido;
- necessidade de provider-safe;
- risco critico sem approval.

### Atlas Decide

Atlas Decide e o compilador operacional.

Entrada:

- intent;
- profile;
- context;
- policy;
- telemetry;
- provider health;
- model capability registry;
- model performance history;
- tool availability;
- budget;
- risk.

Saida:

```text
Decision Receipt
```

Contrato:

```json
{
  "decision_id": "uuid",
  "domain": "programming",
  "flow": "programming.dev",
  "risk": "medium",
  "provider_selection": {
    "primary": "codex",
    "fallbacks": ["claude"],
    "model": "selected-by-decide",
    "selection_mode": "auto_best_allowed",
    "selection_reason": "best fit for code editing with available policy budget",
    "manual_override": null
  },
  "execution_graph": [],
  "context_strategy": "balanced",
  "tool_strategy": "risk_proportional",
  "required_gates": [],
  "repair_policy": {
    "enabled": true,
    "max_attempts": 2
  },
  "evidence_contract": {
    "required": true,
    "minimum": ["diff_summary", "tests_or_reason", "risk_notes"]
  },
  "receipt_hash": "sha256"
}
```

Decide nao executa. Decide autoriza e explica.

### Model Capability Registry

Atlas Decide precisa de um registro vivo de capacidade de modelos.

Esse registry deve considerar:

- coding strength;
- reasoning depth;
- long-context quality;
- multimodal support;
- tool-use reliability;
- patch quality;
- instruction following;
- latency;
- cost;
- availability;
- rate limits;
- provider health;
- privacy/provider-safe constraints;
- historical task success;
- repair success;
- user preference;
- benchmark score.

Exemplo:

```json
{
  "model": "provider/model",
  "capabilities": {
    "coding": 0.92,
    "reasoning": 0.9,
    "vision": 0.7,
    "long_context": 0.85,
    "tool_use": 0.8
  },
  "operational": {
    "latency_score": 0.6,
    "cost_score": 0.5,
    "availability": "healthy",
    "provider_safe": true
  },
  "benchmarks": {
    "programming.dev": 0.86,
    "programming.forge": 0.9
  }
}
```

Decide deve combinar capability registry + policy + telemetry + task profile.
O melhor modelo para uma tarefa simples pode nao ser o melhor para uma tarefa
dificil, barata, multimodal, sensivel ou urgente.

## Domain Plane

Cada domain deve implementar:

- profile catalog;
- intent taxonomy;
- context extensions;
- orchestrator;
- runtime strategy;
- gate matrix;
- repair taxonomy;
- evidence schema;
- learning hooks;
- privacy rules.

### Atlas AI Programming

Objetivo:

```text
Ser o melhor sistema operacional de programacao para tarefas medias/dificeis.
```

Flows:

- `programming.dev`;
- `programming.debug`;
- `programming.repair`;
- `programming.review`;
- `programming.refactor`;
- `programming.qa`;
- `programming.security`;
- `programming.database`;
- `programming.visual`;
- `programming.forge`.

Orchestrator:

```text
AtlasProgrammingOrchestrator
```

Responsabilidades:

- transformar request em plano de engenharia;
- escolher runtime leve/medio/pesado conforme receipt;
- usar Open Brain e Code Intelligence;
- usar Super Tool Runtime por risco;
- chamar Engineering Harness quando necessario;
- unificar dev, forge, fix e continue;
- gerar Programming Evidence Packet;
- alimentar memoria de engenharia.

Regra de produto:

```text
atlas dev e atlas forge sao intensidades do mesmo domain.
```

Status inicial implementado:

- `programming.dev`, `programming.repair`, `programming.review`,
  `programming.refactor`, `programming.qa`, `programming.security`,
  `programming.database`, `programming.visual` e `programming.forge` estao
  declarados como flows executaveis no Domain/Profile Registry;
- `atlas dev` interativo e `atlas dev "prompt"` passam um
  `dev_execution_plan` gerado pelo `AtlasProgrammingOrchestrator`;
- `atlas:ai:chat --dev` agora tambem gera automaticamente um plano raiz quando
  nenhuma surface forneceu `--dev-plan`, impedindo que uma entrada em modo dev
  pule o Programming Orchestrator;
- `AtlasProgrammingOrchestrator` resolve `programming_flow` por profile,
  intent explicito, `routing_task`, `task_type` e sinais do texto da tarefa,
  roteando repair/review/refactor/qa/security/database/visual/forge para os
  flow profiles especializados antes de compor policy e executor;
- cada mensagem dev gera `programming_message_plan`, `programming_dispatch` e
  `programming_repair` antes de executar provider ou Harness;
- `AtlasAiPolicyService` tem guard rails para garantir que
  `programming.repair` use `dev_repair_executor` e que flows de harness
  (`qa/security/database/visual`) exijam Engineering Harness mesmo quando uma
  projection local ainda nao carregou os seeds declarativos;
- `dev_repair_executor` e tratado como executor com escrita controlada de
  workspace no contrato de tools, alinhado ao objetivo de repair completo;
- tarefas multi-layer ou explicitamente forge/harness escalam para
  Engineering Harness pelo mesmo contrato.
- `atlas fix` chama `atlas dev` com intent explicito `repair`, mantendo o
  repair dentro do Programming Orchestrator em vez de criar fluxo paralelo;
- `atlas continue` retoma tanto `dev_execution_plan` legado quanto
  `programming_session_plan` novo, preservando profile `dev/forge`, modelo e
  intent repair quando existirem.
- `AtlasProgrammingSurfaceCommandBuilder` centraliza a traducao de surfaces
  (`fix`, `continue`) para comandos `atlas:cli:dev`, removendo duplicacao de
  flags criticas como `--repair`, `--forge`, modelo, Open Brain e permissao.

### Atlas AI Finance

Objetivo:

```text
Executar analise financeira, risco, portfolio, tese, compliance e backtest com
auditabilidade enterprise, sempre em modo review-only.
```

Requisitos:

- data provenance;
- timestamps;
- source quality;
- risk policy;
- compliance gate;
- simulation/backtest quando aplicavel;
- decision journal;
- post-decision review.

Finance nunca deve agir como chat opinativo em tarefa de risco real.

### Atlas AI Marketing

Objetivo:

```text
Ser um sistema operacional de marketing criativo, analitico e operacional.
```

Marketing nao e apenas "gerar copy". Um domain serio de marketing precisa
entender estrategia, pesquisa, canais, criativos, funil, marca, segmentacao,
mensagens, experimentos, metricas, aprendizado e producao multiformato.

Flows:

- `marketing.strategy`;
- `marketing.research`;
- `marketing.positioning`;
- `marketing.campaign`;
- `marketing.creative`;
- `marketing.copywriting`;
- `marketing.media_plan`;
- `marketing.landing_page`;
- `marketing.email`;
- `marketing.social`;
- `marketing.video_script`;
- `marketing.ab_test`;
- `marketing.analytics`;
- `marketing.brand_review`;
- `marketing.forge`.

Catalog/orchestrator alvo:

```text
AtlasMarketingOrchestrator
```

Status real atual:

- Marketing e scaffold/catalog-ready, nao implemented/ready;
- o catalogo declara 15 flows canonicos para preservar a arquitetura alvo e
  evitar perda de vocabulario de produto;
- ainda falta runtime/orchestrator proprio antes de marcar Marketing como
  implemented;
- `marketing.forge` e fluxo alvo de intensidade alta, mas nao deve ser exposto
  como runtime pronto;
- qualquer output de Marketing deve permanecer draft/review ate aprovacao
  explicita do operador, e publicacao externa nao pode ser automatica.

Contexto especializado:

- produto/oferta;
- ICP/personas;
- mercado;
- concorrentes;
- brand voice;
- assets existentes;
- campanhas anteriores;
- performance historica;
- restricoes legais/compliance;
- canal e formato;
- objetivo de negocio;
- budget e timeline.

Runtime especializado:

- research runtime;
- creative generation runtime;
- copy evaluation runtime;
- landing/page/email runtime;
- analytics runtime;
- experiment runtime;
- asset pipeline;
- brand consistency checker.

Gates:

- brand alignment;
- claim substantiation;
- compliance/legal risk;
- audience-fit score;
- clarity and offer strength;
- creative diversity;
- channel fit;
- measurement plan;
- experiment readiness;
- performance feedback loop.

Evidence:

- creative brief;
- source pack;
- competitor notes;
- generated variants;
- rationale;
- brand/compliance findings;
- experiment plan;
- metrics snapshot;
- final asset manifest;
- learning delta.

Pipeline exemplo:

```text
input: "crie uma campanha para produto X"
  -> marketing intent
  -> profile: marketing.campaign
  -> context: product, ICP, brand, competitors, prior metrics
  -> policy: compliance, claims, budget, channels
  -> Decide: research + creative + validation graph
  -> Marketing Orchestrator
  -> research/runtime/assets/tools
  -> creative variants
  -> gates: brand, claims, audience, channel, measurement
  -> evidence packet
  -> output: campaign kit + assets + test plan + learning hooks
```

Assim, o Atlas pode se tornar excelente em marketing rapidamente porque o Core
ja oferece memoria, contexto, policy, tools, evidence e learning. O que precisa
ser adicionado e a semantica do domain: profiles, orchestrator, runtimes,
gates, datasets e metricas.

### Atlas AI Personal Development

Objetivo:

```text
Transformar desenvolvimento pessoal em sistema medido, privado e evolutivo.
```

Requisitos:

- consentimento;
- privacy first;
- personal memory projection;
- signals/sensors;
- hypotheses;
- interventions;
- follow-up;
- measurement;
- crisis/safety gates quando aplicavel.

Esse domain deve ter runtime proprio. Nao e conversa motivacional.

### Atlas AI Curator

Objetivo:

```text
Impedir que o Atlas volte a virar uma colecao de features soltas.
```

Responsabilidades:

- detectar duplicacao;
- detectar capability presa em surface;
- propor promocao para Core;
- atualizar docs;
- abrir tasks;
- medir regressao de processo;
- sugerir policy/gate improvements;
- manter arquitetura viva.

Curator nao e dono de produto. Curator e guardiao de coerencia.

### Atlas AI Self-Improvement

Objetivo:

```text
Autoavaliar, metrificar, aprender e propor melhorias no proprio Atlas.
```

Self-Improvement e uma especializacao operacional do Curator. Ele existe para
rodar ciclos recorrentes, inclusive de madrugada, sem depender de uma conversa
humana ativa.

Flows:

- `self_improvement.nightly_review`;
- `self_improvement.weekly_architecture_audit`;
- `self_improvement.capability_gap_scan`;
- `self_improvement.benchmark_review`;
- `self_improvement.memory_quality_review`;
- `self_improvement.tool_runtime_review`;
- `self_improvement.domain_learning_review`;
- `self_improvement.docs_drift_review`;
- `self_improvement.provider_performance_review`;
- `self_improvement.proposal_generation`.

Entradas:

- traces;
- decision receipts;
- evidence packets;
- failed gates;
- repair attempts;
- tool failures;
- user corrections;
- latency/cost;
- benchmark results;
- docs/code drift;
- missing capabilities;
- duplicated logic;
- memory quality trends.

Saidas:

- curation proposal;
- architecture drift finding;
- memory delta;
- doc patch proposal;
- test/gate proposal;
- tool recipe/normalizer backlog;
- provider policy adjustment proposal;
- domain improvement proposal;
- benchmark task suggestion.

Regras:

- pode analisar automaticamente;
- pode criar proposals automaticamente;
- pode abrir tarefas de baixo risco;
- pode atualizar memoria de baixo risco quando policy permitir;
- nao deve mudar codigo ou policy critica sem gates e aprovacao;
- toda melhoria precisa de evidence antes/depois.

Ciclo agendado exemplo:

```text
02:00 nightly
  -> collect last 24h traces/evidence
  -> detect repeated failures and missing context
  -> scan capability coverage by surface
  -> review tool/runtime failures
  -> review memory quality trend
  -> generate improvement proposals
  -> rank by impact/risk
  -> create tasks or docs patches when allowed
  -> send operator summary
```

Esse domain e o mecanismo que faz o Atlas adquirir conhecimento novo e melhorar
o proprio processo sem virar autonomia perigosa.

## Domain Onboarding Protocol

Um novo dominio deve ser incorporado por contrato, em fases. Isso permite que o
Atlas adicione uma habilidade complexa como Marketing, Legal, Sales, Education
ou Health sem quebrar a arquitetura-mae.

### Fase 0 - Domain Charter

Declarar:

- nome canonico;
- objetivos;
- nao-objetivos;
- riscos;
- surfaces permitidas;
- dados sensiveis;
- criterios de sucesso;
- owner canonico.

### Fase 1 - Profile Catalog

Declarar:

- domain profile;
- flow profiles;
- intensidade;
- policy defaults;
- approval requirements;
- evidence minima.

### Fase 2 - Context Model

Declarar:

- fontes de contexto;
- memory projection;
- docs/datasets;
- provider-safety;
- freshness;
- provenance;
- budget.

### Fase 3 - Orchestrator

Criar:

- `Atlas<Domain>Orchestrator`;
- request/result contract;
- runtime strategy;
- repair taxonomy;
- output contract.

### Fase 4 - Runtime And Tools

Registrar:

- tools;
- recipes;
- normalizers;
- external APIs;
- sandbox/network policy;
- artifacts.

### Fase 5 - Gates And Evidence

Declarar:

- gate matrix;
- evidence packet schema;
- scoring;
- waivers;
- compliance/privacy;
- quality thresholds.

### Fase 6 - Learning Loop

Declarar:

- feedback capture;
- memory deltas;
- benchmark tasks;
- performance metrics;
- curation rules;
- self-improvement hooks.

### Fase 7 - Surface Integration

Expor via:

- CLI;
- app;
- API;
- automation;
- MCP;
- worker.

Surface so chama o domain. Surface nao implementa a habilidade.

### Fase 8 - Maturity Gate

Um domain so e considerado pronto quando:

- possui profiles;
- possui orchestrator;
- possui policy;
- possui context model;
- possui runtime;
- possui gates;
- possui evidence;
- possui learning;
- possui testes;
- possui docs;
- possui benchmark ou metricas.

Sem isso, e apenas feature ou prompt, nao domain.

## Runtime Plane

Runtime executa o plano autorizado.

Tipos:

| Runtime | Uso |
|---|---|
| Provider Executor | Modelo responde ou edita com contrato. |
| Super Tool Runtime | Ferramentas locais/open-source com evidencia normalizada. |
| Engineering Harness | Programacao pesada, worktree/sandbox, multi-gate. |
| Browser/Visual Runtime | UI, screenshots, e2e, acessibilidade, visual smoke. |
| Finance Runtime | Dados, simulacao, risco, compliance. |
| Marketing Runtime (alvo/scaffold) | Pesquisa, criativos, assets, copy, analytics, experimentos e brand/compliance checks; ainda precisa runtime/orchestrator proprio antes de ser implemented/ready. |
| Personal Runtime | Rotinas, sensores, follow-up, metricas pessoais. |
| Curator Runtime | Auditoria de docs/codigo/processos. |
| Self-Improvement Runtime | Jobs agendados de auditoria, metricas, proposals, learning e arquitetura. |

Runtime nao decide policy. Runtime executa receipt.

## Super Tool Runtime Como Core

Super Tool Runtime e o sistema nervoso operacional.

Ele fornece:

- registry;
- installation detector;
- authority matrix;
- policy engine;
- planner;
- executor;
- normalizer;
- evidence store;
- gate integration;
- waivers;
- approvals;
- reporter;
- learning hook.

Regra:

```text
Nenhum gate deve depender de stdout bruto quando existe normalizer.
Nenhuma surface deve chamar ferramenta diretamente quando existe registry.
```

## Evidence Plane

Evidence e a diferenca entre "parece resolvido" e "esta comprovado".

Status inicial implementado:

- `atlas_ledger_events` como event store append-only minimo;
- `AtlasEvidenceLedger` como gravador canonico;
- `LedgerEventType` com taxonomia inicial do kernel;
- `OperationEnvelopeFactory` emitindo `ENVELOPE_CREATED`;
- `AtlasDecideService` emitindo `DECISION_ISSUED`;
- `AiWorker` emitindo `EXECUTION_STARTED`, `PROVIDER_CALLED`,
  `PROVIDER_RETURNED`, `OPERATION_COMPLETED`, `OPERATION_FAILED` e
  `OPERATION_BLOCKED` sem gravar prompt/output bruto;
- repair nativo de Programming emitindo `GATE_EVALUATED`, `GATE_PASSED`,
  `GATE_BLOCKED`, `REPAIR_INITIATED` e `REPAIR_COMPLETED` com hashes/projecoes
  seguras da evidencia de qualidade;
- `EngineeringHarnessRunnerService` emitindo `EXECUTION_STARTED`,
  `CONTEXT_COMPOSED`, `PROVIDER_RETURNED` quando aplicavel e evento terminal
  `OPERATION_COMPLETED`/`OPERATION_FAILED` para runs do Harness;
- `AtlasToolEvidenceStore` emitindo `TOOL_EVIDENCE_RECORDED` para evidencias
  normalizadas do Super Tool Runtime;
- `AtlasToolGateService` emitindo `GATE_PASSED`, `GATE_BLOCKED` ou
  `GATE_EVALUATED` para gates de ferramentas;
- `AtlasSelfImprovementOrchestrator` resolvendo qualquer flow
  `self_improvement.*` suportado, normalizando opcoes, declarando autonomia
  baixa, gates requeridos e plano de execucao antes de acionar o runtime;
- `AtlasSelfImprovementRuntime` executando 13 flows especializados
  (`nightly_review`, `weekly_architecture_audit`, `capability_gap_scan`,
  `benchmark_review`, `memory_quality_review`, `tool_runtime_review`,
  `repair_loop_review`, `kernel_pipeline_review`, `domain_learning_review`, `docs_drift_review`,
  `provider_performance_review`, `proposal_generation`): consulta o Evidence
  Ledger, detecta envelopes sem terminal, falhas por stage, gates bloqueados,
  drift de SLO, padroes de Repair Loop, rejeicoes de Kernel Pipeline e
  lacunas de tool evidence, alem de ler o Domain Catalog para propor evolucao
  de dominios `scaffold` ou `executable_incomplete`; registra `AtlasInitiativeRun`, emite
  `LEARNING_PROPOSED` e pode criar proposals seguras quando `--emit` estiver
  habilitado;
- comando `atlas:ai:self-improve --list-flows --json` para inspecionar os flows
  suportados sem executar;
- comando `atlas:ai:self-improve --flow=repair_loop_review --plan-only --json`
  para renderizar o plano, gates, runtime e executor sem criar `AtlasInitiativeRun`;
- comando `atlas:ai:self-improve --flow=repair_loop_review --hours=24 --limit=5 --json`
  com agendamento opcional por `atlas_ai.self_improvement.*`; o scheduler aceita
  `ATLAS_AI_SELF_IMPROVEMENT_FLOWS` como lista CSV de flows para rodar ciclos;
  o default recorrente roda `nightly_review,weekly_architecture_audit,repair_loop_review,kernel_pipeline_review,agent_behavior_review`
  especializados na madrugada;
- comando `atlas:ai:self-improve --flow=domain_learning_review --onboarding-status=scaffold --json`
  para revisar habilidades catalogadas que ainda nao podem ser tratadas como
  capacidade completa;
- comando `atlas:ai:ledger {envelope} --json` para replay operacional por
  `envelope_id`.
- comando `atlas:ai:architecture-validate --json` para validar contratos
  executaveis de Capability Registry e Domain/Profile Registry sem depender de
  leitura manual da documentacao.
- comando `atlas:ai:domains --json` para inventariar domains, flows e
  orchestrators, incluindo maturidade, runtime, autonomia, executor preference
  onboarding scorecard, contadores ready/scaffold/incomplete e filtros por
  domain/flow/maturity/onboarding status.
- endpoint `GET /ai/domains` usando o mesmo `AtlasAiDomainCatalogService` do
  CLI, para app e automacoes consumirem o contrato sem duplicar logica.
- `GET /ai/observability` inclui resumo `domain_catalog` com validacao,
  contadores de onboarding e distribuicao ready/scaffold/incomplete, para que o
  painel principal mostre quando uma habilidade ainda e apenas scaffold.

Todo fluxo operacional produz:

```json
{
  "evidence_packet_id": "uuid",
  "decision_id": "uuid",
  "domain": "programming",
  "flow": "programming.dev",
  "status": "passed|warning|blocked|failed",
  "artifacts": [],
  "tool_runs": [],
  "findings": [],
  "gates": [],
  "repair_attempts": [],
  "waivers": [],
  "risks": [],
  "summary": {}
}
```

Regra dura:

```text
Sem evidence packet, nao existe resolved em tarefa operacional.
```

## Gate Matrix

Gates sao proporcionais ao risco.

| Risco | Gates esperados |
|---|---|
| Low | sanity, diff summary, no obvious blockers |
| Medium | tests relevantes, lint/type quando aplicavel, risk note |
| High | harness ou tool runtime, security/quality gates, repair policy |
| Critical | sandbox/worktree, multi-gate, approval, release-style evidence |

Gate nao executa ferramenta diretamente. Gate avalia evidencia.

## Repair Loop

Repair deve ser um loop unico por domain, nao um comando separado.

Contrato:

```json
{
  "failure_type": "test_failure|tool_failure|provider_failure|policy_block|gate_block",
  "root_cause_hypothesis": "",
  "repair_action": "",
  "max_attempts": 2,
  "stop_conditions": [],
  "evidence_required": []
}
```

Regra:

```text
atlas fix e intent repair dentro do Atlas AI Programming.
```

## Learning Plane

Learning transforma execucao em vantagem acumulada.

Entradas:

- decision receipts;
- evidence packets;
- tool findings;
- user feedback;
- repair success/failure;
- benchmark scores;
- repeated friction;
- duplicated capability;
- missing context;
- failed gates.

Saidas:

- memory delta;
- doc update;
- policy suggestion;
- tool promotion;
- gate calibration;
- prompt/process improvement;
- benchmark update;
- curation proposal.

Learning nao deve promover memoria sensivel sem privacy policy.

## Human Knowledge Surface / Personal Knowledge Workspace

A Human Knowledge Surface / Personal Knowledge Workspace reune Obsidian,
AtlasVault, notas longas, pesquisas,
rascunhos, reflexoes, identidade, mapas de projeto e revisao de memorias.

Ele resolve um problema que nenhum provider resolve sozinho: continuidade
humana. O Atlas pode ler o que o operador escreveu ao longo do tempo, devolver
notas gerenciadas para revisao, criar backlinks `atlas://`, preparar resumos e
propor promocao de conhecimento para a memoria operacional.

Contrato duro:

- Obsidian/AtlasVault e Human Knowledge Surface / Personal Knowledge Workspace,
  nao core runtime operacional bruto.
- Open Brain nao deve ler nota solta diretamente em runtime.
- Nota so entra em contexto de provider apos classificacao, redacao,
  provider-safety, dedupe e link de evidencia.
- Docs canonicos, Postgres/index, memory/core, audits, code intelligence e
  evidence ledger continuam sendo fonte operacional.
- Atlas pode escrever notas no Vault como projection humana gerenciada, nao como
  substituto de migrations, services, docs canonicos ou testes.
- No kernel executavel, essa fronteira aparece como `atlas_vault`, adapter da
  Human Knowledge Surface. Ele declara texto, arquivos, workspace humano e
  managed note projection; nao declara decisao, provider, tool runtime,
  context compose ou memory recall. O poder do Vault vem da curadoria,
  backlinks, revisao humana e import/export seguro, nao de burlar o pipeline.

O documento que manda nesta fronteira e `obsidian-atlas-vault.md`.

## Self-Improvement Operations

O Atlas deve melhorar continuamente por ciclos controlados.

Tipos de ciclo:

| Ciclo | Frequencia | Objetivo |
|---|---|---|
| Nightly Review | diario, madrugada | detectar falhas repetidas, gaps, drift, regressao e propostas pequenas |
| Weekly Architecture Audit | semanal | revisar arquitetura, docs, duplicacoes, domain health e coverage |
| Benchmark Review | apos suite ou semanal | medir qualidade contra baseline, Claude Code e versoes anteriores |
| Memory Quality Review | diario/semanal | revisar recall, redundancia, stale memory, provider-safety e learning |
| Tool Runtime Review | diario/semanal | analisar falhas, tools ausentes, normalizers, recipes e gates |
| Domain Learning Review | semanal | transformar feedback e metricas em melhoria de profiles/policies/gates |

Cada ciclo gera:

- `SelfImprovementRun`;
- plano de orquestracao com domain/flow/profile;
- findings;
- proposals;
- evidence;
- risk classification;
- recommended actions;
- operator summary.

Autonomia permitida:

| Risco | Acao automatica permitida |
|---|---|
| Low | criar proposta, atualizar doc de baixo risco, abrir task, registrar memoria provider-safe |
| Medium | criar patch proposal e pedir revisao |
| High | apenas diagnosticar e recomendar |
| Critical | bloquear autonomia e exigir operador |

Esse e o caminho para autoaprimoramento real: medir, propor, validar e aprender,
sem permitir que o Atlas altere a si mesmo sem controle.

## Surface Plane

Surfaces permitidas:

- CLI;
- app;
- API;
- workers;
- MCP;
- IDE;
- mobile;
- automations.

Contrato de surface:

```text
Surface coleta input.
Surface passa hints.
Surface chama Atlas AI.
Surface renderiza output.
Surface nao cria fluxo paralelo.
```

Exemplo:

```text
atlas dev "corrija bug"
-> surface=cli
-> domain=programming
-> flow=programming.dev
-> same master pipeline
```

```text
app programming sheet
-> surface=app
-> domain=programming
-> flow=programming.dev
-> same master pipeline
```

## Anti-Duplicacao Executavel

Documentacao nao basta. O Atlas precisa de testes que falham quando a
arquitetura quebra.

Criar:

1. Capability Registry.
2. Surface Capability Matrix.
3. Domain Flow Registry.
4. Policy/Profile Registry.
5. Gate Registry.
6. Evidence Contract Tests.
7. Architecture Drift Tests.

Exemplo de teste:

```text
Se Atlas.Input suporta image_attachment,
entao ask, chat, dev, app e API devem declarar suporte ou justificar ausencia.
```

Outro:

```text
Se programming.forge exige tool_runtime_gate,
entao a evidence matrix precisa aceitar a mesma evidencia em programming.dev
quando o risco escalar.
```

## Como Atlas Vence Claude Code

Claude Code e um executor de programacao com UX forte.

Atlas deve ser um sistema operacional de programacao com:

- memoria longa;
- knowledge base versionada;
- code intelligence;
- context compiler;
- policy por risco;
- multi-provider opcional;
- Super Tool Runtime;
- Engineering Harness;
- gates objetivos;
- repair estruturado;
- evidence packet;
- replay;
- learning;
- fair benchmark.

### Comparacao Correta

Nao declarar:

```text
Atlas e 5x melhor porque usa varios modelos.
```

Declarar apenas quando medido:

```text
Atlas reduz intervencao humana, falhas finais, regressao e tempo de conclusao
em tarefas medias/dificeis por meio de orquestracao evidence-driven.
```

Metricas:

- task success rate;
- human intervention count;
- final bug count;
- regression count;
- test pass rate;
- repair success rate;
- time to verified completion;
- context reuse quality;
- cost per verified task;
- evidence completeness;
- user trust score.

### Fair Claude

Comparacao justa:

```text
Atlas Fair Claude = mesmo Claude, com Atlas orchestration.
Claude Code = Claude Code direto.
```

Se Atlas Fair Claude vence, o ganho vem da arquitetura, nao do modelo.

### Atlas Supercharged

Produto real:

```text
Atlas Supercharged = melhor provider/model graph + Atlas orchestration.
```

Esse modo pode usar Codex, Claude, Gemini, tools e harness, mas a medicao deve
separar ganho de modelo e ganho de orquestracao.

## Maturity Model

| Nivel | Nome | Criterio |
|---|---|---|
| L0 | Surface Commands | comandos chamam providers e services soltos |
| L1 | Shared Pipeline | surfaces entram em pipeline comum |
| L2 | Policy/Profile | profiles e policies resolvidos antes de execucao |
| L3 | Decision Receipt | Decide emite receipt auditavel |
| L4 | Domain Orchestrators | domains executam sem logica em comando |
| L5 | Evidence-Driven | gates e resolved dependem de evidence packet |
| L6 | Runtime Unified | tools/harness/providers rodam por runtime graph |
| L7 | Learning Loop | resultados melhoram memoria, policy, docs e gates |
| L8 | Curated OS | Curator detecta drift e corrige arquitetura |
| L9 | Enterprise Autonomous | autonomia alta com sandbox, approvals e auditabilidade |

Estado desejado para substituir Claude Code em tarefas medias/dificeis:

```text
L6 minimo.
L7 competitivo.
L8 superior.
```

## Roadmap De Implementacao

### Phase 1 - Architecture Lock

- tornar este documento leitura obrigatoria;
- atualizar START_HERE/README;
- classificar docs resolver P0/P1/P2;
- congelar vocabulário: domain, flow, profile, policy, receipt, orchestrator,
  runtime, gate, evidence.

### Phase 2 - Registries

- `ai_domain_profiles`;
- `ai_flow_profiles`;
- `AtlasDomainOrchestratorRegistry`;
- `ai_policy_profiles`;
- `ai_capabilities`;
- `ai_surface_capabilities`;
- `ai_gate_definitions`;
- `ai_evidence_contracts`;
- `ai_architecture_drifts`.
- `ai_domain_onboarding_checklists`;
- `ai_self_improvement_runs`;
- `ai_curation_proposals`.

Status parcial implementado:

- domain/flow profiles existem em banco e fallback estatico;
- `AtlasDomainOrchestratorRegistry` mapeia nomes curtos de manifest para
  classes PHP reais;
- `AtlasDomainManifestValidator` valida orchestrator por classe, interface,
  maturidade e suporte declarado ao domain/flow;
- `AtlasAiDomainCatalogService` fornece um payload unico para CLI e API;
- `AtlasDomainOnboardingScorecard` calcula 9 fases de incorporacao por dominio
  (`charter`, `profile`, `context`, `orchestrator`, `runtime`, `gates`,
  `learning`, `surface`, `maturity_gate`) e expõe proximas acoes;
- `atlas:ai:domains` e `GET /ai/domains` tornam o catalogo auditavel por
  operador, app e automacao, com filtro `--onboarding-status`/`onboarding_status`
  para isolar rapidamente dominios `ready`, `executable_incomplete` ou `scaffold`;
- `programming` esta `ready 9/9`: declara context policy Open Brain/code
  intelligence, memory/learning policy, gates por flow e surfaces oficiais
  (`atlas dev`, `atlas forge`, `atlas fix`, `atlas continue`, chat dev/review/debug,
  API, app e MCP);
- `self_improvement` esta `ready 9/9`: declara 13 flows especializados de
  auditoria/evolucao, fontes de contexto (`atlas_evidence_ledger`, architecture
  validation, domain scorecards, KB, code intelligence, tool evidence, memory
  quality, provider performance e benchmark corpus), learning policy, gates de
  risco/evidencia/aprovacao e surfaces scheduler/CLI/API/app;
- `finance` esta `ready 9/9`: declara 10 flows enterprise de pesquisa,
  risco, portfolio, tese, macro, earnings, impacto de noticias, compliance,
  backtest e forge, com autonomia baixa, gates de compliance/source/risk,
  memoria provider-safe e proibicao absoluta de execucao de mercado;
- `personal_development` esta `ready 9/9`: declara 10 flows privados
  plan-only para reflexao, revisoes, habitos, foco, aprendizado, energia,
  objetivos, recuperacao e forge, com redacao obrigatoria para provider-safe,
  linguagem nao clinica e bloqueio de mutacao automatica de calendario/tarefas;
- `marketing` esta scaffold/catalog-ready: declara catalogo alvo com 15 flows
  de estrategia, pesquisa, positioning, campanha, criativos, copy, midia,
  landing page, email, social, video script, A/B test, analytics, brand review e
  forge, mas ainda precisa runtime/orchestrator proprio antes de virar
  implemented/ready;
- `research`, `health`, `learning`, `writing`, `qa`, `security`,
  `operations`, `background` e `general` tambem sao scaffolds auditaveis para
  onboarding incremental sem sumir da validacao arquitetural.

### Phase 3 - Control Plane Services

- `AtlasInputService`;
- `AtlasIntentService`;
- `AtlasProfileResolver`;
- `AtlasContextCompiler`;
- `AtlasAiPolicyService`;
- `AtlasDecisionReceipt`;
- `DomainOrchestratorRouter`.

### Phase 4 - Programming Unification

- `AtlasProgrammingOrchestrator` vira autoridade unica;
- `atlas dev`, `atlas:ai:chat --dev`, `forge`, `fix`, `continue`, app e worker
  chamam o mesmo orchestrator;
- repair loop unificado;
- quality matrix por risco;
- evidence packet obrigatorio.

### Phase 5 - Runtime Graph

- provider executor adapter;
- Super Tool Runtime planner;
- Engineering Harness adapter;
- visual/browser adapter;
- sandbox/worktree policy;
- runtime graph telemetry.

### Phase 6 - Evidence And Gates

- final evidence packet;
- gate matrix;
- waiver policy;
- release/security/SBOM integration;
- provider-safe evidence export.

### Phase 7 - Learning And Curator

- memory delta;
- architecture drift detector;
- capability coverage tests;
- process improvement proposals;
- benchmark feedback loop.

### Phase 7.5 - Self-Improvement Operations

- nightly review automation;
- weekly architecture audit;
- memory quality review;
- tool runtime review;
- domain learning review;
- proposal ranking;
- risk-based autonomy policy;
- operator digest.

### Phase 8 - Competitive Benchmark

- Fair Claude benchmark;
- Atlas Supercharged benchmark;
- task suite medium/hard;
- scorecard published in docs;
- regression suite before release claims.

## Non-Negotiable Rules

1. Provider e motor, nao autoridade.
2. Surface e porta, nao produto interno.
3. Profile e fluxo operacional, nao modelo.
4. Policy e lei, nao preferencia informal.
5. Decide compila, nao executa domain.
6. Orchestrator executa semantica, runtime executa mundo real.
7. Gate julga evidencia, nao opiniao.
8. Repair e loop do domain, nao comando separado.
9. Evidence e obrigatoria para `resolved`.
10. Learning precisa de privacy e qualidade.
11. Capability horizontal nasce no Core.
12. Curator protege o Atlas contra a propria expansao.
13. Novo domain entra por Domain Onboarding Protocol.
14. Autoaprimoramento cria evidence e proposals antes de mudar comportamento critico.

## Definicao De Pronto Da Arquitetura-Mae

A arquitetura-mae esta pronta quando:

- todo comando principal mapeia para surface + domain + flow;
- nenhum comando principal escolhe provider fora de policy/Decide;
- todo fluxo operacional gera Decision Receipt;
- todo fluxo operacional gera Evidence Packet;
- Dev e Forge compartilham Programming Orchestrator;
- Tool Runtime e consumido por risco, nao por comando;
- paste-image/anexos/memoria/tools/gates possuem coverage por surface;
- Curator consegue detectar capability solta;
- Self-Improvement roda ciclos agendados com findings, proposals e evidence;
- novo domain complexo pode ser adicionado seguindo Domain Onboarding Protocol;
- benchmarks separam ganho de modelo e ganho de orquestracao;
- docs canonicos e codigo contam a mesma historia.

## Conclusao

Atlas AI nao deve tentar ser apenas um Claude Code alternativo.

Atlas AI deve ser a camada operacional que torna qualquer modelo melhor,
qualquer ferramenta auditavel, qualquer fluxo mensuravel e qualquer aprendizado
persistente.

O cerebro do Atlas e esta arquitetura:

```text
Policy-governed.
Profile-aware.
Decision-receipted.
Domain-orchestrated.
Runtime-executed.
Evidence-gated.
Repair-capable.
Memory-backed.
Curator-evolved.
```

Esse e o caminho para vencer Claude Code em qualidade real, nao em marketing.
