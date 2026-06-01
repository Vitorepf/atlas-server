---
id: legacy-cleanup-waves-and-gates
type: engineering_knowledge
title: Legacy Cleanup Waves And Gates
status: active
category: documentation-governance
priority: 86
summary: Execution waves, gates and rollback rules for continuing Atlas legacy documentation cleanup safely.
tags:
  - atlas
  - documentation
  - cleanup
  - gates
capabilities:
  - legacy_cleanup_waves_and_gates
decisions:
  - Cleanup waves must be small, reversible and validated.
  - Runtime changes are outside cleanup scope unless explicitly declared.
maintenance:
  - Update when the cleanup process changes.
related_paths:
  - docs/engineering-knowledge-base/legacy-documentation-cleanup-plan.md
  - docs/engineering-knowledge-base/legacy-cleanup/handoff-checklist.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: legacy-cleanup-waves-and-gates

graph_title: Legacy Cleanup Waves And Gates

graph_world: atlas

graph_layer: system

graph_kind: policy

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Legacy Cleanup Waves And Gates
canonical_name: Legacy Cleanup Waves And Gates
technical_name: legacy-cleanup-waves-and-gates
cartography_type: policy
canonical_source: docs/engineering-knowledge-base/legacy-cleanup/waves-and-gates.md

owner: legacy-cleanup

repo_paths:
  - docs/engineering-knowledge-base/legacy-cleanup/waves-and-gates.md

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
  - legacy-cleanup

evidence:
  - docs/engineering-knowledge-base/legacy-cleanup/waves-and-gates.md
evidence_refs:
  - symbol: AtlasWavesAndGatesService
  - command: atlas:aaeos:waves-and-gates
  - test: AtlasWavesAndGatesTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - policy
  - legacy-cleanup

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
# Legacy Cleanup Waves And Gates

## Wave 0 - Freeze Authority

Goal: prevent old docs from competing with current governance.

Actions:

- confirm README, START_HERE and canonical index are still the entry points;
- capture dirty worktree;
- decide the owner doc for every legacy source being touched.

Gate: the session can answer "which doc wins in conflict?"

## Wave 1 - Promote Stable Decisions

Goal: move durable decisions into small canonical docs.

Actions:

- extract decision, not transcript;
- add frontmatter and related paths;
- link source material;
- update index only if the new doc is a real authority.

Gate: promoted doc passes line limits and has a clear owner.

## Wave 2 - Merge Partial Duplicates

Goal: remove duplicate authority without losing useful details.

Actions:

- diff legacy source against owner doc;
- patch only missing decisions;
- add redirect or archive note to source.

Gate: no sentence creates a new master flow.

## Wave 2.5 - Normalize Domain Docs

Goal: keep domain specs as Layer 4, not architecture roots.

Actions:

- verify safety boundaries;
- verify domain does not decide provider, policy or runtime;
- connect domain to pipeline, memory and evidence.

Gate: domain remains below Kernel, Master and Pipeline.

## Wave 3 - Redirect And Archive

Goal: preserve history while removing authority confusion.

Required note:

```md
> Status: archived.
> Canonical replacement: `docs/engineering-knowledge-base/...`.
> Cleanup note: preserved for history and link compatibility. Do not use as source of truth.
```

Gate: old doc clearly points to the replacement.

## Wave 4 - Quarantine Delete Candidates

Goal: protect against accidental loss.

Required checks:

```bash
rg -n "filename-or-slug" docs app config database routes tests scripts
git log --follow -- path/to/file.md
```

Gate: delete waits for a separate human-approved change.

## Wave 5 - Human Knowledge Surface

Goal: prevent personal knowledge from becoming raw provider context.

Actions:

- keep sensitive material in AtlasVault/Obsidian or curated sync;
- promote only reviewed excerpts;
- label privacy and source policy.

Gate: provider-safe content is explicit.

## Rollback

If cleanup creates confusion:

1. revert only the cleanup patch;
2. restore the previous redirect/header;
3. keep archived source material;
4. record the cause before retrying.

## Resumo

Execution waves, gates and rollback rules for continuing Atlas legacy documentation cleanup safely.

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
