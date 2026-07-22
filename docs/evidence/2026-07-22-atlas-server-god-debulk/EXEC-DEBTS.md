# EXEC-DEBTS — fila do implementador

```yaml
mission: atlas-server-god-debulk-execute
mode: implement
meta_only: false
awaiting_operator_token: EXECUTE GOD-DEBULK
preferred_engine: claude  # Sol Extra Alto blocked 2026-07-22 on AIP-*-DOCS package
queue_index: 1
wave: A1
bucket: app/Services/Ai/SelfConstruction
anti_trap: ignore_AIP_RES_selfconstruction_dispatcher
p0_tooling_status: complete
claimed_paths:
  - scripts/god-debulk-audit.php
  - scripts/god-debulk-guard.sh
  - scripts/god-debulk-codemap-verify.php
  - app/Services/Ai/CODEMAP.md
  - tests/Feature/Scripts/GodDebulkToolingTest.php
  - docs/evidence/2026-07-22-atlas-server-god-debulk/EXEC-DEBTS.md
  - docs/evidence/2026-07-22-atlas-server-god-debulk/EXEC-LEDGER.md
  - docs/superpowers/plans/2026-07-22-god-debulk-wave-p0-tooling.md
```

## Fila (ordem — derive dos META-FINDINGS; atualize ao executar)

0. **P0 tooling** — complete: audit + guard + CODEMAP verifier + initial incomplete CODEMAP; baseline `>5k=14`, `>2k=40`.
1. **A1-SC-0001..0008** — `AtlasSelfConstructionReadinessService.php`
   - TEST status/write honesty + payload contracts
   - BUGFIX read-only vs mutate + fail-closed defaults
   - SPLIT façade thin + owners ≤2000 / hot ≤800 (sem novo `*Section` monstro)
   - OWNER / EXTRACT / CODEMAP / PERF (nessa ordem)
2. Próximos YAMLs em `META-FINDINGS/A1--SelfConstruction.md` (LOC desc, s0 primeiro)
3. Holding → Kernel/Gates → resto A1 → A2… (COMPLETE §5)

## Regras

- Uma op por ciclo · acceptance do finding obrigatória
- Claim path aqui antes de editar (evita briga com META)
- PROIBIDO vanity `residual pass N` sem aceitação
- Fonte: `META-FINDINGS/<WAVE>--<Bucket>.md`
