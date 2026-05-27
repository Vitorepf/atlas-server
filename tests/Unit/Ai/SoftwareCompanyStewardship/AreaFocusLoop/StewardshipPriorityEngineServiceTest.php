<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipPriorityEngineService;
use Tests\TestCase;

final class StewardshipPriorityEngineServiceTest extends TestCase
{
    private function service(): StewardshipPriorityEngineService
    {
        return app(StewardshipPriorityEngineService::class);
    }

    public function test_prioritizes_maximum_advancement_and_robustness(): void
    {
        $report = $this->service()->rank([
            'candidates' => [
                [
                    'id' => 'docs_cleanup',
                    'title' => 'Small docs cleanup',
                    'kind' => 'doc',
                    'severity' => 'low',
                    'owner_candidate' => 'atlas_dev',
                    'confidence' => 0.9,
                    'affected_files' => ['docs/foo.md'],
                ],
                [
                    'id' => 'dev_forge_bug',
                    'title' => 'Fix Dev/Forge bridge bug',
                    'kind' => 'bug',
                    'severity' => 'high',
                    'owner_candidate' => 'dev_forge',
                    'confidence' => 0.82,
                    'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/Bridge.php', 'tests/Unit/BridgeTest.php'],
                    'has_tests' => true,
                ],
                [
                    'id' => 'risky_config',
                    'title' => 'Broad config rewrite',
                    'kind' => 'cleanup',
                    'severity' => 'medium',
                    'owner_candidate' => 'atlas_dev',
                    'confidence' => 0.6,
                    'affected_files' => ['config/atlas.php', 'routes/api.php', 'app/A.php', 'app/B.php', 'app/C.php', 'app/D.php'],
                ],
            ],
        ]);

        $this->assertSame(StewardshipPriorityEngineService::STATUS_READY, $report['status']);
        $this->assertSame('dev_forge_bug', $report['top_candidate']['candidate_id']);
        $this->assertSame('P0_maximum_advancement', $report['top_candidate']['priority_band']);
        $this->assertSame('high_priority_operator_review', $report['top_candidate']['autonomy_hint']);
        $this->assertGreaterThan($report['ranked_candidates'][2]['priority_score'], $report['ranked_candidates'][0]['priority_score']);
        $this->assertFalse($report['claim_policy']['merge_performed']);
    }

    public function test_docs_and_tests_can_be_auto_merge_candidates_after_ap769(): void
    {
        $report = $this->service()->rank([
            'candidates' => [[
                'id' => 'tests_hardening',
                'title' => 'Harden branch registry tests',
                'kind' => 'test',
                'severity' => 'medium',
                'owner_candidate' => 'atlas_dev',
                'confidence' => 0.95,
                'affected_files' => ['tests/Unit/FooTest.php', 'docs/ap/AP-771.md'],
                'has_tests' => true,
            ]],
        ]);

        $this->assertSame('auto_merge_candidate_after_ap769_validation', $report['top_candidate']['autonomy_hint']);
        $this->assertContains('eligible_for_ap769_safe_auto_merge_after_validation', $report['top_candidate']['recommended_execution_order']);
    }

    public function test_blocks_without_candidates(): void
    {
        $report = $this->service()->rank([]);

        $this->assertSame(StewardshipPriorityEngineService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('priority_candidates_required', $report['reason']);
    }
}
