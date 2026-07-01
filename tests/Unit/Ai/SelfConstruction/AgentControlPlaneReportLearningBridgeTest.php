<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proves the report → learning-transfer bridge on AgentControlPlaneTaskQueueOrchestrator:
 * a give_back carries the give_back reason into the lesson-candidate fact and the envelope
 * gains learning_bridge=accepted|rejected; a resolved report produces a success fact; and a
 * learning-side exception leaves the report envelope intact with learning_bridge=error
 * (fail-open) — the admission orchestrator is composed in its default OBSERVE mode, never
 * armed for APPLY, never modified.
 */
final class AgentControlPlaneReportLearningBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
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

    private function input(string $id): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => 'bridge test '.$id,
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ];
    }

    // ── (a) give_back carries the reason and the envelope gains learning_bridge ──

    public function test_give_back_produces_lesson_candidate_fact_with_reason_and_learning_bridge_status(): void
    {
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue(['task_packet' => $this->input('bridge-give-back')]);
        $claim = $svc->claimNext('agent-give-back');

        $result = $svc->reportGiveBack('bridge-give-back', (string) $claim['lease_id'], 'agent-give-back', 'scope_conflict_with_sibling_task');

        $this->assertSame('given_back', $result['event']);
        $this->assertArrayHasKey('learning_bridge', $result);
        $this->assertContains($result['learning_bridge']['status'], ['accepted', 'rejected']);
        $this->assertSame('give_back', $result['learning_bridge']['fact']['outcome']);
        $this->assertSame('scope_conflict_with_sibling_task', $result['learning_bridge']['fact']['reason']);
        $this->assertSame('scope_conflict_with_sibling_task', $result['learning_bridge']['fact']['give_back_reason']);
        $this->assertSame('bridge-give-back', $result['learning_bridge']['fact']['task_packet_id']);
    }

    // ── (b) resolved report produces a success fact ───────────────────────────────

    public function test_resolved_report_produces_a_success_fact(): void
    {
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue(['task_packet' => $this->input('bridge-resolved')]);
        $claim = $svc->claimNext('agent-resolved');

        $result = $svc->markResolved('bridge-resolved', (string) $claim['lease_id'], 'agent-resolved', 'abc123def');

        $this->assertSame('task_resolved', $result['event']);
        $this->assertArrayHasKey('learning_bridge', $result);
        $this->assertContains($result['learning_bridge']['status'], ['accepted', 'rejected']);
        $this->assertSame('resolved', $result['learning_bridge']['fact']['outcome']);
        $this->assertSame('agent-resolved', $result['learning_bridge']['fact']['agent_id']);
        $this->assertArrayNotHasKey('give_back_reason', $result['learning_bridge']['fact']);
    }

    public function test_completed_dry_run_report_produces_a_completed_dry_run_fact(): void
    {
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue(['task_packet' => $this->input('bridge-dry-run')]);
        $claim = $svc->claimNext('agent-dry-run');
        $evidence = $this->completionEvidenceFor('bridge-dry-run', (string) $claim['lease_id'], 'agent-dry-run');

        $result = $svc->completeDryRun('bridge-dry-run', (string) $claim['lease_id'], $evidence);

        $this->assertSame('completed_dry_run', $result['event']);
        $this->assertArrayHasKey('learning_bridge', $result);
        $this->assertSame('completed_dry_run', $result['learning_bridge']['fact']['outcome']);
    }

    // ── (c) a learning-side exception leaves the report envelope intact, fail-open ──

    public function test_learning_side_exception_leaves_report_envelope_intact_with_learning_bridge_error(): void
    {
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue(['task_packet' => $this->input('bridge-exception')]);
        $claim = $svc->claimNext('agent-exception');

        // Corrupt the persisted queue record's objective into a non-string (array) — a real
        // runtime fault the bridge can hit when reading the queue entry (hash('sha256', ...) on
        // a non-string), not a mock. The report itself must still complete successfully.
        $path = 'atlas/self-construction/agent-control-plane/task-queue/task_bridge-exception.json';
        $record = json_decode(Storage::disk('local')->get($path), true);
        $record['task_packet']['objective'] = ['not', 'a', 'string'];
        Storage::disk('local')->put($path, json_encode($record));

        $result = $svc->markResolved('bridge-exception', (string) $claim['lease_id'], 'agent-exception', 'deadbeef');

        $this->assertSame('task_resolved', $result['event']);
        $this->assertSame('bridge-exception', $result['task_packet_id']);
        $this->assertSame('deadbeef', $result['commit_sha']);
        $this->assertArrayHasKey('learning_bridge', $result);
        $this->assertSame('error', $result['learning_bridge']['status']);
        $this->assertNotEmpty($result['learning_bridge']['error']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string,mixed>
     */
    private function completionEvidenceFor(string $packetId, string $leaseId, string $actor, array $overrides = []): array
    {
        $evidence = array_merge([
            'packet_id' => $packetId,
            'lease_id' => $leaseId,
            'actor' => $actor,
            'files_changed' => ['app/Services/Ai/SelfConstruction/'.$packetId.'.php'],
            'commands_run' => ['php artisan test '.$packetId.': passed'],
            'tests_or_gates_result' => 'passed',
            'git_status_short' => ' M app/Services/Ai/SelfConstruction/'.$packetId.'.php',
            'git_diff_check_result' => 'clean',
        ], $overrides);
        $evidence['evidence_hash'] = AgentControlPlaneTaskQueueOrchestrator::canonicalCompletionEvidenceHash($evidence);

        return $evidence;
    }
}
