# AAEOS Absolute Closure — LAYOUT

**Program:** close T1 Absolute MASTER DONE residual-honest (LIVE, not unit-only).  
**T0 freeze three-mode:** already GREEN (do not reopen).  
**Branch:** `main` only · scoped commits.

## DAG

```
W0 SoT → (W1 R104-TRANSPORT ∥ W2 CUTOVER-LIVE ∥ W3 R103-PUBLIC)
      → W4 R106-LIVE → W5 R107-LIVE → W6 R108-LIVE
      → W7 R103-PRE retire → W8 deepen → W9 absolute seal
```

## T0 freeze snapshot (immutable baseline)

| Artifact | sha256[:16] |
|---|---|
| PHASE-P4-FREEZE.json | 36d1e8cca34c957d |
| PHASE-P4-DEV.json | 85cf71276e730568 |
| PHASE-P4-FORGE.json | 4d800cd15cd1b325 |
| PHASE-P4-AUTONOMOS.json | 0fb9ff80e822aabb |
| SCOREBOARD (T0) | e4ec398cd30bf844 |

Baseline HEAD at program open: see LEDGER.

## Owners (reuse-only)

| Wave | Primary owners |
|---|---|
| W1 | HermesCliProvider, HermesNativeFc*, ProviderLock, Decide, EliteExecutorKernelDevAdapter, AgentExecutionProviderPortAdapter |
| W2 | DecisionReceiptIssuer, RuntimeGuard, ops env |
| W3 | bin/atlas, AtlasCliDevCommand, Forge/Autonomos public entries |
| W4–W6 | AgentQosExcellenceLaw, Court/Floor, AtlasUpliftRunner, RivalsExcellenceMeasure, brain:seed |
| W7 | PipelineRunExecutor family + census |

## Law

- LIVE proof required for residual GREEN.
- PHPUnit alone = PATH_CORE only.
- Model suffix `-FC` is never a transport fact.
- ACP Hermes shell/edit activity ≠ Atlas `atlas_apply_patch` FC.
