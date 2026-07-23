<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainScaffoldVariantBandit;
use Tests\TestCase;

final class AtlasExternalBrainScaffoldVariantBanditTest extends TestCase
{
    private AtlasExternalBrainScaffoldVariantBandit $bandit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bandit = new AtlasExternalBrainScaffoldVariantBandit;
    }

    private function goodVariant(string $id, int $runs = 10, array $overrides = []): array
    {
        return array_merge([
            'variant_id'       => $id,
            'total_runs'       => $runs,
            'successes'        => (int) ($runs * 0.8),
            'give_backs'       => 0,
            'heldout_pass_rate' => 0.9,
            'green_commit_rate' => 0.9,
            'avg_value'        => 8.0,
            'avg_cost'         => 2.0,
            'proxy_leak_rate'  => 0.0,
        ], $overrides);
    }

    // ── AC2: proxy_leak_rate above ceiling + MIN_EVIDENCE runs → quarantined, not selected

    public function test_ac2_high_proxy_leak_with_enough_runs_is_quarantined(): void
    {
        $result = $this->bandit->select([
            'model_tier' => 'tier1',
            'task_class' => 'impl',
            'variant_outcomes' => [
                $this->goodVariant('clean-v1'),
                $this->goodVariant('leaky-v2', 10, ['proxy_leak_rate' => 0.50]),
            ],
        ]);

        $this->assertContains('leaky-v2', $result['quarantined_variants']);
        $this->assertNotSame('leaky-v2', $result['selected_variant']);
        $this->assertSame('clean-v1', $result['selected_variant']);
    }

    public function test_ac2_high_proxy_leak_below_min_evidence_is_not_quarantined(): void
    {
        $result = $this->bandit->select([
            'model_tier' => 'tier1',
            'task_class' => 'impl',
            'variant_outcomes' => [
                $this->goodVariant('leaky-new', 2, ['proxy_leak_rate' => 0.90]),
            ],
        ]);

        $this->assertNotContains('leaky-new', $result['quarantined_variants']);
    }

    public function test_ac2_exact_ceiling_is_not_quarantined(): void
    {
        $result = $this->bandit->select([
            'model_tier' => 'tier1',
            'task_class' => 'impl',
            'variant_outcomes' => [
                $this->goodVariant('boundary-v', 10, ['proxy_leak_rate' => AtlasExternalBrainScaffoldVariantBandit::PROXY_LEAK_CEILING]),
            ],
        ]);

        $this->assertNotContains('boundary-v', $result['quarantined_variants']);
        $this->assertSame('boundary-v', $result['selected_variant']);
    }

    public function test_ac2_all_quarantined_returns_null_selected(): void
    {
        $result = $this->bandit->select([
            'model_tier' => 'tier1',
            'task_class' => 'impl',
            'variant_outcomes' => [
                $this->goodVariant('leaky-a', 10, ['proxy_leak_rate' => 0.80]),
                $this->goodVariant('leaky-b', 8, ['proxy_leak_rate' => 0.60]),
            ],
        ]);

        $this->assertNull($result['selected_variant']);
        $this->assertCount(2, $result['quarantined_variants']);
    }

    // ── AC3: unsampled/under-sampled variants get exploration priority; evidence_counts always shown

    public function test_ac3_unsampled_variant_beats_sampled_low_performer(): void
    {
        $result = $this->bandit->select([
            'model_tier' => 'tier1',
            'task_class' => 'impl',
            'variant_outcomes' => [
                $this->goodVariant('low-scorer', 10, [
                    'successes' => 1, 'heldout_pass_rate' => 0.1, 'green_commit_rate' => 0.1, 'avg_value' => 1.0,
                ]),
                ['variant_id' => 'unsampled', 'total_runs' => 0, 'proxy_leak_rate' => 0.0],
            ],
        ]);

        $this->assertSame('unsampled', $result['selected_variant']);
    }

    public function test_ac3_under_sampled_variants_appear_in_exploration_variants(): void
    {
        // Perfect champion (UCB=1.0) beats any under-sampled variant so it is selected.
        // Under-sampled clean variants then appear in exploration_variants instead.
        $result = $this->bandit->select([
            'model_tier' => 'tier1',
            'task_class' => 'impl',
            'variant_outcomes' => [
                $this->goodVariant('champion', 20, [
                    'successes' => 20, 'heldout_pass_rate' => 1.0,
                    'green_commit_rate' => 1.0, 'avg_value' => 10.0, 'avg_cost' => 0.0,
                ]),
                $this->goodVariant('under-a', 3),
                $this->goodVariant('under-b', 1),
            ],
        ]);

        $this->assertSame('champion', $result['selected_variant']);
        $this->assertContains('under-a', $result['exploration_variants']);
        $this->assertContains('under-b', $result['exploration_variants']);
        $this->assertNotContains('champion', $result['exploration_variants']);
    }

    public function test_ac3_evidence_counts_includes_quarantined_variants(): void
    {
        $result = $this->bandit->select([
            'model_tier' => 'tier1',
            'task_class' => 'impl',
            'variant_outcomes' => [
                $this->goodVariant('clean-v1'),
                $this->goodVariant('leaky-v2', 7, ['proxy_leak_rate' => 0.80]),
            ],
        ]);

        $this->assertArrayHasKey('leaky-v2', $result['evidence_counts']);
        $this->assertSame(7, $result['evidence_counts']['leaky-v2']);
        $this->assertArrayHasKey('clean-v1', $result['evidence_counts']);
    }

    // ── AC4: rejected variants need MIN_EVIDENCE + low weighted_outcome; deterministic tie-break

    public function test_ac4_low_weighted_outcome_with_min_evidence_is_rejected(): void
    {
        $result = $this->bandit->select([
            'model_tier' => 'tier1',
            'task_class' => 'impl',
            'variant_outcomes' => [
                $this->goodVariant('good-v', 10),
                $this->goodVariant('bad-v', 10, [
                    'successes' => 1, 'heldout_pass_rate' => 0.05, 'green_commit_rate' => 0.05,
                    'avg_value' => 0.5, 'give_backs' => 8,
                ]),
            ],
        ]);

        $this->assertContains('bad-v', $result['rejected_variants']);
        $this->assertNotContains('good-v', $result['rejected_variants']);
    }

    public function test_ac4_low_performer_below_min_evidence_is_not_rejected(): void
    {
        $result = $this->bandit->select([
            'model_tier' => 'tier1',
            'task_class' => 'impl',
            'variant_outcomes' => [
                $this->goodVariant('good-v', 10),
                $this->goodVariant('bad-but-new', 3, [
                    'successes' => 0, 'heldout_pass_rate' => 0.0, 'green_commit_rate' => 0.0,
                ]),
            ],
        ]);

        $this->assertNotContains('bad-but-new', $result['rejected_variants']);
    }

    public function test_ac4_equal_ucb_tie_broken_alphabetically_by_variant_id(): void
    {
        // Two identical unsampled variants — tie must resolve to the alphabetically first id.
        $result = $this->bandit->select([
            'model_tier' => 'tier1',
            'task_class' => 'impl',
            'variant_outcomes' => [
                ['variant_id' => 'variant-z', 'total_runs' => 0, 'proxy_leak_rate' => 0.0],
                ['variant_id' => 'variant-a', 'total_runs' => 0, 'proxy_leak_rate' => 0.0],
            ],
        ]);

        $this->assertSame('variant-a', $result['selected_variant']);
    }

    public function test_ac4_same_input_produces_identical_output(): void
    {
        $input = [
            'model_tier' => 'tier1',
            'task_class' => 'impl',
            'variant_outcomes' => [
                $this->goodVariant('v-a', 10),
                $this->goodVariant('v-b', 3),
                $this->goodVariant('v-c', 0),
            ],
        ];

        $this->assertSame($this->bandit->select($input), $this->bandit->select($input));
    }
}
