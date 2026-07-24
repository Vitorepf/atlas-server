# COLE ISTO NO CODEX (prompt que trabalha)

```
Implemente o AAEOS Elite Deepening. Trabalhe duro por horas. Entregue código.

LEI:
docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER.md

CURSOR (ler agora):
docs/evidence/2026-07-23-aaeos-elite-deepening/LEDGER.md
docs/evidence/2026-07-23-aaeos-elite-deepening/SCOREBOARD.md

ESTADO ATUAL (não discuta, use):
- P0 GREEN, P1a GREEN, P1-JSON GREEN (R104 path law)
- residual R104-TRANSPORT (Hermes sem FC nativo live) NÃO bloqueia P2
- PRÓXIMO: EXECUTE P2a.1 e siga o DAG do MASTER até P4

COMO TRABALHAR:
1) git branch = main; ignore FOREIGN_WIP (não toque)
2) Uma fatia lógica por vez, mas ENCADEIE fatias na mesma sessão sem pedir licença
3) RED → código nos paths do MASTER → GREEN → commit scoped (git add -- paths, NUNCA -A)
4) Atualize PHASE + LEDGER + SCOREBOARD e commit de evidência
5) Já passe para a próxima fatia do DAG

DAG (não pule o crítico):
P2a.1 → P2a.2 → P2b-EXPAND→SHADOW→CANARY→CUTOVER
→ P1b.1 → P2c → P2g-QOS → P2g-CURR → P2g-MEAS → P2g-EVOL
→ P1b.2 → P1b.3 → P2d → P2e → P2f → P2b-CONTRACT
→ P3a → P3b → P4-DEV → P4-FORGE → P4-AUTONOMOS → P4-FREEZE

PROIBIDO DE VERDADE:
- git add -A
- criar AaeosRunApplication / second ledger / ACDE loop
- parar o programa por “precisa emenda humana de fronteira de provider”
- reabrir P0/P1a/P1-JSON path law
- claim 50× vanity / GOD_SOTA / PHPUnit = REAL_OPERATION
- dizer “80% no teste = teto do modelo”

NÃO PARE POR:
- review_basis rehash
- Hermes sem atlas_apply_patch hoje (é R104-TRANSPORT residual)
- falta de “dois GateEvaluated” se testes GREEN e PHASE checklist ok
- pedir operador como revisor técnico

PARE SÓ SE:
- faltar credencial/env real de P4 (ATLAS_P4_PG_*) e você chegou em P4
- precisar inventar órgão proibido
- FOREIGN_WIP cobrir o único arquivo que você tem que editar

COMECE AGORA em EXECUTE P2a.1. Trabalhe. Commit. Evidência. Continue.
```
