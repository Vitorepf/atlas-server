<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorSpecNoveltyGate;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainOriginatorSpecNoveltyGateTest extends TestCase
{
    private AtlasExternalBrainOriginatorSpecNoveltyGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new AtlasExternalBrainOriginatorSpecNoveltyGate;
    }

    public function test_template_farm_batch_of_renamed_wrappers_is_blocked_against_each_other(): void
    {
        $objective = 'Harden AtlasExternalBrainWidgetPolicy so shallow acceptance criteria cannot pass as durable evolution.';
        $acceptance = ['policy rejects shallow acceptance criteria batches'];

        $result = $this->gate->evaluate([
            'candidates' => [
                [
                    'class_name' => 'AtlasExternalBrainWidgetPolicy',
                    'objective' => $objective,
                    'acceptance' => $acceptance,
                    'allowed_files' => ['app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainWidgetPolicy.php'],
                ],
                [
                    'class_name' => 'AtlasExternalBrainWidgetPolicyRenamed',
                    'objective' => $objective,
                    'acceptance' => $acceptance,
                    'allowed_files' => ['app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainWidgetPolicyRenamed.php'],
                ],
            ],
        ]);

        $second = $result['results'][1];
        $this->assertFalse($second['safe_to_enqueue'], 'a renamed wrapper of a sibling candidate must be blocked');
        $this->assertContains('template_farm_sibling', array_column($second['duplicate_evidence'], 'reason'));
        $this->assertNotNull($second['minimal_rewrite_suggestion']);
    }

    public function test_coherent_strategic_chain_with_distinct_capability_is_allowed(): void
    {
        $objective = 'Harden AtlasExternalBrainWidgetPolicy so shallow acceptance criteria cannot pass as durable evolution.';
        $acceptance = ['policy rejects shallow acceptance criteria batches'];

        $result = $this->gate->evaluate([
            'candidates' => [
                [
                    'class_name' => 'AtlasExternalBrainWidgetPolicy',
                    'objective' => $objective,
                    'acceptance' => $acceptance,
                    'allowed_files' => ['app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainWidgetPolicy.php'],
                    'distinct_capability' => 'acceptance_depth_gate',
                ],
                [
                    'class_name' => 'AtlasExternalBrainWidgetPolicyRollout',
                    'objective' => $objective,
                    'acceptance' => $acceptance,
                    'allowed_files' => ['app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainWidgetPolicyRollout.php'],
                    'distinct_capability' => 'staged_rollout_gate',
                ],
            ],
        ]);

        $second = $result['results'][1];
        $this->assertTrue($second['safe_to_enqueue'], 'a chain task changing a distinct capability must not be blocked');
        $this->assertSame([], $second['duplicate_evidence']);
    }

    public function test_task_that_unlocks_a_later_task_is_allowed(): void
    {
        $objective = 'Harden AtlasExternalBrainWidgetPolicy so shallow acceptance criteria cannot pass as durable evolution.';

        $result = $this->gate->evaluate([
            'candidates' => [
                [
                    'class_name' => 'AtlasExternalBrainWidgetPolicy',
                    'objective' => $objective,
                    'allowed_files' => ['app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainWidgetPolicy.php'],
                ],
                [
                    'class_name' => 'AtlasExternalBrainWidgetPolicyNextStep',
                    'objective' => $objective,
                    'allowed_files' => ['app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainWidgetPolicyNextStep.php'],
                    'unlocks_task_id' => 'AtlasExternalBrainWidgetPolicy',
                ],
            ],
        ]);

        $second = $result['results'][1];
        $this->assertTrue($second['safe_to_enqueue'], 'a task that declares it unlocks a sibling must not be blocked');
    }

    public function test_semantic_overlap_against_pool_returns_minimal_rewrite_suggestion(): void
    {
        $result = $this->gate->evaluate([
            'candidates' => [[
                'class_name' => 'AtlasRenamedHealthService',
                'objective' => 'Strengthen AtlasHealthService so it reports the configured disk health and blocking reason inside every snapshot.',
                'acceptance' => ['snapshot includes disk health ok and reason fields'],
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasHealthService.php'],
            ]],
            'queued_targets' => [[
                'class_name' => 'AtlasHealthService',
                'objective' => 'Strengthen AtlasHealthService so it reports the configured disk health and blocking reason inside the snapshot.',
                'acceptance' => ['snapshot includes disk health ok and reason fields'],
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasHealthService.php'],
            ]],
        ]);

        $row = $result['results'][0];
        $this->assertFalse($row['safe_to_enqueue']);
        $this->assertStringContainsString('AtlasHealthService', (string) $row['minimal_rewrite_suggestion']);
    }

    public function test_class_name_collision_returns_minimal_rewrite_suggestion(): void
    {
        $result = $this->gate->evaluate([
            'candidates' => [[
                'class_name' => 'AtlasFooService',
                'objective' => 'Something unrelated.',
            ]],
            'existing_class_names' => ['AtlasFooService'],
        ]);

        $row = $result['results'][0];
        $this->assertFalse($row['safe_to_enqueue']);
        $this->assertNotNull($row['minimal_rewrite_suggestion']);
    }

    public function test_fully_novel_candidate_has_null_rewrite_suggestion(): void
    {
        $result = $this->gate->evaluate([
            'candidates' => [[
                'class_name' => 'AtlasBrandNewService',
                'objective' => 'Implement a completely unrelated capability nobody has touched before.',
            ]],
        ]);

        $row = $result['results'][0];
        $this->assertTrue($row['safe_to_enqueue']);
        $this->assertNull($row['minimal_rewrite_suggestion']);
    }

    // ── duplicate_family ──

    public function test_duplicate_family_set_when_same_family_collides(): void
    {
        $result = $this->gate->evaluate([
            'candidates' => [[
                'class_name' => 'AtlasRenamedHealthService',
                'task_family' => 'health_monitoring',
                'objective' => 'Strengthen AtlasHealthService so it reports the configured disk health and blocking reason inside every snapshot.',
                'acceptance' => ['snapshot includes disk health ok and reason fields'],
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasHealthService.php'],
            ]],
            'queued_targets' => [[
                'class_name' => 'AtlasHealthService',
                'task_family' => 'health_monitoring',
                'objective' => 'Strengthen AtlasHealthService so it reports the configured disk health and blocking reason inside the snapshot.',
                'acceptance' => ['snapshot includes disk health ok and reason fields'],
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasHealthService.php'],
            ]],
        ]);
        $row = $result['results'][0];
        $this->assertFalse($row['safe_to_enqueue']);
        $this->assertSame('health_monitoring', $row['duplicate_family']);
    }

    public function test_duplicate_family_null_when_different_families(): void
    {
        $result = $this->gate->evaluate([
            'candidates' => [[
                'class_name' => 'AtlasRenamedHealthService',
                'task_family' => 'health_monitoring',
                'objective' => 'Strengthen AtlasHealthService so it reports the configured disk health and blocking reason inside every snapshot.',
                'acceptance' => ['snapshot includes disk health ok and reason fields'],
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasHealthService.php'],
            ]],
            'queued_targets' => [[
                'class_name' => 'AtlasHealthService',
                'task_family' => 'network_monitoring',
                'objective' => 'Strengthen AtlasHealthService so it reports the configured disk health and blocking reason inside the snapshot.',
                'acceptance' => ['snapshot includes disk health ok and reason fields'],
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasHealthService.php'],
            ]],
        ]);
        $row = $result['results'][0];
        $this->assertFalse($row['safe_to_enqueue']);
        $this->assertNull($row['duplicate_family']);
    }

    public function test_duplicate_family_null_when_novel(): void
    {
        $result = $this->gate->evaluate([
            'candidates' => [[
                'class_name' => 'AtlasBrandNewService',
                'task_family' => 'brand_new',
                'objective' => 'Implement a completely unrelated capability nobody has touched before.',
            ]],
        ]);
        $row = $result['results'][0];
        $this->assertTrue($row['safe_to_enqueue']);
        $this->assertNull($row['duplicate_family']);
    }
}
