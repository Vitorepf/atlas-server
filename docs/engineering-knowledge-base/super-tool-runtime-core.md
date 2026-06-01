---
id: atlas-super-tool-runtime-core
type: engineering_knowledge
title: Atlas Super Tool Runtime Core
status: active
category: architecture
priority: 98
summary: Compact parent contract for registering, governing, executing, normalizing and gating local/project tools used by Atlas.
tags:
  - atlas
  - tools
  - harness
  - evidence
capabilities:
  - tool_registry
  - tool_policy_engine
  - tool_executor
  - result_normalizer
  - evidence_store
  - tool_gates
  - finding_waivers
decisions:
  - Tools enter the canonical registry before recurring automation.
  - Missing optional tools produce auditable state instead of silence.
  - Tools execute under policy, sandbox, privacy, approval and provider-safe constraints.
  - Evidence, normalizers, gates, approvals and waivers are shared across Harness, CLI, API and app.
  - External coding agents are governed executors inside Atlas, not replacement control-planes.
  - Tool Runtime e camada de evidence/execution do fluxo pesado, nao decisor nem flow inteiro.
maintenance:
  - Keep this parent compact; edit focused specs under tool-runtime/.
  - Full historical source is archived in archive/source-material/super-tool-runtime-core-full-2026-05-08.md.
related_paths:
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/tool-runtime/README.md
  - docs/engineering-knowledge-base/tool-runtime/contracts.md
  - docs/engineering-knowledge-base/tool-runtime/evidence-gates.md
  - docs/engineering-knowledge-base/tool-runtime/catalog-roadmap.md
  - docs/engineering-knowledge-base/tool-runtime/runbook.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - docs/engineering-knowledge-base/archive/source-material/super-tool-runtime-core-full-2026-05-08.md
  - app/Services/Tools/AtlasToolRegistryService.php
  - app/Services/Tools/AtlasToolPolicyEngine.php
  - app/Services/Tools/AtlasToolExecutor.php
  - app/Services/Tools/AtlasToolEvidenceStore.php
  - app/Services/Tools/AtlasToolResultNormalizer.php
  - app/Services/Tools/AtlasToolGateService.php
  - app/Console/Commands/AtlasToolsCommand.php
  - app/Http/Controllers/AtlasToolRuntimeController.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-super-tool-runtime-core

graph_title: Atlas Super Tool Runtime Core

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Super Tool Runtime Core
canonical_name: Atlas Super Tool Runtime Core
technical_name: atlas-super-tool-runtime-core
cartography_type: module
canonical_source: docs/engineering-knowledge-base/super-tool-runtime-core.md

owner: architecture

repo_paths:
  - docs/engineering-knowledge-base/super-tool-runtime-core.md

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
  - architecture

evidence:
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
evidence_refs:
  - symbol: AtlasToolRegistryService
  - command: atlas:tools

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - system
  - module
  - architecture

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
# Atlas Super Tool Runtime Core

Super Tool Runtime is the shared control-plane for local, project-local and
Atlas-managed tools. It prevents each tool from becoming an isolated flow.

## Read Order

| Need | Read |
|---|---|
| Registry, policy, tiers, authority, external agents | `tool-runtime/contracts.md` |
| Evidence Store, normalizers, gates, release gate, API contract harness | `tool-runtime/evidence-gates.md` |
| Tool family backlog for heavy programming | `tool-runtime/catalog-roadmap.md` |
| CLI/API operations and validation | `tool-runtime/runbook.md` |
| Historical implementation narrative | `archive/source-material/super-tool-runtime-core-full-2026-05-08.md` |

## Foundation

Implemented foundation includes:

- persistent tool definitions, installations and policies;
- tool runs, artifacts and findings as transversal Evidence Store;
- policy decisions: `allowed`, `denied`, `requires_approval`, `skipped`;
- executor with controlled cwd, argv array, timeout, dry-run, safe env allowlist,
  redacted output and unsafe argument rejection;
- normalizer contract for findings, metrics, artifacts, recommendations and
  blocking failures;
- approval and waiver services with audit history;
- generic gates, release gates and authority matrix;
- Engineering Harness integration for quality scan, visual smoke, code
  intelligence, API contract, security scan and SBOM.

## Operational Rule

Tools do not decide. Tools produce evidence. Atlas Kernel, policy, gates,
Decision Receipt and operator review decide what the evidence means.

No fluxo pesado de programacao, a posicao completa do Tool Runtime vive em
`atlas-programming-forge-flow.md`. Tool Runtime Gateway conecta tools, agentes e
harnesses ao Evidence Ledger, mas nao substitui Atlas Decide, Programming
Governance, Forge OS ou Repair Loop.

## Tool Lifecycle

1. Catalog tool in registry.
2. Define authority group, tier, risks, command recipes and policy.
3. Detect installation with doctor.
4. Execute only through tool runtime or managed harness.
5. Normalize output into evidence.
6. Evaluate with gate/release gate when required.
7. Feed findings into repair, review, Curator or release decisions.

## Missing Tool Rule

Optional missing tools create `missing`/`skipped` evidence with a reason. Required
missing tools block only when a consumer explicitly requires that evidence.

## External Agent Rule

Aider, Continue, OpenHands, Serena/MCP writes and similar agents can assist Atlas
only as governed executors. Atlas remains the owner of context, policy, gates,
memory, evidence and final status.

## Validation

```bash
./bin/atlas tools doctor --workspace=<repo> --json
./bin/atlas tools authority --json
./bin/atlas tools gate --workspace=<repo> --json
/opt/homebrew/bin/php artisan test --filter=AtlasToolRuntimeCoreTest
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge docs-health
```

## Resumo

Compact parent contract for registering, governing, executing, normalizing and gating local/project tools used by Atlas.

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
