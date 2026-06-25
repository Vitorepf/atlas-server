<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ControlPlane;

use App\Console\Commands\AtlasSelfConstructionControlPlaneCommand;
use App\Services\Ai\SelfConstruction\ControlPlane\AtlasSelfConstructionAutonomyModePolicy;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Tests\TestCase;

/**
 * Wires AtlasSelfConstructionAutonomyModePolicy into the live
 * `atlas:self-construction:control-plane policy` flow. Proves the policy is reached by real
 * production code via the operator CLI.
 */
final class AtlasSelfConstructionAutonomyModePolicyWiringWiredTest extends TestCase
{
    private string $factsPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->factsPath = sys_get_temp_dir().'/atlas-autonomy-policy-'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->factsPath);
        parent::tearDown();
    }

    public function test_command_class_imports_the_policy_symbol(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AtlasSelfConstructionControlPlaneCommand::class))->getFileName(),
        );
        $this->assertStringContainsString(
            AtlasSelfConstructionAutonomyModePolicy::class,
            $source,
            'control-plane command must reference the autonomy policy so it is no longer an orphan',
        );
    }

    public function test_policy_action_emits_disabled_envelope_under_operator_force_disabled(): void
    {
        file_put_contents($this->factsPath, (string) json_encode([
            'operator_overrides' => ['force_disabled' => true],
        ]));

        $exit = Artisan::call('atlas:self-construction:control-plane', [
            'action' => 'policy',
            '--facts' => $this->factsPath,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame(AtlasSelfConstructionAutonomyModePolicy::SCHEMA, $payload['schema']);
        $this->assertSame(AtlasSelfConstructionAutonomyModePolicy::MODE_DISABLED, $payload['mode']);
        $this->assertContains('operator_force_disabled', $payload['reasons']);
    }

    public function test_policy_action_emits_blockers_for_missing_organs(): void
    {
        // No operator override, no organ readiness — the policy must report blockers per organ.
        file_put_contents($this->factsPath, (string) json_encode([
            'organ_readiness' => [],
        ]));

        $exit = Artisan::call('atlas:self-construction:control-plane', [
            'action' => 'policy',
            '--facts' => $this->factsPath,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame(AtlasSelfConstructionAutonomyModePolicy::SCHEMA, $payload['schema']);
        $this->assertArrayHasKey('blockers', $payload);
        $this->assertArrayHasKey('readiness_summary', $payload);
    }
}
