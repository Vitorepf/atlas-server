# Trinity Anti-Decoupling Auditor

`AtlasLoopTrinityContractAuditor` is the **build / pre-commit gate** that REFUSES any primitive edit which breaks its emit/consume promise registered by `AtlasLoopTrinityContractEmitter`.

## How it works

For each Trinity primitive (Loop / Cortex / Maestro) the auditor:

1. Reads the frozen contract (`emits` + `consumes` + per-primitive fingerprint) from the emitter
2. Asks the **current-fingerprint provider** for the sha256 of the *actual current source* of that primitive's emit-side and consume-side
3. Recomputes the canonical fingerprint of the frozen side using the same canonicalisation the emitter used (recursive ksort + JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)
4. Compares; any divergence throws `TrinityContractBreachException` with `primitive`, `side` (emit|consume), and the first `counterpart` that can no longer be satisfied

## Why a provider injection

The actual source-derived fingerprint is computed by a duck-typed `callable` so the auditor stays **pure** and **deterministic**. Production wires a closure that walks the file with PhpParser; tests inject a closure that returns mutated fingerprints to drive the breach paths.

## CLI

`atlas:loop:trinity:contract-audit [--json]`

- Exits 0 on `clean`
- Exits non-zero on `breach` with a structured JSON envelope (primitive, side, counterpart, message)

Wire into pre-commit hooks or CI gates to enforce that touching any one Trinity primitive forces ack across all three.
