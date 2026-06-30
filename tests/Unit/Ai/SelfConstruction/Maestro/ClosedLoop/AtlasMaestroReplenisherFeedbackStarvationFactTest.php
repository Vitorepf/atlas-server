<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\ClosedLoop;

use App\Services\Ai\SelfConstruction\Maestro\ClosedLoop\AtlasMaestroReplenisherFeedback;
use Tests\TestCase;

final class AtlasMaestroReplenisherFeedbackStarvationFactTest extends TestCase
{
    /** @param array<string,array<string,array<string,mixed>>> $facts */
    private function feedback(array $facts): AtlasMaestroReplenisherFeedback
    {
        $miner = new class($facts)
        {
            /** @param array<string,mixed> $facts */
            public function __construct(private array $facts) {}

            public function mine(): array
            {
                return $this->facts;
            }
        };

        return new AtlasMaestroReplenisherFeedback($miner);
    }

    public function test_starvation_bucket_renders_fact_only_line_with_claimable_per_active_worker_and_support(): void
    {
        config(['atlas.maestro.closed_loop.feedback_enabled' => true]);

        $facts = [
            'queue_starvation' => [
                'worker_floor_breach' => [
                    'dimension' => 'queue_starvation',
                    'bucket' => 'worker_floor_breach',
                    'total' => 12,
                    'insufficient_support' => false,
                    'no_claimable_task_count' => 5,
                    'claimable_per_active_worker' => 0.83,
                    'worker_floor_breach_count' => 9,
                ],
            ],
        ];

        $block = $this->feedback($facts)->renderFactsBlock();

        $this->assertNotSame('', $block);
        $this->assertStringContainsString('claimable_per_active_worker=0.83', $block);
        $this->assertStringContainsString('no_claimable_task=5', $block);
        $this->assertStringContainsString('worker_floor_breaches=9', $block);
        $this->assertStringContainsString('support>=8', $block);

        foreach (['prefer', 'should', 'must', 'avoid', 'do not', 'recommend'] as $imperative) {
            $this->assertStringNotContainsStringIgnoringCase($imperative, $block, "no imperative '{$imperative}'");
        }
    }

    public function test_low_support_starvation_bucket_is_not_emitted(): void
    {
        config(['atlas.maestro.closed_loop.feedback_enabled' => true]);

        $facts = [
            'queue_starvation' => [
                'worker_floor_breach' => [
                    'dimension' => 'queue_starvation',
                    'bucket' => 'worker_floor_breach',
                    'total' => 3,
                    'insufficient_support' => true,
                    'no_claimable_task_count' => 1,
                    'claimable_per_active_worker' => 0.5,
                    'worker_floor_breach_count' => 2,
                ],
            ],
        ];

        $this->assertSame('', $this->feedback($facts)->renderFactsBlock());
    }

    public function test_existing_origin_kind_rendering_remains_intact_alongside_starvation_facts(): void
    {
        config(['atlas.maestro.closed_loop.feedback_enabled' => true]);

        $facts = [
            'origin_kind' => [
                'orphan' => ['dimension' => 'origin_kind', 'bucket' => 'orphan', 'delivered' => 14, 'total' => 22, 'insufficient_support' => false, 'delivery_rate' => 14 / 22],
            ],
            'queue_starvation' => [
                'worker_floor_breach' => [
                    'dimension' => 'queue_starvation',
                    'bucket' => 'worker_floor_breach',
                    'total' => 10,
                    'insufficient_support' => false,
                    'no_claimable_task_count' => 4,
                    'claimable_per_active_worker' => 0.4,
                    'worker_floor_breach_count' => 6,
                ],
            ],
        ];

        $block = $this->feedback($facts)->renderFactsBlock();

        $this->assertStringContainsString('origin_kind=orphan: 14 delivered / 22 total', $block);
        $this->assertStringContainsString('queue_starvation=worker_floor_breach', $block);
    }
}
