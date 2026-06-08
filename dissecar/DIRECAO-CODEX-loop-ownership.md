# DIREÇÃO → Codex: você assume o Atlas Unified Evolution Loop

> Documento-mãe. Cole isto + os 3 anexos no Codex. A partir daqui, **você é o dono do loop**.
> Repo: `/Users/vitorepf/develop/Atlas/atlas-server`. Operador: Vitor (local-first, Mac).

## Ordem de leitura (leia nesta sequência, tudo em `dissecar/`)
1. `HANDOFF-CODEX-unified-evolution-loop.md` — contexto completo, arquitetura, **§4 o gate (obrigatório)**, estado atual.
2. **Este doc** — direção, prioridade, contrato de operação.
3. `OBRA-CODEX-reprova-out-of-process.md` — obra #1 (comece por ela).
4. `OBRA-CODEX-materializacao-P4.md` — obra #2 (o salto, mais pesada).

---

## 1. Contrato de operação (regras que NUNCA mudam — quebrar invalida tudo)
1. **PROPOSE-ONLY. NUNCA mergeia pra main.** Toda saída é hipótese pra humano. 3 camadas no DB proíbem merge — não as contorne.
2. **Honestidade acima de verde-falso.** Rejeição/null/"no winner" é o sistema funcionando. **NUNCA fabrique nota.** No escopo completo dos 4 pontos NÃO é 9.5 — não minta sobre isso pro operador.
3. **Provider-agnóstico.** Default `hermes_cli` (free, strongest). Nunca hardcode provider.
4. **Consulte o Atlas antes de mexer** (`atlas:ai:session-bootstrap` / `place-feature`). Depois, sincronize KB (`sync --prune` + `index-code --prune --workspace`).
5. **Vocabulário proibido:** Jarvis, Rivals, benchmark, superiority, concurrent. Atlas **substitui** Claude Code/Codex como produto e os **usa como engine** — nunca "concorre".
6. **Disciplina de toda mudança no loop:** testes verdes (`php artisan test tests/Unit/Ai/AutonomousEvolution/`) + phpstan nível 5 limpo + **se tocar o gate, re-rode as exploits** (`storage/atlas/tmp/exploit*.php`). Suíte verde NÃO garante o gate — viva isso.

---

## 2. PRIMEIRO MOVIMENTO (faça hoje, antes de qualquer obra nova)
**Não deixe as 73 propostas apodrecerem.** Existe valor real parado em `storage/atlas/loop/unified/run-20260608-133531-fe7dda/proposals.jsonl` (37 remoções de código morto + 36 docs completados, yield 98,6%, propose-only). Elas são **candidatas certificadas pelo gate, NÃO verdades.** Transforme-as em verdades:
- Implemente/rode a **re-prova out-of-process** (obra #1) em cima desse run. As que sobreviverem viram `independently_verified` → prontas pra você (humano/operador) revisar e mergear manualmente. As que caírem são aprendizado real sobre o gate.

## 3. ROADMAP priorizado (a sequência, com custo honesto)
| # | O quê | Por quê | Custo | Risco |
|---|---|---|---|---|
| **1** | **Re-prova out-of-process** (obra #1) | blinda as 73 + fechadura final pra tudo; quebra ponto-cego de self-verification | dias | baixo |
| **2** | **Materialização P4-pequeno** (obra #2) | **o salto** — destrava o 4º ponto (implementação real com teste como holdout) | semanas | médio |
| **3** | **Detector de duplicação/clones** (modo novo) | ponto 3 do operador ("duplicações, encanamentos") — hoje não cobre | dias-semana | baixo |
| **4** | **Síntese meta dos achados** | "47 docs faltando a mesma seção → conserta a raiz, não 47 sintomas" | semana | baixo |
| **5** | **Supervisor real 24h** | o loop morreu em 6.6h; watchdog vigiava o pid errado. Auto-restart + heartbeat (use `heartbeat.json` + idade do `report.json` como liveness) | dias | baixo |
| **6** | **Robustez de DB da campanha P1/P4** | verificar/completar retry+reconnect contra blip de Postgres; no worktree atual procure `AtlasLoopDbResilience` + testes antes de reimplementar | dias | baixo |
| **7** | **Modos novos**: complexidade/hotspot, cobertura de teste, doc-drift/dedup | cobre o resto de 2+3 | semanas | baixo |
| **8** | **Priorização por blast-radius** (via code-graph AP-811) | trabalha no que IMPORTA, não no que tem mais contagem | semana | baixo |
| **9** | **Learning flywheel** (aprovado/rejeitado → prioriza descoberta) | loop fica esperto, não só ocupado (compounding) | semanas | médio |
| **10** | **Generalização cross-domínio** (cyber primeiro — é checável) | o salto de tese: o loop vira plataforma dos 15 domínios | meses | alto |

**Comece 1 → 2.** Não pule pra "polish" (mais um teste no gate, mais um campo no relatório) enquanto 2 (o salto real) não estiver desenhado — foi o aviso que deixei no §7 do handoff.

## 4. A fronteira honesta (NÃO tente apagar — é design, não bug)
O loop fecha sozinho só o que é **behavior-free/checável**. Continuam roteados pra **humano/Forge**, sempre:
- julgamento semântico (qual duplicata manter, qual encanamento é o certo);
- implementação grande (meses);
- refactor multi-arquivo amplo (até ter gate próprio provado).

Evoluir = empurrar a LINHA (materialização sobe P4-pequeno pra dentro; modos novos cobrem mais de 2+3), **não apagar a linha**. Um loop que promete fechar julgamento sozinho está mentindo.

## 5. Como você sabe que está ganhando (métricas honestas)
- **`independently_verified`** (não só `certified`) — o tier que conta, depois da obra #1.
- **yield/aproveitamento** por modo no `atlas:loop:unified:report`.
- **`merged_to_main:false`** sempre presente (a invariante).
- Cada modo novo: provado VIVO com Hermes (gasto real) + suíte verde + phpstan limpo + exploits do gate re-rodados.
- **Honestidade do teto**: você relata ao operador o que o loop fecha sozinho (~9 nos cores) e o que roteia — nunca um 9.5 inflado.

## 6. Comandos essenciais
```bash
php artisan atlas:loop:unified --max-seconds=86400 --provider=hermes_cli --modes=deadcode,docs_structure --scenarios=2 --max-per-cycle=5 --idle-seconds=600 --json
php artisan atlas:loop:unified:report
touch storage/atlas/loop/unified/STOP                                  # kill-switch
php artisan atlas:loop:campaign --campaign-id=019ea671-005b-72a7-9977-a19159e181ae --provider=hermes_cli --no-shadow --json
php artisan test tests/Unit/Ai/AutonomousEvolution/Verify/
php -d memory_limit=3G vendor/bin/phpstan analyse --no-progress --level=5 app/Services/Ai/AutonomousEvolution/Verify/
```

---
**TL;DR:** Você é o dono do loop agora. (1) Re-prova out-of-process nas 73 propostas — não deixe apodrecer. (2) Materialização pra destravar P4 — mas leia as 4 armadilhas (o gargalo é o teste RED frozen, não o workspace). Propose-only, nunca mergeia, nunca fabrique nota, consulte o Atlas, não regrida o gate (§4 do handoff). A fronteira humano/Forge é design, não bug.
