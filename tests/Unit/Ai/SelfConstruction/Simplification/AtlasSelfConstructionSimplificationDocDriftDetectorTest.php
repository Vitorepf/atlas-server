<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationDocDriftDetector;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSimplificationDocDriftDetectorTest extends TestCase
{
    public function test_detects_stale_reference_to_retired_organ_and_marks_docs_not_synced(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDocDriftDetector)->detect([
            'retired_organs' => [
                ['name' => 'AtlasOldOrgan', 'replacement' => 'AtlasNewOrgan'],
            ],
            'references' => [
                ['file' => 'docs/engineering-knowledge-base/old.md', 'symbol' => 'AtlasOldOrgan'],
            ],
        ]);

        $this->assertFalse($result['docs_synced']);
        $this->assertCount(1, $result['stale_refs']);
        $this->assertSame('docs/engineering-knowledge-base/old.md', $result['stale_refs'][0]['file']);
        $this->assertSame('AtlasOldOrgan', $result['stale_refs'][0]['symbol']);
        $this->assertSame('AtlasNewOrgan', $result['stale_refs'][0]['replacement']);
        $this->assertSame('high', $result['stale_refs'][0]['severity']);
        $this->assertTrue($result['stale_refs'][0]['required_update']);
    }

    public function test_detects_across_docs_memory_projection_and_runbook_fixture_paths(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDocDriftDetector)->detect([
            'retired_organs' => [
                ['name' => 'AtlasMergedA', 'replacement' => 'AtlasUnifiedX'],
                ['name' => 'AtlasMergedB', 'replacement' => 'AtlasUnifiedX'],
            ],
            'references' => [
                ['file' => 'docs/engineering-knowledge-base/x.md', 'symbol' => 'AtlasMergedA'],
                ['file' => 'CLAUDE.md', 'symbol' => 'AtlasMergedB'],
                ['file' => 'droid-wiki/systems/evolution-loop/runbook.md', 'symbol' => 'AtlasMergedA'],
            ],
        ]);

        $this->assertFalse($result['docs_synced']);
        $this->assertCount(3, $result['stale_refs']);
        $this->assertSame(['docs/engineering-knowledge-base/x.md', 'CLAUDE.md', 'droid-wiki/systems/evolution-loop/runbook.md'], array_column($result['stale_refs'], 'file'));
    }

    public function test_reference_not_matching_any_retired_organ_is_not_flagged(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDocDriftDetector)->detect([
            'retired_organs' => [
                ['name' => 'AtlasOldOrgan', 'replacement' => 'AtlasNewOrgan'],
            ],
            'references' => [
                ['file' => 'docs/x.md', 'symbol' => 'AtlasStillLiveOrgan'],
            ],
        ]);

        $this->assertTrue($result['docs_synced']);
        $this->assertSame([], $result['stale_refs']);
    }

    public function test_docs_synced_true_when_all_stale_refs_are_downgraded_below_high_severity(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDocDriftDetector)->detect([
            'retired_organs' => [
                ['name' => 'AtlasOldOrgan', 'replacement' => 'AtlasNewOrgan'],
            ],
            'references' => [
                ['file' => 'CHANGELOG.md', 'symbol' => 'AtlasOldOrgan', 'severity' => 'low'],
            ],
        ]);

        $this->assertTrue($result['docs_synced']);
        $this->assertCount(1, $result['stale_refs']);
        $this->assertSame('low', $result['stale_refs'][0]['severity']);
    }

    public function test_no_retired_organs_or_references_is_synced_with_empty_stale_refs(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDocDriftDetector)->detect([]);

        $this->assertTrue($result['docs_synced']);
        $this->assertSame([], $result['stale_refs']);
        $this->assertSame('atlas.self_construction.simplification.doc_drift_detector.v1', $result['schema']);
    }
}
