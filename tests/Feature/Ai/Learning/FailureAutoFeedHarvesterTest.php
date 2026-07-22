<?php

namespace Tests\Feature\Ai\Cognitive;

use App\Services\Ai\Cognitive\Failure\FailureAutoFeedHarvester;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class FailureAutoFeedHarvesterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_07_160000_create_failure_signatures_table.php'))->up();

        Schema::create('ai_job_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ai_job_id');
            $table->integer('attempt_number')->default(1);
            $table->string('worker_id')->default('worker-test');
            $table->string('provider');
            $table->string('model')->nullable();
            $table->string('status');
            $table->integer('exit_code')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->text('stderr_excerpt')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_job_attempts');
        Schema::dropIfExists('failure_repetition_alerts');
        Schema::dropIfExists('failure_diversity_metrics');
        Schema::dropIfExists('failure_signatures');
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_disabled_by_default_writes_nothing(): void
    {
        $this->insertAttempt(['status' => 'failed', 'error_message' => 'provider crashed hard']);

        $report = app(FailureAutoFeedHarvester::class)->harvest();

        $this->assertSame('disabled', $report['status']);
        $this->assertSame(0, DB::table('failure_signatures')->count());
    }

    public function test_harvests_failed_attempts_with_provider_in_features_and_triggers_alert(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->insertAttempt([
                'status' => 'failed',
                'provider' => 'codex_cli',
                'error_code' => 'provider_exception',
                'error_message' => 'provider process exited unexpectedly during tool call',
                'metadata' => json_encode(['tool' => 'workspace_edit']),
            ]);
        }
        $this->insertAttempt(['status' => 'succeeded', 'error_message' => null]);

        $report = app(FailureAutoFeedHarvester::class)->harvest(force: true);

        $this->assertSame('ok', $report['status']);
        $this->assertSame(3, $report['scanned']);
        $this->assertSame(3, $report['harvested']);
        $this->assertSame(1, $report['alerts_triggered']);
        $this->assertSame(3, DB::table('failure_signatures')->count());

        $row = (array) DB::table('failure_signatures')->orderByDesc('id')->first();
        $features = json_decode((string) $row['canonical_features'], true);
        $this->assertSame('codex_cli', $features['provider']);
        $this->assertSame('workspace_edit', $features['tool']);
        $this->assertSame('ai_job_attempt_failed', $features['event_type']);
        $this->assertStringStartsWith('job_attempt:', (string) $row['envelope_id']);
        $this->assertSame(3, (int) $row['recurrence_count']);
    }

    public function test_second_run_is_idempotent(): void
    {
        $this->insertAttempt(['status' => 'failed', 'error_message' => 'timeout waiting for provider response']);
        $this->insertAttempt(['status' => 'timeout', 'error_code' => 'timeout', 'error_message' => 'attempt timed out after 300s']);

        $harvester = app(FailureAutoFeedHarvester::class);
        $first = $harvester->harvest(force: true);
        $second = $harvester->harvest(force: true);

        $this->assertSame(2, $first['harvested']);
        $this->assertSame(0, $second['harvested']);
        $this->assertSame(2, $second['skipped_existing']);
        $this->assertSame(2, DB::table('failure_signatures')->count());
    }

    public function test_fixture_noise_is_blocked_by_quality_gate(): void
    {
        $this->insertAttempt(['status' => 'failed', 'error_message' => 'deterministic smoke test echo failed']);

        $report = app(FailureAutoFeedHarvester::class)->harvest(force: true);

        $this->assertSame(0, $report['harvested']);
        $this->assertSame(1, $report['skipped_noise']['fixture_echo'] ?? 0);
        $this->assertSame(0, DB::table('failure_signatures')->count());
    }

    public function test_attempts_outside_window_are_not_scanned(): void
    {
        $this->insertAttempt([
            'status' => 'failed',
            'error_message' => 'old failure outside window',
            'created_at' => now()->subDays(10)->toDateTimeString(),
        ]);

        $report = app(FailureAutoFeedHarvester::class)->harvest(windowHours: 24, force: true);

        $this->assertSame(0, $report['scanned']);
    }

    public function test_ledger_operation_failed_is_harvested_with_source_event_id(): void
    {
        app(AtlasEvidenceLedger::class)->record(LedgerEventType::OperationFailed, [
            'schema_version' => 'test.operation_failed.v1',
            'message' => 'mission execution failed before certification',
            'domain' => 'engineering',
            'provider' => 'hermes_cli',
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'test',
            'envelope_id' => 'op:'.Str::ulid(),
            'correlation_id' => 'test',
            'emitter_stage' => 'test',
            'emitter_version' => 'v1',
        ]);

        $report = app(FailureAutoFeedHarvester::class)->harvest(force: true);

        $this->assertSame(1, $report['harvested']);
        $row = (array) DB::table('failure_signatures')->first();
        $this->assertNotNull($row['source_ledger_event_id']);
        $this->assertStringStartsWith('ledger_event:', (string) $row['envelope_id']);
        $features = json_decode((string) $row['canonical_features'], true);
        $this->assertSame('hermes_cli', $features['provider']);
    }

    public function test_enabled_flag_allows_scheduled_run_without_force(): void
    {
        config(['atlas.ai.failure_auto_feed.enabled' => true]);
        $this->insertAttempt(['status' => 'failed', 'error_message' => 'real runtime failure with the flag on']);

        Artisan::call('atlas:failure:auto-feed', ['--json' => true]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('ok', $report['status']);
        $this->assertSame(1, $report['harvested']);
    }

    public function test_recurrence_metric_computes_deltas_on_raw_attempts(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->insertAttempt([
                'status' => 'failed',
                'error_code' => 'provider_exception',
                'error_message' => 'recurring provider failure pattern',
                'created_at' => now()->subDays(2)->toDateTimeString(),
            ]);
        }
        $this->insertAttempt([
            'status' => 'failed',
            'error_code' => 'provider_exception',
            'error_message' => 'recurring provider failure pattern',
            'created_at' => now()->subDays(20)->toDateTimeString(),
        ]);
        $this->insertAttempt([
            'status' => 'succeeded',
            'created_at' => now()->subDays(2)->toDateTimeString(),
        ]);

        Artisan::call('atlas:failure', ['action' => 'recurrence', '--days' => 14, '--json' => true]);
        $metric = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('computed', $metric['status']);
        $this->assertSame(1, $metric['cluster_count']);
        $cluster = $metric['clusters'][0];
        $this->assertSame(3, $cluster['current_count']);
        $this->assertSame(1, $cluster['previous_count']);
        $this->assertSame(2, $cluster['delta']);
        $this->assertSame('worsening', $cluster['direction']);
        $this->assertSame(3, $metric['totals']['current']['failed']);
        $this->assertSame(1, $metric['totals']['previous']['failed']);
        $this->assertSame(round(3 / 4, 4), $metric['totals']['current']['failure_rate']);
        $this->assertStringContainsString('ai_job_attempts', (string) $metric['measurement_basis']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function insertAttempt(array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::table('ai_job_attempts')->insert(array_merge([
            'id' => $id,
            'ai_job_id' => (string) Str::uuid(),
            'attempt_number' => 1,
            'worker_id' => 'worker-test',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'status' => 'failed',
            'exit_code' => 1,
            'error_code' => null,
            'error_message' => null,
            'started_at' => now()->subMinutes(5)->toDateTimeString(),
            'finished_at' => now()->subMinutes(4)->toDateTimeString(),
            'metadata' => json_encode([]),
            'created_at' => now()->subMinutes(5)->toDateTimeString(),
            'updated_at' => now()->subMinutes(4)->toDateTimeString(),
        ], $overrides));

        return $id;
    }
}
