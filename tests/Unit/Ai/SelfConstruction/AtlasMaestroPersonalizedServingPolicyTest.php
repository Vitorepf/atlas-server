<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\Personalization\AtlasMaestroPersonalizedServingPolicy;
use App\Services\Ai\SelfConstruction\Maestro\Personalization\AtlasMaestroWorkerPreferenceRegistry;
use Tests\TestCase;

class AtlasMaestroPersonalizedServingPolicyTest extends TestCase
{
    private function policy(array $declared): AtlasMaestroPersonalizedServingPolicy
    {
        return new AtlasMaestroPersonalizedServingPolicy(new AtlasMaestroWorkerPreferenceRegistry($declared));
    }

    public function test_decide_envelope_shape(): void
    {
        $verdict = $this->policy(['claude' => ['max_files' => 2, 'max_loc' => 200, 'tier' => 'small_scope']])
            ->decide('claude', ['task_packet_id' => 'pk-1', 'allowed_files' => ['a.php'], 'loc_estimate' => 50, 'tier_hint' => 'small_scope']);

        foreach (['advisory', 'shape_match', 'reasons', 'client_id', 'packet_id'] as $key) {
            self::assertArrayHasKey($key, $verdict);
        }
        self::assertTrue($verdict['advisory']);
        self::assertGreaterThanOrEqual(0.0, $verdict['shape_match']);
        self::assertLessThanOrEqual(1.0, $verdict['shape_match']);
    }

    public function test_packet_with_12_files_for_max_files_2_yields_below_half_match_but_still_advisory(): void
    {
        $verdict = $this->policy(['claude' => ['max_files' => 2, 'max_loc' => 200, 'tier' => 'small_scope']])
            ->decide('claude', ['task_packet_id' => 'big-pk', 'allowed_files' => range('a', 'l'), 'loc_estimate' => 2000, 'tier_hint' => 'multi_file_large']);

        self::assertTrue($verdict['advisory']);
        self::assertLessThan(0.5, $verdict['shape_match']);
    }

    public function test_packet_matching_tier_yields_high_match(): void
    {
        $verdict = $this->policy(['codex' => ['max_files' => 10, 'max_loc' => 2000, 'tier' => 'multi_file_large']])
            ->decide('codex', ['task_packet_id' => 'tier-pk', 'allowed_files' => ['x.php', 'y.php'], 'loc_estimate' => 800, 'tier_hint' => 'multi_file_large']);

        self::assertGreaterThanOrEqual(0.8, $verdict['shape_match']);
    }

    public function test_policy_class_is_not_referenced_from_atlas_task_serving_service(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/AtlasTaskServingService.php'));
        self::assertStringNotContainsString('AtlasMaestroPersonalizedServingPolicy', $src, 'advisory policy must NOT be wired into the serving path');
    }

    public function test_decide_never_throws_on_unknown_client_or_empty_packet(): void
    {
        $verdict = $this->policy([])->decide('unknown', []);

        self::assertTrue($verdict['advisory']);
        self::assertSame('', $verdict['packet_id']);
        self::assertNotEmpty($verdict['reasons']);
    }

    public function test_zero_score_still_returns_advisory_true(): void
    {
        $verdict = $this->policy(['claude' => ['max_files' => 1, 'max_loc' => 1, 'tier' => 'tiny']])
            ->decide('claude', ['task_packet_id' => 'mismatch', 'allowed_files' => range('a', 'z'), 'loc_estimate' => 100000, 'tier_hint' => 'huge']);

        self::assertTrue($verdict['advisory']);
        self::assertGreaterThanOrEqual(0.0, $verdict['shape_match']);
    }
}
