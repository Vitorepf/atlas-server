# Atlas Loop Recovery & Replay CLI

`atlas:loop:recovery` is the operator-facing surface for the Loop receipt-ledger trust chain. It
exposes four subcommands wiring the underlying services:

- `replay`  — reconstruct ledger state via `AtlasLoopReceiptReplayer`
- `backup`  — snapshot ledgers into a deterministic tar via `AtlasLoopBackupComposer`
- `verify`  — replay a backup tar and assert state hash via `AtlasLoopRestoreVerifier`
- `restore` — extract a backup tar to a target directory

## Subcommands

### `replay --ledger=PATH`

Reads a single JSONL receipt ledger and emits the reconstructed `state` map plus the audit
`event_count`. Read-only.

### `backup [--ledger=PATH,PATH,...]`

Snapshots every supplied (or autodiscovered under `storage/app/atlas/loop/ledgers/`) receipt ledger
into one tar at `storage/app/atlas/loop/backups/loop-ledger-<utc-iso>.tar`. Prints the absolute tar
path and the manifest's `archive_sha256`.

### `verify --tar=PATH --checkpoint=JSON-or-PATH`

Extracts the tar into a temp dir, replays every ledger via `AtlasLoopReceiptReplayer`, and compares
the resulting state hash against the operator-supplied checkpoint. Exits 0 when the hashes match;
exit code 2 on mismatch. The temp directory is cleaned up on both success and failure paths.

### `restore --tar=PATH --target=DIR [--i-know-what-im-doing]`

Extracts the backup tar into the target directory. **Refuses to write into
`storage/app/atlas/loop/ledgers/` unless `--i-know-what-im-doing` is set** — that path holds the
live ledger files the runtime is reading. Operator opt-in for that destructive operation is
required.

## Receipt-ledger trust chain

1. Every line in a receipt ledger carries a `receipt_hash` = sha256 of its canonical-JSON body.
2. `AtlasLoopReceiptReplayer` refuses to replay any line whose recomputed hash does not match the
   stored `receipt_hash` — tampering is fail-closed.
3. `AtlasLoopBackupComposer` carries the per-file sha256 + a top-level `archive_sha256` into the
   manifest at the head of the tar. The archive hash is structural — robust to mtime drift.
4. `AtlasLoopRestoreVerifier` extracts into a temp directory, replays through the same replayer,
   and recomputes the state hash. Operator checkpoints (saved out-of-band) are the third link.

## Disaster-recovery runbook

1. **Backup nightly**: `php artisan atlas:loop:recovery backup` (cron, immutable artifact storage).
2. **Snapshot the checkpoint**: after backup, persist the verifier's expected state hash next to
   the tar. Both objects together are the recovery atom.
3. **Verify before relying**: `php artisan atlas:loop:recovery verify --tar=… --checkpoint=…`. If
   exit code is 2, the backup is unsafe — investigate before restoring.
4. **Restore to scratch first**: `php artisan atlas:loop:recovery restore --tar=… --target=/tmp/recover`
   to inspect.
5. **Promote to live (ONLY when verified)**: re-run restore with `--target=…/ledgers/` and
   `--i-know-what-im-doing`. Capture the resulting operator confirmation in your IR log.
