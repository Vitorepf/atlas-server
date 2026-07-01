<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Personalization;

use App\Services\Ai\SelfConstruction\Maestro\Personalization\AtlasMaestroPersonalizedServingPolicy;
use App\Services\Ai\SelfConstruction\Maestro\Personalization\AtlasMaestroWorkerPreferenceRegistry;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroPersonalizedServingPolicyTest extends TestCase
{
    private function policy(array $declared = []): AtlasMaestroPersonalizedServingPolicy
    {
        return new AtlasMaestroPersonalizedServingPolicy(new AtlasMaestroWorkerPreferenceRegistry($declared));
    }

    // ── Schema / envelope ────────────────────────────────────────────────────

    public function test_decide_envelope_includes_defer_fields(): void
    {
        $verdict = $this->policy()->decide('w', ['task_packet_id' => 'pk-1']);

        foreach (['advisory', 'shape_match', 'reasons', 'client_id', 'packet_id', 'deferred', 'defer_reason'] as $key) {
            $this->assertArrayHasKey($key, $verdict, "Missing key: {$key}");
        }
        $this->assertFalse($verdict['deferred']);
        $this->assertNull($verdict['defer_reason']);
    }

    // ── AC2: high-risk tasks require matching proven capability ───────────────

    public function test_high_risk_task_without_proven_capability_is_deferred_with_capability_mismatch(): void
    {
        $verdict = $this->policy()->decide('w', [
            'task_packet_id' => 'pk-risky',
            'task_family' => 'security_patch',
            'risk_level' => 'high',
        ]);

        $this->assertTrue($verdict['deferred']);
        $this->assertSame('capability_mismatch', $verdict['defer_reason']);
        $this->assertSame(0.0, $verdict['shape_match']);
        $this->assertContains('capability_mismatch', $verdict['reasons']);
    }

    public function test_high_risk_task_with_proven_capability_is_not_deferred(): void
    {
        $verdict = $this->policy()->decide('w', [
            'task_packet_id' => 'pk-risky',
            'task_family' => 'security_patch',
            'risk_level' => 'high',
        ], ['skill_scores' => ['security_patch' => 0.9]]);

        $this->assertFalse($verdict['deferred']);
        $this->assertNull($verdict['defer_reason']);
        $this->assertGreaterThan(0.0, $verdict['shape_match']);
    }

    public function test_high_risk_task_with_below_threshold_skill_is_still_deferred(): void
    {
        $verdict = $this->policy()->decide('w', [
            'task_packet_id' => 'pk-risky',
            'task_family' => 'security_patch',
            'risk_level' => 'high',
        ], ['skill_scores' => ['security_patch' => 0.3]]);

        $this->assertTrue($verdict['deferred']);
        $this->assertSame('capability_mismatch', $verdict['defer_reason']);
    }

    public function test_low_risk_task_without_capability_is_never_deferred(): void
    {
        $verdict = $this->policy()->decide('w', [
            'task_packet_id' => 'pk-safe',
            'task_family' => 'docs',
            'risk_level' => 'low',
        ]);

        $this->assertFalse($verdict['deferred']);
    }

    public function test_capability_mismatch_overrides_starvation_floor(): void
    {
        $verdict = $this->policy()->decide('w', [
            'task_packet_id' => 'pk-risky-starved',
            'task_family' => 'security_patch',
            'risk_level' => 'high',
            'starved_ticks' => 10,
        ]);

        $this->assertTrue($verdict['deferred']);
        $this->assertSame(0.0, $verdict['shape_match'], 'capability_mismatch must override the starvation floor for high-risk tasks');
    }

    // ── AC3: preferred families boost score only when poison history is low ──

    public function test_preferred_family_with_low_poison_history_boosts_score(): void
    {
        $prefs = ['max_files' => 5, 'max_loc' => 500, 'tier' => 'neutral'];
        $packet = ['task_packet_id' => 'pk-pref', 'task_family' => 'code_gen', 'allowed_files' => ['a.php'], 'loc_estimate' => 100];

        $withPreference = $this->policy(['w' => $prefs])->decide('w', $packet, [
            'preferred_families' => ['code_gen'],
            'family_poison_rate' => ['code_gen' => 0.0],
        ]);
        $withoutPreference = $this->policy(['w' => $prefs])->decide('w', $packet);

        $this->assertGreaterThan($withoutPreference['shape_match'], $withPreference['shape_match']);
        $this->assertContains('preferred_family_boost:code_gen', $withPreference['reasons']);
    }

    public function test_preferred_family_with_high_poison_history_does_not_boost_score(): void
    {
        $prefs = ['max_files' => 5, 'max_loc' => 500, 'tier' => 'neutral'];
        $packet = ['task_packet_id' => 'pk-pref', 'task_family' => 'code_gen', 'allowed_files' => ['a.php'], 'loc_estimate' => 100];

        $verdict = $this->policy(['w' => $prefs])->decide('w', $packet, [
            'preferred_families' => ['code_gen'],
            'family_poison_rate' => ['code_gen' => 0.9],
        ]);

        $this->assertNotContains('preferred_family_boost:code_gen', $verdict['reasons']);
        $this->assertStringContainsString('preferred_family_poison_blocked:code_gen', implode(',', $verdict['reasons']));
    }

    public function test_non_preferred_family_gets_no_boost_regardless_of_poison_history(): void
    {
        $prefs = ['max_files' => 5, 'max_loc' => 500, 'tier' => 'neutral'];
        $packet = ['task_packet_id' => 'pk-pref', 'task_family' => 'other_family', 'allowed_files' => ['a.php'], 'loc_estimate' => 100];

        $verdict = $this->policy(['w' => $prefs])->decide('w', $packet, [
            'preferred_families' => ['code_gen'],
            'family_poison_rate' => ['code_gen' => 0.0],
        ]);

        $this->assertNotContains('preferred_family_boost:other_family', $verdict['reasons']);
    }

    // ── AC4: high-value packets are not starved by narrow preferences ─────────

    public function test_high_value_packet_gets_floor_score_despite_narrow_preference_mismatch(): void
    {
        $verdict = $this->policy(['w' => ['max_files' => 1, 'max_loc' => 1, 'tier' => 'tiny']])->decide('w', [
            'task_packet_id' => 'pk-valuable',
            'allowed_files' => range('a', 'z'),
            'loc_estimate' => 100000,
            'tier_hint' => 'huge',
            'value_score' => 0.95,
        ]);

        $this->assertGreaterThanOrEqual(AtlasMaestroPersonalizedServingPolicy::HIGH_VALUE_FLOOR, $verdict['shape_match']);
        $this->assertStringContainsString('high_value_floor', implode(',', $verdict['reasons']));
    }

    public function test_low_value_packet_gets_no_floor_from_value_score(): void
    {
        $verdict = $this->policy(['w' => ['max_files' => 1, 'max_loc' => 1, 'tier' => 'tiny']])->decide('w', [
            'task_packet_id' => 'pk-cheap',
            'allowed_files' => range('a', 'z'),
            'loc_estimate' => 100000,
            'tier_hint' => 'huge',
            'value_score' => 0.1,
        ]);

        $this->assertStringNotContainsString('high_value_floor', implode(',', $verdict['reasons']));
    }

    public function test_high_value_floor_does_not_rescue_a_capability_mismatched_high_risk_packet(): void
    {
        $verdict = $this->policy()->decide('w', [
            'task_packet_id' => 'pk-risky-valuable',
            'task_family' => 'security_patch',
            'risk_level' => 'high',
            'value_score' => 0.99,
        ]);

        $this->assertTrue($verdict['deferred']);
        $this->assertSame(0.0, $verdict['shape_match'], 'safety gate must win over the value floor');
    }
}
