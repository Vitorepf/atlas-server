---
id: atlas-tool-runtime-contracts
type: engineering_knowledge
title: Atlas Tool Runtime Contracts
status: active
category: architecture
priority: 98
summary: Focused contract for Super Tool Runtime registry, policy, tiers, authority matrix and external agent boundaries.
tags:
  - atlas
  - tools
  - contracts
capabilities:
  - tool_registry
  - tool_policy_engine
  - tool_authority_matrix
decisions:
  - Every tool enters through the registry before recurring automation.
  - Tool execution must be governed by tier, risk, privacy, sandbox and approvals.
  - External coding agents are executors inside Atlas, never replacement control-planes.
maintenance:
  - Keep policy and authority changes here.
related_paths:
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/tool-runtime/evidence-gates.md
  - app/Services/Tools/AtlasToolRegistryService.php
  - app/Services/Tools/AtlasToolPolicyEngine.php
  - app/Services/Tools/AtlasToolAuthorityMatrixService.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-tool-runtime-contracts

graph_title: Atlas Tool Runtime Contracts

graph_world: atlas

graph_layer: system

graph_kind: contract

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Tool Runtime Contracts
canonical_name: Atlas Tool Runtime Contracts
technical_name: atlas-tool-runtime-contracts
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/tool-runtime/contracts.md

owner: tool-runtime

repo_paths:
  - docs/engineering-knowledge-base/tool-runtime/contracts.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - tool-runtime

evidence:
  - docs/engineering-knowledge-base/tool-runtime/contracts.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - system
  - contract
  - tool-runtime

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas Tool Runtime Contracts

## Core Tables

- `atlas_tool_definitions`;
- `atlas_tool_installations`;
- `atlas_tool_policies`;
- `atlas_tool_runs`;
- `atlas_tool_artifacts`;
- `atlas_tool_findings`.

## Registry Contract

Every tool definition declares:

- slug, category and capabilities;
- execution tier and expected cost;
- default trigger and recommended surface;
- authority group and authority role;
- timeout, network posture, sandbox posture and failure policy;
- safe command recipes when executable.

Missing optional binaries produce `missing` or `skipped` with reason. They do not
silently disappear and do not become mandatory paid dependencies.

## Evidence Persistence Contract

Tool evidence writes are all-or-nothing. `AtlasToolEvidenceStore` requires
`atlas_tool_definitions`, `atlas_tool_runs`, `atlas_tool_artifacts` and
`atlas_tool_findings` before recording external tool evidence. If any required
table is unavailable, it fails closed and writes no partial run, artifact,
finding or ledger event.

Run, artifact and finding persistence share one transaction. Ledger projection is
recorded only after the local evidence graph exists, and ledger failures do not
turn partial evidence into success.

Run metadata includes the tool definition's category, type, execution tier,
expected cost, default trigger, authority group and authority role. `context`
metadata may add recipe or execution-origin fields, but it does not create a new
authority model outside the registry.

Each run metadata also carries `receipt_schema_version`,
`summary_hash`, `normalized_result_hash` and `evidence_receipt_hash`. The ledger
event reuses those hashes so gates can compare persisted evidence with replayed
ToolEvidenceRecorded events without exposing raw command output or workspace
paths.

Evidence export surfaces must expose these hashes as an explicit `receipt`
block. The receipt is pointer/hash only: it may include `tool_run_id`,
`tool_slug`, `workspace_hash`, `command_hash`, `summary_hash`,
`normalized_result_hash` and `evidence_receipt_hash`, but it must not expose raw
commands, raw output or workspace paths. Provider dispatch and runtime policy
mutation remain closed in exported evidence receipts.

Each run also carries `atlas.tool_action_runtime.contract.v1`. This contract
states that persisted evidence is `evidence_recording`, not execution authority:
raw command/output/workspace remain hidden, provider dispatch, policy mutation
and Agent Control Plane dispatch stay false, and operator approval is required
before any external execution path can use that evidence.

`php artisan atlas:ai:tool-action-runtime-report --json` is the compact
read-only promotion check for this layer. It summarizes registry definitions,
installation tracking, recent evidence runs, failed required evidence, open
blocking findings and presence of the action-runtime contract without executing
tools, evaluating gates or writing ledger events. Promotion status is based on
the latest evidence per tool so older pre-contract runs remain visible as
history without creating false blockers.
The report also flags unsafe action-runtime contracts when raw command/output/
workspace exposure, provider dispatch, runtime policy mutation, Agent Control
Plane dispatch or missing operator approval appear on the latest evidence. Recent
run summaries expose only schema, contract hash and boolean safety flags.

## Policy Contract

`AtlasToolPolicyEngine` emits auditable decisions:

- `allowed`;
- `denied`;
- `requires_approval`;
- `skipped`.

Inputs include tier, risk, network, cost, sandbox, privacy, task type,
provider-safe requirement, approval status and workspace/global overrides.

## Tier Contract

| Tier | Use | Blocks? |
|---|---|---|
| T0 interactive | symbol lookup, rg, cheap context | No by itself |
| T1 local fast | format/lint/typecheck/diff secret scan | Yes for scoped direct errors |
| T2 PR/review | full static/security/API/a11y/architecture scans | Yes by severity/policy |
| T3 release/nightly | mutation, license, deep vulnerability/performance | Yes for release/nightly |

T3 must not block interactive flow. T0 must not write without approval.

## Authority Matrix

Overlapping tools need a declared authority group:

- primary tools define default blocking severity;
- complementary tools add evidence and may elevate when more precise;
- fallbacks prevent total blind spots;
- duplicate findings are correlated for gate counting, never deleted from evidence.

Waivers attach to findings/fingerprints, not to entire tools.

## External Agent Boundary

Aider, Continue, OpenHands, Serena/MCP with writes and similar agents are tool
executors inside Atlas.

They receive Atlas context and constraints, run in worktree/sandbox, produce
patch/artifacts, and are validated by Atlas tests/gates/evidence. They never mark
work resolved, pick providers, bypass secrets policy or become the source of
truth.

## Resumo

Focused contract for Super Tool Runtime registry, policy, tiers, authority matrix and external agent boundaries.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
