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

    // ── AC2: behavior-shifted organs (still exist, docs describe stale behavior) ─

    public function test_reference_to_behavior_shifted_organ_is_flagged_stale(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDocDriftDetector)->detect([
            'behavior_shifted_organs' => [
                ['name' => 'AtlasStillNamedOrgan', 'note' => 'now fails closed instead of open'],
            ],
            'references' => [
                ['file' => 'docs/x.md', 'symbol' => 'AtlasStillNamedOrgan'],
            ],
        ]);

        $this->assertFalse($result['docs_synced']);
        $this->assertCount(1, $result['stale_refs']);
        $this->assertSame('behavior_shift', $result['stale_refs'][0]['drift_kind']);
        $this->assertSame('', $result['stale_refs'][0]['replacement']);
        $this->assertStringContainsString('now fails closed instead of open', $result['stale_refs'][0]['recommended_sync_action']);
    }

    public function test_retired_organ_reference_has_retired_drift_kind(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDocDriftDetector)->detect([
            'retired_organs' => [
                ['name' => 'AtlasOldOrgan', 'replacement' => 'AtlasNewOrgan'],
            ],
            'references' => [
                ['file' => 'docs/x.md', 'symbol' => 'AtlasOldOrgan'],
            ],
        ]);

        $this->assertSame('retired', $result['stale_refs'][0]['drift_kind']);
    }

    // ── AC3: intentional historical notes are distinguished from real drift ────

    public function test_intentional_historical_note_does_not_require_update_or_block_sync(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDocDriftDetector)->detect([
            'retired_organs' => [
                ['name' => 'AtlasOldOrgan', 'replacement' => 'AtlasNewOrgan'],
            ],
            'references' => [
                ['file' => 'CHANGELOG.md', 'symbol' => 'AtlasOldOrgan', 'intentional_historical_note' => true],
            ],
        ]);

        $this->assertTrue($result['docs_synced']);
        $this->assertCount(1, $result['stale_refs']);
        $this->assertFalse($result['stale_refs'][0]['required_update']);
        $this->assertSame('historical', $result['stale_refs'][0]['severity']);
        $this->assertStringContainsString('No action', $result['stale_refs'][0]['recommended_sync_action']);
    }

    // ── AC4: exact doc target, stale symbol and recommended sync action ────────

    public function test_recommended_sync_action_names_file_symbol_and_replacement_for_retired_organ(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDocDriftDetector)->detect([
            'retired_organs' => [
                ['name' => 'AtlasOldOrgan', 'replacement' => 'AtlasNewOrgan'],
            ],
            'references' => [
                ['file' => 'docs/x.md', 'symbol' => 'AtlasOldOrgan'],
            ],
        ]);

        $action = $result['stale_refs'][0]['recommended_sync_action'];
        $this->assertStringContainsString('docs/x.md', $action);
        $this->assertStringContainsString('AtlasOldOrgan', $action);
        $this->assertStringContainsString('AtlasNewOrgan', $action);
    }
}
