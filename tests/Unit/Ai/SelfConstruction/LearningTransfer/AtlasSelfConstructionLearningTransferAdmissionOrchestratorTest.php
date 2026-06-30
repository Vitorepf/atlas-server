<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\LearningTransfer;

use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningTransferAdmissionLedger;
use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningTransferAdmissionOrchestrator;
use Tests\TestCase;

class AtlasSelfConstructionLearningTransferAdmissionOrchestratorTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-lt-orchestrator-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function orchestrator(): AtlasSelfConstructionLearningTransferAdmissionOrchestrator
    {
        return new AtlasSelfConstructionLearningTransferAdmissionOrchestrator(
            ledger: new AtlasSelfConstructionLearningTransferAdmissionLedger($this->ledgerPath),
        );
    }

    private function admittableFact(string $reason = 'duplicate_capability'): array
    {
        return [
            'task_packet_id' => 'pkt-A',
            'reason' => $reason,
            'allowed_files' => ['app/Foo.php'],
            'blocking_facts' => ['observed_already_exists'],
            'evidence_refs' => ['evidence://1', 'evidence://2', 'evidence://3'],
            'observations' => [
                ['outcome' => 'give_back', 'evidence_refs' => ['evidence://1']],
                ['outcome' => 'give_back', 'evidence_refs' => ['evidence://2']],
                ['outcome' => 'give_back', 'evidence_refs' => ['evidence://3']],
            ],
        ];
    }

    public function test_admit_chains_classifier_gate_planner_updater_and_records_to_ledger(): void
    {
        $o = $this->orchestrator();
        $r = $o->admit($this->admittableFact(), ['acceptance_criteria' => []]);

        self::assertSame('admitted_and_recorded', $r['outcome']);
        self::assertSame('admit', $r['gate_decision']['decision']);
        self::assertNotNull($r['plan']);
        self::assertNotNull($r['ledger']);
        self::assertSame('recorded', $r['ledger']['status']);
    }

    public function test_non_admit_gate_short_circuits_before_planner(): void
    {
        $o = $this->orchestrator();
        // Reason maps to a known class but with NO observations to fail the repetition threshold.
        $fact = [
            'task_packet_id' => 'pkt-B',
            'reason' => 'scope_gap',
            'observations' => [['outcome' => 'one_off', 'evidence_refs' => []]],
        ];
        $r = $o->admit($fact);
        self::assertSame('short_circuited_at_gate', $r['outcome']);
        self::assertNull($r['plan']);
        self::assertNull($r['ledger']);
    }

    public function test_admit_is_deterministic_byte_identical_json(): void
    {
        $a = $this->orchestrator()->admit($this->admittableFact());
        // Drop volatile per-row recorded_at + ledger status by stripping ledger.
        unset($a['ledger']);
        $b = $this->orchestrator()->admit($this->admittableFact());
        unset($b['ledger']);
        self::assertSame(json_encode($a), json_encode($b));
    }

    public function test_ledger_idempotent_second_admit_reports_already_recorded(): void
    {
        $ledger = new AtlasSelfConstructionLearningTransferAdmissionLedger($this->ledgerPath);
        $o = new AtlasSelfConstructionLearningTransferAdmissionOrchestrator(ledger: $ledger);
        $first = $o->admit($this->admittableFact());
        $second = $o->admit($this->admittableFact());

        self::assertSame('recorded', $first['ledger']['status']);
        self::assertSame('already_recorded', $second['ledger']['status']);

        $lines = file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(1, $lines);
    }

    public function test_observe_mode_with_no_template_records_intended_action_only(): void
    {
        $r = $this->orchestrator()->admit($this->admittableFact());
        self::assertSame('observe', $r['mode']);
        self::assertSame(['observe_mode_no_template_provided' => true], $r['template_after']);
    }

    public function test_default_collaborators_are_instantiated_when_not_injected(): void
    {
        // No constructor args at all — should not throw.
        $o = new AtlasSelfConstructionLearningTransferAdmissionOrchestrator();
        $r = $o->admit($this->admittableFact());
        self::assertArrayHasKey('classification', $r);
        self::assertArrayHasKey('gate_decision', $r);
    }

    public function test_envelope_includes_stable_lesson_key(): void
    {
        $r = $this->orchestrator()->admit($this->admittableFact());

        self::assertArrayHasKey('lesson_key', $r);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $r['lesson_key']);
        // Same input → same key across independent orchestrator instances.
        $r2 = $this->orchestrator()->admit($this->admittableFact());
        self::assertSame($r['lesson_key'], $r2['lesson_key']);
    }

    public function test_duplicate_lesson_key_in_template_snapshot_short_circuits(): void
    {
        // First call to obtain the lesson_key.
        $first = $this->orchestrator()->admit($this->admittableFact());
        $lessonKey = $first['lesson_key'];
        self::assertNotEmpty($lessonKey);

        // Second call with the key listed in known_lesson_keys → duplicate_observed.
        $second = $this->orchestrator()->admit(
            $this->admittableFact(),
            ['known_lesson_keys' => [$lessonKey]],
        );
        self::assertSame('duplicate_observed', $second['outcome']);
        self::assertSame($lessonKey, $second['lesson_key']);
        self::assertNull($second['plan']);
        self::assertNull($second['ledger']);
    }
}
