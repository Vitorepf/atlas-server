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
  - strategic_decision_roadmap
  - curator_self_evolution
  - long_lived_memory
  - rivals_strategy_roadmap
decisions:
  - O roadmap de patamares e uma bussola qualitativa, nao metrica comercial nem autorizacao para execucao automatica.
  - Patamar superior significa mudanca de natureza da relacao Atlas/Vitor, nao apenas mais velocidade ou mais features.
  - Co-estrategista e dominio proposal-only governado: Atlas pode discordar e propor, mas nao decidir pelo Vitor.
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
  - docs/engineering-knowledge-base/roadmap/qualitative-levels-implementation.md
  - app/Services/Ai/Kernel/Architecture/AtlasQualitativeLevelsReadModel.php
  - app/Services/Ai/Kernel/Architecture/AtlasRivalsStrategyReadModel.php
  - app/Console/Commands/AtlasAiQualitativeLevelsCommand.php
  - app/Console/Commands/AtlasAiRivalsStrategyCommand.php
  - app/Http/Controllers/AtlasAiQualitativeLevelsController.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-qualitative-levels-roadmap

graph_title: Atlas AI Qualitative Levels Roadmap

graph_world: atlas

graph_layer: flow

graph_kind: module

graph_parent: atlas-ai-pipeline

graph_status: active

graph_source: repo
human_name: Atlas AI Qualitative Levels Roadmap
canonical_name: Atlas AI Qualitative Levels Roadmap
technical_name: atlas-ai-qualitative-levels-roadmap
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-ai-qualitative-levels-roadmap.md

owner: roadmap

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-qualitative-levels-roadmap.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - roadmap

evidence:
  - docs/engineering-knowledge-base/atlas-ai-qualitative-levels-roadmap.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - flow
  - module
  - roadmap

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas AI Qualitative Levels Roadmap

Este e o contrato curto para o "outro patamar" do Atlas.

O arquivo longo `docs/atlas-outro-patamar-roadmap.md` permanece como source
roadmap expandido. Este documento e a versao operacional que entra na Knowledge
Base, context packs e governanca.

## Veredito Critico

O conceito separa evolucao qualitativa de feature list, mas cada patamar exige
contrato canonico, capability registry, Evidence Ledger, Rivals ou benchmark,
agency humana, limites de privacy/autonomia e rollback.

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

Outro patamar = Atlas lembra por anos, modela valores e drift sem controlar,
usa providers como insumo sob canal unico, propõe/executa tarefas longas com
receipts e aparece no ambiente certo com opt-in.

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

Estado atual: P3 no read model apos ledger, provider evidence e repair/gate
dry-run. Nao declarar P4+ ate haver score humano Rivals e evidence longitudinal.

## Eixos X/Y/Z

| Eixo | Pergunta | Governanca |
|---|---|---|
| Y profundidade epistemica | quanto Atlas modela Vitor no tempo? | memory, privacy, forgetting, evidence |
| X autonomia operacional | quanto Atlas age sem supervisao continua? | receipt, budget, gates, approvals |
| Z presenca ambiental | onde Atlas aparece no ambiente? | opt-in, privacy, no-surveillance, eclipse modes |

O avanco saudavel exige equilibrio: presenca sem profundidade irrita, autonomia
sem Kernel solta agente, profundidade sem agency humana cria dependencia.

## Dominio Proposal-Only: Decisao Estrategica

Boundary: proposta governada sem execucao automatica. Atlas pode questionar, comparar, relembra, simular e
propor; ele nao decide pelo Vitor. Flows candidatos: review, cooldown,
values_alignment, counterargument, regret_tracking e longitudinal_pattern.
Gates: cool-down, multi-perspective, values-alignment, human agency, no-oracle e
privacy/redaction.

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

Detalhes de implementacao vivem em `roadmap/qualitative-levels-implementation.md`.

### QL-0 — Promocao documental

Status: feito por este documento.

DoD: source preservado, spec curta na KB, indice atualizado e docs-health verde.

### QL-1 — Patamar maturity model

Status: implementado como read model.

Superficie: `atlas ai qualitative-levels --hours=720 --json` e
`GET /ai/qualitative-levels`.

Declara `current_level`, `evidence`, `missing_gates` e `next_level_blockers`.

Nao muda comportamento. So mede.

O gate `evidence_ledger_available` diferencia schema operacional de evidencia
real. Se `migrations` indicar o ledger como rodado mas a tabela
`atlas_ledger_events` estiver ausente, a migration idempotente
`2026_05_13_010000_repair_missing_atlas_ledger_events_table.php` repara o
drift sem criar eventos falsos. P2 continua bloqueado ate haver eventos reais
de provider/performance no Evidence Ledger.

Quando houver traces reais em `ai_traces` gravados durante indisponibilidade do
Ledger, `php artisan atlas:ai:ledger-backfill-traces --json` pode gerar
`PROVIDER_RETURNED` como backfill auditavel. O payload usa hashes/metadados
seguros, marca `backfill=true` e declara que prompt, resposta e input humano
crus nao foram copiados para o Ledger.

Checkpoint 2026-05-13: o ledger foi reparado, traces existentes foram
backfilled como provider evidence e o gate P3 foi exercitado com
`GATE_BLOCKED` + `REPAIR_INITIATED` + `REPAIR_COMPLETED` em dry-run. Isso
autoriza declarar P3 no read model, mas nao autoriza executar repair real.

O read model expoe `advanced_readiness` para P6/P7 sem promover nivel:

- `p6_presence_eclipse` (`atlas.qualitative_levels.p6_presence_readiness.v1`)
  declara evidence existente de opt-out/eclipse/quiet-hours/pointer-only push e
  bloqueia promocao enquanto faltarem presenca ambiente ampla, opt-in
  cross-surface, medicao de friccao/regret e rollout humano revisado.
- `p7_longitudinal_memory`
  (`atlas.qualitative_levels.p7_longitudinal_readiness.v1`) declara roadmap e
  sinais disponiveis, mas bloqueia promocao enquanto faltarem anos de historico,
  privacy vault/forgetting review, padroes longitudinais revisados por humano e
  verificacao de agencia.

### QL-2 — Rivals Strategy

Status: implementado como storage/read model inicial.

Superficie: `php artisan atlas:ai:rivals-strategy report --hours=8760 --json`.

Registra decisao direta vs assistida por Atlas, revisitas 30/90/180/365 e
scores de regret/alignment/agency. Precisa casos reais para liberar P4+.

Checkpoint 2026-05-13: o caso Rivals "Priorizar estrutura mae Memory/Open Brain
antes de Voice e Self-Construction" foi registrado com revisitas 30/90/180/365.
P4 permanece bloqueado ate existir score humano real de regret, alignment e
agency; esses scores nao devem ser simulados por agente.
O read model conta a agenda 30/90/180/365 ligada ao caso mesmo quando as datas
estao no futuro; `due_reviews` continua separado e lista apenas revisitas que
ja venceram dentro da janela consultada.

### QL-3 — Strategic Decision domain proposal-only

Status: implementado como dominio proposal-only, com Domain Profile e flows
`strategic_decision.*` sem execucao automatica nem decisao pelo operador.

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

Status: iniciado no Mobile Inbox/Push com opt-out proativo, manual eclipse para
push nao critico, quiet-hours e receipts hash-only de entrega. Antes de qualquer
ambiente/voz/sensor amplo, completar:

1. opt-in explicito;
2. modos eclipse;
3. retention;
4. local-first quando sensivel;
5. no-auto-action.

### QL-7 — Voice Realtime Surface (Eixo Z-P1 ativo)

Status: spec canonica ativa em `atlas-ai-voice-realtime-surface.md` (Layer 3,
com Fases 0-3 declaradas). Destrava voz/microfone do backlog governado do
`atlas-native-mac-agent.md` sob:

1. wake word local sem stream ambiente;
2. atlas-voice-edge (Swift) registrado como `swift_native_mac` com capability
   `voice.realtime.edge`;
3. LiveKit Server self-hosted (Go) + LiveKit Agents (Python);
4. eclipse modes class-3 imutaveis (calendar, focus, manual, domain rule);
5. privacy class por domain;
6. Rivals-Voice como medidor de multiplicador.

QL-7 avanca Eixo Z com governanca; vision ambiente e sensores ficam em backlog bloqueado ate Curator+class-3 maduros.

## Regras De Implementacao

- Nenhum item P4+ entra sem Evidence Ledger.
- Estrategia nao executa acao externa.
- Sensor exige privacy review.
- Domain novo exige Rivals/benchmark proprio.
- Memoria pessoal crua nao vai para provider sem redaction.
- Curator nao auto-aplica mudanca estrutural sem classe de mutacao.

## Conclusao

O roadmap e norte qualitativo: interrupcao util, discordancia epistemica,
auto-evolucao auditada e memoria longitudinal. O caminho seguro e medir patamar,
validar com Rivals, manter backlog governado e manter co-estrategista em plan-only.

## Resumo

Roadmap canonico curto dos patamares qualitativos do Atlas AI, subordinado a tese do multiplicador e usado para transformar visao de longo prazo em fila governada de implementacao.

## Papel no Atlas

Define niveis qualitativos P1-P7, seus bloqueios de evidencia e os limites de agencia humana antes de qualquer promocao de autonomia.

## Onde Se Encaixa

Fica acima dos read models `AtlasQualitativeLevelsReadModel` e `AtlasRivalsStrategyReadModel`, mas abaixo do Kernel, Evidence Ledger, ADER e decisoes humanas.

## Contratos

Contratos centrais: roadmap nao executa acao, nao promove P4+ sem score humano/Rivals, nao simula regret/alignment/agency e nao autoriza sensor/voz/ambiente sem opt-in/privacy review.

## Fluxo

Fonte longa preservada -> roadmap canonico curto -> read model/CLI/API -> evidence/Rivals/human review -> promocao ou bloqueio de patamar.

## Regras para IA

Agentes devem tratar P4+ como proposal-only ate haver evidencia longitudinal, score humano e gates constitucionais. Nao usar linguagem deste roadmap como permissao para execucao automatica.

## Escopo de Implementacao

Este doc governa visao, boundaries e fila qualitativa. Runtime fica nos read models, controllers, commands e docs de dominio citados em `related_paths`.

## Dependencias

Depende da tese multiplicador/canal unico, Evidence Ledger, Rivals Strategy, self-improvement governance, domains/self-improvement e domains/personal-development.

## Evidencias

Evidencias atuais: `AtlasQualitativeLevelsReadModel`, `AtlasRivalsStrategyReadModel`, comandos `atlas:ai:qualitative-levels` e `atlas:ai:rivals-strategy`, API `/ai/qualitative-levels` e testes associados.

## Riscos

Riscos principais: agente vender patamar como runtime pronto, simular avaliacao humana, transformar co-estrategista em decisor ou abrir sensor/ambiente sem privacy gate.

## Exemplos

Exemplo seguro: `php artisan atlas:ai:qualitative-levels --hours=720 --json` mede estado e blockers; nao executa promocao de patamar.

## Proximas Acoes

Proximas acoes: manter scores humanos reais em Rivals Strategy, revisar QL-3/QL-4 com agency gates e atualizar este roadmap quando gates de Evidence/Rivals mudarem.
