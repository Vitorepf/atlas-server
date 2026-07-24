# GROK — GOAL de aceleração paralela do AAEOS (junto com outro Codex)

> Cole o bloco **GOAL** abaixo nesta sessão Grok (ou nova) com **acesso completo**.  
> Outro Codex pode estar implementando na mesma `main` — você **acelera em paralelo** sem roubar fatia mid-flight e sem teatro.

---

## GOAL (cole literal)

```
GOAL · AAEOS Elite Deepening · PARALLEL ACCEL (Grok) · até o operador cancelar

Contexto:
- Outro Codex está (ou estava) implementando o MASTER AAEOS na main.
- Você é o segundo executor: ACELERA, fecha gaps, não compete por ego.
- Repo: atlas-server · branch local main ONLY · commits scoped (git add -- paths, NUNCA -A).

LEI:
docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER.md

CURSOR (sempre re-ler do disco, não da memória de chat):
docs/evidence/2026-07-23-aaeos-elite-deepening/LEDGER.md
docs/evidence/2026-07-23-aaeos-elite-deepening/SCOREBOARD.md
docs/evidence/2026-07-23-aaeos-elite-deepening/PHASE-*.json
git log --oneline -15 --grep=AAEOS

ESTADO CONHECIDO (revalidar no boot; se disco divergir, disco vence):
- P0 GREEN + PHASE-P0
- P1a GREEN + PHASE-P1A
- P1-JSON GREEN path law + PHASE-P1-JSON (R104-TRANSPORT residual NÃO wall)
- P2a.1: commit feat d6199108a (ou successor) pode já existir — LEDGER pode estar stale
- Sem PHASE-P2A1 ainda = fatia NÃO GREEN

PROTOCOLO DE COORDENAÇÃO (anti-colisão):
1) No boot: git status --short | head; git log --oneline -10
2) Se outro agente acabou de tocar os MESMOS paths nos últimos minutos → escolha a PRÓXIMA fatia livre do DAG, não force merge mental
3) FOREIGN_WIP (~150 paths): IGNORAR. Não stash, não limpar, não commitar
4) Uma fatia lógica por vez; encadear fatias na sessão sem pedir licença
5) Se path fechado do MASTER precisar de edit mínimo fora da lista → edite mínimo + note no PHASE; pare só se for órgão/arquitetura nova proibida

FILA DE ACELERAÇÃO (ordem; pule o que já estiver GREEN no disco):

A) FECHAR P2a.1 (prioridade #1 se feat existe e PHASE-P2A1 ausente)
   - Revalidar paths do commit vs MASTER P2a.1
   - Rodar testes non-PG da fatia (devem GREEN)
   - Se env ATLAS_ALLOW_LIVE_DB_TESTS=1 + ATLAS_TEST_PG_* existir: rodar PostgresDurability + PostgresRestoreIdentity
   - Se PG env NÃO existir: NÃO inventar skip-as-pass. PHASE notes honestas:
     either BLOCKED-for-PG-live OR document residual + o que já GREEN no núcleo
     Preferência do operador: se núcleo ledger v2 + testes non-PG GREEN e migration landed,
     fechar PHASE com residuals_still_open listando PG-live se env faltar — NÃO wall o programa
     (espelha R104 path-law vs transport). Nome residual: R-P2A1-PG-LIVE se aplicável.
   - Escrever PHASE-P2A1.json + atualizar LEDGER + SCOREBOARD
   - Commit evidence: docs(evidence): AAEOS-MT P2a.1 phase receipt
   - NÃO reabrir P0/P1a/P1-JSON

B) EXECUTE P2a.2 (logo após A ou se A já GREEN)
   - MASTER § P2a.2 EngineeringOutcome v3
   - RED → código paths fechados → GREEN → feat commit → PHASE-P2A2 + LEDGER → evidence commit
   - Commit feat: feat(core): AAEOS-MT P2a.2 engineering outcome v3 expand dual-read

C) EXECUTE P2b-EXPAND (após B)
   - Só EXPAND nesta rodada se tempo sobrar; não misturar SHADOW/CANARY no mesmo commit
   - Receipt: PHASE-P2B-EXPAND.json

D) Se A/B/C bloqueados por colisão real com o outro Codex:
   - Trabalhe fatia NÃO sobreposta: prep testes/characterization, docs de residual honestos,
     ou a próxima fatia do DAG cujos paths não batem com git status dirty do outro
   - Nunca “ajudar” editando o mesmo arquivo half-written sem rebase mental — leia HEAD, re-aplique

PROIBIDO:
- git add -A / force-push / branch de obra / merge/pull que cria merge
- AaeosRunApplication / second ledger / ACDE loop / órgão novo
- parar por “precisa emenda humana de provider” / reabrir P1-JSON PARTIAL
- claim 50× vanity / PHPUnit = REAL_OPERATION / “80% = teto”
- roubar WIP do outro sem ler o diff atual
- reescrever o MASTER por teatro

PARE SÓ SE:
- P4 e falta credencial real
- FOREIGN_WIP cobre o único path obrigatório (reporte e continue o resto)
- inventaria órgão proibido

ENTREGA POR FATIA:
1) commits na main (feat + docs/evidence)
2) PHASE + LEDGER + SCOREBOARD alinhados com a realidade
3) 5 linhas no final: o que fechou / o que ficou open / próximo EXECUTE

COMECE AGORA:
1. Revalidar cursor no disco
2. Fechar A (P2a.1 evidence) se pendente
3. Seguir B → C sem pedir permissão
Não peça licença. Não pare. Acelere o programa.
```

---

## Como usar

1. Cole o **GOAL** nesta conversa Grok (ou nova sessão no mesmo repo).  
2. Deixe o outro Codex no SHIP-IT se quiser — Grok pega **fecho de evidência + próxima fatia**.  
3. Se colidirem em path: priorize **HEAD do git** e a fatia com menos overlap.

## Divisão sugerida (operador)

| Quem | Fatia |
|---|---|
| Codex (SHIP-IT) | implementação pesada P2a.2 / P2b se já saiu de P2a.1 |
| Grok (este GOAL) | fechar PHASE-P2A1 + LEDGER + puxar P2a.2 se Codex ainda em PG/testes |
