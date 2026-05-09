---
id: atlas-engineering-blueprint-contracts
type: engineering_knowledge
title: Atlas Engineering Blueprint Contracts
status: active
category: contracts
priority: 97
summary: Contratos canonicos de blueprint, task contract, inventory, scenarios, evidencias, review findings e Postgres gates do Atlas Engineering Blueprint System.
tags:
  - atlas
  - engineering
  - contracts
  - schema
capabilities:
  - engineering_blueprint
  - task_contracts
  - scenario_inventory
  - qa_evidence
  - review_gates
  - postgres_gate
decisions:
  - Contratos operacionais vivem em Postgres e payloads versionados.
  - Markdown canonico descreve schema, invariantes e evolucao esperada.
  - Task contract e blueprint snapshot entram no context pack como fontes obrigatorias para execucao autonoma.
maintenance:
  - Atualizar quando payloads, migrations, models, controllers ou app types mudarem.
  - Toda alteracao de schema precisa teste API/CLI e update em Code Intelligence.
related_paths:
  - app/Services/Engineering/EngineeringTaskContractService.php
  - app/Services/Engineering/EngineeringBlueprintService.php
  - app/Services/Engineering/EngineeringBlueprintSnapshotService.php
  - app/Services/Engineering/EngineeringRunArtifactService.php
  - app/Models/AtlasEngineeringBlueprint.php
  - app/Models/AtlasEngineeringEvidence.php
  - app/Models/AtlasEngineeringReviewFinding.php
  - atlas-app/lib/api/client.ts
---

# Atlas Engineering Blueprint Contracts

Este documento define os contratos que uma IA ou manutentor deve respeitar ao
implementar o Engineering Blueprint System.

## Fontes De Verdade

| Tipo de informacao | Fonte primaria | Motivo |
|---|---|---|
| Decisao, regra e arquitetura | Docs nesta Knowledge Base | Versionado, reviewavel e indexavel |
| Blueprint congelado | Postgres `atlas_engineering_project_blueprints` e `atlas_engineering_blueprints` | Estado operacional e historico por projeto/task |
| Evidencia de QA/review/db | Postgres `atlas_engineering_evidence`, test runs e artifacts | Auditavel e consultavel pelo app/API |
| Runs, attempts e scoring | Tabelas do Engineering Harness Runner | Necessario para replay, benchmark e score |
| Localizacao de codigo | Code Intelligence Index | Liga docs a simbolos reais |
| Memoria reutilizavel | Memory Core | Recall futuro com politica de privacy |

## Project Engineering Blueprint

O produto final precisa de um blueprint no nivel de projeto, acima do blueprint
por task que ja existe.

Implementacao atual: `atlas_engineering_project_blueprints` guarda draft/frozen,
`content_hash`, `version`, `validation_json`, excecao humana e supersede. O
payload vive em `blueprint_json` no schema
`atlas.engineering.project_blueprint.v1`.

Campos minimos:

```json
{
  "schema_version": "atlas.engineering.project_blueprint.v1",
  "blueprint_id": "proj_bp_...",
  "project_id": 123,
  "status": "draft|frozen|superseded|archived",
  "version": 1,
  "content_hash": "sha256...",
  "created_by": "operator|atlas_ai|import",
  "objective": {
    "problem": "Problema de produto ou engenharia",
    "desired_outcome": "Resultado observavel",
    "non_goals": ["O que nao sera feito"],
    "success_metrics": ["Como medir sucesso"]
  },
  "product_context": {
    "users": ["operador", "cliente", "agente"],
    "workflows": ["fluxo principal"],
    "constraints": ["latencia", "seguranca", "custo"]
  },
  "technical_context": {
    "repositories": ["atlas-server", "atlas-app"],
    "systems": ["Laravel", "Expo", "Postgres"],
    "dependencies": ["servicos ou libs relevantes"],
    "risk_profile": "low|medium|high|critical"
  },
  "inventory": {},
  "scenarios": [],
  "data_model": {},
  "data_flow": {},
  "phase_plan": [],
  "task_generation_policy": {},
  "qa_plan": {},
  "review_plan": {},
  "postgres_plan": {},
  "contingency_policy": {},
  "memory_policy": {}
}
```

Invariantes:

- `content_hash` deve ser deterministico para o payload canonicalizado.
- `frozen` nao pode ser alterado; nova mudanca cria versao.
- `superseded` aponta para a versao atual.
- Blueprint de projeto deve gerar ou atualizar tasks, nunca sobrescrever trabalho
  humano sem revisao.
- Freeze deve bloquear se `inventory`, `scenarios`, `phase_plan` ou gates
  obrigatorios estiverem incompletos.

## Task Engineering Blueprint

O blueprint por task ja existe em `EngineeringBlueprintService`. Ele deve ser o
recorte executavel do blueprint de projeto.

Campos atuais essenciais:

```json
{
  "schema_version": "atlas.engineering.blueprint.v1",
  "blueprint_id": "eng_...",
  "source": "task_contract",
  "generated_at": "2026-05-03T00:00:00Z",
  "objective": {},
  "phases": [],
  "task_contract_refs": {},
  "acceptance_matrix": [],
  "scenario_inventory": [],
  "review_gates": [],
  "contingency_policy": {}
}
```

Alvo profissional:

- manter compatibilidade com o schema atual;
- adicionar referencia ao `project_blueprint_id` quando existir;
- carregar `scenario_refs`, `data_model_refs` e `qa_case_refs`;
- sinalizar `stale=true` quando contract, task metadata ou blueprint de projeto
  mudarem depois do freeze;
- expor `blocking_gates` e `missing_evidence` diretamente para app/API.

## Engineering Task Contract

Contrato minimo por task:

```json
{
  "contract_version": "atlas.engineering.task_contract.v1",
  "type": "feature|bugfix|refactor|migration|test|docs",
  "goal": "Objetivo tecnico especifico",
  "context": "Contexto suficiente para executar sem conversa anterior",
  "in_scope": ["Itens permitidos"],
  "out_of_scope": ["Itens proibidos"],
  "acceptance_criteria": [
    {
      "id": "ac_1",
      "statement": "Comportamento esperado",
      "verification_method": "test|manual_qa|database_review|deep_code_review"
    }
  ],
  "likely_files": ["app/Services/..."],
  "allowed_paths": ["app/", "tests/"],
  "strict_file_scope": false,
  "patterns_to_follow": ["Padroes locais"],
  "patterns_to_avoid": ["Anti-padroes"],
  "edge_cases": ["Caso limite"],
  "dependencies": ["Task ou decisao relacionada"],
  "test_coverage": ["Teste esperado"],
  "definition_of_done": ["DoD verificavel"],
  "refs": {
    "project_blueprint_id": "proj_bp_...",
    "knowledge_refs": [],
    "code_refs": []
  }
}
```

Invariantes:

- `goal`, `acceptance_criteria` e `definition_of_done` nao podem estar vazios.
- Task tecnica `ready/resolved` precisa ter contrato minimo validado.
- `out_of_scope` deve ser injetado no prompt do provider.
- `strict_file_scope=true` deve bloquear patch fora do escopo, salvo aprovacao
  humana registrada.

## Inventory

O inventory transforma produto em superficie verificavel.

Estrutura alvo:

```json
{
  "screens": [
    {
      "id": "screen_inbox",
      "name": "Inbox operacional",
      "route": "/inbox",
      "states": ["empty", "loading", "ready", "error", "offline"],
      "interactions": ["open_task", "complete_task"],
      "visual_requirements": ["responsive", "no_overlap", "stable_toolbar"]
    }
  ],
  "api_surfaces": [
    {
      "id": "api_task_engineering",
      "route": "GET /tasks/{task}/engineering",
      "consumers": ["atlas-app", "CLI", "automation"],
      "failure_modes": ["404", "422", "auth"]
    }
  ],
  "data_entities": [
    {
      "id": "atlas_engineering_blueprints",
      "owner": "Engineering Blueprint",
      "risks": ["stale_snapshot", "hash_mismatch"]
    }
  ],
  "external_tools": [
    {
      "id": "playwright",
      "purpose": "visual smoke and browser evidence",
      "cost": "free/local"
    }
  ]
}
```

Freeze project-level deve exigir inventory quando a mudanca tocar UI, API,
schema, workflow operacional ou integracao externa.

## Scenarios

Scenario e a ponte entre criterio de aceite e evidencia.

```json
{
  "id": "scenario_manual_qa_blueprint_panel",
  "type": "happy_path|alternative|exception|regression|visual",
  "title": "Operador congela blueprint e registra evidencia",
  "preconditions": ["task existe", "blueprint gerado"],
  "steps": [
    "abrir /projects",
    "selecionar projeto",
    "abrir painel Engenharia",
    "congelar blueprint"
  ],
  "expected_result": "snapshot congelado aparece com versao e hash",
  "acceptance_refs": ["ac_1"],
  "evidence_required": ["manual_qa", "screenshot"],
  "risk": "medium"
}
```

Regras:

- UI com risco medio/alto precisa de scenario visual.
- API com mudanca de contrato precisa de scenario de erro.
- Migration precisa de scenario de rollback ou justificativa.
- Toda task gerada a partir de projeto deve referenciar pelo menos um scenario
  ou acceptance criterion.

## QA Evidence

Evidencia manual ou automatica deve ser estruturada:

```json
{
  "evidence_type": "acceptance|scenario|validation_evidence|manual_qa|deep_code_review|database_review",
  "target_id": "ac_1|scenario_id|manual_qa|database_review",
  "status": "passed|failed|needs_review|not_applicable",
  "confidence": 0.92,
  "summary": "O que foi verificado",
  "source": "operator|harness|playwright|quality_scan|review|postgres_gate",
  "trace_id": "trace_...",
  "command": "npm run typecheck",
  "artifact_url": "artifacts/...",
  "output_excerpt": "Trecho curto sem segredo",
  "files": ["app/..."],
  "metadata": {
    "screenshots": [],
    "console_errors": [],
    "network_failures": [],
    "risk_notes": []
  }
}
```

Regras:

- `failed` ou `needs_review` bloqueia gate correspondente.
- `not_applicable` exige justificativa em `metadata.reason`.
- Evidencia visual deve preferir screenshot/trace; texto puro e aceitavel apenas
  quando o alvo nao tem superficie visual.
- `output_excerpt` deve ser curto e redigido quando houver segredo.

## Review Finding

Finding profissional precisa ser mais rico que severidade.

Campos alvo:

```json
{
  "severity": "P0|P1|P2|P3",
  "confidence": 0.86,
  "category": "correctness|security|data_integrity|performance|ux|test_gap|maintainability",
  "status": "open|fixed|false_positive|accepted_risk",
  "title": "Resumo curto",
  "body": "Explicacao objetiva",
  "file": "app/Services/...",
  "start_line": 10,
  "end_line": 12,
  "evidence_refs": ["test_run_id", "artifact_id"],
  "recommendation": "Acao concreta"
}
```

Gate:

- `P0` ou `P1` aberto com `confidence >= 0.80` bloqueia conclusao.
- `accepted_risk` exige aprovacao humana, justificativa e data.
- `false_positive` exige resumo do motivo.

## Postgres Review

Contrato alvo para database review:

```json
{
  "gate_id": "database_review",
  "required": true,
  "triggers": [
    "migration_created",
    "schema_changed",
    "db_unprepared",
    "query_changed",
    "index_or_constraint_changed",
    "backfill_or_destructive_change"
  ],
  "checks": [
    "rollback_safe",
    "jsonb_contract",
    "timestamptz_policy",
    "fk_index",
    "unique_constraint",
    "large_table_strategy",
    "explain_plan",
    "lock_risk"
  ],
  "status": "passed|failed|needs_review",
  "confidence": 0.86,
  "findings": []
}
```

Mudancas destrutivas, locks longos, backfills sem estrategia e `DB::unprepared`
sem rollback coerente devem exigir aprovacao humana.

## Context Pack

O context pack para task de engenharia deve conter:

- `task_contract`;
- `engineering_blueprint`;
- `engineering_blueprint_snapshot` quando existir;
- `knowledge_refs` incluindo estes docs quando a tarefa tocar planejamento,
  QA, review ou Postgres;
- `code_refs` para services, routes, migrations, tests e app surfaces;
- `tool_evidence_refs` quando Super Tool Runtime tiver findings relevantes;
- `memory_refs` ratificadas e seguras para provider.

## Compatibilidade

Novos campos devem ser adicionados de forma aditiva. O app, CLI e API precisam
tratar campos ausentes com fallback porque runs antigos e snapshots antigos
continuarao existindo.
