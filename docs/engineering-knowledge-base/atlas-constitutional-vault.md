---
id: atlas-constitutional-vault
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Constitutional Vault
slug: atlas-constitutional-vault
status: building
implementation_state: runtime_available
category: governance
priority: 99
summary: Vault assinado separado (storage/atlas/governance/constitutional_vault.json) fora do source tree, HMAC-SHA256 derivado de chave externa. Verifica drift entre vault e Kernel in-code.
tags: [atlas-ai, governance, kernel, vault, patamar-4]
capabilities: [signed_invariant_vault, hmac_verification, kernel_drift_detection, operator_only_signing]
decisions:
  - Vault path em storage/atlas/governance, fora de app/.
  - HMAC key externa (env ATLAS_KERNEL_VAULT_KEY OU storage/atlas/governance/.vault_key).
  - sign() é operator-only via CLI; nunca automatizado.
  - verify() retorna status canon; caller decide bloquear boot ou seguir com violation.
maintenance:
  - Atualizar vault APÓS PR + redeploy que muda invariants in-code.
  - Rotacionar chave via operator (sign re-emite com nova chave).
risk_level: high
owner: atlas-ai
graph_id: atlas-constitutional-vault
graph_title: Atlas Constitutional Vault
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-constitutional-kernel
graph_status: building
graph_source: repo
depends_on: [atlas-constitutional-kernel]
flows_to: [atlas-constitutional-kernel]
unlocks: [kernel_tamper_detection]
governs: [petreo_invariants_separation]
authority_class: vault
related_paths:
  - app/Services/Ai/Governance/AtlasConstitutionalVaultService.php
  - app/Console/Commands/AtlasConstitutionalVaultCommand.php
  - tests/Unit/Ai/Governance/AtlasConstitutionalVaultServiceTest.php
  - storage/atlas/governance/constitutional_vault.json
repo_paths:
  - docs/engineering-knowledge-base/atlas-constitutional-vault.md
  - app/Services/Ai/Governance/AtlasConstitutionalVaultService.php
evidence:
  - app/Services/Ai/Governance/AtlasConstitutionalVaultService.php
  - tests/Unit/Ai/Governance/AtlasConstitutionalVaultServiceTest.php
required_tests:
  - "php artisan test tests/Unit/Ai/Governance/AtlasConstitutionalVaultServiceTest.php"
next_actions:
  - Sign initial vault em ambiente real após primeiro redeploy operacional.
  - Rotacionar chave HMAC com cadência mensal.
allowed_changes:
  - Adicionar mais fontes de chave (Keychain Mac, HashiCorp Vault) preservando schema.
forbidden_changes:
  - vault_signs_on_boot
  - kernel_invariants_loaded_from_vault_directly
  - benchmark_or_rivals_claim
requires_evidence: true
line_limit: 480
schema:
  - atlas.constitutional.vault.v1
---

# Atlas Constitutional Vault

## Resumo

Os invariants pétreos vivem em duas camadas: (a) `AtlasConstitutionalKernelService::INVARIANTS` (in-code source of truth para PR/redeploy) e (b) `storage/atlas/governance/constitutional_vault.json` (vault assinado, fora do alcance de auto-modificação). Vault Service compara as duas camadas e detecta drift.

## Papel no Atlas

Operator-declared canon: pétreo é pétreo. Auto-modificação não pode tocar. Vault separado fora do source garante audit trail: se vault não bate com in-code, alguém mexeu numa camada sem atualizar a outra.

## Onde Se Encaixa

- `sign(actor, reason)` — operator CLI grava vault com HMAC
- `verify()` — checa file exists + signature OK + vault matches in-code Kernel
- `read()` — debug only, sem verificação
- `kernelSnapshot()` — current in-code Kernel canonical (para comparar)

## Contratos

Schema `atlas.constitutional.vault.v1` com status canon:
- `ok` — vault + signature + in-code match
- `vault_missing` — arquivo não existe
- `key_missing` — chave HMAC não resolvível
- `signature_invalid` — HMAC mismatch
- `kernel_drift` — vault válido mas in-code mudou
- `malformed` — JSON corrupto

## Fluxo

1. Operator define ATLAS_KERNEL_VAULT_KEY (env ou file)
2. `atlas:constitutional:vault sign --actor=ops --reason="initial seal"` → grava vault
3. Cron/AppServiceProvider chama `verify()` regularmente
4. Status diferente de `ok` → emite violation (caller decide o que fazer)

## Regras para IA

- NÃO assinar vault automaticamente — operator-only.
- NÃO carregar invariants de vault para Kernel — vault só verifica.
- NÃO esconder mismatch — sempre reportar status canon.

## Escopo de Implementacao

Service + CLI + tests + AppServiceProvider wiring opcional.

## Dependencias

- AtlasConstitutionalKernelService (in-code source)
- env ATLAS_KERNEL_VAULT_KEY OU storage/atlas/governance/.vault_key

## Evidencias

Service + test + CLI + arquivo vault (após primeira assinatura operator).

## Riscos

- Chave HMAC vazada → atacante pode forjar vault. Mitigação: rotacionar chave + auditar storage/.vault_key permissions (0600).
- Operator esquece de re-sign após PR → kernel_drift detected. Mitigação: alertar via violation.

## Exemplos

```bash
ATLAS_KERNEL_VAULT_KEY="..." \
  php artisan atlas:constitutional:vault sign --actor=ops --reason="post PR-1234" --json

php artisan atlas:constitutional:vault verify --json
```

## Proximas Acoes

Cron horário verify + integration test boot path.

## Safety

- HMAC-SHA256 com chave externa.
- Vault path fora de app/.
- claim_policy provider-safe.
- Operator-only signing.
