---
id: atlas-ai-qualitative-levels-roadmap
type: engineering_knowledge
title: Atlas AI Qualitative Levels Roadmap
status: active
category: roadmap
priority: 96
summary: Roadmap canonico curto dos patamares qualitativos do Atlas AI, subordinado a tese do multiplicador e usado para transformar visao de longo prazo em fila governada de implementacao.
tags:
  - atlas-ai
  - qualitative-levels
  - roadmap
  - long-term-strategy
  - curator
capabilities:
  - qualitative_levels_roadmap
  - strategic_decision_domain
  - curator_self_evolution
  - long_lived_memory
  - rivals_strategy_validation
decisions:
  - O roadmap de patamares e uma bussola qualitativa, nao metrica comercial nem autorizacao para execucao automatica.
  - Patamar superior significa mudanca de natureza da relacao Atlas/Vitor, nao apenas mais velocidade ou mais features.
  - Co-estrategista e dominio futuro governado: Atlas pode discordar e propor, mas nao decidir pelo Vitor.
  - Todo salto de patamar exige evidence, Rivals, gates de agency humana e revisao constitucional.
maintenance:
  - Manter abaixo de 260 linhas.
  - Atualizar antes de promover qualquer item P2-P7 para implementacao.
  - Rodar docs-health e architecture-validate depois de alterar.
related_paths:
  - docs/atlas-outro-patamar-roadmap.md
  - docs/archive/atlas-outro-patamar-roadmap-v0-pre-thesis.md
  - docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-autonomy-power-backlog.md
  - docs/engineering-knowledge-base/atlas-ai-governed-backlog.md
  - docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
  - docs/engineering-knowledge-base/domains/self-improvement.md
  - docs/engineering-knowledge-base/domains/personal-development.md
  - app/Services/Ai/Kernel/Architecture/AtlasQualitativeLevelsReadModel.php
  - app/Services/Ai/Kernel/Architecture/AtlasRivalsStrategyReadModel.php
  - app/Console/Commands/AtlasAiQualitativeLevelsCommand.php
  - app/Console/Commands/AtlasAiRivalsStrategyCommand.php
  - app/Http/Controllers/AtlasAiQualitativeLevelsController.php
---

# Atlas AI Qualitative Levels Roadmap

Este e o contrato curto para o "outro patamar" do Atlas.

O arquivo longo `docs/atlas-outro-patamar-roadmap.md` permanece como source
roadmap expandido. Este documento e a versao operacional que entra na Knowledge
Base, context packs e governanca.

## Veredito Critico

Faz sentido, com ressalvas.

O conceito e forte porque separa evolucao qualitativa de feature list. Atlas ja
mudou de natureza uma vez: deixou de ser wrapper de Claude/Codex e virou
ecossistema com Kernel, Decide, Evidence, Memory, Domains, Tools, Curator,
surfaces e governance. Portanto faz sentido medir proximos saltos por mudanca
de relacao, nao por "mais comandos".

Mas isso nao pode virar fantasia operacional. Cada patamar precisa de:

1. contrato canonico;
2. capability registry;
3. evidence no Ledger;
4. Rivals ou benchmark equivalente;
5. gates de agency humana;
6. limites de privacy e autonomia;
7. rollback quando multiplicador for negativo.

## Autoridade

| Assunto | Fonte |
|---|---|
| Tese multiplicador/canal unico | `atlas-ai-thesis-multiplier-channel.md` |
| Kernel executavel | `atlas-ai-kernel-architecture.md` |
| Planes/domain strategy | `atlas-ai-master-architecture.md` |
| Roadmap expandido | `docs/atlas-outro-patamar-roadmap.md` |
| Versao operacional curta | este documento |

Quando houver conflito, Layer -1 e Kernel vencem. Este doc nao autoriza runtime
paralelo, vigilancia continua, decisao automatica ou self-modification sem gate.

## Definicao De Outro Patamar

Outro patamar = Atlas vira outra natureza de relacao com:

1. tempo: lembra, compara e aprende por anos;
2. self do Vitor: modela valores, padroes e drift sem controlar;
3. providers: usa todos como insumo, mantendo canal unico;
4. autonomia: propõe e executa tarefas longas com receipts e gates;
5. ambiente: aparece no canal certo, no momento certo, com opt-in.

Nao e "mais rapido". Nao e "mais inteligente" isoladamente. E mudanca na forma
como Atlas participa da vida, trabalho e estrategia.

## Patamares P1-P7

| Patamar | Nome | Sinal qualitativo |
|---|---|---|
| P1 | Reativo Contextualizado | responde usando memoria e evidence reais |
| P2 | Proativo Calibrado | interrompe quando vale, com baixa taxa de falso positivo |
| P3 | Autonomo de Tarefas Longas | opera horas/dias com gates, budget e repair seguros |
| P4 | Simbiotico Discordante | discorda com base epistemica e Vitor reconhece valor |
| P5 | Auto-Modificavel Auditado | Curator escreve PR em si mesmo e Rivals confirma ganho |
| P6 | Federado Encarnado | surfaces ambiente tornam Atlas presente sem friccao |
| P7 | Espelho Longevo | anos/decadas de Ledger revelam padroes invisiveis ao Vitor |

Estado atual: P1 com pecas de P3/P5 em construcao. Nao declarar P4+ ate haver
evidence longitudinal.

## Eixos X/Y/Z

| Eixo | Pergunta | Governanca |
|---|---|---|
| Y profundidade epistemica | quanto Atlas modela Vitor no tempo? | memory, privacy, forgetting, evidence |
| X autonomia operacional | quanto Atlas age sem supervisao continua? | receipt, budget, gates, approvals |
| Z presenca ambiental | onde Atlas aparece no ambiente? | opt-in, privacy, no-surveillance, eclipse modes |

O avanco saudavel exige equilibrio. Presenca ambiental sem profundidade vira
notificacao irritante. Autonomia sem Kernel vira agente solto. Profundidade sem
agency humana vira risco de dependencia.

## Dominio Futuro: Decisao Estrategica

Status: `future/scaffold`.

Objetivo: Atlas atua como co-estrategista de longo prazo. Ele questiona,
compara, relembra, simula e propõe. Ele nao decide pelo Vitor.

Flows candidatos:

1. `strategic_decision.review`
2. `strategic_decision.cooldown`
3. `strategic_decision.values_alignment`
4. `strategic_decision.counterargument`
5. `strategic_decision.regret_tracking`
6. `strategic_decision.longitudinal_pattern`

Gates obrigatorios:

1. cool-down gate para decisoes grandes;
2. multi-perspective gate;
3. values-alignment gate;
4. human agency gate;
5. no-oracle gate;
6. privacy/redaction gate.

## Sinais De Mudanca De Patamar

| Transicao | Sinal |
|---|---|
| P1 -> P2 | Atlas interrompe pouco, mas quase sempre com utilidade reconhecida |
| P2 -> P3 | tarefas longas terminam com evidence, baixo rework e baixo regret |
| P3 -> P4 | Atlas discorda, Vitor admite que havia base, regret cai |
| P4 -> P5 | Curator gera PR, Vitor aprova, Rivals confirma multiplicador positivo |
| P5 -> P6 | Atlas aparece no ambiente certo sem aumentar friccao ou dependencia |
| P6 -> P7 | Atlas revela padroes de anos que Vitor nao veria sozinho |

## Riscos Bloqueantes

| Risco | Defesa |
|---|---|
| Atlas vira oraculo | proposal-only em estrategia; Vitor decide |
| gaslighting algoritimico | drift e observacao, nunca correcao de identidade |
| dependencia cognitiva | eclipse modes e teste "Vitor sem Atlas por 1 semana" |
| vigilancia ambiente | opt-in, minimizacao, local-first e no-surveillance default |
| self-modification ruim | Constitution class-3, Rivals e rollback |
| multiplicador negativo | stop-the-line |
| dominio demais | hibernacao e poda por evidence |

## Fila Governada De Implementacao

### QL-0 — Promocao documental

Status: feito por este documento.

DoD:

1. source roadmap preservado;
2. spec curta na KB;
3. indice canonico atualizado;
4. docs-health verde.

### QL-1 — Patamar maturity model

Status: implementado como read model.

Superficie: `atlas ai qualitative-levels --hours=720 --json` e
`GET /ai/qualitative-levels`.

Declara `current_level`, `evidence`, `missing_gates` e `next_level_blockers`.

Nao muda comportamento. So mede.

### QL-2 — Rivals Strategy

Status: implementado como storage/read model inicial.

Superficie: `atlas ai rivals-strategy report --hours=8760 --json`.

Registra decisao direta vs assistida por Atlas, revisitas 30/90/180/365 e
scores de regret/alignment/agency. Precisa casos reais para liberar P4+.

### QL-3 — Strategic Decision domain scaffold

Status: implementado como dominio scaffold, com Domain Profile e flows
`strategic_decision.*` sem execucao automatica.

### QL-4 — Co-Strategist plan-only

Status: iniciado com packet plan-only via `atlas ai strategic-decision review`.

Entrega counterargument, values alignment, cool-down, agency gate e Rivals hint.
Com `--audit`, emite DecisionReceipt dry-run e eventos no Evidence Ledger.

### QL-5 — Curator mutation classes

Formalizar classes de mutacao:

1. class-1 auto-aplicavel de baixo risco;
2. class-2 exige review Vitor;
3. class-3 constitucional imutavel.

### QL-6 — Presence and eclipse governance

Antes de qualquer ambiente/voz/sensor, implementar:

1. opt-in explicito;
2. modos eclipse;
3. retention;
4. local-first quando sensivel;
5. no-auto-action.

### QL-7 — Voice Realtime Surface (Eixo Z-P1 ativo)

Status: spec canonica ativa em `atlas-ai-voice-realtime-surface.md` (Layer 3,
status scaffold com Fases 0-3 declaradas). Destrava voz/microfone do escopo
future do `atlas-native-mac-agent.md` sob:

1. wake word local sem stream ambiente;
2. atlas-voice-edge (Swift) registrado como `swift_native_mac` com capability
   `voice.realtime.edge`;
3. LiveKit Server self-hosted (Go) + LiveKit Agents (Python);
4. eclipse modes class-3 imutaveis (calendar, focus, manual, domain rule);
5. privacy class por domain;
6. Rivals-Voice como medidor de multiplicador.

QL-7 e o primeiro avanco real do Eixo Z (presenca ambiental) com governanca
suficiente. Avancos posteriores (vision ambiente, sensores fisiologicos)
permanecem future ate Curator+Constitutional class-3 estarem maduros.

## Regras De Implementacao

1. Nenhum item P4+ entra sem Evidence Ledger.
2. Nenhum item de estrategia executa acao externa.
3. Nenhum sensor entra sem privacy review.
4. Nenhum domain novo vira ready sem Rivals ou benchmark proprio.
5. Nenhuma memoria pessoal crua vai para provider sem redaction.
6. Nenhum Curator auto-aplica mudanca estrutural sem classe de mutacao.

## Conclusao

O roadmap faz sentido como norte de alto nivel. A parte mais valiosa e tratar
"outro patamar" como mudanca qualitativa: interrupcao util, discordancia
epistemica, auto-evolucao auditada e memoria longitudinal.

A parte perigosa e transformar essa visao em autonomia, ambiente ou decisao
estrategica sem safeguards. Portanto o caminho correto e: medir patamar antes,
criar Rivals Strategy, depois domain scaffold, depois co-estrategista plan-only.
