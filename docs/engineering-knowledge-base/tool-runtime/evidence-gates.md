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
