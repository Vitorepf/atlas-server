# AP-168 — Cognitive Productive Failure Flow

Status: scaffold

## Objetivo

Implementar **flow integrado** `learning.productive_failure` que orquestra 3 fases formais de Manu Kapur (Productive Failure):

- **Fase 1 — Generation** (5-15min): Atlas apresenta problema mal-estruturado **sem instrucao previa**; operador tenta resolver
- **Fase 2 — Comparison/Consolidation** (10-20min): Atlas mostra worked example canonico/personal; operador compara sua tentativa contra solucao canonica
- **Fase 3 — Integration** (5-10min): operador articula o que aprendeu; Atlas cria `transfer_test` para sessao seguinte

Combina capabilities ja documentadas: Generation Engine (Pretest), Worked Example Engine (AP-164), Transfer Probe. Nao e capability nova; e flow integrado que **fecha o ciclo** do principio C14 + C16 + C20.

Diferenca do `learning.active_recall` (que pergunta sobre material ja estudado) e do `learning.worked_example` standalone: PF e processo estruturado de **errar para aprender** com framework formal.

## Nao Objetivo

Nao implementar:

- Capability nova (consome AP-164 + Generation Engine + Transfer Probe)
- Substituir `learning.deep_work` (PF e flow especifico, deep_work e generico)
- Predictive Failure Insertion (AP-170; PF usa problema canonical_library, nao predicao personal)
- Auto-promover articulacao do operador a memoria sem review

## Authority

Em conflito: Tese central > Kernel > `cognitive/principles.md` (C14, C16, C20) > `cognitive/pipeline-overlay.md` > este AP > `domains/learning.md`.

Status promove para `implemented-operational-read-model` apos DoD.

## Fluxo

```
Atlas Input (atlas productive-failure <topic>)
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

### Migration `2026_05_07_180000_create_productive_failure_sessions.php`

```php
Schema::create('productive_failure_sessions', function (Blueprint $t) {
    $t->id();
    $t->uuid('envelope_id');
    $t->uuid('knowledge_node_id');
    $t->string('domain', 64);
    $t->unsignedTinyInteger('dreyfus_stage_target');  // 1..5; PF usual em 2-4
    $t->json('phase_1_problem');                       // {description, context, expected_difficulty, source}
    $t->json('phase_1_attempt')->nullable();           // {solution_attempt, time_spent_min, confidence_pre, surrender_reason}
    $t->foreignId('phase_2_worked_example_id')->nullable()->constrained('worked_examples');
    $t->json('phase_2_comparison')->nullable();        // {what_matched, what_diverged, surprise_points}
    $t->json('phase_3_articulation')->nullable();      // {key_insight, why_attempt_failed, principle_extracted}
    $t->foreignId('phase_3_transfer_test_id')->nullable();  // ref a transfer_tests table (futura)
    $t->string('completion_status', 32);               // in_progress | complete | abandoned_phase_1 | abandoned_phase_2 | abandoned_phase_3
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
| `ProductiveFailureFlow` | orchestrator do flow `learning.productive_failure` | `app/Services/Ai/Domain/` |
| `ProductiveFailurePhaseController` | gerencia transicoes 1->2->3; previne pulos | `app/Services/Ai/Cognitive/ProductiveFailure/` |
| `ProductiveFailureProblemSelector` | escolhe problema mal-estruturado calibrado para zona 80/20 do dreyfus_stage | `app/Services/Ai/Cognitive/ProductiveFailure/` |
| `ProductiveFailureAttemptCapture` | captura tentativa do operador na fase 1 | `app/Services/Ai/Cognitive/ProductiveFailure/` |
| `ProductiveFailureComparisonEngine` | apresenta worked_example (consome AP-164) + facilita comparacao estruturada | `app/Services/Ai/Cognitive/ProductiveFailure/` |
| `ProductiveFailureArticulationCapture` | captura insight + principio extraido | `app/Services/Ai/Cognitive/ProductiveFailure/` |
| `ProductiveFailureTransferTestScheduler` | agenda transfer_test para 7-14 dias futuros | `app/Services/Ai/Cognitive/ProductiveFailure/` |
| `ProductiveFailureSessionRepository` | CRUD + queries | `app/Services/Ai/Cognitive/ProductiveFailure/` |
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
| `atlas productive-failure <topic>` | inicia flow; conduz pelas 3 fases |
| `atlas productive-failure status` | sessao em curso (se houver) |
| `atlas productive-failure history --window=30d` | sessoes completas + abandonadas |
| `atlas productive-failure resume <session-id>` | retoma sessao em fase 2 ou 3 |

### Tela / Interacao tipica

```
$ atlas productive-failure queue-batching

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

## Tests

| Test | Local |
|---|---|
| `ProductiveFailureFlowTest` (orchestracao 3 fases) | `tests/Unit/Ai/Cognitive/ProductiveFailure/` |
| `ProductiveFailurePhaseControllerTest` (nao pula fase) | `tests/Unit/Ai/Cognitive/ProductiveFailure/` |
| `ProductiveFailureProblemSelectorTest` (calibracao por dreyfus_stage) | `tests/Unit/Ai/Cognitive/ProductiveFailure/` |
| `ProductiveFailurePhaseCompleteGateTest` (3 cenarios block + pass) | `tests/Unit/Ai/Kernel/Gates/` |
| `ProductiveFailureProblemCalibratedGateTest` | `tests/Unit/Ai/Kernel/Gates/` |
| `ProductiveFailureFlowIntegrationTest` (end-to-end com worked_example) | `tests/Feature/Ai/Cognitive/` |
| `AtlasProductiveFailureCommandTest` | `tests/Feature/Console/` |

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
5. CLI `atlas productive-failure` (start/status/history/resume) operacional
6. Integracao com AP-164 (Worked Example Engine) funciona end-to-end
7. Phase 3 Transfer Test e agendado automaticamente para 7-14d futuros
8. Ledger emite todos os 8 events declarados em fluxo end-to-end completo
9. SLOs registrados em `KernelSloTargets`
10. `atlas:ai:architecture-validate --json` continua verde
11. `cognitive/pipeline-overlay.md` lista flow `learning.productive_failure` como `implemented`
12. Architecture test garante zero pulo de fase em sessao marcada como `complete`
