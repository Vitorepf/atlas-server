# CODEMAP — Public entries (D2)

| Entry | Artisan / chain | Live executor | AAEOS on stack? |
|---|---|---|---|
| `bin/atlas dev` | `atlas:cli:dev` → Efficient → `RunExecutor` | **KernelRunExecutor** | absent |
| `bin/atlas forge` | `atlas:cli:dev --forge` | Forge profile / not PRE | absent |
| `bin/atlas autonomos` | `atlas:self-construction:runtime-daemon claim --json` | TaskServing / orchestrator | absent |
| `atlas:aaeos:run` | artisan AAEOS router | dispatch → native modes | router only |

**Proof:** container resolves `RunExecutor` → `KernelRunExecutor` (2026-07-25).  
**PRE:** retained under Http until full port+delete (R103).
