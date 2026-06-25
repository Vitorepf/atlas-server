# Trinity Anti-Decoupling Drift Detector

`AtlasLoopTrinityContractDriftDetector` is the **forensic memory** of the Trinity coupling: which primitive last broke the contract, in which commit, and which counterpart did it desynchronize?

It walks git history (HEAD..N), replays `AtlasLoopTrinityContractAuditor` at every commit boundary, and produces a deterministic, descending-by-commit-time list of `TrinityContractBreachFact` records:

```json
{
  "commit_sha": "abc1234",
  "author": "alice",
  "committed_at_unix": 1700000000,
  "primitive": "maestro",
  "side": "consume",
  "counterpart": "loop",
  "divergence_summary": "Trinity contract breach: primitive=maestro side=consume counterpart=loop ..."
}
```

## Pétreo: FACTS-only

NO severity, NO ranking, NO score. Only the bare commit-level fact + the breach naming. Empty list ⇒ the contract was intact across the window.

## CLI

`atlas:loop:trinity:contract-drift [--window=100] [--json]`

Wired via the container — production binds the git-log commits source + the per-commit fingerprint-provider factory; tests inject synthetic versions.
