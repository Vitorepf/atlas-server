# AP-170 — Cognitive Predictive Failure Insertion

Status: scaffold

## Objetivo

Implementar capability cardinal do **Multiplier Edge** que **predicta onde o operador vai falhar** cruzando 4 sinais (gaps no Knowledge Graph + decay overlay alto + dreyfus_stage baixo + failure_signature historico) e **insere problema calibrado em zona 80/20** que ativa Generation Effect personalizado.

E **Capability 9** do Multiplier Edge (`cognitive/multiplier-edge.md`). Implementa C14 como erro preditivo calibrado com historico pessoal — algo que tutor humano ou IA generica nao consegue por nao ter o historico longitudinal. Nao autoriza frustracao aleatoria nem promessa de ganho sem Rivals-Learning.

## Pre-requisitos

- AP-166 (Failure Signature Classifier + Bayesian Tracker) implementado — fonte de `failure_signature` historico
- AP-163 (Dreyfus Dynamic Pedagogy) implementado — fonte de `dreyfus_overlay`
- Knowledge Graph maduro com decay overlay funcional
- Evidence Ledger maduro (~30-60 dias)

## Nao Objetivo

Nao implementar:

- Failure Signature Classifier (AP-166; consome dele)
- Inserir falha em flow continuo / passivo (apenas em flows opt-in: `learning.daily_plan`, `learning.deep_work` quando configurado)
- Garantir que operador vai falhar em probabilidade exata (sistema bayesiano e probabilistico, nao deterministico)
- Substituir AP-168 Productive Failure Flow (PF usa problema canonical_library; PFI usa problema personal_predicted)

## Authority

Em conflito: Tese central > Kernel > `cognitive/multiplier-edge.md` > este AP > `domains/learning.md`.

Status promove para `implemented-operational-read-model` apos DoD.

## Fluxo

```
Trigger (atlas daily-plan | atlas deep-work com PFI on)
  -> PredictiveFailureSelector cruza 4 sinais
       * Knowledge Graph: gaps + decay overlay alto
       * Dreyfus: stage baixo (1-2) ou stage medio (3) com confianca baixa
       * Failure Tracker: signature historico em area adjacente
       * Cognitive Load: load nao-alto (PFI nao roda em fadiga)
  -> FailureProbabilityEstimator computa probability esperada
  -> Calibration Band Gate (target 0.7-0.85 probabilidade de erro)
  -> PredictiveFailureProblemGenerator constroi problema (canonical ou personal-derived)
  -> Atlas Decide compila Decision Receipt
  -> Quality Gates (predictive_failure_calibration_band, predictive_failure_safety)
  -> Output: problema apresentado ao operador
  -> Operador tenta resolver
  -> PredictiveFailureOutcomeTracker captura outcome
  -> Atualiza priors do estimator (bayesian update)
  -> Ledger: PREDICTIVE_FAILURE_INSERTED + OUTCOME_*
```

## Schema

### Migration `2026_05_07_200000_create_predictive_failure_insertions.php`

```php
Schema::create('predictive_failure_insertions', function (Blueprint $t) {
    $t->id();
    $t->uuid('envelope_id');
    $t->uuid('target_knowledge_node_id');
    $t->string('domain', 64);
    $t->json('signals_used');                     // {kg_gap_score, decay_score, dreyfus_stage, dreyfus_confidence, similar_failure_signatures[]}
    $t->decimal('predicted_failure_probability', 4, 3);   // 0.000..1.000
    $t->string('calibration_band', 16);           // outside | low | sweet | high
    $t->string('predicted_failure_signature_key', 200)->nullable();
    $t->json('problem_payload');                  // {description, context, expected_difficulty, source}
    $t->string('source_type', 32);                // canonical_library | personal_derived
    $t->timestamp('inserted_at');
    $t->string('outcome', 32)->nullable();        // not_attempted | success | partial | failure | abandoned | skipped
    $t->json('actual_failure_signature')->nullable();    // se outcome=failure
    $t->timestamp('outcome_recorded_at')->nullable();
    $t->decimal('prediction_calibration_error', 4, 3)->nullable();  // |predicted - actual| 0..1
    $t->timestamps();
    $t->index(['domain', 'inserted_at']);
    $t->index('outcome');
});

Schema::create('predictive_failure_calibration_metrics', function (Blueprint $t) {
    $t->id();
    $t->string('domain', 64);
    $t->timestamp('window_start');
    $t->timestamp('window_end');
    $t->unsignedInteger('total_insertions')->default(0);
    $t->unsignedInteger('outcomes_recorded')->default(0);
    $t->decimal('avg_calibration_error', 4, 3)->nullable();
    $t->decimal('brier_score', 4, 3)->nullable();
    $t->json('insertion_distribution_by_band')->nullable();
    $t->timestamp('computed_at');
    $t->index(['domain', 'window_end']);
});

// Idempotente; nunca INSERT INTO migrations manualmente.
```

## Components

| Componente | Responsabilidade | Local sugerido |
|---|---|---|
| `PredictiveFailureSelector` | cruza 4 sinais; ranqueia knowledge_nodes candidatos | `app/Services/Ai/Cognitive/PredictiveFailure/` |
| `FailureProbabilityEstimator` | bayesian model; computa P(falhar \| sinais) | `app/Services/Ai/Cognitive/PredictiveFailure/` |
| `CalibrationBandClassifier` | classifica probabilidade em outside/low/sweet/high | `app/Services/Ai/Cognitive/PredictiveFailure/` |
| `PredictiveFailureProblemGenerator` | constroi problema canonical_library OU adapta worked_example com camada de erro implicita | `app/Services/Ai/Cognitive/PredictiveFailure/` |
| `PredictiveFailureOutcomeTracker` | captura outcome; calcula calibration_error; atualiza priors | `app/Services/Ai/Cognitive/PredictiveFailure/` |
| `PredictiveFailureCalibrationMetricsService` | computa Brier score + outras metricas longitudinais | `app/Services/Ai/Cognitive/PredictiveFailure/` |
| `PredictiveFailureCalibrationBandGate` | gate executavel | `app/Services/Ai/Kernel/Gates/` |
| `PredictiveFailureSafetyGate` | gate executavel | `app/Services/Ai/Kernel/Gates/` |
| `AtlasPredictCommand` | CLI base | `app/Console/Commands/` |

## Gates Executaveis

### `predictive_failure_calibration_band`

```php
final class PredictiveFailureCalibrationBandGate {
    private const SWEET_MIN = 0.70;
    private const SWEET_MAX = 0.85;

    public function evaluate(PredictiveFailureInsertion $i): GateResult {
        if ($i->predicted_failure_probability < self::SWEET_MIN) {
            return GateResult::block('predictive_failure_too_easy_outside_zone');
        }
        if ($i->predicted_failure_probability > self::SWEET_MAX) {
            return GateResult::block('predictive_failure_too_hard_outside_zone');
        }
        return GateResult::pass(['band' => 'sweet']);
    }
}
```

### `predictive_failure_safety`

```php
final class PredictiveFailureSafetyGate {
    public function evaluate(PredictiveFailureInsertion $i, CognitiveLoadSnapshot $load): GateResult {
        if ($load->level === 'high' || $load->level === 'critical') {
            return GateResult::block('predictive_failure_blocked_high_cognitive_load');
        }
        if ($i->problem_payload['privacy_class'] >= 3) {
            return GateResult::block('predictive_failure_privacy_class_too_high');
        }
        if ($this->emotionalStateCheck->detectsHighStress()) {
            return GateResult::block('predictive_failure_blocked_stress_state');
        }
        return GateResult::pass();
    }
}
```

## Surfaces e Comandos

| Comando | Output |
|---|---|
| `atlas daily-plan` (auto) | inclui "1 problema do dia que voce provavelmente vai errar" quando PFI on |
| `atlas predict failure <node>` | inspeciona predicao + probability + sinais |
| `atlas predict failure history --window=30d` | tabela: insertion_id, node, predicted_prob, outcome, calibration_error |
| `atlas predict failure metrics [--domain=...]` | Brier score + avg calibration error |
| `atlas predict failure on/off [--domain=...]` | toggle |

### Tela / Interacao tipica

```
$ atlas daily-plan

Daily Plan, 2026-05-08:

[Spaced Review]      6 cards (4 min)
[Deep Work]          eigenvectors (60 min)
[Predictive Failure] queue-batching-deadlock (15-20 min)
                     [predicted_failure_probability: 0.78, band: sweet]

Atlas detectou:
  - decay overlay alto em queue-batching (ultima aplicacao 47d)
  - failure_signature historica adjacente: race-condition.queue-worker
  - dreyfus_stage atual: 3 (proficiente) com confidence 0.62

Problema do dia (gerar erro antes de aprender):
  "Voce tem 2 workers Laravel processando mesma queue.
   Job dispatch faz UPDATE em users.points e SUM por tenant.
   Sob carga, totals divergem de checks de auditoria.
   Que mecanismo voce usa pra garantir consistencia sem matar throughput?"

Tente resolver antes de consultar nada.

[outcome registrado depois via:]
  atlas predict failure resolve <id> --outcome=failure --signature="optimistic-locking-not-used"

PREDICTIVE_FAILURE_INSERTED. Insertion #14.
```

```
$ atlas predict failure metrics --domain=programming --window=60d

Calibration Report (programming, 60d):
  total_insertions:        18
  outcomes_recorded:       16  (89%)
  avg_calibration_error:   0.14  (saudavel: < 0.20)
  brier_score:             0.18  (saudavel: < 0.25)

Distribuicao por band:
  sweet (0.70-0.85):       14 (88%)
  low (< 0.70):             2 (problema fácil demais — recalibrar selector)
  high (> 0.85):            0

Interpretacao C14:
  Atlas predisse com erro medio 14% — predicao bem calibrada.
  Voce errou em 12 de 16 insertions (75%) — proximo da banda sweet de 78%.
```

## SLO Targets

| Metric | Target p95 |
|---|---|
| `predictive_failure_selector_latency_ms` | <= 500 |
| `predictive_failure_probability_estimator_latency_ms` | <= 200 |
| `predictive_failure_problem_generation_latency_ms` | <= 800 |
| `predictive_failure_avg_calibration_error_60d` | <= 0.20 |
| `predictive_failure_brier_score_60d` | <= 0.25 |

Namespace `cognitive.predictive_failure.*`.

## Evidence Ledger Events

`PREDICTIVE_FAILURE_INSERTED`, `PREDICTIVE_FAILURE_INSERTION_SKIPPED`, `PREDICTIVE_FAILURE_OUTCOME_SUCCESS`, `PREDICTIVE_FAILURE_OUTCOME_FAILURE`, `PREDICTIVE_FAILURE_OUTCOME_ABANDONED`, `PREDICTIVE_FAILURE_CALIBRATION_COMPUTED`, `PREDICTIVE_FAILURE_PRIOR_UPDATED`. Taxonomia fechada.

## Tests

| Test | Local |
|---|---|
| `PredictiveFailureSelectorTest` (4 sinais combinados) | `tests/Unit/Ai/Cognitive/PredictiveFailure/` |
| `FailureProbabilityEstimatorTest` (bayesian update) | `tests/Unit/Ai/Cognitive/PredictiveFailure/` |
| `CalibrationBandClassifierTest` | `tests/Unit/Ai/Cognitive/PredictiveFailure/` |
| `PredictiveFailureProblemGeneratorTest` (canonical + personal_derived) | `tests/Unit/Ai/Cognitive/PredictiveFailure/` |
| `PredictiveFailureOutcomeTrackerTest` (calibration_error correto) | `tests/Unit/Ai/Cognitive/PredictiveFailure/` |
| `CalibrationBandGateTest` (3 cenarios block + 1 pass) | `tests/Unit/Ai/Kernel/Gates/` |
| `PredictiveFailureSafetyGateTest` (load alto, privacy, stress) | `tests/Unit/Ai/Kernel/Gates/` |
| `PredictiveFailureIntegrationTest` (end-to-end com AP-166 mock) | `tests/Feature/Ai/Cognitive/` |
| `AtlasPredictCommandTest` | `tests/Feature/Console/` |
| `CognitiveDomainComplianceTest::test_pfi_never_runs_under_high_cognitive_load` | `tests/Feature/Architecture/` |

## Validation Commands

```bash
php artisan migrate
php artisan test tests/Unit/Ai/Cognitive/PredictiveFailure tests/Feature/Ai/Cognitive
php artisan atlas:predict failure <node-id> --json
php artisan atlas:predict failure metrics --domain=programming --window=60d --json
php artisan atlas:ai:architecture-validate --json
```

## Definition Of Done

1. Migrations `predictive_failure_insertions` + `predictive_failure_calibration_metrics` aplicadas e idempotentes
2. Todos os components implementados e testados
3. Ambos os gates executaveis e ligados ao policy compiler
4. Integracao com AP-166 funciona (consome failure_signature historico)
5. Integracao com AP-163 funciona (consome dreyfus_overlay)
6. Integracao com Knowledge Graph + decay overlay funciona
7. CLI `atlas predict failure` (history/metrics/on/off/resolve) operacional
8. `atlas daily-plan` inclui 1 PFI por dia quando PFI on para o dominio
9. Brier score + calibration_error sao computados em janela rolling 60d
10. Architecture test garante que PFI nao roda sob load alto (`test_pfi_never_runs_under_high_cognitive_load`)
11. Ledger emite todos os 7 events em fluxo end-to-end
12. SLOs registrados em `KernelSloTargets`; `cognitive/multiplier-edge.md` marca Capability 9 com status exato da taxonomia
