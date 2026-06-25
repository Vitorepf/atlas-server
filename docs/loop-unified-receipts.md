# Unified Receipt Chain

The unified receipt chain composes every Loop sub-ledger receipt into a single append-only, hash-chained
JSONL spool. Operators verify it nightly; CI fails fast on tamper.

## Chain contract

- Each node: `{node_id, source_ledger, source_receipt_id, source_facts_json, prev_hash, payload_hash, node_hash, recorded_at, seq}`.
- `payload_hash = sha256(canonical_json({source_ledger, source_receipt_id, source_facts_json}))`.
- `node_hash = sha256(prev_hash || payload_hash)` — a single break flips every subsequent node hash.
- `seq` is monotonic (1-based) and `prev_hash` of node N+1 equals `node_hash` of node N.
- Genesis node has `prev_hash = "0"*64`.

## Sub-ledger sources

The chain federates per-ledger surfaces — provider-health probe, model floor receipts, cycle receipts,
auto-merge receipts, autopoiesis bootstrap receipts, scope-origination receipts, etc. Each appender
hands a `{source_ledger, source_receipt_id, facts}` tuple to `AtlasLoopUnifiedReceiptChain::append()`.

## Deterministic hash rules

- Canonical JSON: nested ksort on associative arrays, lists preserved in order, `JSON_UNESCAPED_SLASHES`
  and `JSON_UNESCAPED_UNICODE`.
- Stored hashes are NEVER recomputed by the exporter — exports are byte-for-byte the chain's recorded
  bytes (modulo a `exported_at_us` envelope field). A bug in the exporter cannot mask a tampered node.
- `verify` re-canonicalizes the payload to reproduce `payload_hash`, then recomputes `node_hash` from
  `prev_hash || payload_hash`, and finally checks the link to the next node. A single mismatch returns
  `ok=false` with `first_break_seq` and `break_reason` — the structural break is named, not scored.

## Operator runbook

### Verify (nightly + CI)

```
php artisan atlas:loop:receipt:unified verify
```

- Exit 0 ⇒ chain is intact.
- Exit 1 ⇒ first break printed (seq + reason). Investigate immediately; do NOT auto-repair.

### Inspect (situational awareness)

```
php artisan atlas:loop:receipt:unified inspect --json
```

Returns `{head_hash, total_nodes, per_source, tail}` with stable key order.

### Export (cold archive + audit handoff)

```
php artisan atlas:loop:receipt:unified export --out=/var/archive/loop-receipts-$(date +%F).jsonl
```

- Refuses to overwrite an existing file without `--force`.
- `--from=N --to=N` exports a closed range; `--since-hash=H` exports everything past a known head.
- Round-trip safe: the exported file is accepted by the verifier on a fresh chain initialized over it.

## Failure modes

- Tampered byte inside a node ⇒ `payload_hash` mismatch at that seq.
- Reordered or deleted line ⇒ `prev_hash` mismatch at the next seq.
- Forged appended line ⇒ `node_hash` mismatch on the boundary (the canonical inputs no longer reproduce
  the recorded `node_hash`).

The chain is the audit substrate for everything the Loop does. Tampering is a structural break; verify
nightly, treat any `ok=false` as an incident.
