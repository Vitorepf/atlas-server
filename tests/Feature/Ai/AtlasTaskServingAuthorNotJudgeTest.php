<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * govA-author-not-judge-servetime-w2 — author≠judge separation at serve time.
 *
 * Workers must NEVER receive a packet that was authored and then immediately served without an
 * independent excellence-grade re-check intervening. The serving service calls the inspector
 * twice on every claimed packet: once to reproduce the minter's self-sufficiency claim, once
 * as an independent serve-time gate. Both share the same BLOCKING_DEFICIENCIES list.
 */
final class AtlasTaskServingAuthorNotJudgeTest extends TestCase
{
    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->envFile = sys_get_temp_dir().'/atlas-anj-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        if ($this->envFile !== '') {
            @unlink($this->envFile);
        }
        parent::tearDown();
    }

    public function test_serve_time_inspector_is_called_exactly_twice_per_packet(): void
    {
        $source = (string) file_get_contents(
            base_path('app/Services/Ai/SelfConstruction/AtlasTaskServingService.php')
        );
        // The author≠judge contract: exactly two `$this->inspector->inspect(` calls in next().
        $count = substr_count($source, '$this->inspector->inspect(');
        self::assertSame(2, $count, 'expected exactly two inspector calls (self-check + independent re-check)');
    }

    public function test_quarantine_path_is_identical_for_self_check_and_serve_time_failures(): void
    {
        // Both failure paths go through the SAME `quarantineClaimed` call inside the loop.
        $source = (string) file_get_contents(
            base_path('app/Services/Ai/SelfConstruction/AtlasTaskServingService.php')
        );
        $count = substr_count($source, '$this->orchestrator->quarantineClaimed(');
        self::assertSame(1, $count, 'both inspection failures must share one quarantineClaimed call');
    }

    public function test_serve_time_failure_response_mirrors_self_check_failure(): void
    {
        $orch = $this->orchestrator();
        // Deficient: zero acceptance criteria → blocking deficiency 'missing_acceptance_criteria'.
        $this->rawEnqueue($this->input('only-deficient', acceptance: []));

        $serving = new AtlasTaskServingService($orch);
        $res = $serving->next('client-cold');

        self::assertSame('no_self_sufficient_task', $res['status']);
        self::assertSame('needs_brain_origination', $res['escalation']);
        self::assertContains('missing_acceptance_criteria', $res['blocking_deficiencies']);

        $blocked = (new AgentControlPlaneTaskPacketQueueRepository)->get('only-deficient');
        self::assertSame('blocked', (string) ($blocked['status'] ?? ''));
    }

    public function test_excellence_grade_packet_is_served_with_quality_facts(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('excellent')]);

        $serving = new AtlasTaskServingService($orch);
        $res = $serving->next('client-cold');

        self::assertSame('served', $res['status']);
        self::assertSame('excellent', $res['task']['task_packet_id']);
        self::assertTrue($res['task']['packet_quality']['self_sufficient']);
        self::assertSame([], $res['task']['packet_quality']['blocking_deficiencies']);
    }

    private function orchestrator(): AgentControlPlaneTaskQueueOrchestrator
    {
        return new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );
    }

    /** @param array<string, mixed> $input */
    private function rawEnqueue(array $input): void
    {
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($input);
        (new AgentControlPlaneTaskPacketQueueRepository)->enqueue($packet);
    }

    /** @return array<string, mixed> */
    private function input(string $id, ?array $acceptance = null, ?array $evidence = null): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => 'wire AtlasFooService into the php artisan kernel for packet '.$id,
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'acceptance_criteria' => $acceptance ?? ['php artisan test passes'],
            'required_evidence' => $evidence ?? ['task_packet_created'],
        ];
    }
}
