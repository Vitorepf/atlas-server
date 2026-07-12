# ACOS Max — Scoreboard de Execução v1 (estado durável; a memória entre sessões e entre IAs)

> **Como usar:** este arquivo é ESTADO, não spec. Cada slice muda de estado NO MESMO commit que o implementa. Estados válidos: `pending` · `in_progress` · `landed(<sha>)` · `suspended(ELEV-28)` · `refutado(<motivo>)` · `blocked_by:<dep>` · `pending_window:<janela>`. Uma IA nova retoma lendo APENAS: plano (`atlas-acos-max-frontier-plan-v1.md`) + playbook (`atlas-acos-max-implementation-playbook-v1.md`) + este scoreboard. Marque `[x]` só em estado terminal (`landed`/`suspended`/`refutado`). Slices de 2 partes (freeze/série etc.) só fecham com as duas. NUNCA reordenar lotes nem editar aceites aqui.
>
> **100% =** 255/255 em estado terminal + gates L0–L12 verdes + MARCO ESP-V1 + critérios finais NO MESMO CORTE (a lista completa está no LOTE 12 abaixo e no cabeçalho do playbook: §vii itens 1-10 + §viii itens 11-17 + pétreos §xiii + §xv itens 18-21 do plano + M>1 e R>0).

## LOTE 0 — Higiene imediata (M0) — GATE: hooks 1×/evento · baseline latência carimbada · receipts vivos · refs impressos · ESP-00 publicado
- [x] ESP-00 — landed(bb15641a19) · Evidence Ledger `EVIDENCE_PACKED` event_id=`01KXA1JWR53YAYT915GTGS0RXB` · content_hash=`e71448b0b81b65da3e16aa86927f9c0c632234efb7cae13b2fad789c3efd4b87` · unexplained=0 · JSONL runtime=`storage/app/atlas/evidence/acos-max-esp-00-ground-truth.jsonl`
- [ ] TETO-06 — blocked_by:ELEV-22 (deps: claims/blackboard; desbloqueia ao landar ELEV-22)
- [x] TETO-09 — landed(8e8b54d42c) · Evidence Ledger event_id=`01KXA1SZ0TYE2VZ9HAG46YDY0D` · content_hash=`b6f672b050b12282540eeb797bedfec7ca70b7546fa7efa306c7c5da757c509d` · opção b endurecimento áreas 2/11/14 · gatilhos MULTX-02/incidente
- [x] MAXG-01 (mínimo) — landed(f1f75d5dda) · freeze `aobg.latency_ledger.v1` content_hash=`9f1338cdd9d78e3a1b8e360455a8684fbdf22e8b0364877fb2704b2c5fe23fd3` · JSONL `storage/app/atlas/evidence/acos-measure-freeze.jsonl` · ledger `storage/atlas/aobg/latency-ledger/` · cmd `atlas:context:latency` · WDG `wdg-01.aobg_latency` · aceite pleno p95-de-1d = pending_window
- [x] MAXE-02 — landed · removed absolute duplicate AOBG hooks in .claude/settings.json (1×/event)
- [x] MAXE-03 — landed · atlas-ctx.sh activate TTL 6h + hard timeout/perl-alarm on pack
- [x] MAXF-01 — landed · compactForScope failed_open + repair migration receipts; live Schema@5433 pending_window (pgsql hang)
- [x] MAXE-01 — landed(8b30866a95bd) · renderMarkdown prints ref=<canonical> per item + citation footer; deliveredFromPack == rendered refs; ARFL share>0 = pending_window
- [x] ELEV-22 — landed · hot-list acos-max-elev-22.v1 + PreToolUse advisory claim check + release on scoped commit; fail-open; TTL 900s

## LOTE 1 — Freios (F0) — GATE F0: porta única observe · ledgers imunes · event_hash+cadeia+âncora · captura operador viva · restore drill · attempts terminais
- [ ] ASI-05 — pending
- [ ] ASI-01 — pending
- [ ] ASI-02 (+ELEV-08) — pending
- [ ] MAXI-01 — pending
- [ ] ASI-03 — pending
- [ ] ASI-04 — pending
- [ ] ESP-01 — pending
- [ ] MAXK-07 (+ELEV-09) — pending
- [ ] MAXL-01 — pending
- [ ] MAXL-02 (+ELEV-11) — pending
- [ ] MAXN-01 — pending
- [ ] ELEV-17 — pending
- [ ] TETO-05 — pending (obra-retro; cadência a cada fecho de lote daqui em diante)

## LOTE 2 — Réguas v2 congeladas (M1) — GATE M1: todo freeze com hash+judge≠author · golden v2 targets_available==cases · registry de séries populado
- [ ] ELEV-02 (ASI-METRIC) — pending
- [ ] ELEV-12 — pending
- [ ] ELEV-20s — pending
- [ ] ELEV-25 — pending
- [ ] MAXG-01 (completo) — pending
- [ ] MAXG-02 — pending
- [ ] MAXA-03 — pending
- [ ] MAXB-02 — pending (GÊMEO — fecha no landing do MAXG-04; ver plano vi-b)
- [ ] MAXG-04 — pending
- [ ] MAXH-01 — pending
- [ ] MAXI-02 — pending
- [ ] MAXI-03 — pending
- [ ] MAXJ-01 — pending
- [ ] MAXJ-05 — pending
- [ ] MAXK-01 — pending
- [ ] MAXK-04 — pending
- [ ] MAXL-06 — pending
- [ ] MULTK-01 — pending
- [ ] MULTN15-01 — pending
- [ ] MULTN15-02 — pending
- [ ] MULTN17-04 (freeze) — pending
- [ ] MULTX-01 (freeze) — pending
- [ ] MULTX-06 (freeze) — pending
- [ ] MULTJ-01 — pending
- [ ] MULTJ-02 — pending
- [ ] MULTJ-03 — pending
- [ ] TETO-02 — pending (mission_e2e_rate — freeze junto das réguas)

## LOTE 3 — Eficiência estrutural + protocolo (M2) — GATE M2: floor intermediário ELEV-16 · toda flag no PromotionProtocol · :356 wirado · daemon com manifest
- [ ] ELEV-26s — pending
- [ ] MULTX-09 — pending
- [ ] MAXA-02 — pending
- [ ] MAXA-07 — pending
- [ ] MAXA-01 — pending
- [ ] MAXA-10 — pending
- [ ] MAXB-01 — pending (GÊMEO — fecha no landing de MAXA-01+MAXA-02; ver plano vi-b)
- [ ] MAXE-04 — pending (harmonizado com MAXG-01 — uma régua, duas granularidades; ver plano vi-b)
- [ ] MAXE-05 — pending
- [ ] MAXB-09 — pending
- [ ] MAXG-09 — pending
- [ ] MAXG-10 (+ELEV-16) — pending
- [ ] MAXH-03 — pending
- [ ] MAXK-05 — pending
- [ ] MAXK-06 — pending
- [ ] MAXM-05 — pending
- [ ] MAXM-08 — pending
- [ ] MAXN-03 (scaffold) — pending
- [ ] MULTN15-06 — pending
- [ ] ESP-02 — pending
- [ ] ESP-03 — pending
- [ ] ESP-05 — pending
- [ ] ELEV-19 — pending
- [ ] ELEV-24 — pending
- [ ] ELEV-27 — pending
- [ ] ELEV-29s — pending
- [ ] TETO-08 — pending (cockpit read-only; fontes futuras = unavailable)

## LOTE 4 — Ligar o fluxo (F1; FLIPS = OPERADOR) — GATE F1-fluxo: ≥5 outcomes/dia 7d · decision_id 100% · reflection/pattern com dado real · distiller shadow dual-read
- [ ] MAXC-01 — pending
- [ ] MAXC-02 — pending
- [ ] MAXC-06 — pending
- [ ] ASI-06 (preflight; FLIP = operador) — pending
- [ ] ASI-07 (floor E2E; FLIP = operador) — pending
- [ ] ASI-08 — pending
- [ ] ASI-09 — pending
- [ ] (CHECK v1 onda 5: ENG-13/14/15, PIP-07, CPT-10, ADV-01 — só verificar estado; dono = executor v1) — pending
- [ ] MULTV-10 (seam default-OFF) — pending
- [ ] ASI-10 (flip via janela ELEV-26s) — pending

## LOTE 5 — Corpus e cobertura (M3) — GATE M3: corpus qualificado ≥300 (taxa gated, nunca contagem-aceite) · provenance 100% · golden v2 medindo vivo
- [ ] ELEV-21 — pending
- [ ] MAXA-05 — pending (dep externa: MEM-05 v1)
- [ ] MAXA-06 (fase 1) — pending
- [ ] MAXD-01 — pending
- [ ] MAXD-09 — pending
- [ ] MAXD-06 — pending
- [ ] MAXD-08 — pending
- [ ] MAXD-02 — pending
- [ ] MAXH-02 — pending
- [ ] MAXI-04 — pending
- [ ] MAXM-01 — pending
- [ ] RAGX-01 — pending
- [ ] TETO-01 — pending (N-Capture Drill — 1ª execução)

## LOTE 6 — VERTICAL 1 (subset mínimo + MARCO; gate F1→F2)
- [ ] MAXN-04 — pending
- [ ] MULTN17-03 — pending
- [ ] ESP-09 — pending
- [ ] ESP-04 — pending
- [ ] ESP-07 — pending
- [ ] MAXJ-02 — pending
- [ ] MAXH-04 — pending
- [ ] MAXH-05 — pending
- [ ] ESP-08 — pending
- [ ] MULTX-01 (série cheia) — pending
- [ ] **MARCO ESP-V1** (loops_complete ≥ 1, proven_real, ids encadeados, zero fixture) — pending

## LOTE 7 — M4 completo — GATE M4: measured_share subindo · funil com 3 executores den>0 · receipt MULTV-01 acumulando · zero atuador sem shadow
- [ ] MAXA-04 — pending
- [ ] MAXA-08 — pending (GÊMEO — fecha no landing do MAXB-03; ver plano vi-b)
- [ ] MAXB-03 — pending
- [ ] MAXB-04 — pending
- [ ] MAXB-05 — pending
- [ ] MAXB-06 — pending
- [ ] MAXB-07 — pending
- [ ] MAXB-10 — pending (pode ser absorvido pelo MAXB-03 — registrar decisão)
- [ ] MAXC-03 — pending
- [ ] MAXC-04 — pending
- [ ] MAXC-05 — pending
- [ ] MAXD-03 — pending
- [ ] MAXD-04 — pending
- [ ] MAXD-07 — pending
- [ ] MAXE-08 — pending
- [ ] MAXE-06 — pending
- [ ] MAXE-07 — pending
- [ ] MAXF-02 — pending
- [ ] MAXF-03 — pending
- [ ] MAXF-08 — pending
- [ ] MAXF-10 — pending
- [ ] MAXF-04 — pending
- [ ] MAXF-06 — pending
- [ ] MAXF-07 — pending
- [ ] MAXF-05 — pending
- [ ] MAXH-06 — pending
- [ ] MAXI-05 — pending
- [ ] MAXI-06 — pending
- [ ] MAXI-07 — pending
- [ ] MAXI-08 — pending
- [ ] MAXJ-03 — pending
- [ ] MAXJ-04 — pending
- [ ] MAXJ-06 — pending
- [ ] MAXK-02 — pending
- [ ] MAXK-03 — pending
- [ ] MAXL-03 — pending
- [ ] MAXL-04 — pending
- [ ] MAXL-05 — pending
- [ ] MAXL-07 — pending
- [ ] MAXL-08 — pending
- [ ] MAXM-02 — pending
- [ ] MAXM-03 — pending
- [ ] MAXM-04 — pending
- [ ] MAXM-06 — pending
- [ ] MAXM-07 — pending
- [ ] MAXN-02 — pending
- [ ] MAXN-03 (fecho) — pending
- [ ] MAXN-05 — pending
- [ ] MAXN-06 — pending
- [ ] RAGX-08 — pending
- [ ] MULTK-03 — pending
- [ ] MULTK-04 — pending
- [ ] MULTK-02 — pending
- [ ] MULTN15-03 — pending
- [ ] MULTN15-04 — pending
- [ ] MULTN15-07 — pending
- [ ] MULTN17-07 — pending
- [ ] MULTN17-01 — pending
- [ ] MULTN17-08 — pending
- [ ] MULTN17-04 (curva) — pending
- [ ] MULTH-01 — pending
- [ ] MULTH-02 — pending
- [ ] MULTH-07 — pending
- [ ] MULTH-03 — pending
- [ ] MULTV-01 — pending
- [ ] MULTV-07 — pending
- [ ] MULTV-05 — pending
- [ ] MULTX-03 — pending
- [ ] MULTX-02 — pending
- [ ] MULTX-04 — pending
- [ ] MULTX-06 (série) — pending
- [ ] ESP-06 — pending
- [ ] TETO-03 — pending (Trajectory Vault — liga assim que ESP-04 landar)

## LOTE 8 — Eixo reflexivo (F2) — GATE F2: cascata ≥50 com estados formais + SLA · séries multi-ator · receipt com self_model + banda
- [ ] ASI-11 (completo; ELEV-10/23) — pending
- [ ] ASI-12 — pending
- [ ] ASI-13 — pending
- [ ] ASI-14 — pending
- [ ] ASI-15 — pending
- [ ] MAXK-08 — pending

## LOTE 9 — Certificação + espinhas (M5) — GATE M5: ADV-Max sem refutação pendente · pétreos §xiii verdes · cascata MULTV-02 advisory ≥30
- [ ] MAXG-03 — pending
- [ ] MAXG-05 — pending
- [ ] MAXG-06 — pending
- [ ] MAXG-07 — pending
- [ ] MAXB-08 — pending
- [ ] MAXH-07 — pending
- [ ] MAXH-08 — pending
- [ ] MAXH-09 — pending
- [ ] MAXH-10 — pending
- [ ] MAXI-09 — pending
- [ ] MAXJ-07 — pending
- [ ] MAXJ-08 — pending
- [ ] MAXK-09 — pending
- [ ] MAXL-09 — pending
- [ ] MULTV-02 — pending
- [ ] MULTV-03 — pending
- [ ] MULTV-09 — pending
- [ ] MULTK-05 — pending
- [ ] MULTK-07 — pending
- [ ] MULTN15-05 — pending
- [ ] MULTN17-02 — pending
- [ ] MULTH-04 — pending
- [ ] MULTH-05 — pending
- [ ] MULTH-06 — pending
- [ ] MULTX-05 — pending
- [ ] ESP-10 — pending
- [ ] ESP-11 — pending
- [ ] ESP-12 — pending
- [ ] REC-01 — pending
- [ ] REC-03 — pending
- [ ] REC-05 — pending
- [ ] REC-02 — pending
- [ ] TETO-10 — pending (digest como produto de revisão)

## LOTE 10 — Fronteira condicional (M6) — GATE M6: todo condicional com A/B REGISTRADO (live OU suspenso/refutado — ambos são sucesso) · REC-04 em shadow com hipóteses de funil
- [ ] RAGX-07 — pending
- [ ] RAGX-06 — pending
- [ ] RAGX-03 — pending
- [ ] RAGX-11 — pending
- [ ] RAGX-05 — pending
- [ ] RAGX-02 — pending
- [ ] RAGX-10 — pending
- [ ] RAGX-09 — pending
- [ ] RAGX-04 — pending (candidato-a-corte declarado)
- [ ] MAXA-09 — pending
- [ ] MAXC-07 — pending (condicional)
- [ ] MAXD-05 — pending (gated MAXA-06)
- [ ] MAXF-09 — pending
- [ ] MAXF-11 — pending (SÓ pós-enforce CPT-09)
- [ ] MULTJ-04 — pending
- [ ] MULTJ-05 — pending
- [ ] MULTJ-06 — pending
- [ ] MULTJ-07 — pending
- [ ] MULTJ-08 — pending
- [ ] MULTJ-09 — suspended(ELEV-28) por construção — destrava com ≥5 skills vivas
- [ ] MULTV-04 — pending
- [ ] MULTV-06 — pending
- [ ] MULTV-08 — pending
- [ ] MULTK-06 — pending
- [ ] MULTK-08 — pending
- [ ] MULTN15-08 — pending
- [ ] MULTN17-05 — pending
- [ ] MULTN17-06 — pending
- [ ] MULTH-08 — pending
- [ ] MULTX-07 — pending
- [ ] MULTX-08 — pending
- [ ] REC-04 (shadow) — pending
- [ ] REC-06 — pending
- [ ] TETO-04 — pending (2º domínio; gated MARCO ESP-V1)
- [ ] TETO-07 — pending (model-refresh drill)

## LOTE 11 — Substrato 10-100× (F3) — GATE F3: pack ≤2s / recall ≤1s / hooks ≤5s sustentados 14d · bancada 12 clientes provada
- [ ] ASI-16 — pending
- [ ] ASI-17 — pending
- [ ] ASI-18 — pending
- [ ] MAXA-06 (fase 2) — pending

## LOTE 12 — Fecho (o mesmo corte)
- [ ] Critérios §vii 1-10 + §viii 11-17 + pétreos §xiii + §xv 18-21 (mission_e2e ≥ alvo · N-Capture Drill executado · ≥1 volta proven_real fora de engenharia · Trajectory Vault com privacy provada) — verdes NO MESMO CORTE — pending
- [ ] MAXG-08 (Marco Zero v2; gatilho ADV-01) — pending
- [ ] MAXL-10 — pending
- [ ] REC-04 flip shadow→atuar — **GATILHO EXCLUSIVO DO OPERADOR** (M>1, R>0, freios verdes) — pending

---
*Criado em 12/07/2026; atualizado no mesmo dia para 255/255 slices em `pending` (família TETO, seção xv). Fonte de atribuição: inventário 5.5 do playbook (autoridade). Atualizar SEMPRE no mesmo commit do slice.*

## ESP-00 — Tabela de ground-truth (corte 2026-07-11; unexplained=0)

| número | valor medido agora | fonte/comando | explicação da divergência |
|---|---|---|---|
| memory entries (77 vs 106) | total=106 · active=90 · archived=16 · with_embedding=106 | `SELECT status, count(*) FROM atlas_memory_entries GROUP BY status` (via artisan/tinker @5433) | **escopo+data**: 106=total rows (Codex); 77=foto Max (plan:282 “77 com embedding”) em corpus menor; vivo canônico=106/90 |
| origination targets (1173 vs 369) | done-set autonomous=1173 · queued-targets live=369 | `wc -l storage/app/atlas/brain/done-set/autonomous.jsonl` · `php artisan atlas:brain:queued-targets --scope=autonomous --json` | **escopo**: 1173=histórico done-set; 369=fila live serving (não são o mesmo denominador) |
| “AWEOS 69 execuções” | atlas_aweos_executions=69 | `DB::table('atlas_aweos_executions')->count()` · canon `atlas-autonomous-work-execution-os.md` | **órgão real** (Mission Control); não é erro de leitor — só fora do mapa MAX* |
| Decide proven_real (52/3481) | 52 proven_real=true / 3481 linhas | `rg --no-ignore` + parse `storage/atlas/atlas_decide/live_outcomes.jsonl` | **confirmado** — sem divergência |
| 87 successes não-provados no ranking? | success∩quality∩¬proven_real=87 · **SIM influenciam** | count no JSONL + `AtlasDecideCostOutcomeRouter.php:254` e `:386-390` | **confirmado comportamento**: router ignora `proven_real`; remédio=ESP-05 |

Receipt: Evidence Ledger `EVIDENCE_PACKED` event_id=`01KXA1JWR53YAYT915GTGS0RXB` · content_hash=`e71448b0b81b65da3e16aa86927f9c0c632234efb7cae13b2fad789c3efd4b87`. Caso negativo do protocolo: unexplained⇒gap; observado unexplained=0 ⇒ zero issues abertas no gaps ledger.
