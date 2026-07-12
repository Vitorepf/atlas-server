# ACOS Max — Operator flip runbook (Fase 2)

> Estado operacional. Não flipar por IA. Master/FEE/ADV/drill = ato do operador.

## Checklist de exit (operador)

- [ ] ASI-06 ON
- [ ] FEE-04 ON (quando critérios OK)
- [ ] ADV-01 6/6 confirmed
- [ ] TETO-01 N-Capture Drill executado

---

## 2.1 ASI-06 — Autônomos master

**Pré (obrigatório exit 0):**

```bash
php artisan atlas:autonomos:preflight --json
# expect: ready=true, 8/8 passed
```

Corte 2026-07-12: preflight **7/8** — `leases_reaped_and_giveback` falha com `give_back_feedback_organ_missing`. Corrigir órgão de give-back **antes** do flip.

**Flip (só operador):**

```bash
php artisan atlas:agents:on autonomos
# ou ATLAS_AUTONOMOS_MASTER_ENABLED=true no .env
```

**Pós (7d):**

- ≥5 `ai_run_outcomes`/dia
- ELEV-15: `decision_id` 100% nos landings (writers Fase 1 já estampam)
- reflection/pattern com dado real (`atlas:brain:path-yield --json`)

**Nunca:** machine flip do master.

---

## 2.2 FEE-04 — flip de feedback labels

**Pré:** soak/OK residual do v1 documentado no progress doc.

**Ordem pétrea:** `FEE-04 flip → watchdog → MAXB que consome labels` (MAXB-03/07/08).

**Pós:** labels negativos reais + MAXB-05 mining acumulando.

Não fabricar labels.

---

## 2.3 CPT-10 soak + ADV-01 re-prova

Hoje: ADV-01 **5/6** (CPT-10 refuted/pending_soak); PIP-07 drift.

1. Fechar CPT-10 soak cross-week.
2. Re-rodar onda 5 / ADV-01 até **6/6 confirmed**.
3. Só então ASI-10 flip (ELEV-26) e MAXG-08 freeze-v2.

---

## 2.4 TETO-01 N-Capture Drill

Mecânica landed (`atlas.n_capture_drill.v1`).

**Execução = operador** (`days_between_drills_max=180`).

Publicar tempos: `time_to_first_routed_task_seconds`, `time_to_first_proven_real_seconds`, `hours_of_integration`.

---

## Verificação pós-flips

```bash
php artisan atlas:autonomos:preflight --json
php artisan atlas:flywheel:loops --json
php artisan atlas:flywheel:funnel --json
php artisan atlas:acos:cockpit --json
```

MARCO ESP-V1 ainda exige 1 loop real (Fase 6) após ASI-06 ON + feedback com `run_outcome_id` + 2ª task com `memory_candidate_id`.
