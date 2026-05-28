<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsBatteryEvidenceService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsCollectEvidenceService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use Tests\TestCase;

/**
 * Focused unit coverage for AtlasForgeRivalsBatteryEvidenceService public contract.
 *
 * Complements the integration-heavy AtlasForgeRivalsBatteryEvidenceReplayMultiCaseTest
 * by locking resolveRunIds input normalization and the fail-closed blocked envelope
 * when the operator omits run identifiers.
 */
final class AtlasForgeRivalsBatteryEvidenceServiceTest extends TestCase
{
    public function test_schema_version_and_battery_pack_file_constants(): void
    {
        $this->assertSame(
            'atlas.forge.rivals.battery_evidence_pack.v1',
            AtlasForgeRivalsBatteryEvidenceService::SCHEMA_VERSION,
        );
        $this->assertSame(
            'battery_evidence_pack.json',
            AtlasForgeRivalsBatteryEvidenceService::BATTERY_PACK_FILE,
        );
    }

    public function test_resolve_run_ids_from_csv_trims_dedupes_and_preserves_order(): void
    {
        $ids = AtlasForgeRivalsBatteryEvidenceService::resolveRunIds([
            'run_ids' => ' run-a , run-b, run-a , run-c ',
        ]);

        $this->assertSame(['run-a', 'run-b', 'run-c'], $ids);
    }

    public function test_resolve_run_ids_merges_single_run_id_without_duplicates(): void
    {
        $ids = AtlasForgeRivalsBatteryEvidenceService::resolveRunIds([
            'run_ids' => ['run-a', 'run-b'],
            'run_id' => 'run-b',
        ]);

        $this->assertSame(['run-a', 'run-b'], $ids);
    }

    public function test_resolve_run_ids_accepts_repeated_run_id_array(): void
    {
        $ids = AtlasForgeRivalsBatteryEvidenceService::resolveRunIds([
            'run_id' => ['run-a', 'run-b', 'run-a'],
        ]);

        $this->assertSame(['run-a', 'run-b'], $ids);
    }

    public function test_resolve_run_ids_returns_empty_for_empty_input(): void
    {
        $this->assertSame([], AtlasForgeRivalsBatteryEvidenceService::resolveRunIds([]));
    }

    public function test_aggregate_blocks_with_run_ids_required_when_input_empty(): void
    {
        $paths = new AtlasForgeRivalsRunPathResolver;
        $service = new AtlasForgeRivalsBatteryEvidenceService(
            $paths,
            new AtlasForgeRivalsCollectEvidenceService($paths),
        );

        $result = $service->aggregate([]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(['run_ids_required'], $result['blockers']);
        $this->assertFalse($result['external_provider_call']);
        $this->assertTrue($result['separated_from_external_rivals_certification']);
        $this->assertStringContainsString('--run-id', (string) $result['next_command']);
    }
}
