---
id: atlas-ai-scenario-simulation-harness
type: engineering_knowledge
title: Atlas AI Scenario Simulation Harness
status: active
category: architecture
priority: 91
summary: Contrato alvo para implementar simulacoes multiagente inspiradas por MiroFish/OASIS como harness governado do Atlas, com calibracao contra resultado real.
tags:
  - atlas-ai
  - scenario-simulation
  - multi-agent
  - graph-rag
  - calibration
capabilities:
  - scenario_simulation_harness
  - multi_agent_simulation
  - outcome_calibration
  - prediction_evidence_loop
decisions:
  - Simulacao de cenarios e harness/capability compartilhada, nao Atlas AI Domain separado por padrao.
  - MiroFish e source material; Atlas deve reimplementar padroes uteis sem copiar codigo AGPL para produto fechado sem decisao juridica.
  - Saida de simulacao e hipotese/projecao com incerteza, nunca verdade ou previsao garantida.
  - Toda simulacao importante deve ter plano de avaliacao posterior contra resultado real.
maintenance:
  - Manter abaixo de 300 linhas.
  - Atualizar antes de implementar swarm, scenario simulation, market/campaign simulation, OASIS adapter ou calibration metrics.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-autonomy-power-backlog.md
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
  - docs/engineering-knowledge-base/atlas-ai-business-contexts.md
  - docs/engineering-knowledge-base/domains/finance.md
  - docs/engineering-knowledge-base/domains/self-improvement.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-scenario-simulation-harness

graph_title: Atlas AI Scenario Simulation Harness

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Scenario Simulation Harness
canonical_name: Atlas AI Scenario Simulation Harness
technical_name: atlas-ai-scenario-simulation-harness
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-ai-scenario-simulation-harness.md

owner: architecture

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-scenario-simulation-harness.md

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
  - architecture

evidence:
  - docs/engineering-knowledge-base/atlas-ai-scenario-simulation-harness.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - architecture

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
# Atlas AI Scenario Simulation Harness

Este documento define como o Atlas deve implementar simulacoes de cenarios
multiagente. A inspiracao e MiroFish/OASIS, mas o contrato Atlas e mais rigoroso:
simulacao gera hipoteses auditaveis, mede resultado real depois e calibra.

## Regra Mae

```text
Simulacao nao e profecia.
Simulacao e ensaio estruturado de cenarios com incerteza, evidencia e calibracao.
```

O Atlas deve usar simulacao quando a resposta depende de reacao coletiva,
incentivos, narrativa, propagacao social, segunda ordem ou comportamento de
audiencias. Para resposta factual simples, nao usar swarm.

## Onde Entra No Atlas

Scenario Simulation e capability/harness consumida por dominios:

| Consumidor | Uso correto |
|---|---|
| Marketing | testar narrativa, oferta, preco, criativo, publico e resistencia antes de gastar |
| Strategic Decision | simular stakeholders, coalizoes, trade-offs e reacoes |
| Finance | review-only: narrativa de mercado, risco, cenarios; nunca ordem automatica |
| Business Contexts | Blackink/futuras empresas como contexto, nao dominio novo |
| Self-Improvement | simular impacto de mudancas do proprio Atlas em modo proposal-only |

Nao criar `mirofish` ou `simulation` como AI Domain ate existir metodologia,
runtime, gates, memory projection e onboarding proprios.

## Pipeline Alvo

```text
Input estrategico
-> Intent: scenario_simulation_needed?
-> Seed Pack
-> Knowledge Graph / GraphRAG
-> Persona/Audience Builder
-> Environment Builder
-> Simulation Runs
-> ReportAgent
-> Decision Support Packet
-> Evidence Ledger
-> Outcome Tracking
-> Calibration / Learning
```

Cada etapa escreve Evidence Ledger quando a simulacao for medium/high risk.

## Seed Pack

Seed Pack contem:

pergunta, business_context, dominio consumidor, documentos/links/metricas,
fatos fixos, assumptions, variaveis, janela temporal, criterios de sucesso,
plano de observacao real, privacidade e provider policy.

Sem criterio de sucesso ou plano de observacao, a simulacao vira exploration,
nao prediction-grade.

## Graph E Personas

Atlas deve construir grafo local primeiro quando possivel:

```text
entities, stakeholders, audience segments, incentives, claims,
relationships, channels, constraints, prior events, source refs
```

Personas precisam ter provenance:

baseadas em dados reais/segmento/hipotese declarada; com influencia,
resistencia, objetivos, vieses, prompt/model version e `persona_confidence`;
sem inventar demografia sensivel desnecessaria.

## Simulation Runs

Rodar pelo menos: baseline, optimistic, pessimistic, contrarian/rival e stress.

Para casos importantes, rodar varias sementes/temperaturas e agregar. A saida
deve mostrar dispersao, nao apenas uma narrativa bonita.

## Output Contract

Todo relatorio deve incluir: executive summary, top scenarios, turning points,
objections/resistance, stakeholder map, leading indicators, confidence,
uncertainty, assumptions, falsification criteria, next experiment, follow-up
questions e outcome tracking plan.

Nao usar linguagem de certeza como "vai acontecer". Usar "simulacao sugere",
"cenario plausivel", "risco observado", "sinal a monitorar".

## Resultado Real E Calibracao

O ciclo fecha apenas quando o Atlas compara simulacao com realidade.

```text
Simulation Packet
-> Outcome Observation Window
-> Real Outcome Capture
-> Metric Extraction
-> Calibration Score
-> Error Analysis
-> Prompt/Persona/Graph/Gate Proposal
-> AP-99 / Self-Improvement
```

## Outcome Tracking Plan

Cada simulacao prediction-grade deve declarar:

| Campo | Exemplo |
|---|---|
| `outcome_window` | 7 dias, 30 dias, apos campanha, apos release |
| `observable_metrics` | CTR, CPA, churn, sentiment, support tickets, revenue, bug rate |
| `expected_ranges` | baixo/base/alto com intervalo |
| `leading_indicators` | comentarios, rejeicao, shares, search volume, error rate |
| `ground_truth_sources` | analytics, tracker, ledger, CRM, logs, relatorio humano |
| `review_date` | data para comparar |
| `owner` | usuario, dominio, Curator |

Sem esses campos, o Atlas nao aprende; apenas gera storytelling.

## Calibration Metrics

Registrar:

```text
simulation_id
domain_id
business_context
provider/model
seed_pack_hash
graph_version
persona_builder_version
scenario_count
predicted_outcomes
real_outcomes
directional_accuracy
range_error
ranking_accuracy
timing_error
surprise_events
human_usefulness_score
decision_regret_score
cost
latency
```

Para Marketing, exemplos:

```text
predicted objection order vs real objections
predicted CTR range vs real CTR
predicted CPA range vs real CPA
predicted sentiment vs comments/support data
```

Para Programming/Product:

```text
predicted rollout risk vs incident count
predicted user confusion vs support tickets
predicted performance impact vs measured latency
```

## Error Analysis

Quando errar, classificar: seed incomplete, wrong entity graph, weak persona,
missing external event, model/provider weakness, overfit narrative, metric
mismatch, outcome window wrong, human execution differed from plan ou
random/irreducible uncertainty.

Essa taxonomia alimenta Self-Improvement. O Atlas nao deve "forcar" a historia
para parecer que acertou.

## Evidence Ledger Events

Eventos futuros esperados:

```text
SCENARIO_SIMULATION_REQUESTED
SIMULATION_SEED_PACK_CREATED
SIMULATION_GRAPH_BUILT
SIMULATION_PERSONAS_CREATED
SIMULATION_RUN_STARTED
SIMULATION_RUN_COMPLETED
SIMULATION_REPORT_GENERATED
SIMULATION_OUTCOME_WINDOW_OPENED
SIMULATION_REAL_OUTCOME_RECORDED
SIMULATION_CALIBRATED
SIMULATION_IMPROVEMENT_PROPOSED
```

## Runtime Boundaries

Laravel continua Kernel/Maestro. Python e o runtime natural para GraphRAG,
multiagent simulation, analytics e calibration. Go so entra se houver ingestao
massiva/tempo real. Swift pode fornecer contexto local opt-in, nunca simular por
conta propria.

## Safety

Finance permanece analysis/review-only. Simulacao nunca autoriza ordem,
transacao, deploy, gasto publicitario ou mudanca operacional sem Decision
Receipt, Policy/Profile e approval quando houver risco real.

Simulacoes sobre pessoas/grupos devem evitar perfis sensiveis sem necessidade,
declarar assumptions e respeitar privacidade.

## Roadmap

| Fase | Entrega |
|---|---|
| SIM-0 | Spec canonica e avaliacao MiroFish |
| SIM-1 | Simulation Packet schema + outcome tracking plan |
| SIM-2 | Evidence Ledger events e calibration record |
| SIM-3 | Python prototype local com graph/persona/report sem AGPL copy |
| SIM-4 | Marketing/Strategic Decision adapter |
| SIM-5 | Real outcome capture + calibration dashboard |
| SIM-6 | Self-Improvement usando erros de simulacao |

## Definition Of Done

Uma simulacao Atlas esta pronta quando tem seed pack versionado, assumptions,
incerteza, evidence, plano de resultado real, comparacao com realidade,
calibration score, error analysis, melhoria revisavel e nenhuma acao real sem
approval.

## Resumo

Contrato alvo para implementar simulacoes multiagente inspiradas por MiroFish/OASIS como harness governado do Atlas, com calibracao contra resultado real.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
