---
id: atlas-forge-operating-system-contracts
type: engineering_knowledge
title: Atlas Forge Operating System Contracts
status: active
category: programming-forge
priority: 99
summary: Contratos persistentes do Forge OS para spec-mae, packets, estado, reservas, permissoes, evidence, provider governance e release gate.
tags:
  - atlas
  - forge
  - programming
  - contracts
  - multi-agent
capabilities:
  - forge_contracts
  - work_packet_contracts
  - forge_governance_objects
  - forge_permission_boundaries
decisions:
  - Forge OS opera por objetos persistentes, nao por texto solto em conversa.
  - Provider recebe packet governado, contexto minimizado e obriga evidence.
  - Permissao, sandbox, segredo, custo e release sao gates de primeira classe.
maintenance:
  - Atualize quando contratos, objetos, gates, provider governance ou boundaries humanos do Forge mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system-runbook.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md
  - docs/engineering-knowledge-base/self-construction/work-splitter-contract.md
  - docs/engineering-knowledge-base/self-construction/scope-validator-contract.md
  - docs/engineering-knowledge-base/self-construction/assignment-and-claim-contract.md
  - docs/engineering-knowledge-base/self-construction/packet-evidence-report-contract.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-forge-operating-system-contracts
graph_title: Atlas Forge Operating System Contracts
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-forge-operating-system
graph_status: active
graph_source: repo
human_name: Atlas Forge Operating System Contracts
canonical_name: Atlas Forge Operating System Contracts
technical_name: atlas-forge-operating-system-contracts
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-forge-operating-system-contracts.md
owner: programming

repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-operating-system-contracts.md

allowed_changes:
  - Atualizar contratos do Forge quando arquitetura, runtime, evidence ou governanca mudarem.

forbidden_changes:
  - Declarar Forge runtime pronto sem codigo, testes e Evidence Ledger.
  - Reduzir gates de permissao, segredo, provider ou release para acelerar execucao.

depends_on:
  - atlas-forge-operating-system
  - atlas-programming-governance-system
  - atlas-ai-self-construction-os

flows_to:
  - atlas-forge-operating-system-runbook
  - atlas-code

unlocks:
  - forge-contract-readable-context

governs:
  - programming.forge.contracts

evidence:
  - docs/engineering-knowledge-base/atlas-forge-operating-system-contracts.md
evidence_refs:
  - symbol: ForgeOperatingSystemContractsService
  - command: atlas:aaeos:forge-operating-system-contracts
  - test: ForgeOperatingSystemContractsTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - forge
  - contracts
  - governance

ai_entrypoints:
  - Leia este doc antes de criar packets, reservas, gates, provider adapters ou release logic do Forge.

ai_usage_notes:
  - Este doc define contratos futuros; nao implica runtime implementado.

quality_gates:
  - mother-spec-approved
  - packet-scoped
  - dependency-dag-valid
  - model-routing-recorded
  - scope-validator-pass
  - permission-approved
  - dry-run-clean
  - evidence-complete
  - rollback-plan-present
  - ci-pipeline-green
  - release-green

failure_modes:
  - Contrato textual sem objeto persistente.
  - Packet sem escopo, evidence ou rollback.
  - Provider com autoridade maior que o contrato.
  - Release gate aceita artefato sem provenance.

observability_signals:
  - contract ids
  - packet state changes
  - scheduler decisions
  - model routing decisions
  - permission decisions
  - dry-run receipts
  - scope validation reports
  - evidence receipts
  - quality gate runs
  - rollback plans
  - release decisions

next_actions:
  - Usar estes contratos como base para AP/implementacao futura do Forge.
---
# Atlas Forge Operating System Contracts

## Resumo

Este documento contem os contratos persistentes do Forge OS. O indice canonico
fica em `atlas-forge-operating-system.md`, o fluxo operacional fica em
`atlas-forge-operating-system-runbook.md` e o mapa inteiro de programacao pesada
fica em `atlas-programming-forge-flow.md`.

## Papel no Atlas

Dar ao Forge uma linguagem contratual estavel para coordenar trabalho de
programacao multiagente sem depender de conversa, memoria implicita ou poder
generico de provider.

## Onde Se Encaixa

Este doc e filho de `atlas-forge-operating-system.md` e alimenta o runbook do
Forge. Ele depende de Programming Governance, Self-Construction OS e dos
contratos de packet, splitter, scope validator, assignment e evidence.

## Contratos

Os contratos abaixo sao a fronteira autoral deste documento. Eles definem o que
precisa existir antes de qualquer AP ou runtime Forge ser tratado como pronto.

## Contrato 1: Mother Spec

Todo trabalho Forge comeca com uma spec-mae. Ela define:

- objetivo maior;
- motivacao;
- escopo e fora de escopo;
- arquitetura afetada;
- docs canonicos;
- Code Intelligence context;
- riscos;
- gates;
- estrategia de divisao;
- criterio de conclusao global.

Nenhum trabalho Forge inicia sem spec-mae legivel por humano, IA e runtime.

## Contrato 2: Work Packet

Cada packet precisa ter:

- id, titulo e objetivo;
- mother spec id;
- owner/agente sugerido;
- arquivos permitidos e proibidos;
- simbolos reservados;
- dependencias;
- entradas e saidas;
- comandos de validacao;
- acceptance criteria;
- evidence requerido;
- risco, rollback e idempotency key;
- permission profile, sandbox profile e secret policy;
- artifact outputs e integration notes.

Packet sem escopo ou evidence nao entra em execucao.

## Contrato 3: Agent Assignment

Agentes recebem assignments, nao poder generico:

- Codex pode implementar e integrar quando autorizado;
- Claude pode planejar, revisar, criticar ou propor;
- Gemini pode pesquisar, explorar, validar contexto ou comparar;
- agentes locais podem testar, indexar, lintar, observar ou checar;
- futuros providers entram por adapter, nao por excecao.

Papel, provider e permissao sao conceitos separados.

## Contrato 4: Packet State Machine

Estados canonicos:

- `draft`;
- `approved`;
- `claimed`;
- `reserved`;
- `in_progress`;
- `waiting_for_input`;
- `waiting_for_tool_permission`;
- `submitted`;
- `under_review`;
- `changes_requested`;
- `verified`;
- `queued_for_integration`;
- `integrated`;
- `released`;
- `failed`;
- `cancelled`;
- `deferred`.

Estado muda apenas por evento registrado. Cancelamento preserva artifacts e
evidence parcial.

## Contrato 5: Scope And Reservation Ledger

Reservas registram:

- arquivo;
- simbolo;
- agente;
- packet;
- tempo;
- status;
- conflito;
- decisao.

Dois agentes nao editam o mesmo arquivo ou simbolo sem consciencia do sistema.
Conflito vira evento governado, nao surpresa no merge.

## Contrato 6: Capability Registry

Toda ferramenta, provider ou MCP capability precisa de descriptor:

- `capability_id`;
- `type`;
- `provider`;
- `tool_schema`;
- `permissions_required`;
- `risk_level`;
- `data_boundary`;
- `timeout`;
- `cost_model`;
- `evidence_output`;
- `failure_modes`;
- `allowed_packet_types`.

Ferramenta sem descriptor nao entra no Forge.

## Contrato 7: Permission, Sandbox And Secret Gate

Categorias de risco:

- `read_only`;
- `write_scoped`;
- `test_execution`;
- `network_read`;
- `network_write`;
- `secret_access`;
- `destructive_command`;
- `external_provider_context`;
- `production_like_action`.

Regras:

- segredo nunca entra em prompt salvo sem politica explicita;
- provider externo recebe contexto minimizado;
- comando destrutivo exige approval/rollback;
- escrita fora de `allowed_files` e bloqueada;
- tool refusal vira evento, nao texto perdido.

## Contrato 8: Evidence Normalization

Evidence de qualquer provider ou tool deve normalizar:

- ids e hashes de inputs;
- comandos;
- outputs;
- testes;
- diffs;
- artifacts;
- screenshots;
- docs updates;
- cartography updates;
- riscos;
- rollback;
- provider cost/time.

Evidence nao depende do estilo do agente.

## Contrato 9: Artifact Provenance

Cada artifact deve ter:

- `artifact_id`;
- `artifact_type`;
- `source_packet`;
- `source_agent`;
- `source_runner`;
- `created_at`;
- `input_hash`;
- `output_hash`;
- `parent_artifacts`;
- `related_files`;
- `related_specs`;
- `retention_policy`;
- `sensitivity_level`.

Artifact sem origem nao e confiavel.

## Contrato 10: Integration Queue

Estados da fila:

- `pending`;
- `blocked`;
- `ready_for_review`;
- `reviewing`;
- `requires_changes`;
- `ready_to_integrate`;
- `integrating`;
- `integrated`;
- `rejected`;
- `deferred`.

Trabalho paralelo converge por fila, nao por colagem manual de conversas.

## Contrato 11: Release Gate

Release exige:

- spec-mae completa;
- packets fechados ou adiados explicitamente;
- conflitos resolvidos;
- diffs integrados;
- tests/gates proporcionais verdes;
- docs atualizadas;
- Code Intelligence atualizado;
- cartografia publicada quando afetada;
- Evidence Ledger completo;
- learning registrado;
- rollback conhecido;
- risco residual aceito.

Release sem evidence e impossivel.

## Contratos 12-17: Fabrica Enterprise Completa

Forge tambem exige estes contratos para nao haver lacuna operacional:

| Contrato | Campos/regras minimas |
|---|---|
| Dependency DAG And Scheduler | `packet_id`, `depends_on`, `unlocks`, `priority`, `risk_level`, `estimated_cost`, `estimated_context_size`, `parallel_safe`, `serialization_reason`; schema, migration, security, runtime, provider e contratos compartilhados rodam serial. |
| Context Budget And Model Routing | `model_routing_decision`, provider/modelo, papel, context budget, max tool calls, runtime, cost, retry budget, compression, privacy boundary e escalation. Modelo barato nao decide arquitetura critica. |
| Scope Validator | Classifica diff/comandos/artifacts como `allowed`, `adjacent_allowed`, `needs_replan`, `forbidden` ou `unknown`; forbidden bloqueia integracao. |
| Completion Evidence Report | Fecha cada packet com status, changed files, commands, tests, tests-not-run reason, scope result, risk delta, gaps, followups, rollback notes, evidence ids e handoff. |
| Dry Run | Alto risco exige ensaio de split, DAG, claims, permissoes, provider routing, custo, gates, integracao e rollback antes de executar. |
| Branch/Worktree/CI | Modos: `direct_patch`, `patch_artifact`, `branch_per_packet`, `worktree_per_agent`, `integration_branch`, `shadow_branch`; CI normaliza lint, typecheck, tests, BDD, build, docs-health, code-index, security, migration dry-run e cartography check. |
| Review/Quality/Rollback | Review por tecnica, arquitetura, security, tests, docs, cartografia, scope, migration, performance e reversibility; gates por perfil de risco; rollback/migration declara strategy, commands, backup, forward/backward plan, blast radius e approvals. |
| Failure/Recipes/Evals | Falhas viram records classificados; prompts, recipes, skills e templates sao versionados; provider/prompt/split/gate novo entra por eval ou shadow quando o risco justificar. |
| Per-Obra SDD/QA/Certification Loop | `app/Services/Ai/Programming/Forge/Qa/`. Schemas `atlas.forge.sdd_spec.v1` (9 secoes: problem_statement, scope, non_goals, constraints, architecture_notes, acceptance_criteria, verification_plan, risks, required_evidence), `qa_gate_run.v1` (6 gates: spec_complete, acceptance_criteria_defined, verification_plan_defined, evidence_ready, tests_declared_or_blocked, certification_ready) e `obra_certification.v1` (status passed\|warn\|failed\|blocked + blockers + remediation). Obra pesada (escalation_packet OU risk high/critical OU sdd_intake/architecture_review/long_run) NAO certifica sem SDD/QA completos; gate failed emite blocker + remediation candidate. |

## Fluxo

O fluxo de uso deste documento e:

1. ler o indice Forge;
2. validar se o trabalho exige Forge completo;
3. selecionar os contratos aplicaveis;
4. transformar contrato em AP, teste ou runtime somente com owner claro;
5. voltar ao runbook para execucao, evidence e release.

## Regras para IA

- Nao criar packet sem allowed files, forbidden files e evidence.
- Nao dar autoridade de politica a provider, tool ou surface.
- Nao pular permission/sandbox gate por conveniencia.
- Nao marcar release sem artifacts e provenance.
- Nao tratar `future` como implementado.

## Escopo de Implementacao

Este doc governa objetos e invariantes do Forge. Implementacao futura pode tocar
servicos de programming, self-construction, evidence, tool runtime e
cartography, mas somente via AP/owner doc e testes proporcionais.

## Dependencias

- `atlas-forge-operating-system.md`;
- `atlas-forge-operating-system-runbook.md`;
- `atlas-programming-governance-system.md`;
- Self-Construction packet/split/scope/assignment/evidence contracts;
- Evidence Ledger e Code Intelligence.

## Evidencias

Evidencias aceitas: docs canonicos, APs, testes, comandos, receipts,
Evidence Ledger, artifacts com hash/provenance e docs-health verde.

## Riscos

- Contrato virar aspiracao sem objeto persistente.
- Packet amplo demais permitir drift de escopo.
- Provider receber segredo ou permissao excessiva.
- Release gate aceitar prova incompleta.

## Exemplos

Exemplo valido: packet de implementacao com allowed files, validation commands,
rollback, permission profile, evidence receipt e estado `verified` antes de
entrar na integration queue.

Exemplo invalido: pedir a um provider para "resolver o modulo" sem escopo,
reservas, evidence e limite de toolset.

## Governance Object Model

Forge deve tratar estes objetos como entidades persistentes:

- `ForgeRun`;
- `MotherSpec`;
- `SpecAnchor`;
- `ChangeDelta`;
- `ContextPack`;
- `WorkPacket`;
- `PacketContract`;
- `DependencyDAG`;
- `SchedulerDecision`;
- `Claim`;
- `Reservation`;
- `AgentAssignment`;
- `ModelRoutingDecision`;
- `ContextBudget`;
- `CapabilityDescriptor`;
- `PermissionDecision`;
- `DryRunReceipt`;
- `ToolRun`;
- `PatchArtifact`;
- `ScopeValidationReport`;
- `VerificationArtifact`;
- `CompletionEvidenceReport`;
- `EvidenceReceipt`;
- `IntegrationQueueItem`;
- `ReviewDecision`;
- `QualityGateRun`;
- `CIPipelineReceipt`;
- `RollbackPlan`;
- `MigrationPlan`;
- `ReleaseDecision`;
- `FailureRecord`;
- `EscalationDecision`;
- `LearningProposal`;
- `PromptRecipe`;
- `EvalRun`;
- `CartographyPublication`;
- `Checkpoint`.

Cada objeto precisa de id estavel e links para pais/filhos. Sem isso, Forge
vira log textual e nao sistema operacional.

## Provider Governance

Nenhum provider e soberano dentro do Forge. Providers sao motores executores.

Cada provider precisa ter:

- capability descriptor;
- allowed operations;
- forbidden operations;
- cost model;
- timeout;
- evidence contract;
- privacy/data boundary;
- failure handling;
- review requirement.

Provider pode sugerir escopo, mas nao expandir escopo sozinho.

## Human And Approval Boundaries

Exigem approval ou escalacao:

- mudanca de proposito, autonomia, politica ou prioridade;
- acesso a segredo ou dado sensivel;
- comando destrutivo;
- alteracao em contrato de seguranca;
- mudanca irreversivel de schema/dados;
- publicacao externa;
- aumento grande de custo;
- ignorar gate vermelho;
- promover learning para regra canonica.

Quando humano nao atua, approval pode vir de gate canonico superior, mas deve
ser explicitamente registrado como decisao do sistema.
## Proximas Acoes

1. Reusar contratos em AP futura de Forge runtime; atualizar runbook quando contrato ganhar comando ou surface real.
