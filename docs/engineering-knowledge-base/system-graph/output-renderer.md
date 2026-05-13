---
id: output-renderer
type: engineering_knowledge
title: Output Renderer
status: active
category: kernel
priority: 86
summary: Renderiza resposta, patch, plano, proposta ou briefing para a superficie correta preservando receipt e evidencia.
tags: [atlas, kernel, output]
capabilities: [output_renderer, response_rendering]
decisions:
  - Output Renderer adapta apresentacao; nao altera verdade operacional.
maintenance:
  - Atualizar quando surfaces exigirem novos formatos de saida.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: output-renderer
graph_title: Output Renderer
graph_world: atlas
graph_layer: gear
graph_kind: step
graph_parent: atlas-ai-kernel-pipeline
graph_status: active
graph_source: repo
owner: atlas-kernel
repo_paths:
  - docs/engineering-knowledge-base/system-graph/output-renderer.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md
allowed_changes:
  - Adicionar formatos por surface.
forbidden_changes:
  - Omitir receipt ou evidencia relevante da saida.
depends_on:
  - learning-proposals
  - evidence-ledger
flows_to:
  - surface-plane
unlocks:
  - surface-plane
governs:
  - response-output
evidence:
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: medium
next_actions:
  - Padronizar output de resposta, diff, plan, receipt e evidence no Atlas Code.
---

# Output Renderer

## Resumo

Output Renderer apresenta o resultado do Kernel na surface correta sem mudar a verdade operacional.

## Papel no Atlas

Ele adapta formato para Atlas Code, CLI, mobile ou API preservando receipt, evidencia e estado.

## Onde Se Encaixa

Pai: `atlas-ai-kernel-pipeline`. Recebe de `learning-proposals` e `evidence-ledger`, retorna para `surface-plane`.

## Contratos

Entrada: resposta, patch, plano, receipt, evidence ou proposta. Saida: representacao de surface. Invariante: render nao altera dado canonico.

## Fluxo

Kernel produz resultado. Renderer escolhe formato e surface mostra ao usuario.

## Regras para IA

IA deve manter separacao entre conteudo canonico e apresentacao visual.

## Escopo de Implementacao

Permitido: formatting, agrupamento e destaque. Proibido: remover falhas ou warnings.

## Dependencias

- `learning-proposals`
- `evidence-ledger`
- `surface-plane`

## Evidencias

- `docs/engineering-knowledge-base/atlas-ai-pipeline.md`
- `docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md`

## Riscos

- UI esconder risco por estetica.
- Output divergente entre surfaces.

## Exemplos

Atlas Code pode mostrar plan, receipt e evidence em paineis separados, mas os dados vêm do mesmo evento.

## Proximas Acoes

Definir view model unico para resposta do Atlas Code.

