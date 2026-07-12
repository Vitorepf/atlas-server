<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition;

use App\Models\Capture;
use App\Services\Ai\Autonomy\AtlasWeeklyMemoryDigestService;
use App\Services\Ai\Cognition\CognitiveImmunePromotionGateEvaluator;
use App\Services\Ai\Cognition\ImmuneCalibrationService;
use App\Services\Ai\Cognition\ImmuneVerdictLedger;
use App\Services\CaptureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ImmuneCalibrationCommandTest extends TestCase
{
    public function test_capture_pipeline_appends_immune_verdict_ledger_and_command_reports_known_miss_lower_bound(): void
    {
        $this->assertTrue(class_exists(ImmuneVerdictLedger::class), 'MAXI-03 immune verdict ledger service must exist.');
        $this->assertTrue(class_exists(ImmuneCalibrationService::class), 'MAXI-03 immune calibration service must exist.');
        if (! class_exists(ImmuneVerdictLedger::class) || ! class_exists(ImmuneCalibrationService::class)) {
            return;
        }

        $this->createCaptureTables();
        $this->createImmuneVerdictLedgerTable();

        $capture = $this->captureWithMetadata([
            'has_secret_marker' => true,
            'recurrence_count' => 2,
        ]);

        $ledgerRow = DB::table('immune_verdict_ledger')->first();
        $this->assertNotNull($ledgerRow);
        $this->assertSame('capture_pipeline', $ledgerRow->writer);
        $this->assertSame(data_get($capture->metadata, 'cognitive_quarantine.content_hash'), $ledgerRow->candidate_hash);
        $this->assertSame('blocked', $ledgerRow->promotion_status);
        $this->assertSame('true_block', $ledgerRow->sample_label);

        $exit = Artisan::call('atlas:immune:calibration', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(ImmuneCalibrationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(ImmuneCalibrationService::MEASURE_ID, $payload['measure_id']);
        $this->assertSame('read_only', $payload['mode']);
        $this->assertSame(1, data_get($payload, 'samples.known_miss_seed'));
        $this->assertSame('registered_elev_20s', data_get($payload, 'freeze.registry_status'));

        $seedGroup = collect($payload['groups'])->firstWhere('writer', 'known_miss_seed');
        $this->assertIsArray($seedGroup);
        $this->assertSame('G3', $seedGroup['gate']);
        $this->assertSame(1, $seedGroup['n']);
        $this->assertSame(1, $seedGroup['missed_poison']);
        $this->assertSame('lower_bound_known_miss', data_get($seedGroup, 'missed_poison_rate.bound'));
        $this->assertSame('insufficient_sample', data_get($seedGroup, 'missed_poison_rate.status'));
        $this->assertSame(1, data_get($seedGroup, 'missed_poison_rate.denominator'));

        $captureGroup = collect($payload['groups'])->firstWhere('writer', 'capture_pipeline');
        $this->assertIsArray($captureGroup);
        $this->assertSame('G3', $captureGroup['gate']);
        $this->assertSame(1, $captureGroup['blocks']);
        $this->assertSame(0.0, data_get($captureGroup, 'false_block_rate.value'));
    }

    public function test_immune_calibration_bands_are_pure_and_never_calibrated_without_known_miss_denominator(): void
    {
        $this->assertTrue(class_exists(ImmuneCalibrationService::class), 'MAXI-03 immune calibration service must exist.');
        if (! class_exists(ImmuneCalibrationService::class)) {
            return;
        }

        $service = new ImmuneCalibrationService(new ImmuneVerdictLedger, new CognitiveImmunePromotionGateEvaluator);

        $first = $service->bandForRate(0.2, 10, 10, 'false_block_rate');
        $second = $service->bandForRate(0.2, 10, 10, 'false_block_rate');
        $zeroKnownMissDenominator = $service->bandForRate(0.0, 0, 10, 'missed_poison_rate');

        $this->assertSame($first, $second);
        $this->assertSame('calibrated', $first['status']);
        $this->assertSame('insufficient_sample', $zeroKnownMissDenominator['status']);
        $this->assertSame('known_miss_denominator_zero', $zeroKnownMissDenominator['reason']);
    }

    public function test_weekly_digest_exposes_immune_calibration_section(): void
    {
        $this->assertTrue(class_exists(ImmuneCalibrationService::class), 'MAXI-03 immune calibration service must exist.');
        if (! class_exists(ImmuneCalibrationService::class)) {
            return;
        }

        $this->createImmuneVerdictLedgerTable();

        $digest = app(AtlasWeeklyMemoryDigestService::class)->digest(7);

        $this->assertSame(ImmuneCalibrationService::MEASURE_ID, data_get($digest, 'immune_calibration.measure_id'));
        $this->assertSame(1, data_get($digest, 'immune_calibration.samples.known_miss_seed'));
        $this->assertNotSame([], data_get($digest, 'immune_calibration.groups'));
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function captureWithMetadata(array $metadata): Capture
    {
        config()->set('atlas.transcription.enabled', false);

        $result = app(CaptureService::class)->create([
            'client_id' => (string) Str::uuid(),
            'kind' => 'audio',
            'domain' => 'outro',
            'content_text' => 'Bug regression with replay evidence. Provenance: local test. This learning should remain a candidate until review.',
            'captured_at' => now()->toJSON(),
            'captured_timezone' => 'America/Sao_Paulo',
            'metadata' => $metadata,
        ]);

        return $result['capture'];
    }

    private function createCaptureTables(): void
    {
        Schema::dropIfExists('transcription_jobs');
        Schema::dropIfExists('captures');

        Schema::create('captures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('client_id')->unique();
            $table->string('kind');
            $table->string('domain');
            $table->text('content_text')->nullable();
            $table->string('content_file_path')->nullable();
            $table->integer('content_duration_ms')->nullable();
            $table->integer('content_size_bytes')->nullable();
            $table->string('content_sha256')->nullable();
            $table->string('content_mime_type')->nullable();
            $table->string('transcription_status')->default('pending');
            $table->string('transcription_engine')->nullable();
            $table->text('transcription_error')->nullable();
            $table->timestamp('captured_at');
            $table->string('captured_timezone');
            $table->decimal('captured_lat', 10, 7)->nullable();
            $table->decimal('captured_lng', 10, 7)->nullable();
            $table->json('metadata')->default('{}');
            $table->json('pre_capture_digital_context')->default('{}');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('transcription_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('capture_id');
            $table->string('status')->default('queued');
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(3);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    private function createImmuneVerdictLedgerTable(): void
    {
        Schema::dropIfExists('immune_verdict_ledger');

        if (file_exists(database_path('migrations/2026_07_12_030000_create_immune_verdict_ledger_table.php'))) {
            (require database_path('migrations/2026_07_12_030000_create_immune_verdict_ledger_table.php'))->up();
        }
    }
}
