---
id: atlas-strategic-decision-domain
title: Atlas Strategic Decision Domain
doc_schema: atlas_canonical_module_doc.v1
type: domain
status: active
category: domain
priority: 90
summary: Dominio que governa decisoes de rota, risco, prioridade e tradeoff antes de execucao Atlas.
canonical_name: Atlas Strategic Decision Domain
technical_acronym: ASD
experience_surface: Atlas Strategic Review
owner: atlas-ai
source_of_truth: docs/engineering-knowledge-base/domains/strategic_decision.md
tags:
  - atlas
  - strategic-decision
  - domain
  - routing
capabilities:
  - route_decision
  - risk_decision
  - provider_gate
  - dev_forge_escalation
decisions:
  - strategic_decision governa decisao antes de execucao quando ha risco, rota, prioridade ou tradeoff.
  - Dev e Forge sao propostas diferentes e a decisao entre eles deve ser explicita.
maintenance:
  - Atualizar quando routing, runtime gate, product delivery ou strategic decision mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Services/Ai/Product
  - app/Services/Ai/StrategicDecision
  - docs/engineering-knowledge-base/atlas-execution-doctrine-product-delivery-system.md
graph_id: atlas-strategic-decision-domain
graph_title: Atlas Strategic Decision Domain
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-canonical-architecture-index
graph_status: active
graph_source: repo
repo_paths:
  - docs/engineering-knowledge-base/domains/strategic_decision.md
allowed_changes:
  - Atualizar regras de decisao, rotas e gates quando codigo e testes mudarem juntos.
forbidden_changes:
  - Executar provider antes de runtime gate.
  - Criar rota paralela que burle Product Truth, Decision Receipt ou AEDPDS.
depends_on:
  - atlas-execution-doctrine-product-delivery-system
  - atlas-canonical-glossary-and-naming
flows_to:
  - programming.dev
  - programming.forge
unlocks:
  - strategic_decision.review
governs:
  - atlas-ai
  - atlas-dev
  - atlas-forge
evidence:
  - docs/engineering-knowledge-base/domains/strategic_decision.md
required_tests:
  - "php artisan atlas:ai:place-feature \"<feature>\" --json"
  - "php artisan atlas:product-delivery:primitives --json"
  - "php artisan atlas:product-delivery:certify --json --strict"
requires_evidence: true
risk_level: high
next_actions:
  - Manter owner doc disponivel para placement de decisoes estrategicas.
---

# Atlas Strategic Decision Domain

## Resumo

Atlas Strategic Decision Domain governa pedidos em que o Atlas precisa decidir caminho, risco, prioridade, execução ou tradeoff antes de agir.

- Nome humano: Atlas Strategic Decision Domain
- Nome técnico/canônico: `strategic_decision`
- Tipo: domain
- Status: active
- Fonte canônica: `docs/engineering-knowledge-base/domains/strategic_decision.md`

## Papel no Atlas

Este domínio transforma intenção humana ambígua em decisão segura: qual fluxo usar, qual evidência falta, quando usar Dev, quando escalar para Forge, quando pedir aprovação humana e quando bloquear execução.

## Onde Se Encaixa

Fica entre Atlas AI e os runtimes de execução. Ele consulta AEDPDS, Runtime Gate, Product Truth, Dev e Forge antes de permitir execução.

## Contratos

- `strategic_decision.review`
- `atlas.product_execution_primitives.v1`
- `atlas.product_delivery.risk_governor.v1`

## Fluxo

Pedido humano -> Human Intent Model -> Product Truth Contract -> Runtime Gate -> Dev/Forge/Review/Blocked.

## Regras para IA

Não executa decisão crítica sem evidência. Não escolhe provider por preferência fixa. Não substitui docs canônicos, testes, receipts ou gates.

## Escopo de Implementacao

Permitido: regras de decisão, docs de domínio, testes focados e integração com AEDPDS.

Proibido: executar provider, aplicar patch ou criar runtime paralelo.

## Dependencias

- `docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md`
- `docs/engineering-knowledge-base/atlas-execution-doctrine-product-delivery-system.md`

## Evidencias

Gates esperados:

- `php artisan atlas:ai:place-feature "..." --json`
- `php artisan atlas:product-delivery:primitives --json`
- `php artisan atlas:product-delivery:certify --json --strict`
- `php artisan atlas:engineering:knowledge docs-health --json`

## Riscos

- Decisão de rota baseada só em conversa.
- Dev tentando fazer Obra Forge.
- Forge executando sem work packet, gate ou aprovação.

## Exemplos

- Bug curto em login -> Atlas Dev com runtime gate.
- Ecommerce completo -> Forge com workcell, proof e aprovação.

## Proximas Acoes

- Manter este owner doc sincronizado com o placement de `strategic_decision`.
