---
id: atlas-tool-runtime-evidence-gates
type: engineering_knowledge
title: Atlas Tool Runtime Evidence And Gates
status: active
category: architecture
priority: 98
summary: Focused contract for tool evidence storage, normalizers, generic gates, release gates and API Contract Harness.
tags:
  - atlas
  - tools
  - evidence
  - gates
capabilities:
  - evidence_store
  - result_normalizer
  - tool_gates
  - release_gate
decisions:
  - Gates evaluate persisted evidence; they do not execute tools.
  - Normalizers create common findings, metrics, artifacts and blocking failures.
maintenance:
  - Keep gate and evidence semantics here.
related_paths:
  - docs/engineering-knowledge-base/tool-runtime/contracts.md
  - app/Services/Tools/AtlasToolEvidenceStore.php
  - app/Services/Tools/AtlasToolResultNormalizer.php
  - app/Services/Tools/AtlasToolGateService.php
  - app/Services/Tools/AtlasToolReleaseGateService.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-tool-runtime-evidence-gates

graph_title: Atlas Tool Runtime Evidence And Gates

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Tool Runtime Evidence And Gates
canonical_name: Atlas Tool Runtime Evidence And Gates
technical_name: atlas-tool-runtime-evidence-gates
cartography_type: module
canonical_source: docs/engineering-knowledge-base/tool-runtime/evidence-gates.md

owner: tool-runtime

repo_paths:
  - docs/engineering-knowledge-base/tool-runtime/evidence-gates.md

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
  - docs/engineering-knowledge-base/tool-runtime/evidence-gates.md

evidence_refs:
  - symbol: AtlasToolEvidenceStore
  - test: AtlasToolEvidenceStoreTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - system
  - module
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
# Atlas Tool Runtime Evidence And Gates

## Evidence Store

Tool runs persist:

- command/workspace hashes;
- policy decision;
- stdout/stderr redacted previews and truncation metadata;
- normalized findings, metrics and artifacts;
- context ids such as `engineering_run`;
- surface, recipe and authority group metadata.

`ToolEvidenceRecorded` ledger events mirror authority group and role from the
persisted run metadata so audit/replay can reason about which tool family
produced the evidence without exposing raw workspace paths or command output.
They also mirror `summary_hash`, `normalized_result_hash` and
`evidence_receipt_hash` from the run metadata. Gates can use those hashes as the
lineage bridge between local evidence tables and append-only ledger replay.

Exports use sanitized metadata and hashes. Raw secrets or unsafe artifacts do not
leave the evidence boundary.

## Normalizer Contract

`AtlasToolResultNormalizer` maps tool output to:

- status;
- findings;
- metrics;
- artifacts;
- recommendations;
- blocking failures.

Supported structured parsers include SARIF, Gitleaks, Semgrep, ESLint, PHPStan,
Psalm, ShellCheck, Trivy, OSV-Scanner, Grype, Pint, Biome and Hadolint. Generic
fallback remains allowed when a specific parser is not worth its complexity.

## Generic Gate

`AtlasToolGateService` filters persisted evidence by workspace, tool, surface,
policy decision, context, required flag, recipe and freshness.

It blocks on:

- failed/timeout/denied/requires-approval evidence when policy says so;
- blocking findings without valid waiver;
- independent normalized blocking failures;
- stale evidence when `stale_blocks=true`;
- missing required evidence.

`latest_per_tool=true` evaluates only the newest run per tool while preserving
history.

## Release Gate

The Security/SBOM release gate requires recent evidence for:

- secret scan;
- static security scan;
- dependency vulnerability scan;
- SBOM artifact.

Default freshness is 24h and stale evidence blocks release. Waiver-aware failed
runs may pass only when all blocking findings have valid waivers.

## API Contract Harness

`atlas_api_contract` is an internal sensor for OpenAPI vs Laravel route drift. It
does not replace Schemathesis/Pact/WireMock; it creates free local evidence.

It checks:

- OpenAPI/Swagger root;
- `paths`;
- documented operation without Laravel route;
- missing responses;
- Laravel route missing docs, warning by default and blocking in strict mode;
- path parameter equivalence.

Findings persist under surface `engineering_api_contract`.

## Resumo

Focused contract for tool evidence storage, normalizers, generic gates, release gates and API Contract Harness.

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
