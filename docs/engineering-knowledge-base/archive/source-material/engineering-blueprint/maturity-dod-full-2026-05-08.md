---
id: atlas-engineering-blueprint-maturity-dod
type: engineering_knowledge
title: Atlas Engineering Blueprint Maturity And DoD
status: source_material
category: maturity
priority: 96
summary: Estado real, plano de implementacao e Definition of Done final para os 7 itens do Atlas Engineering Blueprint System.
tags:
  - atlas
  - engineering
  - maturity
  - dod
capabilities:
  - engineering_blueprint
  - project_blueprint_pipeline
  - task_contracts
  - qa_evidence
  - review_gates
  - postgres_gate
decisions:
  - A implementacao atual deve ser tratada como base forte por task, nao produto final completo.
  - A proxima fronteira e pipeline project-level e comandos dedicados de QA, review e Postgres.
maintenance:
  - Atualizar sempre que uma fase for concluida.
  - Nao marcar item como completo sem teste, doc, CLI/API/app quando aplicavel e evidencia de uso.
related_paths:
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - docs/engineering-knowledge-base/engineering-blueprint-contracts.md
  - docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md
  - docs/engineering-knowledge-base/engineering-blueprint-runbook.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
---

# Atlas Engineering Blueprint Maturity And DoD

Este documento e a verdade operacional sobre o que ja existe, o que falta e o
que significa "completo" para os 7 itens.

## Resumo Executivo

O Atlas ja implementou uma base forte de Engineering Harness Runner:

- contratos por task;
- blueprint deterministico por task;
- snapshot congelado;
- evidencias;
- runs, attempts, controls, tests, scoring e replay;
- review findings;
- visual smoke;
- quality scan;
- Atlas-Bench;
- Knowledge Base e Code Intelligence;
- Super Tool Runtime fase 0.

O Engineering Blueprint System agora possui a primeira implementacao operacional
end-to-end do produto final. As lacunas restantes sao de maturacao de UX,
wireframe refs dedicados e calibracao com volume real.

- polimento do fluxo visual unificado Blueprint -> Run -> Review -> Evidence -> Promote;
- filtros globais de missing evidence/blocking gates no app;
- wireframe refs dedicados;
- calibracao de Atlas-Bench/Memory Delta com volume real de runs.

## Matriz Dos 7 Itens

| Item | Estado | Ja existe | Falta para completo |
|---|---|---|---|
| 1. Pipeline Produto -> Blueprint -> Fase -> Task -> QA | Implementado base | Project blueprint, prepare/create/validate/freeze, coverage validator, task generation, task blueprint, freeze, run, evidence | Maturar UX e calibrar com uso real |
| 2. Task com contrato forte | Quase completo | `EngineeringTaskContractService`, `atlas dev --task-id`, contract no prompt | Enforcement global, completion coverage por AC, UI completa do contrato |
| 3. Inventory, scenarios e wireframes | Implementado base | Inventory rico, screen states, API failure modes, scenario coverage blocking | Wireframe refs dedicados e baselines visuais por fluxo |
| 4. Contingency policy | Parcial | `contingency_policy` no blueprint/context pack | Eventos, escalation triggers, permissao/Constituicao, bloqueio automatico |
| 5. QA manual com evidencia | Implementado base | `atlas qa`, evidence API, manual_qa rica, app QA panel, test runs, visual smoke | Conectar screenshots reais do runtime visual direto no formulario |
| 6. Review profundo com confidence threshold | Implementado base | `confidence`, `category`, recommendation, `atlas review --deep`, review service dedicado | Ampliar fontes automatizadas de findings |
| 7. Postgres review / optimization gate | Implementado base | `PostgresEngineeringReviewService`, `atlas db review/explain`, checks deterministicos | EXPLAIN real contra conexoes alvo e historico de lock/table size |

## Fase 0 - Documentacao Canonica

Objetivo: garantir que o produto final esteja claro para humanos e IAs.

Status: implementado por esta familia de documentos.

DoD:

- `engineering-blueprint.md` existe;
- contracts, gates, runbook e maturity existem;
- plano raiz esta preservado em archive/source-material;
- README, START_HERE e capability matrix apontam para docs;
- Knowledge Base sync e Code Intelligence index rodam sem erro.

## Fase 1 - Project Blueprint Pipeline

Objetivo: mover o sistema de task-level para project-level.

Implementar:

- model/tabela para `atlas_engineering_project_blueprints` ou extensao segura
  que diferencie `project_blueprint` de `task_blueprint`;
- `EngineeringProjectBlueprintService`;
- `EngineeringBlueprintCoverageValidator`;
- comandos:
  - `atlas project blueprint prepare`;
  - `atlas project blueprint create`;
  - `atlas project blueprint validate`;
  - `atlas project blueprint freeze`;
- rotas API equivalentes;
- UI no app para revisar e congelar blueprint de projeto.

Testes:

- draft com campos minimos;
- freeze com hash deterministico;
- supersede/versionamento;
- bloqueio com inventory/scenario incompleto;
- API 422 para payload invalido;
- app mostra draft/frozen/stale.

DoD:

- um projeto novo consegue gerar blueprint completo sem criar codigo;
- freeze bloqueia lacunas criticas;
- blueprint congelado aparece no context pack de tasks derivadas.

## Fase 2 - Phase Plan E Task Generation

Objetivo: gerar tarefas executaveis a partir do blueprint.

Implementar:

- `EngineeringPhasePlannerService`;
- `EngineeringTaskGenerationService`;
- mapping `phase -> tasks -> acceptance criteria -> scenarios`;
- politica de nao sobrescrever task humana;
- comando `atlas project tasks generate`;
- tela de preview antes de criar tasks.

Testes:

- fase gera tasks pequenas e ordenadas;
- cada task recebe contract minimo;
- out-of-scope e dependencies sao preservados;
- tarefa UI recebe manual QA gate;
- tarefa migration recebe database review gate.

DoD:

- operador consegue sair de blueprint congelado para backlog tecnico com
  contratos fortes e rastreabilidade.

## Fase 3 - Inventory, Scenarios E Wireframes

Objetivo: tornar coverage de produto verificavel.

Implementar:

- schema `inventory_json`;
- schema `scenario_json`;
- suporte a `wireframe_refs` ou markdown resumido;
- validator de telas, estados, APIs, data entities e failure modes;
- painel app para visualizar inventory/scenarios.

Testes:

- screen sem estado obrigatorio falha;
- API sem failure path falha;
- scenario sem AC falha;
- UI visual exige screenshot/manual QA;
- wireframe ref ausente vira advisory ou blocking conforme risco.

DoD:

- toda mudanca user-facing tem cenarios e estados rastreaveis antes da execucao.

## Fase 4 - QA Manual Profissional

Objetivo: transformar QA manual em workflow estruturado.

Implementar:

- `EngineeringQaService`;
- `AtlasCliQaCommand`;
- alias `atlas qa`;
- evidence schema rico;
- app QA panel com passos, resultado real, screenshot, console/network e risk
  notes;
- persistencia em evidence/test runs/artifacts.

Testes:

- QA passed libera gate;
- QA failed bloqueia;
- missing screenshot bloqueia quando visual exige;
- `not_applicable` sem reason falha;
- API/app preservam metadata.

DoD:

- uma task visual nao pode ser concluida sem evidencia de QA ou justificativa
  humana rastreavel.

## Fase 5 - Deep Review Profissional

Objetivo: review nao ser opiniao solta.

Implementar:

- `EngineeringReviewService`;
- migration aditiva para `confidence` e `category` em review findings;
- `AtlasCliReviewDeepCommand`;
- alias `atlas review --deep`;
- thresholds no scoring;
- UI para triagem de findings.

Testes:

- P0 aberto bloqueia;
- P1 confidence >= 0.80 bloqueia;
- P2 nao bloqueia por padrao;
- `fixed`, `false_positive` e `accepted_risk` funcionam;
- finding inclui file/line/category/confidence.

DoD:

- review profundo pode ser auditado, reexecutado e usado pelo Harness como gate.

## Fase 6 - Postgres Engineering Review

Objetivo: banco ser revisado por regra propria.

Implementar:

- `PostgresEngineeringReviewService`;
- `AtlasCliDbReviewCommand`;
- `AtlasCliDbExplainCommand`;
- aliases `atlas db review` e `atlas db explain`;
- checks deterministicos de migrations, constraints, JSONB, timestamps, locks,
  raw SQL, backfills e query plans;
- evidence `database_review`;
- findings `migration_risk` e `data_integrity`.

Testes:

- migration destrutiva falha;
- FK sem indice falha ou advisory conforme risco;
- JSONB contrato sem check gera finding;
- `DB::unprepared` sem rollback falha;
- backfill sem chunking falha;
- explain com scan arriscado gera finding.

DoD:

- toda mudanca de banco relevante tem evidencia persistida e decisao explicavel.

## Fase 7 - App Produto Final

Objetivo: operador enxergar o ciclo inteiro sem endpoint manual.

Implementar no app:

- card de Blueprint na home/inbox/projetos;
- tela de projeto com blueprint draft/frozen/stale;
- botao `Rodar Harness` por task;
- detalhe completo de run;
- QA evidence form rico;
- review findings triage;
- Postgres gate panel;
- promover run para Atlas-Bench case;
- filtros de missing evidence e blocking gates.

Testes:

- freeze blueprint;
- run task pelo app;
- record evidence rico;
- stale blueprint visivel;
- finding triage;
- release mode bloqueia missing evidence.

DoD:

- operador comum consegue executar o fluxo sem conhecer endpoint ou SQL.

## Fase 8 - Atlas-Bench E Memory Delta

Objetivo: aprender com runs reais.

Implementar:

- promocao de run real para benchmark case;
- outcome por blueprint/task;
- comparacao provider/model/policy;
- memory delta com privacy guard;
- scorecard de evidence coverage e quality debt.

Testes:

- run promovido preserva contract/blueprint/evidence;
- outcome alimenta trends;
- memory delta nao salva segredo;
- context pack futuro recupera doc/code/memory refs corretos.

DoD:

- o Atlas melhora sua execucao com base em casos reais sem perder governanca.

## DoD Final Do Sistema

O Engineering Blueprint System esta completo quando:

1. project blueprint existe e e operavel no app/CLI/API;
2. blueprint so congela com coverage suficiente;
3. tasks sao geradas com contracts fortes;
4. Harness consome snapshot, contract, docs, code refs e memory refs;
5. QA manual e evidence automatica sao first-class;
6. review profundo tem confidence/category e bloqueio claro;
7. Postgres gate cobre schema, queries, locks e backfills;
8. app mostra status real de gates e missing evidence;
9. Atlas-Bench consegue usar runs reais como corpus;
10. Memory Core preserva aprendizados ratificados;
11. Code Intelligence liga docs aos services, comandos, rotas, migrations e
    testes;
12. testes automatizados cobrem cada gate bloqueante.

## Regra De Atualizacao

Quando uma fase sair de parcial para implementada:

1. atualizar esta matriz;
2. atualizar `engineering-blueprint-runbook.md` se houver comando/tela nova;
3. atualizar `super-tool-runtime-core.md` ou `programming-power-tools-catalog.md`
   quando a mudanca alterar tools, gates, recipes ou autoridade;
4. rodar sync/index-code;
5. registrar no final da PR ou changelog quais gates foram adicionados.
