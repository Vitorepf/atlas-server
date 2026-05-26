---
title: Atlas Self-Construction Scaffold Staging Executor
slug: atlas-self-construction-scaffold-staging-executor
status: building
risk_level: high
graph_parent: atlas-self-construction-subsystem-builder
depends_on:
  - atlas-self-construction-subsystem-builder
  - atlas-constitutional-kernel
  - atlas-autonomy-admission
authority_class: executor
forbidden_changes:
  - auto_promote_to_source_tree
  - write_outside_staging_root
  - skip_approval_check
schema:
  - atlas.self_construction.scaffold_staging_receipt.v1
---

# Atlas Self-Construction Scaffold Staging Executor

Fecha o loop autônomo: APPROVED proposal → scaffold em diretório de staging. **Nunca escreve direto em source.** Operador promove de `storage/atlas/self_construction/staged/<proposal_id>/` para `app/`, `docs/`, `tests/` manualmente quando estiver satisfeito.

## Safety
- Confirma aprovação no `approvals.jsonl` antes de escrever
- Valida hash da proposal (anti-tamper)
- Path traversal protection — só sob `staging_root`
- Kernel + Admission gates obrigatórios

## API
```php
stage(string $proposalId, string $proposalHash, string $actor='operator'): array
listReceipts(): array
```

## Storage
- `storage/atlas/self_construction/staged/<proposal_id>/` (arquivos de scaffold)
- `storage/atlas/self_construction/staging_receipts.jsonl`

## CLI
`php artisan atlas:scaffold:stage --proposal-id=X --proposal-hash=Y`
