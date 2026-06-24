<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopEarnedAutonomyDecisionTrace;
use App\Services\Ai\Foundry\Rsi\RsiInvariantGuardService;
use App\Services\Ai\Foundry\Rsi\RsiSelfImprovementProposalGate;
use Tests\TestCase;

/**
 * WIRING — RsiSelfImprovementProposalGate::admit() now appends every GUARD-PASSED gate decision to the
 * provider-safe earned-autonomy decision-trace JSONL (changed paths + decision metadata, NEVER raw diff), under
 * a new envelope key `earned_autonomy_trace`. OBSERVE-ONLY: it rides OUTSIDE the gate_hash, never changes
 * routing/status, and is NEVER invoked on a guard reject. Default-OFF ⇒ trace `[]` + no JSONL + byte-identical.
 */
final class AtlasLoopEarnedAutonomyTraceWiringTest extends TestCase
{
    /** A benign path that passes BOTH the constitution and the invariant guard. */
    private const BENIGN_PATH = 'app/Support/TrivialWidget.php';

    /** A sacred path the invariant guard rejects (mirrors RsiCannotWeakenInvariantTest). */
    private const SACRED_GUARD = 'app/Services/Ai/Foundry/Rsi/RsiInvariantGuardService.php';

    private string $traceRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->traceRoot = sys_get_temp_dir().'/atlas-ea-trace-'.bin2hex(random_bytes(6));
        mkdir($this->traceRoot, 0o755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->traceRoot.'/earned_autonomy_trace.jsonl');
        @rmdir($this->traceRoot);
        parent::tearDown();
    }

    private function gate(): RsiSelfImprovementProposalGate
    {
        return new RsiSelfImprovementProposalGate(
            app(RsiInvariantGuardService::class),
            null,
            null,
            new AtlasLoopEarnedAutonomyDecisionTrace($this->traceRoot),
        );
    }

    private function logPath(): string
    {
        return $this->traceRoot.'/earned_autonomy_trace.jsonl';
    }

    /** @param list<string> $changedPaths */
    private function admit(array $changedPaths): array
    {
        return $this->gate()->admit(
            ['diff' => ['changed_paths' => $changedPaths]],
            ['rsi_mode_enabled' => true],
        );
    }

    // (a) flag OFF ⇒ earned_autonomy_trace === [] and no JSONL is appended; status unchanged.
    public function test_flag_off_trace_is_empty_and_no_jsonl(): void
    {
        config(['atlas.loop.earned_autonomy_decision_trace' => false]);

        $result = $this->admit([self::BENIGN_PATH]);

        $this->assertSame(RsiSelfImprovementProposalGate::STATUS_ROUTED_TO_HUMAN_GATE, $result['status']);
        $this->assertSame([], $result['earned_autonomy_trace'], 'OFF ⇒ trace is []');
        $this->assertFileDoesNotExist($this->logPath(), 'OFF ⇒ no JSONL appended');
    }

    // (b) flag ON ⇒ safe trace (schema + changed_paths, no raw lines), JSONL written, status + gate_hash unchanged.
    public function test_flag_on_records_safe_trace_without_changing_hash_or_routing(): void
    {
        // First capture the OFF baseline hash on the SAME proposal — the trace must NOT change gate_hash.
        config(['atlas.loop.earned_autonomy_decision_trace' => false]);
        $hashOff = $this->admit([self::BENIGN_PATH])['gate_hash'];
        @unlink($this->logPath());

        config(['atlas.loop.earned_autonomy_decision_trace' => true]);
        $result = $this->admit([self::BENIGN_PATH]);

        $this->assertSame(RsiSelfImprovementProposalGate::STATUS_ROUTED_TO_HUMAN_GATE, $result['status'], 'routing is unchanged');
        $this->assertSame($hashOff, $result['gate_hash'], 'the trace rides OUTSIDE gate_hash — byte-identical except the new key');

        $trace = $result['earned_autonomy_trace'];
        $this->assertSame('atlas.loop.earned_autonomy_trace.v1', $trace['schema']);
        $this->assertSame([self::BENIGN_PATH], $trace['changed_paths']);
        $this->assertArrayNotHasKey('added_lines', $trace, 'no raw added lines may ride into the trace');
        $this->assertArrayNotHasKey('removed_lines', $trace, 'no raw removed lines may ride into the trace');

        $this->assertFileExists($this->logPath(), 'ON ⇒ one JSONL line appended');
        $lines = array_filter(explode("\n", (string) file_get_contents($this->logPath())), static fn (string $l): bool => trim($l) !== '');
        $this->assertCount(1, $lines);
    }

    // (c) guard REJECTS ⇒ blocked_by_invariant exactly as today; the recorder is NEVER invoked (no JSONL, no key).
    public function test_guard_reject_never_invokes_the_trace(): void
    {
        config(['atlas.loop.earned_autonomy_decision_trace' => true]); // even ON, a reject must not record

        $result = $this->admit([self::SACRED_GUARD]);

        $this->assertSame(RsiSelfImprovementProposalGate::STATUS_BLOCKED_BY_INVARIANT, $result['status']);
        $this->assertArrayNotHasKey('earned_autonomy_trace', $result, 'a guard reject returns before the trace — key absent (byte-identical to today)');
        $this->assertFileDoesNotExist($this->logPath(), 'the recorder is never invoked on a guard reject (zero calls)');
    }
}
