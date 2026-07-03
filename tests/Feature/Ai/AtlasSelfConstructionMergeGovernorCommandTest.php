<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorAdmissionPolicy;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorRiskClassifier;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves atlas:self-construction:merge-governor: inspect emits required services + non-execution
 * guarantees; decide on a clean admitted candidate yields decision=admitted; decide with a missing
 * rollback yields admission.decision=repair_required and rollback_gate.conformant=false; history
 * reads ledger rows; unknown verb returns unknown_action.
 */
final class AtlasSelfConstructionMergeGovernorCommandTest extends TestCase
{
    private string $candidatePath;

    private string $ledgerPath;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(6));
        $this->candidatePath = sys_get_temp_dir().'/atlas_mgcli_cand_'.$tag.'.json';
        $this->ledgerPath = sys_get_temp_dir().'/atlas_mgcli_ledger_'.$tag.'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->candidatePath);
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function writeCandidate(array $candidate): void
    {
        file_put_contents($this->candidatePath, json_encode($candidate, JSON_UNESCAPED_SLASHES));
    }

    public function test_enforce_readiness_folds_evidence_and_fails_closed_on_spurious_reasons(): void
    {
        // Clean window: enough samples, real reasons only => ready. Spurious
        // window (producer-bug reason) => NOT ready, regardless of volume.
        $rowBase = [
            'candidate_hash' => 'c', 'verification_hash' => 'v', 'rollback_hash' => 'r',
            'changed_files_hash' => 'cf', 'project_lane' => ['project_id' => 'demo'],
        ];
        $ledger = new \App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorReleaseDecisionLedger($this->ledgerPath);
        for ($i = 0; $i < 21; $i++) {
            $ledger->append($rowBase + [
                'task_packet_id' => 'clean-'.$i,
                'decision' => 'admitted',
                'reasons' => ['service_or_test_change'],
                'risk_level' => 'medium',
                'decided_at' => '2026-07-02T0'.($i % 9).':00:00Z',
            ]);
            $ledger->append($rowBase + [
                'task_packet_id' => 'spur-'.$i,
                'decision' => 'blocked',
                'reasons' => ['risk:missing_task_evidence_ref', 'risk_blocked'],
                'risk_level' => 'low',
                'decided_at' => '2026-07-02T0'.($i % 9).':00:00Z',
            ]);
        }

        Artisan::call('atlas:self-construction:merge-governor', ['action' => 'enforce-readiness', '--ledger' => $this->ledgerPath, '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame('ok', $payload['status']);
        $this->assertTrue($payload['risk_levels']['medium']['enforce_ready']);
        $this->assertFalse($payload['risk_levels']['low']['enforce_ready']);
        $this->assertSame('spurious_reasons_in_window', $payload['risk_levels']['low']['reason']);
    }

    private function admittedCandidate(): array
    {
        return [
            'project_id' => 'demo',
            'risk_input' => [
                'changed_files' => ['app/Demo/Foo.php'],
                'touched_organs' => ['Demo'],
                'verification_result' => ['passed' => true],
                'rollback_plan' => ['mode' => 'revert_commit'],
                'project_lane' => ['project_id' => 'demo', 'allowed_scope_roots' => ['app/Demo']],
                'scope_deviations' => [],
            ],
            'rollback_plan' => [
                'affected_files' => ['app/Demo/Foo.php'],
                'restore_strategy' => 'revert_commit',
                'verification_after_rollback' => ['phpunit'],
                'owner_scope' => 'demo',
                'project_lane' => ['project_id' => 'demo', 'allowed_scope_roots' => ['app/Demo']],
            ],
            'verification_court' => ['server_side_green' => true, 'evidence_hash' => 'evh-1', 'missing_rerun' => [], 'project_id' => 'demo'],
            'release_window_policy' => ['allowed_risk_levels' => ['low', 'medium']],
        ];
    }

    public function test_inspect_reports_services_and_non_execution_guarantees(): void
    {
        $exit = Artisan::call('atlas:self-construction:merge-governor', ['action' => 'inspect', '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertContains(AtlasMergeGovernorAdmissionPolicy::SCHEMA, $payload['services']);
        $this->assertContains(AtlasMergeGovernorRiskClassifier::SCHEMA, $payload['services']);
        $this->assertFalse($payload['non_execution_guarantees']['writes_storage']);
        $this->assertFalse($payload['non_execution_guarantees']['starts_merge']);
    }

    public function test_decide_admitted_candidate_returns_admission_admitted(): void
    {
        $this->writeCandidate($this->admittedCandidate());
        Artisan::call('atlas:self-construction:merge-governor', ['action' => 'decide', '--candidate' => $this->candidatePath, '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED, $payload['admission']['decision']);
        $this->assertTrue($payload['rollback_gate']['conformant']);
    }

    public function test_decide_with_missing_rollback_yields_repair_required_with_non_conformant_rollback(): void
    {
        $c = $this->admittedCandidate();
        $c['rollback_plan'] = ['restore_strategy' => '', 'affected_files' => [], 'verification_after_rollback' => []];
        $this->writeCandidate($c);
        Artisan::call('atlas:self-construction:merge-governor', ['action' => 'decide', '--candidate' => $this->candidatePath, '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertFalse($payload['rollback_gate']['conformant']);
        $this->assertSame(AtlasMergeGovernorAdmissionPolicy::DECISION_REPAIR, $payload['admission']['decision']);
    }

    public function test_history_reads_ledger_rows_without_writing(): void
    {
        // Pre-seed ledger by appending once via the decide path with --ledger.
        $c = $this->admittedCandidate();
        $c['ledger_envelope'] = [
            'task_packet_id' => 'pkt-1',
            'candidate_hash' => 'cand-h-1',
            'decision' => AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED,
            'reasons' => ['service_or_test_change'],
            'verification_hash' => 'ver-h-1',
            'rollback_hash' => 'rb-h-1',
            'project_lane' => ['project_id' => 'demo'],
            'decided_at' => '2026-06-25T00:00:00Z',
        ];
        $this->writeCandidate($c);
        Artisan::call('atlas:self-construction:merge-governor', ['action' => 'decide', '--candidate' => $this->candidatePath, '--ledger' => $this->ledgerPath, '--json' => true]);

        Artisan::call('atlas:self-construction:merge-governor', ['action' => 'history', '--ledger' => $this->ledgerPath, '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertSame('ok', $payload['status']);
        $this->assertNotEmpty($payload['rows']);
        $this->assertSame('pkt-1', $payload['rows'][0]['task_packet_id']);
    }

    public function test_unknown_action_returns_unknown_action(): void
    {
        $exit = Artisan::call('atlas:self-construction:merge-governor', ['action' => 'bogus', '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('unknown_action', $payload['status']);
    }
}
