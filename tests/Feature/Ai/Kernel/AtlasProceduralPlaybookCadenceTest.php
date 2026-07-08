<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Kernel;

use App\Services\Ai\Kernel\Procedural\AtlasProceduralPlaybookApplier;
use App\Services\Ai\Kernel\Procedural\AtlasProceduralPlaybookLedger;
use App\Services\Ai\Kernel\Procedural\ProceduralPlaybook;
use Tests\TestCase;

/**
 * ATLAS cadence-reader gate: the per-playbook visibility read model reports the
 * REAL ledger counters — injections (attempts), success rate, corrections fired
 * — and an honest zero (empty / unmeasured) before any real use, never a
 * fabricated number.
 */
class AtlasProceduralPlaybookCadenceTest extends TestCase
{
    private function ledger(): AtlasProceduralPlaybookLedger
    {
        $path = tempnam(sys_get_temp_dir(), 'procedural_cadence_').'.jsonl';
        @unlink($path);

        return new AtlasProceduralPlaybookLedger($path);
    }

    public function test_empty_ledger_is_honest_zero(): void
    {
        $this->assertSame([], $this->ledger()->cadence());
    }

    public function test_defined_but_never_applied_is_unmeasured(): void
    {
        $ledger = $this->ledger();
        $ledger->define(new ProceduralPlaybook('repair', 'fix', ['step'], ['pc'], ['no']));

        $cadence = $ledger->cadence();
        $this->assertCount(1, $cadence);
        $this->assertSame('repair', $cadence[0]['task_category']);
        $this->assertSame('unmeasured', $cadence[0]['status']);
        $this->assertSame(0, $cadence[0]['attempts']);
        $this->assertSame(0, $cadence[0]['corrections']);
    }

    public function test_reports_real_counters_per_playbook(): void
    {
        $ledger = $this->ledger();
        $applier = new AtlasProceduralPlaybookApplier($ledger);
        $ledger->define(new ProceduralPlaybook('repair', 'fix', ['step'], ['pc'], ['no']));
        $ledger->define(new ProceduralPlaybook('frontend', 'ui', ['step'], ['pc'], ['no']));

        // repair: one proven success.
        $a1 = $applier->apply('repair');
        $ledger->recordOutcome($a1['application_id'], 'success', ['tests_run' => 3, 'assertions_executed' => 9]);

        // repair: one proven failure → seeds a correction.
        $a2 = $applier->apply('repair');
        $ledger->recordOutcome($a2['application_id'], 'failed', ['tests_run' => 2, 'assertions_executed' => 2]);
        $ledger->recordFailureCorrection($a2['application_id'], 'grep callers first');

        // frontend: one injection, no outcome yet.
        $applier->apply('frontend');

        $cadence = $ledger->cadence();
        $this->assertCount(2, $cadence);

        // Sorted by category → frontend, repair.
        [$frontend, $repair] = $cadence;

        $this->assertSame('frontend', $frontend['task_category']);
        $this->assertSame(1, $frontend['attempts']);
        $this->assertSame(0, $frontend['successes']);
        $this->assertSame(0, $frontend['corrections']);

        $this->assertSame('repair', $repair['task_category']);
        $this->assertSame(2, $repair['attempts']);
        $this->assertSame(1, $repair['successes']);
        $this->assertSame(0.5, $repair['success_rate']);
        $this->assertSame(1, $repair['corrections']);
    }

    public function test_stray_outcome_without_define_is_not_reported(): void
    {
        $ledger = $this->ledger();
        // An applied/outcome for a category that was never defined must not
        // manufacture a cadence row.
        $ledger->recordApplied('app-x', 'ghost');
        $ledger->recordOutcome('app-x', 'success', ['tests_run' => 1, 'assertions_executed' => 1]);

        $this->assertSame([], $ledger->cadence());
    }
}
