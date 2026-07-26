# Atlas Agenda (iOS) — Prompt COPY · melhorar o MT AAEOS

> **Uso:** no Atlas Agenda / Atlas AI (iOS) → botão **copiar** / colar em sessão Codex·Claude·Cursor.  
> **Alvo do trabalho:** só o plano de implementação (ainda **não** implementar P0–P4 de código, salvo o operador pedir).  
> **Gate verbal:** se a sessão pedir confirmação, responda `MELHORAR MT AAEOS`.

---

## BLOCO PARA COPIAR (do `BEGIN` ao `END`)

```
BEGIN_ATLAS_AGENDA_PROMPT
ATLAS · AGENDA iOS · OFERTA #1 · MELHORAR O MT AAEOS (nível absoluto)

Você NÃO está aqui para “dar por pronto” o plano.
Você NÃO está aqui para implementar P0–P4 de código nesta rodada (a menos que o operador diga EXECUTE P0).
Você ESTÁ aqui para melhorar o arquivo do plano até o nível mais absoluto possível.

══════════════════════════════════════════════════════════════════
CORREÇÃO PÉTREA (leia 2×)
══════════════════════════════════════════════════════════════════
Alguém (humano ou IA) usou / leu o MT e ACHOU QUE JÁ ESTAVA CERTO / COMPLETO.
ISSO ESTÁ ERRADO.

- MT v2 (§0–§44) é BASE FORTE, não teto.
- “1438 linhas” ≠ completeza absoluta.
- Scorecard GOD/SOTA ~9.5 ≠ programa Elite Deepening DONE.
- Se você for tentar “só polir e declarar ok” → PARE. Continue aprofundando.

══════════════════════════════════════════════════════════════════
ARQUIVO ÚNICO DE TRABALHO (os dois mexem AQUI)
══════════════════════════════════════════════════════════════════
CANÔNICO (editar):
  docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER.md

SATÉLITES (atualizar se o MT mudar — não virar plano paralelo):
  docs/evidence/2026-07-23-aaeos-elite-deepening/LEDGER.md
  docs/evidence/2026-07-23-aaeos-elite-deepening/SCOREBOARD.md

PROIBIDO criar segundo “plano mestre” em outro path.
Se faltar seção → ACRESCENTAR no MASTER (§45+), atualizar índice + §44 checklist.

══════════════════════════════════════════════════════════════════
QUEM SÃO “OS DOIS”
══════════════════════════════════════════════════════════════════
1) Agente A — Cursor / sessão que abriu o MT (já tem contexto da auditoria AAEOS).
2) Agente B — você (cola deste prompt via Atlas Agenda iOS / outra sessão).

Regra de colaboração:
- Antes de editar o MASTER: leia o arquivo inteiro (ou § índice + §44 + changelog).
- Não apague conteúdo válido; aprofunde, corrija, complete gaps.
- Se discordar do outro agente: registre DEBATE curto no LEDGER (1 parágrafo) + escolha honesta.
- Commits: branch local main · git add -- <só arquivos do slice> · nunca git add -A.
- Mensagem: docs(core): AAEOS-MT improve <foco curto>

══════════════════════════════════════════════════════════════════
MISSÃO DESTA RODADA
══════════════════════════════════════════════════════════════════
Eleve o MT ao nível ABSOLUTO de plano de implementação elite.

Faça, nesta ordem:

1) AUDIT DE BURACOS
   - Varra §44 “nada omitido”. Liste o que ainda falta de verdade (não invente drama).
   - Cruze com disco real:
       app/Services/Ai/Aaeos/**
       app/Services/Ai/AgenticEngineeringOs/** (só o que o MT precisa citar)
       app/Services/Ai/EngineeringKernel/EliteExecutorKernel.php
       docs/evidence/2026-07-23-aaeos-{god-sota,operate,hygiene}/
       docs/engineering-knowledge-base/atlas-elite-executors-dev-forge-autonomos.md
       docs/engineering-knowledge-base/atlas-aaeos-vocabulary.md
   - Consulte cérebro se disponível:
       php artisan atlas:context-pack "AAEOS MT absolute completeness gaps" --json

2) COMPLETE O QUE FALTAR NO MASTER
   Exemplos de gaps típicos (verifique se já estão; se não, escreva):
   - Pseudocódigo / contratos de API internos faltantes
   - Tabelas de flags CLI vs receipt fields (1:1)
   - Lista explícita de testes NEW com nomes de arquivo
   - Mapa de riscos → mitigação → sinal de detecção
   - Critérios de ACEITE mensuráveis por fase (pass/fail comandos)
   - “Definition of READY” por fase (não só do programa)
   - Dependências externas (DB tables: atlas_ledger_events, task packets…)
   - Janela de deprecação (cycle alias, OperateScorecard orphan)
   - Exemplos de receipt JSON dry-run vs live (realistas)
   - Protocolo de conflito entre dois agentes no mesmo arquivo
   - Checklist de review do PR/commit do próprio MT
   - Ligação explícita residual R1–R15 → checkbox da fase → comando de prova
   Qualquer gap real vira §45, §46… + linha em §44.

3) CORRIJA ERROS FACTUAIS
   - DISK-RECEIPT antigo (“archive not done”, “CLI optional”) NÃO pode contradizer o MT.
   - R4: com --live Autonomos JÁ chama brain:next — o MT deve refletir isso.
   - Composite vivo ~9.52/9.56 vs snapshot 9.33 — deixar explícito.
   - Certify ainda tem hints 9.2 — o MT deve tratar como BUG do programa, não vitória.

4) NÃO IMPLEMENTAR CÓDIGO DE P0–P4
   A menos que o operador escreva literalmente: EXECUTE P0
   Nesta oferta Agenda: só plano + evidence harness docs.

5) FECHE A RODADA COM PROVA
   No LEDGER, acrescente:
   - o que foi adicionado/corrigido (bullets)
   - gaps restantes honestos (se houver)
   - próximo passo recomendado (continuar MT | EXECUTE P0)
   No MASTER: changelog §20 + bump versão (v2.1, v2.2…)
   Atualize SCOREBOARD só se mudar dimensão do programa MT (não invente 10/10).

══════════════════════════════════════════════════════════════════
LEIS (não negociar)
══════════════════════════════════════════════════════════════════
- main local only · commits escopados
- bar(Dev)=bar(Forge)=bar(Autônomos) · não fundir os 3 modos
- AAEOS governa; músculo = brain/task/kernel — não reimplementar
- Quarantine archive FROZEN — não reanimar
- ACDE/atlas:loop:* morto · keep-list AtlasLoop*
- Anti-Goodhart: não “completar” o plano com faxina vazia
- Terminal-first: não desviar para casca iOS/Mac nesta oferta
- Learning pending_review · sem auto-promote

══════════════════════════════════════════════════════════════════
FORMATO DE RESPOSTA AO OPERADOR (no chat)
══════════════════════════════════════════════════════════════════
1) 3–6 bullets: o que melhorou no MT
2) path do arquivo
3) gaps ainda abertos (honestos) OU “nenhum gap estrutural óbvio”
4) pergunta única: “Autoriza EXECUTE P0 ou mais uma rodada de absolute no MT?”

Não despeje o MT inteiro no chat. Edite o arquivo.

══════════════════════════════════════════════════════════════════
IDEIAS DO ATGENDA / OPERADOR (encaixe sem diluir)
══════════════════════════════════════════════════════════════════
O Atlas Agenda iOS é a superfície de captura desta oferta.
Se o operador (ou o Agenda) trouxer ideias novas:
- Avalie com o filtro de 5 (soberania, antifragilidade, fim-a-fim, destrava função, não polish).
- Se passar: incorpore no MASTER como § nova ou checkbox de fase.
- Se for vanity/casca/ACDE: recuse em 1 linha no LEDGER.

END_ATLAS_AGENDA_PROMPT
```

---

## Como usar no iOS

1. Abra **Atlas Agenda** (ou Atlas AI sheet).  
2. Crie / abra o item da oferta: **Melhorar MT AAEOS (nível absoluto)**.  
3. Cole o bloco `BEGIN…END` (ou use o botão copiar deste arquivo no desktop).  
4. Dispare a sessão (Codex / Claude / Cursor) com cwd `atlas-server`, branch `main`.  
5. Gate: `MELHORAR MT AAEOS`.  
6. Repita o botão enquanto ainda houver gaps honestos.  
7. Só depois: oferta #2 = `EXECUTE P0` (outro prompt).

## Relação com o plano

| Artefato | Papel |
|---|---|
| Este prompt | Oferta Agenda #1 — melhorar o plano |
| `…-aaeos-elite-deepening-MASTER.md` | Plano canônico (os dois agentes editam) |
| Evidence `…-aaeos-elite-deepening/` | LEDGER/SCOREBOARD da melhoria |

## Oferta #2 (não misturar nesta cola)

Quando o operador autorizar implementação:

```
EXECUTE P0 · AAEOS-MT
Seguir docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER.md §9 P0 + §29 Tasks A–C
main only · commits escopados · não reanimar Quarantine
```
