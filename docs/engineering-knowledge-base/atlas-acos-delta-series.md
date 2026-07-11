# Atlas ACOS Delta Series — doc canônico

> Dono da verdade sobre a série longitudinal de evidência do ACOS (ex `fable-delta-series`).
> Runtime: `atlas:acos:delta-series` (alias legado `atlas:fable:delta-series` durante a transição).

## O que é

A série temporal append-only que registra, **um snapshot por dia-calendário**, o estado resolvido-por-evidência do ACOS (scorecard overall/pipeline + métricas + impact receipts), comparado contra o **Marco Zero congelado de 11-12/06/2026**. É a ÚNICA fonte de dados do gate longitudinal (`atlas:cognition:acos-long-horizon-gate`, floors: 30 dias contíguos, overall ≥ 9.5, pipeline ≥ 9.5, staleness ≤ 2d). Sem esta série íntegra, nenhuma certificação longitudinal do ACOS existe.

- **Arquivo vivo:** `storage/app/atlas/evidence/acos-delta-series.jsonl` (ex `fable-delta-series.jsonl`)
- **Schema da linha:** `{date, recorded_at, baseline_recorded_at, baseline_scorecard_overall, metrics, impact_receipts, sources}`
- **Baseline congelado:** `storage/app/atlas/evidence/marco-zero-fable-2026-06-11.json` (nome histórico PRESERVADO — é evidência congelada da campanha; nunca renomear/reescrever)
- **Produtor:** `AtlasAcosDeltaSeriesCommand` (ex `AtlasFableDeltaSeriesCommand`), agendado `dailyAt('05:10')` + catch-up hourly guardado por closure (EVI-04)
- **Config:** `atlas.acos.delta_series_enabled` (lê fallback do legado `atlas.fable.delta_series_enabled`)

## Por que o rename (decisão canônica, 11/07/2026)

"Fable" é nome de modelo de provider. A série nasceu como artefato da campanha Fable (L4/L5, 11 dias), mas foi **promovida a espinha do ACOS** quando o gate longitudinal passou a depender dela. Tese pétrea do Atlas: providers são MOTOR, nunca identidade — componente de espinha não carrega marca de provider. O rename acontece ANTES de o medidor congelar (EVI-05/06) e ANTES de o relógio de 30d ligar (fim da onda 2) — o único momento em que custa quase zero.

**Escopo do rename (runtime vivo, SÓ):**
| Antes | Depois |
|---|---|
| `atlas:fable:delta-series` | `atlas:acos:delta-series` (alias legado mantido 1 ciclo) |
| `atlas:fable:delta` | `atlas:acos:delta` (alias legado mantido 1 ciclo) |
| `AtlasFableDeltaSeriesCommand` / teste | `AtlasAcosDeltaSeriesCommand` / teste |
| `storage/.../fable-delta-series.jsonl` | `storage/.../acos-delta-series.jsonl` (mesmos bytes, hash-verificado) |
| `config('atlas.fable.delta_series_enabled')` | `config('atlas.acos.delta_series_enabled')` com fallback legado |

**Fora do escopo (nomes históricos PRESERVADOS — são evidência, não runtime):** `marco-zero-fable-2026-06-11.json`, todos os receipts `fable-l4-*`/`fable-l5-*` em storage/evidence, `atlas:fable:final-report`, `atlas:fable:final-capture` (artefatos congelados da campanha L4-13/14). `atlas:fable:weekly-report` continua agendado sob o nome atual — rename é follow-up opcional, não desta leva.

## Regras pétreas da série (independem do nome)

1. **Append-only por dia-calendário; dia perdido é dia perdido.** Nunca retro-datar, nunca backfill. O único resgate legítimo é o catch-up same-day (EVI-04), gravado no próprio dia.
2. **`--date` retroativo é RECUSADO** pelo produtor (`--allow-past-date` existe só para teste) — vetor de rewrite silencioso de dia ruim já vivido está fechado.
3. A "idempotência" do `appendSnapshot()` é **REPLACE da linha do dia** (remove + substitui) — a closure de guarda no schedule é LOAD-BEARING: sem ela o catch-up hourly sobrescreveria a amostra das 05:10 a cada hora.
4. **Migração de arquivo preserva bytes** (contagem de linhas + sha256 idênticos antes/depois), sem symlink (lição do incidente wiper: symlink em caminho vivo é vetor de envenenamento).
5. Mudança de MEDIDOR do gate depois de o relógio de 30d ligar **REINICIA a série contada**. Renomes/refactors que não alteram semântica de medição não reiniciam nada — mas exigem prova (mesmos bytes, mesma leitura do gate antes/depois, registrada no ledger).
6. O snapshot pré-mutação do substrato (SUB-01) cobre este JSONL.

## Referências

- Plano da obra: `atlas-acos-excellence-10-10-plan-v1.md` (dimensão evidencia-longitudinal, slices EVI-01..09)
- Gate: `app/Services/Ai/Cognition/AtlasAcosLongHorizonGateService.php` · receipt `storage/app/atlas/evidence/acos-long-horizon-gate.json`
- Origem histórica: campanha Fable (Marco Zero 11-12/06, relatórios L4-13/14) — ver receipts congelados em storage/evidence
