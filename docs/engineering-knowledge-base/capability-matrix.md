---
id: engineering-knowledge-capability-matrix
type: engineering_knowledge
title: Matriz De Capacidades Do Harness Legacy
status: deprecated
category: capability_matrix
priority: 35
summary: Matriz legada das capacidades do Harness; mantida como historico e substituida por Blueprint Maturity, Super Tool Runtime, Programming Power Tools e Canonical Architecture Index.
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
  - open_brain_context_injection
  - code_intelligence_index
  - engineering_blueprint
  - task_contracts
  - qa_evidence
  - review_gates
  - postgres_gate
  - programming_power_tools_catalog
  - fair_claude_benchmark
  - atlas_supercharged_routing
decisions:
  - Capacidades maduras precisam de teste focado e referencia em docs canonicos.
  - Capacidades calibradas por historico precisam indicar nivel de confianca.
  - Capacidades core precisam aparecer no indice de codigo e nao apenas em docs.
  - Este documento nao e mais fonte primaria de status; use os documentos em superseded_by.
maintenance:
  - Nao expandir esta matriz; mover atualizacoes para os docs canonicos vivos.
superseded_by:
  - docs/engineering-knowledge-base/engineering-blueprint-maturity-dod.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
related_paths:
  - app/Services/Engineering/EngineeringHarnessRunnerService.php
  - app/Services/Engineering/EngineeringBlueprintService.php
  - app/Services/Engineering/EngineeringTaskContractService.php
  - app/Services/Engineering/EngineeringBlueprintSnapshotService.php
  - app/Services/Engineering/EngineeringBenchmarkService.php
  - app/Services/Engineering/EngineeringQualityScanService.php
  - app/Services/Engineering/EngineeringCodeIntelligenceService.php
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - docs/atlas-cli-5x-claude-code-plan.md
  - docs/atlas-cli-fair-claude-benchmark.md
  - docs/atlas-cli-release-checklist.md
---

# Matriz De Capacidades

> Status: deprecated. Esta matriz foi util durante a consolidacao inicial do
> Harness, mas hoje duplica status que vive em docs mais especificos. Para
> status atual, leia `engineering-blueprint-maturity-dod.md`,
> `super-tool-runtime-core.md` e `programming-power-tools-catalog.md`.

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
| Memory Learning Promotion + Quality Scorecard | Implementado | `atlas memory maintain` promove deltas aceitos, `atlas memory quality` mede provider-safety, governance, feedback, freshness, completude e recomenda acoes. |
| Provider CLI Runtime Ownership | Implementado | Atlas controla modelo, sandbox/permissao e flags criticos de Claude/Codex/Gemini no job; args configurados obsoletos nao podem reintroduzir `--ask-for-approval`, `--yolo`, sandbox ou modelo divergente; health checks inspecionam `--help`/`exec --help` e degradam CLIs incompativeis antes de execucao real. |
| Knowledge Base | Implementado | Docs canonicos, registry Postgres, CLI/API e context refs. |
| Code Intelligence Index | Implementado | Modulos, simbolos, rotas, comandos, migrations, testes, links docs->codigo e code refs no context pack. |
| Engineering Blueprint task-level | Implementado base | Contrato por task, blueprint deterministico, snapshot congelado, evidence API e injecao no `atlas dev --task-id`. |
| Engineering Blueprint project-level | Implementado base | `atlas project blueprint prepare/create/validate/freeze`, API equivalente, tabela dedicada, hash deterministico, supersede, staleness, coverage validator e geracao de tasks por fase. |
| Manual QA workflow | Implementado base | `atlas qa`, API `tasks/{task}/engineering/qa`, evidence `manual_qa` rica e UI de QA manual no app. |
| Deep Review gate | Implementado base | `confidence`, `category`, recommendation, `atlas review --deep`, API de triagem e threshold P0/P1 com confidence. |
| Postgres Engineering Review | Implementado base | `PostgresEngineeringReviewService`, `atlas db review/explain`, evidence `database_review` e findings `migration_risk`/`data_integrity`. |
| Super Tool Runtime Core | Implementado base | Registry, policy, executor, normalizer, Evidence Store, approvals, waivers, recipes, authority matrix, gates e painel Engineering. |
| Programming Power Tools Catalog | Implementado doc canonico | 61 ferramentas/capacidades organizadas por T0-T3, autoridade, anti-duplicacao, status real e backlog de recipes/normalizers/gates. |

## Implementado Parcialmente

| Capacidade | Estado | Observacao |
|---|---|---|
| Scenario/Inventory coverage | Parcial | Validator bloqueante cobre screens, APIs, scenarios, visual evidence e database review; wireframe refs dedicados ainda sao maturacao. |
| App Produto Final | Parcial | Projetos exibem blueprint project-level e QA; Engineering promove runs para Atlas-Bench. Ainda falta polimento de run-start visual por task e filtros globais. |
| Open Brain Context Injection | Implementado | `atlas dev`, `atlas continue`, `atlas chat` e Atlas AI App usam Open Brain automaticamente em codigo/review/debug com policy, budget, trace metadata, audit log e status no CLI/app. |
| Fair Claude 5x Benchmark | Parcial doc canonico | Protocolo e plano existem; ainda falta enforcement executavel de fair mode, runner pareado Claude Code, scorecard 5x, protocol validity e suite oficial. |
| Atlas Supercharged Routing | Parcial doc canonico | Estrategia documentada para Gemini Scout, Context Compiler, recipes por tipo de tarefa, config propagation audit e Evidence-Based Router; falta implementacao completa e scorecard operacional. |

## Maturacao Operacional

Estas partes existem no codigo, mas dependem de uso real:

- confianca alta na calibracao de harnessability;
- comparacao robusta entre Codex, Claude, GPT e futuros motores;
- comparacao pareada Fair Claude contra Claude Code usando o mesmo Claude;
- calibracao empirica de Gemini Scout como economia de contexto e custo;
- Evidence-Based Router com volume suficiente por tipo de tarefa;
- calibracao dinamica de perfis de quality scan;
- baselines estatisticos de Atlas-Bench por dominio e risco.

## Regra De Manutencao

Ao implementar uma capacidade nova:

1. adicionar ou atualizar doc canonico;
2. sincronizar a knowledge base;
3. criar teste focado;
4. registrar no help/CLI quando virar operavel;
5. expor no app apenas quando houver fluxo claro para operador.
