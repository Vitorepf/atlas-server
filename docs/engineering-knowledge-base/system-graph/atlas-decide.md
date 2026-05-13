---
id: atlas-decide
type: engineering_knowledge
title: Atlas Decide
status: active
category: kernel
priority: 98
summary: Engrenagem do Kernel que escolhe provider, modelo, autonomia, budget, fallback e contrato antes de qualquer execucao.
tags:
  - atlas
  - kernel
  - decide
  - provider-routing
capabilities:
  - atlas_decide
  - model_selection
  - provider_routing
  - decision_receipt
decisions:
  - Atlas Decide e dono da escolha de provider/modelo; surfaces exibem a decisao, nao decidem sozinhas.
  - Manual override precisa ser auditado e refletido no Decision Receipt.
maintenance:
  - Atualizar quando sinais, fallback, budget, AP-99 ou politica de modelo mudarem.
  - Validar com docs-health apos qualquer alteracao.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md
  - docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md
  - docs/engineering-knowledge-base/system-graph/decision-receipt.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-decide
graph_title: Atlas Decide
graph_world: atlas
graph_layer: gear
graph_kind: step
graph_parent: atlas-ai-kernel-pipeline
graph_status: active
graph_source: repo
owner: atlas-kernel
repo_paths:
  - docs/engineering-knowledge-base/system-graph/atlas-decide.md
  - docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md
  - docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md
allowed_changes:
  - Ajustar sinais de decisao quando houver evidencia de performance, custo, latencia ou qualidade.
  - Atualizar fallback chain e politica de override quando os providers evoluirem.
forbidden_changes:
  - Permitir que UI escolha provider/modelo sem Decision Receipt.
  - Declarar provider vencedor sem evidencia, budget e fallback.
depends_on:
  - policy-profile
  - context-builder
  - evidence-loop
flows_to:
  - decision-receipt
unlocks:
  - runtime-executor
governs:
  - provider-routing
  - model-selection
evidence:
  - docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md
  - docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: critical
next_actions:
  - Conectar Decision Receipt v2 aos sinais reais do Atlas Decide.
---

# Atlas Decide

## Resumo

Atlas Decide e a engrenagem que transforma contexto, politica, risco e historico de performance em uma decisao executavel. Ele define quem executa, com qual modelo, sob qual autonomia, com qual budget e com qual fallback.

## Papel no Atlas

Ele impede que cada surface vire um seletor manual de provider. Atlas Code, CLI, mobile e MCP podem solicitar execucao, mas a decisao passa pelo Kernel.

## Onde Se Encaixa

Pai: `atlas-ai-kernel-pipeline`. Depende de `policy-profile`, `context-builder` e feedback do `evidence-loop`. Alimenta `decision-receipt`, que vira contrato antes do runtime.

## Contratos

Entrada: envelope, contexto, perfil de politica, sinais de performance, risco e budget. Saida: decisao com provider, modelo, fallback chain, autonomia, custo estimado e justificativa auditavel.

## Fluxo

Policy/Profile e Context Builder chegam ao Decide. Evidence Loop calibra historico. Decide escolhe rota e entrega ao Decision Receipt.

## Regras para IA

IA nao pode escolher provider por preferencia local. Deve consultar o contrato de modelo e registrar override quando a escolha for humana.

## Escopo de Implementacao

Permitido: regras de selecao, metricas, fallback e documentacao de sinais. Proibido: provider dropdown livre fora do receipt.

## Dependencias

- `policy-profile`
- `context-builder`
- `evidence-loop`
- `atlas-ai-model-selection-strategy`

## Evidencias

- `docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md`
- `docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md`

## Riscos

- UI escolher provider sem governanca.
- Custo real divergir do estimado.
- Historico AP-99 ficar obsoleto.

## Exemplos

Tarefa de arquitetura pode ir para Claude; implementacao localizada pode ir para Codex; override humano precisa aparecer no receipt.

## Proximas Acoes

Mapear os 14 sinais reais usados por Atlas Decide em um payload de Decision Receipt v2.
