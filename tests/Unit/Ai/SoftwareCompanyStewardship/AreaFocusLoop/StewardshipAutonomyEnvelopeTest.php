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
    private function crossSystemAtlasDevFinding(): array
    {
        // owner=atlas_dev, high severity, real cross-system source (outside the
        // factory-scoped boundary), passes the autonomous-execution gate.
        return [
            'finding_id' => 'afdf_test_cross',
            'title' => 'Wire cognitive immune into autonomous engineering decisions',
            'origin' => 'structural_ap717',
            'origin_type' => 'dev_forge_routing_gap',
            'owner' => 'atlas_dev',
            'severity' => 'high',
            'auto_execution_allowed' => true,
            'operator_review_required' => false,
            'affected_files' => ['app/Models/AtlasProject.php'],
        ];
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
        $allowed = ['app/Models/AtlasProject.php', 'tests/Unit/Models/AtlasProjectTest.php'];

        $reason = $this->rejectionReason($finding, $allowed, null);

        // Byte-identical factory_max behavior: a cross-system finding is rejected.
        $this->assertNotSame('', $reason);
    }

    public function test_envelope_admits_cross_system_atlas_dev_routed_to_lane(): void
    {
        $finding = $this->crossSystemAtlasDevFinding();
        $allowed = ['app/Models/AtlasProject.php', 'tests/Unit/Models/AtlasProjectTest.php'];
        $env = StewardshipAutonomyEnvelope::fromArray([
            'area_id' => 'agentic_engineering_os',
            'merge_target' => 'integration_lane',
            'admit_cross_system' => true,
            'risk_ceiling' => 'high',
        ]);

        $reason = $this->rejectionReason($finding, $allowed, $env);

        // Under the standing envelope (merge routes to the integration lane) the
        // same cross-system atlas_dev finding is ADMITTED.
        $this->assertSame('', $reason);
    }

    public function test_envelope_does_not_admit_when_risk_exceeds_ceiling(): void
    {
        $finding = $this->crossSystemAtlasDevFinding(); // severity high
        $allowed = ['app/Models/AtlasProject.php', 'tests/Unit/Models/AtlasProjectTest.php'];
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
