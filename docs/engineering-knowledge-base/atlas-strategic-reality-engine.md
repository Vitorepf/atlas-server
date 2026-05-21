---
id: atlas-strategic-reality-engine
type: engineering_knowledge
title: Atlas Strategic Reality Engine
status: active
category: autonomous-intelligence
priority: 100
summary: Definicao canonica do ASRE, Atlas Strategic Reality Engine, tambem chamado internamente de Atlas Reality Command OS: a camada que modela a realidade do usuario e decide a melhor proxima acao com base em empresas, projetos, recursos, riscos, oportunidades, decisoes, contexto, outcomes e capacidades do Atlas.
implementation_state: local_runtime_active_with_hyperflow_sidecar_and_certification
macro_layer: true
product_name: Atlas Strategic Reality Engine
internal_product_name: Atlas Reality Command OS
runtime_acronym: ASRE
technical_runtime: AtlasStrategicRealityRuntimeService
tags:
  - atlas-ai
  - asre
  - strategic-reality
  - reality-command
  - world-model
  - strategic-decision
  - priority-engine
  - opportunity-radar
  - risk-radar
capabilities:
  - reality_graph
  - strategic_memory
  - opportunity_radar
  - risk_radar
  - priority_engine
  - resource_allocation
  - assumption_ledger
  - reality_freshness_gate
  - strategic_simulation
  - executive_briefing
  - next_best_action
decisions:
  - O nome canonico e Atlas Strategic Reality Engine; ASRE e o acronimo tecnico.
  - Atlas Reality Command OS e nome interno/produto para a experiencia operacional, nao um runtime separado.
  - ASRE responde a pergunta central: dado tudo que o Atlas sabe sobre a realidade do usuario, qual e a melhor proxima decisao ou acao?
  - ASRE nao e apenas um modulo de estrategia; e a camada que conecta memoria, contexto, outcomes, capabilities e dominios a realidade viva do operador.
  - ASRE fica acima de APCR, ACIE, ACOL, TEOS, AEMOR e ASEIF; ele usa essas camadas para decidir prioridade, nao as substitui.
  - ASRE nao executa acao externa diretamente; qualquer execucao passa por Mission Mode, Policy, approval, receipts, rollback e Control Plane.
  - Recomendacao estrategica relevante precisa carregar assumptions, evidence, freshness, tradeoffs, risks, next action e confidence.
maintenance:
  - Atualizar antes de implementar Reality Graph, Priority Engine, Opportunity Radar, Risk Radar, Strategic Simulation ou Executive Briefing.
  - Nao remover ASRE do Hyperflow sem manter certification, Control Plane, tests e claim policy.
  - Sincronizar com Atlas AI Evolution Lineage quando ASRE mudar o patamar de maturidade.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-evolution-lineage-and-target-state.md
  - docs/engineering-knowledge-base/atlas-ai-evolutionary-maturity-model.md
  - docs/engineering-knowledge-base/atlas-persistent-context-runtime.md
  - docs/engineering-knowledge-base/atlas-context-intelligence-engine.md
  - docs/engineering-knowledge-base/atlas-conversation-operations-layer.md
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system.md
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
  - docs/engineering-knowledge-base/atlas-aemor-judgment-learning-guard.md
  - docs/engineering-knowledge-base/atlas-intelligence-factory-os.md
  - docs/engineering-knowledge-base/atlas-world-model.md
  - docs/engineering-knowledge-base/atlas-domain-company-runtimes.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-strategic-reality-engine
graph_title: Atlas Strategic Reality Engine
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-autonomous-intelligence-operating-system
graph_status: active
graph_source: repo
human_name: Atlas Strategic Reality Engine
canonical_name: Atlas Strategic Reality Engine
technical_name: AtlasStrategicRealityRuntimeService
cartography_type: system
canonical_source: docs/engineering-knowledge-base/atlas-strategic-reality-engine.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-strategic-reality-engine.md
allowed_changes:
  - Criar contracts, migrations, services, commands, read models, Control Plane e tests para ASRE.
  - Refinar componentes e DoD quando APCR, AEMOR e Intelligence Factory gerarem mais dados reais.
forbidden_changes:
  - Declarar ASRE implementado por existir apenas esta doc.
  - Usar ASRE para executar acao externa sem governanca.
  - Tratar recomendacao estrategica como verdade sem assumptions e freshness.
  - Criar decision memory paralela se AEMOR/Evidence Ledger ja cobrem o caso.
depends_on:
  - atlas-autonomous-intelligence-operating-system
  - atlas-persistent-context-runtime
  - atlas-context-intelligence-engine
  - atlas-execution-memory-outcome-runtime
  - atlas-intelligence-factory-os
  - atlas-world-model
flows_to:
  - atlas_ai
  - atlas_dev
  - atlas_forge
  - atlas_research
  - atlas_finance
  - atlas_marketing
  - atlas_automation
  - atlas_autonomous_company_os
unlocks:
  - next_best_action_runtime
  - strategic_reality_control_plane
  - multi_domain_priority_engine
  - reality_aware_capability_evolution
governs:
  - atlas.strategic_decision
  - atlas.strategic_reality.decision.v1
  - atlas.next_best_action
  - atlas.reality_model
  - atlas.priority_policy
evidence:
  - docs/engineering-knowledge-base/atlas-strategic-reality-engine.md
  - app/Services/Ai/StrategicReality/AtlasStrategicRealityRuntimeService.php
  - app/Services/Ai/StrategicReality/AtlasStrategicRealityCertificationService.php
  - app/Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php
  - app/Services/Ai/ControlPlane/AtlasAiControlPlaneService.php
  - database/migrations/2026_05_20_150000_create_atlas_strategic_reality_tables.php
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test tests/Feature/Ai/StrategicReality"
  - "php artisan test tests/Feature/Ai/RouterRuntime/AtlasAiInteractionHyperflowEntryTest.php"
  - "php artisan atlas:strategic-reality:certify --json --strict"
requires_evidence: true
risk_level: critical
line_limit: 520
ai_entrypoints:
  - Leia esta doc antes de propor estrategia, prioridade macro, proxima melhor acao, portfolio de projetos, decisao de foco ou resource allocation.
  - Se o usuario perguntar o que deve fazer agora, o que vale priorizar, qual oportunidade seguir ou qual risco importa, ASRE e o destino canonico.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test tests/Feature/Ai/StrategicReality"
  - "php artisan test tests/Feature/Ai/RouterRuntime/AtlasAiInteractionHyperflowEntryTest.php"
  - "php artisan atlas:strategic-reality:certify --json --strict"
next_actions:
  - Usar outcomes reais do AEMOR para calibrar ranking estrategico.
  - Expandir Reality Graph com entidades de empresas, produtos, canais e investimentos reais.
  - Evoluir APCR, AEMOR e ASEIF como fontes iniciais do Reality Graph.
---
## Resumo

ASRE, Atlas Strategic Reality Engine, e a camada que torna o Atlas capaz de
responder a pergunta central:

```text
Dado tudo que o Atlas sabe sobre minha realidade, qual e a melhor proxima
decisao ou acao?
```

O nome interno/produto e **Atlas Reality Command OS**. Ele representa a
experiencia de comando estrategico. O runtime tecnico canonico e ASRE.

ASRE e um dos objetivos originais do Atlas: parar de responder apenas ao prompt
e passar a responder a realidade do usuario.

## Papel no Atlas

O papel do ASRE e conectar:

- contexto persistente;
- memoria de decisoes;
- outcomes reais;
- capacidades disponiveis;
- empresas e projetos;
- riscos e oportunidades;
- recursos limitados;
- timing;
- governanca;
- execucao.

Claude, Codex e outros providers respondem bem ao contexto enviado. ASRE deve
fazer o Atlas decidir qual contexto, qual objetivo e qual acao importam antes
de chamar qualquer provider ou flow.

## Onde Se Encaixa

Hierarquia operacional:

```text
APCR / ACIE / ACOL / TEOS
-> AEMOR
-> Atlas Intelligence Factory OS / ASEIF
-> ASRE
-> Mission Mode / Domain Runtimes / Dev / Forge / Research / Finance / Marketing
-> Governed execution
```

ASRE fica acima das camadas de memoria e aprendizado. Ele nao substitui essas
camadas; ele consome seus sinais.

## Contratos

### Contrato central

Toda recomendacao estrategica relevante deve produzir:

- `reality_scope`: qual parte da realidade foi considerada;
- `evidence_refs`: quais fontes sustentam a recomendacao;
- `assumptions`: hipoteses usadas;
- `freshness`: validade temporal dos dados;
- `options`: alternativas reais;
- `tradeoffs`: custo, risco e oportunidade;
- `recommended_action`: proxima acao;
- `why_now`: por que agora;
- `why_not`: o que nao fazer;
- `confidence`: confianca calibrada;
- `review_at`: quando revisar.
- `context_signals`: hashes/status de APCR, AEMOR, ASEIF e Context Operations
  usados como sinais internos sem vazar texto bruto.

### Claim policy

ASRE pode recomendar. ASRE nao pode declarar certeza absoluta. ASRE nao pode
executar acao externa sem passar por policy, approval e receipts.

## Fluxo

Fluxo canonico:

```text
user question / mission / scheduled review
-> identify reality scope
-> load Reality Graph
-> pull APCR context
-> pull AEMOR outcomes
-> pull ASEIF capabilities/gaps
-> check freshness
-> build assumptions
-> detect opportunities and risks
-> simulate options
-> rank priorities
-> emit strategic decision brief
-> create next action / mission / capability gap / blocker
-> record outcome after execution
```

## Regras para IA

1. Nao responda pergunta estrategica grande apenas com opiniao.
2. Sempre diferencie fato, inferencia, hipotese e preferencia.
3. Sempre declare dados antigos, ausentes ou fracos.
4. Nao trate oportunidade como prioridade sem resource allocation.
5. Nao trate risco como blocker sem severidade e mitigacao.
6. Nao criar capability nova se ASEIF ja registrou uma aplicavel.
7. Nao executar acao externa a partir de ASRE; ASRE recomenda, outro runtime
   executa com governanca.
8. Toda decisao importante volta para AEMOR depois do outcome.

## Escopo de Implementacao

### Componentes obrigatorios

1. **Reality Graph**: entidades vivas como empresas, projetos, produtos,
   pessoas, ativos, canais, documentos, metas, decisoes e constraints.
2. **Strategic Memory**: decisoes, hipoteses, rationale e resultados.
3. **Opportunity Radar**: oportunidades por mercado, produto, conteudo,
   automacao, investimento, parceria e melhoria interna.
4. **Risk Radar**: riscos tecnicos, financeiros, estrategicos, legais,
   operacionais, reputacionais e de foco.
5. **Priority Engine**: ranking por ROI, risco, timing, alavancagem,
   dependencias, custo e reversibilidade.
6. **Resource Allocation Engine**: tempo, atencao, dinheiro, agentes,
   providers, pesquisa, capabilities e execucao externa.
7. **Assumption Ledger**: hipoteses, validade, invalidadores e revisao.
8. **Reality Freshness Gate**: bloqueio ou warning quando dado critico esta
   velho, ausente ou superseded.
9. **Strategic Simulation Runtime**: compara cenarios e consequencias.
10. **Executive Briefing Generator**: gera brief acionavel, curto e auditavel.
11. **Decision-to-Action Bridge**: cria mission, work order, capability gap ou
   approval request.
12. **Strategic Control Plane**: mostra prioridades, risks, opportunities,
   assumptions, blockers, outcomes e revisoes pendentes.

### Persistencia esperada

Persistencia local inicial:

- `atlas_reality_entities`
- `atlas_reality_relationships`
- `atlas_strategic_decisions`
- `atlas_strategic_assumptions`
- `atlas_opportunity_signals`
- `atlas_risk_signals`
- `atlas_priority_rankings`
- `atlas_resource_allocation_plans`
- `atlas_strategic_simulations`
- `atlas_executive_briefings`

### Commands esperados

```text
php artisan atlas:strategic-reality scan --question="..." --json
php artisan atlas:strategic-reality decide --question="..." --json
php artisan atlas:strategic-reality:control-plane --json
php artisan atlas:strategic-reality:certify --json --strict
```

## Dependencias

| Sistema | Uso pelo ASRE |
| --- | --- |
| APCR | contexto persistente e must-know ledger |
| ACIE | context pack e retrieval correto |
| ACOL | handoff e higiene de conversa |
| TEOS | continuidade temporal e freshness |
| AEMOR | outcomes, negative knowledge e reliability |
| ASEIF | capabilities, gaps e evolution candidates |
| World Model | entidades, relacoes e estado do mundo/codigo |
| Evidence Ledger | prova, receipts e hashes |
| Mission Mode | transformar decisao em execucao governada |
| Control Plane | visibilidade operacional |

## Evidencias

ASRE esta `active` no runtime local quando estes requisitos seguem verdes:

- doc canonica valida;
- contracts versionados;
- migrations/models;
- runtime service;
- commands/API;
- Control Plane;
- tests unitarios e feature;
- readiness/certification;
- pelo menos um scenario real:
  - pergunta de foco semanal;
  - analise de oportunidade;
  - priorizacao de projeto;
  - risco estrategico;
  - resource allocation;
- output com evidence_refs, assumptions, freshness e next action;
- feedback de outcome enviado para AEMOR.

Estado atual validado:

- runtime service `AtlasStrategicRealityRuntimeService`;
- 10 tabelas de persistencia ASRE;
- comandos `scan`, `decide`, `control-plane` e `certify`;
- Control Plane agregado no Atlas AI;
- Hyperflow sidecar para strategy, finance, marketing, personal development e automation;
- context signals sanitizados vindos de APCR, AEMOR, ASEIF e Context Operations;
- financeiro/acao externa bloqueia execucao e exige approval/Mission Mode;
- certificacao strict com 11 checks;
- smoke de certificacao roda em transacao rollback-only para nao poluir o Control Plane.

## Riscos

| Risco | Mitigacao |
| --- | --- |
| Virar opiniao bonita | Exigir evidence, assumptions e freshness |
| Confundir estrategia com execucao | ASRE recomenda; Mission/Domain executa |
| Dado velho orientar decisao | Reality Freshness Gate |
| Prioridade emocional | Priority Engine com criteria explicitos |
| Overengineering de grafo | Comecar com entidades criticas e edges simples |
| False learning | AEMOR Judgment Guard |
| Capability errada virar padrao | ASEIF certification |
| Acao externa perigosa | Approval, mandate, rollback, kill switch |

## Exemplos

Pergunta:

```text
Atlas, qual e a melhor coisa para eu focar esta semana?
```

Resposta ASRE esperada:

```text
Recomendacao: fechar o fluxo X antes de abrir Y.
Por que agora: X desbloqueia tres capabilities e reduz risco de retrabalho.
Nao fazer agora: iniciar Y, porque depende de X e aumenta WIP.
Evidencia: projeto A atrasado, capability B pronta, risco C baixo.
Assumptions: semana com 20h disponiveis; sem mudanca no objetivo D.
Freshness: dados de projeto atualizados hoje; mercado precisa nova leitura.
Proxima acao: criar mission de 3 ciclos para X com review no fim.
```

## Proximas Acoes

Ordem recomendada depois do runtime ativo:

1. Alimentar Reality Graph com dados reais de empresas, projetos, ativos,
   canais e metas.
2. Conectar outcomes reais do AEMOR para calibrar ranking e negative
   knowledge.
3. Conectar ASEIF para detectar capability gaps a partir de decisoes ASRE.
4. Criar Decision-to-Action Bridge governado para abrir Mission Mode quando
   a recomendacao virar execucao.
5. Criar revisao periodica estrategica com freshness, assumptions e outcomes.

Meta final:

```text
Atlas deve ser capaz de olhar para a realidade persistente do operador e dizer,
com evidencia, assumptions e risco calibrado, qual e a melhor proxima decisao
ou acao.
```
