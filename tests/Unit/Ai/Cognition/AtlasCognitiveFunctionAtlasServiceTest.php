<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition;

use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\Cognition\AtlasCognitiveFunctionAtlasService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use Tests\TestCase;

class AtlasCognitiveFunctionAtlasServiceTest extends TestCase
{
    private AtlasCognitiveFunctionAtlasService $svc;

    private AtlasCognitionScoreCardService $scoreCard;

    private AtlasConstitutionalKernelService $kernel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scoreCard = $this->app->make(AtlasCognitionScoreCardService::class);
        $this->kernel = new AtlasConstitutionalKernelService;
        $this->svc = new AtlasCognitiveFunctionAtlasService($this->scoreCard, $this->kernel);
    }

    public function test_self_model_envelope_shape(): void
    {
        $sm = $this->svc->selfModel();
        $this->assertSame(AtlasCognitiveFunctionAtlasService::SELF_MODEL_SCHEMA, $sm['schema_version']);
        $this->assertSame(AtlasCognitionScoreCardService::canonicalSubsystemCount(), $sm['subsystem_count']);
        $this->assertGreaterThan(0, $sm['group_count']);
        $this->assertIsArray($sm['shape']);
        $this->assertIsArray($sm['gaps']);
        $this->assertSame($this->kernel->kernelHash(), $sm['kernel_hash']);
    }

    public function test_group_taxonomy_returns_sorted_unique_groups(): void
    {
        $groups = $this->svc->groupTaxonomy();
        $sorted = $groups;
        sort($sorted);
        $this->assertSame($sorted, $groups);
        $this->assertSame(array_values(array_unique($groups)), $groups);
        $this->assertContains('cognitive_immune', $groups);
        $this->assertContains('aucri', $groups);
    }

    public function test_subsystems_by_group_filters_correctly(): void
    {
        $immune = $this->svc->subsystemsByGroup('cognitive_immune');
        $this->assertNotEmpty($immune);
        foreach ($immune as $s) {
            $this->assertSame('cognitive_immune', $s['group']);
        }
    }

    public function test_subsystems_by_group_empty_string_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->subsystemsByGroup('');
    }

    public function test_gaps_by_group_returns_sorted_desc(): void
    {
        $gaps = $this->svc->gapsByGroup();
        $this->assertNotEmpty($gaps);
        $prev = PHP_INT_MAX;
        foreach ($gaps as $g) {
            $this->assertArrayHasKey('group', $g);
            $this->assertArrayHasKey('non_ready_pipeline', $g);
            $this->assertLessThanOrEqual($prev, $g['non_ready_pipeline']);
            $prev = $g['non_ready_pipeline'];
        }
    }

    public function test_which_group_owns_resolves_canonical_acronyms(): void
    {
        $this->assertSame('cognitive_immune', $this->svc->whichGroupOwns('G0'));
        $this->assertSame('aucri', $this->svc->whichGroupOwns('ARPTL'));
        $this->assertSame('reality', $this->svc->whichGroupOwns('AURG-4D'));
        $this->assertSame('teos', $this->svc->whichGroupOwns('TEOS-I3'));
        $this->assertNull($this->svc->whichGroupOwns('NOT-A-REAL-ONE'));
        $this->assertNull($this->svc->whichGroupOwns(''));
    }

    public function test_cognitive_shape_sums_to_canonical_count(): void
    {
        $shape = $this->svc->cognitiveShape();
        $sum = 0;
        foreach ($shape as $row) {
            $this->assertSame(AtlasCognitiveFunctionAtlasService::GROUP_SUMMARY_SCHEMA, $row['schema_version']);
            $sum += (int) $row['total'];
        }
        $this->assertSame(AtlasCognitionScoreCardService::canonicalSubsystemCount(), $sum);
    }

    public function test_is_group_overloaded_threshold(): void
    {
        // AUCRI has 18 subsystems → overloaded above default 8 threshold.
        $this->assertTrue($this->svc->isGroupOverloaded('aucri'));
        // TEOS has only 1 subsystem (TEOS-I3) → not overloaded.
        $this->assertFalse($this->svc->isGroupOverloaded('teos'));
        // Custom threshold.
        $this->assertTrue($this->svc->isGroupOverloaded('teos', 1));
    }

    public function test_threshold_zero_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->isGroupOverloaded('aucri', 0);
    }

    public function test_self_model_groups_match_taxonomy(): void
    {
        $sm = $this->svc->selfModel();
        $this->assertSame($this->svc->groupTaxonomy(), $sm['groups']);
        $this->assertSame(count($sm['groups']), $sm['group_count']);
    }

    public function test_shape_pipeline_buckets_consistent(): void
    {
        foreach ($this->svc->cognitiveShape() as $row) {
            $sum = (int) $row['pipeline_ready'] + (int) $row['pipeline_partial'] + (int) $row['pipeline_building'];
            $this->assertSame((int) $row['total'], $sum, "group {$row['group']} pipeline buckets must sum to total");
        }
    }
}
