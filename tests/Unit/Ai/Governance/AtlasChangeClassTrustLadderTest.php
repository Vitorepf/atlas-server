<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Governance;

use App\Services\Ai\Governance\AtlasChangeClassTrustLadder;
use App\Services\Ai\Policy\PolicyCanon;
use Tests\TestCase;

class AtlasChangeClassTrustLadderTest extends TestCase
{
    private const FJ = AtlasChangeClassTrustLadder::EVIDENCE_FROZEN_JUDGE_PASS;

    private const CP = AtlasChangeClassTrustLadder::EVIDENCE_CLEAN_PROMOTION;

    private const RV = AtlasChangeClassTrustLadder::EVIDENCE_REVERT;

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
            $l->recordEvidence('kernel_numeric_safety', self::FJ, 'fj-'.$i);
        }

        // 50 distinct clean evidences, but with NO operator thresholds it stays SUGGEST.
        $this->assertSame(50, $l->cleanStreak('kernel_numeric_safety'));
        $this->assertSame(PolicyCanon::AUTONOMY_SUGGEST, $l->earnedAutonomy('kernel_numeric_safety'));
    }

    public function test_earns_autonomy_as_clean_evidence_passes_operator_thresholds(): void
    {
        config(['atlas.ai.trust_ladder.thresholds' => ['draft' => 3, 'execute_with_approval' => 5, 'autonomous' => 10]]);
        $l = $this->ladder();

        $this->assertSame(PolicyCanon::AUTONOMY_SUGGEST, $l->earnedAutonomy('c'));
        for ($i = 0; $i < 3; $i++) {
            $l->recordEvidence('c', self::FJ, 'fj-'.$i);
        }
        $this->assertSame(PolicyCanon::AUTONOMY_DRAFT, $l->earnedAutonomy('c'));
        for ($i = 0; $i < 2; $i++) {
            $l->recordEvidence('c', self::CP, 'cp-'.$i);
        }
        $this->assertSame(PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL, $l->earnedAutonomy('c'));
    }

    public function test_a_single_revert_resets_the_streak_immediately(): void
    {
        config(['atlas.ai.trust_ladder.thresholds' => ['draft' => 2]]);
        $l = $this->ladder();
        $l->recordEvidence('c', self::FJ, 'r1');
        $l->recordEvidence('c', self::FJ, 'r2');
        $this->assertSame(PolicyCanon::AUTONOMY_DRAFT, $l->earnedAutonomy('c'));

        $l->recordEvidence('c', self::RV);

        // Trust is asymmetric: one revert returns friction to maximum.
        $this->assertSame(0, $l->cleanStreak('c'));
        $this->assertSame(PolicyCanon::AUTONOMY_SUGGEST, $l->earnedAutonomy('c'));
    }

    public function test_evidence_integrity_clean_requires_a_distinct_real_ref(): void
    {
        config(['atlas.ai.trust_ladder.thresholds' => ['draft' => 1]]);
        $l = $this->ladder();

        // Clean evidence with NO ref cannot accrue (must carry a re-checkable id).
        $l->recordEvidence('c', self::FJ);
        $this->assertSame(0, $l->cleanStreak('c'));

        // The same ref twice counts once — re-running the same proof cannot inflate.
        $l->recordEvidence('c', self::FJ, 'acceptance-hash-1');
        $l->recordEvidence('c', self::FJ, 'acceptance-hash-1');
        $this->assertSame(1, $l->cleanStreak('c'));

        // A fabricated evidence kind is ignored (closed vocabulary, service boundary).
        $l->recordEvidence('c', 'made_up_kind', 'x');
        $this->assertSame(1, $l->cleanStreak('c'));
    }
}
