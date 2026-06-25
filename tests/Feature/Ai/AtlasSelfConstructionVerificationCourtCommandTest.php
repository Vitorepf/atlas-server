<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtFalseGreenDetector;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtVerdictLedger;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves atlas:self-construction:verification-court: inspect lists services; plan emits replay plan
 * facts; verdict over green replay outcomes ⇒ verdict=passed; verdict over a red outcome ⇒
 * verdict=failed with replay_red:<id>; history reads ledger rows; unknown verb returns unknown_action.
 */
final class AtlasSelfConstructionVerificationCourtCommandTest extends TestCase
{
    private string $evidencePath;

    private string $replayPath;

    private string $ledgerPath;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(6));
        $this->evidencePath = sys_get_temp_dir().'/atlas_court_evidence_'.$tag.'.json';
        $this->replayPath = sys_get_temp_dir().'/atlas_court_replay_'.$tag.'.json';
        $this->ledgerPath = sys_get_temp_dir().'/atlas_court_ledger_'.$tag.'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->evidencePath);
        @unlink($this->replayPath);
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function writeEvidence(array $e): void
    {
        file_put_contents($this->evidencePath, json_encode($e, JSON_UNESCAPED_SLASHES));
    }

    private function writeReplay(array $r): void
    {
        file_put_contents($this->replayPath, json_encode($r, JSON_UNESCAPED_SLASHES));
    }

    private function validEvidence(): array
    {
        return [
            'task_packet_id' => 'pkt-1',
            'lease_id' => 'lease_01XYZ',
            'files_changed' => ['app/Foo.php'],
            'commands_run' => [['name' => 'phpunit', 'exit_code' => 0]],
            'tests_or_gates_result' => ['passed' => true, 'gate' => 'phpunit'],
            'evidence_hash' => 'evh-1',
            'scope_deviations' => [],
            'residual_risks' => [],
            'runtime_owner' => 'atlas_native',
            'allowed_files' => ['app/Foo.php'],
            'packet_facts' => ['declared_gates' => []],
            'risk_level' => 'low',
        ];
    }

    public function test_inspect_lists_services_and_non_execution_guarantees(): void
    {
        $exit = Artisan::call('atlas:self-construction:verification-court', ['action' => 'inspect', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exit);
        $this->assertSame('ok', $p['status']);
        $this->assertContains(AtlasVerificationCourtVerdictLedger::SCHEMA, $p['services']);
        $this->assertFalse($p['non_execution_guarantees']['runs_replay_commands']);
    }

    public function test_plan_reads_evidence_and_emits_replay_plan_facts(): void
    {
        $this->writeEvidence($this->validEvidence());
        Artisan::call('atlas:self-construction:verification-court', ['action' => 'plan', '--evidence' => $this->evidencePath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('ok', $p['status']);
        $this->assertTrue($p['evidence_contract']['accepted']);
        $this->assertNotEmpty($p['replay_plan']['commands']);
    }

    public function test_verdict_pass_over_green_replay_outcomes(): void
    {
        $this->writeEvidence($this->validEvidence());
        // Use the plan once to harvest the command_ids, then feed matching outcomes.
        Artisan::call('atlas:self-construction:verification-court', ['action' => 'plan', '--evidence' => $this->evidencePath, '--json' => true]);
        $plan = json_decode(trim(Artisan::output()), true);
        $outcomes = [];
        foreach ($plan['replay_plan']['commands'] as $cmd) {
            $outcomes[] = ['command_id' => $cmd['id'], 'passed' => true, 'output_present' => true];
        }
        $this->writeReplay(['outcomes' => $outcomes]);

        Artisan::call('atlas:self-construction:verification-court', ['action' => 'verdict', '--evidence' => $this->evidencePath, '--replay' => $this->replayPath, '--json' => true]);
        $v = json_decode(trim(Artisan::output()), true);
        $this->assertSame(AtlasVerificationCourtFalseGreenDetector::VERDICT_PASSED, $v['detector']['verdict']);
    }

    public function test_verdict_fail_when_any_replay_outcome_is_red(): void
    {
        $this->writeEvidence($this->validEvidence());
        Artisan::call('atlas:self-construction:verification-court', ['action' => 'plan', '--evidence' => $this->evidencePath, '--json' => true]);
        $plan = json_decode(trim(Artisan::output()), true);
        $outcomes = [];
        foreach ($plan['replay_plan']['commands'] as $i => $cmd) {
            $outcomes[] = ['command_id' => $cmd['id'], 'passed' => $i !== 0, 'output_present' => true];
        }
        $this->writeReplay(['outcomes' => $outcomes]);

        Artisan::call('atlas:self-construction:verification-court', ['action' => 'verdict', '--evidence' => $this->evidencePath, '--replay' => $this->replayPath, '--json' => true]);
        $v = json_decode(trim(Artisan::output()), true);
        $this->assertSame(AtlasVerificationCourtFalseGreenDetector::VERDICT_FAILED, $v['detector']['verdict']);
    }

    public function test_history_reads_ledger_rows_without_writing(): void
    {
        $evidence = $this->validEvidence();
        $evidence['ledger_envelope'] = [
            'task_packet_id' => 'pkt-1',
            'evidence_hash' => 'evh-1',
            'replay_plan_hash' => 'plan-h-1',
            'verdict' => AtlasVerificationCourtFalseGreenDetector::VERDICT_PASSED,
            'reasons' => [],
            'replay_outcome_hash' => 'out-h-1',
            'decided_at' => '2026-06-25T00:00:00Z',
        ];
        $this->writeEvidence($evidence);
        $this->writeReplay(['outcomes' => []]);
        Artisan::call('atlas:self-construction:verification-court', ['action' => 'verdict', '--evidence' => $this->evidencePath, '--replay' => $this->replayPath, '--ledger' => $this->ledgerPath, '--json' => true]);

        Artisan::call('atlas:self-construction:verification-court', ['action' => 'history', '--ledger' => $this->ledgerPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('ok', $p['status']);
        $this->assertSame('pkt-1', $p['rows'][0]['task_packet_id']);
    }

    public function test_unknown_action_returns_unknown_action(): void
    {
        $exit = Artisan::call('atlas:self-construction:verification-court', ['action' => 'bogus', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('unknown_action', $p['status']);
    }
}
