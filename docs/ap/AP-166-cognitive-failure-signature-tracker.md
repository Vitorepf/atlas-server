# AP-166 — Cognitive Failure Signature Classifier + Bayesian Failure Tracker

Status: implemented-operational-read-model

Implementation note (2026-05-07): Laravel read model, CLI, gates, SLOs, Ledger
events and `learning.failure_review`/`self_improvement.failure_pattern_review`
registration are implemented. Self-Improvement proposal emission to Inbox remains
proposal-only/future runtime work; the read model is ready for that consumer.

## Objetivo

Implementar capability Core que **classifica cada falha registrada no Evidence Ledger em assinatura tipada** (`failure_signature`) e calcula **`failure_diversity_index`** longitudinal por dominio. Implementa **C20** (Falha repetida e estagnacao; falha diversificada e exploracao). Frase canonica do operador: "errar a mesma coisa e burrice; errar diferente e progresso".

Comportamento: repeticao da mesma assinatura em janela curta dispara `FAILURE_REPETITION_ALERT`; falha com assinatura distinta marca progresso e e silenciosa. Productive Failure (Kapur) operacionalizado.

E base para o **Predictive Failure Insertion** (AP-170, scaffold) — capability cardinal Multiplier Edge que usa este historico para inserir problema calibrado em zona 80/20.

## Nao Objetivo

Nao implementar:

- Predictive Failure Insertion (AP-170; consome este tracker)
- Promover finding de falha a memoria canonica sem review
- Auto-corrigir comportamento do operador baseado em padrao detectado
- Diagnostico clinico de qualquer natureza (`non_clinical_safety` continua hard gate)
- Substituir `dev_repair_executor` ou `programming.repair` (escopos diferentes)

## Authority

Em conflito: Tese central > Kernel > `cognitive/overview.md` > `cognitive/principles.md` (C20) > `cognitive/capabilities-core.md` > este AP.

Status promove para `implemented-operational-read-model` apos DoD.

## Fluxo

```
Evento de falha no ledger (qualquer dominio: programming, decision, voice_failed, etc.)
  -> FailureSignatureClassifier (extrai categoria + sub-causa + contexto)
  -> FailureSimilarityComputer (compara contra historico do operador)
  -> Persiste failure_signatures + atualiza counters
  -> Se recurrence_count >= threshold em janela: FAILURE_REPETITION_ALERT
  -> Atualiza failure_diversity_index periodicamente
  -> learning.failure_review (semanal) consome read model
  -> Self-Improvement gera proposal revisavel quando padrao critico
```

## Schema

### Migration `2026_05_07_160000_create_failure_signatures_table.php`

```php
Schema::create('failure_signatures', function (Blueprint $t) {
    $t->id();
    $t->string('envelope_id', 80);
    $t->string('source_ledger_event_id', 80)->nullable();
    $t->string('signature_key', 200);
    $t->string('domain', 64);
    $t->string('category', 64);                  // technical | decision | communication | attention | knowledge_gap | process | safety
    $t->string('sub_cause', 128);                // race_condition | premature_optimization | unclear_brief | overcommit | wrong_abstraction | ...
    $t->text('context_summary');                 // redacted, provider-safe
    $t->json('canonical_features')->nullable();  // [stack_trace_hash, error_class, decision_topic_tag, ...]
    $t->json('vector_embedding')->nullable();    // optional ANN search
    $t->decimal('similarity_to_previous', 4, 3)->nullable();  // 0.000..1.000
    $t->unsignedSmallInteger('recurrence_count')->default(1); // quantas vezes ja vi essa assinatura
    $t->timestamp('recorded_at');
    $t->timestamps();
    $t->index(['domain', 'category', 'sub_cause']);
    $t->index(['signature_key', 'recorded_at']);
    $t->index('recorded_at');
});

Schema::create('failure_diversity_metrics', function (Blueprint $t) {
    $t->id();
    $t->string('domain', 64);
    $t->timestamp('window_start');
    $t->timestamp('window_end');
    $t->unsignedInteger('total_failures')->default(0);
    $t->unsignedInteger('unique_signatures')->default(0);
    $t->decimal('diversity_index', 4, 3);        // unique / total, 0..1
    $t->unsignedInteger('repetition_alerts_triggered')->default(0);
    $t->json('top_repeated_signatures')->nullable();
    $t->timestamp('computed_at');
    $t->index(['domain', 'window_end']);
});

Schema::create('failure_repetition_alerts', function (Blueprint $t) {
    $t->id();
    $t->string('signature_key', 200);            // hash de domain+category+sub_cause+canonical_features
    $t->string('domain', 64);
    $t->unsignedSmallInteger('repetition_count');
    $t->timestamp('first_occurrence_at');
    $t->timestamp('latest_occurrence_at');
    $t->string('alert_status', 32)->default('open'); // open | acknowledged | resolved | suppressed
    $t->string('severity', 32)->default('warning');
    $t->text('operator_reflection')->nullable();
    $t->timestamp('acknowledged_at')->nullable();
    $t->timestamp('resolved_at')->nullable();
    $t->timestamps();
    $t->index(['signature_key', 'alert_status']);
});

// Idempotente; nunca INSERT INTO migrations manualmente.
```

### Memory type `failure_signature`

Ja documentado em `cognitive/pipeline-overlay.md`:

```yaml
failure_signature:
  envelope_id: uuid
  domain: string
  category: technical | decision | communication | attention | knowledge_gap | process | safety
  sub_cause: string
  context_summary: text (redacted)
  similarity_to_previous: 0.0..1.0
  recurrence_count: int
  signature_key: hash
```

## Components

| Componente | Responsabilidade | Local sugerido |
|---|---|---|
| `FailureSignatureClassifier` | recebe ledger event de falha; extrai category + sub_cause + canonical_features (heuristica + LLM como fallback) | `app/Services/Ai/Cognitive/Failure/` |
| `FailureSimilarityComputer` | compara nova signature contra historico via signature_key + opcional embedding ANN | `app/Services/Ai/Cognitive/Failure/` |
| `FailureSignatureRepository` | CRUD + queries por dominio/categoria/janela | `app/Services/Ai/Cognitive/Failure/` |
| `BayesianFailureTracker` | atualiza priors por categoria; computa `failure_diversity_index` por janela | `app/Services/Ai/Cognitive/Failure/` |
| `FailureRepetitionAlerter` | detecta recurrence_count >= threshold em janela; cria/atualiza alert; emite `FAILURE_REPETITION_ALERT` | `app/Services/Ai/Cognitive/Failure/` |
| `LearningFailureReviewFlow` | flow `learning.failure_review` semanal | `app/Services/Ai/Domain/` |
| `FailureSignatureClassifiedGate` | gate executavel | `app/Services/Ai/Kernel/Gates/` |
| `FailureSignatureProviderSafetyGate` | gate executavel | `app/Services/Ai/Kernel/Gates/` |
| `AtlasFailureCommand` | CLI base `atlas:failure` | `app/Console/Commands/` |
| `SelfImprovementFailurePatternFlow` | flow `self_improvement.failure_pattern_review` registrado; proposal emission consome read model em fase futura | `app/Services/Ai/SelfImprovement/` |

## Gates Executaveis

### `failure_signature_classified`

```php
final class FailureSignatureClassifiedGate {
    public function evaluate(LedgerEvent $event): GateResult {
        if (!$event->isFailureEvent()) {
            return GateResult::pass(['skipped' => 'not_failure_event']);
        }
        $sig = $this->classifier->classify($event);
        if ($sig === null) {
            return GateResult::block('failure_signature_classification_failed');
        }
        if (!in_array($sig->category, FailureCategory::ALL)) {
            return GateResult::block('failure_signature_invalid_category');
        }
        return GateResult::pass(['signature_id' => $sig->id]);
    }
}
```

### `failure_signature_provider_safety`

Antes de incluir `context_summary` em context pack ou push notification: redact PII, secrets, e tokens. Bloqueia se `privacy_class >= 3` sem redaction confirmada.

## Repetition Alert Threshold

Default: **3 ocorrencias da mesma `signature_key` em janela de 30 dias** dispara `FAILURE_REPETITION_ALERT`. Configuravel por dominio em `config/atlas_ai.php`.

Severidade calculada:

| Recurrence | Severity |
|---|---|
| 3 em 30d | warning |
| 5 em 30d | high |
| 8 em 30d ou 3 em 7d | critical |

## Surfaces e Comandos

| Comando | Output |
|---|---|
| `php artisan atlas:failure recent [--domain=...] [--days=14]` | tabela: signature_key, category, sub_cause, recurrence, last_seen |
| `php artisan atlas:failure diversity [--domain=...] [--days=30]` | metrica: total_failures, unique_signatures, diversity_index, alerts |
| `php artisan atlas:failure signature <id>` | inspeciona signature: contexto, features, similarity, recurrence |
| `php artisan atlas:failure alerts [--status=open]` | lista alerts ativos com severidade |
| `php artisan atlas:failure ack <alert-id> --reflection="..."` | reconhece alert + adiciona reflection |
| `php artisan atlas:failure review [--domain=...] [--days=14]` | cria pacote plan-only de `learning.failure_review` |

### Tela / Interacao tipica (alerta de repeticao)

```
[push no Inbox / mobile]

FAILURE_REPETITION_ALERT
  signature: race-condition.queue-worker.no-lock
  domain: programming
  ocorrencias: 3 em 21 dias
  severidade: warning

C20: falha repetida e estagnacao; falha diversificada e exploracao.
Voce gerou a mesma assinatura de falha 3 vezes em 21 dias.

Sugestao do Self-Improvement:
  - Pretest pre-aprendizado focado em distributed-locking-patterns
  - Worked Example: "queue worker com lock pessimista"
  - Process Pattern matcher: "race-condition em job batching"

Acoes:
  [a] php artisan atlas:failure ack <id> --reflection="..."
  [b] php artisan atlas:study distributed-locking
  [c] php artisan atlas:worked-example queue-worker-lock
  [d] php artisan atlas:pattern matcher "race condition queue"
```

```
$ php artisan atlas:failure diversity --domain=programming --days=90

Diversity Report (programming, 90d):
  total_failures:           23
  unique_signatures:        18
  diversity_index:          0.783  (saudavel: > 0.7)
  repetition_alerts:        1 open (race-condition.queue-worker.no-lock)

Interpretacao C20:
  diversity 0.783 = alto. Voce esta errando coisas diferentes.
  1 alert aberto: assinatura merece atencao explicita.
```

## SLO Targets

| Metric | Target p95 |
|---|---|
| `cognitive.failure.classify` | <= 250ms |
| `cognitive.failure.similarity` | <= 400ms |
| `cognitive.failure.diversity` | <= 2500ms p95; freshness via scheduled consumer <= 24h |
| `cognitive.failure.alert` | <= 1000ms p95; hard target <= 60s |
| `cognitive.failure.gate` | <= 50ms |

Namespace `cognitive.failure.*`.

## Evidence Ledger Events

`FAILURE_SIGNATURE_RECORDED`, `FAILURE_REPETITION_ALERT`, `FAILURE_DIVERSITY_INDEX_COMPUTED`, `FAILURE_ALERT_ACKNOWLEDGED`, `FAILURE_ALERT_RESOLVED`, `FAILURE_ALERT_SUPPRESSED`. Taxonomia fechada, aditiva.

## Self-Improvement Integration

Flow `self_improvement.failure_pattern_review` (aditivo aos 13 existentes em `domains/self-improvement.md`):

- Le `failure_diversity_metrics` por dominio
- Cruza com `failure_repetition_alerts` abertos
- Quando severidade `high` ou `critical` recorrente -> cria proposal `atlas.self_improvement.failure_pattern.v1` no Inbox (consumer futuro; read model pronto)
- Proposal sugere acoes: pretest, worked_example, pattern matcher, deep_work focado, mudanca de policy

Curator nunca auto-aplica; abre proposal revisavel.

## Tests

| Test | Local |
|---|---|
| `FailureSignatureClassifierTest` (categorias canonicas) | `tests/Unit/Ai/Cognitive/Failure/` |
| `FailureSimilarityComputerTest` (signature_key + embedding) | `tests/Unit/Ai/Cognitive/Failure/` |
| `BayesianFailureTrackerTest` (diversity_index calculation) | `tests/Unit/Ai/Cognitive/Failure/` |
| `FailureRepetitionAlerterTest` (3 thresholds de severidade) | `tests/Unit/Ai/Cognitive/Failure/` |
| `FailureSignatureClassifiedGateTest` | `tests/Unit/Ai/Kernel/Gates/` |
| `LearningFailureReviewFlowIntegrationTest` (end-to-end) | `tests/Feature/Ai/Cognitive/` |
| `SelfImprovementFailurePatternFlowTest` (proposal generation futuro) | `tests/Feature/Ai/SelfImprovement/` |
| `AtlasFailureCommandTest` | `tests/Feature/Ai/Cognitive/` |
| `LearningFailureReviewFlowIntegrationTest` | `tests/Feature/Ai/Cognitive/` |
| `CognitiveDomainComplianceTest::test_failure_signatures_classified_for_all_failure_events` (futuro hard invariant) | `tests/Feature/Architecture/` |

## Validation Commands

```bash
php artisan migrate
php artisan test tests/Unit/Ai/Cognitive/Failure tests/Unit/Ai/Kernel/Gates/FailureSignatureClassifiedGateTest.php tests/Feature/Ai/Cognitive/AtlasFailureCommandTest.php tests/Feature/Ai/Cognitive/LearningFailureReviewFlowIntegrationTest.php
php artisan atlas:failure recent --json
php artisan atlas:failure diversity --domain=programming --days=90 --json
php artisan atlas:failure alerts --status=open --json
php artisan atlas:ai:self-improve --flow=failure_pattern_review --plan-only --json
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune
atlas engineering knowledge docs-health
```

## Definition Of Done

1. Migrations `failure_signatures`, `failure_diversity_metrics`, `failure_repetition_alerts` aplicadas e idempotentes
2. `FailureSignatureClassifier`, `FailureSimilarityComputer`, `BayesianFailureTracker`, `FailureRepetitionAlerter` implementados e testados
3. `FailureSignatureClassifiedGate` e `FailureSignatureProviderSafetyGate` executaveis
4. Flow `learning.failure_review` operacional; flow `self_improvement.failure_pattern_review` registrado para consumir o read model
5. CLI `atlas:failure recent`, `atlas:failure diversity`, `atlas:failure signature`, `atlas:failure alerts`, `atlas:failure ack`, `atlas:failure review` operacionais
6. Ledger emite `FAILURE_SIGNATURE_RECORDED` para todo failure event existente; `FAILURE_REPETITION_ALERT` quando threshold atingido
7. SLOs registrados em `KernelSloTargets`
8. Self-Improvement proposal emission `atlas.self_improvement.failure_pattern.v1` fica como proximo consumer; read model e flow ja estao prontos
9. `atlas:ai:architecture-validate --json` continua verde
10. Architecture hard invariant para zero falha sem signature fica como proxima fase de enforcement global
11. C20 medido empiricamente: `diversity_index` por dominio aparece em observability dashboard
12. AP-170 (Predictive Failure Insertion) pode consumir este tracker sem refactor
