---
id: atlas-tool-runtime-runbook
type: engineering_knowledge
title: Atlas Tool Runtime Runbook
status: active
category: maintenance
priority: 97
summary: Operational commands for doctor, authority, recipes, evidence, gates, approvals, waivers and release checks.
tags:
  - atlas
  - tools
  - runbook
capabilities:
  - tool_registry
  - tool_gates
  - finding_waivers
decisions:
  - Operators should prefer recipes and dry-run before new automated execution.
  - Approvals and waivers are audit records, not invisible bypasses.
maintenance:
  - Update when tool CLI/API contracts change.
related_paths:
  - docs/engineering-knowledge-base/tool-runtime/contracts.md
  - docs/engineering-knowledge-base/tool-runtime/evidence-gates.md
  - app/Console/Commands/AtlasToolsCommand.php
  - app/Http/Controllers/AtlasToolRuntimeController.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-tool-runtime-runbook

graph_title: Atlas Tool Runtime Runbook

graph_world: atlas

graph_layer: module

graph_kind: runbook

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Tool Runtime Runbook
canonical_name: Atlas Tool Runtime Runbook
technical_name: atlas-tool-runtime-runbook
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/tool-runtime/runbook.md

owner: tool-runtime

repo_paths:
  - docs/engineering-knowledge-base/tool-runtime/runbook.md

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
  - docs/engineering-knowledge-base/tool-runtime/runbook.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - module
  - runbook
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
# Atlas Tool Runtime Runbook

## Discovery

```bash
./bin/atlas tools doctor --workspace=<repo> --json
./bin/atlas tools list --json
./bin/atlas tools authority --json
./bin/atlas tools commands <tool> --workspace=<repo> --json
```

## Recipes And Runs

```bash
./bin/atlas tools run-recipe gitleaks --recipe=detect-redacted --workspace=<repo> --json
./bin/atlas tools run <tool> --workspace=<repo> --command=<bin> --command=--version --dry-run --json
```

Use `--tool-env=KEY=VALUE`; sensitive env keys are rejected. Use
`--output-limit`, `--max-execution-tier`, `--sandbox-mode`, `--privacy-level`,
`--task-type` and `--requires-provider-safe` to make policy explicit.

## Evidence And Gates

```bash
./bin/atlas tools evidence --workspace=<repo> --status=failed --json
./bin/atlas tools evidence-show --run-id=<id> --workspace=<repo> --json
./bin/atlas tools gate --workspace=<repo> --require-evidence --json
./bin/atlas tools gate --workspace=<repo> --max-age-minutes=240 --stale-blocks --latest-per-tool --json
./bin/atlas tools release-gate --workspace=<repo> --json
```

Evidence store writes fail closed when required tool runtime tables are missing.
Treat a missing run as unavailable infrastructure, not a skipped tool result.
Do not reconstruct artifacts or findings manually; restore the schema and rerun
the sensor or command.

## Approvals And Waivers

```bash
./bin/atlas tools approve codeql --workspace=<repo> --max-execution-tier=T2 --ttl-hours=24 --json
./bin/atlas tools policies --workspace=<repo> --json
./bin/atlas tools revoke codeql --workspace=<repo> --json
./bin/atlas tools waive-finding --finding-id=<id> --reason="false positive" --ttl-hours=24 --json
./bin/atlas tools revoke-finding-waiver --finding-id=<id> --reason="expired exception" --json
```

Approvals allow execution under guardrails. Waivers suppress specific findings
while preserving evidence.

## Engineering Sensors

```bash
./bin/atlas engineering security-scan --workspace=<repo> --profile=release --json
./bin/atlas engineering sbom --workspace=<repo> --profile=release --json
./bin/atlas engineering api-contract --workspace=<repo> --strict --json
```

## Validation

```bash
/opt/homebrew/bin/php artisan test --filter=AtlasToolRuntimeCoreTest
/opt/homebrew/bin/php artisan test --filter=AtlasEngineeringQualityScanCommandTest
/opt/homebrew/bin/php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge docs-health
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune
git diff --check
```

## Resumo

Operational commands for doctor, authority, recipes, evidence, gates, approvals, waivers and release checks.

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
