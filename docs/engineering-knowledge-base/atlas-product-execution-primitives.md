---
id: atlas-product-execution-primitives
type: engineering_knowledge
title: Atlas Product Execution Primitives
status: active
category: product-delivery
priority: 95
summary: Contrato que materializa Human Intent Model, Software Twin, Outcome Memory, Cartografia operacional, Runtime Gate e Provider/Agent Strategy antes de execucao.
human_summary: Mostra se um pedido humano ja tem intencao, simulacao, memoria, cartografia, gate e estrategia suficientes para continuar.
human_what: Envelope read-only das seis primitivas de execucao de produto.
human_purpose: Impedir que Atlas Dev, Forge ou provider executem trabalho sem os blocos minimos de decisao e prova.
human_input: Pedido humano, workspace, rota opcional, evidencias e flags de aprovacao.
human_output: Envelope `atlas.product_execution_primitives.v1`.
human_change_when: Atualize quando AEDPDS, Product Truth, Twin, Outcome, Cartografia, Runtime Gate ou Provider Strategy mudarem.
human_block_when: Bloqueie se provider ou escrita puderem iniciar sem Runtime Gate.
human_name: Atlas Product Execution Primitives
canonical_name: Atlas Product Execution Primitives
technical_name: atlas-product-execution-primitives
product_name: Atlas Product Execution Primitives
internal_product_name: Atlas Product Execution Primitive Envelope
technical_runtime: AtlasProductExecutionPrimitivesService
runtime_acronym: APEP
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-product-execution-primitives.md
tags:
  - atlas
  - product-delivery
  - execution-primitives
  - runtime-gate
capabilities:
  - human_intent_model
  - software_twin_simulation
  - outcome_memory
  - operational_cartography
  - runtime_gate
  - provider_agent_strategy
decisions:
  - APEP e read-only e nao invoca provider.
  - Runtime Gate bloqueado torna o envelope `status=blocked`; materializar primitivas nao e autorizacao para executar.
  - Provider/Agent Strategy recomenda modo de trabalho, nao declara provider vencedor.
maintenance:
  - Atualizar junto com `AtlasProductExecutionPrimitivesService`.
related_paths:
  - docs/engineering-knowledge-base/atlas-execution-doctrine-product-delivery-system.md
  - docs/engineering-knowledge-base/atlas-universal-reality-cartography.md
  - app/Services/Ai/Product/AtlasProductExecutionPrimitivesService.php
  - app/Console/Commands/Ai/Product/AtlasProductExecutionPrimitivesCommand.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-product-execution-primitives
graph_title: Atlas Product Execution Primitives
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-execution-doctrine-product-delivery-system
graph_status: active
graph_source: repo
owner: product-delivery
repo_paths:
  - docs/engineering-knowledge-base/atlas-product-execution-primitives.md
allowed_changes:
  - Atualizar schemas, testes e command quando uma primitiva mudar.
forbidden_changes:
  - Transformar APEP em executor mutativo.
  - Declarar provider vencedor sem evidence real.
depends_on:
  - atlas-execution-doctrine-product-delivery-system
flows_to:
  - atlas-dev-runtime-intelligence
  - atlas-forge-work-packet-native-capabilities
unlocks:
  - product_execution_gate
governs:
  - atlas-ai
  - atlas-dev
  - atlas-forge
evidence:
  - app/Services/Ai/Product/AtlasProductExecutionPrimitivesService.php
  - tests/Feature/Ai/Product/AtlasExecutionDoctrineProductDeliverySystemTest.php
evidence_refs:
  - symbol: AtlasProductExecutionPrimitivesService
  - command: atlas:product-delivery:primitives
required_tests:
  - "php artisan test tests/Feature/Ai/Product/AtlasExecutionDoctrineProductDeliverySystemTest.php --filter=product_execution_primitives"
  - "php artisan atlas:product-delivery:primitives \"estou com bug na tela de login\" --workspace=atlas-app --operator-approved --ux=\"login visual regression expectation\" --json --strict"
requires_evidence: true
risk_level: high
next_actions:
  - Manter APEP certificado dentro de `atlas:product-delivery:certify`.
---

# Atlas Product Execution Primitives

## Resumo

APEP e o envelope que mostra se o Atlas tem os seis blocos minimos para executar com qualidade: intencao, simulacao, memoria, cartografia, gate e estrategia.

## Papel no Atlas

Ele fica dentro do AEDPDS e antes de provider, patch, subagente ou conclusao. O objetivo e transformar uma fala humana em um estado operacional auditavel.

## Onde Se Encaixa

```text
Pedido humano
  -> AEDPDS
  -> APEP
      -> Human Intent Model
      -> Software Twin / Simulation
      -> Outcome Memory
      -> Cartografia operacional
      -> Runtime Gate
      -> Provider/Agent Strategy
  -> Dev / Forge / Review / Blocked
```

## Contratos

- `atlas.product_execution_primitives.v1`
- `atlas.product_execution.human_intent_model.v1`
- `atlas.product_execution.operational_cartography.v1`
- `atlas.product_execution.runtime_gate.v1`
- `atlas.product_execution.provider_agent_strategy.v1`

## Fluxo

APEP reusa contratos existentes. Product Truth vira Human Intent Model. Product Twin vira simulacao. Outcome Memory registra aprendizado. AURC projeta cartografia. Risk Governor e Enforcement formam Runtime Gate. Provider Memory vira estrategia.

## Regras para IA

- Nao criar primitivas paralelas.
- Nao executar provider a partir do envelope.
- Tratar `status=ready` como envelope pronto e gate operacional liberado, nao como autorizacao mutativa.
- Tratar `runtime_gate.gate_decision=blocked` como bloqueio real; nesse caso `status` precisa ser `blocked` e `--strict` deve falhar.

## Escopo de Implementacao

Implementado em `AtlasProductExecutionPrimitivesService` e exposto por `atlas:product-delivery:primitives`.

## Dependencias

- AEDPDS
- AURC
- Product Twin
- Product Delivery Risk Governor
- Product Delivery Provider Memory Feed

## Evidencias

- Testes focados cobrem os seis blocos.
- Certificacao AEDPDS inclui `product_execution_primitives`.

## Riscos

- Confundir envelope pronto com execucao liberada.
- Ignorar aprovacao humana em risco alto.
- Usar provider strategy como ranking absoluto de modelo.

## Exemplos

```bash
php artisan atlas:product-delivery:primitives "estou com bug na tela de login" --workspace=atlas-app --operator-approved --ux="login visual regression expectation" --json --strict
```

Exemplo que deve bloquear em modo strict:

```bash
php artisan atlas:product-delivery:primitives "estou com bug na tela de login" --workspace=atlas-app --json --strict
```

## Proximas Acoes

- Conectar o envelope como painel visivel em Atlas AI quando o frontend pedir o estado de execucao.
