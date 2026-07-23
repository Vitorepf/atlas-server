<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionOpportunityMiner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCompressionOpportunityMinerTest extends TestCase
{
    private function miner(): AtlasExternalBrainCompressionOpportunityMiner
    {
        return new AtlasExternalBrainCompressionOpportunityMiner;
    }

    public function test_ranked_candidate_case_orders_by_leverage_score_descending(): void
    {
        $r = $this->miner()->mine([
            'candidates' => [
                [
                    'id' => 'low',
                    'allowed_files' => ['app/Foo.php'],
                    'entropy_score' => 0.2,
                    'hotspot_score' => 0.2,
                    'duplicate_score' => 0.2,
                    'reachability_score' => 0.2,
                ],
                [
                    'id' => 'high',
                    'allowed_files' => ['app/Bar.php'],
                    'entropy_score' => 0.9,
                    'hotspot_score' => 0.9,
                    'duplicate_score' => 0.9,
                    'reachability_score' => 0.9,
                ],
            ],
        ]);

        $ids = array_column($r['candidates'], 'id');
        $this->assertSame(['high', 'low'], $ids);
        $this->assertGreaterThan($r['candidates'][1]['leverage_score'], $r['candidates'][0]['leverage_score']);
    }

    public function test_unimplementable_exclusion_case_no_allowed_files(): void
    {
        $r = $this->miner()->mine([
            'candidates' => [
                ['id' => 'ghost', 'entropy_score' => 0.9, 'hotspot_score' => 0.9],
            ],
        ]);

        $this->assertSame([], $r['candidates']);
        $this->assertCount(1, $r['excluded']);
        $this->assertSame('ghost', $r['excluded'][0]['id']);
        $this->assertSame('no_implementable_allowed_files', $r['excluded'][0]['reason']);
    }

    public function test_excludes_candidate_missing_required_proof_prework(): void
    {
        $r = $this->miner()->mine([
            'candidates' => [
                [
                    'id' => 'unproven',
                    'allowed_files' => ['app/Foo.php'],
                    'required_proof_prework' => ['behavior_lock', 'parity_proof'],
                    'proof_prework_done' => ['behavior_lock'],
                ],
            ],
        ]);

        $this->assertSame([], $r['candidates']);
        $this->assertSame('missing_required_proof_prework', $r['excluded'][0]['reason']);
    }

    public function test_candidate_with_all_required_prework_done_is_ranked(): void
    {
        $r = $this->miner()->mine([
            'candidates' => [
                [
                    'id' => 'proven',
                    'allowed_files' => ['app/Foo.php'],
                    'required_proof_prework' => ['behavior_lock'],
                    'proof_prework_done' => ['behavior_lock', 'parity_proof'],
                    'entropy_score' => 0.5,
                ],
            ],
        ]);

        $this->assertCount(1, $r['candidates']);
        $this->assertSame('proven', $r['candidates'][0]['id']);
    }

    public function test_proof_debt_reduces_leverage_score(): void
    {
        $r = $this->miner()->mine([
            'candidates' => [
                [
                    'id' => 'debt_free',
                    'allowed_files' => ['app/A.php'],
                    'entropy_score' => 0.8,
                    'hotspot_score' => 0.8,
                    'duplicate_score' => 0.8,
                    'reachability_score' => 0.8,
                    'proof_debt_score' => 0.0,
                ],
                [
                    'id' => 'debt_heavy',
                    'allowed_files' => ['app/B.php'],
                    'entropy_score' => 0.8,
                    'hotspot_score' => 0.8,
                    'duplicate_score' => 0.8,
                    'reachability_score' => 0.8,
                    'proof_debt_score' => 0.7,
                ],
            ],
        ]);

        $byId = [];
        foreach ($r['candidates'] as $c) {
            $byId[$c['id']] = $c;
        }

        $this->assertGreaterThan($byId['debt_heavy']['leverage_score'], $byId['debt_free']['leverage_score']);
    }

    public function test_leverage_score_never_negative(): void
    {
        $r = $this->miner()->mine([
            'candidates' => [
                [
                    'id' => 'all_debt',
                    'allowed_files' => ['app/A.php'],
                    'proof_debt_score' => 1.0,
                ],
            ],
        ]);

        $this->assertSame(0.0, $r['candidates'][0]['leverage_score']);
    }

    public function test_empty_candidates_produces_empty_output(): void
    {
        $r = $this->miner()->mine([]);

        $this->assertSame([], $r['candidates']);
        $this->assertSame([], $r['excluded']);
    }

    public function test_schema_present(): void
    {
        $r = $this->miner()->mine([]);

        $this->assertSame(AtlasExternalBrainCompressionOpportunityMiner::SCHEMA, $r['schema']);
    }
}
