<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Federation;

use App\Services\Ai\AutonomousEvolution\Federation\AtlasLoopAttributionInheritanceChannel;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AtlasLoopAttributionInheritanceChannelTest extends TestCase
{
    public function test_merging_two_cycle_priors_for_same_shape_sums_samples_and_pools_mean(): void
    {
        $result = (new AtlasLoopAttributionInheritanceChannel)->mergePriors([
            [
                'cycle_id' => 'cycle-b',
                'files' => ['app/B.php'],
                'global_prior' => [
                    ['shape_token' => 'shape-x', 'samples' => 3, 'mean_delta' => 10.0],
                ],
            ],
            [
                'cycle_id' => 'cycle-a',
                'files' => ['app/A.php'],
                'global_prior' => [
                    ['shape_token' => 'shape-x', 'samples' => 2, 'mean_delta' => 4.0],
                ],
            ],
        ]);

        $this->assertSame(AtlasLoopAttributionInheritanceChannel::SCHEMA_VERSION, $result['schema']);
        $this->assertSame([
            ['shape_token' => 'shape-x', 'samples' => 5, 'mean_delta' => 7.6],
        ], $result['global_prior']);
    }

    public function test_shape_present_in_only_one_cycle_still_appears_in_global_prior(): void
    {
        $result = (new AtlasLoopAttributionInheritanceChannel)->mergePriors([
            [
                'cycle_id' => 'cycle-a',
                'global_prior' => [
                    ['shape_token' => 'shape-only', 'samples' => 1, 'mean_delta' => 2.5],
                ],
            ],
        ]);

        $this->assertSame([
            ['shape_token' => 'shape-only', 'samples' => 1, 'mean_delta' => 2.5],
        ], $result['global_prior']);
        $this->assertSame([
            ['cycle_id' => 'cycle-a', 'shape_tokens' => ['shape-only']],
        ], $result['contributing_cycles']);
    }

    public function test_merge_priors_is_deterministic_and_order_independent(): void
    {
        $cycles = [
            [
                'cycle_id' => 'cycle-b',
                'files' => ['app/B.php'],
                'global_prior' => [
                    ['shape_token' => 'shape-y', 'samples' => 4, 'mean_delta' => 1.0],
                    ['shape_token' => 'shape-x', 'samples' => 2, 'mean_delta' => 5.0],
                ],
            ],
            [
                'cycle_id' => 'cycle-a',
                'files' => ['app/A.php'],
                'global_prior' => [
                    ['shape_token' => 'shape-x', 'samples' => 2, 'mean_delta' => 9.0],
                ],
            ],
        ];

        $service = new AtlasLoopAttributionInheritanceChannel;

        $this->assertSame(
            $service->mergePriors($cycles),
            $service->mergePriors(array_reverse($cycles)),
        );
    }

    public function test_overlapping_cycle_files_are_rejected_to_preserve_disjunction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AtlasLoopAttributionInheritanceChannel)->mergePriors([
            ['cycle_id' => 'cycle-a', 'files' => ['app/Shared.php'], 'global_prior' => [['shape_token' => 'a', 'samples' => 1, 'mean_delta' => 1]]],
            ['cycle_id' => 'cycle-b', 'files' => ['app/Shared.php'], 'global_prior' => [['shape_token' => 'b', 'samples' => 1, 'mean_delta' => 1]]],
        ]);
    }
}
