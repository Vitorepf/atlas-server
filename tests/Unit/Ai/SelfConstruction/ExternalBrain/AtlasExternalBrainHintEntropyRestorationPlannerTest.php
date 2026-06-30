<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainHintEntropyRestorationPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainHintEntropyRestorationPlannerTest extends TestCase
{
    private AtlasExternalBrainHintEntropyRestorationPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new AtlasExternalBrainHintEntropyRestorationPlanner;
    }

    private function base(array $overrides = []): array
    {
        return array_merge([
            'hint_entropy'             => 0.80,
            'recent_batch_families'    => ['frontier-harvest', 'compounding', 'pattern-design'],
            'starved_paths'            => ['adversarial-critique', 'simulation-twin'],
            'dominant_vein'            => 'frontier-harvest',
            'dominant_vein_compounding' => true,
            'batch_size'               => 10,
        ], $overrides);
    }

    // ── Schema / AC4 required keys ────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->planner->plan($this->base());

        foreach (['schema', 'target_entropy_floor', 'required_hint_families', 'banned_repeated_families', 'allowed_exceptions'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainHintEntropyRestorationPlanner::SCHEMA, $result['schema']);
    }

    // ── AC3: dominant compounding vein preserved as exception ─────────────────

    public function test_compounding_dominant_vein_is_in_allowed_exceptions(): void
    {
        $result = $this->planner->plan($this->base());

        $this->assertContains('frontier-harvest', $result['allowed_exceptions']);
    }

    public function test_non_compounding_dominant_vein_is_not_in_exceptions(): void
    {
        $result = $this->planner->plan($this->base(['dominant_vein_compounding' => false]));

        $this->assertNotContains('frontier-harvest', $result['allowed_exceptions']);
    }

    // ── AC2: stronger constraints at low entropy ───────────────────────────────

    public function test_critical_entropy_produces_high_target_floor(): void
    {
        $result = $this->planner->plan($this->base(['hint_entropy' => 0.10]));

        $this->assertGreaterThanOrEqual(0.60, $result['target_entropy_floor']);
    }

    public function test_moderate_entropy_produces_medium_target_floor(): void
    {
        $result = $this->planner->plan($this->base(['hint_entropy' => 0.45]));

        $this->assertGreaterThanOrEqual(0.40, $result['target_entropy_floor']);
        $this->assertLessThan(0.65, $result['target_entropy_floor']);
    }

    public function test_high_entropy_produces_minimum_floor(): void
    {
        $result = $this->planner->plan($this->base(['hint_entropy' => 0.90]));

        $this->assertSame(0.30, $result['target_entropy_floor']);
    }

    // ── AC2: concentrated family is banned at low entropy ────────────────────

    public function test_family_appearing_over_50_percent_of_recent_batches_is_banned_at_low_entropy(): void
    {
        $result = $this->planner->plan($this->base([
            'hint_entropy'          => 0.10,
            'recent_batch_families' => ['compounding', 'compounding', 'compounding', 'frontier-harvest'],
        ]));

        $this->assertContains('compounding', $result['banned_repeated_families']);
    }

    public function test_no_ban_at_high_entropy(): void
    {
        $result = $this->planner->plan($this->base([
            'hint_entropy'          => 0.85,
            'recent_batch_families' => ['compounding', 'compounding', 'compounding'],
        ]));

        $this->assertSame([], $result['banned_repeated_families']);
    }

    // ── AC2: starved paths appear in required families ────────────────────────

    public function test_starved_paths_appear_in_required_hint_families(): void
    {
        $result = $this->planner->plan($this->base([
            'starved_paths' => ['adversarial-critique', 'simulation-twin'],
        ]));

        $this->assertContains('adversarial-critique', $result['required_hint_families']);
        $this->assertContains('simulation-twin', $result['required_hint_families']);
    }

    public function test_banned_family_excluded_from_required_even_if_starved(): void
    {
        $result = $this->planner->plan($this->base([
            'hint_entropy'          => 0.10,
            'recent_batch_families' => ['adversarial-critique', 'adversarial-critique', 'adversarial-critique'],
            'starved_paths'         => ['adversarial-critique'],  // starved but also banned
        ]));

        $this->assertNotContains('adversarial-critique', $result['required_hint_families']);
    }

    // ── AC3: at least one orthogonal probe required when critical ─────────────

    public function test_critical_entropy_with_no_starved_paths_adds_orthogonal_probe_signal(): void
    {
        $result = $this->planner->plan($this->base([
            'hint_entropy'   => 0.05,
            'starved_paths'  => [],
            'dominant_vein'  => 'frontier-harvest',
        ]));

        // Must have at least one entry signalling orthogonal probe need
        $this->assertNotEmpty($result['required_hint_families']);
    }

    // ── Dominant vein not duplicated in required when it's in exceptions ──────

    public function test_dominant_compounding_vein_not_in_required_hint_families(): void
    {
        $result = $this->planner->plan($this->base([
            'starved_paths'            => ['frontier-harvest', 'simulation-twin'],
            'dominant_vein'            => 'frontier-harvest',
            'dominant_vein_compounding' => true,
        ]));

        $this->assertNotContains('frontier-harvest', $result['required_hint_families']);
        $this->assertContains('simulation-twin', $result['required_hint_families']);
    }

    // ── Empty inputs ──────────────────────────────────────────────────────────

    public function test_empty_recent_batches_produces_no_banned_families(): void
    {
        $result = $this->planner->plan($this->base([
            'recent_batch_families' => [],
            'hint_entropy'          => 0.05,
        ]));

        $this->assertSame([], $result['banned_repeated_families']);
    }

    public function test_target_entropy_floor_is_always_between_zero_and_one(): void
    {
        foreach ([0.0, 0.1, 0.3, 0.6, 1.0] as $entropy) {
            $result = $this->planner->plan($this->base(['hint_entropy' => $entropy]));
            $this->assertGreaterThanOrEqual(0.0, $result['target_entropy_floor']);
            $this->assertLessThanOrEqual(1.0, $result['target_entropy_floor']);
        }
    }
}
