<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCandidateQuarantineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * AP-801 · multi-agent workcell wiring into AP-786. Confirms the workcell is
 * gated behind the explicit flag: OFF preserves the legacy single-pass flow
 * (no `multi_agent_workcell` key), ON attaches the workcell projection without
 * ever claiming a provider ran in a dry run.
 */
final class MultiAgentWorkcellWiringTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap801_wire_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): AutonomousEvolutionSessionService
    {
        $service = app(AutonomousEvolutionSessionService::class);
        $service->setStorageDirForTesting($this->tmp.'/sessions');
        $quarantine = app(AreaFocusCandidateQuarantineService::class);
        $quarantine->setStorageRootForTesting($this->tmp.'/quarantine');
        $service->setCandidateQuarantineForTesting($quarantine);

        return $service;
    }

    public function test_flag_off_preserves_legacy_flow_without_workcell(): void
    {
        $payload = $this->service()->run([
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'cycles' => 1,
            'execute' => false,
        ]);

        $this->assertArrayNotHasKey('multi_agent_workcell', $payload, 'workcell must not appear when the flag is off');
    }

    public function test_flag_on_attaches_workcell_projection(): void
    {
        $payload = $this->service()->run([
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'cycles' => 1,
            'execute' => false,
            'multi_agent_workcell' => true,
        ]);

        $this->assertArrayHasKey('multi_agent_workcell', $payload);
        $block = $payload['multi_agent_workcell'];
        $this->assertSame('AP-801', $block['ap_contract']);
        $this->assertTrue($block['enabled'] ?? false);

        // A dry-run cycle did not run a provider, so the workcell must report
        // `not_executed` per cycle and never claim a real provider invocation.
        foreach (($block['cycles'] ?? []) as $cycle) {
            $this->assertSame('not_executed', $cycle['status']);
            $this->assertFalse($cycle['provider_invoked']);
            $this->assertFalse($cycle['production_certified']);
        }
        foreach (($payload['cycles'] ?? []) as $cycle) {
            $this->assertArrayHasKey('multi_agent_workcell', $cycle);
        }
    }
}
