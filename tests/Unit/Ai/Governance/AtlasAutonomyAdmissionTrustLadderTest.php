<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Governance;

use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasChangeClassTrustLadder;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Policy\PolicyCanon;
use Tests\TestCase;

/**
 * The two pétreo sovereignty invariants of the Self-Construction trust ladder,
 * proven at the admission boundary:
 *   (1) an unproven change_class DEFAULTS to max friction (suggest);
 *   (2) a fully-earned class can NEVER exceed the risk-canon cap.
 */
class AtlasAutonomyAdmissionTrustLadderTest extends TestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'atlas.ai.trust_ladder.eligible_classes' => [],
            'atlas.ai.trust_ladder.blocked_class_patterns' => ['never_merge', 'kernel'],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $p) {
            @unlink($p);
        }
        parent::tearDown();
    }

    private function tmp(string $tag): string
    {
        $p = sys_get_temp_dir().'/atlas-adm-'.$tag.'-'.bin2hex(random_bytes(4)).'.jsonl';
        $this->tmp[] = $p;

        return $p;
    }

    private function ladder(): AtlasChangeClassTrustLadder
    {
        $l = new AtlasChangeClassTrustLadder();
        $l->setLogPathForTesting($this->tmp('ladder'));

        return $l;
    }

    private function admission(AtlasChangeClassTrustLadder $ladder): AtlasAutonomyAdmissionService
    {
        $kernel = new AtlasConstitutionalKernelService();
        $kernel->setViolationsLogPathForTesting($this->tmp('kernel'));
        $adm = new AtlasAutonomyAdmissionService($kernel);
        $adm->setTicketsLogPathForTesting($this->tmp('tickets'));
        $adm->setChangeClassLadder($ladder);

        return $adm;
    }

    public function test_unproven_change_class_defaults_to_max_friction(): void
    {
        config(['atlas.ai.trust_ladder.thresholds' => ['autonomous' => 3]]);
        $adm = $this->admission($this->ladder());

        // Low-risk change (canon cap = autonomous) but an UNPROVEN class.
        $env = $adm->admit([
            'change_kind' => 'config_tweak',
            'proposed_effect' => 'a benign tweak',
            'change_class' => 'kernel_numeric_safety',
            'requested_autonomy' => PolicyCanon::AUTONOMY_AUTONOMOUS,
            'risk_level' => PolicyCanon::RISK_LOW,
        ]);

        // Re-bound to max friction despite low risk + a high requested autonomy.
        $this->assertSame(PolicyCanon::AUTONOMY_SUGGEST, $env['max_autonomy_for_risk']);
        $this->assertTrue($env['requires_human_approval']);
    }

    public function test_fully_earned_class_can_never_exceed_the_risk_canon_cap(): void
    {
        config(['atlas.ai.trust_ladder.thresholds' => ['autonomous' => 1]]);
        $ladder = $this->ladder();
        $ladder->recordEvidence('risky_change', AtlasChangeClassTrustLadder::EVIDENCE_FROZEN_JUDGE_PASS, 'acceptance-hash-1');
        $this->assertSame(PolicyCanon::AUTONOMY_AUTONOMOUS, $ladder->earnedAutonomy('risky_change'));

        $adm = $this->admission($ladder);

        // HIGH-risk change (canon cap = draft). Even with the class fully earned to
        // autonomous, admission re-binds under the canon — never autonomous.
        $env = $adm->admit([
            'change_kind' => 'risky',
            'proposed_effect' => 'a change to a sensitive path',
            'change_class' => 'risky_change',
            'requested_autonomy' => PolicyCanon::AUTONOMY_AUTONOMOUS,
            'risk_level' => PolicyCanon::RISK_HIGH,
        ]);

        $this->assertNotSame(PolicyCanon::AUTONOMY_AUTONOMOUS, $env['max_autonomy_for_risk']);
        $this->assertContains($env['max_autonomy_for_risk'], [PolicyCanon::AUTONOMY_SUGGEST, PolicyCanon::AUTONOMY_DRAFT]);
    }

    public function test_ladder_wired_but_no_thresholds_stays_max_friction(): void
    {
        // Regression lock for the default-OFF posture: the ladder is WIRED, but with
        // NO operator thresholds at all, even a long clean streak stays at max friction.
        config(['atlas.ai.trust_ladder.thresholds' => []]);
        $ladder = $this->ladder();
        for ($i = 0; $i < 20; $i++) {
            $ladder->recordEvidence('kernel_numeric_safety', AtlasChangeClassTrustLadder::EVIDENCE_FROZEN_JUDGE_PASS, 'h-'.$i);
        }
        $this->assertSame(20, $ladder->cleanStreak('kernel_numeric_safety'));

        $env = $this->admission($ladder)->admit([
            'change_kind' => 'config_tweak',
            'proposed_effect' => 'a benign tweak',
            'change_class' => 'kernel_numeric_safety',
            'requested_autonomy' => PolicyCanon::AUTONOMY_AUTONOMOUS,
            'risk_level' => PolicyCanon::RISK_LOW,
        ]);

        // 20 clean evidences, low risk, autonomous requested — but no thresholds ⇒ suggest.
        $this->assertSame(PolicyCanon::AUTONOMY_SUGGEST, $env['max_autonomy_for_risk']);
        $this->assertTrue($env['requires_human_approval']);
    }
}
