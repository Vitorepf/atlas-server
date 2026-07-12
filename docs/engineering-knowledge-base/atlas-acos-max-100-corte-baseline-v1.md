# ACOS Max 100% — Corte baseline (Fase 0)

> Estado, não spec. Gerado 2026-07-12T18:50:53Z · HEAD `f015908497`.

## Contagens

| Métrica | Valor |
|---|---|
| Scoreboard checked `[x]` | 201 |
| Scoreboard open `[ ]` | 65 |
| `atlas:flywheel:loops` loops_complete | 0 |
| marco_esp_v1.satisfied | false |
| Partial chains scanned | 83 |
| Autonomos preflight | 7/8 (`ready=false`) |

## Top blocked_by (partial loops)

Todas as 50 primeiras parciais compartilham:

1. `outcome_not_proven_real`
2. `decision_receipt_missing`
3. `delivered_context_missing`
4. `learning_candidate_missing`
5. `subsequent_measured_recall_missing`

## Preflight ASI-06

- `passed=7/total=8` · `master_flip_by=operator` · `never_flip_by_machine=true`
- FAIL: `leases_reaped_and_giveback` → `give_back_feedback_organ_missing` (`AtlasLoopGiveBackToReplenisherFeedback`)

## Classificação A–F dos 65 open (resumo)

Ver plano ACOS Max 100%. Classe A (código ausente) prioridade Fase 3; wiring MARCO = Fase 1.

## Anti-gap session rule

Toda fatia: AOBG → implementar → teste focado → scoreboard no mesmo commit → push main. Sem stash-hide. Sem WIP Rivals no commit ACOS.

## Fase 1 exit (writers)

- MARCO path unblocked (writers): spine payload shape + flywheel distill + ARFL `run_outcome_id` + feedback `memory_candidate_id`.
- Live MARCO still false until operator volume (ASI-06) + real subsequent recall.
