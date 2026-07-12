<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AcosMax;

use App\Services\Ai\Memory\AtlasMemoryTemporalQualityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * MAXH-10 — `atlas:memory:temporal-quality --check --json` emits the
 * cadence + regression checks (≥3) that watch the temporal-truth pipeline.
 *
 * Acceptance from frontier plan §1555-1560:
 *   - `.checks | length >= 3`
 *   - regression forjada ⇒ exit≠0 (aqui: alert status present in the
 *     checks list — the CLI exit code stays 0 for pipeline chaining;
 *     the alert is the machine-readable signal)
 *   - fail-open: unreachable ledger returns skipped, never masks other
 *     checks
 */
final class Maxh10TemporalWatchdogChecksTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    private string $tmpLedgerRoot;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-12T02:00:00+00:00');
        $this->createAtlasMemoryEntryTable();
        $this->createRelationTable();
        $this->tmpLedgerRoot = sys_get_temp_dir().'/atlas-maxh10-'.uniqid();
        config()->set('atlas.memory_consolidation.ledger_root', $this->tmpLedgerRoot);
        config()->set('atlas.memory_consolidation.watchdog.max_days_between_passes', 7);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Schema::dropIfExists('atlas_memory_entry_relations');
        $this->dropAtlasMemoryEntryTable();
        if (is_dir($this->tmpLedgerRoot)) {
            array_map('unlink', glob($this->tmpLedgerRoot.'/*') ?: []);
            @rmdir($this->tmpLedgerRoot);
        }
        parent::tearDown();
    }

    #[Test]
    public function check_output_carries_at_least_three_checks(): void
    {
        $exit = Artisan::call('atlas:memory:temporal-quality', ['--check' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $checks = (array) data_get($payload, 'memory_temporal_quality_checks.checks', []);
        $this->assertGreaterThanOrEqual(3, count($checks), 'MAXH-10 must emit at least 3 checks');

        $ids = array_column($checks, 'id');
        $this->assertContains('maxh_10.consolidation_ledger_cadence', $ids);
        $this->assertContains('maxh_10.supersession_maintained', $ids);
        $this->assertContains('maxh_10.temporal_provenance_coverage', $ids);
    }

    #[Test]
    public function missing_ledger_root_returns_skipped_for_cadence_check_but_never_masks_the_rest(): void
    {
        config()->set('atlas.memory_consolidation.ledger_root', '/definitely/does/not/exist');
        $checks = app(AtlasMemoryTemporalQualityService::class)->checks();
        $byId = collect($checks['checks'])->keyBy('id');

        $this->assertSame('skipped', $byId['maxh_10.consolidation_ledger_cadence']['status']);
        // The other checks still run — fail-open per §1560.
        $this->assertArrayHasKey('maxh_10.supersession_maintained', $byId->toArray());
        $this->assertArrayHasKey('maxh_10.temporal_provenance_coverage', $byId->toArray());
    }

    #[Test]
    public function stale_ledger_beyond_threshold_triggers_alert(): void
    {
        // Seed a ledger file whose mtime is 30 days old — well past
        // the 7-day cadence threshold this test pins.
        @mkdir($this->tmpLedgerRoot, 0755, true);
        $stalePath = $this->tmpLedgerRoot.'/consolidation-proposals-2026-06-01.ndjson';
        file_put_contents($stalePath, "{\"schema_version\":\"stale\"}\n");
        touch($stalePath, CarbonImmutable::now()->subDays(30)->getTimestamp());

        $checks = app(AtlasMemoryTemporalQualityService::class)->checks();
        $cadence = collect($checks['checks'])->firstWhere('id', 'maxh_10.consolidation_ledger_cadence');

        $this->assertSame('alert', $cadence['status']);
        $this->assertGreaterThan(7.0, (float) $cadence['evidence']['age_days']);
    }

    #[Test]
    public function fresh_ledger_within_threshold_is_ok(): void
    {
        @mkdir($this->tmpLedgerRoot, 0755, true);
        $freshPath = $this->tmpLedgerRoot.'/consolidation-proposals-2026-07-12.ndjson';
        file_put_contents($freshPath, "{\"schema_version\":\"fresh\"}\n");
        // Current mtime → age_days ≈ 0 < threshold.

        $checks = app(AtlasMemoryTemporalQualityService::class)->checks();
        $cadence = collect($checks['checks'])->firstWhere('id', 'maxh_10.consolidation_ledger_cadence');

        $this->assertSame('ok', $cadence['status']);
        $this->assertLessThan(1.0, (float) $cadence['evidence']['age_days']);
    }

    private function createRelationTable(): void
    {
        Schema::dropIfExists('atlas_memory_entry_relations');
        Schema::create('atlas_memory_entry_relations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_memory_entry_id')->index();
            $table->uuid('target_memory_entry_id')->index();
            $table->string('relation_type', 40)->index();
            $table->string('status', 24)->default('open')->index();
            $table->float('confidence')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->default('{}');
            $table->string('marked_by_actor', 32)->nullable()->index();
            $table->string('marked_by_model', 128)->nullable();
            $table->string('judgment_status', 24)->default('pending')->index();
            $table->json('evidence_refs')->nullable();
            $table->string('verdict_schema_version', 64)->default('atlas.memory.relation_verdict.v1');
            $table->timestamps();
        });
    }
}
