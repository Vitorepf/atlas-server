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
}
