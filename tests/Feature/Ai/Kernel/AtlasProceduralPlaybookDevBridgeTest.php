<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Kernel;

use App\Services\Ai\Kernel\Procedural\AtlasProceduralPlaybookDevBridge;
use App\Services\Ai\Kernel\Procedural\AtlasProceduralPlaybookLedger;
use App\Services\Ai\Kernel\Procedural\ProceduralPlaybook;
use Tests\TestCase;

/**
 * ATLAS producer gate: the bridge that wires the general procedural playbook
 * into the Dev flow. A synthetic Dev task (keyed by run id) matches + injects a
 * playbook (advisory lines) at task-START, and its REAL outcome at task-END
 * moves the measured follow rate unmeasured→measured (same proven_real gate) and
 * seeds a prior-correction on a proven failure. Kill-switch => byte-identical
 * no-op.
 */
class AtlasProceduralPlaybookDevBridgeTest extends TestCase
{
    private function bridge(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'playbook_bridge_').'.jsonl';
        @unlink($path);
        $ledger = new AtlasProceduralPlaybookLedger($path);

        return [new AtlasProceduralPlaybookDevBridge($ledger), $ledger];
    }

    private function definePlaybook(AtlasProceduralPlaybookLedger $ledger): void
    {
        $ledger->define(new ProceduralPlaybook(
            taskCategory: 'repair',
            objective: 'fix the bug at its root',
            steps: ['reproduce with a failing test', 'grep all callers'],
            postconditions: ['failing test now passes'],
            forbiddenActions: ['edit the test to hide the bug'],
        ));
    }

    public function test_task_start_injects_advisory_lines_and_records_the_attempt(): void
    {
        [$bridge, $ledger] = $this->bridge();
        $this->definePlaybook($ledger);

        $this->assertSame('unmeasured', $ledger->rateFor('repair')['status']);

        $lines = $bridge->injectionLinesForTask('run-1', 'repair');

        $this->assertNotEmpty($lines);
        $joined = implode("\n", $lines);
        $this->assertStringContainsString('reproduce with a failing test', $joined);
        $this->assertStringContainsString('NAO fazer: edit the test to hide the bug', $joined);
        $this->assertStringContainsString('pos-condicao: failing test now passes', $joined);

        // The attempt is recorded (denominator) → measured, not unmeasured.
        $rate = $ledger->rateFor('repair');
        $this->assertSame('measured', $rate['status']);
        $this->assertSame(1, $rate['attempts']);
        $this->assertSame(0, $rate['successes']);
    }

    public function test_proven_real_outcome_moves_the_measured_rate(): void
    {
        [$bridge, $ledger] = $this->bridge();
        $this->definePlaybook($ledger);

        $bridge->injectionLinesForTask('run-2', 'repair');
        $bridge->recordOutcomeForTask('run-2', 'success', ['commands' => ['php artisan test'], 'tests_run' => 3, 'assertions_executed' => 9]);

        $rate = $ledger->rateFor('repair');
        $this->assertSame(1, $rate['attempts']);
        $this->assertSame(1, $rate['successes']);
        $this->assertSame(1.0, $rate['success_rate']);
    }

    public function test_no_playbook_for_category_injects_nothing_and_stays_unmeasured(): void
    {
        [$bridge, $ledger] = $this->bridge();
        // No playbook defined for 'frontend'.

        $this->assertSame([], $bridge->injectionLinesForTask('run-3', 'frontend'));
        $this->assertSame('unmeasured', $ledger->rateFor('frontend')['status']);
    }

    public function test_proven_failure_seeds_a_correction_that_changes_next_injection(): void
    {
        [$bridge, $ledger] = $this->bridge();
        $this->definePlaybook($ledger);

        $bridge->injectionLinesForTask('run-4', 'repair');
        $bridge->recordOutcomeForTask('run-4', 'failed', ['commands' => ['php artisan test'], 'tests_run' => 2, 'assertions_executed' => 2], ['app/Services/Foo.php']);

        // The next task-START injection for this category now carries the
        // prior-correction derived from the real failure.
        $next = implode("\n", $bridge->injectionLinesForTask('run-5', 'repair'));
        $this->assertStringContainsString('correcao de prior', $next);
        $this->assertStringContainsString('app/Services/Foo.php', $next);
    }

    public function test_fake_green_outcome_is_not_credited_and_seeds_no_correction(): void
    {
        [$bridge, $ledger] = $this->bridge();
        $this->definePlaybook($ledger);

        $bridge->injectionLinesForTask('run-6', 'repair');
        // Claims success with ZERO tests → fake-green.
        $bridge->recordOutcomeForTask('run-6', 'success', ['commands' => ['php -l x.php'], 'tests_run' => 0, 'assertions_executed' => 0]);

        $rate = $ledger->rateFor('repair');
        $this->assertSame(0, $rate['successes']);
        $this->assertSame(1, $rate['fake_green_suppressed']);

        // A fake-green is not a real failure → no prior-correction.
        $next = implode("\n", $bridge->injectionLinesForTask('run-7', 'repair'));
        $this->assertStringNotContainsString('correcao de prior', $next);
    }

    public function test_kill_switch_disables_injection_and_outcome(): void
    {
        [$bridge, $ledger] = $this->bridge();
        $this->definePlaybook($ledger);
        config()->set('atlas_dev.procedural_playbook.enabled', false);

        $this->assertSame([], $bridge->injectionLinesForTask('run-8', 'repair'));
        $bridge->recordOutcomeForTask('run-8', 'success', ['tests_run' => 3, 'assertions_executed' => 9]);

        $this->assertSame('unmeasured', $ledger->rateFor('repair')['status']);
    }
}
