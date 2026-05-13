---
id: atlas-programming-governance-system-runbook
type: engineering_knowledge
title: Atlas Programming Governance System Runbook
status: building
category: programming-governance
priority: 98
summary: Runbook de programacao governada por IA: arquitetura alvo, fluxo integrado, acceptance, runner gateway, evidence, repair, DoD e gaps.
tags:
  - atlas
  - programming
  - governance
  - runbook
capabilities:
  - programming_governance_runbook
  - executable_acceptance
  - programming_repair_loop
  - programming_cartography
decisions:
  - Fluxo compacto e permitido para mudanca pequena, mas ownership e evidence continuam obrigatorios.
  - Mudanca estrutural usa fluxo completo com spec, delta, task contract, tests, docs, index e cartografia quando afetados.
maintenance:
  - Atualize quando fluxo, arquitetura alvo, runners, repair ou DoD mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md
  - docs/engineering-knowledge-base/atlas-code-long-session-programming-cockpit.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-programming-governance-system-runbook

graph_title: Atlas Programming Governance System Runbook

graph_world: atlas

graph_layer: system

graph_kind: runbook

graph_parent: atlas-programming-governance-system

graph_status: building

graph_source: repo

owner: programming

repo_paths:
  - docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md

allowed_changes:
  - Atualizar runbook quando fluxo operacional de programacao mudar.

forbidden_changes:
  - Tratar este runbook futuro como runtime entregue.
  - Pular evidence, docs ou index quando afetados.

depends_on:
  - atlas-programming-governance-system
  - atlas-programming-governance-system-contracts
  - code-intelligence

flows_to:
  - atlas-forge-operating-system
  - atlas-code

unlocks:
  - programming-governance-operational-context

governs:
  - programming.runbook

evidence:
  - docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - programming
  - runbook
  - evidence

ai_entrypoints:
  - Leia este doc antes de implementar fluxo, runner ou completion gate de programacao.

ai_usage_notes:
  - Este runbook orienta implementacao futura; validacao real depende de codigo e testes.

quality_gates:
  - docs-health
  - executable-acceptance
  - evidence-ledger
  - cartography-update

failure_modes:
  - Fluxo compacto virar ausencia de governanca.
  - Repair apagar evidence.
  - Runner virar autoridade sem contrato.

observability_signals:
  - runner receipts
  - evidence events
  - docs/index refresh
  - cartography links

next_actions:
  - Converter partes deste runbook em APs quando a implementacao entrar no caminho critico.
---
# Atlas Programming Governance System Runbook

## Resumo

Este documento define o fluxo operacional de programacao governada. Contratos
vivem em `atlas-programming-governance-system-contracts.md`; o indice canonico
vive em `atlas-programming-governance-system.md`.

## Papel no Atlas

Transformar os contratos de programacao em execucao verificavel: intake,
placement, spec, task, runner, acceptance, evidence, docs, index, cartografia e
learning.

## Onde Se Encaixa

Este runbook opera abaixo de Knowledge Governance e acima de Forge OS. Ele
orienta Programming Domain, Self-Construction e agentes quando ha codigo,
review, repair ou refactor.

## Contratos

- Mudanca pequena usa fluxo compacto com evidence proporcional.
- Mudanca estrutural usa fluxo completo.
- Brownfield usa delta explicito.
- Repair preserva evidence e gera learning quando houver repeticao.
- Cartografia e docs sao obrigatorias quando afetadas.

## Fluxo

Fluxo completo:

```text
1. Intake
2. Sovereign/Epistemic preflight se houver autonomia, risco ou duvida de verdade
3. Feature Placement
4. Code Intelligence Context Pack
5. Canonical Spec
6. Change Delta se brownfield
7. Acceptance Criteria
8. Task Contract
9. Agent Role Assignment
10. Implementation Plan
11. Runner/Tool Execution
12. Patch
13. Executable Acceptance / Tests
14. Evidence Ledger
15. Docs Update
16. Code Intelligence Refresh
17. Cartography Update
18. Learning Proposal
19. Completion Gate
```

Fluxo compacto:

```text
1. Placement rapido
2. Contexto minimo
3. Contrato compacto
4. Patch
5. Teste/comando proporcional
6. Evidence final
```

## Regras para IA

- Nao usar fluxo compacto para mudanca estrutural.
- Nao deixar runner/tool decidir politica.
- Nao fechar acceptance critica sem teste, comando ou gap explicito.
- Nao atualizar codigo e esquecer docs/index/cartografia quando afetados.

## Escopo de Implementacao

Runbook cobre gates e fluxo para code generation, review, repair, refactor,
tool execution, executable acceptance, evidence, docs/index refresh e
cartography publishing.

Quando o fluxo aparecer no Atlas Code, ele deve materializar o contrato de
sessao longa: Context Pack visivel, Spec/Plan/Tasks vivos, Task Contract,
Checkpoint/Resume, Diff/Scope Guard, Verify Gate Runner, Evidence Ledger,
Long Session Memory, Failure/Repair Loop e Cartografia de Execucao. A barra SDD
nao e suficiente sem esses objetos operacionais.

## Sintese Das Ferramentas Dissecadas

| Ferramenta | Valor absorvido pelo Atlas | Onde entra |
|---|---|---|
| Spec Kit | Constitution, spec, plan, tasks | Spec, tasks, completion gate |
| OpenSpec | Delta specs brownfield | Change Delta System |
| BMAD-METHOD | Papeis e handoffs | Agent Role Lattice |
| Goose | Runtime/tool/MCP agnostico | Runner And Tool Gateway |
| Aider | Repo-aware patch loop | Patch Discipline, Repair Loop |
| Tessl SDD Tile | Spec anchoring | Spec-as-Source |
| Reqnroll | BDD executavel | Behavior Gates |
| Gauge | Executable Markdown, runners, events, rerun failed | Evidence Events, Rerun |

## Arquitetura Final Obrigatoria

Subsistemas alvo:

- Canonical Spec Engine;
- Change Delta System;
- Agent Role Lattice;
- Code Intelligence Context Layer;
- Task Contract Engine;
- Executable Acceptance Layer;
- Runner And Tool Gateway;
- Evidence Ledger;
- Refactor And Repair Governance;
- Cartography Publishing Contract.

## Executable Acceptance Layer

Formatos aceitos:

- BDD/Gherkin quando o dominio pedir linguagem de negocio;
- Markdown executable spec quando o Atlas quiser fluxo livre;
- teste unitario/integracao/e2e quando a prova for codigo;
- check CLI quando a prova for comando/gate.

Regra critica: acceptance textual sem evidence e gap, nao sucesso.

## Runner And Tool Gateway

Runners canonicos:

- `codex_runner`;
- `claude_runner`;
- `local_tool_runner`;
- `test_runner`;
- `static_analysis_runner`;
- `docs_health_runner`;
- `code_index_runner`;
- `cartography_renderer`;
- `bdd_runner`;
- `evidence_writer`.

Cada runner declara capacidade, timeout, permissao e evidence.

## Refactor And Repair Governance

Fluxo:

```text
detectar falha
-> classificar tipo
-> criar repair spec/delta
-> montar context pack
-> aplicar patch pequeno
-> verificar
-> registrar evidence
-> propor learning
```

Repair nao apaga evidence; refactor preserva comportamento ou declara delta.

## Definition Of Done Canonico

Uma mudanca governada esta concluida quando:

- intencao foi registrada;
- local correto foi identificado;
- contexto de codigo foi consultado quando necessario;
- spec existe antes do codigo para mudanca estrutural;
- delta existe para mudanca brownfield;
- task contract limitou arquivos e risco;
- patch ficou dentro do contrato ou teve excecao explicita;
- acceptance criteria foram verificados;
- testes/gates proporcionais rodaram;
- evidence ledger recebeu receipt;
- docs foram atualizadas quando afetadas;
- Code Intelligence foi atualizado quando afetado;
- cartografia foi atualizada quando afetada;
- learning foi registrado quando houve aprendizado;
- risco residual esta claro.

## Gaps Que Nao Podem Ser Esquecidos

- parser/AST para docs canonicos;
- delta governance;
- constitution/principles;
- roles e handoffs;
- runner/tool gateway;
- patch discipline;
- executable acceptance;
- spec-as-source;
- JSON/evidence events;
- rerun failed;
- refactor docs-code;
- context packing;
- provider-agnostic execution;
- cartography as operational graph.

## Dependencias

- Programming Domain;
- Spec Operating System;
- Code Intelligence;
- Evidence Ledger;
- Tool Runtime;
- Forge OS;
- Cartographic Knowledge OS.

## Evidencias

Evidencias aceitas: placement, context pack, spec/delta, task contract, runner
receipt, test output, diff, docs-health, index-code, cartography artifact,
learning proposal e completion gate.

## Riscos

- Fluxo compacto ocultar risco estrutural.
- Runner produzir log sem schema.
- Code Intelligence stale.
- Acceptance sem prova.
- Repair repetido sem learning.

## Exemplos

Valido: mudanca brownfield com delta MODIFIED, context pack, task contract,
testes, docs afetadas e index-code.

Invalido: refatorar modulo central sem delta, sem affected tests e sem evidence.

## Implementacao Atual (Thin Slice)

Status: building. Fluxo end-to-end testavel ja vive sob `atlas:programming:*`.
Forge OS, plan/tasks autogeneration, cartografia automatizada e learning loop
permanecem como gaps registrados em cada work item.

### Comandos canonicos

| Comando | Papel |
|---|---|
| `atlas:programming:intake` | Recebe intencao, classifica (intent_type, scope_mode, risk_level), cria `AtlasProgrammingWorkItem`. `--workspace` ancora o ledger e o scope-guard a um caminho real |
| `atlas:programming:spec` | Anexa spec canonico (objetivo, contexto, comportamento, arquivos, riscos, testes, evidence, rollback, criterios). Hash `spec_hash` persistido. Rejeita spec retroativa |
| `atlas:programming:spec-compile` | Sintetiza um draft de spec a partir de intake + placement + Code Intelligence. Sem LLM. `--critique` retorna o relatorio do critic; `--attach` persiste o draft (bloqueado por critic salvo `--force`) |
| `atlas:programming:plan` | Anexa plan + task contracts (allowed_files, forbidden_files, validation_commands, acceptance_criteria, cartography_required). Hash `plan_hash` persistido |
| `atlas:programming:receipt` | Append-only no Evidence Ledger. Rejeita resumos textuais. Hash sha256 por arquivo declarado, hash do output, leitura+excerpt do `--diff-path`, link opcional `--parent-receipt`. Arquivo declarado que nao existe **rejeita** o receipt |
| `atlas:programming:verify` | Roda gates aplicaveis ao `scope_mode`. `--strict` retorna exit 1 quando ha blocking failure ou required gate ausente. `--gate=` para subset |
| `atlas:programming:complete` | Registra `AtlasProgrammingReview` (approved/changes_requested/blocked/deferred) e roda completion gate |
| `atlas:programming:status` | Read-only: snapshot + gate runs + reviews + evidence refs |

### REST API (consumivel pelo Atlas Code SCOR-1 e qualquer cliente)

Sob middleware `atlas.token` (header `X-Atlas-Token`):

| Verbo | Path | Papel |
|---|---|---|
| GET | `/atlas-code/programming/work-items` | Lista paginada com filtros `status`, `intent_type`, `scope_mode`, `owner`, `limit` |
| GET | `/atlas-code/programming/work-items/{code}` | Snapshot completo + gate_runs + reviews |
| GET | `/atlas-code/programming/work-items/{code}/gate-runs` | Timeline cronologica de gates |
| GET | `/atlas-code/programming/work-items/{code}/spec-compile` | Spec compilado + critic, sem persistir |

### Servicos canonicos

```text
app/Services/Ai/Programming/Governance/
  ProgrammingScopeMode.php             enum compact|structural + requiredGates()
  ProgrammingWorkItemClassifier.php    keyword matrix PT/EN, override por flags
  ProgrammingGovernanceService.php     orchestrator de topo
  ProgrammingGateRunner.php            itera gates, persiste runs, gate ausente required = blocking failure
  ProgrammingEvidenceLedger.php        evidence append-only, sha256 por arquivo, output_hash, diff hash+excerpt, parent-receipt linking
  ProgrammingSpecCompiler.php          sintetiza spec a partir de placement + Code Intelligence; critic flagga ambiguidade
  Gates/
    ProgrammingGateContract.php
    ProgrammingGateOutcome.php         passed|failed|skipped|waived
    ProgrammingPlacementGate.php       delega AtlasFeaturePlacementService
    ProgrammingCodeIntelligenceGate.php delega EngineeringCodeIntelligenceService::summary (le `module_count` canonico)
    ProgrammingSpecBeforeCodeGate.php  valida spec_hash + 9 campos canonicos
    ProgrammingEvidenceGate.php        rejeita resumo textual e receipt nao persistido no ledger
    ProgrammingScopeGuardGate.php      bloqueia arquivo fora de allowed_files / em forbidden_files; le git diff opcionalmente
    ProgrammingDocsHealthGate.php      delega EngineeringDocumentationHealthService
    ProgrammingCartographyGate.php     emite gap, nunca bloqueia
    ProgrammingCompletionGate.php      so passa com evidence + review approved + gates verdes
```

### Controller REST

```text
app/Http/Controllers/AtlasProgrammingGovernanceController.php
  index(Request)             lista filtravel
  show(string $codeOrId)     snapshot completo
  gateRuns(string $codeOrId) timeline cronologica
  compileSpec(string $codeOrId, ProgrammingSpecCompiler) draft + critic
```

Service provider: `app/Providers/ProgrammingGovernanceServiceProvider.php` (tag
`programming.governance.gate`).

### Tabelas

| Tabela | Migration | Papel |
|---|---|---|
| `atlas_programming_work_items` | `2026_05_13_060000_create_atlas_programming_work_items_table.php` | Lifecycle do trabalho governado |
| `atlas_programming_gate_runs` | `2026_05_13_070000_create_atlas_programming_gate_runs_table.php` | Append-only de cada execucao de gate |
| `atlas_programming_reviews` | `2026_05_13_080000_create_atlas_programming_reviews_table.php` | Decisao de review (approved/changes_requested/blocked/deferred) |
| `atlas_engineering_evidence` | (pre-existente) | Receipts mecanicos compartilhados com Engineering |

### Tests

`tests/Unit/ProgrammingGovernance/*` cobre logica pura (29 testes).
`tests/Feature/ProgrammingGovernance/*` cobre fluxo via Artisan (20 testes).
Trait `tests/Concerns/CreatesAtlasProgrammingGovernanceTables.php` cria as
tabelas em SQLite in-memory.

### Gaps registrados em cada work item

- `plan_autogeneration` — Spec Compiler ja existe (template-driven). Plan/tasks autogen ainda manual
- `cartography_publishing` — Cartographic Knowledge OS ainda nao publica grafo visual
- `learning_loop_automation` — Drift Detector + memory delta promotion ainda nao reentram

### Fora de escopo nesta fatia

- Forge OS (work packets, reservations, collision matrix, integration queue, release gate) — `atlas-forge-operating-system.md` permanece em status `future`. Esta fatia ja entrega o substrato (WorkItem, gates, evidence, scope guard, REST surface) sobre o qual Forge sera construido
- Plan/tasks autogeneration full — compiler atual gera spec; plan generation com decomposicao de tasks fica para proximo ciclo
- Cartografia visual publishing — apenas hook em `gaps_json`
- Atlas Code SCOR-1 desktop UI — REST API ja servida, mas tela em si vive em `atlas-code-long-session-programming-cockpit.md` (status future)

## Proximas Acoes

1. Vincular runbook a comandos reais quando AP for criada.
2. Manter fluxo compacto proporcional, mas auditavel.
3. Revalidar docs-health, architecture-validate, sync e index-code apos mudancas.
