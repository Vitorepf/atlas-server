<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use App\Models\Capture;
use App\Services\Ai\Memory\AtlasMemoryRegistryService;
use App\Services\Ai\Cognition\CaptureHmacLineageService;
use App\Services\Ai\Knowledge\AtlasKnowledgeSourcePacketRegistryService;
use App\Services\CaptureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

final class Maxi07CaptureHmacLineageTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    private const LINEAGE_SECRET_PROBE = 'maxi07-local-only-secret-probe-9f3c2a1b';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.capture.hmac_lineage_secret', self::LINEAGE_SECRET_PROBE);
        config()->set('atlas.transcription.enabled', false);
        config()->set('atlas.aurg.ingest_on_write', false);
        config()->set('atlas.memory_admission.mode', 'observe');

        $this->createCaptureTables();
        $this->createPacketTable();
        $this->createAtlasMemoryEntryTable();
    }

    public function test_source_packet_registration_stamps_hmac_source_stage(): void
    {
        $sourceHash = hash('sha256', 'maxi07-packet-source');
        $result = app(AtlasKnowledgeSourcePacketRegistryService::class)->register([
            'source_type' => AtlasKnowledgeSourcePacketRegistryService::SOURCE_TYPE_DOC,
            'origin_uri' => 'docs/engineering-knowledge-base/maxi07.md',
            'source_hash' => $sourceHash,
            'ingester' => 'maxi07-test',
        ]);

        $this->assertTrue($result['ok']);
        $packet = app(AtlasKnowledgeSourcePacketRegistryService::class)->findBySourceHash($sourceHash);
        $this->assertNotNull($packet);

        $chain = $packet->lineage['hmac_lineage'] ?? null;
        $this->assertIsArray($chain);
        $this->assertSame('verified', app(CaptureHmacLineageService::class)->verify($chain)['status']);
        $this->assertSame('source', $chain['stages'][0]['stage']);
    }

    public function test_capture_stamps_hmac_lineage_and_verify_lineage_reports_verified(): void
    {
        $capture = $this->createCapture();
        $chain = data_get($capture->metadata, 'cognitive_quarantine.lineage.hmac_lineage');
        $this->assertIsArray($chain);
        $this->assertSame('capture', $chain['stages'][0]['stage']);

        Artisan::call('atlas:immune:verify-lineage', [
            '--ref' => 'capture:'.$capture->client_id,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('verified', $payload['status']);
        $this->assertSame('pending_window', $payload['coverage_window']['status']);
        $this->assertSame(1, $payload['coverage_window']['chained_captures']);
        $this->assertSame(10, $payload['coverage_window']['min_captures']);
        $this->assertStringNotContainsString(self::LINEAGE_SECRET_PROBE, $output);
    }

    public function test_tampered_stage_payload_hash_reports_broken_at_capture(): void
    {
        $capture = $this->createCapture();
        $metadata = is_array($capture->metadata) ? $capture->metadata : [];
        data_set($metadata, 'cognitive_quarantine.lineage.hmac_lineage.stages.0.stage_payload_hash', str_repeat('f', 64));
        $capture->metadata = $metadata;
        $capture->save();

        Artisan::call('atlas:immune:verify-lineage', [
            '--ref' => 'capture:'.$capture->id,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('broken_at:capture', $payload['status']);
        $this->assertSame('capture', $payload['verify']['broken_at']);
    }

    public function test_memory_admission_stamps_memory_stage_with_provider_safe_chain(): void
    {
        $prior = app(CaptureHmacLineageService::class)->stampStage([], CaptureHmacLineageService::STAGE_CAPTURE, [
            'source_type' => 'capture',
            'client_id' => (string) Str::uuid(),
            'kind' => 'text',
            'domain' => 'engineering',
            'content_hash' => hash('sha256', 'maxi07-memory-body'),
        ]);

        $entry = app(AtlasMemoryRegistryService::class)->record([
            'memory_type' => 'technical_context',
            'scope_type' => 'global',
            'title' => 'MAXI-07 lineage fixture',
            'body' => 'motivo: hmac lineage memory stage. provenance: "maxi07 test fixture."',
            'source_type' => 'maxi07_test',
            'metadata' => [
                'capture_hmac_lineage_prior' => $prior,
            ],
        ]);

        $chain = data_get($entry->metadata, 'acos_max.maxi_07.capture_hmac_lineage');
        $this->assertIsArray($chain);
        $this->assertSame('memory', $chain['stages'][array_key_last($chain['stages'])]['stage']);

        $projection = json_encode($chain, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::LINEAGE_SECRET_PROBE, $projection);
        $this->assertStringNotContainsString('hmac_lineage_secret', $projection);

        Artisan::call('atlas:immune:verify-lineage', [
            '--ref' => 'memory:'.$entry->id,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('verified', $payload['status']);
        $this->assertStringNotContainsString(self::LINEAGE_SECRET_PROBE, Artisan::output());
    }

    public function test_capture_links_source_packet_chain_when_source_hash_present(): void
    {
        $sourceHash = hash('sha256', 'maxi07-linked-source');
        app(AtlasKnowledgeSourcePacketRegistryService::class)->register([
            'source_type' => AtlasKnowledgeSourcePacketRegistryService::SOURCE_TYPE_MANUAL,
            'origin_uri' => 'manual://maxi07',
            'source_hash' => $sourceHash,
            'ingester' => 'maxi07-test',
        ]);

        $capture = $this->createCapture([
            'source_hash' => $sourceHash,
            'origin_uri' => 'manual://maxi07',
        ]);

        $chain = data_get($capture->metadata, 'cognitive_quarantine.lineage.hmac_lineage');
        $this->assertIsArray($chain);
        $this->assertGreaterThanOrEqual(2, count($chain['stages']));
        $this->assertSame('source', $chain['stages'][0]['stage']);
        $this->assertSame('capture', $chain['stages'][1]['stage']);
        $this->assertSame('verified', app(CaptureHmacLineageService::class)->verify($chain)['status']);
    }

    public function test_coverage_window_stays_pending_until_ten_chained_captures(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->createCapture(['content_text' => 'capture '.$i]);
        }

        $report = app(CaptureHmacLineageService::class)->coverageWindowReport();
        $this->assertSame('pending_window', $report['status']);
        $this->assertSame(9, $report['chained_captures']);

        $this->createCapture(['content_text' => 'capture ten']);
        $report = app(CaptureHmacLineageService::class)->coverageWindowReport();
        $this->assertSame('ready', $report['status']);
        $this->assertSame(10, $report['chained_captures']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createCapture(array $overrides = []): Capture
    {
        $metadata = is_array($overrides['metadata'] ?? null) ? $overrides['metadata'] : [];
        unset($overrides['metadata']);

        $result = app(CaptureService::class)->create(array_merge([
            'client_id' => (string) Str::uuid(),
            'kind' => 'audio',
            'domain' => 'engineering',
            'content_text' => 'MAXI-07 capture lineage fixture with provenance marker.',
            'captured_at' => now()->toJSON(),
            'captured_timezone' => 'America/Sao_Paulo',
            'metadata' => array_merge(['recurrence_count' => 2], $metadata),
        ], $overrides));

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

    private function createPacketTable(): void
    {
        Schema::dropIfExists('atlas_knowledge_source_packets');
        $migration = require database_path('migrations/2026_05_25_040000_create_atlas_knowledge_source_packets_table.php');
        $migration->up();
    }
}
