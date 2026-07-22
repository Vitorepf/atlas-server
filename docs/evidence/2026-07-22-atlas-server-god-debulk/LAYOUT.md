# LAYOUT PÉTREO — META + EXECUTE GOD Debulk (atlas-server)

> Se o Sol/Codex violar isto, está **errado**. Corrija imediatamente.

## Árvore obrigatória

```
docs/superpowers/plans/
  2026-07-22-atlas-server-god-debulk-INTENT.md          # curto · eixos 1–69
  2026-07-22-atlas-server-god-debulk-COMPLETE.md        # hub curto · 10/10 A–G
  2026-07-22-atlas-server-god-debulk-FILESYSTEM-100.md  # índice gerado · inventário

docs/prompts/
  atlas-server-god-debulk-META.md                       # prompt META (só plano)
  atlas-server-god-debulk-META-START.md                 # paste META
  atlas-server-god-debulk-EXECUTE.md                    # prompt EXECUTE (só implementar)
  atlas-server-god-debulk-EXECUTE-START.md              # paste EXECUTE

docs/evidence/2026-07-22-atlas-server-god-debulk/
  LAYOUT.md                  # este arquivo
  META-LEDGER.md             # cursor META (minúsculo)
  EXEC-LEDGER.md             # cursor EXECUTE (minúsculo + provas curtas)
  OWNERSHIP.md               # mapa owners
  DEBTS.md                   # fila META
  EXEC-DEBTS.md              # fila EXECUTE
  META-FINDINGS/
    A1--SelfConstruction.md  # 1 md POR BUCKET (nunca fundir waves)
    A1--Holding.md
    …
    T--Unit-Ai.md
    …
```

## Três agentes

| Agente | Prompt | Escreve |
|---|---|---|
| META (Sol) | `…-META.md` | plans + META-* + META-FINDINGS |
| EXECUTE (Sol) | `…-EXECUTE.md` | app/tests/config + EXEC-* + child plans + CODEMAP |
| ARQUITETURA (Claude) | sessão operador | ARCH-BLUEPRINTS/ + OWNERSHIP + canon + review |

Regra de blueprint: SPLIT/OWNER/EXTRACT de monstro (>5k LOC) **obedece** o blueprint da capability em
`ARCH-BLUEPRINTS/<Capability>.md` quando ele existir. Sem blueprint, EXECUTE trabalha itens
não-estruturais (TEST · BUGFIX · DELETE · tooling · test monsters) — o loop nunca para por espera.

## Regras

1. **PROIBIDO** um único `FINDINGS.md` / `ALL.md` / append infinito no COMPLETE.  
2. **OBRIGATÓRIO** `META-FINDINGS/<WAVE>--<BucketSlug>.md` — um bucket = um arquivo.  
3. Se um findings passar de **~1500 linhas**, partir: `A1--SelfConstruction--Readiness.md`.  
4. `META-LEDGER.md` só cursor (`bucket`, `file_cursor`, `buckets_done`) — sem colar findings.  
5. `EXEC-LEDGER.md` só cursor + comandos curtos — sem dump de findings/código.  
6. `FILESYSTEM-100` = inventário; findings = evidência; COMPLETE = leis.  
7. META commits: `docs(core): GOD-DEBULK-META <bucket>` · stage só docs.  
8. EXECUTE commits: `refactor(core)|test(core)|docs(core): GOD-DEBULK …` · stage explícito.  
9. Zero `app/` / `tests/` de implementação até o operador escrever `EXECUTE GOD-DEBULK`.

## Naming

- Wave prefix: `A1` `A2` `A3` `A4` `B1` `B3` `B4` `B-SVC` `B-OTHER` `T` `D` `I`  
- Bucket slug: último segmento do path do FILESYSTEM-100, sem `/`, ex. `SelfConstruction`, `Unit-Ai`  
- Exemplo: bucket `app/Services/Ai/SelfConstruction` → `META-FINDINGS/A1--SelfConstruction.md`
