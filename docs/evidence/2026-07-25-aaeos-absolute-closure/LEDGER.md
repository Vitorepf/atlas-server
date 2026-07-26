# AAEOS Absolute Closure — LEDGER

```yaml
branch: main
program: aaeos-absolute-closure
execution: in_progress
opened_at: 2026-07-25
baseline_head: 6f4971827
cursor: W1-R104-TRANSPORT PATH_CORE
recent:
  - W0 SoT folder + SCOREBOARD/LEDGER/DEBTS/LAYOUT
  - W1 HermesNativeFunctionCallSupport + HermesNativeFcCapabilityAttestor
  - W1 HermesCliProvider declare prompt + metadata tool_calls lift (CLI+ACP)
  - W1 Port job payload lift_native_function_calls; Decide/DevAdapter capability wire
  - W1 config atlas.ai.providers.hermes_cli.native_fc.enabled (default false)
  - PHASE-R104-TRANSPORT.json status=PATH_CORE
next:
  - operator: ATLAS_AI_HERMES_NATIVE_FC_ENABLED=true + LIVE L2 single-file mutative
  - W3 R103 public matrix
  - W2 cutover canary when keys ready
```

## Rule

REAL residual GREEN requires live_proofs[]. PHPUnit = path-core stamp only.
