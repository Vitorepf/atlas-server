<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Governance;

use App\Services\Ai\Governance\AtlasChangeClassTrustLadder;
use App\Services\Ai\Policy\PolicyCanon;
use Tests\TestCase;

class AtlasChangeClassTrustLadderTest extends TestCase
{
    private string $log = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = sys_get_temp_dir().'/atlas-ladder-'.bin2hex(random_bytes(4)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        parent::tearDown();
    }

    private function ladder(): AtlasChangeClassTrustLadder
    {
        $l = new AtlasChangeClassTrustLadder();
        $l->setLogPathForTesting($this->log);

        return $l;
    }

    public function test_defaults_to_max_friction_with_no_thresholds(): void
    {
        config(['atlas.ai.trust_ladder.thresholds' => []]); // default = disabled
        $l = $this->ladder();
        for ($i = 0; $i < 50; $i++) {
            $l->recordEvidence('kernel_numeric_safety', AtlasChangeClassTrustLadder::EVIDENCE_FROZEN_JUDGE_PASS);
        }

        // 50 clean evidences, but with NO operator thresholds it stays at SUGGEST.
        $this->assertSame(50, $l->cleanStreak('kernel_numeric_safety'));
        $this->assertSame(PolicyCanon::AUTONOMY_SUGGEST, $l->earnedAutonomy('kernel_numeric_safety'));
    }

    public function test_earns_autonomy_as_clean_evidence_passes_operator_thresholds(): void
    {
        config(['atlas.ai.trust_ladder.thresholds' => ['draft' => 3, 'execute_with_approval' => 5, 'autonomous' => 10]]);
        $l = $this->ladder();

        $this->assertSame(PolicyCanon::AUTONOMY_SUGGEST, $l->earnedAutonomy('c'));
        for ($i = 0; $i < 3; $i++) {
            $l->recordEvidence('c', AtlasChangeClassTrustLadder::EVIDENCE_FROZEN_JUDGE_PASS);
        }
        $this->assertSame(PolicyCanon::AUTONOMY_DRAFT, $l->earnedAutonomy('c'));
        for ($i = 0; $i < 2; $i++) {
            $l->recordEvidence('c', AtlasChangeClassTrustLadder::EVIDENCE_CLEAN_PROMOTION);
        }
        $this->assertSame(PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL, $l->earnedAutonomy('c'));
    }

    public function test_a_single_revert_resets_the_streak_immediately(): void
    {
        config(['atlas.ai.trust_ladder.thresholds' => ['draft' => 2]]);
        $l = $this->ladder();
        $l->recordEvidence('c', AtlasChangeClassTrustLadder::EVIDENCE_FROZEN_JUDGE_PASS);
        $l->recordEvidence('c', AtlasChangeClassTrustLadder::EVIDENCE_FROZEN_JUDGE_PASS);
        $this->assertSame(PolicyCanon::AUTONOMY_DRAFT, $l->earnedAutonomy('c'));

        $l->recordEvidence('c', AtlasChangeClassTrustLadder::EVIDENCE_REVERT);

        // Trust is asymmetric: one revert returns friction to maximum.
        $this->assertSame(0, $l->cleanStreak('c'));
        $this->assertSame(PolicyCanon::AUTONOMY_SUGGEST, $l->earnedAutonomy('c'));
    }
}
