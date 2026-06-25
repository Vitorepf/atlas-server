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
}
