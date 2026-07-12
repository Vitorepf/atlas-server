<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AcosMax;

use App\Services\Ai\EngineeringKernel\KernelEvidenceAuthority;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class Elev12VerifiedShareTest extends TestCase
{
    private string $originalStoragePath;

    private string $tmpStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = storage_path();
        $this->tmpStorage = sys_get_temp_dir().'/atlas-elev12-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);
        $this->app->useStoragePath($this->tmpStorage);

        $this->migrateLedger();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        $this->deleteDirectory($this->tmpStorage);
        $this->app->useStoragePath($this->originalStoragePath);

        parent::tearDown();
    }

    public function test_verified_share_requires_independent_freeze_before_reporting_series(): void
    {
        Artisan::call('atlas:acos:verified-share', ['--json' => true]);
        $missing = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('missing_freeze', $missing['status']);
        self::assertSame('acos.verified_share.v1', $missing['measure_id']);

        Artisan::call('atlas:acos:freeze', ['--json' => json_encode($this->freezePayload(), JSON_THROW_ON_ERROR)]);

        $this->appendLiveOutcome('dev');
        $this->appendLiveOutcome('dev');
        $this->appendLiveOutcome('forge');

        Artisan::call('atlas:acos:verified-share', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('insufficient_signal', $payload['status']);
        self::assertSame(3, $payload['aggregate']['total_count']);
        self::assertSame(5, $payload['freeze']['denominator_min']);
        self::assertSame('cursor-acos-max-elev12', $payload['freeze']['author_engine_id']);
        self::assertSame('codex-elev12-judge', $payload['freeze']['judge_engine_id']);
        self::assertNotSame($payload['freeze']['author_engine_id'], $payload['freeze']['judge_engine_id']);
    }

    public function test_verified_share_reports_raw_counts_by_executor_after_freeze(): void
    {
        Artisan::call('atlas:acos:freeze', ['--json' => json_encode($this->freezePayload(), JSON_THROW_ON_ERROR)]);

        foreach (['dev', 'dev', 'dev', 'forge', 'forge'] as $executor) {
            $this->appendLiveOutcome($executor);
        }
        foreach (['atlas_dev.pipeline_run_executor', 'atlas_dev.provider_process', 'atlas_forge.work_packet_execution_cycle', 'atlas_forge.provider_process'] as $surface) {
            $this->insertCoverageReceipt($surface);
        }

        Artisan::call('atlas:acos:verified-share', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('ok', $payload['status']);
        self::assertSame('acos.verified_share.v1', $payload['measure_id']);
        self::assertSame(4, $payload['aggregate']['verified_count']);
        self::assertSame(5, $payload['aggregate']['total_count']);
        self::assertSame(0.8, $payload['aggregate']['verified_share']);
        self::assertSame(['verified_count' => 2, 'total_count' => 3, 'verified_share' => 0.6667], $payload['executors']['dev']);
        self::assertSame(['verified_count' => 2, 'total_count' => 2, 'verified_share' => 1.0], $payload['executors']['forge']);
        self::assertSame(['verified_count' => 0, 'total_count' => 0, 'verified_share' => null], $payload['executors']['autonomos']);
    }

    /** @return array<string,mixed> */
    private function freezePayload(): array
    {
        return [
            'kind' => 'measure_freeze',
            'measure_id' => 'acos.verified_share.v1',
            'formula' => 'verified_share = enforce-mode verification receipts ÷ OUTC-01 outcome receipts, grouped by executor',
            'thresholds' => [
                'verified_share_min' => 0.80,
                'window_days_min' => 14,
                'denominator_min_executions' => 5,
            ],
            'denominator_min' => 5,
            'ttl_days' => 30,
            'author_engine_id' => 'cursor-acos-max-elev12',
            'judge_engine_id' => 'codex-elev12-judge',
            'series_registry' => [
                'series' => 'acos.verified_share.v1',
                'path' => 'atlas:acos:verified-share --json',
                'watchdog_plugin' => 'wdg-01.acos_verified_share',
            ],
        ];
    }

    private function appendLiveOutcome(string $executor): void
    {
        $path = storage_path('atlas/atlas_decide/live_outcomes.jsonl');
        @mkdir(dirname($path), 0o775, true);
        file_put_contents($path, json_encode([
            'schema_version' => 'atlas.atlas_decide.live_outcome.v1',
            'recorded_at' => now()->toIso8601String(),
            'task_category' => $executor,
            'role' => $executor,
            'provider' => 'local',
            'result' => 'success',
            'actor' => 'engineering_outcome_spine:'.$executor,
        ], JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);
    }

    private function insertCoverageReceipt(string $surface): void
    {
        DB::table('atlas_ledger_events')->insert([
            'event_id' => (string) str()->ulid(),
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'elev12-'.$surface,
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => 'elev12',
            'causation_id' => null,
            'event_type' => 'OPERATION_COMPLETED',
            'emitter_stage' => KernelEvidenceAuthority::EMITTER_STAGE,
            'emitter_version' => 'atlas.engineering_kernel.evidence_authority.v1',
            'payload' => json_encode([
                'event_name' => 'engineering.execution.coverage.recorded',
                'mode' => 'enforce',
                'surface' => $surface,
            ], JSON_THROW_ON_ERROR),
            'payload_hash' => hash('sha256', $surface),
            'scope_type' => 'engineering_execution_surface',
            'scope_id' => $surface,
            'event_hash' => hash('sha256', 'event:'.$surface),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function migrateLedger(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 32)->primary();
            $table->string('schema_version', 40)->default('atlas.ledger_event.v1');
            $table->string('tenant_id', 120)->index();
            $table->string('operator_id', 120)->index();
            $table->string('envelope_id', 80)->index();
            $table->string('receipt_id', 80)->nullable()->index();
            $table->string('trace_id', 80)->nullable()->index();
            $table->string('correlation_id', 120)->index();
            $table->string('causation_id', 80)->nullable()->index();
            $table->string('event_type', 80)->index();
            $table->string('emitter_stage', 120)->index();
            $table->string('emitter_version', 80);
            $table->json('payload');
            $table->string('payload_hash', 64)->index();
            $table->string('scope_type', 40)->nullable()->index();
            $table->string('scope_id', 80)->nullable()->index();
            $table->string('event_hash', 64)->nullable()->index();
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();
        });
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
