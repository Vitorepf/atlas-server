<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDocSyncDrafter;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainDocSyncDrafterTest extends TestCase
{
    private function drafter(): AtlasExternalBrainDocSyncDrafter
    {
        return new AtlasExternalBrainDocSyncDrafter;
    }

    private function cleanSnapshot(array $overrides = []): array
    {
        return array_merge([
            'maturity_band' => 'advanced',
            'maturity_score' => 80.0,
            'blockers' => [],
            'next_actions' => [],
            'evidence_gaps' => [],
            'next_missing_capabilities' => [],
            'dimensions' => ['ledger_present' => true, 'queue_status' => 'healthy', 'audit_verdict' => 'pass'],
            'stale_docs' => false,
            'stale_code_index' => false,
            'queue_quality_risks' => [],
        ], $overrides);
    }

    // ── AC2: all seven sections are always emitted ────────────────────────────

    public function test_all_seven_sections_are_always_emitted(): void
    {
        $result = $this->drafter()->draft($this->cleanSnapshot());

        foreach (['architecture', 'operation', 'limitations', 'evidence', 'queue_quality', 'knowledge_freshness', 'next_leverage'] as $section) {
            $this->assertArrayHasKey($section, $result['sections'], "section: {$section}");
            $this->assertNotEmpty($result['sections'][$section], "section: {$section}");
        }
    }

    // ── AC3: readiness_claim capped below 95% when blockers/gaps/staleness/risks present ──

    public function test_readiness_uncapped_when_snapshot_is_clean(): void
    {
        $result = $this->drafter()->draft($this->cleanSnapshot(['maturity_band' => 'autonomous']));

        $this->assertSame('90% final', $result['readiness_claim']);
        $this->assertFalse($result['certification_blocked']);
        $this->assertSame([], $result['refused_claims']);
    }

    public function test_readiness_capped_by_certification_blockers(): void
    {
        $result = $this->drafter()->draft($this->cleanSnapshot([
            'maturity_band' => 'autonomous',
            'blockers' => [['dimension' => 'evidence', 'impact' => 'caps_maturity_at_functional']],
        ]));

        $this->assertNotSame('90% final', $result['readiness_claim']);
        $this->assertTrue($result['certification_blocked']);
        $this->assertContains('95_percent_final_refused:certification_blockers_remain', $result['refused_claims']);
    }

    public function test_readiness_capped_by_evidence_gaps(): void
    {
        $result = $this->drafter()->draft($this->cleanSnapshot([
            'maturity_band' => 'autonomous',
            'evidence_gaps' => ['missing_runtime_proof'],
        ]));

        $this->assertContains('95_percent_final_refused:evidence_gaps_present', $result['refused_claims']);
    }

    public function test_readiness_capped_by_stale_docs(): void
    {
        $result = $this->drafter()->draft($this->cleanSnapshot(['maturity_band' => 'autonomous', 'stale_docs' => true]));

        $this->assertContains('95_percent_final_refused:stale_docs', $result['refused_claims']);
    }

    public function test_readiness_capped_by_stale_code_index(): void
    {
        $result = $this->drafter()->draft($this->cleanSnapshot(['maturity_band' => 'autonomous', 'stale_code_index' => true]));

        $this->assertContains('95_percent_final_refused:stale_code_index', $result['refused_claims']);
    }

    public function test_readiness_capped_by_queue_quality_risks(): void
    {
        $result = $this->drafter()->draft($this->cleanSnapshot([
            'maturity_band' => 'autonomous',
            'queue_quality_risks' => ['high_give_back_rate'],
        ]));

        $this->assertContains('95_percent_final_refused:queue_quality_risks_unresolved', $result['refused_claims']);
    }

    // ── AC4: doc_deltas fields + post_task_knowledge_sync smallest required follow-up ──

    public function test_doc_deltas_include_all_required_fields(): void
    {
        $result = $this->drafter()->draft($this->cleanSnapshot([
            'blockers' => [['dimension' => 'evidence', 'impact' => 'caps_maturity_at_functional']],
            'evidence_gaps' => ['gap-1'],
        ]));

        $this->assertNotEmpty($result['doc_deltas']);
        foreach ($result['doc_deltas'] as $delta) {
            foreach (['doc_key', 'section', 'proposed_delta', 'rationale', 'evidence_refs', 'update_priority'] as $field) {
                $this->assertArrayHasKey($field, $delta, "field: {$field}");
            }
        }
    }

    public function test_post_task_knowledge_sync_lists_smallest_required_follow_up(): void
    {
        $result = $this->drafter()->draft($this->cleanSnapshot([
            'blockers' => [['dimension' => 'evidence', 'impact' => 'caps_maturity_at_functional']],
            'evidence_gaps' => ['gap-1'],
            'stale_code_index' => true,
            'queue_quality_risks' => ['high_give_back_rate'],
            'next_actions' => ['review evidence'],
        ]));

        $sync = $result['post_task_knowledge_sync'];
        $this->assertNotEmpty($sync['docs_to_update']);
        $this->assertTrue($sync['code_index_refresh_required']);
        $this->assertContains('evidence_gap:gap-1', $sync['evidence_refs']);
        $this->assertSame(['high_give_back_rate'], $sync['queue_quality_risks']);
        $this->assertContains('unblock:evidence', $sync['next_leverage_deltas']);
        $this->assertNotEmpty($sync['refused_claims']);
    }
}
