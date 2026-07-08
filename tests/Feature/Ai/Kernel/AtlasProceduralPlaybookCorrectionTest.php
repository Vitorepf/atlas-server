<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Kernel;

use App\Services\Ai\Kernel\Procedural\AtlasProceduralPlaybookApplier;
use App\Services\Ai\Kernel\Procedural\AtlasProceduralPlaybookLedger;
use App\Services\Ai\Kernel\Procedural\ProceduralPlaybook;
use Tests\TestCase;

/**
 * ATLAS BUILD #3 SLICE 3 gate: a REAL, proven failure of following the playbook
 * produces a prior-correction that CHANGES the next retrieval/injection — the
 * delta over a static checklist. Corrections derive only from a proven failure
 * on record; they can never be fabricated.
 */
class AtlasProceduralPlaybookCorrectionTest extends TestCase
{
    private function ledger(): AtlasProceduralPlaybookLedger
    {
        $path = tempnam(sys_get_temp_dir(), 'procedural_correction_').'.jsonl';
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

    private const CORRECTION = 'step "grep all callers" was skipped → always grep callers before editing the shared fn';

    public function test_real_failure_produces_correction_that_changes_next_retrieval(): void
    {
        $ledger = $this->ledger();
        $this->seedPlaybook($ledger);
        $applier = new AtlasProceduralPlaybookApplier($ledger);

        // Before any failure: retrieval has no prior corrections.
        $this->assertSame([], $ledger->retrieve('backend_bugfix')->priorCorrections);

        // Apply, then a REAL failure of following it.
        $app = $applier->apply('backend_bugfix');
        $verdict = $ledger->recordOutcome($app['application_id'], 'failed', [
            'commands' => ['php artisan test'],
            'tests_run' => 3,
            'assertions_executed' => 4,
        ]);
        $this->assertFalse($verdict['credited']);
        $this->assertFalse($verdict['fake_green']);

        // The failure seeds a prior-correction.
        $this->assertTrue($ledger->recordFailureCorrection($app['application_id'], self::CORRECTION));

        // NEXT retrieval is CHANGED — the correction is folded in.
        $next = $ledger->retrieve('backend_bugfix');
        $this->assertSame([self::CORRECTION], $next->priorCorrections);

        // And the NEXT injection carries it (the recovery actually differs).
        $nextInjection = $applier->apply('backend_bugfix')['injection'];
        $this->assertStringContainsString('Correcoes de priors', $nextInjection);
        $this->assertStringContainsString(self::CORRECTION, $nextInjection);
    }

    public function test_correction_rejected_when_outcome_was_a_success(): void
    {
        $ledger = $this->ledger();
        $this->seedPlaybook($ledger);
        $applier = new AtlasProceduralPlaybookApplier($ledger);

        $app = $applier->apply('backend_bugfix');
        $ledger->recordOutcome($app['application_id'], 'success', ['tests_run' => 3, 'assertions_executed' => 9]);

        // No real failure → no correction (never fabricated).
        $this->assertFalse($ledger->recordFailureCorrection($app['application_id'], self::CORRECTION));
        $this->assertSame([], $ledger->retrieve('backend_bugfix')->priorCorrections);
    }

    public function test_correction_rejected_when_outcome_was_a_fake_green(): void
    {
        $ledger = $this->ledger();
        $this->seedPlaybook($ledger);
        $applier = new AtlasProceduralPlaybookApplier($ledger);

        $app = $applier->apply('backend_bugfix');
        // Claims success with ZERO tests → fake-green, not a real failure.
        $ledger->recordOutcome($app['application_id'], 'success', ['tests_run' => 0, 'assertions_executed' => 0]);

        $this->assertFalse($ledger->recordFailureCorrection($app['application_id'], self::CORRECTION));
        $this->assertSame([], $ledger->retrieve('backend_bugfix')->priorCorrections);
    }

    public function test_correction_rejected_when_no_outcome_recorded(): void
    {
        $ledger = $this->ledger();
        $this->seedPlaybook($ledger);
        $applier = new AtlasProceduralPlaybookApplier($ledger);

        $app = $applier->apply('backend_bugfix'); // applied, but never outcome'd

        $this->assertFalse($ledger->recordFailureCorrection($app['application_id'], self::CORRECTION));
        $this->assertFalse($ledger->recordFailureCorrection('totally_unknown', self::CORRECTION));
    }

    public function test_empty_correction_is_never_stored(): void
    {
        $ledger = $this->ledger();
        $this->seedPlaybook($ledger);
        $applier = new AtlasProceduralPlaybookApplier($ledger);

        $app = $applier->apply('backend_bugfix');
        $ledger->recordOutcome($app['application_id'], 'failed', ['tests_run' => 2, 'assertions_executed' => 2]);

        $this->assertFalse($ledger->recordFailureCorrection($app['application_id'], '   '));
        $this->assertSame([], $ledger->retrieve('backend_bugfix')->priorCorrections);
    }
}
