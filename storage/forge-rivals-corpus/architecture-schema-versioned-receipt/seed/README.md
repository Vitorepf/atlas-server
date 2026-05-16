# Seed · architecture-schema-versioned-receipt

ReceiptWriter grava JSON sem `schema_version` e sem hash do payload. Replay não auditável. O arm precisa tornar o receipt canon:

1. Sempre incluir `schema_version` explícito (ex: `atlas.receipt.v1`).
2. Calcular `sha256` determinístico do payload normalized (sem campo `generated_at`).
3. Incluir `replay_manifest` mínimo com `payload_hash` + `schema_version`.

Reprodutibilidade entre hosts é DNA inviolável: mesmo payload ⇒ mesmo sha256, sempre.

## Arquivos
- `ReceiptWriter.php` — versão seed: grava só timestamp + status.
- `ReceiptWriterTest.php` — 3 cenários cobrindo schema_version, hash determinístico, replay_manifest.

## Como o arm sabe que acertou
```
php artisan test --filter='ReceiptWriterTest'
```
