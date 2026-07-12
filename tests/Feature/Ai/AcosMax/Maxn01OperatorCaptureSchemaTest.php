<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AcosMax;

use App\Models\AiTrace;
use App\Models\OperatorLearningSignal;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckRegistry;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use App\Services\Ai\Cognition\Watchdog\Checks\OperatorLearningCaptureSchemaWatchdogCheck;
use App\Services\Ai\OperatorIntelligence\OperatorLearningRuntimeCaptureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class Maxn01OperatorCaptureSchemaTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_traces');
        $this->dropOperatorTables();
        Cache::forget(OperatorLearningRuntimeCaptureService::FAILURE_COUNTER_CACHE_KEY);
        Cache::forget(OperatorLearningRuntimeCaptureService::LAST_FAILURE_CACHE_KEY);

        parent::tearDown();
    }

    public function test_operator_migrations_materialize_all_operator_tables_and_live_capture_writes_sqlite_signal(): void
    {
        config()->set('atlas_operator_intelligence.enabled', true);
        config()->set('atlas_operator_intelligence.chat_capture_enabled', true);
        config()->set('atlas_operator_intelligence.comprehension_per_turn_enabled', false);

        $this->migrateOperatorSchema();
        $this->createMinimalAiTracesTable();

        foreach (OperatorLearningRuntimeCaptureService::REQUIRED_TABLES as $table) {
            self::assertTrue(Schema::hasTable($table), $table.' should exist after MAXN-01 operator migrations');
        }
        self::assertTrue(Schema::hasTable('operator_learning_signals'));

        $trace = AiTrace::query()->create([
            'source_type' => 'manual',
            'status' => 'queued',
            'metadata' => [],
        ]);

        $receipt = app(OperatorLearningRuntimeCaptureService::class)->captureFromTrace(
            $trace,
            'Prefiro respostas diretas e curtas daqui em diante.',
            [
                'create_candidate' => false,
                'payload' => ['operator_id' => 'maxn-01-operator'],
            ],
        );

        self::assertSame('captured', $receipt['status'] ?? null);
        self::assertSame(1, OperatorLearningSignal::query()->count());
        self::assertSame('chat_explicit_operator_signal', OperatorLearningSignal::query()->value('source_type'));
        self::assertSame('captured', $trace->refresh()->metadata['operator_learning_capture']['status'] ?? null);
    }

    public function test_watchdog_alerts_when_chat_capture_is_enabled_and_operator_table_is_missing(): void
    {
        config()->set('atlas_operator_intelligence.enabled', true);
        config()->set('atlas_operator_intelligence.chat_capture_enabled', true);
        $this->dropOperatorTables();

        $result = app(OperatorLearningCaptureSchemaWatchdogCheck::class)->run();

        self::assertSame(AtlasWatchdogCheckResult::STATUS_ALERT, $result->status);
        self::assertContains('operator_learning_signals', $result->evidence['missing_tables']);
        self::assertSame('operator_learning_schema_missing', $result->alert['code'] ?? null);
    }

    public function test_capture_missing_schema_increments_exposed_failure_counter_instead_of_only_logging(): void
    {
        config()->set('atlas_operator_intelligence.enabled', true);
        config()->set('atlas_operator_intelligence.chat_capture_enabled', true);
        config()->set('atlas_operator_intelligence.comprehension_per_turn_enabled', false);
        $this->createMinimalAiTracesTable();
        $this->dropOperatorTables();
        Cache::forget(OperatorLearningRuntimeCaptureService::FAILURE_COUNTER_CACHE_KEY);
        Cache::forget(OperatorLearningRuntimeCaptureService::LAST_FAILURE_CACHE_KEY);

        $trace = AiTrace::query()->create([
            'source_type' => 'manual',
            'status' => 'queued',
            'metadata' => [],
        ]);

        $receipt = app(OperatorLearningRuntimeCaptureService::class)->captureFromTrace(
            $trace,
            'Prefiro respostas diretas e curtas daqui em diante.',
        );
        $report = app(OperatorLearningRuntimeCaptureService::class)->captureFailureReport();

        self::assertSame('failed', $receipt['status'] ?? null);
        self::assertSame('missing_operator_tables', $receipt['reason'] ?? null);
        self::assertGreaterThanOrEqual(1, $report['failure_count']);
        self::assertContains('operator_learning_signals', $report['missing_tables']);
        self::assertSame('missing_operator_tables', $report['last_failure']['reason'] ?? null);
    }

    public function test_app_provider_registers_maxn01_watchdog_check(): void
    {
        self::assertContains(
            'maxn-01.operator_learning_capture_schema',
            app(AtlasWatchdogCheckRegistry::class)->ids(),
        );
    }

    private function migrateOperatorSchema(): void
    {
        $this->dropOperatorTables();

        foreach ([
            '2026_06_08_130000_create_operator_learning_signals_table.php',
            '2026_06_08_130100_create_operator_learning_candidates_table.php',
            '2026_06_08_130200_create_operator_profile_items_table.php',
            '2026_06_08_130300_create_operator_profile_policy_rules_table.php',
            '2026_06_08_130400_create_operator_profile_feedback_events_table.php',
            '2026_06_08_130500_create_operator_profile_snapshots_table.php',
            '2026_06_08_140000_create_operator_pattern_detections_table.php',
            '2026_06_08_150000_create_operator_skill_proposals_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    private function createMinimalAiTracesTable(): void
    {
        Schema::dropIfExists('ai_traces');
        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_type', 80)->nullable();
            $table->string('status', 40)->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function dropOperatorTables(): void
    {
        foreach (array_reverse(OperatorLearningRuntimeCaptureService::REQUIRED_TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
}
