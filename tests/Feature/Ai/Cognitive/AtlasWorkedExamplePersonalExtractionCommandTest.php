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
            'commit_explanation' => 'Explained the lock contention, evidence, rollback path, and transfer rule.',
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
    }
}
