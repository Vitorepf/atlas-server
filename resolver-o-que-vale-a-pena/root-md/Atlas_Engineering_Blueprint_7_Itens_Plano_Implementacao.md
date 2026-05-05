> Cleanup status: archived.
> Canonical replacement: docs/engineering-knowledge-base/engineering-blueprint.md; docs/engineering-knowledge-base/engineering-blueprint-contracts.md; docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md; docs/engineering-knowledge-base/engineering-blueprint-runbook.md.
> Cleanup note: Historical blueprint implementation plan; a source-material copy is already preserved in the KB archive.

# Atlas Engineering Blueprint - Plano Profissional Dos 7 Itens

> Status em 2026-05-03: este arquivo foi preservado como material-fonte.
> A especificacao canonica operacional agora vive em
> `atlas-server/docs/engineering-knowledge-base/engineering-blueprint.md` e nos
> docs relacionados `engineering-blueprint-contracts.md`,
> `engineering-blueprint-quality-gates.md`,
> `engineering-blueprint-runbook.md` e
> `engineering-blueprint-maturity-dod.md`.

| | |
|---|---|
| Sistema | Atlas |
| Documento | Plano de implementacao dos aprendizados do SWE-ATLAS |
| Data | 1 de maio de 2026 |
| Status | Implementacao inicial em andamento |
| Fonte analisada | `syahiidkamil/Software-Engineer-AI-Agent-Atlas` |
| Decisao central | Absorver a disciplina de engenharia, nao a dependencia em Claude Code |

---

## 0. Decisao Executiva

O SWE-ATLAS tem valor real em engenharia, mas o valor nao esta no runtime. Ele e um scaffold de contexto para Claude Code. O nosso Atlas ja possui runtime superior: Laravel, Postgres, AI Gateway, providers plugaveis, traces, skills, tool runtime, permission engine, quality gate, mobile inbox e memoria.

A melhor versao profissional e implementar uma camada propria chamada:

**Atlas Engineering Blueprint**

Ela deve transformar projeto tecnico em artefatos executaveis antes do `atlas dev` escrever codigo:

```text
Project Intent
-> Engineering Blueprint
-> Inventory
-> Scenarios
-> Data Model / Data Flow
-> Phase Plan
-> Task Contracts
-> Dev Execution
-> QA Evidence
-> Review Gate
-> Memory Delta
```

Regra: o Atlas nao deve copiar `.claude/commands`. Deve converter o conceito em comandos, services, tabelas, skills e gates do proprio Atlas.

### 0.1 Implementado em 1 de maio de 2026

- Contrato tecnico nativo por tarefa via `EngineeringTaskContractService`.
- Blueprint deterministico com fases, matriz de aceite, inventario de cenarios, gates e contingencia via `EngineeringBlueprintService`.
- Artefato de execucao, historico de runs, evidencias e snapshot recalculado de gates via `EngineeringRunArtifactService`.
- API `GET /tasks/{task}/engineering` e `POST /tasks/{task}/engineering/evidence`.
- Integracao do `atlas dev --task-id` com contrato, blueprint, prompt e persistencia do artefato.
- Skill `engineering-blueprint` registrado no servidor e no vault.
- Painel no app de projetos para visualizar gates e registrar evidencia de engenharia.
- Tabela dedicada `atlas_engineering_evidence` com fallback em metadata para evidencias historicas.
- Evidencia `validation_evidence` automatica quando `atlas dev` registra resultados de teste.
- Tabela dedicada `atlas_engineering_blueprints` com snapshot congelado, hash de conteudo e versao.
- API e app conseguem fixar o blueprint da tarefa antes de registrar evidencias ou executar o `atlas dev`.

---

## 1. Arquitetura-Alvo

### 1.1 Novos Conceitos

| Conceito | Papel | Persistencia recomendada |
|---|---|---|
| Engineering Blueprint | Contrato congelado do que sera construido | tabela propria |
| Inventory | Telas, superficies, cenarios e estados que precisam existir | JSON versionado no blueprint |
| Scenario | Jornada com happy path, alternative paths e exception paths | JSON no blueprint, com snapshots markdown opcionais |
| Phase | Marco shippable com objetivo e criterios | `atlas_project_steps` ou nova camada sobre steps |
| Task Contract | Unidade executavel por `atlas dev` | `atlas_tasks.metadata.engineering_contract` |
| QA Case | Caso de teste manual/automado com passos e esperado | tabela propria |
| QA Run | Execucao com evidencia, screenshots, console e resultado | tabela propria |
| Review Finding | Achado com severidade, confianca e evidencia | `ai_quality_actions` ou tabela propria depois |

### 1.2 Tabelas Profissionais Recomendadas

**MVP rapido pode usar `metadata`; versao profissional deve ter tabelas.**

1. `atlas_engineering_blueprints`
   - `id`
   - `project_id`
   - `source_capture_id`
   - `status`: `draft | review | frozen | superseded | archived`
   - `version`
   - `objective_json`
   - `problem_json`
   - `prototype_json`
   - `prd_json`
   - `stack_json`
   - `architecture_json`
   - `inventory_json`
   - `data_model_json`
   - `data_flow_json`
   - `contingency_json`
   - `phase_plan_json`
   - `quality_report_json`
   - `content_hash`
   - `frozen_at`
   - `created_at`
   - `updated_at`

2. `atlas_engineering_test_cases`
   - `id`
   - `blueprint_id`
   - `project_id`
   - `task_id`
   - `scenario_id`
   - `case_code`
   - `type`: `smoke | happy_path | edge_case | regression | qa_manual`
   - `priority`
   - `preconditions_json`
   - `steps_json`
   - `expected_result`
   - `status`

3. `atlas_engineering_test_runs`
   - `id`
   - `test_case_id`
   - `trace_id`
   - `provider`
   - `runner`: `manual | playwright | shell | hybrid`
   - `status`: `passed | failed | blocked | skipped`
   - `viewport`
   - `evidence_json`
   - `screenshots_json`
   - `console_json`
   - `network_json`
   - `started_at`
   - `finished_at`

4. `atlas_engineering_review_findings`
   - `id`
   - `trace_id`
   - `task_id`
   - `file_path`
   - `line_start`
   - `line_end`
   - `severity`
   - `confidence`
   - `category`
   - `finding`
   - `evidence`
   - `status`: `open | accepted | false_positive | fixed | deferred`

---

## 2. Os 7 Itens E A Melhor Implementacao

## Item 1 - Pipeline Produto -> Blueprint -> Fase -> Task -> QA

### O que eles fazem bem

Eles nao deixam a IA sair codando. Primeiro o sistema entrevista, trava escopo, cria PRD, arquitetura, fases, tasks e testes.

### Melhor versao no Atlas

Criar um workflow nativo:

```bash
atlas project blueprint prepare
atlas project blueprint create
atlas project blueprint freeze
atlas project phase create
atlas project tasks generate
atlas dev --task=<task-id>
atlas qa --task=<task-id>
```

### Implementacao

1. Criar skill `engineering-blueprint`.
2. Criar service `EngineeringBlueprintService`.
3. Criar command `AtlasCliProjectBlueprintCommand`.
4. Criar endpoint opcional para app mobile revisar blueprint.
5. Ao congelar blueprint, gerar `AtlasProjectStep` e `AtlasTask`.

### Criterio de pronto

- Um projeto `technical_build` consegue sair de captura vaga para blueprint congelado.
- O `atlas dev` consegue receber uma task com contrato completo.
- O quality gate valida contra criterios de aceite, nao apenas contra diff/teste generico.

---

## Item 2 - Task Com Contrato Forte

### O que eles fazem bem

As tasks deles tem objetivo, contexto, escopo, fora de escopo, acceptance criteria, arquivos provaveis, dependencias, edge cases, testes e tamanho.

### Melhor versao no Atlas

Nao criar arquivos soltos como fonte primaria. Usar `AtlasTask` como entidade primaria e salvar contrato em:

```text
atlas_tasks.metadata.engineering_contract
```

Formato recomendado:

```json
{
  "contract_version": 1,
  "type": "feature|refactor|infra|bugfix|test|docs",
  "goal": "",
  "context": "",
  "in_scope": [],
  "out_of_scope": [],
  "acceptance_criteria": [],
  "likely_files": [],
  "patterns_to_follow": [],
  "patterns_to_avoid": [],
  "edge_cases": [],
  "dependencies": {
    "blocks": [],
    "blocked_by": []
  },
  "test_coverage": [],
  "estimated_size": "XS|S|M|L",
  "definition_of_done": []
}
```

### Implementacao

1. Adicionar helper em `ProjectExecutionService` ou novo `EngineeringTaskContractService`.
2. Gerar contrato ao aceitar blueprint.
3. Alterar `AtlasCliDevWorkflowService` para buscar contrato por `--task`.
4. Alterar `AtlasCliQualityService` para checar criterios de aceite registrados.

### Criterio de pronto

- Nenhuma task tecnica pode ser marcada como pronta sem contrato minimo.
- `atlas dev --task=<id>` injeta goal, scope, out-of-scope, criteria e tests no prompt.
- O completion packet aponta quais criterios foram atendidos.

---

## Item 3 - Inventory, Scenarios E Wireframes

### O que eles fazem bem

Eles forcam cobertura de telas, estados e jornadas antes da implementacao. Isso evita feature incompleta.

### Melhor versao no Atlas

Transformar isso em `inventory_json` dentro do blueprint:

```json
{
  "screens": [
    {
      "slug": "",
      "purpose": "",
      "roles": [],
      "states": ["default", "loading", "empty", "error", "auth_gated"],
      "interactions": []
    }
  ],
  "scenarios": [
    {
      "id": "SC-001",
      "title": "",
      "actor": "",
      "preconditions": [],
      "trigger": "",
      "main_flow": [],
      "alternative_flows": [],
      "exception_flows": [],
      "postconditions": []
    }
  ]
}
```

Wireframes devem existir em duas formas:

- JSON estruturado para runtime.
- Markdown/ASCII exportado para leitura humana e Vault.

### Implementacao

1. `EngineeringBlueprintService::buildInventory`.
2. `EngineeringBlueprintService::validateCoverage`.
3. Export opcional para `AtlasVault/engineering-blueprints/{project-slug}/`.
4. App mobile pode mostrar inventory como checklist de cobertura.

### Criterio de pronto

- Toda tela tem estados obrigatorios.
- Todo scenario tem happy path, pelo menos um alternative path e exception flows quando aplicavel.
- Toda task gerada referencia scenario ou acceptance criteria.

---

## Item 4 - Contingency Policy

### O que eles fazem bem

Eles criam uma politica para quando o blueprint nao especifica uma microdecisao. Isso e superior a deixar o agente improvisar.

### Melhor versao no Atlas

Integrar a Contingency Policy com a Constituicao do Atlas e com permissao.

Categorias obrigatorias:

- UX defaults
- Data defaults
- Error defaults
- Performance defaults
- Security defaults
- Accessibility defaults
- Escalation triggers
- Decision log

### Implementacao

1. Salvar `contingency_json` no blueprint.
2. Injetar no `ContextPack` quando `atlas dev` usar task do blueprint.
3. Criar evento `contingency_decision_applied` em `ai_tool_events` ou `atlas_project_events`.
4. Bloquear execucao quando a decisao cair em escalation trigger.

### Criterio de pronto

- O provider recebe politica curta e objetiva.
- Toda decisao tomada por default fica registrada.
- Auth, PII, schema destrutivo, credenciais e escopo expandido acionam parada.

---

## Item 5 - QA Manual Com Evidencia

### O que eles fazem bem

QA nao e opiniao. Eles documentam passos, esperado, real, screenshots, console e severidade.

### Melhor versao no Atlas

Criar `atlas qa` como workflow oficial. Ele deve usar browser quando disponivel e persistir evidencias.

```bash
atlas qa --task=<task-id>
atlas qa --case=TC-001
atlas qa --latest --viewport=mobile
```

### Implementacao

1. Criar skill `qa-manual-tester` ou expandir `ui-verification`.
2. Criar service `EngineeringQaService`.
3. Criar command `AtlasCliQaCommand`.
4. Integrar com Playwright ou browser plugin quando disponivel.
5. Persistir em `atlas_engineering_test_runs`.

### Criterio de pronto

- Cada QA run gera status, passos, evidencia e screenshots quando aplicavel.
- Bugs viram `atlas_engineering_review_findings` ou `AtlasProjectBlocker`.
- `atlas dev --complete` pode chamar QA quando task tiver UI scenario.

---

## Item 6 - Review Profundo Com Confidence Threshold

### O que eles fazem bem

Review nao e lista de palpites. Eles filtram falso positivo com score de confianca e so reportam achado forte.

### Melhor versao no Atlas

Criar dois modos:

```bash
atlas review
atlas review --deep
```

`atlas review` continua direto e pragmático.  
`atlas review --deep` usa lentes separadas:

1. diff-only bug scan;
2. architecture/context scan;
3. historical/context scan quando Git permitir;
4. tests/coverage scan;
5. security/privacy scan quando tocar auth, dados ou tool runtime.

Cada finding recebe:

```json
{
  "severity": "P0|P1|P2|P3",
  "confidence": 0.0,
  "category": "bug|security|architecture|test_gap|regression",
  "evidence": "",
  "file": "",
  "line": null
}
```

### Implementacao

1. Expandir skill `code-reviewer`.
2. Criar `EngineeringReviewService`.
3. Persistir findings.
4. Fazer `AtlasCliQualityService` considerar findings P0/P1 como gate failure.

### Criterio de pronto

- Findings abaixo de 0.80 nao bloqueiam.
- P0/P1 com confidence >= 0.80 bloqueia completion.
- Review final diferencia bug real, risco residual e teste faltante.

---

## Item 7 - Postgres Review / Optimization Gate

### O que eles fazem bem

Eles tem checklist especifico para Postgres: JSONB, GIN/GiST, constraints, TIMESTAMPTZ, RLS, indexes, EXPLAIN, pg_stat_statements.

### Melhor versao no Atlas

Como nosso backend usa PostgreSQL, JSONB, UUID, pgvector e migrations SQL raw, isso deve virar gate tecnico oficial:

```bash
atlas db review
atlas db review --changed
atlas db explain "<query>"
```

### Implementacao

1. Criar skill `postgres-review`.
2. Criar service `PostgresEngineeringReviewService`.
3. Gate automatico quando diff tocar:
   - `database/migrations`
   - models com casts JSON
   - queries complexas
   - indices
   - constraints
   - `DB::unprepared`
4. Checks iniciais deterministicos:
   - migration destrutiva sem backfill/plano;
   - JSONB sem constraint quando campo vira contrato;
   - coluna temporal sem timezone;
   - FK sem indice relevante;
   - query paginada com offset grande;
   - indice ausente em lookup frequente;
   - `DB::unprepared` sem rollback coerente.

### Criterio de pronto

- Diff com migration roda `atlas db review`.
- Findings de schema destrutivo bloqueiam sem aprovacao humana.
- O review recomenda indice/constraint com justificativa.

---

## 3. Sequencia De Implementacao Recomendada

### Fase A - Base De Contratos

1. Criar skill `engineering-blueprint`.
2. Criar `engineering_contract` em `AtlasTask.metadata`.
3. Criar service para gerar task contracts a partir de projeto/step.
4. Alterar `atlas dev` para aceitar `--task=<id>`.

Entrega: `atlas dev` passa a trabalhar com task estruturada.

### Fase B - Blueprint Persistente

1. Criar tabela `atlas_engineering_blueprints`.
2. Criar command `atlas project blueprint`.
3. Gerar blueprint draft a partir de `AtlasProject`.
4. Congelar blueprint e materializar steps/tasks.

Entrega: projeto tecnico vira plano executavel.

### Fase C - Coverage E Contingency

1. Adicionar `inventory_json`.
2. Adicionar `scenarios`.
3. Adicionar `contingency_json`.
4. Criar validator de coverage.

Entrega: blueprint nao congela com lacunas obvias.

### Fase D - QA Evidence

1. Criar tabelas de test cases/runs.
2. Criar `atlas qa`.
3. Integrar screenshots/console quando browser estiver disponivel.
4. Bugs viram blocker/finding.

Entrega: UI e fluxos passam por evidencia, nao por declaracao.

### Fase E - Review Profundo E Postgres Gate

1. Criar findings persistidos.
2. Implementar `atlas review --deep`.
3. Implementar `atlas db review`.
4. Integrar ambos ao quality gate.

Entrega: `atlas dev --complete` so conclui com evidencia forte.

---

## 4. Versao Profissional Do Fluxo Final

```bash
# 1. Criar projeto tecnico
atlas project create "Mobile inbox diff review"

# 2. Preparar blueprint
atlas project blueprint prepare --project=<id>

# 3. Gerar blueprint
atlas project blueprint create --project=<id>

# 4. Congelar depois de revisao humana
atlas project blueprint freeze --project=<id>

# 5. Gerar tasks tecnicas
atlas project tasks generate --project=<id>

# 6. Executar primeira task
atlas dev --task=<task-id> --complete --auto-test

# 7. Rodar QA se tiver UI
atlas qa --task=<task-id>

# 8. Review profundo se tocar area critica
atlas review --deep

# 9. Gate final
atlas quality --run-tests --yes
```

---

## 5. Diferenca Entre Copia E Absorcao Correta

| SWE-ATLAS | Atlas profissional |
|---|---|
| `.claude/commands` | comandos `atlas` provider-neutral |
| markdown solto como fonte primaria | Postgres como fonte primaria + Vault como snapshot humano |
| Claude Code como superficie | Atlas CLI/TUI/App como superficie |
| Ralph Loop | `atlas dev --complete` com traces e permission engine |
| task markdown | `AtlasTask` com `engineering_contract` |
| QA em pasta | QA run persistido com evidencia e inbox |
| review por subagents | review service com findings, confidence e quality gate |

---

## 6. Conclusao

Os 7 itens devem virar uma camada nova do nosso Atlas, nao uma copia do repo externo.

A implementacao profissional e:

1. Blueprint persistente.
2. Task contract estruturado.
3. Coverage de telas/cenarios/estados.
4. Contingency policy ligada a Constituicao.
5. QA evidence persistido.
6. Deep review com confidence threshold.
7. Postgres gate especializado.

Isso fecha a lacuna principal do nosso `atlas dev`: hoje ele ja executa, valida e registra melhor que o SWE-ATLAS; depois dessa camada, ele tambem vai planejar e definir "pronto" melhor.
