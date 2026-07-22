# EXEC-DEBTS — fila do implementador

```yaml
mission: atlas-server-god-debulk-execute
mode: implement
meta_only: false
awaiting_operator_token: EXECUTE GOD-DEBULK
preferred_engine: claude  # Sol Extra Alto blocked 2026-07-22 on AIP-*-DOCS package
queue_index: 2
wave: A1
bucket: app/Services/Ai/SelfConstruction
anti_trap: ignore_AIP_RES_selfconstruction_dispatcher
p0_tooling_status: complete
current_focus: A1-SC-0020..0021 (queued; not started)
claimed_paths:
  - app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionAgentCodexSection.php
  - tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentCodexSectionTest.php
  - docs/evidence/2026-07-22-atlas-server-god-debulk/EXEC-DEBTS.md
  - docs/evidence/2026-07-22-atlas-server-god-debulk/EXEC-LEDGER.md
  - docs/superpowers/plans/2026-07-22-god-debulk-wave-a1-selfconstruction.md
```

## Fila (ordem — derive dos META-FINDINGS; atualize ao executar)

0. **P0 tooling** — complete: audit + guard + CODEMAP verifier + initial incomplete CODEMAP; baseline `>5k=14`, `>2k=40`.
1. **A1-SC-0019** — `ReadinessProjectionAgentCodexSection.php`
   - complete: executable liveness-monitor preflight regression reproduced
     `Readiness\\Schema` before the facade import and passes after it.
2. **A1-SC-0020..0021** — same readiness bucket, after A1-SC-0019 evidence.
3. **A1-SC-0001..0008** — `AtlasSelfConstructionReadinessService.php`
   - TEST status/write honesty + payload contracts
   - BUGFIX read-only vs mutate + fail-closed defaults
   - SPLIT façade thin + owners ≤2000 / hot ≤800 (sem novo `*Section` monstro)
   - OWNER / EXTRACT / CODEMAP / PERF (nessa ordem)
4. Próximos YAMLs em `META-FINDINGS/A1--SelfConstruction.md` (LOC desc, s0 primeiro)
5. Holding → Kernel/Gates → resto A1 → A2… (COMPLETE §5)

## Regras

- Uma op por ciclo · acceptance do finding obrigatória
- Claim path aqui antes de editar (evita briga com META)
- PROIBIDO vanity `residual pass N` sem aceitação
- Fonte: `META-FINDINGS/<WAVE>--<Bucket>.md`
