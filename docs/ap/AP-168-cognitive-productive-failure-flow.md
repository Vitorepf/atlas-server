# AP-168 — Cognitive Productive Failure Flow

Status: implemented_partial

Implementation note (2026-05-09): runtime minimo interno implementado com `ProductiveFailureProblemSelector`, capture/comparison/articulation, `ProductiveFailureTransferTestScheduler`, gates, migration, CLI `php artisan atlas:productive-failure`, read-model `transfer-tests`, Self-Improvement review proposal e eventos `PRODUCTIVE_FAILURE_*`. Continua `implemented_partial` porque ainda nao existe UX App/Mobile/Voice de uso diario.

Hardening note (2026-05-09): runtime agora exige topico explicito; `php artisan atlas:productive-failure --json` nao cria sessao `unknown`. Productive Failure e erro preditivo deliberado, nao frustracao aleatoria.

Hardening note (2026-05-10): service-level guard tambem bloqueia `productive_failure_storage_unavailable` quando `productive_failure_sessions` nao existe; o fluxo retorna `run_migrations_before_productive_failure` e nao emite evento de sessao iniciada. Isso impede que uma IA trate AP-168 como sessao valida sem read model operacional.

## Objetivo

Implementar **flow integrado** `learning.productive_failure` que orquestra 3 fases formais de Manu Kapur (Productive Failure):

- **Fase 1 — Generation** (5-15min): Atlas apresenta problema mal-estruturado **sem instrucao previa**; operador tenta resolver
- **Fase 2 — Comparison/Consolidation** (10-20min): Atlas mostra worked example canonico/personal; operador compara sua tentativa contra solucao canonica
- **Fase 3 — Integration** (5-10min): operador articula o que aprendeu; Atlas cria `transfer_test` para sessao seguinte

Combina capabilities ja documentadas: Generation Engine (Pretest), Worked Example Engine (AP-164), Transfer Probe. Nao e capability nova; e flow integrado que **fecha o ciclo** do principio C14 + C16 + C20.

Diferenca do `learning.active_recall` (que pergunta sobre material ja estudado) e do `learning.worked_example` standalone: PF e processo estruturado de **errar para aprender** com framework formal.

## Contrato de Erro Preditivo

Este AP implementa C14 como contrato mensuravel, nao como slogan. Cada sessao registra:

| Campo | Fonte | Funcao |
|---|---|---|
| `prediction_prompt` | Fase 1 | problema cru antes da teoria |
| `operator_prediction` | Fase 1 | hipotese/tentativa inicial |
| `validated_reality` | Fase 2 | worked example, teste ou resposta canonica |
| `prediction_error_delta` | Fase 2 | divergencia entre previsao e realidade |
| `model_update` | Fase 3 | principio corrigido extraido pelo operador |
| `transfer_probe` | Fase 3 | caso futuro para provar transferencia |

Sessao sem `prediction_error_delta` nao pode marcar `PRODUCTIVE_FAILURE_COMPLETED`; vira `incomplete_comparison` ou continua em fase 2.

## Nao Objetivo

Nao implementar:

- Capability nova (consome AP-164 + Generation Engine + Transfer Probe)
- Substituir `learning.deep_work` (PF e flow especifico, deep_work e generico)
- Predictive Failure Insertion (AP-170; PF usa problema canonical_library, nao predicao personal)
- Auto-promover articulacao do operador a memoria sem review
- Criar sessao sem topico explicito ou por automacao passiva

## Authority

Em conflito: Tese central > Kernel > `cognitive/principles.md` (C14, C16, C20) > `cognitive/pipeline-overlay.md` > este AP > `domains/learning.md`.

Status promove para `implemented-runtime` apenas quando `transfer_test` tiver consumer real ou proposal review integrado; promove para `implemented-surface-integrated` quando App/Mobile/Voice expuserem UX canonica.

## Fluxo

```
Atlas Input (future product alias `atlas productive-failure <topic>` / canonical local `php artisan atlas:productive-failure <topic>`)
  -> Surface Adapter canoniza
  -> Operation Envelope (input_kind=cognitive)
  -> Domain / Profile / Flow (learning.productive_failure)
  -> Context Builder (Knowledge Graph + dreyfus_overlay)
  -> Atlas Decide
  -> Decision Receipt v2
  -> Runtime: ProductiveFailureFlow
       -> Fase 1: ProductiveFailureProblemSelector + captura tentativa
       -> Fase 2: WorkedExampleSelector (consome AP-164) + captura comparacao
       -> Fase 3: ArticulationCapture + cria transfer_test agendado
  -> Quality Gates (productive_failure_phase_complete, productive_failure_problem_calibrated)
  -> Output Renderer
  -> Evidence Ledger (PRODUCTIVE_FAILURE_*)
```

## Schema

### Migration `2026_05_07_180000_create_productive_failure_sessions_table.php`

```php
Schema::create('productive_failure_sessions', function (Blueprint $t) {
    $t->id();
    $t->uuid('envelope_id');
    $t->uuid('knowledge_node_id');
    $t->string('domain', 64);
    $t->unsignedTinyInteger('dreyfus_stage_target');  // 1..5; PF usual em 2-4
    $t->json('phase_1_problem');                       // {prediction_prompt, context, expected_difficulty, source}
    $t->json('phase_1_attempt')->nullable();           // {operator_prediction, solution_attempt, time_spent_min, confidence_pre, surrender_reason}
    $t->foreignId('phase_2_worked_example_id')->nullable()->constrained('worked_examples');
    $t->json('phase_2_comparison')->nullable();        // {validated_reality, prediction_error_delta, what_matched, what_diverged, surprise_points}
    $t->json('phase_3_articulation')->nullable();      // {model_update, key_insight, why_attempt_failed, principle_extracted}
    $t->string('phase_3_transfer_test_id', 128)->nullable(); // proposal-only ate tabela futura
    $t->json('phase_3_transfer_test')->nullable();      // proposta governada, nao auto-aplicada
    $t->string('completion_status', 32);                // in_progress | phase_*_recorded | complete | abandoned
    $t->timestamp('phase_1_started_at')->nullable();
    $t->timestamp('phase_2_started_at')->nullable();
    $t->timestamp('phase_3_started_at')->nullable();
    $t->timestamp('completed_at')->nullable();
    $t->timestamps();
    $t->index(['knowledge_node_id', 'domain']);
    $t->index('completion_status');
});

// Idempotente; nunca INSERT INTO migrations manualmente.
```

## Components

| Componente | Responsabilidade | Local sugerido |
|---|---|---|
| `ProductiveFailureFlow` | orchestrator do flow `learning.productive_failure` | `app/Services/Ai/Cognitive/ProductiveFailure/` |
| `ProductiveFailurePhaseController` | gerencia transicoes 1->2->3; previne pulos | `app/Services/Ai/Cognitive/ProductiveFailure/` |
| `ProductiveFailureProblemSelector` | escolhe problema mal-estruturado calibrado para zona 80/20 do dreyfus_stage | `app/Services/Ai/Cognitive/ProductiveFailure/` |
| `ProductiveFailureAttemptCapture` | captura tentativa do operador na fase 1 | `app/Services/Ai/Cognitive/ProductiveFailure/` |
| `ProductiveFailureComparisonEngine` | apresenta worked_example (consome AP-164) + facilita comparacao estruturada | `app/Services/Ai/Cognitive/ProductiveFailure/` |
| `PredictiveErrorDeltaExtractor` | calcula divergencia entre `operator_prediction` e `validated_reality`; bloqueia comparacao vazia | `app/Services/Ai/Cognitive/ProductiveFailure/` |
| `ProductiveFailureArticulationCapture` | captura insight + principio extraido | `app/Services/Ai/Cognitive/ProductiveFailure/` |
| `ProductiveFailureTransferTestScheduler` | cria proposta `transfer_test` para 7-14 dias futuros, sem auto-aplicar | `app/Services/Ai/Cognitive/ProductiveFailure/` |
| `ProductiveFailureSessionRepository` | CRUD + history + transfer_test review read-model | `app/Services/Ai/Cognitive/ProductiveFailure/` |
| `ProductiveFailurePhaseCompleteGate` | gate executavel | `app/Services/Ai/Kernel/Gates/` |
| `ProductiveFailureProblemCalibratedGate` | gate executavel | `app/Services/Ai/Kernel/Gates/` |
| `AtlasProductiveFailureCommand` | CLI base | `app/Console/Commands/` |

## Gates Executaveis

### `productive_failure_phase_complete`

```php
final class ProductiveFailurePhaseCompleteGate {
    public function evaluate(ProductiveFailureSession $s, string $advancingTo): GateResult {
        if ($advancingTo === 'phase_2' && empty($s->phase_1_attempt)) {
            return GateResult::block('productive_failure_phase_1_attempt_missing');
        }
        if ($advancingTo === 'phase_3' && empty($s->phase_2_comparison)) {
            return GateResult::block('productive_failure_phase_2_comparison_missing');
        }
        if ($advancingTo === 'complete' && empty($s->phase_2_comparison['prediction_error_delta'] ?? null)) {
            return GateResult::block('productive_failure_prediction_error_delta_missing');
        }
        if ($advancingTo === 'complete' && empty($s->phase_3_articulation)) {
            return GateResult::block('productive_failure_phase_3_articulation_missing');
        }
        return GateResult::pass();
    }
}
```

### `productive_failure_problem_calibrated`

```php
final class ProductiveFailureProblemCalibratedGate {
    public function evaluate(array $problemPayload, int $dreyfusStage): GateResult {
        $expectedDifficulty = $problemPayload['expected_difficulty'] ?? null;
        // Zona PF: problema deve ser ligeiramente acima do stage atual
        // Para stage 2 (competente): difficulty 3-4; para stage 3 (proficiente): difficulty 4-5
        $minDifficulty = $dreyfusStage + 0;
        $maxDifficulty = $dreyfusStage + 2;
        if ($expectedDifficulty < $minDifficulty || $expectedDifficulty > $maxDifficulty) {
            return GateResult::block('productive_failure_problem_outside_calibration_band');
        }
        return GateResult::pass();
    }
}
```

## Surfaces e Comandos

| Comando | Output |
|---|---|
| `php artisan atlas:productive-failure <topic>` | inicia flow; conduz pelas 3 fases |
| `php artisan atlas:productive-failure status` | sessao em curso (se houver) |
| `php artisan atlas:productive-failure history --window=30d` | sessoes completas + abandonadas |
| `php artisan atlas:productive-failure resume <session-id>` | retoma sessao em fase 2 ou 3 |

### Tela / Interacao tipica

```
$ php artisan atlas:productive-failure queue-batching

[Fase 1/3 — Generation, 5-15min]
  Problema:
    "Voce tem queue Laravel processando 1k jobs/min. Latency p95
     comecou a subir de 200ms para 4s sem alertas obvios. Memory
     ok, CPU ok. Que hipotese voce levanta primeiro e que metrica
     voce checa para confirmar?"

  Sua tentativa (escreva sem consultar nada):
  > _

  Tempo gasto na fase 1: _ min
  Confianca pre-comparacao (1-5): _

[Fase 2/3 — Comparison, 10-20min]
  Worked example canonico:
    Hipotese: lock contention em job batching
    Metrica: pg_locks count + Redis SLOWLOG durante pico

  Sua resposta foi: "verifico Redis memory + queue depth"

  O que casou: voce identificou que era infra (Redis), nao app
  O que divergiu: voce nao chegou a lock contention; foi para memory primeiro
  Pontos de surpresa: pg_locks era a metrica mais reveladora

[Fase 3/3 — Integration, 5-10min]
  Insight principal:
  > _

  Por que sua tentativa falhou:
  > _

  Principio extraido (vai virar candidato a Process Pattern):
  > _

  Atlas agendou transfer_test para 2026-05-21:
    "Voce vai diagnosticar latency similar em sistema diferente
     (Kafka consumer batching) e confirmar se o principio se transfere"

PRODUCTIVE_FAILURE_COMPLETED. Episode #38.
```

## SLO Targets

| Metric | Target p95 |
|---|---|
| `productive_failure_phase_transition_latency_ms` | <= 200 |
| `productive_failure_problem_selection_latency_ms` | <= 600 |
| `productive_failure_full_session_completion_rate_30d` | >= 0.6 (60% das sessoes completam as 3 fases) |

Namespace `cognitive.productive_failure.*`.

## Evidence Ledger Events

`PRODUCTIVE_FAILURE_PHASE_1_STARTED`, `PRODUCTIVE_FAILURE_PHASE_1_ATTEMPT_RECORDED`, `PRODUCTIVE_FAILURE_PHASE_2_STARTED`, `PRODUCTIVE_FAILURE_PHASE_2_COMPARISON_RECORDED`, `PRODUCTIVE_FAILURE_PHASE_3_STARTED`, `PRODUCTIVE_FAILURE_ARTICULATION_RECORDED`, `PRODUCTIVE_FAILURE_COMPLETED`, `PRODUCTIVE_FAILURE_ABANDONED`. Taxonomia fechada.

## Governance Contract

Todo payload do runtime atual inclui `atlas.cognitive.productive_failure.governance.v1`:

- `operator_opt_in_required=true`;
- `specific_topic_required=true`;
- `empty_topic_allowed=false`;
- `auto_schedule_allowed=false`;
- `passive_session_allowed=false`;
- `random_frustration_allowed=false`;
- `productive_failure_storage_unavailable` bloqueia inicio quando a tabela `productive_failure_sessions` nao existe;
- `run_migrations_before_productive_failure` e a unica acao sugerida nesse estado;
- `requires_prediction_error_delta=true`;
- `transfer_test_review_required=true`;
- `allowed_surfaces_now=["cli_explicit"]`;
- App, Mobile, Voice e Daily Plan exigem AP/review futuro antes de virar default.

## Tests

| Test | Local |
|---|---|
| `ProductiveFailurePhaseControllerTest` (nao pula fase) | `tests/Unit/Ai/Cognitive/ProductiveFailure/` |
| `ProductiveFailureProblemSelectorTest` (calibracao por dreyfus_stage) | `tests/Unit/Ai/Cognitive/ProductiveFailure/` |
| `ProductiveFailurePhaseCompleteGateTest` (3 cenarios block + pass) | `tests/Unit/Ai/Kernel/Gates/` |
| `ProductiveFailureProblemCalibratedGateTest` | `tests/Unit/Ai/Kernel/Gates/` |
| `AtlasProductiveFailureCommandTest` (end-to-end com worked_example + ledger) | `tests/Feature/Ai/Cognitive/` |

## Validation Commands

```bash
php artisan migrate
php artisan test tests/Unit/Ai/Cognitive/ProductiveFailure tests/Feature/Ai/Cognitive
php artisan atlas:productive-failure queue-batching
php artisan atlas:productive-failure history --window=30d --json
php artisan atlas:ai:architecture-validate --json
```

## Definition Of Done

1. Migration `productive_failure_sessions` aplicada e idempotente
2. Todos os components implementados e testados
3. Ambos os gates executaveis e ligados ao policy compiler
4. Flow `learning.productive_failure` integrado ao Domain Profile Registry
5. CLI `php artisan atlas:productive-failure` (start/status/history/resume) operacional
6. Integracao com AP-164 (Worked Example Engine) funciona end-to-end
7. Phase 3 Transfer Test e emitido como proposta review-only + Self-Improvement review quando vence
8. Ledger emite todos os 8 events declarados em fluxo end-to-end completo
9. SLOs `cognitive.productive_failure.problem_selection`, `phase_gate` e `calibration_gate` registrados em `KernelSloTargets`
10. `atlas:ai:architecture-validate --json` continua verde
11. `cognitive/pipeline-overlay.md` lista flow `learning.productive_failure` com status exato da taxonomia
12. Architecture test garante zero pulo de fase em sessao marcada como `complete`
