---
id: decision-receipt
type: engineering_knowledge
title: Decision Receipt
status: active
category: kernel
priority: 98
summary: Contrato assinado que registra decisao, escopo, autonomia, budget, rollback, fallback e evidencia antes da execucao.
tags:
  - atlas
  - kernel
  - receipt
  - audit
capabilities:
  - decision_receipt
  - signed_execution_contract
  - auditability
decisions:
  - Nenhuma execucao relevante deve ocorrer sem receipt.
  - Receipt nao e log pos-fato; e contrato antes do runtime.
maintenance:
  - Atualizar quando campos do receipt, assinatura, rollback ou fallback mudarem.
related_paths:
  - docs/engineering-knowledge-base/spec-operating-system/plan-task-and-receipt-contract.md
  - docs/engineering-knowledge-base/system-graph/atlas-decide.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: decision-receipt
graph_title: Decision Receipt
graph_world: atlas
graph_layer: gear
graph_kind: step
graph_parent: atlas-ai-kernel-pipeline
graph_status: active
graph_source: repo
owner: atlas-kernel
repo_paths:
  - docs/engineering-knowledge-base/system-graph/decision-receipt.md
  - docs/engineering-knowledge-base/spec-operating-system/plan-task-and-receipt-contract.md
allowed_changes:
  - Evoluir campos do receipt quando gates, budget, fallback ou assinatura exigirem.
forbidden_changes:
  - Executar mudanca sem receipt quando a politica exigir contrato.
  - Usar receipt como texto explicativo sem hash, escopo ou evidencia.
depends_on:
  - atlas-decide
flows_to:
  - runtime-executor
unlocks:
  - quality-gates
  - evidence-ledger
governs:
  - execution-contract
  - rollback-policy
evidence:
  - docs/engineering-knowledge-base/spec-operating-system/plan-task-and-receipt-contract.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: critical
next_actions:
  - Garantir que Atlas Code exiba receipt completo antes de aplicar qualquer diff.
---

# Decision Receipt

## Resumo

Decision Receipt e o contrato auditavel entre decisao e execucao. Ele preserva o que foi decidido, por quem, com qual modelo, com qual escopo, qual rollback, quais gates e qual evidencia precisa existir depois.

## Papel no Atlas

Ele impede execucao invisivel. Se Atlas Code vai programar, o receipt e a linha que separa intencao de acao.

## Onde Se Encaixa

Pai: `atlas-ai-kernel-pipeline`. Recebe a decisao do `atlas-decide` e alimenta `runtime-executor`.

## Contratos

Campos minimos: decision id, trace id, obra, domain, flow, provider/modelo, fallback, confidence, budget, autonomy, allowed scope, forbidden scope, rollback, required gates e assinatura quando aplicavel.

## Fluxo

Atlas Decide emite decisao. Receipt cristaliza contrato. Runtime Executor so executa dentro do contrato.

## Regras para IA

IA deve tratar receipt como fronteira de execucao. Se o arquivo alvo nao esta permitido ou esta proibido, deve parar.

## Escopo de Implementacao

Permitido: schema, assinatura, exibicao, verificacao e ledger. Proibido: aplicar diff sem receipt em fluxo governado.

## Dependencias

- `atlas-decide`
- `spec-operating-system/plan-task-and-receipt-contract`

## Evidencias

- `docs/engineering-knowledge-base/spec-operating-system/plan-task-and-receipt-contract.md`

## Riscos

- Receipt incompleto virar teatro de governanca.
- Assinatura existir sem validar payload real.
- Runtime ignorar escopo permitido/proibido.

## Exemplos

Um receipt de refactor deve listar arquivos permitidos, testes obrigatorios e rollback antes de qualquer patch.

## Proximas Acoes

Conectar receipt do backend com o painel Atlas Code e exigir confirmacao antes de execucao.
