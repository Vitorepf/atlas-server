---
id: atlas-venture-foundry-operating-system
type: engineering_knowledge
title: Atlas Venture Foundry Operating System
status: active
category: architecture
priority: 88
summary: Setor canonico de criacao e gestao de empresas - ideacao governada, registro duravel de ventures, regras de negocio versionadas, metricas observadas e estrategista de crescimento ate o alvo de 100M USD ARR.
tags:
  - atlas-ai
  - venture-foundry
  - strategy
  - business-creation
capabilities:
  - venture_ideation
  - governed_generative_ideation
  - venture_registry
  - business_rule_canon
  - growth_ladder_evaluation
  - strategist_review
  - growth_trajectory_projection
  - strategist_qualitative_analysis
  - weekly_review_cadence
  - research_domain_handoff
  - execution_bridge_draft_missions
  - workspace_comprehension
  - business_rule_mining
  - problem_map
  - improvement_scanner
  - audience_usage_profile
  - documentation_generation
decisions:
  - O Venture Foundry e a camada de criacao e gestao de empresas DO OPERADOR; ele consome o Strategy Domain (Venture Studio) e nao o substitui.
  - Estagio de venture nunca e auto-declarado; toda recomendacao vem de gates avaliados sobre registros persistidos (artefatos ligados, regras ativas, metricas observadas).
  - Recomendacao e promocao sao atos separados; o estagio so muda com strategist-review --apply explicito.
  - Geracao de ideias por provider existe SOMENTE como batch governado (ideate-generate) com schema fail-closed e cite-or-omit para market size; nunca inline, nunca auto-promove.
  - Projecao de trajetoria e deterministica e fica BLOCKED sem ARR observado; nenhuma claim financeira sintetica.
  - A ponte de execucao cria missoes DRAFT com autonomy=suggest (o operador promove rascunho para trabalho planejado); idempotente por review e capped.
  - O parecer estrategico qualitativo (LLM) e grounded fail-closed; movimento estrategico que nao cita fact ids do estado persistido e DESCARTADO; falha do provider nunca bloqueia a review deterministica.
  - Cadencia semanal roda segunda 06:30 (domingo continua exclusivo do digest); deterministica por default, analise LLM no ciclo so com cycle_analyze ligado.
maintenance:
  - Atualizar quando a escada de crescimento, gates ou categorias de regra mudarem junto com codigo e testes.
  - Manter abaixo de 260 linhas.
related_paths:
  - app/Services/Ai/VentureFoundry/VentureIdeationService.php
  - app/Services/Ai/VentureFoundry/VentureIdeaGenerationService.php
  - app/Services/Ai/VentureFoundry/VentureRegistryService.php
  - app/Services/Ai/VentureFoundry/VentureBusinessRuleService.php
  - app/Services/Ai/VentureFoundry/VentureGrowthLadderService.php
  - app/Services/Ai/VentureFoundry/VentureStrategistService.php
  - app/Services/Ai/VentureFoundry/VentureTrajectoryService.php
  - app/Services/Ai/VentureFoundry/VentureStrategistAnalysisService.php
  - app/Services/Ai/VentureFoundry/Comprehension/VentureComprehensionService.php
  - app/Services/Ai/VentureFoundry/Comprehension/WorkspaceReader.php
  - app/Services/Ai/VentureFoundry/Comprehension/Capabilities/VentureBusinessRuleMinerService.php
  - app/Services/Ai/VentureFoundry/Comprehension/Capabilities/VentureProblemMapService.php
  - app/Services/Ai/VentureFoundry/Comprehension/Capabilities/VentureImprovementScannerService.php
  - app/Services/Ai/VentureFoundry/Comprehension/Capabilities/VentureAudienceUsageProfileService.php
  - app/Services/Ai/VentureFoundry/Comprehension/Capabilities/VentureDocumentationGeneratorService.php
  - app/Services/Ai/VentureFoundry/VentureResearchHandoffService.php
  - app/Services/Ai/VentureFoundry/VentureExecutionBridgeService.php
  - app/Console/Commands/AtlasVentureFoundryCommand.php
  - config/atlas_venture_foundry.php
  - tests/Feature/Ai/VentureFoundry/VentureGrowthLadderServiceTest.php
  - docs/engineering-knowledge-base/atlas-ai-autonomous-holding-operating-system.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-venture-foundry-operating-system
graph_title: Atlas Venture Foundry Operating System
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-canonical-architecture-index
graph_status: active
graph_source: repo
human_name: Atlas Venture Foundry Operating System
canonical_name: Atlas Venture Foundry Operating System
technical_name: VentureGrowthLadderService
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-venture-foundry-operating-system.md
owner: venture-foundry
repo_paths:
  - docs/engineering-knowledge-base/atlas-venture-foundry-operating-system.md
allowed_changes:
  - Evoluir gates, playbooks e categorias quando codigo e testes mudarem juntos.
forbidden_changes:
  - Promover estagio de venture sem gate verde sobre dados persistidos.
  - Criar rota de ideacao que invente conteudo sem fonte (operador ou radar).
depends_on:
  - atlas-ai-knowledge-governance-system
  - atlas-ai-core-vs-domain
flows_to:
  - atlas-ai-autonomous-holding-operating-system
unlocks:
  - venture-foundry-runtime
governs:
  - venture-foundry
evidence:
  - app/Services/Ai/VentureFoundry/VentureGrowthLadderService.php
  - tests/Feature/Ai/VentureFoundry/VentureGrowthLadderServiceTest.php
evidence_refs:
  - symbol: VentureGrowthLadderService
  - command: atlas:venture
  - test: VentureGrowthLadderServiceTest
required_tests:
  - "php artisan test tests/Feature/Ai/VentureFoundry"
requires_evidence: true
risk_level: medium
ai_entrypoints:
  - Leia Contratos e Regras para IA antes de criar, promover ou revisar qualquer venture.
ai_usage_notes:
  - Use `php artisan atlas:venture status --json` para o estado do setor.
  - Use `php artisan atlas:venture strategist-review --venture=<id> --json` para a revisao estrategica; adicione `--apply` apenas com gates verdes.
quality_gates:
  - "php artisan test tests/Feature/Ai/VentureFoundry"
failure_modes:
  - Confundir recomendacao de estagio com estagio aplicado.
  - Registrar metrica sintetica para forcar gate de receita.
observability_signals:
  - ventures_by_stage no atlas:venture status
  - gaps e next_actions por strategist review
next_actions:
  - Operar a primeira venture real do operador pelo ciclo completo.
---
# Atlas Venture Foundry Operating System

## Resumo

O Venture Foundry e o setor do Atlas responsavel por **criar e gerir empresas
do operador**: gera e ranqueia ideias, promove ideia a venture duravel, mantem
o canon de regras de negocio versionadas, observa metricas reais e atua como
estrategista permanente ate o alvo declarado de **100M USD ARR**.

## Papel no Atlas

A Autonomous Holding governa os Domain Company Runtimes internos do Atlas.
O Venture Foundry e diferente: ele cria e gere empresas EXTERNAS do operador
como entidades duraveis (`ai_ventures`), reutilizando os primitivos do
Strategy Domain (opportunity radar, venture blueprint, experimentos, memos)
sem duplica-los.

## Onde Se Encaixa

Camada de dominio acima do Strategy Domain (Venture Studio). Consome
`OpportunityRadarService`, `VentureBlueprintService` e `StrategyMemoService`;
entrega registro de empresas, regras de negocio, metricas e revisao
estrategica recorrente.

## Contratos

- `atlas.ai.venture.idea.v1` - ideia com problema/ICP/dor e score deterministico
  auditavel (pain 30, urgency 15, market 25, founder_fit 15, sovereignty_fit 15).
- `atlas.ai.venture.v1` - empresa duravel com estagio, tese, north star e alvo
  de ARR (default 100M USD).
- `atlas.ai.venture.business_rule.v1` - regra de negocio versionada por
  categoria (product, pricing, customer, operations, finance, legal, brand,
  people, risk); re-declarar o mesmo rule_id cria nova versao e marca a
  anterior como superseded.
- `atlas.ai.venture.metric_observation.v1` - observacao de metrica com fonte e
  timestamp; ultima observacao por chave alimenta os gates.
- `atlas.ai.venture.strategy_review.v1` - revisao do estrategista com gates,
  gaps, next actions, playbook, trajetoria e memo do Strategy Domain ligado.
- `atlas.ai.venture.idea_generation_run.v1` - lote de ideacao gerativa
  governada: provider via AiProviderManager, schema fail-closed
  (AtlasStructuredOutputValidator), market size cite-or-omit (numero sem
  premissa declarada e descartado), clamp estrutural 0-5, cap configurado,
  dedupe e drops reportados (nunca truncamento silencioso).
- `atlas.ai.venture.trajectory.v1` - projecao deterministica ate o alvo:
  cenarios de crescimento anual (anos ate o alvo) + CAGR exigido por
  horizonte; BLOCKED sem ARR observado.
- `atlas.ai.venture.research_handoff.v1` - handoff governado strategy->research
  (permitido pelo manifest) com perguntas deterministicas derivadas da venture
  e dos gaps; research responde sob seus proprios gates de qualidade.
- `atlas.ai.venture.execution_bridge.v1` - gaps do review viram missoes DRAFT
  com autonomy=suggest e proactive_origin; idempotente por review
  (bridged_mission_ids), capped, nunca auto-executa.
- `atlas.ai.venture.strategist_analysis.v1` - parecer qualitativo do
  estrategista: fact sheet numerada (F1..Fn) construida so de registros
  persistidos -> provider -> trava de grounding (movimento sem fact id valido
  e descartado e reportado), confidence clamped, degrade honesto
  (provider_failed | invalid_structured_output | all_moves_ungrounded).
- `atlas.ai.venture.review_cycle.v1` - cadencia semanal: revisa toda venture
  ativa cujo ultimo review e mais velho que cycle_min_interval_days; nunca
  aplica estagio; reporta reviewed/skipped.
- `atlas.ai.venture.comprehension_report.v1` - o degrau 0: compreensao total do
  workspace real da venture. `atlas:venture comprehend --venture=<id>` roda 5
  capacidades deterministicas-first sobre o repo via `WorkspaceReader` (leitor
  read-only, scoped, exclui vendor/node_modules, cita path+linha, memory-safe em
  repo grande): (1) business_rule mining (precos, trial, comissao, validacao,
  authz, constantes - numero usado como threshold/multiplicador NUNCA vira preco);
  (2) problem map (secret/dangerous_code/debt/debug/swallowed_error/large_file/
  test_gap, severidade-ranked - secret so dispara em token inequivoco OU literal
  de alta entropia fora de teste/template, nao em mensagem de validacao);
  (3) improvement scanner (leverage = impacto/esforco); (4) audience_usage
  (planos/locales/integracoes/entidades de schema + blind spots HONESTOS do que o
  codigo nao revela); (5) documentation generator (doc canonico da empresa com
  frontmatter de cartografia, fecha docs_status incomplete). `--promote-rules`
  promove regras minadas de alta confianca (>=0.8) ao canon oficial versionado;
  `--write-docs=<dir>` escreve o doc gerado em disco (nunca no repo alvo por
  default).

## Escada de Crescimento (S0 -> S5)

| Estagio | Nome | Gates de entrada |
|---|---|---|
| S0 | Ideacao | venture criada a partir de ideia promovida |
| S1 | Validacao | oportunidade ligada + >=3 regras de negocio ativas |
| S2 | Primeira Receita | blueprint ligado + north star definida + ARR > 0 observado |
| S3 | Tracao | ARR >= 1M USD observado |
| S4 | Escala | ARR >= 10M USD + LTV/CAC >= 3 observados |
| S5 | Categoria | ARR >= 100M USD observado |

A avaliacao quebra a corrente no primeiro estagio reprovado: ARR alto sem
estrutura (oportunidade, regras, blueprint) nao pula etapas.

## Fluxo

1. `atlas:venture idea-register`, `ideate-from-radar` (deriva do radar,
   idempotente) ou `ideate-generate --brief="..."` (LLM governado, batch).
2. `atlas:venture promote --idea=...` cria a venture em S0.
3. `atlas:venture rule-add` constroi o canon de regras de negocio.
4. `atlas:venture link` liga oportunidade, blueprint e north star.
5. `atlas:venture metric-record` registra metricas reais.
6. `atlas:venture strategist-review [--apply] [--analyze]` avalia gates,
   projeta a trajetoria, opcionalmente adiciona o parecer qualitativo grounded
   (`--analyze` = gasto explicito), grava review + memo e (somente com
   `--apply`) move o estagio recomendado.
6b. `atlas:venture review-cycle` roda a cadencia (agendada toda segunda 06:30,
   gated por weekly_review_enabled).
7. `atlas:venture research-handoff --venture=...` emite handoff
   strategy->research com as perguntas de mercado em aberto.
8. `atlas:venture bridge-execution --venture=...` transforma os gaps do ultimo
   review em missoes rascunho para o operador ativar.

## Regras para IA

Nao declarar estagio sem gate verde sobre dado persistido. Nao registrar
metrica sem fonte real. Nao criar venture sem ideia registrada. Recomendacao
do estrategista nao e promocao: `--apply` e ato explicito. Ideia gerada por
provider entra sempre como proposed/source=generated com premissas citadas e
NUNCA e promovida automaticamente. Missao criada pela ponte de execucao nasce
draft/suggest e so o operador a ativa. Projecao de trajetoria nunca substitui
ARR observado.

## Escopo de Implementacao

Permitido: evoluir gates, playbooks, categorias e metricas com testes juntos.
Proibido: executar acao externa (venda, marketing, gasto) a partir deste
setor; isso pertence aos runtimes governados com operator mandate.

## Dependencias

Strategy Domain (radar, blueprint, memos), Knowledge Governance e, para acao
externa futura, Evidence Ledger + operator mandates da Autonomous Holding.

## Evidencias

`php artisan test tests/Feature/Ai/VentureFoundry` (26 testes),
`php artisan atlas:venture status --json` live e `schedule:list` mostrando o
ciclo semanal de segunda 06:30.

## Riscos

Principal risco e teatro de progresso: estagio declarado sem receita
observada. O desenho bloqueia isso com gates metric-driven e separacao
recomendacao/promocao.

## Exemplos

Ideia "SaaS de auditoria continua" registrada, promovida a venture, 3 regras
ativas + oportunidade ligada -> review recomenda S1; sem blueprint e ARR,
S2 fica bloqueado com gaps explicitos.

## Proximas Acoes

Operar a primeira venture real do operador; depois avaliar ideacao assistida
por provider via conductor governado (AP dedicado).
