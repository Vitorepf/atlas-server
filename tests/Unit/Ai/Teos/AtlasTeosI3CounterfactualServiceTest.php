<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Teos;

use App\Services\Ai\Teos\AtlasTeosI3CounterfactualService;
use Tests\TestCase;

class AtlasTeosI3CounterfactualServiceTest extends TestCase
{
    private string $tmpDir;

    private AtlasTeosI3CounterfactualService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/atlas_teos_i3_'.uniqid('', true);
        @mkdir($this->tmpDir, 0775, true);
        $this->svc = new AtlasTeosI3CounterfactualService;
        $this->svc->setBranchesLogPathForTesting($this->tmpDir.'/branches.jsonl');
        $this->svc->setRecommendationsLogPathForTesting($this->tmpDir.'/recommendations.jsonl');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function test_branch_envelope_shape(): void
    {
        $b = $this->svc->branch([
            'anchor_decision_id' => 'dec-1',
            'alternative' => ['decision_kind' => 'provider_swap', 'value' => 'codex'],
            'factual_outcome_score' => 0.5,
            'projected_outcome_score' => 0.85,
        ]);
        $this->assertSame(AtlasTeosI3CounterfactualService::BRANCH_SCHEMA, $b['schema_version']);
        $this->assertTrue($b['is_counterfactual']);
        $this->assertSame(0.35, $b['divergence_score']);
        $this->assertStringStartsWith('cf_', $b['branch_id']);
        $this->assertStringStartsWith('sha256:', $b['branch_hash']);
    }

    public function test_anchor_required(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->branch([
            'anchor_decision_id' => '',
            'alternative' => ['decision_kind' => 'provider_swap'],
        ]);
    }

    public function test_unknown_alternative_kind_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->branch([
            'anchor_decision_id' => 'dec-1',
            'alternative' => ['decision_kind' => 'magic_wand'],
        ]);
    }

    public function test_scores_are_clamped_to_unit_interval(): void
    {
        $b = $this->svc->branch([
            'anchor_decision_id' => 'dec-1',
            'alternative' => ['decision_kind' => 'replan'],
            'factual_outcome_score' => -0.5,
            'projected_outcome_score' => 2.0,
        ]);
        $this->assertSame(0.0, $b['factual_outcome_score']);
        $this->assertSame(1.0, $b['projected_outcome_score']);
        $this->assertSame(1.0, $b['divergence_score']);
    }

    public function test_depth_capped_at_max(): void
    {
        $b = $this->svc->branch([
            'anchor_decision_id' => 'dec-1',
            'alternative' => ['decision_kind' => 'replan'],
            'depth' => 50,
        ]);
        $this->assertSame(AtlasTeosI3CounterfactualService::MAX_BRANCH_DEPTH, $b['depth']);
        $this->assertCount(AtlasTeosI3CounterfactualService::MAX_BRANCH_DEPTH, $b['projected_path']);
    }

    public function test_recommend_with_no_branches_returns_low_confidence(): void
    {
        $r = $this->svc->recommendReplan(['scope' => []]);
        $this->assertSame('low', $r['confidence']);
        $this->assertFalse($r['actionable']);
        $this->assertNull($r['best_branch_id']);
    }

    public function test_recommend_picks_max_improvement(): void
    {
        $this->svc->branch([
            'anchor_decision_id' => 'd1',
            'alternative' => ['decision_kind' => 'provider_swap'],
            'factual_outcome_score' => 0.5,
            'projected_outcome_score' => 0.6,
            'scope' => ['mission_id' => 'm1'],
        ]);
        $this->svc->branch([
            'anchor_decision_id' => 'd2',
            'alternative' => ['decision_kind' => 'replan'],
            'factual_outcome_score' => 0.5,
            'projected_outcome_score' => 0.9,
            'scope' => ['mission_id' => 'm1'],
        ]);
        $r = $this->svc->recommendReplan(['scope' => ['mission_id' => 'm1']]);
        $this->assertGreaterThanOrEqual(0.3, $r['improvement_delta']);
        $this->assertSame('high', $r['confidence']);
        $this->assertTrue($r['actionable']);
        $this->assertTrue($r['requires_human_approval']);
    }

    public function test_unknown_trigger_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->recommendReplan(['trigger' => 'made_up']);
    }

    public function test_recommendation_hash_deterministic_per_scope_best(): void
    {
        $this->svc->branch([
            'anchor_decision_id' => 'd1',
            'alternative' => ['decision_kind' => 'provider_swap'],
            'factual_outcome_score' => 0.4,
            'projected_outcome_score' => 0.7,
            'scope' => ['mission_id' => 'mX'],
        ]);
        $a = $this->svc->recommendReplan(['scope' => ['mission_id' => 'mX']])['recommendation_hash'];
        $b = $this->svc->recommendReplan(['scope' => ['mission_id' => 'mX']])['recommendation_hash'];
        $this->assertSame($a, $b);
    }

    public function test_branches_are_persisted(): void
    {
        $this->svc->branch([
            'anchor_decision_id' => 'p1',
            'alternative' => ['decision_kind' => 'replan'],
        ]);
        $this->assertCount(1, $this->svc->listBranches());
    }
}
