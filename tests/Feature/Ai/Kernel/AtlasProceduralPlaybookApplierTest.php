<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Kernel;

use App\Services\Ai\Kernel\Procedural\AtlasProceduralPlaybookApplier;
use App\Services\Ai\Kernel\Procedural\AtlasProceduralPlaybookLedger;
use App\Services\Ai\Kernel\Procedural\ProceduralPlaybook;
use Tests\TestCase;

/**
 * ATLAS BUILD #3 SLICE 2 gate: a task matches its playbook, RECEIVES the
 * injection (steps + forbidden + postconditions), and the REAL outcome of
 * following it updates the MEASURED rate — routed through the same
 * OutcomeProofGate, so a fake-green can never earn a success credit.
 */
class AtlasProceduralPlaybookApplierTest extends TestCase
{
    private function ledger(): AtlasProceduralPlaybookLedger
    {
        $path = tempnam(sys_get_temp_dir(), 'procedural_applier_').'.jsonl';
        @unlink($path);

        return new AtlasProceduralPlaybookLedger($path);
    }

    private function seedPlaybook(AtlasProceduralPlaybookLedger $ledger): void
    {
        $ledger->define(new ProceduralPlaybook(
            taskCategory: 'backend_bugfix',
            objective: 'root-cause fix',
            steps: ['reproduce with a failing test', 'grep all callers'],
            postconditions: ['failing test now passes'],
            forbiddenActions: ['edit the test to hide the bug'],
        ));
    }

    public function test_apply_injects_steps_forbidden_and_postconditions(): void
    {
        $ledger = $this->ledger();
        $this->seedPlaybook($ledger);

        $result = (new AtlasProceduralPlaybookApplier($ledger))->apply('backend_bugfix');

        $this->assertNotNull($result);
        $this->assertNotSame('', $result['application_id']);
        $injection = $result['injection'];
        $this->assertStringContainsString('reproduce with a failing test', $injection);
        $this->assertStringContainsString('grep all callers', $injection);
        $this->assertStringContainsString('edit the test to hide the bug', $injection);
        $this->assertStringContainsString('failing test now passes', $injection);

        // The attempt is recorded → measured, not unmeasured.
        $rate = $ledger->rateFor('backend_bugfix');
        $this->assertSame('measured', $rate['status']);
        $this->assertSame(1, $rate['attempts']);
        $this->assertSame(0, $rate['successes']);
    }

    public function test_apply_no_match_returns_null_and_stays_unmeasured(): void
    {
        $ledger = $this->ledger();

        $this->assertNull((new AtlasProceduralPlaybookApplier($ledger))->apply('never_defined'));
        $this->assertSame('unmeasured', $ledger->rateFor('never_defined')['status']);
    }

    public function test_proven_real_success_updates_measured_rate(): void
    {
        $ledger = $this->ledger();
        $this->seedPlaybook($ledger);
        $applier = new AtlasProceduralPlaybookApplier($ledger);

        $result = $applier->apply('backend_bugfix');
        $verdict = $ledger->recordOutcome($result['application_id'], 'success', [
            'commands' => ['php artisan test tests/Feature/Foo'],
            'tests_run' => 3,
            'assertions_executed' => 9,
        ]);

        $this->assertTrue($verdict['proven_real']);
        $this->assertTrue($verdict['credited']);
        $this->assertFalse($verdict['fake_green']);

        $rate = $ledger->rateFor('backend_bugfix');
        $this->assertSame(1, $rate['attempts']);
        $this->assertSame(1, $rate['successes']);
        $this->assertSame(1.0, $rate['success_rate']);
    }

    public function test_fake_green_success_is_suppressed_never_credited(): void
    {
        $ledger = $this->ledger();
        $this->seedPlaybook($ledger);
        $applier = new AtlasProceduralPlaybookApplier($ledger);

        $result = $applier->apply('backend_bugfix');
        // Claims success but ZERO tests executed → fake-green.
        $verdict = $ledger->recordOutcome($result['application_id'], 'success', [
            'commands' => ['php -l file.php'],
            'tests_run' => 0,
            'assertions_executed' => 0,
        ]);

        $this->assertFalse($verdict['credited']);
        $this->assertTrue($verdict['fake_green']);

        $rate = $ledger->rateFor('backend_bugfix');
        $this->assertSame(1, $rate['attempts']);
        $this->assertSame(0, $rate['successes']);
        $this->assertSame(0.0, $rate['success_rate']);
        $this->assertSame(1, $rate['fake_green_suppressed']);
    }

    public function test_real_failure_counts_as_attempt_without_success(): void
    {
        $ledger = $this->ledger();
        $this->seedPlaybook($ledger);
        $applier = new AtlasProceduralPlaybookApplier($ledger);

        $result = $applier->apply('backend_bugfix');
        $verdict = $ledger->recordOutcome($result['application_id'], 'failed', [
            'commands' => ['php artisan test'],
            'tests_run' => 3,
            'assertions_executed' => 4,
        ]);

        $this->assertFalse($verdict['credited']);
        $this->assertFalse($verdict['fake_green']); // a truthful failure is not a fake-green

        $rate = $ledger->rateFor('backend_bugfix');
        $this->assertSame(1, $rate['attempts']);
        $this->assertSame(0, $rate['successes']);
    }

    public function test_outcome_without_open_application_is_noop(): void
    {
        $ledger = $this->ledger();
        $this->seedPlaybook($ledger);

        $verdict = $ledger->recordOutcome('never_applied', 'success', ['tests_run' => 5, 'assertions_executed' => 5]);
        $this->assertFalse($verdict['credited']);
        $this->assertSame('no_open_application', $verdict['reason']);
    }
}
