<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Feedback\AtlasLoopGiveBackPatternMiner;
use Tests\TestCase;

final class AtlasLoopGiveBackPatternMinerTest extends TestCase
{
    public function test_mine_returns_deterministic_fact_only_patterns_from_reader_outcomes(): void
    {
        $miner = new AtlasLoopGiveBackPatternMiner($this->fakeReader());

        $result = $miner->mine();

        $this->assertSame(
            [
                'top_reasons',
                'give_back_rate_by_class',
                'top_reason_by_class',
                'worker_concentration',
            ],
            array_keys($result)
        );
        $this->assertSame(
            [
                ['reason' => 'scope_too_wide', 'count' => 2],
                ['reason' => 'tests_missing', 'count' => 2],
                ['reason' => 'missing_acceptance', 'count' => 1],
            ],
            $result['top_reasons']
        );
        $this->assertSame(
            [
                'acde' => 0.5,
                'loop' => 0.75,
            ],
            $result['give_back_rate_by_class']
        );
        $this->assertSame(
            [
                'acde' => 'tests_missing',
                'loop' => 'scope_too_wide',
            ],
            $result['top_reason_by_class']
        );
        $this->assertSame(
            [
                'alice' => 3,
                'bob' => 1,
                'carol' => 1,
            ],
            $result['worker_concentration']
        );
    }

    public function test_output_is_byte_stable_for_the_same_fixture(): void
    {
        $miner = new AtlasLoopGiveBackPatternMiner($this->fakeReader());

        $first = json_encode($miner->mine(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $second = json_encode($miner->mine(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $this->assertSame($first, $second);
    }

    public function test_output_never_emits_a_scalar_quality_score_field(): void
    {
        $result = (new AtlasLoopGiveBackPatternMiner($this->fakeReader()))->mine();

        $forbidden = ['quality_score', 'score', 'qualityScore', 'scalar_score'];
        foreach ($this->collectKeys($result) as $key) {
            $this->assertNotContains($key, $forbidden);
        }
    }

    private function fakeReader(): object
    {
        return new class
        {
            /**
             * @return list<array<string, mixed>>
             */
            public function recentOutcomes(int $limit = 200): array
            {
                return array_slice([
                    [
                        'packet_id' => 'loop-1',
                        'packet_class' => 'loop',
                        'outcome' => 'give_back',
                        'reason' => 'scope_too_wide',
                        'worker' => 'alice',
                        'recorded_at' => '2026-06-24T00:00:01+00:00',
                    ],
                    [
                        'packet_id' => 'loop-2',
                        'packet_class' => 'loop',
                        'outcome' => 'give_back',
                        'reason' => 'scope_too_wide',
                        'worker' => 'alice',
                        'recorded_at' => '2026-06-24T00:00:02+00:00',
                    ],
                    [
                        'packet_id' => 'loop-3',
                        'packet_class' => 'loop',
                        'outcome' => 'give_back',
                        'reason' => 'missing_acceptance',
                        'worker' => 'alice',
                        'recorded_at' => '2026-06-24T00:00:03+00:00',
                    ],
                    [
                        'packet_id' => 'loop-4',
                        'packet_class' => 'loop',
                        'outcome' => 'completed',
                        'reason' => 'committed_to_main',
                        'worker' => 'worker-z',
                        'recorded_at' => '2026-06-24T00:00:04+00:00',
                    ],
                    [
                        'packet_id' => 'acde-1',
                        'packet_class' => 'acde',
                        'outcome' => 'give_back',
                        'reason' => 'tests_missing',
                        'worker' => 'bob',
                        'recorded_at' => '2026-06-24T00:00:05+00:00',
                    ],
                    [
                        'packet_id' => 'acde-2',
                        'packet_class' => 'acde',
                        'outcome' => 'cancelled',
                        'reason' => 'operator_cancelled',
                        'worker' => '',
                        'recorded_at' => '2026-06-24T00:00:06+00:00',
                    ],
                    [
                        'packet_id' => 'acde-3',
                        'packet_class' => 'acde',
                        'outcome' => 'completed',
                        'reason' => 'completed_dry_run',
                        'worker' => 'worker-y',
                        'recorded_at' => '2026-06-24T00:00:07+00:00',
                    ],
                    [
                        'packet_id' => 'acde-4',
                        'packet_class' => 'acde',
                        'outcome' => 'give_back',
                        'reason' => 'tests_missing',
                        'worker' => 'carol',
                        'recorded_at' => '2026-06-24T00:00:08+00:00',
                    ],
                ], 0, $limit);
            }
        };
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     * @return list<string>
     */
    private function collectKeys(array $payload): array
    {
        $keys = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }

            if (is_array($value)) {
                array_push($keys, ...$this->collectKeys($value));
            }
        }

        return $keys;
    }
}
