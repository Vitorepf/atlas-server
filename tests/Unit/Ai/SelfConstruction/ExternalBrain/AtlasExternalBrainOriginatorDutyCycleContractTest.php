<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorDutyCycleContract;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainOriginatorDutyCycleContractTest extends TestCase
{
    public function test_healthy_queue_with_active_mission_is_not_terminal_and_not_stop_or_wait(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'mission_active' => true,
            'quota_remaining' => 10,
            'claimable_depth' => 28,
            'servable_now' => 28,
            'active_workers' => 4,
            'replenish_action' => 'wait',
        ]);

        self::assertFalse($result['terminal']);
        self::assertNotContains($result['next_action'], ['stop', 'wait']);
        self::assertSame(AtlasExternalBrainOriginatorDutyCycleContract::ACTION_CONSOLIDATE_WITH_NEXT_BATCH, $result['next_action']);
    }

    public function test_quota_complete_is_terminal_with_machine_readable_reason(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'mission_active' => true,
            'quota_complete' => true,
        ]);

        self::assertTrue($result['terminal']);
        self::assertSame(AtlasExternalBrainOriginatorDutyCycleContract::TERMINAL_QUOTA_COMPLETE, $result['terminal_reason']);
    }

    public function test_disabled_switch_is_terminal_with_machine_readable_reason(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'mission_active' => true,
            'brain_enabled' => false,
        ]);

        self::assertTrue($result['terminal']);
        self::assertSame(AtlasExternalBrainOriginatorDutyCycleContract::TERMINAL_DISABLED_SWITCH, $result['terminal_reason']);
    }

    public function test_no_value_after_exhaustive_escalation_is_terminal(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'mission_active' => true,
            'exhaustive_escalation_attempted' => true,
            'value_found_after_escalation' => false,
        ]);

        self::assertTrue($result['terminal']);
        self::assertSame(
            AtlasExternalBrainOriginatorDutyCycleContract::TERMINAL_NO_VALUE_AFTER_EXHAUSTIVE_ESCALATION,
            $result['terminal_reason'],
        );
    }

    public function test_local_surface_exhausted_with_quota_remaining_pivots_with_escalation_fronts(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'mission_active' => true,
            'quota_remaining' => 5,
            'local_surface_exhausted' => true,
        ]);

        self::assertFalse($result['terminal']);
        self::assertSame(AtlasExternalBrainOriginatorDutyCycleContract::ACTION_PIVOT_OR_RESEARCH, $result['next_action']);
        self::assertNotEmpty($result['required_escalation_fronts']);
    }

    public function test_starving_queue_keeps_originating(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'mission_active' => true,
            'quota_remaining' => 10,
            'claimable_depth' => 1,
            'servable_now' => 1,
            'active_workers' => 4,
            'replenish_action' => 'urgent',
        ]);

        self::assertFalse($result['terminal']);
        self::assertSame(AtlasExternalBrainOriginatorDutyCycleContract::ACTION_KEEP_ORIGINATING, $result['next_action']);
    }

    // ── AC2: deep healthy queue returns selective_originator_mode, not idle ────

    public function test_deep_queue_returns_selective_originator_mode_not_idle(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'mission_active' => true,
            'quota_remaining' => 10,
            'claimable_depth' => 50,
            'active_workers' => 5, // 10.0 per worker — deep, not merely comfortable
            'replenish_action' => 'wait',
        ]);

        self::assertFalse($result['terminal']);
        self::assertNotContains($result['next_action'], ['stop', 'wait', 'idle']);
        self::assertSame(AtlasExternalBrainOriginatorDutyCycleContract::ACTION_SELECTIVE_ORIGINATOR_MODE, $result['next_action']);
    }

    public function test_moderately_comfortable_queue_still_consolidates_not_selective(): void
    {
        // 8.0 per worker — comfortable, but below the deep threshold (10.0).
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'replenish_action' => 'wait',
            'claimable_depth' => 40,
            'active_workers' => 5,
        ]);

        self::assertSame(AtlasExternalBrainOriginatorDutyCycleContract::ACTION_CONSOLIDATE_WITH_NEXT_BATCH, $result['next_action']);
    }

    // ── AC3: exhausted local candidates require second_pass, research or simplification before any stop ──

    public function test_local_surface_exhausted_names_second_pass_and_simplification_as_additional_recovery_paths(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'local_surface_exhausted' => true,
        ]);

        self::assertFalse($result['terminal']);
        self::assertSame(AtlasExternalBrainOriginatorDutyCycleContract::ACTION_PIVOT_OR_RESEARCH, $result['next_action']);
        self::assertNotEmpty($result['required_escalation_fronts']);
        self::assertArrayHasKey('additional_recovery_paths', $result);
        self::assertContains('second_pass', $result['additional_recovery_paths']);
        self::assertContains('simplification', $result['additional_recovery_paths']);
    }

    public function test_additional_recovery_paths_omit_fronts_already_attempted(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'local_surface_exhausted' => true,
            'second_pass_attempted' => true,
        ]);

        self::assertNotContains('second_pass', $result['additional_recovery_paths']);
        self::assertContains('simplification', $result['additional_recovery_paths']);
    }

    public function test_additional_recovery_paths_empty_when_both_already_attempted(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'local_surface_exhausted' => true,
            'second_pass_attempted' => true,
            'simplification_attempted' => true,
        ]);

        self::assertSame([], $result['additional_recovery_paths']);
    }

    // ── AC4: true idle requires explicit evidence that all high-value paths are exhausted ──

    public function test_exhaustive_escalation_claim_without_front_evidence_refuses_terminal(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'exhaustive_escalation_attempted' => true,
            'value_found_after_escalation' => false,
            'escalation_fronts_attempted' => ['cross_domain_search'], // incomplete
        ]);

        self::assertFalse($result['terminal'], 'a bare claim without evidence for every front must not be honored as terminal');
        self::assertSame(AtlasExternalBrainOriginatorDutyCycleContract::ACTION_PIVOT_OR_RESEARCH, $result['next_action']);
        self::assertContains('deep_architecture_scan', $result['required_escalation_fronts']);
        self::assertContains('research_adaptation', $result['required_escalation_fronts']);
        self::assertNotContains('cross_domain_search', $result['required_escalation_fronts']);
    }

    public function test_exhaustive_escalation_with_full_front_evidence_is_terminal(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'exhaustive_escalation_attempted' => true,
            'value_found_after_escalation' => false,
            'escalation_fronts_attempted' => ['cross_domain_search', 'deep_architecture_scan', 'research_adaptation'],
        ]);

        self::assertTrue($result['terminal']);
        self::assertSame(
            AtlasExternalBrainOriginatorDutyCycleContract::TERMINAL_NO_VALUE_AFTER_EXHAUSTIVE_ESCALATION,
            $result['terminal_reason'],
        );
    }

    public function test_legacy_bool_only_claim_without_fronts_key_still_terminates(): void
    {
        // Backward compatibility: callers that never supply escalation_fronts_attempted at all
        // keep the original bool-trusting contract.
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'exhaustive_escalation_attempted' => true,
            'value_found_after_escalation' => false,
        ]);

        self::assertTrue($result['terminal']);
    }

    // AC: output includes selected_mode, mode_reason, forbidden_actions
    public function test_output_has_mode_fields(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([]);
        self::assertArrayHasKey('selected_mode', $result);
        self::assertArrayHasKey('mode_reason', $result);
        self::assertArrayHasKey('forbidden_actions', $result);
    }

    // AC: selects create mode when healthy queue and sufficient value density
    public function test_selects_create_mode_when_healthy(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'queue_health' => 'healthy',
            'value_density' => 0.7,
        ]);
        self::assertSame('create', $result['selected_mode']);
        self::assertSame([], $result['forbidden_actions']);
    }

    // AC: selects repair mode when low value density and degraded queue
    public function test_selects_repair_mode_when_degraded(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'queue_health' => 'degraded',
            'value_density' => 0.2,
        ]);
        self::assertSame('repair', $result['selected_mode']);
        self::assertContains('create', $result['forbidden_actions']);
    }

    // AC: selects consolidate mode when comfortable queue
    public function test_selects_consolidate_mode_when_comfortable(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'claimable_depth' => 8,
            'active_workers' => 2,
            'replenish_action' => 'wait',
        ]);
        self::assertSame('consolidate', $result['selected_mode']);
    }

    // AC: selects research mode when local surface exhausted
    public function test_selects_research_mode_when_exhausted(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'local_surface_exhausted' => true,
        ]);
        self::assertSame('research', $result['selected_mode']);
    }

    // AC: selects pause mode on terminal conditions
    public function test_selects_pause_mode_on_terminal(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'quota_complete' => true,
        ]);
        self::assertSame('pause', $result['selected_mode']);
        self::assertSame(['create', 'repair', 'consolidate', 'research'], $result['forbidden_actions']);
    }

    // AC: rejects create mode when value density is low and repair evidence stronger
    public function test_rejects_create_when_low_density_degraded(): void
    {
        $result = (new AtlasExternalBrainOriginatorDutyCycleContract)->evaluate([
            'queue_health' => 'degraded',
            'value_density' => 0.1,
        ]);
        self::assertNotSame('create', $result['selected_mode']);
        self::assertContains('create', $result['forbidden_actions']);
    }
}
