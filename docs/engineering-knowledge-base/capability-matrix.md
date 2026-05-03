---
id: engineering-knowledge-capability-matrix
type: engineering_knowledge
title: Matriz De Capacidades Do Harness
status: active
category: capability_matrix
priority: 94
summary: Estado das principais capacidades do Atlas Engineering Harness Runner e como elas devem ser mantidas.
tags:
  - harness
  - atlas-bench
  - quality
  - visual
capabilities:
  - runner_mvp
  - atlas_bench
  - docker_harness
  - visual_harness
  - quality_scan
  - context_pack_recall
  - code_intelligence_index
  - engineering_blueprint
  - task_contracts
  - qa_evidence
  - review_gates
  - postgres_gate
decisions:
  - Capacidades maduras precisam de teste focado e referencia em docs canonicos.
  - Capacidades calibradas por historico precisam indicar nivel de confianca.
  - Capacidades core precisam aparecer no indice de codigo e nao apenas em docs.
maintenance:
  - Atualizar esta matriz quando uma fase do Harness muda de escopo.
  - Nao marcar como maduro aquilo que depende de volume real ainda inexistente.
  - Rode atlas engineering knowledge index-code --prune para atualizar cobertura codigo->docs.
related_paths:
  - app/Services/Engineering/EngineeringHarnessRunnerService.php
  - app/Services/Engineering/EngineeringBlueprintService.php
  - app/Services/Engineering/EngineeringTaskContractService.php
  - app/Services/Engineering/EngineeringBlueprintSnapshotService.php
  - app/Services/Engineering/EngineeringBenchmarkService.php
  - app/Services/Engineering/EngineeringQualityScanService.php
  - app/Services/Engineering/EngineeringCodeIntelligenceService.php
  - docs/engineering-knowledge-base/engineering-blueprint.md
---

# Matriz De Capacidades

## Implementado

| Capacidade | Estado | Observacao |
|---|---|---|
| Runner MVP | Implementado | Runs, attempts, context pack, patch artifacts, controls, tests e scoring. |
| Repair loop | Implementado | Replay de run/attempt, ranking de attempts e override de modelo. |
| Worktree sandbox | Implementado | Isolamento por tentativa e politica de autonomia. |
| Review findings | Implementado | Findings dedicados e bloqueio por severidade P0/P1. |
| Harnessability | Implementado | Score e calibracao historica; confianca aumenta com volume real. |
| Atlas-Bench | Implementado | Suites, cases, corpus, trends, quality metrics, release gates e outcomes. |
| Docker Harness | Implementado | Sandbox Docker, provider runtime Docker, healthchecks, cache e cleanup. |
| Visual Harness | Implementado | Visual smoke, Playwright Atlas-managed, traces, screenshots e baseline pixel-level. |
| Quality Scan | Implementado | Ferramentas locais/gratuitas, artifacts, redaction, findings e recommendations. |
| Knowledge Base | Implementado | Docs canonicos, registry Postgres, CLI/API e context refs. |
| Code Intelligence Index | Implementado | Modulos, simbolos, rotas, comandos, migrations, testes, links docs->codigo e code refs no context pack. |
| Engineering Blueprint task-level | Implementado base | Contrato por task, blueprint deterministico, snapshot congelado, evidence API e injecao no `atlas dev --task-id`. |
| Engineering Blueprint project-level | Implementado base | `atlas project blueprint prepare/create/validate/freeze`, API equivalente, tabela dedicada, hash deterministico, supersede, staleness, coverage validator e geracao de tasks por fase. |
| Manual QA workflow | Implementado base | `atlas qa`, API `tasks/{task}/engineering/qa`, evidence `manual_qa` rica e UI de QA manual no app. |
| Deep Review gate | Implementado base | `confidence`, `category`, recommendation, `atlas review --deep`, API de triagem e threshold P0/P1 com confidence. |
| Postgres Engineering Review | Implementado base | `PostgresEngineeringReviewService`, `atlas db review/explain`, evidence `database_review` e findings `migration_risk`/`data_integrity`. |

## Implementado Parcialmente

| Capacidade | Estado | Observacao |
|---|---|---|
| Scenario/Inventory coverage | Parcial | Validator bloqueante cobre screens, APIs, scenarios, visual evidence e database review; wireframe refs dedicados ainda sao maturacao. |
| App Produto Final | Parcial | Projetos exibem blueprint project-level e QA; Engineering promove runs para Atlas-Bench. Ainda falta polimento de run-start visual por task e filtros globais. |

## Maturacao Operacional

Estas partes existem no codigo, mas dependem de uso real:

- confianca alta na calibracao de harnessability;
- comparacao robusta entre Codex, Claude, GPT e futuros motores;
- calibracao dinamica de perfis de quality scan;
- baselines estatisticos de Atlas-Bench por dominio e risco.

## Regra De Manutencao

Ao implementar uma capacidade nova:

1. adicionar ou atualizar doc canonico;
2. sincronizar a knowledge base;
3. criar teste focado;
4. registrar no help/CLI quando virar operavel;
5. expor no app apenas quando houver fluxo claro para operador.
