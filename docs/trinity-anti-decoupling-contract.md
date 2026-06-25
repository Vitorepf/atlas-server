# Trinity Anti-Decoupling Contract

`AtlasLoopTrinityContractEmitter` is the **single canonical source** of the Trinity coupling contract. For each of the three primitives (Loop / Cortex / Maestro) it emits a frozen, deterministic SHA-256 descriptor declaring:

- `emits` — the exact event names + payload schema the primitive PRODUCES
- `consumes` — the exact event references the primitive REQUIRES from the other two

## Recursive-coupling invariant

Each primitive's `consumes` MUST reference **at least one symbol from BOTH of the other two primitives**. A primitive that does not is REJECTED with `TrinityContractMalformedException`. Practical consequence: touching any one primitive forces re-emission across all three.

## Deterministic fingerprint

Given the same input descriptors, the per-primitive SHA-256 fingerprints AND the `registry_fingerprint` are byte-identical across runs. Canonical form: recursive `ksort` + `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.

## Output shape

```json
{
  "schema_version": "atlas.trinity.anti_decoupling.contract.v1",
  "primitives": {
    "loop":   {"emits": [...], "consumes": [...], "fingerprint": "..."},
    "cortex": {"emits": [...], "consumes": [...], "fingerprint": "..."},
    "maestro":{"emits": [...], "consumes": [...], "fingerprint": "..."}
  },
  "registry_fingerprint": "..."
}
```

## Wiring

`AppServiceProvider` binds `AtlasLoopTrinityContractEmitter` as a singleton. Downstream code resolves it from the container; the emitted registry is the canonical source consumed by anti-decoupling tooling.
