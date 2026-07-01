<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\StrategyCouncil;

use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilRoadmapCandidateFilter;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasStrategyCouncilRoadmapCandidateFilter: a valid candidate is kept; resolved is dropped;
 * duplicate duplicate_key is dropped (first-wins); missing evidence_path is dropped; proxy-only kind
 * is dropped; outside admitted owner_scope is dropped; lists are deterministically ordered.
 */
final class AtlasStrategyCouncilRoadmapCandidateFilterTest extends TestCase
{
    private function candidate(string $id, array $overrides = []): array
    {
        return array_merge([
            'candidate_id' => $id,
            'organ' => 'Task Fabric',
            'capability' => 'cap',
            'evidence_path' => 'docs/'.$id.'.md',
            'current_state' => 'absent',
            'target_state' => 'present',
            'owner_scope' => 'atlas-native',
            'duplicate_key' => 'organ/cap/'.$id,
            'resolved' => false,
            'kind' => 'structural',
        ], $overrides);
    }

    public function test_valid_candidate_is_kept(): void
    {
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter([$this->candidate('a')]);
        $this->assertCount(1, $r['kept']);
        $this->assertSame([], $r['dropped']);
    }

    public function test_resolved_candidate_is_dropped(): void
    {
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter([$this->candidate('a', ['resolved' => true])]);
        $this->assertSame('dropped:resolved', $r['dropped'][0]['drop_reason']);
    }

    public function test_duplicate_duplicate_key_first_wins_dropped(): void
    {
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter([
            $this->candidate('a', ['duplicate_key' => 'k1']),
            $this->candidate('b', ['duplicate_key' => 'k1']),
        ]);
        $this->assertCount(1, $r['kept']);
        $this->assertSame('a', $r['kept'][0]['candidate_id']);
        $this->assertSame('dropped:duplicate:k1', $r['dropped'][0]['drop_reason']);
    }

    public function test_missing_evidence_path_dropped(): void
    {
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter([$this->candidate('a', ['evidence_path' => ''])]);
        $this->assertSame('dropped:missing_evidence_path', $r['dropped'][0]['drop_reason']);
    }

    public function test_proxy_only_kind_dropped(): void
    {
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter([$this->candidate('a', ['kind' => 'cyclomatic_shrink'])]);
        $this->assertSame('dropped:proxy_only', $r['dropped'][0]['drop_reason']);
    }

    public function test_outside_admitted_scope_dropped(): void
    {
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter([$this->candidate('a', ['owner_scope' => 'external'])], ['atlas-native']);
        $this->assertSame('dropped:outside_scope:external', $r['dropped'][0]['drop_reason']);
    }

    public function test_high_leverage_candidate_with_autonomy_impact_metadata_is_accepted(): void
    {
        $c = $this->candidate('high-lev', [
            'leverage_score' => 9.2,
            'autonomy_impact' => 'high',
            'implementability' => 'achievable',
            'evidence_path' => 'docs/high-lev.md',
        ]);
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter([$c]);
        $this->assertCount(1, $r['kept']);
        $this->assertSame('high-lev', $r['kept'][0]['candidate_id']);
        $this->assertSame(9.2, $r['kept'][0]['leverage_score']);
    }

    public function test_ranked_drop_reason_resolved_takes_precedence_over_proxy_kind(): void
    {
        // resolved=true AND kind=proxy → must get dropped:resolved, not dropped:proxy_only
        $c = $this->candidate('dual-fail', ['resolved' => true, 'kind' => 'cyclomatic_shrink']);
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter([$c]);
        $this->assertCount(1, $r['dropped']);
        $this->assertSame('dropped:resolved', $r['dropped'][0]['drop_reason']);
    }

    public function test_kept_and_dropped_lists_are_deterministically_sorted(): void
    {
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter([
            $this->candidate('zeta'),
            $this->candidate('alpha'),
            $this->candidate('mu', ['resolved' => true]),
        ]);
        $this->assertSame(['alpha', 'zeta'], array_column($r['kept'], 'candidate_id'));
        $this->assertSame(['mu'], array_column($r['dropped'], 'candidate_id'));
    }

    // ── quarantine / dead-prereq / poison ─────────────────────────────────────

    public function test_quarantined_candidate_is_dropped(): void
    {
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter([
            $this->candidate('q1', ['quarantined' => true]),
        ]);
        $this->assertSame([], $r['kept']);
        $this->assertSame('dropped:quarantined', $r['dropped'][0]['drop_reason']);
    }

    public function test_blocked_by_dead_prereq_candidate_is_dropped(): void
    {
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter([
            $this->candidate('d1', ['blocked_by_dead_prereq' => true]),
        ]);
        $this->assertSame('dropped:blocked_by_dead_prereq', $r['dropped'][0]['drop_reason']);
    }

    public function test_poison_signature_candidate_is_dropped(): void
    {
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter([
            $this->candidate('p1', ['poison_signature' => true]),
        ]);
        $this->assertSame('dropped:poison_signature', $r['dropped'][0]['drop_reason']);
    }

    public function test_resolved_beats_quarantined_in_drop_reason(): void
    {
        // resolved is checked first — quarantined on the same candidate must not shadow it.
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter([
            $this->candidate('dual', ['resolved' => true, 'quarantined' => true]),
        ]);
        $this->assertSame('dropped:resolved', $r['dropped'][0]['drop_reason']);
    }

    public function test_candidate_with_live_status_claimable_and_evidence_is_kept(): void
    {
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter([
            $this->candidate('live-1', ['live_status' => 'claimable']),
        ]);
        $this->assertCount(1, $r['kept']);
        $this->assertSame('live-1', $r['kept'][0]['candidate_id']);
    }

    public function test_candidate_with_live_status_not_queued_and_evidence_is_kept(): void
    {
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter([
            $this->candidate('live-2', ['live_status' => 'not_queued']),
        ], ['atlas-native']);
        $this->assertCount(1, $r['kept']);
        $this->assertSame('live-2', $r['kept'][0]['candidate_id']);
    }

    public function test_stale_candidate_is_dropped(): void
    {
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter([
            $this->candidate('s1', ['stale' => true]),
        ]);
        $this->assertSame([], $r['kept']);
        $this->assertSame('dropped:stale', $r['dropped'][0]['drop_reason']);
    }

    public function test_stale_does_not_shadow_higher_priority_resolved_reason(): void
    {
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter([
            $this->candidate('dual-stale', ['resolved' => true, 'stale' => true]),
        ]);
        $this->assertSame('dropped:resolved', $r['dropped'][0]['drop_reason']);
    }

    public function test_new_drop_reasons_are_deterministically_sorted_with_existing(): void
    {
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter([
            $this->candidate('zz-poison',   ['poison_signature' => true]),
            $this->candidate('aa-quarantine', ['quarantined' => true]),
            $this->candidate('mm-dead',     ['blocked_by_dead_prereq' => true]),
            $this->candidate('kept-ok'),
        ]);
        $this->assertSame(['kept-ok'], array_column($r['kept'], 'candidate_id'));
        $this->assertSame(
            ['aa-quarantine', 'mm-dead', 'zz-poison'],
            array_column($r['dropped'], 'candidate_id'),
        );
    }

    // ── AC: admitted / rejected / review with owner_scope, autonomy_fit, evidence_path, worker_capacity, duplicate_reason ──

    public function test_valid_candidate_lands_in_admitted_with_facts(): void
    {
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter([$this->candidate('a')]);

        $this->assertCount(1, $r['admitted']);
        $entry = $r['admitted'][0];
        foreach (['candidate_id', 'owner_scope', 'autonomy_fit', 'evidence_path', 'worker_capacity', 'duplicate_reason'] as $key) {
            $this->assertArrayHasKey($key, $entry, "missing {$key}");
        }
        $this->assertSame('atlas-native', $entry['owner_scope']);
        $this->assertSame([], $r['rejected']);
        $this->assertSame([], $r['review']);
    }

    public function test_missing_owner_scope_lands_in_rejected(): void
    {
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter(
            [$this->candidate('a', ['owner_scope' => 'external'])],
            ['atlas-native']
        );

        $this->assertCount(1, $r['rejected']);
        $this->assertSame('a', $r['rejected'][0]['candidate_id']);
    }

    public function test_weak_evidence_lands_in_rejected(): void
    {
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter([$this->candidate('a', ['evidence_path' => ''])]);

        $this->assertCount(1, $r['rejected']);
        $this->assertSame('', $r['rejected'][0]['evidence_path']);
    }

    public function test_worker_floor_low_lands_in_review_with_insufficient_worker_capacity(): void
    {
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter(
            [$this->candidate('a', ['implementation_scope' => false])],
            [],
            true
        );

        $this->assertCount(1, $r['review']);
        $this->assertSame('insufficient', $r['review'][0]['worker_capacity']);
    }

    public function test_duplicate_candidate_lands_in_rejected_with_duplicate_reason(): void
    {
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter([
            $this->candidate('a', ['duplicate_key' => 'k1']),
            $this->candidate('b', ['duplicate_key' => 'k1']),
        ]);

        $this->assertCount(1, $r['admitted']);
        $this->assertCount(1, $r['rejected']);
        $this->assertSame('b', $r['rejected'][0]['candidate_id']);
        $this->assertNotNull($r['rejected'][0]['duplicate_reason']);
    }

    public function test_review_needed_candidate_lands_in_review_bucket(): void
    {
        $r = (new AtlasStrategyCouncilRoadmapCandidateFilter)->filter([
            $this->candidate('a', ['needs_review' => true]),
        ]);

        $this->assertCount(1, $r['review']);
        $this->assertSame('needs_review', $r['review'][0]['autonomy_fit']);
        $this->assertSame([], $r['admitted']);
    }
}
