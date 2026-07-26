# CODEMAP — Triple Kernel roles (S-KERNEL-MAP D49 PATH_CORE)

| Directory | Role | Live hot path? |
|---|---|---|
| `app/Services/Ai/EngineeringKernel/` | Execute / court / QoS / ProviderPort / Spine façades | **yes** (EliteExecutorKernel) |
| `app/Services/Ai/Kernel/` | Ledger, Decision receipts, Repair platform, EvidenceSpine | **yes** (ledger/receipts) |
| `app/Services/Ai/Programming/Kernel/` | Dev/Forge handoff adapters | adapter only |

**Policy:** do not merge directories without consumer map. New execute/court code → EngineeringKernel. New ledger/receipt → Kernel. Mode handoff → Programming/Kernel.
