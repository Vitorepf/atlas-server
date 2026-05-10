<?php

namespace Tests\Feature\Ai\Cognitive;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasWorkedExamplePersonalExtractionCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_07_120000_create_dreyfus_overlays_table.php'))->up();
        (require database_path('migrations/2026_05_07_140000_create_worked_examples_table.php'))->up();
        (require database_path('migrations/2026_05_07_190000_create_worked_example_extractions_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('personal_extraction_jobs');
        Schema::dropIfExists('worked_example_extractions');
        Schema::dropIfExists('worked_examples');
        Schema::dropIfExists('dreyfus_overlays');
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_extracts_personal_programming_example_from_ledger_without_creating_parallel_flow(): void
    {
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::OperationCompleted, [
            'schema_version' => 'atlas.test.personal_worked_example_source.v1',
            'source_type' => 'programming_pr',
            'source_ref' => 'pr-42',
            'domain' => 'programming',
            'topic' => 'queue-batching',
            'title' => 'Queue batching lock fix for vitorepf@example.com',
            'problem_context' => 'Fix queue latency using api_key=sk_testsecret123456 after batching rollout.',
            'steps' => [
                ['action' => 'Measure lock contention.', 'reasoning' => 'CPU stayed low while latency rose.', 'why_works' => 'Finds concurrency bottleneck.'],
                ['action' => 'Batch writes outside the lock.', 'reasoning' => 'Reduce critical section.', 'why_works' => 'Keeps throughput stable.'],
            ],
            'commit_explanation' => 'Explained the lock contention to vitorepf@example.com with token=commitsecret123456, evidence, rollback path, and transfer rule.',
            'tests_passed' => true,
            'no_regression_30d' => true,
            'privacy_class' => 3,
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'test',
            'envelope_id' => 'test:personal_worked_example:pr-42',
            'correlation_id' => 'test:personal_worked_example',
            'emitter_stage' => 'test',
        ]);

        Artisan::call('atlas:worked-example', [
            'actionOrTopic' => 'extract',
            '--extract-source' => 'programming_pr',
            '--domain' => 'programming',
            '--json' => true,
        ]);
        $extract = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('ok', $extract['status']);
        $this->assertSame(1, $extract['summary']['extracted']);
        $this->assertSame(0, $extract['summary']['discarded_quality']);
        $this->assertSame(1, DB::table('worked_example_extractions')->where('extraction_status', 'extracted')->count());
        $this->assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::PersonalWorkedExampleExtracted->value)->count());
        $this->assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::PersonalExtractionBatchCompleted->value)->count());

        Artisan::call('atlas:worked-example', [
            'actionOrTopic' => 'personal',
            '--domain' => 'programming',
            '--json' => true,
        ]);
        $personal = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('personal', $personal['mode']);
        $this->assertCount(1, $personal['examples']);
        $this->assertSame('personal_ledger', $personal['examples'][0]['source']);

        $serialized = json_encode($personal['examples'][0], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('vitorepf@example.com', $serialized);
        $this->assertStringNotContainsString('sk_testsecret123456', $serialized);
        $this->assertStringContainsString('[redacted_email]', $serialized);
        $this->assertStringContainsString('[redacted_secret]', $serialized);

        $ledgerPayload = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::PersonalWorkedExampleExtracted->value)
            ->firstOrFail()
            ->payload;

        $this->assertFalse(data_get($ledgerPayload, 'candidate.raw_content_in_ledger'));
        $this->assertSame(sha1('pr-42'), data_get($ledgerPayload, 'candidate.source_ref_hash'));
        $this->assertArrayNotHasKey('problem_context', data_get($ledgerPayload, 'candidate', []));

        $extraction = DB::table('worked_example_extractions')
            ->where('source_ref', 'pr-42')
            ->first();
        $storedMetadata = json_encode(json_decode((string) $extraction->source_metadata, true), JSON_THROW_ON_ERROR);
        $storedSignals = json_encode(json_decode((string) $extraction->quality_signals, true), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('vitorepf@example.com', $storedMetadata);
        $this->assertStringNotContainsString('commitsecret123456', $storedMetadata);
        $this->assertStringNotContainsString('vitorepf@example.com', $storedSignals);
        $this->assertStringNotContainsString('commitsecret123456', $storedSignals);
    }

    public function test_rejects_low_quality_source_and_reports_extraction_status(): void
    {
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::OperationCompleted, [
            'source_type' => 'feynman_session',
            'source_ref' => 'feynman-low-score',
            'domain' => 'learning',
            'topic' => 'event-sourcing',
            'feynman_score' => 4,
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'test',
            'envelope_id' => 'test:feynman-low-score',
            'correlation_id' => 'test:personal_worked_example',
            'emitter_stage' => 'test',
        ]);

        Artisan::call('atlas:worked-example', [
            'actionOrTopic' => 'extract',
            '--extract-source' => 'feynman_session',
            '--json' => true,
        ]);
        $extract = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $extract['summary']['extracted']);
        $this->assertSame(1, $extract['summary']['discarded_quality']);

        Artisan::call('atlas:worked-example', [
            'actionOrTopic' => 'extract',
            'subject' => 'status',
            '--status' => 'discarded_quality',
            '--json' => true,
        ]);
        $status = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('status', $status['mode']);
        $this->assertSame('quality_feynman_below_threshold', $status['extractions'][0]['discard_reason']);

        $ledgerPayload = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::PersonalWorkedExampleDiscardedQuality->value)
            ->firstOrFail()
            ->payload;

        $this->assertFalse(data_get($ledgerPayload, 'candidate.raw_content_in_ledger'));
        $this->assertArrayNotHasKey('problem_context', data_get($ledgerPayload, 'candidate', []));
    }

    public function test_duplicate_extraction_preserves_existing_record_and_logs_only_safe_summary(): void
    {
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::OperationCompleted, [
            'schema_version' => 'atlas.test.personal_worked_example_source.v1',
            'source_type' => 'programming_pr',
            'source_ref' => 'pr-duplicate',
            'domain' => 'programming',
            'topic' => 'queue-batching',
            'title' => 'Queue batching duplicate source',
            'problem_context' => 'Sensitive context token=secretduplicatetoken123.',
            'steps' => [
                ['action' => 'Measure contention.', 'reasoning' => 'Latency rose.', 'why_works' => 'Finds bottleneck.'],
            ],
            'commit_explanation' => 'Explained evidence and transfer rule.',
            'tests_passed' => true,
            'no_regression_30d' => true,
            'privacy_class' => 1,
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'test',
            'envelope_id' => 'test:personal_worked_example:pr-duplicate',
            'correlation_id' => 'test:personal_worked_example_duplicate',
            'emitter_stage' => 'test',
        ]);

        Artisan::call('atlas:worked-example', [
            'actionOrTopic' => 'extract',
            '--extract-source' => 'programming_pr',
            '--domain' => 'programming',
            '--json' => true,
        ]);
        $first = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $first['summary']['extracted']);

        Artisan::call('atlas:worked-example', [
            'actionOrTopic' => 'extract',
            '--extract-source' => 'programming_pr',
            '--domain' => 'programming',
            '--json' => true,
        ]);
        $second = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $second['summary']['extracted']);
        $this->assertSame(1, $second['summary']['discarded_duplicate']);
        $this->assertSame('extracted', DB::table('worked_example_extractions')->where('source_ref', 'pr-duplicate')->value('extraction_status'));

        $duplicatePayload = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::PersonalWorkedExampleDiscardedDuplicate->value)
            ->firstOrFail()
            ->payload;

        $this->assertSame('read_only_existing_record_preserved', $duplicatePayload['duplicate_policy']);
        $this->assertFalse(data_get($duplicatePayload, 'candidate.raw_content_in_ledger'));
        $this->assertStringNotContainsString('secretduplicatetoken123', json_encode($duplicatePayload, JSON_THROW_ON_ERROR));
    }

    public function test_personal_extraction_schedule_control_surface_is_review_only(): void
    {
        Artisan::call('atlas:worked-example', [
            'actionOrTopic' => 'extract',
            'subject' => 'schedule',
            '--status' => 'on',
            '--json' => true,
        ]);
        $enabled = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('ok', $enabled['status']);
        $this->assertSame('extract_schedule', $enabled['mode']);
        $this->assertSame('on', $enabled['operation']);
        $this->assertTrue(data_get($enabled, 'job.enabled'));
        $this->assertFalse(data_get($enabled, 'autonomy.auto_apply'));
        $this->assertTrue(data_get($enabled, 'autonomy.review_required'));
        $this->assertTrue(data_get($enabled, 'autonomy.scheduler_registered'));
        $this->assertSame('registered_review_only', $enabled['registration_status']);

        Artisan::call('atlas:worked-example', [
            'actionOrTopic' => 'extract',
            'subject' => 'schedule',
            '--status' => 'off',
            '--json' => true,
        ]);
        $disabled = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse(data_get($disabled, 'job.enabled'));
        $this->assertFalse(data_get($disabled, 'autonomy.auto_apply'));
        $this->assertNull(data_get($disabled, 'job.next_run_at'));
    }

    public function test_scheduled_personal_extraction_runs_only_when_enabled_and_due(): void
    {
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::OperationCompleted, [
            'schema_version' => 'atlas.test.personal_worked_example_source.v1',
            'source_type' => 'programming_pr',
            'source_ref' => 'pr-scheduled',
            'domain' => 'programming',
            'topic' => 'scheduled-learning',
            'title' => 'Scheduled extraction source',
            'problem_context' => 'Safe scheduled source.',
            'steps' => [
                ['action' => 'Find the reusable pattern.', 'reasoning' => 'Pattern was repeated.', 'why_works' => 'Improves transfer.'],
            ],
            'commit_explanation' => 'Explained evidence and transfer rule.',
            'tests_passed' => true,
            'no_regression_30d' => true,
            'privacy_class' => 1,
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'test',
            'envelope_id' => 'test:personal_worked_example:pr-scheduled',
            'correlation_id' => 'test:personal_worked_example_scheduled',
            'emitter_stage' => 'test',
        ]);

        Artisan::call('atlas:worked-example', [
            'actionOrTopic' => 'extract',
            'subject' => 'scheduled',
            '--json' => true,
        ]);
        $missingJob = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('skipped', $missingJob['status']);
        $this->assertSame('schedule_job_not_configured', $missingJob['reason']);

        Artisan::call('atlas:worked-example', [
            'actionOrTopic' => 'extract',
            'subject' => 'schedule',
            '--status' => 'on',
            '--json' => true,
        ]);

        DB::table('personal_extraction_jobs')
            ->where('cadence', 'weekly')
            ->update(['next_run_at' => now()->subMinute()]);

        Artisan::call('atlas:worked-example', [
            'actionOrTopic' => 'extract',
            'subject' => 'scheduled',
            '--json' => true,
        ]);
        $run = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('ok', $run['status']);
        $this->assertSame('extract_scheduled', $run['mode']);
        $this->assertFalse(data_get($run, 'autonomy.auto_apply'));
        $this->assertTrue(data_get($run, 'autonomy.review_required'));
        $this->assertSame(1, data_get($run, 'result.summary.extracted'));
        $this->assertNotNull(data_get($run, 'schedule.job.last_run_at'));
        $this->assertNotNull(data_get($run, 'schedule.job.next_run_at'));
        $this->assertSame('completed', data_get($run, 'schedule.job.last_run_summary.status'));
    }
}
