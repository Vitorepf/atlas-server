---
id: atlas-ai-finance-domain
type: engineering_knowledge
title: Atlas AI Finance Domain
status: active
category: architecture
priority: 96
summary: Spec canonica do dominio implemented/ready Finance para pesquisa, risco, portfolio, tese, macro, earnings, noticias, compliance, backtest e forge review-only.
tags:
  - atlas-ai
  - domains
  - finance
  - compliance
  - review-only
capabilities:
  - finance_domain
  - finance_compliance_review
  - portfolio_analysis
  - market_research
decisions:
  - Finance e dominio implemented/ready, mas sempre analysis/review-only.
  - Finance nunca executa, prepara ou sugere payload executavel de ordem de mercado.
  - Qualquer pedido de execucao financeira deve ser bloqueado por compliance gate.
maintenance:
  - Atualize este documento quando flows, gates, runtime, profile factory ou safety policy de Finance mudarem.
  - Leia junto de atlas-ai-master-architecture.md e atlas-ai-kernel-architecture.md antes de alterar runtime Finance.
related_paths:
  - app/Services/Ai/Finance/AtlasFinanceComplianceGate.php
  - app/Services/Ai/Finance/AtlasFinanceDomainContract.php
  - app/Services/Ai/Finance/AtlasFinanceProfileFactory.php
  - app/Services/Ai/Finance/AtlasFinanceOrchestrator.php
  - app/Services/Ai/Finance/AtlasFinanceRuntime.php
  - app/Services/Ai/Finance/AtlasFinanceSafetyPolicy.php
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-finance-domain

graph_title: Atlas AI Finance Domain

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Finance Domain
canonical_name: Atlas AI Finance Domain
technical_name: atlas-ai-finance-domain
cartography_type: module
canonical_source: docs/engineering-knowledge-base/domains/finance.md

owner: domains

repo_paths:
  - docs/engineering-knowledge-base/domains/finance.md

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
  - domains

evidence:
  - docs/engineering-knowledge-base/domains/finance.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - domains

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
  - Finance Company Runtime entregue 2026-05-18 via app/Services/Ai/Finance/Kernel/ + comando atlas:ai:finance-domain --action=readiness|smoke|control-plane|enterprise-analysis. Live trading permanece hard-blocked.
---
# Atlas AI Finance Domain

## Charter

Finance is an Atlas AI enterprise domain for financial research, risk review, portfolio analysis, thesis development, macro and issuer review, news impact review, compliance checks, and backtest planning.

The domain is analysis/review only. It can produce auditable research packets, risk notes, assumptions, scenario outlines, and review plans. It must never place, modify, cancel, or prepare executable market orders.

The local source of truth for the isolated implementation is `App\Services\Ai\Finance\AtlasFinanceDomainContract`. The orchestrator, runtime, tests, and Finance profile migration all derive flow definitions, gates, context sources, memory policy, tool policy, and forbidden actions from that contract.

Profile generation lives in `App\Services\Ai\Finance\AtlasFinanceProfileFactory`. The migration uses this factory so database profiles and the integration-facing domain contract stay aligned.

Runtime compliance is enforced by `App\Services\Ai\Finance\AtlasFinanceComplianceGate`. The runtime evaluates this gate even when a caller bypasses the orchestrator and passes a raw plan directly.

## Flows

- `finance.market_research`: market and instrument research with source attribution and assumptions.
- `finance.risk_review`: exposure, scenario, downside, liquidity, and limit review.
- `finance.portfolio_analysis`: allocation, concentration, diversification, and suitability review.
- `finance.trade_thesis`: thesis, countercase, invalidation, and monitoring plan review.
- `finance.macro_review`: macro indicators, calendar, policy, rates, inflation, and scenario review.
- `finance.earnings_review`: issuer materials, consensus, guidance, and event-risk review.
- `finance.news_impact`: source quality, impact window, uncertainty, and second-order effects review.
- `finance.compliance_review`: jurisdiction, policy constraints, restricted action scan, and disclosure review.
- `finance.backtest_plan`: hypothesis, dataset, bias controls, and methodology review.
- `finance.forge`: multi-flow finance review packet. It requires explicit human approval and still cannot execute market actions.

## Context Sources

- Operator-supplied financial context
- Market data snapshots
- Issuer filings and earnings materials
- Portfolio snapshots supplied by the operator or approved read-only systems
- Risk policy and compliance policy
- Macroeconomic calendar and policy calendar
- News source packs
- Backtest dataset manifests

## Memory Policy

Finance memory uses the `finance` projection with provider-safe defaults and usage recording.

Allowed learning inputs are reviewed research notes, reviewed risk findings, and validated backtest methodology. Portfolio preferences, risk limits, compliance rules, and watchlists require human review before promotion.

Finance memory must never store broker credentials, account secrets, or unredacted personal financial data. Memory may support later review, but it must never trigger automatic execution.

## Gates

Required gates for every Finance flow:

- `finance_compliance_review`
- `source_attribution`
- `risk_disclosure`
- `analysis_review_only`

Additional gates are flow-specific, such as `risk_limits`, `portfolio_suitability_review`, `thesis_countercase`, `issuer_specific_risk`, `news_source_quality`, `regulatory_scope`, `methodology_review`, `operator_approval`, and `forge_scope_review`.

If an operator request includes a forbidden market action, the runtime records `GATE_BLOCKED` with `market_execution_forbidden`, returns `blocked_for_market_execution_request`, and still emits no broker instructions, trade order payload, or executable market action.

Natural-language execution intent is classified by `App\Services\Ai\Finance\AtlasFinanceSafetyPolicy`. It catches canonical action IDs and common English/Portuguese execution requests such as buy, sell, rebalance, broker connection, cash transfer, and option exercise language.

Malformed plans that bypass the orchestrator are blocked with `blocked_for_finance_compliance` when they enable market execution, use any output mode other than `analysis_review_only`, omit required global gates, or disable the Finance compliance gate.

## Autonomy Model

Default autonomy is low. Background execution is disabled. Finance output is review-only even when a user asks for a trade thesis or forge packet.

`finance.forge` requires human approval before any downstream integration can consume the packet. Approval does not grant market execution inside this domain; it only authorizes review packet progression in integrated review surfaces that keep Finance analysis-only.

## Forbidden Actions

Finance domain code and profiles forbid:

- Placing orders
- Modifying orders
- Canceling orders
- Rebalancing accounts
- Transferring cash
- Exercising options
- Connecting broker APIs for execution
- Publishing personalized investment advice as an automatic recommendation
- Emitting broker instructions or executable order payloads

## Integration Status

Finance is now centrally registered as a first-class Atlas AI domain.

- `config/atlas_ai.php` maps `AtlasFinanceOrchestrator` to `App\Services\Ai\Finance\AtlasFinanceOrchestrator`.
- `AtlasDomainProfileRegistry` exposes all 10 Finance flows in static fallback mode.
- `database/migrations/2026_05_05_080000_expand_finance_domain_contract.php` seeds the active database profiles and retires the old `finance.research` bootstrap flow.
- `atlas:ai:domains --json` reports Finance as `ready 9/9` with 10 flows.
- `atlas:ai:architecture-validate --json` includes Finance in the ready domain count.
- Finance Company Runtime upgrade (2026-05-18) registers manifest `finance` no Domain Runtime com 7 capabilities (research_desk, valuation, portfolio_review, risk_review, compliance, reporting, paper_trading_simulation) sob `app/Services/Ai/Finance/Kernel/`. Bridges seguros para Mission/Domain Runtime/Policy/Evidence; live trading hard-blocked por FinanceDomainCanon::liveTradingBlocked() e FinanceComplianceService::assertNotLiveTrade.
- Comando `atlas:ai:finance-domain --action=readiness|smoke|control-plane` valida invariantes (live_trading_blocked_default=true, broker_execution_allowed=false, auto_rebalance_allowed=false) e roda smoke E2E research→valuation→portfolio→risk→compliance→paper-trading→reporting.
- Comando `atlas:ai:finance-domain --action=enterprise-analysis --json` entrega o pacote institucional inspirado no padrao Claude for Financial Services: interface unificada de dados, conectores read-only/governados, source links, audit trail de modelos, due diligence de data room, portfolio monitoring, compliance automation e investment committee memo. Continua sem live trading, broker execution, auto rebalance ou money movement.

Implemented files:

- `app/Services/Ai/Finance/AtlasFinanceComplianceGate.php`
- `app/Services/Ai/Finance/AtlasFinanceDomainContract.php`
- `app/Services/Ai/Finance/AtlasFinanceProfileFactory.php`
- `app/Services/Ai/Finance/AtlasFinanceReviewRequest.php`
- `app/Services/Ai/Finance/AtlasFinanceOrchestrator.php`
- `app/Services/Ai/Finance/AtlasFinanceRuntime.php`
- `app/Services/Ai/Finance/AtlasFinanceSafetyPolicy.php`
- `app/Services/Ai/Finance/Kernel/FinanceEnterpriseAnalysisService.php`
- `database/migrations/2026_05_05_080000_expand_finance_domain_contract.php`

Validation:

- `php artisan test tests/Unit/Ai/Finance tests/Feature/Ai/Finance`
- `php artisan test tests/Feature/Ai/FinanceDomain/FinanceEnterpriseAnalysisTest.php`
- `php artisan test tests/Feature/Architecture/DomainProfileComplianceTest.php tests/Feature/Ai/AtlasAiDomainsCommandTest.php`
- `php artisan atlas:ai:domains --json`
- `php artisan atlas:ai:architecture-validate --json`

## Resumo

Spec canonica do dominio implemented/ready Finance para pesquisa, risco, portfolio, tese, macro, earnings, noticias, compliance, backtest e forge review-only.

## Papel no Atlas

Finance isola pesquisa e analise financeira dentro de um dominio review-only. Ele permite research packets, risk notes, thesis review, compliance review e backtest planning sem abrir caminho para ordem de mercado, broker API, rebalanceamento automatico ou recomendacao personalizada executavel.

## Onde Se Encaixa

Finance fica em `domains`, registrado por `AtlasDomainProfileRegistry` e executado por `AtlasFinanceOrchestrator`/`AtlasFinanceRuntime`. O contrato fonte local e `AtlasFinanceDomainContract`; compliance e safety vivem em `AtlasFinanceComplianceGate` e `AtlasFinanceSafetyPolicy`.

## Contratos

Entradas aceitas sao contexto financeiro fornecido pelo operador, snapshots read-only, filings, portfolio snapshots aprovados, policy de risco/compliance, calendario macro e manifests de dataset. Saidas aceitas sao analysis/review packets, risk notes, assumptions, scenario outlines e review plans. Saidas proibidas incluem order payloads, broker instructions, money movement, auto rebalance e personalized investment advice automatico.

## Fluxo

Pedido entra em um dos 10 flows Finance, passa por contract/profile, safety policy, compliance gate e runtime review-only. Se houver intencao de execucao de mercado, o runtime bloqueia com `market_execution_forbidden` e nao emite payload executavel.

## Regras para IA

IA deve reutilizar `AtlasFinanceDomainContract`, `AtlasFinanceRuntime` e `AtlasFinanceComplianceGate` antes de criar qualquer novo fluxo Finance. Nunca implemente live trading, broker execution, money movement ou auto rebalance dentro deste dominio. Qualquer extensao deve manter `analysis_review_only`.

## Escopo de Implementacao

Escopo ativo: 10 flows Finance, compliance gate, safety policy, runtime review-only, profile factory, enterprise-analysis package e Finance Company Runtime em `app/Services/Ai/Finance/Kernel/`. Fora do escopo: execucao real de trades, integracao mutativa com broker, custodia, money movement e recomendacao personalizada automatica.

## Dependencias

Depende de `AtlasFinanceDomainContract`, `AtlasFinanceProfileFactory`, `AtlasFinanceOrchestrator`, `AtlasFinanceRuntime`, `AtlasFinanceComplianceGate`, `AtlasFinanceSafetyPolicy`, Finance Kernel, domain registry e migrations de profile.

## Evidencias

Evidencias principais: `AtlasFinanceRuntime.php` reachable/high, testes `tests/Feature/Ai/Finance/AtlasFinanceRuntimeTest.php` e `tests/Unit/Ai/Finance/AtlasFinanceOrchestratorTest.php`, comandos `atlas:ai:finance-domain`, migration `2026_05_05_080000_expand_finance_domain_contract.php` e `atlas:ai:domains --json`.

## Riscos

Risco principal e uma IA confundir review financeiro com execucao financeira. Outros riscos: guardar dado financeiro sensivel em memoria, tratar paper-trading simulation como broker execution, ou criar flow paralelo fora do contract/compliance gate.

## Exemplos

Permitido: "analise risco de concentracao deste portfolio" ou "monte tese e countercase para este issuer". Bloqueado: "compre 10 acoes", "rebalanceie minha conta", "conecte na corretora" ou "transfira caixa".

## Proximas Acoes

Manter Finance ativo apenas como analysis/review-only, ampliar coverage de enterprise-analysis quando novos flows forem adicionados e rodar `php artisan atlas:ai:finance-domain --action=readiness --json` junto de testes Finance antes de alterar gates, profile ou Kernel.
