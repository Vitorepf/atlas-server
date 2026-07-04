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

    // ── AC1: retired organ without replacement → missing_replacement_proof ──

    public function test_retired_organ_without_replacement_has_missing_replacement_proof(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDocDriftDetector)->detect([
            'retired_organs' => [
                ['name' => 'AtlasLostOrgan', 'replacement' => ''],
            ],
            'references' => [
                ['file' => 'docs/guide.md', 'symbol' => 'AtlasLostOrgan'],
            ],
        ]);

        $this->assertFalse($result['docs_synced']);
        $ref = $result['stale_refs'][0];
        $this->assertTrue($ref['required_update']);
        $this->assertTrue($ref['missing_replacement_proof']);
        $this->assertSame('retired_without_replacement_proof', $ref['reason']);
        $this->assertStringContainsString('no replacement', $ref['recommended_sync_action']);
    }

    // ── AC2: historical reference has required_update=false and historical_reference reason ──

    public function test_intentional_historical_note_has_historical_reference_reason(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDocDriftDetector)->detect([
            'retired_organs' => [
                ['name' => 'AtlasOldOrgan', 'replacement' => 'AtlasNewOrgan'],
            ],
            'references' => [
                ['file' => 'CHANGELOG.md', 'symbol' => 'AtlasOldOrgan', 'intentional_historical_note' => true],
            ],
        ]);

        $ref = $result['stale_refs'][0];
        $this->assertFalse($ref['required_update']);
        $this->assertSame('historical_reference', $ref['reason']);
        $this->assertFalse($ref['missing_replacement_proof']);
        $this->assertTrue($result['docs_synced']);
    }

    public function test_retired_with_replacement_has_correct_reason_and_no_missing_proof(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDocDriftDetector)->detect([
            'retired_organs' => [
                ['name' => 'AtlasOldOrgan', 'replacement' => 'AtlasNewOrgan'],
            ],
            'references' => [
                ['file' => 'docs/x.md', 'symbol' => 'AtlasOldOrgan'],
            ],
        ]);

        $ref = $result['stale_refs'][0];
        $this->assertFalse($ref['missing_replacement_proof']);
        $this->assertSame('retired_with_replacement', $ref['reason']);
    }

    public function test_behavior_shifted_organ_has_behavior_shifted_reason(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDocDriftDetector)->detect([
            'behavior_shifted_organs' => [
                ['name' => 'AtlasShiftedOrgan', 'note' => 'behavior changed'],
            ],
            'references' => [
                ['file' => 'docs/x.md', 'symbol' => 'AtlasShiftedOrgan'],
            ],
        ]);

        $ref = $result['stale_refs'][0];
        $this->assertSame('behavior_shifted', $ref['reason']);
        $this->assertFalse($ref['missing_replacement_proof']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC2: live guidance to retired organ without replacement
    // ═══════════════════════════════════════════════════════════════════════

    public function test_live_guidance_without_replacement_proof_sets_docs_synced_false_and_emits_required_update_and_missing_proof(): void
    {
        // A live guidance reference (not marked historical) to a retired organ
        // with no replacement must make docs_synced=false and emit required_update
        // plus missing_replacement_proof.
        $result = (new AtlasSelfConstructionSimplificationDocDriftDetector)->detect([
            'retired_organs' => [
                ['name' => 'AtlasLostOrgan', 'replacement' => ''],
            ],
            'references' => [
                ['file' => 'docs/guide.md', 'symbol' => 'AtlasLostOrgan'],
            ],
        ]);

        $this->assertFalse($result['docs_synced'],
            'Live guidance to retired organ without replacement must make docs_synced=false');
        $ref = $result['stale_refs'][0];
        $this->assertTrue($ref['required_update'], 'must emit required_update');
        $this->assertTrue($ref['missing_replacement_proof'], 'must emit missing_replacement_proof');
        $this->assertSame('retired_without_replacement_proof', $ref['reason']);
    }

    public function test_live_guidance_with_replacement_does_not_show_missing_proof(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDocDriftDetector)->detect([
            'retired_organs' => [
                ['name' => 'AtlasLostOrgan', 'replacement' => 'AtlasReplacementOrgan'],
            ],
            'references' => [
                ['file' => 'docs/guide.md', 'symbol' => 'AtlasLostOrgan'],
            ],
        ]);

        $ref = $result['stale_refs'][0];
        $this->assertFalse($ref['missing_replacement_proof'],
            'A retired organ with a replacement must not have missing_replacement_proof');
        $this->assertTrue($ref['required_update']);
        $this->assertFalse($result['docs_synced']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC3: historical references distinguished from live guidance
    // ═══════════════════════════════════════════════════════════════════════

    public function test_historical_reference_does_not_fail_docs_synced_even_without_replacement(): void
    {
        // A historical reference to a retired organ with no replacement must
        // NOT fail docs_synced. The reference must remain visible but with
        // required_update=false and reason=historical_reference.
        $result = (new AtlasSelfConstructionSimplificationDocDriftDetector)->detect([
            'retired_organs' => [
                ['name' => 'AtlasLostOrgan', 'replacement' => ''],
            ],
            'references' => [
                ['file' => 'CHANGELOG.md', 'symbol' => 'AtlasLostOrgan', 'intentional_historical_note' => true],
            ],
        ]);

        $this->assertTrue($result['docs_synced'],
            'Historical reference must not fail docs_synced even without replacement proof');
        $this->assertCount(1, $result['stale_refs'], 'Historical reference must remain visible');
        $ref = $result['stale_refs'][0];
        $this->assertFalse($ref['required_update'], 'Historical reference must have required_update=false');
        $this->assertSame('historical_reference', $ref['reason']);
        $this->assertSame('historical', $ref['severity']);
    }

    public function test_mixed_live_and_historical_references_in_same_call(): void
    {
        // When a detect call has both live and historical references, only the
        // live ones should trigger docs_synced=false.
        $result = (new AtlasSelfConstructionSimplificationDocDriftDetector)->detect([
            'retired_organs' => [
                ['name' => 'AtlasOldOrgan', 'replacement' => 'AtlasNewOrgan'],
            ],
            'references' => [
                ['file' => 'docs/guide.md', 'symbol' => 'AtlasOldOrgan'],
                ['file' => 'CHANGELOG.md', 'symbol' => 'AtlasOldOrgan', 'intentional_historical_note' => true],
            ],
        ]);

        // The historical reference must not prevent docs_synced=false when live guide is stale
        $this->assertFalse($result['docs_synced']);
        $this->assertCount(2, $result['stale_refs']);

        $live = $result['stale_refs'][0];
        $historical = $result['stale_refs'][1];

        $this->assertTrue($live['required_update'], 'Live reference must have required_update=true');
        $this->assertFalse($historical['required_update'], 'Historical reference must have required_update=false');
        $this->assertSame('historical_reference', $historical['reason']);
        $this->assertSame('retired_with_replacement', $live['reason']);
    }

    public function test_historical_reference_without_replacement_still_shows_missing_proof_fact(): void
    {
        // `missing_replacement_proof` is a data fact about the retired organ,
        // not an action flag. Even historical references to organs without
        // replacement will indicate missing replacement proof.
        $result = (new AtlasSelfConstructionSimplificationDocDriftDetector)->detect([
            'retired_organs' => [
                ['name' => 'AtlasLostOrgan', 'replacement' => ''],
            ],
            'references' => [
                ['file' => 'CHANGELOG.md', 'symbol' => 'AtlasLostOrgan', 'intentional_historical_note' => true],
            ],
        ]);

        $ref = $result['stale_refs'][0];
        $this->assertTrue($ref['missing_replacement_proof'],
            'missing_replacement_proof is a data fact: even historical references reflect it');
        $this->assertFalse($ref['required_update'], 'but required_update must be false for historical notes');
        $this->assertTrue($result['docs_synced'], 'historical references must not block docs_synced');
    }
}
