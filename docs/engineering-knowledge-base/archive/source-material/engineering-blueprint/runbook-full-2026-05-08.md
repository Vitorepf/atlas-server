---
id: atlas-engineering-blueprint-runbook
type: engineering_knowledge
title: Atlas Engineering Blueprint Runbook
status: active
category: runbook
priority: 96
summary: Operacao diaria para preparar blueprint, congelar contrato, executar Harness, registrar evidencias, revisar gates e sincronizar a Knowledge Base.
tags:
  - atlas
  - engineering
  - runbook
  - cli
  - app
capabilities:
  - engineering_blueprint
  - task_contracts
  - qa_evidence
  - review_gates
  - context_pack_recall
decisions:
  - O operador deve ter caminho equivalente no app, CLI e API.
  - Comandos alvo devem ser implementados como wrappers sobre services testados.
  - Depois de mudar docs canonicos, sync e index-code sao obrigatorios.
maintenance:
  - Atualizar quando comandos, rotas, telas ou services mudarem.
  - Usar este runbook antes de implementar novas etapas do Blueprint System.
related_paths:
  - bin/atlas
  - app/Console/Commands/AtlasCliDevCommand.php
  - app/Console/Commands/AtlasEngineeringRunCommand.php
  - app/Http/Controllers/AtlasTaskController.php
  - app/Http/Controllers/EngineeringRunController.php
  - atlas-app/app/projects.tsx
  - atlas-app/app/engineering.tsx
---

# Atlas Engineering Blueprint Runbook

Este runbook descreve como operar e implementar o Engineering Blueprint System.

## Hoje: Fluxo Disponivel

### Pelo App

1. Abrir `Home > Projetos`.
2. Selecionar um projeto com `active_next_task`.
3. Abrir o painel `Engenharia`.
4. Revisar contrato, blueprint, gates e evidencias.
5. Congelar o blueprint da task.
6. Registrar evidencia manual quando necessario.
7. Abrir `Home > Atlas Engineering` para benchmarks, knowledge, tool runtime,
   replay e operacao de runs.

Limite atual: o app ainda precisa de fluxo unificado para iniciar run por task,
mostrar detalhe completo do blueprint, registrar QA rica e navegar por runs fora
do contexto de benchmark.

### Pelo CLI

Executar uma task com contrato e blueprint no prompt:

```bash
atlas dev --task-id=<task-id>
```

Executar pelo Harness Runner:

```bash
atlas engineering run --task-id=<task-id> --workspace=/Users/vitorepf/Develop/atlas/atlas-server --sandbox=worktree --auto-test
```

Reexecutar a partir de run existente:

```bash
atlas engineering replay --run-id=<run-id>
```

Sincronizar docs e codigo:

```bash
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune --workspace=/Users/vitorepf/Develop/atlas/atlas-server
```

### Pela API

Consultar pacote de engenharia:

```text
GET /tasks/{task}/engineering
```

Congelar blueprint:

```text
POST /tasks/{task}/engineering/blueprint/freeze
```

Registrar evidencia:

```text
POST /tasks/{task}/engineering/evidence
```

Criar run:

```text
POST /tasks/{task}/engineering/runs
```

Consultar run:

```text
GET /engineering/runs/{run}
```

## Alvo: Fluxo Completo De Produto

### 1. Preparar Blueprint De Projeto

Comando alvo:

```bash
atlas project blueprint prepare --project-id=<project-id>
```

Status: implementado como `atlas:project:blueprint:prepare`, API
`POST /projects/{project}/engineering/blueprint/prepare` e card Blueprint no
app de Projetos.

Responsabilidades:

- coletar objetivo, contexto, repositorios, riscos e constraints;
- ler project steps e tasks existentes;
- consultar Knowledge Base e Code Intelligence;
- produzir draft sem congelar;
- apontar campos ausentes.

Service alvo:

- `EngineeringProjectBlueprintService::prepare(Project $project, array $options)`.

Saida:

- JSON com draft, missing fields, suggested questions e risk profile.

### 2. Criar Blueprint De Projeto

Comando alvo:

```bash
atlas project blueprint create --project-id=<project-id> --from-intent=<file-or-text>
```

Status: implementado como draft deterministico a partir do estado do projeto,
steps, tasks e metadata canonica (`engineering_inventory`,
`engineering_data_model`, `engineering_risk_profile`).

Responsabilidades:

- gerar `objective`, `inventory`, `scenarios`, `data_model`, `data_flow`,
  `phase_plan`, `qa_plan`, `review_plan`, `postgres_plan` e contingency;
- nao executar codigo;
- salvar draft no Postgres com status `draft`;
- expor no app para revisao humana.

### 3. Validar Cobertura

Comando alvo:

```bash
atlas project blueprint validate --project-id=<project-id>
```

Status: implementado via `EngineeringBlueprintCoverageValidator`.

Responsabilidades:

- bloquear blueprint sem inventory minimo;
- detectar screen sem state ou scenario;
- detectar API sem failure path;
- detectar migration sem Postgres plan;
- detectar task sem AC ou DoD;
- retornar lista de missing evidence/gates.

Service alvo:

- `EngineeringBlueprintCoverageValidator`.

### 4. Congelar Blueprint

Comando alvo:

```bash
atlas project blueprint freeze --project-id=<project-id> --blueprint-version=<version>
```

Status: implementado. O contrato Artisan canonico usa `--blueprint-version`
porque Symfony reserva `--version`. O launcher `atlas` aceita `--version` como
alias humano e normaliza para `--blueprint-version` antes de chamar Artisan.

Responsabilidades:

- canonicalizar payload;
- calcular hash;
- versionar;
- marcar versoes antigas como `superseded`;
- emitir evento de auditoria;
- habilitar geracao de tasks.

Regra: freeze so passa se coverage validator passar ou se operador registrar
excecao com motivo.

### 5. Gerar Tasks

Comando alvo:

```bash
atlas project tasks generate --project-id=<project-id> --from-blueprint=<version>
```

Status: implementado via `EngineeringTaskGenerationService`, preservando tasks
humanas e criando `engineering_contract` forte em metadata.

Responsabilidades:

- transformar `phase_plan` em tasks pequenas;
- criar `engineering_contract` em metadata de cada task;
- mapear scenarios e ACs;
- definir gates obrigatorios;
- manter out-of-scope claro;
- nao sobrescrever tasks humanas sem confirmacao.

### 6. Rodar Task

Comando existente:

```bash
atlas engineering run --task-id=<task-id> --workspace=<repo> --sandbox=worktree --auto-test
```

Melhorias alvo:

- preflight mostra snapshot, staleness e gates;
- se blueprint esta stale, comando falha com instrucao;
- `--evidence-mode=release` exige QA/review/db completos;
- `--json` retorna run id, score, decision, gates e artifacts.

### 7. QA Manual

Comando alvo:

```bash
atlas qa --task-id=<task-id>
```

Status: implementado via `EngineeringQaService`, CLI, API e app.

Responsabilidades:

- listar cenarios e ACs que exigem QA;
- abrir ou informar URL/rota alvo;
- coletar passos, resultado real, screenshot, console/network e confidence;
- registrar evidence `manual_qa`;
- bloquear se status falhar.

No app, esse fluxo deve estar no painel da task e na tela Engineering.

### 8. Review Profundo

Comando alvo:

```bash
atlas review --deep --task-id=<task-id>
```

Status: implementado via `EngineeringReviewService`, findings com confidence,
category, evidence refs e recommendation.

Responsabilidades:

- ler patch, contract, blueprint, evidence e code refs;
- gerar findings estruturados;
- aplicar severity/confidence/category;
- persistir findings;
- bloquear P0/P1 conforme threshold.

### 9. Postgres Review

Comando alvo:

```bash
atlas db review --task-id=<task-id>
atlas db explain --task-id=<task-id>
```

Status: implementado via `PostgresEngineeringReviewService`, evidence
`database_review` e findings `migration_risk`/`data_integrity`.

Responsabilidades:

- detectar migrations e queries relevantes;
- rodar migration pretend quando aplicavel;
- verificar rollback, constraints, indices, JSONB, timezone, locks e raw SQL;
- gerar evidence `database_review`;
- registrar findings de `migration_risk` ou `data_integrity`.

### 10. Promover Para Atlas-Bench

Quando uma task real representa caso recorrente:

```bash
atlas engineering benchmark corpus refresh
```

Alvo do app:

- botao `Promover para benchmark case`;
- associar blueprint/contract/evidence ao case;
- registrar outcome real;
- comparar providers e perfis de harness.

### 11. Memory Delta

Depois de concluir:

- decisao arquitetural duravel vira doc ou ADR;
- feedback operacional vira Memory Core com privacy policy;
- padrao de codigo vira Code Intelligence/doc link;
- artifact bruto fica fora dos docs canonicos.

## Checklist Para Implementar Uma Nova Etapa

1. Criar service pequeno e testavel.
2. Criar rota API se o app precisa operar a etapa.
3. Criar comando CLI com `--json`.
4. Registrar alias em `bin/atlas`.
5. Atualizar types/client do app.
6. Atualizar UI apenas quando fluxo estiver claro.
7. Criar testes unitarios e feature.
8. Atualizar docs canonicos.
9. Rodar sync/index-code.
10. Atualizar capability matrix.

## Checklist Para Uma IA Comecar Trabalho

```bash
git status --short
atlas engineering knowledge status
atlas engineering knowledge code-status
atlas engineering knowledge show atlas-engineering-blueprint
atlas engineering knowledge show atlas-engineering-blueprint-contracts
atlas engineering knowledge show atlas-engineering-blueprint-quality-gates
```

Depois disso, ler os arquivos apontados por Code Intelligence antes de editar.

## Falha Segura

Se Postgres, Knowledge Base ou Code Intelligence estiverem indisponiveis, o
Harness pode executar task simples, mas deve registrar que rodou com contexto
degradado. Para mudancas de alto risco, contexto degradado deve bloquear modo
release.
