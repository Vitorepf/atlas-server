<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use App\Services\Ai\SelfConstruction\AtlasTaskServingSwitch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesAgentControlPlaneTaskQueueOrchestrator;
use Tests\TestCase;

/**
 * Proves governed scope expansion: a give_back carrying a justified expansion
 * request rebuilds the packet with the wider scope (audited receipt), and the
 * SAME worker reclaims it immediately — no give_back stamp, no cooldown, no
 * doom-ladder escalation. A thin justification or a forbidden target refuses
 * the expansion and falls through to the normal give_back path unchanged.
 */
final class AtlasTaskScopeExpansionTest extends TestCase
{
    use MakesAgentControlPlaneTaskQueueOrchestrator;
    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->envFile = sys_get_temp_dir().'/atlas-scx-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
        AtlasTaskServingSwitch::on();
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        AtlasTaskServingSwitch::$envPathOverride = null;
        @unlink($this->envFile);
        parent::tearDown();
    }

    /** @return array{client:string, task_packet_id:string, lease_id:string, serving:AtlasTaskServingService} */
    private function servedTask(string $id): array
    {
        $exit = Artisan::call('atlas:task:enqueue', [
            '--objective' => 'refactor the report pipeline seam for '.$id,
            '--allow' => ['app/Services/Reports/Pipeline'.$id.'.php'],
            '--accept' => ['pipeline behavior preserved for '.$id],
            '--evidence' => ['tests_or_gates_result'],
            '--id' => $id,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $serving = new AtlasTaskServingService($this->orchestrator());
        $res = $serving->next('client-'.$id);
        $this->assertSame('served', $res['status'], json_encode($res));

        return [
            'client' => 'client-'.$id,
            'task_packet_id' => (string) $res['task']['task_packet_id'],
            'lease_id' => (string) $res['task']['lease_id'],
            'serving' => $serving,
        ];
    }

    public function test_justified_expansion_widens_scope_and_same_worker_reclaims_immediately(): void
    {
        $t = $this->servedTask('scx-grant');
        $extra = 'app/Services/Reports/PipelineCallerscx-grant.php';

        $result = $t['serving']->report($t['client'], $t['task_packet_id'], $t['lease_id'], [
            'outcome' => 'give_back',
            'evidence' => ['scope_expansion_request' => [
                'files' => [$extra],
                'justification' => 'the extracted pipeline is consumed by this caller; migrating it is part of the same seam',
            ]],
        ]);

        $this->assertSame('scope_expanded', $result['status'], json_encode($result));
        $this->assertContains($extra, (array) $result['result']['allowed_files']);

        // Audit trail on the receipt chain.
        $record = (new AgentControlPlaneTaskPacketQueueRepository)->get($t['task_packet_id']);
        $kinds = array_column((array) ($record['receipts'] ?? []), 'receipt_kind');
        $this->assertContains('scope_expansion_granted', $kinds);

        // NOT a give_back: no count escalation, no cooldown stamp.
        $this->assertSame(0, (int) data_get($record, 'metadata.give_back_count', 0));
        $this->assertSame('', (string) data_get($record, 'metadata.last_give_back_by', ''));

        // The SAME worker reclaims the SAME packet immediately, now with the wider scope.
        $again = $t['serving']->next($t['client']);
        $this->assertSame('served', $again['status'], json_encode($again));
        $this->assertSame($t['task_packet_id'], (string) $again['task']['task_packet_id']);
        $this->assertContains($extra, (array) data_get($again, 'task.allowed_files'));
    }

    public function test_thin_justification_refuses_expansion_and_falls_through_to_give_back(): void
    {
        $t = $this->servedTask('scx-thin');

        $result = $t['serving']->report($t['client'], $t['task_packet_id'], $t['lease_id'], [
            'outcome' => 'give_back',
            'evidence' => ['scope_expansion_request' => [
                'files' => ['app/Services/Reports/PipelineCallerscx-thin.php'],
                'justification' => 'need it',
            ]],
        ]);

        $this->assertSame('reported', $result['status'], json_encode($result));
        $record = (new AgentControlPlaneTaskPacketQueueRepository)->get($t['task_packet_id']);
        $this->assertSame(1, (int) data_get($record, 'metadata.give_back_count', 0));
        $kinds = array_column((array) ($record['receipts'] ?? []), 'receipt_kind');
        $this->assertNotContains('scope_expansion_granted', $kinds);
    }

    public function test_forbidden_axis_target_refuses_expansion(): void
    {
        $t = $this->servedTask('scx-forbidden');

        $granted = $this->orchestrator()->requestScopeExpansion(
            $t['task_packet_id'],
            $t['lease_id'],
            $t['client'],
            ['forge/Anything.php'],
            'the seam crosses into forge orchestration and needs this adapter migrated too',
        );

        $this->assertSame('scope_expansion_refused', $granted['event'], json_encode($granted));
        // Nothing mutated: the packet is still claimed by the worker, scope unchanged.
        $record = (new AgentControlPlaneTaskPacketQueueRepository)->get($t['task_packet_id']);
        $this->assertSame('claimed', (string) $record['status']);
        $this->assertNotContains('forge/Anything.php', (array) data_get($record, 'task_packet.normalized_scope.allowed_files'));
    }
}
