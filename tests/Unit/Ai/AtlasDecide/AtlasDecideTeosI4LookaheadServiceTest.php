<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use App\Services\Ai\AtlasDecide\AtlasDecideTeosI4LookaheadService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Teos\AtlasTeosI3CounterfactualService;
use App\Services\Ai\Teos\AtlasTeosI4CounterfactualTreeService;
use Tests\TestCase;

final class StubAdmlForLookahead extends AtlasDecideMetaLearningService
{
    public function __construct()
    {
    }

    public function recommend(array $scope): array
    {
        return [
            'schema_version' => self::RECOMMENDATION_SCHEMA,
            'scope' => $scope,
            'signal' => 'ok',
            'recommended_provider' => 'claude_code',
            'recommended_model' => 'opus-4.7',
            'runner_up_provider' => 'codex_cli',
            'runner_up_model' => 'gpt-5-codex',
            'actionable' => true,
        ];
    }
}

class AtlasDecideTeosI4LookaheadServiceTest extends TestCase
{
    private string $i3Branches;
    private string $i3Recos;
    private string $i4Trees;
    private string $kernelLog;
    private string $admissionLog;
    private string $lookaheadsLog;
    private AtlasDecideTeosI4LookaheadService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $u = uniqid('', true);
        $this->i3Branches = sys_get_temp_dir()."/atlas_la_i3b_{$u}.jsonl";
        $this->i3Recos = sys_get_temp_dir()."/atlas_la_i3r_{$u}.jsonl";
        $this->i4Trees = sys_get_temp_dir()."/atlas_la_i4t_{$u}.jsonl";
        $this->kernelLog = sys_get_temp_dir()."/atlas_la_kernel_{$u}.jsonl";
        $this->admissionLog = sys_get_temp_dir()."/atlas_la_admission_{$u}.jsonl";
        $this->lookaheadsLog = sys_get_temp_dir()."/atlas_la_lookaheads_{$u}.jsonl";

        $i3 = new AtlasTeosI3CounterfactualService;
        $i3->setBranchesLogPathForTesting($this->i3Branches);
        $i3->setRecommendationsLogPathForTesting($this->i3Recos);

        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting($this->kernelLog);
        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting($this->admissionLog);

        $teosI4 = new AtlasTeosI4CounterfactualTreeService($i3, $kernel, $admission);
        $teosI4->setTreesLogPathForTesting($this->i4Trees);

        $adml = new StubAdmlForLookahead;

        $this->svc = new AtlasDecideTeosI4LookaheadService($adml, $teosI4);
        $this->svc->setLookaheadsLogPathForTesting($this->lookaheadsLog);
    }

    protected function tearDown(): void
    {
        foreach ([$this->i3Branches, $this->i3Recos, $this->i4Trees, $this->kernelLog, $this->admissionLog, $this->lookaheadsLog] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function test_lookahead_envelope_shape(): void
    {
        $env = $this->svc->lookahead([
            'task_category' => 'code_generation',
            'role' => 'primary',
            'privacy_class' => 'public',
        ]);
        $this->assertSame(AtlasDecideTeosI4LookaheadService::SCHEMA_VERSION, $env['schema_version']);
        $this->assertStringStartsWith('sha256:', $env['envelope_hash']);
    }

    public function test_lookahead_uses_adml_recommendation(): void
    {
        $env = $this->svc->lookahead([
            'task_category' => 'code_generation',
            'role' => 'primary',
        ]);
        $this->assertSame('claude_code', $env['adml_recommendation']['recommended_provider']);
        $this->assertSame('codex_cli', $env['adml_recommendation']['runner_up_provider']);
        $this->assertTrue($env['adml_recommendation']['actionable']);
    }

    public function test_lookahead_expands_tree(): void
    {
        $env = $this->svc->lookahead([
            'task_category' => 'code_generation',
            'role' => 'primary',
        ]);
        $this->assertNotNull($env['tree']);
        $this->assertGreaterThan(0, $env['tree']['node_count']);
        $this->assertNotEmpty($env['tree']['best_path']);
    }

    public function test_claim_policy_safe(): void
    {
        $env = $this->svc->lookahead(['task_category' => 'x', 'role' => 'y']);
        $this->assertFalse($env['claim_policy']['rivals_claim_allowed']);
        $this->assertFalse($env['claim_policy']['benchmark_claim_allowed']);
        $this->assertFalse($env['claim_policy']['superiority_claim_allowed']);
    }

    public function test_invalid_scope_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->lookahead(['task_category' => '', 'role' => '']);
    }

    public function test_persisted_append_only(): void
    {
        $this->svc->lookahead(['task_category' => 'a', 'role' => 'b']);
        $this->svc->lookahead(['task_category' => 'c', 'role' => 'd']);
        $this->assertCount(2, $this->svc->listLookaheads());
    }
}
