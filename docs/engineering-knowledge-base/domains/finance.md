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
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
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

Finance memory must never store broker credentials, account secrets, or unredacted personal financial data. Memory may support future analysis, but it must never trigger automatic execution.

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

`finance.forge` requires human approval before any downstream integration can consume the packet. Approval does not grant market execution inside this domain; it only authorizes review packet progression in a future integrated surface.

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
- `database/migrations/2026_05_05_080000_expand_finance_domain_contract.php` seeds the active database profiles and removes the legacy `finance.research` scaffold flow.
- `atlas:ai:domains --json` reports Finance as `ready 9/9` with 10 flows.
- `atlas:ai:architecture-validate --json` includes Finance in the ready domain count.

Implemented files:

- `app/Services/Ai/Finance/AtlasFinanceComplianceGate.php`
- `app/Services/Ai/Finance/AtlasFinanceDomainContract.php`
- `app/Services/Ai/Finance/AtlasFinanceProfileFactory.php`
- `app/Services/Ai/Finance/AtlasFinanceReviewRequest.php`
- `app/Services/Ai/Finance/AtlasFinanceOrchestrator.php`
- `app/Services/Ai/Finance/AtlasFinanceRuntime.php`
- `app/Services/Ai/Finance/AtlasFinanceSafetyPolicy.php`
- `database/migrations/2026_05_05_080000_expand_finance_domain_contract.php`

Validation:

- `php artisan test tests/Unit/Ai/Finance tests/Feature/Ai/Finance`
- `php artisan test tests/Feature/Architecture/DomainProfileComplianceTest.php tests/Feature/Ai/AtlasAiDomainsCommandTest.php`
- `php artisan atlas:ai:domains --json`
- `php artisan atlas:ai:architecture-validate --json`

## Resumo

Spec canonica do dominio implemented/ready Finance para pesquisa, risco, portfolio, tese, macro, earnings, noticias, compliance, backtest e forge review-only.

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
