<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDocSyncDrafter;
use Tests\TestCase;

final class AtlasExternalBrainDocSyncDrafterTest extends TestCase
{
    private function drafter(): AtlasExternalBrainDocSyncDrafter
    {
        return new AtlasExternalBrainDocSyncDrafter;
    }

    private function cleanSnapshot(): array
    {
        return [
            'schema'                    => 'atlas.external_brain.control_plane_snapshot.v1',
            'maturity_band'             => 'advanced',
            'maturity_score'            => 0.75,
            'blockers'                  => [],
            'next_actions'              => [],
            'evidence_gaps'             => [],
            'next_missing_capabilities' => [],
            'dimensions'                => [
                'rubric_score'   => 0.75,
                'queue_status'   => 'healthy',
                'audit_verdict'  => 'pass',
                'ledger_present' => true,
                'stalled_yield'  => false,
            ],
        ];
    }

    private function blockedSnapshot(): array
    {
        return array_merge($this->cleanSnapshot(), [
            'maturity_band'  => 'emerging',
            'maturity_score' => 0.3,
            'blockers'       => [
                ['dimension' => 'ledger', 'reason' => 'missing_ledger', 'impact' => 'caps_maturity_at_emerging'],
            ],
            'evidence_gaps' => ['learning_ledger'],
            'next_actions'  => ['Initialize the learning ledger with at least one completed outcome cycle'],
            'dimensions'    => array_merge($this->cleanSnapshot()['dimensions'], [
                'ledger_present' => false,
            ]),
        ]);
    }

    public function test_schema_constant(): void
    {
        $this->assertSame(
            'atlas.external_brain.doc_sync_drafter.v1',
            AtlasExternalBrainDocSyncDrafter::SCHEMA,
        );
    }

    public function test_output_has_all_canonical_keys(): void
    {
        $result = $this->drafter()->draft($this->cleanSnapshot());

        foreach (['schema', 'readiness_claim', 'certification_blocked', 'refused_claims', 'sections', 'doc_deltas'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame(AtlasExternalBrainDocSyncDrafter::SCHEMA, $result['schema']);
    }

    public function test_sections_has_all_four_canonical_keys(): void
    {
        $result = $this->drafter()->draft($this->cleanSnapshot());

        foreach (['architecture', 'operation', 'limitations', 'evidence'] as $section) {
            $this->assertArrayHasKey($section, $result['sections']);
            $this->assertIsString($result['sections'][$section]);
            $this->assertNotEmpty($result['sections'][$section]);
        }
    }

    public function test_sections_has_all_seven_canonical_keys(): void
    {
        $result = $this->drafter()->draft($this->cleanSnapshot());

        foreach (['architecture', 'operation', 'limitations', 'evidence', 'queue_quality', 'knowledge_freshness', 'next_leverage'] as $section) {
            $this->assertArrayHasKey($section, $result['sections']);
            $this->assertIsString($result['sections'][$section]);
            $this->assertNotEmpty($result['sections'][$section]);
        }
    }

    public function test_maturity_change_produces_different_architecture_section(): void
    {
        $advanced      = $this->cleanSnapshot();
        $bootstrapping = array_merge($this->cleanSnapshot(), ['maturity_band' => 'bootstrapping', 'maturity_score' => 0.05]);

        $resultA = $this->drafter()->draft($advanced);
        $resultB = $this->drafter()->draft($bootstrapping);

        $this->assertNotSame($resultA['sections']['architecture'], $resultB['sections']['architecture']);
        $this->assertStringContainsString('advanced', $resultA['sections']['architecture']);
        $this->assertStringContainsString('bootstrapping', $resultB['sections']['architecture']);
    }

    public function test_maturity_change_produces_different_operation_section(): void
    {
        $healthy = $this->cleanSnapshot();
        $stalled = array_merge($this->cleanSnapshot(), [
            'maturity_band' => 'emerging',
            'dimensions'    => array_merge($this->cleanSnapshot()['dimensions'], ['queue_status' => 'stalled']),
        ]);

        $resultH = $this->drafter()->draft($healthy);
        $resultS = $this->drafter()->draft($stalled);

        $this->assertNotSame($resultH['sections']['operation'], $resultS['sections']['operation']);
        $this->assertStringContainsString('stalled', $resultS['sections']['operation']);
    }

    public function test_maturity_change_produces_different_limitations_section(): void
    {
        $clean   = $this->drafter()->draft($this->cleanSnapshot());
        $blocked = $this->drafter()->draft($this->blockedSnapshot());

        $this->assertNotSame($clean['sections']['limitations'], $blocked['sections']['limitations']);
        $this->assertStringContainsString('No active limitations', $clean['sections']['limitations']);
        $this->assertStringContainsString('CERTIFICATION BLOCKED', $blocked['sections']['limitations']);
    }

    public function test_maturity_change_produces_different_evidence_section(): void
    {
        $clean   = $this->drafter()->draft($this->cleanSnapshot());
        $blocked = $this->drafter()->draft($this->blockedSnapshot());

        $this->assertNotSame($clean['sections']['evidence'], $blocked['sections']['evidence']);
        $this->assertStringContainsString('learning_ledger', $blocked['sections']['evidence']);
        $this->assertStringContainsString('No outstanding evidence gaps', $clean['sections']['evidence']);
    }

    public function test_refuses_95_percent_final_when_certification_blockers_remain(): void
    {
        $result = $this->drafter()->draft($this->blockedSnapshot());

        $this->assertTrue($result['certification_blocked']);
        $this->assertNotEmpty($result['refused_claims']);
        $this->assertStringContainsString('95', $result['refused_claims'][0]);

        preg_match('/(\d+)%/', $result['readiness_claim'], $m);
        $this->assertLessThan(95, (int) ($m[1] ?? 100));
    }

    public function test_no_refused_claims_when_no_blockers(): void
    {
        $result = $this->drafter()->draft($this->cleanSnapshot());

        $this->assertFalse($result['certification_blocked']);
        $this->assertSame([], $result['refused_claims']);
    }

    public function test_lowers_maturity_impact_also_blocks_certification(): void
    {
        $snapshot = array_merge($this->cleanSnapshot(), [
            'maturity_band'  => 'functional',
            'maturity_score' => 0.55,
            'blockers'       => [
                ['dimension' => 'yield', 'reason' => 'stalled_yield', 'impact' => 'lowers_maturity'],
            ],
            'evidence_gaps' => ['yield_recovery_evidence'],
        ]);

        $result = $this->drafter()->draft($snapshot);

        $this->assertTrue($result['certification_blocked']);
        $this->assertNotEmpty($result['refused_claims']);
        preg_match('/(\d+)%/', $result['readiness_claim'], $m);
        $this->assertLessThan(95, (int) ($m[1] ?? 100));
    }

    public function test_caps_maturity_at_functional_also_blocks_certification(): void
    {
        $snapshot = array_merge($this->cleanSnapshot(), [
            'maturity_band'  => 'functional',
            'maturity_score' => 0.5,
            'blockers'       => [
                ['dimension' => 'batch_quality_audit', 'verdict' => 'reject', 'impact' => 'caps_maturity_at_functional'],
            ],
            'evidence_gaps' => ['batch_quality_proof'],
        ]);

        $result = $this->drafter()->draft($snapshot);

        $this->assertTrue($result['certification_blocked']);
        preg_match('/(\d+)%/', $result['readiness_claim'], $m);
        $this->assertLessThan(95, (int) ($m[1] ?? 100));
    }

    public function test_doc_deltas_always_include_maturity_status_delta(): void
    {
        $result = $this->drafter()->draft($this->cleanSnapshot());

        $docKeys  = array_column($result['doc_deltas'], 'doc_key');
        $sections = array_column($result['doc_deltas'], 'section');

        $this->assertContains('droid-wiki/systems/open-brain/index.md', $docKeys);
        $this->assertContains('maturity-status', $sections);
    }

    public function test_doc_deltas_include_blocker_delta_when_blockers_present(): void
    {
        $result = $this->drafter()->draft($this->blockedSnapshot());

        $sections = array_column($result['doc_deltas'], 'section');
        $this->assertContains('active-blockers', $sections);
    }

    public function test_doc_deltas_include_evidence_gap_delta_when_gaps_present(): void
    {
        $result = $this->drafter()->draft($this->blockedSnapshot());

        $docKeys = array_column($result['doc_deltas'], 'doc_key');
        $this->assertContains('droid-wiki/systems/open-brain/context-pack.md', $docKeys);
    }

    public function test_empty_snapshot_does_not_crash_and_returns_bootstrapping(): void
    {
        $result = $this->drafter()->draft([]);

        $this->assertSame(AtlasExternalBrainDocSyncDrafter::SCHEMA, $result['schema']);
        $this->assertStringContainsString('bootstrapping', $result['sections']['architecture']);
    }

    public function test_autonomous_readiness_reaches_90_with_no_refused_claims(): void
    {
        $snapshot = array_merge($this->cleanSnapshot(), ['maturity_band' => 'autonomous', 'maturity_score' => 0.90]);

        $result = $this->drafter()->draft($snapshot);

        $this->assertFalse($result['certification_blocked']);
        $this->assertSame([], $result['refused_claims']);
        preg_match('/(\d+)%/', $result['readiness_claim'], $m);
        $this->assertSame(90, (int) ($m[1] ?? 0));
    }

    public function test_stale_docs_caps_readiness_below_95_and_adds_refused_claim(): void
    {
        $snapshot = array_merge($this->cleanSnapshot(), ['stale_docs' => true]);

        $result = $this->drafter()->draft($snapshot);

        $this->assertNotEmpty($result['refused_claims']);
        $this->assertStringContainsString('stale_docs', implode(',', $result['refused_claims']));
        preg_match('/(\d+)%/', $result['readiness_claim'], $m);
        $this->assertLessThan(95, (int) ($m[1] ?? 100));
    }

    public function test_stale_code_index_caps_readiness_below_95(): void
    {
        $snapshot = array_merge($this->cleanSnapshot(), ['stale_code_index' => true]);

        $result = $this->drafter()->draft($snapshot);

        $claims = implode(',', $result['refused_claims']);
        $this->assertStringContainsString('stale_code_index', $claims);
        preg_match('/(\d+)%/', $result['readiness_claim'], $m);
        $this->assertLessThan(95, (int) ($m[1] ?? 100));
    }

    public function test_queue_quality_risks_cap_readiness_below_95(): void
    {
        $snapshot = array_merge($this->cleanSnapshot(), ['queue_quality_risks' => ['poison_family_detected']]);

        $result = $this->drafter()->draft($snapshot);

        $claims = implode(',', $result['refused_claims']);
        $this->assertStringContainsString('queue_quality_risks', $claims);
        preg_match('/(\d+)%/', $result['readiness_claim'], $m);
        $this->assertLessThan(95, (int) ($m[1] ?? 100));
    }

    public function test_queue_quality_section_reflects_risks(): void
    {
        $snapshot = array_merge($this->cleanSnapshot(), ['queue_quality_risks' => ['malformed_burst', 'give_back_spike']]);

        $result = $this->drafter()->draft($snapshot);

        $this->assertStringContainsString('malformed_burst', $result['sections']['queue_quality']);
        $this->assertStringContainsString('give_back_spike', $result['sections']['queue_quality']);
    }

    public function test_queue_quality_section_clean_when_no_risks(): void
    {
        $result = $this->drafter()->draft($this->cleanSnapshot());

        $this->assertStringContainsString('No queue quality risks detected', $result['sections']['queue_quality']);
    }

    public function test_knowledge_freshness_section_reflects_stale_flags(): void
    {
        $snapshot = array_merge($this->cleanSnapshot(), ['stale_docs' => true, 'stale_code_index' => true]);

        $result = $this->drafter()->draft($snapshot);

        $this->assertStringContainsString('STALE', $result['sections']['knowledge_freshness']);
    }

    public function test_knowledge_freshness_section_current_when_no_staleness(): void
    {
        $result = $this->drafter()->draft($this->cleanSnapshot());

        $this->assertStringContainsString('current', $result['sections']['knowledge_freshness']);
        $this->assertStringNotContainsString('STALE', $result['sections']['knowledge_freshness']);
    }

    public function test_doc_deltas_include_evidence_refs_and_update_priority(): void
    {
        $result = $this->drafter()->draft($this->cleanSnapshot());

        foreach ($result['doc_deltas'] as $delta) {
            $this->assertArrayHasKey('evidence_refs', $delta);
            $this->assertArrayHasKey('update_priority', $delta);
            $this->assertIsArray($delta['evidence_refs']);
            $this->assertIsString($delta['update_priority']);
        }
    }

    public function test_stale_docs_adds_knowledge_freshness_delta(): void
    {
        $snapshot = array_merge($this->cleanSnapshot(), ['stale_docs' => true]);

        $result = $this->drafter()->draft($snapshot);

        $sections = array_column($result['doc_deltas'], 'section');
        $this->assertContains('knowledge-freshness', $sections);
    }

    public function test_queue_quality_risks_adds_queue_quality_risks_delta(): void
    {
        $snapshot = array_merge($this->cleanSnapshot(), ['queue_quality_risks' => ['drain_overdue']]);

        $result = $this->drafter()->draft($snapshot);

        $sections = array_column($result['doc_deltas'], 'section');
        $this->assertContains('queue-quality-risks', $sections);
    }

    // ── post_task_knowledge_sync ─────────────────────────────────────────────────

    public function test_output_has_post_task_knowledge_sync_with_required_keys(): void
    {
        $result = $this->drafter()->draft($this->cleanSnapshot());

        $this->assertArrayHasKey('post_task_knowledge_sync', $result);
        $sync = $result['post_task_knowledge_sync'];
        foreach (['docs_to_update', 'code_index_refresh_required', 'evidence_refs', 'queue_quality_risks', 'next_leverage_deltas', 'refused_claims'] as $key) {
            $this->assertArrayHasKey($key, $sync, "post_task_knowledge_sync missing key: {$key}");
        }
    }

    public function test_docs_to_update_includes_maturity_status_doc_for_clean_snapshot(): void
    {
        $result = $this->drafter()->draft($this->cleanSnapshot());

        $this->assertContains('droid-wiki/systems/open-brain/index.md', $result['post_task_knowledge_sync']['docs_to_update']);
    }

    public function test_code_index_refresh_required_reflects_stale_code_index_flag(): void
    {
        $clean = $this->drafter()->draft($this->cleanSnapshot());
        $this->assertFalse($clean['post_task_knowledge_sync']['code_index_refresh_required']);

        $stale = $this->drafter()->draft(array_merge($this->cleanSnapshot(), ['stale_code_index' => true]));
        $this->assertTrue($stale['post_task_knowledge_sync']['code_index_refresh_required']);
    }

    public function test_evidence_refs_includes_evidence_gap_refs(): void
    {
        $result = $this->drafter()->draft($this->blockedSnapshot());

        $found = false;
        foreach ($result['post_task_knowledge_sync']['evidence_refs'] as $ref) {
            if (str_contains($ref, 'learning_ledger')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'evidence_refs must surface evidence gaps');
    }

    public function test_queue_quality_risks_passed_through_to_post_task_sync(): void
    {
        $snapshot = array_merge($this->cleanSnapshot(), ['queue_quality_risks' => ['drain_overdue', 'malformed_spike']]);

        $result = $this->drafter()->draft($snapshot);

        $this->assertSame(['drain_overdue', 'malformed_spike'], $result['post_task_knowledge_sync']['queue_quality_risks']);
    }

    public function test_next_leverage_deltas_derived_from_blockers_capabilities_and_actions(): void
    {
        $result = $this->drafter()->draft($this->blockedSnapshot());

        $deltas = $result['post_task_knowledge_sync']['next_leverage_deltas'];
        $hasUnblock = false;
        $hasOperatorAction = false;
        foreach ($deltas as $d) {
            if (str_starts_with($d, 'unblock:')) {
                $hasUnblock = true;
            }
            if (str_starts_with($d, 'operator_action:')) {
                $hasOperatorAction = true;
            }
        }
        $this->assertTrue($hasUnblock, 'next_leverage_deltas must surface blockers to unblock');
        $this->assertTrue($hasOperatorAction, 'next_leverage_deltas must surface pending operator actions');
    }

    public function test_refused_claims_matches_top_level_refused_claims(): void
    {
        $result = $this->drafter()->draft($this->blockedSnapshot());

        $this->assertSame($result['refused_claims'], $result['post_task_knowledge_sync']['refused_claims']);
        $this->assertNotEmpty($result['post_task_knowledge_sync']['refused_claims']);
    }

    // ── readiness_claim stays capped below 95% whenever any blocker is present ──

    public function test_readiness_claim_below_95_when_docs_stale(): void
    {
        $result = $this->drafter()->draft(array_merge($this->cleanSnapshot(), ['stale_docs' => true]));

        $readiness = (int) rtrim($result['readiness_claim'], '% final');
        $this->assertLessThan(95, $readiness);
    }

    public function test_readiness_claim_below_95_when_code_index_stale(): void
    {
        $result = $this->drafter()->draft(array_merge($this->cleanSnapshot(), ['stale_code_index' => true]));

        $readiness = (int) rtrim($result['readiness_claim'], '% final');
        $this->assertLessThan(95, $readiness);
    }

    public function test_readiness_claim_below_95_when_evidence_gaps_present(): void
    {
        $result = $this->drafter()->draft(array_merge($this->cleanSnapshot(), ['evidence_gaps' => ['some_gap']]));

        $readiness = (int) rtrim($result['readiness_claim'], '% final');
        $this->assertLessThan(95, $readiness);
    }

    public function test_readiness_claim_below_95_when_queue_quality_risks_present(): void
    {
        $result = $this->drafter()->draft(array_merge($this->cleanSnapshot(), ['queue_quality_risks' => ['x']]));

        $readiness = (int) rtrim($result['readiness_claim'], '% final');
        $this->assertLessThan(95, $readiness);
    }

    public function test_readiness_claim_below_95_when_certification_blocked(): void
    {
        $result = $this->drafter()->draft($this->blockedSnapshot());

        $readiness = (int) rtrim($result['readiness_claim'], '% final');
        $this->assertLessThan(95, $readiness);
    }

    public function test_post_task_knowledge_sync_is_deterministic(): void
    {
        $snapshot = $this->blockedSnapshot();
        $a = $this->drafter()->draft($snapshot);
        $b = $this->drafter()->draft($snapshot);

        $this->assertSame(
            json_encode($a['post_task_knowledge_sync'], JSON_UNESCAPED_SLASHES),
            json_encode($b['post_task_knowledge_sync'], JSON_UNESCAPED_SLASHES),
        );
    }
}
