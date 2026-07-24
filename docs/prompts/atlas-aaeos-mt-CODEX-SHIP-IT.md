# CODEX — AAEOS (prompt que TRABALHA)

> **Único prompt válido.** Os outros (`ZERO-FULL-MISSION`, `WORK-HARD`) são lixo — **não use**.
> Cole o bloco abaixo em Codex **novo** · cwd `atlas-server` · branch `main` · acesso completo · esforço alto.

---

## COPIE DAQUI

```
ATLAS · AAEOS Elite Deepening · EXECUTE · até o operador cancelar.

Você implementa o programa AAEOS na main. Sucesso = código + testes + commits + evidência.
Fracasso = parar para “auditar”, “emendar MASTER”, “esperar humano”, reabrir fase GREEN.

LEI (só isto):
docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER.md
docs/evidence/2026-07-23-aaeos-elite-deepening/LEDGER.md
docs/evidence/2026-07-23-aaeos-elite-deepening/SCOREBOARD.md

CURSOR (fato, não discuta):
P0 GREEN · P1a GREEN · P1-JSON GREEN (path law)
R104-TRANSPORT residual NÃO bloqueia nada
PRÓXIMO = EXECUTE P2a.1
Depois: P2a.2 → P2b-EXPAND→SHADOW→CANARY→CUTOVER → P1b.1 → P2c → P2g-QOS → P2g-CURR → P2g-MEAS → P2g-EVOL → P1b.2 → P1b.3 → P2d → P2e → P2f → P2b-CONTRACT → P3a → P3b → P4-DEV → P4-FORGE → P4-AUTONOMOS → P4-FREEZE

PROTOCOLO (repita até cancelar):
1) git branch --show-current = main
2) Abra a fatia ACTIVE no MASTER (paths + checklist + testes)
3) RED nos testes da fatia → edite só paths da fatia → GREEN
4) Commit 1: feat(core): …  com `git add -- <paths>` (NUNCA -A)
5) PHASE-*.json + LEDGER + SCOREBOARD → Commit 2: docs(evidence): …
6) Marque GREEN e PULE para a próxima fatia do DAG na mesma sessão
7) FOREIGN_WIP: ignore (não limpe, não stash, não toque)

PROIBIDO (halt real):
- git add -A / force-push / branch de obra / merge
- criar AaeosRunApplication, second ledger, ACDE loop, órgão novo
- pular fatia crítica do DAG
- claim 50× vanity / PHPUnit = REAL_OPERATION / “80% = teto do modelo”
- reabrir P0 / P1a / P1-JSON path law
- parar porque “Hermes sem FC nativo” / “precisa emenda humana” / “phase PARTIAL”
- parar porque falta “dois GateEvaluated” se testes GREEN e checklist da fatia ok
- pedir permissão para continuar a próxima fatia já no DAG

SE PATH FALTAR na lista fechada mas for necessário pro requisito JÁ listado:
- edite o mínimo + anote no PHASE notes
- só pare se for arquitetura nova proibida

PARE SÓ SE:
- chegou em P4 e falta credencial real ATLAS_P4_PG_* / ATLAS_TEST_PG_*
- FOREIGN_WIP cobre o ÚNICO arquivo obrigatório da fatia (reporte e continue o resto)
- precisaria inventar órgão proibido

COMECE AGORA:
1. cat LEDGER.md SCOREBOARD.md
2. Abra MASTER seção P2a.1
3. Implemente P2a.1 até GREEN + 2 commits
4. Continue a próxima fatia sem pedir licença

Não peça permissão. Não pare. Não reescreva o plano. Entregue o programa.
```

---

## Por que os prompts anteriores eram horríveis

| Prompt | Falha |
|---|---|
| ZERO-FULL-MISSION | 326 linhas de cerimônia → Codex vira auditor e STOP |
| WORK-HARD | Ainda apontava NEXT=P1-JSON e treinava “pare se residual” |
| SHIP-IT v1 | Bom na intenção, mas fraco contra MASTER/PHASE com `exit_checklist_all_true:false` |

Correção de evidência: PHASE-P1-JSON = GREEN path law; `exit_checklist_all_true=true` para path law; residual R104-TRANSPORT não wall.
