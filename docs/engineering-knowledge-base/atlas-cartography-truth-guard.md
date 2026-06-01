---
id: atlas-cartography-truth-guard
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Cartography Truth Guard
slug: atlas-cartography-truth-guard
status: building
implementation_state: runtime_available
category: cartography
priority: 98
summary: Probe append-only que detecta drift entre Cartografia render e docs canônicos. Drift detectado emite violation no Constitutional Kernel. Pétreo invariant operator-declared.
tags: [atlas-ai, cartography, governance, truth, patamar-4]
capabilities: [canonical_doc_scan, kb_index_compare, drift_detection, kernel_violation_emission]
decisions:
  - Cartografia é espelho fiel da documentação canônica; drift = crime crítico.
  - Probe é READ-ONLY; nunca corrige sozinho; só registra e viola.
  - 3 kinds de drift canon: orphan_in_kb, missing_in_kb, content_drift.
  - Defensive degradation honesta (kb_table_missing | docs_root_missing).
maintenance:
  - Atualizar quando docs_root mover ou KB source_type mudar.
  - Manter probe READ-ONLY; jamais escrever no KB a partir daqui.
risk_level: high
owner: atlas-ai
graph_id: atlas-cartography-truth-guard
graph_title: Atlas Cartography Truth Guard
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-constitutional-kernel
graph_status: building
graph_source: repo
depends_on: [atlas-constitutional-kernel, atlas-ai-knowledge-governance-system]
flows_to: [atlas-vault-cartografia]
unlocks: [cartography_truth_invariant]
governs: [cartografia_render_truthfulness]
authority_class: guard
related_paths:
  - docs/engineering-knowledge-base/atlas-cartography-truth-guard.md
  - app/Services/Ai/Cartography/CartographyTruthGuardService.php
  - app/Console/Commands/AtlasCartographyTruthGuardCommand.php
  - tests/Unit/Ai/Cartography/CartographyTruthGuardServiceTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-cartography-truth-guard.md
  - app/Services/Ai/Cartography/CartographyTruthGuardService.php
evidence:
  - app/Services/Ai/Cartography/CartographyTruthGuardService.php
  - tests/Unit/Ai/Cartography/CartographyTruthGuardServiceTest.php
evidence_refs:
  - symbol: CartographyTruthGuardService
  - command: atlas:cartography:truth-guard
  - test: CartographyTruthGuardServiceTest
required_tests:
  - "php artisan test tests/Unit/Ai/Cartography/CartographyTruthGuardServiceTest.php"
next_actions:
  - Adicionar cron horário para sweep automatizado depois de validação operacional.
allowed_changes:
  - Adicionar novos drift kinds preservando schema retrocompatível.
forbidden_changes:
  - probe_writes_back_to_kb
  - auto_archive_orphan_items
  - skip_kernel_violation_emission
requires_evidence: true
line_limit: 480
schema:
  - atlas.cartography.truth_guard_sweep.v1
  - atlas.cartography.drift_entry.v1
---

# Atlas Cartography Truth Guard

## Resumo

Probe append-only que compara documentação canônica (`docs/engineering-knowledge-base/*.md`) com o read-model que alimenta a Cartografia (KB Postgres `atlas_engineering_knowledge_items`). Detecta drift e emite violation no Constitutional Kernel. **Não corrige sozinho** — operator reconcilia via `atlas engineering knowledge sync --prune`.

## Papel no Atlas

Operator declarou: Cartografia = espelho fiel da documentação canônica. Se Cartografia mostra dado divergente do `.md`, é crime crítico — fere governance. Este service torna essa regra runtime, não só declaração.

## Onde Se Encaixa

- Lê `.md` canônicos via `EngineeringKnowledgeBaseService.docsRoot()`
- Compara com `AtlasEngineeringKnowledgeItem` (read-model Cartografia)
- Emite drift entries + Kernel violation por entrada
- Receipt JSONL append-only

## Contratos

- `atlas.cartography.truth_guard_sweep.v1` — envelope do sweep com `sweep_hash`
- `atlas.cartography.drift_entry.v1` — drift individual (`orphan_in_kb` | `missing_in_kb` | `content_drift`)

## Fluxo

1. `sweep(actor)` → scan `.md` → compute `{slug, content_hash}` canônico
2. Read KB → compute `{slug, content_hash}` indexed
3. Diff → drift entries
4. Cada drift → `kernel.recordViolation('cartography_truth_guard', BLOCK)`
5. Append JSONL receipt com `sweep_hash`

## Regras para IA

- NUNCA escrever no KB a partir deste service.
- NUNCA arquivar orphan automaticamente.
- NUNCA pular emissão de violation.
- Probe é honest: degradação devolve status canon, não inventa truthful.

## Escopo de Implementacao

Service + CLI `atlas:cartography:truth-guard` (sweep|list|latest) + tests unit + receipt JSONL.

## Dependencias

- AtlasConstitutionalKernelService (violation emission)
- EngineeringKnowledgeBaseService.docsRoot (canon path)
- AtlasEngineeringKnowledgeItem (KB read-model)
- CanonicalDocsFrontmatterParser (slug derivation)

## Evidencias

Service file + test file + receipt JSONL local + Kernel violations ledger.

## Riscos

- Race entre sync e sweep: probe roda durante sync parcial → drift falso. Mitigação: operator roda sync antes de sweep.
- KB table missing em test env: probe degrada honesto.

## Exemplos

```bash
php artisan atlas:cartography:truth-guard --action=sweep --json
php artisan atlas:cartography:truth-guard --action=latest --json
```

## Proximas Acoes

Cron horário após validação operacional. Dashboard surface de drift.

## Safety

- Append-only JSONL local.
- Kernel violation por drift garante audit trail.
- claim_policy provider-safe enforced.
- Probe READ-ONLY: pétreo invariant.
