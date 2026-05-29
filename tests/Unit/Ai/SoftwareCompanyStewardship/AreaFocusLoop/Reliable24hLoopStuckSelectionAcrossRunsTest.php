<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCandidateQuarantineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusFactoryMaxCanonicalBacklogService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusSelfConstructionAdmissionBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoopRunnerService;
use Illuminate\Support\Facades\File;
use ReflectionClassConstant;
use Tests\TestCase;

/**
 * Reproduction for the AP-790 stuck-selection bug (operator rule: "não repetir
 * finding falho sem corrigir causa").
 *
 * A real factory_max run blocked the SAME Self-Construction packet across five
 * cycles (judge_status=repair_required -> merge_performed=false -> blocked) and
 * never advanced to a different admissible packet, stopping only at
 * max_blocked_in_row. ~24 other admissible packets were available.
 *
 * The per-finding blocked-attempt cap (MAX_BLOCKED_ATTEMPTS_PER_FINDING) is a
 * PER-RUN in-memory counter that is reset to [] on every run() and never resumed
 * from the durable ledger. A non-terminal "blocked" outcome does NOT lock the
 * finding across runs (so repair can finish), so when the loop runs as short
 * invocations (e.g. one cycle per run), the cap never accumulates to its
 * threshold and the same admissible-but-repeatedly-blocked packet is re-selected
 * forever.
 *
 * This test drives the runner across multiple run() invocations that share one
 * ledger, with a session runner that selects from the REAL canonical backlog +
 * admission bridge (the source the loop uses) and HONORS session_review_locked
 * exactly as the real AutonomousEvolutionSessionService selection does. It proves
 * the stuck slice stops being re-selected after the cap and a DIFFERENT
 * admissible packet is selected instead.
 */
final class Reliable24hLoopStuckSelectionAcrossRunsTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap790_stuck_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function runnerService(): Reliable24hLoopRunnerService
    {
        $service = app(Reliable24hLoopRunnerService::class);
        $service->setStorageRootForTesting($this->tmp);
        $service->setSleeperForTesting(static fn (int $s): null => null);
        app(AreaFocusCandidateQuarantineService::class)->setStorageRootForTesting($this->tmp.'/quarantine');

        return $service;
    }

    private function maxBlockedAttemptsPerFinding(): int
    {
        return (int) (new ReflectionClassConstant(
            Reliable24hLoopRunnerService::class,
            'MAX_BLOCKED_ATTEMPTS_PER_FINDING',
        ))->getValue();
    }

    /**
     * The REAL admissible packet slice ids the loop's selection can choose from,
     * in the same canonical backlog order the session selection walks. Each
     * canonical high-value finding decomposes into bounded packets; only the FIRST
     * pending packet per parent is selectable per cycle (slice-progression).
     *
     * @return list<string>
     */
    private function realFirstPacketSliceIds(string $areaId, string $focus): array
    {
        $backlog = app(AreaFocusFactoryMaxCanonicalBacklogService::class);
        $bridge = app(AreaFocusSelfConstructionAdmissionBridgeService::class);

        $sliceIds = [];
        foreach ($backlog->findings($areaId, $focus) as $finding) {
            $admission = $bridge->admit(
                $finding,
                'factory_max_rejects_high_risk_deep_finding_without_forge_authority',
                $areaId,
                $focus,
                [],
            );
            $packetFinding = is_array($admission['first_packet_finding'] ?? null)
                ? $admission['first_packet_finding']
                : null;
            $sliceId = $packetFinding !== null ? (string) ($packetFinding['active_slice_id'] ?? '') : '';
            if (($admission['admissible'] ?? false) === true && $sliceId !== '') {
                $sliceIds[] = $sliceId;
            }
        }

        return array_values(array_unique($sliceIds));
    }

    /**
     * A faithful AP-786 session double (NOT runtime authority): it mirrors the real
     * selectCandidate admission behaviour by choosing the FIRST admissible packet
     * slice not present in session_review_locked, then always returns a NON-terminal
     * blocked cycle (judge repair_required). Confined to this test; never wired at
     * runtime. The point is to prove the runner's cross-run cap, not the provider.
     *
     * @param  list<string>  $slicePool
     * @param  list<array{locked:list<string>,selected:string}>  $log
     * @return callable(array<string,mixed>):array<string,mixed>
     */
    private function lockHonoringSessionRunner(array $slicePool, array &$log): callable
    {
        return function (array $input) use ($slicePool, &$log): array {
            $locked = (array) ($input['session_review_locked'] ?? []);
            $terminalLocked = (array) ($input['session_terminal_locked'] ?? []);
            $blocked = $locked + $terminalLocked;

            $selected = '';
            foreach ($slicePool as $sliceId) {
                if (! isset($blocked[$sliceId])) {
                    $selected = $sliceId;
                    break;
                }
            }

            $log[] = ['locked' => array_keys($locked), 'selected' => $selected];

            if ($selected === '') {
                // Honest empty selection — the loop has run out of admissible packets.
                return [
                    'schema_version' => 'atlas.software_company_stewardship.area_focus_autonomous_evolution_session.v1',
                    'status' => 'blocked',
                    'cycles' => [[
                        'cycle_id' => 'cnone',
                        'final_status' => 'blocked',
                        'selected_finding' => [],
                        'merge_performed' => false,
                        'blockers' => ['backlog_exhausted'],
                    ]],
                ];
            }

            return [
                'schema_version' => 'atlas.software_company_stewardship.area_focus_autonomous_evolution_session.v1',
                'status' => 'partial',
                'cycles' => [[
                    'cycle_id' => 'c_'.substr($selected, 0, 8),
                    'final_status' => 'blocked',
                    'selected_finding' => ['active_slice_id' => $selected],
                    'merge_performed' => false,
                    // Non-terminal repair-required block: the real source of the leak —
                    // this re-enters selection so repair can finish its bounded loop.
                    'blockers' => ['owner_runtime_senior_loop_execution_not_passed_repair_required'],
                ]],
            ];
        };
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_replace([
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'execute' => true,
            'continue_on_blocked' => true,
            'max_blocked_in_row' => 50,
            'max_cycles' => 1,
            'max_runtime_minutes' => 1440,
        ], $overrides);
    }

    public function test_a_repeatedly_blocked_packet_is_not_reselected_across_short_runs(): void
    {
        $areaId = 'agentic_engineering_os';
        $focus = 'dev_forge';
        $pool = $this->realFirstPacketSliceIds($areaId, $focus);

        // Sanity: the REAL backlog + admission bridge offer many admissible packets,
        // so advancing off a stuck one is genuinely possible (not a starved backlog).
        $this->assertGreaterThanOrEqual(2, count($pool), 'the real canonical backlog must offer >=2 admissible packets');

        $stuckSlice = $pool[0];
        $cap = $this->maxBlockedAttemptsPerFinding();

        /** @var list<array{locked:list<string>,selected:string}> $log */
        $log = [];
        $sessionRunner = $this->lockHonoringSessionRunner($pool, $log);

        // Simulate the loop being invoked as many short runs (one cycle per run),
        // each a fresh runner sharing the SAME durable ledger. This is the exact
        // shape that defeated the per-run-only blocked-attempt cap.
        $runs = $cap + 4;
        $lastReport = [];
        for ($i = 0; $i < $runs; $i++) {
            $runner = $this->runnerService();
            $runner->setSessionRunnerForTesting($sessionRunner);
            $lastReport = $runner->run($this->input());
        }

        $selections = array_map(static fn (array $row): string => $row['selected'], $log);
        $stuckSelectionCount = count(array_filter($selections, static fn (string $s): bool => $s === $stuckSlice));

        // CORE ASSERTION: a non-terminally-blocked packet must stop being re-selected
        // after the per-finding cap — never re-implemented over and over.
        $this->assertLessThanOrEqual(
            $cap,
            $stuckSelectionCount,
            'a repeatedly-blocked packet must not be re-selected more than MAX_BLOCKED_ATTEMPTS_PER_FINDING times across runs',
        );

        // And the loop must have ADVANCED to a different admissible packet (or stopped
        // honestly), proving the cap routes selection elsewhere instead of stalling.
        $advancedSelections = array_values(array_filter(
            $selections,
            static fn (string $s): bool => $s !== '' && $s !== $stuckSlice,
        ));
        $this->assertNotEmpty(
            $advancedSelections,
            'after the cap the loop must select a DIFFERENT admissible packet',
        );

        $this->assertNotEmpty($lastReport, 'the runner must return a report');
    }

    public function test_the_stuck_packet_is_review_locked_in_the_session_input_after_the_cap(): void
    {
        $areaId = 'agentic_engineering_os';
        $focus = 'dev_forge';
        $pool = $this->realFirstPacketSliceIds($areaId, $focus);
        $this->assertGreaterThanOrEqual(2, count($pool));

        $stuckSlice = $pool[0];
        $cap = $this->maxBlockedAttemptsPerFinding();

        /** @var list<array{locked:list<string>,selected:string}> $log */
        $log = [];
        $sessionRunner = $this->lockHonoringSessionRunner($pool, $log);

        $runs = $cap + 2;
        for ($i = 0; $i < $runs; $i++) {
            $runner = $this->runnerService();
            $runner->setSessionRunnerForTesting($sessionRunner);
            $runner->run($this->input());
        }

        // Once the cap is reached, the runner must inject the stuck slice into the
        // session's session_review_locked map so the SAME loop selection skips it.
        $lockedSomewhere = false;
        foreach ($log as $row) {
            if (in_array($stuckSlice, $row['locked'], true)) {
                $lockedSomewhere = true;
                break;
            }
        }
        $this->assertTrue(
            $lockedSomewhere,
            'after the per-finding cap, the stuck slice must appear in session_review_locked across runs',
        );

        // The very first offer must NOT be pre-locked.
        $this->assertNotContains($stuckSlice, $log[0]['locked'] ?? [], 'the first offer must not be pre-locked');
    }
}
