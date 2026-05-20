---
id: atlas-dev-efficient-programming-flow-runbook-v1
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow Runbook v1
status: active
category: programming
priority: 105
summary: Runbook de implementacao do Atlas Dev Efficient Programming Flow. Sequencia de fatias com paths absolutos, signatures, fixtures, DoD operacional e ordem dentro da fatia. Doc filho do contrato principal e do contracts. Nao contem regras de medicao, benchmark, oraculos ou Rivals — sao trabalho de outra equipe.
tags:
  - atlas-dev
  - efficient-programming-flow
  - runbook
  - implementation
  - slices
  - dod
capabilities:
  - atlas_dev_implementation_runbook
  - fatia_0_schemas
  - fatia_1_context_layer
  - fatia_1_5_runtime_quality_foundations
  - fatia_2_plan_only_pipeline
  - fatia_3_one_call_receipts
  - fatia_4_repair_loop
  - fatia_5_surface_wireup
  - senior_engineer_loop
decisions:
  - Implementacao segue ordem rigida 0 -> 1 -> 1.5 -> 2 -> 3 -> 4 -> 5. Fatia nao comeca sem DoD da anterior verde.
  - Cada fatia tem DoD operacional verificavel por testes mecanicos.
  - Reuso obrigatorio dos services existentes (AtlasCliDevWorkflowService, AtlasProgrammingOrchestrator, KernelPipelineDevPlanBuilder, etc.). Nao criar paralelos.
  - Persistencia local em `storage/atlas-dev/receipts/<run_id>/` ate Fatia 5.
  - Provider lock `claude_cli` + Sonnet sem fallback durante todo o fluxo.
  - Esta equipe constroi ate Fatia 5; outra equipe assume medicao/Rivals.
  - Surface inicial completa e Atlas AI Desktop Mac (`surface_id=atlas_desktop_ai`); CLI/App/API entram como paridade depois.
maintenance:
  - Atualize este runbook quando ordem de fatias mudar, quando uma fatia ganhar/perder PR, ou quando DoD mudar.
  - Nao adicione passos de medicao competitiva, baterias, oraculos ou Rivals.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-index.md
  - docs/engineering-knowledge-base/atlas-dev-glossary.md
  - docs/engineering-knowledge-base/atlas-dev-policy.md
  - docs/engineering-knowledge-base/atlas-dev-patamares.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - ../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/AtlasAiSurface.tsx
  - ../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/contract.ts
  - ../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/client.ts
  - app/Services/Ai/Surface/Adapters/AtlasDesktopAiSurfaceAdapter.php
  - app/Services/Ai/Programming/AtlasDevRuntimeService.php
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-01.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-02.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-03.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-04.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-05.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-06.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-07.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-08.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-09.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-10.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-11.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-efficient-programming-flow-runbook-v1
graph_title: Atlas Dev Efficient Programming Flow Runbook v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-dev-efficient-programming-flow-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
allowed_changes:
  - Adicionar PR novo dentro de fatia existente, com paths, signatures e DoD.
  - Detalhar fixtures e edge cases conforme implementacao avance.
forbidden_changes:
  - Pular fatia ou pular DoD.
  - Criar fatia de medicao/benchmark/Rivals/Opus challenge.
  - Inserir prompt artesanal em qualquer fatia; prompt vem de ProviderPromptProjection.
depends_on:
  - atlas-dev-efficient-programming-flow-v1
  - atlas-dev-efficient-programming-flow-contracts-v1
flows_to:
  - atlas_cli_dev
  - atlas_ai_chat
  - atlas_desktop_ai
  - atlas_app
unlocks:
  - atlas_dev_efficient_flow_runtime
governs:
  - atlas_dev.implementation.slices
  - atlas_dev.implementation.dod
evidence:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md
next_actions:
  - Comecar Fatia 0 (schemas DTOs read-only) com PR 0.1.
required_tests:
  - "php artisan test tests/Unit/Ai/Programming/AtlasDev"
  - "php artisan test tests/Feature/AtlasDev"
requires_evidence: true
risk_level: high
line_limit: 2400
---
# Atlas Dev Efficient Programming Flow Runbook v1

## Resumo

Este documento e o runbook de implementacao do Atlas Dev Efficient Programming Flow.

## Papel no Atlas

Traduz o contrato principal e o anexo de schemas em fatias implementaveis com DoD verificavel.

## Onde Se Encaixa

Fica como doc filho do contrato principal e dos contratos de schema, guiando execucao por PR/fatia.

## Contratos

Respeita os contratos de schema, hash, provider prompt projection, verification receipt e escalation decision.

## Fluxo

Executa Fatia 0 -> 1 -> 1.5 -> 2 -> 3 -> 4 -> 5, sem pular DoD.

## Regras para IA

IA deve seguir a ordem de fatias, preservar worktree, reutilizar services existentes e nao introduzir benchmark/Rivals neste fluxo.

## Escopo de Implementacao

Escopo: orientar implementacao do Atlas Dev Efficient Flow; nao define medicao competitiva nem substitui o contrato principal.

## Dependencias

Depende do contrato principal, do anexo de schemas, do AtlasCliDevWorkflowService e dos sistemas de contexto/programacao existentes.

## Evidencias

Evidencias esperadas: testes unitarios/feature por fatia, receipts locais e DoD operacional verde.

## Riscos

Risco principal: criar driver paralelo ou pular gates. Mitigacao: reuso obrigatorio e DoD sequencial.

## Exemplos

Os exemplos operacionais aparecem nas secoes de cada fatia abaixo.

## Senior Engineer Loop

O Senior Engineer Loop e a camada de julgamento operacional acima do fast path.
Ele nao substitui os gates existentes; ele projeta e audita, para cada run,
se o Atlas Dev agiu como engenheiro senior:

- resolveu ambiguidade com hipoteses baseadas no workspace;
- produziu plano multi-step com evidencias por etapa;
- manteve debug loop verificavel por comandos reais;
- preservou edicao architecture-aware via allowed_files, forbidden_files,
  max_files_changed, non_goals e provider_lock sem fallback;
- expos paineis de cockpit Desktop para intent, ambiguidade, plano, escopo,
  verificacao, receipt e learning;
- gerou handoff para ErrorLedger/Programming Curator sem auto-aplicar aprendizado;
- permaneceu enterprise: provider-safe, receipts persistidos, fallback bloqueado
  e audit strict.

Comando de auditoria:

```bash
php artisan atlas:dev:senior-loop:audit --json --strict
```

O comando persiste `senior_engineer_loop_audit.json` em
`storage/atlas-dev/receipts/<run_id>/` com schema
`atlas.dev.senior_engineer_loop_audit.v1`.

Comando operacional end-to-end:

```bash
php artisan atlas:dev:senior-loop:run --json --strict
```

Esse comando executa Plan -> Run -> patch/diff -> scope guard -> verification
-> learning handoff em workspace fixture isolado e persiste
`senior_engineer_loop_execution.json` com schema
`atlas.dev.senior_engineer_loop_execution.v1`. Em runs falhos, bloqueados ou
que exigem revisao, o handoff grava `error_ledger.vN.json` real para curadoria
humana e `failure_capsule.<attempt>.json` para registrar assinatura da falha,
budget de reparo e stop signals; aprendizado nunca e auto-aplicado. Completion
do patamar Senior Engineer Loop so pode ser alegado quando audit, execution,
testes AtlasDev e checks de documentacao estiverem verdes.

## Proximas Acoes

Comecar pela Fatia 0, com DTOs read-only e testes de schema.

## Detalhes Operacionais Extraidos

Este documento foi reduzido para funcionar como indice canonico. O conteudo operacional detalhado vive nos recortes abaixo, para manter a cartografia e o modal humano legiveis sem perder nenhuma informacao.

- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-01.md` — 1. Resumo ate 6.1 Objetivo.
- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-02.md` — 6.2 PRs Sugeridos ate 7.1 Objetivo.
- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-03.md` — 7.2 PRs Sugeridos ate 8.1 Objetivo.
- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-04.md` — 8.2 PRs Sugeridos ate 9.1 Objetivo.
- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-05.md` — 9.2 PRs Sugeridos ate 10.1 Objetivo.
- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-06.md` — 10.2 PRs Sugeridos ate 10.3 DoD Operacional Da Fatia 3.
- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-07.md` — 10.4 Marco 3 — One-Call Visivel No Atlas AI Desktop ate 12.1 Objetivo.
- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-08.md` — 12.2 PRs Sugeridos ate 15.1.9 Identidade do fluxo (intake invariants).
- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-09.md` — 15.1.10 Checklist de release ate 15.1.15 Limitações de QA visual.
- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-10.md` — 15.1.16 Diagnóstico operacional — receitas curtas.
- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-11.md` — 15.6 Senior Engineer Loop ate 16. Sequencia De Trabalho Recomendada Por Agente IA.

### Regra De Manutencao

- Nao adicionar novas responsabilidades neste indice se um recorte filho for o lugar correto.
- Ao alterar um recorte, manter backlink para este indice e rodar `php artisan atlas:engineering:knowledge docs-health --json`.
- A cartografia deve tratar este indice como porta de entrada e os recortes como documentacao operacional detalhada.
