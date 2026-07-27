<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesAgentControlPlaneTaskQueueOrchestrator;
use Tests\TestCase;

/**
 * The dominant jam cause: a packet whose pétreo target was moved to forbidden_files by a prior scope-repair, but
 * whose acceptance STILL demands it — quarantined forever on `scope_repair_removed_required_target`, stranding
 * every dead-prereq dependent. {@see AgentControlPlaneTaskQueueOrchestrator::repairScopeBlockedTasks} reconciles:
 *   - a BUILDABLE doomed packet ⇒ reopened with the pétreo demand scrubbed from acceptance (worker builds the
 *     class+test; the operator wires the pétreo flag);
 *   - an ONLY-TEST / only-pétreo doomed packet ⇒ retired (cancelled), which fail-opens its dependents;
 *   - a stale self-sufficient blocked packet ⇒ reopened unchanged.
 */
final class AtlasTaskServingScopeBlockedRepairTest extends TestCase
{
    use MakesAgentControlPlaneTaskQueueOrchestrator;
    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->envFile = sys_get_temp_dir().'/atlas-sbr-env-'.bin2hex(random_bytes(5)).'.env';
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

    public function test_buildable_doomed_packet_is_reopened_with_petreo_demand_scrubbed(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $this->enqueueBlocked('buildable-doomed', [
            'objective' => 'Implement RepairMe. Scope repair: config/atlas.php was removed from allowed_files because Atlas cannot safely commit forbidden self-targets.',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/RepairMe.php'],
            'forbidden_files' => ['config/atlas.php'],
            'acceptance_criteria' => ['Implement RepairMe with a passing test', 'Register the feature flag in config/atlas.php'],
            'required_evidence' => ['task_packet_created'],
        ]);

        $out = $this->orchestrator()->repairScopeBlockedTasks(actor: 'test');

        $this->assertSame(1, $out['reopened_count'], json_encode($out));
        $rec = $repo->get('buildable-doomed');
        $this->assertSame('claimable', (string) ($rec['status'] ?? ''), 'a buildable doomed packet is reopened');
        $acceptance = (array) data_get($rec, 'task_packet.acceptance_criteria', []);
        $joined = implode("\n", array_map('strval', $acceptance));
        $this->assertStringNotContainsString('config/atlas.php', $joined, 'the pétreo demand was scrubbed from acceptance');
        $this->assertStringContainsString('RepairMe', $joined, 'the buildable acceptance survives');
    }

    public function test_only_test_doomed_packet_is_retired_so_dependents_fail_open(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $this->enqueueBlocked('only-test-doomed', [
            'objective' => 'Cover the judge. Scope repair: app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php was removed from allowed_files because Atlas cannot safely commit forbidden self-targets.',
            'allowed_files' => ['tests/Unit/Ai/SelfConstruction/OnlyTestTest.php'],
            'forbidden_files' => ['app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php'],
            'acceptance_criteria' => ['Add a test that proves app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php refuses tampering'],
            'required_evidence' => ['tests_or_gates_result'],
        ]);

        $out = $this->orchestrator()->repairScopeBlockedTasks(actor: 'test');

        $this->assertSame(1, $out['retired_count'], json_encode($out));
        $rec = $repo->get('only-test-doomed');
        $this->assertSame('cancelled', (string) ($rec['status'] ?? ''), 'an only-test/pétreo-only doomed packet is retired (fail-open for dependents)');
    }

    public function test_stale_self_sufficient_blocked_packet_is_reopened_unchanged(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $this->enqueueBlocked('stale-clean', [
            'allowed_files' => ['app/Services/Ai/SelfConstruction/StaleClean.php'],
            'acceptance_criteria' => ['Implement StaleClean with a passing test'],
            'required_evidence' => ['task_packet_created'],
        ]);

        $out = $this->orchestrator()->repairScopeBlockedTasks(actor: 'test');

        $this->assertSame(1, $out['reopened_count'], json_encode($out));
        $this->assertSame('claimable', (string) ($repo->get('stale-clean')['status'] ?? ''));
    }

    public function test_repeated_give_back_quarantine_is_not_reopened_as_stale_clean(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $this->enqueueBlocked('repeated-giveback-clean', [
            'allowed_files' => ['app/Services/Ai/SelfConstruction/RepeatedGivebackClean.php'],
            'acceptance_criteria' => ['Implement RepeatedGivebackClean with a passing test'],
            'required_evidence' => ['task_packet_created'],
        ], [
            'reason' => 'packet_not_self_sufficient',
            'give_back_count' => 7,
            'blocking_deficiencies' => ['repeated_give_back_8'],
        ]);

        $out = $this->orchestrator()->repairScopeBlockedTasks(actor: 'test');

        $this->assertSame(0, $out['reopened_count'], json_encode($out));
        $this->assertSame(1, $out['unrepairable_count'], json_encode($out));
        $this->assertSame('blocked', (string) ($repo->get('repeated-giveback-clean')['status'] ?? ''));
        $this->assertSame('repeated_give_back_quarantine_requires_respec', (string) data_get($out, 'unrepairable.0.reason'));
    }


    public function test_dry_run_mutates_nothing(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $this->enqueueBlocked('dry-doomed', [
            'objective' => 'Implement DryDoomed. Scope repair: config/atlas.php was removed from allowed_files because Atlas cannot safely commit forbidden self-targets.',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/DryDoomed.php'],
            'forbidden_files' => ['config/atlas.php'],
            'acceptance_criteria' => ['Implement DryDoomed', 'wire config/atlas.php'],
            'required_evidence' => ['task_packet_created'],
        ]);

        $out = $this->orchestrator()->repairScopeBlockedTasks(dryRun: true, actor: 'test');

        $this->assertSame(1, $out['planned_count']);
        $this->assertSame('blocked', (string) ($repo->get('dry-doomed')['status'] ?? ''), 'dry-run leaves the packet blocked');
    }

    /** @param array<string, mixed> $overrides */
    private function enqueueBlocked(string $id, array $overrides, array $metadata = []): void
    {
        $input = array_merge([
            'task_packet_id' => $id,
            'objective' => 'scope-blocked repair fixture '.$id,
            'operator_id' => 'tester',
            'scope_in' => $overrides['allowed_files'] ?? [],
        ], $overrides);
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($input);
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $repo->enqueue($packet);
        $repo->updateStatus($id, 'blocked', array_merge(['reason' => 'fixture_quarantine', 'agent_id' => 'fixture'], $metadata));
    }
}
