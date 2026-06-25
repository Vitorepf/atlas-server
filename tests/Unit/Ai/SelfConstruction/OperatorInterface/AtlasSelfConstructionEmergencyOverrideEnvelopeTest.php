<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\OperatorInterface;

use App\Services\Ai\SelfConstruction\OperatorInterface\AtlasSelfConstructionEmergencyOverrideEnvelope;
use Tests\TestCase;

final class AtlasSelfConstructionEmergencyOverrideEnvelopeTest extends TestCase
{
    public function test_each_allowed_emergency_action_is_accepted(): void
    {
        $svc = new AtlasSelfConstructionEmergencyOverrideEnvelope;
        foreach (AtlasSelfConstructionEmergencyOverrideEnvelope::ALLOWED_EMERGENCY_ACTIONS as $action) {
            $env = $svc->envelopeFor($action);
            $this->assertSame($action, $env['accepted_action'], "action {$action} must be accepted");
            $this->assertSame('emergency_safety', $env['action_kind']);
            $this->assertTrue($env['safety_only']);
            $this->assertArrayNotHasKey('rejected_action', $env);
        }
    }

    public function test_ordinary_progress_actions_are_rejected_with_named_reason(): void
    {
        $svc = new AtlasSelfConstructionEmergencyOverrideEnvelope;
        foreach (AtlasSelfConstructionEmergencyOverrideEnvelope::REJECTED_ORDINARY_ACTIONS as $action) {
            $env = $svc->envelopeFor($action);
            $this->assertSame($action, $env['rejected_action']);
            $this->assertSame('ordinary_progress_must_stay_atlas_native', $env['rejection_reason']);
        }
    }

    public function test_unknown_action_is_rejected_as_unknown(): void
    {
        $env = (new AtlasSelfConstructionEmergencyOverrideEnvelope)->envelopeFor('mystery_button');
        $this->assertSame('mystery_button', $env['rejected_action']);
        $this->assertSame('unknown_action_not_in_allowed_or_rejected_lists', $env['rejection_reason']);
    }

    public function test_surface_role_is_emergency_safety_only(): void
    {
        $env = (new AtlasSelfConstructionEmergencyOverrideEnvelope)->envelopeFor(AtlasSelfConstructionEmergencyOverrideEnvelope::ACTION_PAUSE);
        $this->assertSame(AtlasSelfConstructionEmergencyOverrideEnvelope::SURFACE_ROLE, $env['surface_role']);
        $this->assertStringContainsString('emergency', $env['surface_role']);
        $this->assertStringNotContainsString('progress', $env['surface_role']);
        $this->assertStringNotContainsString('control', $env['surface_role']);
    }

    public function test_safety_only_flag_is_always_true(): void
    {
        $svc = new AtlasSelfConstructionEmergencyOverrideEnvelope;
        foreach ([...AtlasSelfConstructionEmergencyOverrideEnvelope::ALLOWED_EMERGENCY_ACTIONS, ...AtlasSelfConstructionEmergencyOverrideEnvelope::REJECTED_ORDINARY_ACTIONS, 'unknown_x'] as $action) {
            $this->assertTrue($svc->envelopeFor($action)['safety_only']);
        }
    }
}
