<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskGraphReleaseGate;
use Tests\TestCase;

final class AtlasExternalBrainTaskGraphReleaseGateTest extends TestCase
{
    public function test_on_critical_path_candidate_is_allowed(): void
    {
        $result = (new AtlasExternalBrainTaskGraphReleaseGate)->evaluate([
            'candidates' => [['task_id' => 't1', 'on_critical_path' => true]],
            'critical_path_task_ids' => ['t1', 't2'],
        ]);

        $this->assertTrue($result['release_allowed']);
        $this->assertSame([], $result['blocked_task_ids']);
    }

    public function test_off_path_leaf_work_is_blocked_while_critical_path_unresolved(): void
    {
        $result = (new AtlasExternalBrainTaskGraphReleaseGate)->evaluate([
            'candidates' => [['task_id' => 'leaf-task', 'novelty_score' => 0.9]],
            'critical_path_task_ids' => ['root', 'child'],
        ]);

        $this->assertFalse($result['release_allowed']);
        $this->assertContains('leaf-task', $result['blocked_task_ids']);
        $this->assertStringContainsString('off_path_while_critical_path_unresolved', $result['release_reasons'][0]);
        $this->assertNotEmpty($result['required_rewrites']);
    }

    public function test_off_path_leaf_work_is_allowed_once_critical_path_is_clear(): void
    {
        $result = (new AtlasExternalBrainTaskGraphReleaseGate)->evaluate([
            'candidates' => [['task_id' => 'leaf-task', 'novelty_score' => 0.9]],
            'critical_path_task_ids' => [],
        ]);

        $this->assertTrue($result['release_allowed']);
        $this->assertFalse($result['critical_path_unresolved']);
    }

    public function test_roadmap_gap_coverage_admits_even_when_off_path(): void
    {
        $result = (new AtlasExternalBrainTaskGraphReleaseGate)->evaluate([
            'candidates' => [['task_id' => 'gap-task', 'covers_roadmap_gap' => true, 'novelty_score' => 0.9]],
            'critical_path_task_ids' => ['root'],
        ]);

        $this->assertTrue($result['release_allowed']);
    }

    public function test_repairs_blocker_admits_even_when_off_path(): void
    {
        $result = (new AtlasExternalBrainTaskGraphReleaseGate)->evaluate([
            'candidates' => [['task_id' => 'repair-task', 'repairs_blocker' => true, 'novelty_score' => 0.9]],
            'critical_path_task_ids' => ['root'],
        ]);

        $this->assertTrue($result['release_allowed']);
    }

    public function test_low_novelty_score_blocks_even_an_on_critical_path_candidate(): void
    {
        $result = (new AtlasExternalBrainTaskGraphReleaseGate)->evaluate([
            'candidates' => [['task_id' => 't1', 'on_critical_path' => true, 'novelty_score' => 0.1]],
            'critical_path_task_ids' => ['t1'],
        ]);

        $this->assertFalse($result['release_allowed']);
        $this->assertContains('t1', $result['blocked_task_ids']);
        $this->assertStringContainsString('low_novelty_duplicate_risk', $result['release_reasons'][0]);
    }

    public function test_mixed_batch_blocks_only_the_offending_task_id(): void
    {
        $result = (new AtlasExternalBrainTaskGraphReleaseGate)->evaluate([
            'candidates' => [
                ['task_id' => 'good-on-path', 'on_critical_path' => true, 'novelty_score' => 0.9],
                ['task_id' => 'bad-off-path', 'novelty_score' => 0.9],
            ],
            'critical_path_task_ids' => ['good-on-path'],
        ]);

        $this->assertFalse($result['release_allowed']);
        $this->assertSame(['bad-off-path'], $result['blocked_task_ids']);
    }

    public function test_no_candidates_is_trivially_allowed(): void
    {
        $result = (new AtlasExternalBrainTaskGraphReleaseGate)->evaluate(['candidates' => [], 'critical_path_task_ids' => ['x']]);

        $this->assertTrue($result['release_allowed']);
        $this->assertSame([], $result['blocked_task_ids']);
    }

    public function test_never_mutates_anything_just_reports_facts(): void
    {
        $result = (new AtlasExternalBrainTaskGraphReleaseGate)->evaluate([
            'candidates' => [['task_id' => 't1', 'on_critical_path' => true]],
            'critical_path_task_ids' => ['t1'],
            'backlog_depth' => 12,
        ]);

        $this->assertSame(12, $result['backlog_depth']);
        $this->assertArrayHasKey('release_allowed', $result);
        $this->assertArrayHasKey('blocked_task_ids', $result);
        $this->assertArrayHasKey('release_reasons', $result);
        $this->assertArrayHasKey('required_rewrites', $result);
    }
}
