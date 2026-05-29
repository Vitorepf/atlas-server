<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipAutonomyEnvelope;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\TestCase;

final class StewardshipAutonomyEnvelopeTest extends TestCase
{
    public function test_cross_system_envelope_must_not_target_main(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StewardshipAutonomyEnvelope::fromArray([
            'area_id' => 'agentic_engineering_os',
            'merge_target' => 'main',
            'admit_cross_system' => true,
        ]);
    }

    public function test_defaults_route_to_integration_lane_and_drop_forge_owner(): void
    {
        $env = StewardshipAutonomyEnvelope::fromArray([
            'area_id' => 'agentic_engineering_os',
            'admit_cross_system' => true,
            'allowed_owners' => ['atlas_dev', 'forge'],
        ]);

        $this->assertTrue($env->routesToIntegrationLane());
        // Forge is plan-only/fixture today; it is never an autonomous owner.
        $this->assertSame(['atlas_dev'], $env->allowedOwners);
        $this->assertTrue($env->admitsCrossSystem('atlas_dev', 'high'));
        $this->assertFalse($env->admitsCrossSystem('forge', 'high'));
    }

    public function test_risk_ceiling_gates_admission(): void
    {
        $env = StewardshipAutonomyEnvelope::fromArray([
            'area_id' => 'agentic_engineering_os',
            'admit_cross_system' => true,
            'risk_ceiling' => 'medium',
        ]);

        $this->assertTrue($env->admitsCrossSystem('atlas_dev', 'medium'));
        $this->assertFalse($env->admitsCrossSystem('atlas_dev', 'high'));
    }

    public function test_from_input_or_null_returns_null_without_envelope(): void
    {
        $this->assertNull(StewardshipAutonomyEnvelope::fromInputOrNull([]));
        $this->assertNull(StewardshipAutonomyEnvelope::fromInputOrNull(['autonomy_envelope' => []]));
        $this->assertInstanceOf(
            StewardshipAutonomyEnvelope::class,
            StewardshipAutonomyEnvelope::fromInputOrNull(['autonomy_envelope' => ['area_id' => 'x', 'admit_cross_system' => true]]),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function crossSystemAtlasDevFinding(array $overrides = []): array
    {
        // owner=atlas_dev, high severity, real cross-system source (outside the
        // factory-scoped boundary), passes the autonomous-execution gate.
        return array_merge([
            'finding_id' => 'afdf_test_cross',
            'title' => 'Wire cognitive immune into autonomous engineering decisions',
            'origin' => 'structural_ap717',
            'origin_type' => 'dev_forge_routing_gap',
            'owner' => 'atlas_dev',
            'severity' => 'high',
            'auto_execution_allowed' => true,
            'operator_review_required' => false,
            'affected_files' => ['app/Services/Ai/Cognition/AtlasCognitiveFunctionDecomposerService.php'],
        ], $overrides);
    }

    private function rejectionReason(array $finding, array $allowedFiles, ?StewardshipAutonomyEnvelope $envelope): string
    {
        $svc = app(AutonomousEvolutionSessionService::class);
        $method = new ReflectionMethod($svc, 'candidateRejectionReason');

        return (string) $method->invoke(
            $svc,
            $finding,
            $allowedFiles,
            [],            // reviewLocked
            'factory_max', // scopeProfile
            'agentic_engineering_os',
            'dev_forge',
            [],            // forgeInputs
            false,         // maintenanceBudgetExhausted
            [],            // terminalLocked
            $envelope,
        );
    }

    public function test_factory_max_rejects_cross_system_without_envelope(): void
    {
        $finding = $this->crossSystemAtlasDevFinding();
        $allowed = ['app/Services/Ai/Cognition/AtlasCognitiveFunctionDecomposerService.php'];

        $reason = $this->rejectionReason($finding, $allowed, null);

        // Byte-identical factory_max behavior: a cross-system finding is rejected.
        $this->assertNotSame('', $reason);
    }

    public function test_envelope_does_not_admit_unpacketized_cross_system_parent(): void
    {
        $finding = $this->crossSystemAtlasDevFinding();
        $allowed = ['app/Services/Ai/Cognition/AtlasCognitiveFunctionDecomposerService.php'];
        $env = StewardshipAutonomyEnvelope::fromArray([
            'area_id' => 'agentic_engineering_os',
            'merge_target' => 'integration_lane',
            'admit_cross_system' => true,
            'risk_ceiling' => 'high',
        ]);

        $reason = $this->rejectionReason($finding, $allowed, $env);

        // The envelope is authority, not a bypass around decomposition. A broad
        // cross-system parent must be rejected with an admission-bridge reason and
        // converted into a bounded Self-Construction packet before execution.
        $this->assertContains($reason, [
            'factory_max_rejects_high_risk_deep_finding_without_forge_authority',
            'factory_max_rejects_non_factory_scope_without_automerge_authority',
        ]);
    }

    public function test_envelope_admits_bounded_self_construction_packet_routed_to_lane(): void
    {
        $finding = $this->crossSystemAtlasDevFinding([
            'finding_id' => 'afdf_test_cross::packet::1',
            'origin_type' => 'self_construction_admission_packet',
            'severity' => 'medium',
            'affected_files' => [
                'app/Services/Ai/Cognition/CognitiveStackTraceContract.php',
                'tests/Unit/Ai/Cognition/CognitiveStackTraceContractTest.php',
            ],
            'self_construction_packet' => [
                'parent_affected_files' => ['app/Services/Ai/Cognition/AtlasCognitiveFunctionDecomposerService.php'],
            ],
        ]);
        $allowed = (array) $finding['affected_files'];
        $env = StewardshipAutonomyEnvelope::fromArray([
            'area_id' => 'agentic_engineering_os',
            'merge_target' => 'integration_lane',
            'admit_cross_system' => true,
            'risk_ceiling' => 'medium',
        ]);

        $reason = $this->rejectionReason($finding, $allowed, $env);

        $this->assertSame('', $reason);
    }

    public function test_envelope_does_not_admit_when_risk_exceeds_ceiling(): void
    {
        $finding = $this->crossSystemAtlasDevFinding(); // severity high
        $allowed = ['app/Services/Ai/Cognition/AtlasCognitiveFunctionDecomposerService.php'];
        $env = StewardshipAutonomyEnvelope::fromArray([
            'area_id' => 'agentic_engineering_os',
            'merge_target' => 'integration_lane',
            'admit_cross_system' => true,
            'risk_ceiling' => 'medium', // below the finding's high severity
        ]);

        $reason = $this->rejectionReason($finding, $allowed, $env);

        $this->assertNotSame('', $reason);
    }
}
