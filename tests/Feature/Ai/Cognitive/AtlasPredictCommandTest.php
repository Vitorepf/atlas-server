<?php

namespace Tests\Feature\Ai\Cognitive;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Cognitive\Dreyfus\DreyfusOverlayRepository;
use App\Services\Ai\Cognitive\PredictiveFailure\PredictiveFailureFlow;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasPredictCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_07_120000_create_dreyfus_overlays_table.php'))->up();
        (require database_path('migrations/2026_05_07_160000_create_failure_signatures_table.php'))->up();
        (require database_path('migrations/2026_05_09_170000_create_predictive_failure_insertions_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('predictive_failure_calibration_metrics');
        Schema::dropIfExists('predictive_failure_insertions');
        Schema::dropIfExists('failure_repetition_alerts');
        Schema::dropIfExists('failure_diversity_metrics');
        Schema::dropIfExists('failure_signatures');
        Schema::dropIfExists('dreyfus_overlays');
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_predict_failure_insert_resolve_and_metrics_flow(): void
    {
        $topic = 'queue-batching-deadlock';
        $nodeId = app(DreyfusOverlayRepository::class)->nodeIdForTopic($topic);
        app(DreyfusOverlayRepository::class)->upsert($nodeId, 'programming', 2, 0.4, ['test']);
        DB::table('failure_signatures')->insert([
            'envelope_id' => 'env_failure_1',
            'source_ledger_event_id' => null,
            'signature_key' => 'race-condition.queue-worker',
            'domain' => 'programming',
            'category' => 'race_condition',
            'sub_cause' => 'queue_worker',
            'context_summary' => 'Queue worker race condition.',
            'canonical_features' => json_encode(['queue', 'race'], JSON_THROW_ON_ERROR),
            'similarity_to_previous' => 0.1,
            'recurrence_count' => 1,
            'recorded_at' => now()->toJSON(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('atlas:predict', [
            'action' => 'failure',
            'subject' => $topic,
            '--domain' => 'programming',
            '--json' => true,
        ]);
        $inserted = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $insertionId = (int) data_get($inserted, 'insertion.id');

        $this->assertSame(0, $exit);
        $this->assertSame('inserted', $inserted['status']);
        $this->assertTrue(data_get($inserted, 'governance.operator_opt_in_required'));
        $this->assertFalse(data_get($inserted, 'governance.auto_schedule_allowed'));
        $this->assertFalse(data_get($inserted, 'governance.passive_insertion_allowed'));
        $this->assertFalse(data_get($inserted, 'governance.random_frustration_allowed'));
        $this->assertSame(['cli_explicit'], data_get($inserted, 'governance.allowed_surfaces_now'));
        $this->assertGreaterThan(0, $insertionId);
        $this->assertSame('sweet', data_get($inserted, 'insertion.calibration_band'));
        $this->assertSame('personal_derived', data_get($inserted, 'insertion.source_type'));
        $this->assertSame('proposal_only', 'proposal_only', 'PFI inserts a problem, not a curriculum mutation.');

        Artisan::call('atlas:predict', [
            'action' => 'resolve',
            'subject' => (string) $insertionId,
            '--outcome' => 'failure',
            '--signature' => 'race-condition.queue-worker',
            '--json' => true,
        ]);
        $resolved = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('resolved', $resolved['status']);
        $this->assertSame('failure', data_get($resolved, 'insertion.outcome'));
        $this->assertNotNull(data_get($resolved, 'insertion.prediction_calibration_error'));

        Artisan::call('atlas:predict', [
            'action' => 'metrics',
            '--domain' => 'programming',
            '--json' => true,
        ]);
        $metrics = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('computed', data_get($metrics, 'metrics.status'));
        $this->assertSame(1, data_get($metrics, 'metrics.total_insertions'));
        $this->assertSame(1, data_get($metrics, 'metrics.outcomes_recorded'));

        $this->assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::PredictiveFailureInserted->value)->count());
        $this->assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::PredictiveFailureOutcomeFailure->value)->count());
        $this->assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::PredictiveFailureCalibrationComputed->value)->count());

        $event = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::PredictiveFailureInserted->value)
            ->firstOrFail();

        $this->assertTrue(data_get($event->payload, 'governance.operator_opt_in_required'));
        $this->assertFalse(data_get($event->payload, 'governance.daily_plan_auto_insert_allowed'));
    }

    public function test_predict_failure_is_blocked_under_high_cognitive_load(): void
    {
        $exit = Artisan::call('atlas:predict', [
            'action' => 'failure',
            'subject' => 'queue-batching-deadlock',
            '--domain' => 'programming',
            '--load' => 'high',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('blocked', $payload['status']);
        $this->assertTrue(data_get($payload, 'governance.operator_opt_in_required'));
        $this->assertFalse(data_get($payload, 'governance.random_frustration_allowed'));
        $this->assertSame('predictive_failure_blocked_high_cognitive_load', data_get($payload, 'safety_gate.reason'));
        $this->assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::PredictiveFailureInsertionSkipped->value)->count());
    }

    public function test_predict_failure_requires_specific_target_to_avoid_random_frustration(): void
    {
        $exit = Artisan::call('atlas:predict', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('invalid_input', $payload['status']);
        $this->assertSame('predictive_failure_subject_required', $payload['reason']);
        $this->assertTrue(data_get($payload, 'governance.specific_target_required'));
        $this->assertFalse(data_get($payload, 'governance.empty_subject_allowed'));
        $this->assertSame(0, DB::table('predictive_failure_insertions')->count());
        $this->assertSame(0, AtlasLedgerEvent::query()->count());
    }

    public function test_predict_resolve_requires_valid_insertion_id(): void
    {
        $exit = Artisan::call('atlas:predict', [
            'action' => 'resolve',
            '--outcome' => 'failure',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('invalid_input', $payload['status']);
        $this->assertSame('predictive_failure_insertion_id_required', $payload['reason']);
        $this->assertTrue(data_get($payload, 'governance.specific_target_required'));
    }

    public function test_predictive_failure_flow_rejects_empty_target_even_outside_cli(): void
    {
        $payload = app(PredictiveFailureFlow::class)->insert('   ', 'programming');

        $this->assertSame('invalid_input', $payload['status']);
        $this->assertSame('predictive_failure_subject_required', $payload['reason']);
        $this->assertTrue(data_get($payload, 'governance.specific_target_required'));
        $this->assertFalse(data_get($payload, 'governance.empty_subject_allowed'));
        $this->assertSame(0, DB::table('predictive_failure_insertions')->count());
        $this->assertSame(0, AtlasLedgerEvent::query()->count());
    }

    public function test_predictive_failure_flow_blocks_when_storage_is_unavailable(): void
    {
        Schema::dropIfExists('predictive_failure_calibration_metrics');
        Schema::dropIfExists('predictive_failure_insertions');

        $payload = app(PredictiveFailureFlow::class)->insert('queue-batching-deadlock', 'programming');

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('predictive_failure_storage_unavailable', $payload['reason']);
        $this->assertSame('run_migrations_before_predictive_failure', $payload['next_action']);
        $this->assertSame(0, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::PredictiveFailureInserted->value)->count());
        $this->assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::PredictiveFailureInsertionSkipped->value)->count());
    }
}
