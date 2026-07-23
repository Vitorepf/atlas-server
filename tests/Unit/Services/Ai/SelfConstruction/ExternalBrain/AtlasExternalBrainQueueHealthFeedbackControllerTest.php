<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainQueueHealthFeedbackController;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainQueueHealthFeedbackControllerTest extends TestCase
{
    private AtlasExternalBrainQueueHealthFeedbackController $controller;

    protected function setUp(): void
    {
        $this->controller = new AtlasExternalBrainQueueHealthFeedbackController;
    }

    private function cleanInput(): array
    {
        return [
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'queue_health' => ['healthy' => true, 'claimable_depth' => 5, 'active_leases' => 2],
            'malformed_sweep' => ['would_block_count' => 0],
            'queued_targets' => [],
        ];
    }

    // ── AC: clean health permits normal batch size ──

    public function test_clean_health_permits_normal_batch(): void
    {
        $result = $this->controller->control($this->cleanInput());

        $this->assertContains(AtlasExternalBrainQueueHealthFeedbackController::CONSTRAINT_NORMAL_BATCH, $result['constraints']);
        $this->assertSame(10, $result['max_batch_size']);
        $this->assertTrue($result['allows_expansion']);
    }

    // ── AC: unhealthy queue shrinks expansion ──

    public function test_unhealthy_queue_shrinks_expansion(): void
    {
        $input = $this->cleanInput();
        $input['queue_health']['healthy'] = false;
        $input['queue_health']['claimable_depth'] = 12;

        $result = $this->controller->control($input);

        $this->assertContains(AtlasExternalBrainQueueHealthFeedbackController::CONSTRAINT_SHRINK_BATCH, $result['constraints']);
        $this->assertSame(2, $result['max_batch_size']);
        $this->assertTrue($result['allows_expansion']);
    }

    // ── AC: malformed blockers force repair-first ──

    public function test_malformed_blockers_force_repair_first(): void
    {
        $input = $this->cleanInput();
        $input['malformed_sweep']['would_block_count'] = 3;

        $result = $this->controller->control($input);

        $this->assertContains(AtlasExternalBrainQueueHealthFeedbackController::CONSTRAINT_REPAIR_FIRST, $result['constraints']);
        $this->assertSame(0, $result['max_batch_size']);
        $this->assertFalse($result['allows_expansion']);
    }

    public function test_malformed_count_in_health_also_forces_repair_first(): void
    {
        $input = $this->cleanInput();
        $input['queue_health']['malformed_count'] = 1;

        $result = $this->controller->control($input);

        $this->assertContains(AtlasExternalBrainQueueHealthFeedbackController::CONSTRAINT_REPAIR_FIRST, $result['constraints']);
        $this->assertSame(0, $result['max_batch_size']);
    }

    // ── deep queue shrinks batch ──

    public function test_deep_queue_shrinks_batch(): void
    {
        $input = $this->cleanInput();
        $input['queue_health']['claimable_depth'] = 25;

        $result = $this->controller->control($input);

        $this->assertContains(AtlasExternalBrainQueueHealthFeedbackController::CONSTRAINT_SHRINK_BATCH, $result['constraints']);
        $this->assertSame(3, $result['max_batch_size']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->controller->control($this->cleanInput());

        $this->assertSame(AtlasExternalBrainQueueHealthFeedbackController::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('constraints', $result);
        $this->assertArrayHasKey('reasons', $result);
        $this->assertArrayHasKey('max_batch_size', $result);
        $this->assertArrayHasKey('allows_expansion', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = $this->cleanInput();
        $a = $this->controller->control($input);
        $b = $this->controller->control($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
