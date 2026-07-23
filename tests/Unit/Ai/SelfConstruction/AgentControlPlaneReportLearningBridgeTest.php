<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningTransferAdmissionLedger;
use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningTransferObservationStore;
use App\Services\Ai\SelfConstruction\Maestro\Adaptive\AtlasMaestroWorkerBehaviorLedger;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesAgentControlPlaneTaskQueueOrchestrator;
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
    use MakesAgentControlPlaneTaskQueueOrchestrator;
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // The admission ledger + observation store live on a phpunit-pinned
        // /tmp path shared by every test run: without cleanup, rows written
        // by earlier runs (or earlier code versions) leak state into the
        // accumulator-dependent assertions below.
        @unlink(AtlasSelfConstructionLearningTransferAdmissionLedger::defaultPath());
        @unlink(AtlasSelfConstructionLearningTransferObservationStore::defaultPath());
        @unlink(AtlasMaestroWorkerBehaviorLedger::defaultPath());
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
        $input = $this->input('bridge-resolved');
        $proof = $this->committedTaskProof('bridge-resolved', $input['allowed_files']);
        $svc = $this->orchestrator($proof['repository']);
        $svc->prepareAndEnqueue(['task_packet' => $input]);
        $claim = $svc->claimNext('agent-resolved');

        $result = $svc->markResolved('bridge-resolved', (string) $claim['lease_id'], 'agent-resolved', $proof['commit_sha']);

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

    // ── (b2) r125 floor producers ride on every fact; the bottleneck is now the gate ──

    public function test_resolved_report_carries_the_r125_outcome_proofs_and_reaches_the_gate(): void
    {
        $id = 'bridge-ledger-'.substr(bin2hex(random_bytes(6)), 0, 10);
        $input = $this->input($id);
        $proof = $this->committedTaskProof($id, $input['allowed_files']);
        $svc = $this->orchestrator($proof['repository']);
        $svc->prepareAndEnqueue(['task_packet' => $input]);
        $claim = $svc->claimNext('agent-ledger');

        $result = $svc->markResolved($id, (string) $claim['lease_id'], 'agent-ledger', $proof['commit_sha']);

        $bridge = $result['learning_bridge'];
        $fact = $bridge['fact'];
        // The bridge now produces every proof the r125 admission floor requires
        // (before this, admits died at refused_by_admission_floor for missing
        // muscle_outcome — the ledger never got a single live row).
        $this->assertSame(['task_packet:'.$id], $fact['evidence_refs']);
        $this->assertSame('packet_admission', $fact['impact_class']);
        $this->assertNotEmpty($fact['design_path_refs']);
        $this->assertSame('resolved', $fact['muscle_outcome']['status']);
        // Honest pin of the CURRENT structural ceiling: a single-run lesson is
        // held at the gate's independent-repetition threshold (the gate is
        // stateless, one observation per admit). Opening this requires a
        // design decision (observation accumulator + floor/classifier
        // reconciliation), not a quick producer patch. If this assertion ever
        // flips to admitted_and_recorded, the accumulator landed — update the
        // test to assert the ledger row instead.
        $this->assertSame('short_circuited_at_gate', $bridge['outcome'], json_encode($bridge));
    }

    public function test_give_back_report_is_refused_by_the_success_only_floor_not_an_error(): void
    {
        $id = 'bridge-floor-'.substr(bin2hex(random_bytes(6)), 0, 10);
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue(['task_packet' => $this->input($id)]);
        $claim = $svc->claimNext('agent-floor');

        $result = $svc->reportGiveBack($id, (string) $claim['lease_id'], 'agent-floor', 'scope_conflict_with_sibling_task');

        $bridge = $result['learning_bridge'];
        // give_back is not in the ledger's success whitelist: the admit must be
        // a governed refusal (or gate short-circuit), never a bridge error and
        // never faked into a success status.
        $this->assertNotSame('error', $bridge['status'], json_encode($bridge));
        $this->assertNotSame('admitted_and_recorded', $bridge['outcome'] ?? null);
        $this->assertSame('give_back', $bridge['fact']['muscle_outcome']['status']);
    }

    // ── (b3) the SAME outcome also lands in the durable worker-behavior ledger ──

    public function test_give_back_writes_a_durable_worker_behavior_fact_a_fresh_process_can_recall(): void
    {
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue(['task_packet' => $this->input('behavior-fact')]);
        $claim = $svc->claimNext('agent-behavior');

        $svc->reportGiveBack('behavior-fact', (string) $claim['lease_id'], 'agent-behavior', 'scope_conflict_with_sibling_task');

        // A FRESH ledger instance (≙ the next process) must recall the fact —
        // this is exactly what the pure in-memory ledger could never do.
        $fresh = new AtlasMaestroWorkerBehaviorLedger;
        $recall = $fresh->recall('agent-behavior', 'family:app/Services');

        $this->assertTrue($recall['seen'], json_encode($fresh->allStats()));
        $this->assertSame(1.0, $recall['give_back_rate']);
        $this->assertStringContainsString('scope_conflict_with_sibling_task', $fresh->topGiveBackCauses()[0]['root_cause_key']);
    }

    // ── (b4) claim path READS the behavior ledger: repeated give-back families demote ──

    public function test_claim_order_demotes_a_family_this_worker_keeps_giving_back_when_flag_is_on(): void
    {
        $svc = $this->orchestrator();
        // Packet A (family:app/Services) enqueued FIRST — wins the stable order by default.
        $svc->prepareAndEnqueue(['task_packet' => $this->input('demote-target')]);
        // Packet B in a DIFFERENT family (app/Console).
        $svc->prepareAndEnqueue(['task_packet' => array_merge($this->input('demote-alt'), [
            'objective' => 'distinct console-side objective for demotion proof',
            'allowed_files' => ['app/Console/Commands/DemoteAlt.php'],
            'scope_in' => ['app/Console/Commands/DemoteAlt.php'],
            'acceptance_criteria' => ['console alt claimable'],
        ])]);

        // Durable evidence: this worker gave family:app/Services back 3 times.
        $ledger = new AtlasMaestroWorkerBehaviorLedger;
        for ($i = 0; $i < 3; $i++) {
            $ledger->record(['client_id' => 'agent-demote', 'task_family' => 'family:app/Services', 'outcome' => 'give_back']);
        }

        // Flag OFF (default): stable order serves packet A first. Proven via a
        // DIFFERENT worker so the claim doesn't consume the queue for the real probe.
        $claimOff = $svc->claimNext('agent-other');
        $this->assertSame('demote-target', $claimOff['task_packet_id']);
        $svc->reportGiveBack('demote-target', (string) $claimOff['lease_id'], 'agent-other', 'putting_it_back_for_the_probe');

        // Anti-vacuity probe: packet A really is claimable again right now (a third
        // worker gets it first under the default order) — so B-wins below can only
        // come from the demotion, never from A being stuck in `released`.
        $probe = $svc->claimNext('agent-probe');
        $this->assertSame('demote-target', $probe['task_packet_id']);
        $svc->reportGiveBack('demote-target', (string) $probe['lease_id'], 'agent-probe', 'putting_it_back_again');

        // Flag ON: the burned family sorts LAST for agent-demote → it claims B.
        config(['atlas.maestro.adaptive.behavior_ledger_enabled' => true]);
        $claim = $svc->claimNext('agent-demote');
        $this->assertSame('claimed', $claim['event'], json_encode($claim));
        $this->assertSame('demote-alt', $claim['task_packet_id']);
    }

    // ── (c) a learning-side exception leaves the report envelope intact, fail-open ──

    public function test_learning_side_exception_leaves_report_envelope_intact_with_learning_bridge_error(): void
    {
        $input = $this->input('bridge-exception');
        $proof = $this->committedTaskProof('bridge-exception', $input['allowed_files']);
        $svc = $this->orchestrator($proof['repository']);
        $svc->prepareAndEnqueue(['task_packet' => $input]);
        $claim = $svc->claimNext('agent-exception');

        // Corrupt the persisted queue record's objective into a non-string (array) — a real
        // runtime fault the bridge can hit when reading the queue entry (hash('sha256', ...) on
        // a non-string), not a mock. The report itself must still complete successfully.
        $path = 'atlas/self-construction/agent-control-plane/task-queue/task_bridge-exception.json';
        $record = json_decode(Storage::disk('local')->get($path), true);
        $record['task_packet']['objective'] = ['not', 'a', 'string'];
        Storage::disk('local')->put($path, json_encode($record));

        $result = $svc->markResolved('bridge-exception', (string) $claim['lease_id'], 'agent-exception', $proof['commit_sha']);

        $this->assertSame('task_resolved', $result['event']);
        $this->assertSame('bridge-exception', $result['task_packet_id']);
        $this->assertSame($proof['commit_sha'], $result['commit_sha']);
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
            'implementation_notes' => 'Validated the scoped implementation and its behavioral proof.',
            'capability_delta' => 'Adds the bounded capability described by this packet.',
            'task_packet_created' => 'receipt:'.$packetId,
            'git_status_short' => ' M app/Services/Ai/SelfConstruction/'.$packetId.'.php',
            'git_diff_check_result' => 'clean',
        ], $overrides);
        $evidence['evidence_hash'] = AgentControlPlaneTaskQueueOrchestrator::canonicalCompletionEvidenceHash($evidence);

        return $evidence;
    }
}
