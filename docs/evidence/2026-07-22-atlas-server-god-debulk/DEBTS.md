# DEBTS — fila META / executor

```yaml
pass: 1
queue_index: 0
meta_only: true
```

## Fila (ordem)

0. A1 SelfConstruction (scan META → findings)
1. A1 Holding
2. A1 Kernel / AgenticEngineeringOs gates
3. restante A1 → A2 → A3 → A4 → B* → T → D → I

## Regras

- META preenche `META-FINDINGS/` + marca `buckets_done` no LEDGER  
- Executor só depois de `EXECUTE GOD-DEBULK`  
- PROIBIDO vanity `residual pass N` sem achado
