<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\LiveCycle\Integration;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Throwable;

/**
 * Drives the canonical 8-phase Loop cycle (orient -> comprehend -> decide-leverage -> architect -> decompose
 * -> implement -> certify -> close-on-main) sequentially with a byte-verifiable per-phase receipt chain.
 *
 * Each phase emits a FACT:
 *   {phase_id, phase_index, prev_receipt_hash, started_at, completed_at, status: ok|fail, error?:string}
 * `prev_receipt_hash` is sha256 of the previous emitted receipt JSON, computed identically by the orchestrator
 * and reproducible by an auditor that only sees the FACT log.
 *
 * On phase failure the cycle halts, emits a fail FACT, and persists last good phase index for resume.
 *
 * Master switch fail-closed: when ATLAS_LOOP_MASTER_ENABLED is OFF, run() is a byte-identical no-op — it
 * returns the off-status envelope without invoking any phase callable, emitting any FACT, or touching any
 * sub-ledger. The off return value is canonical (constant keys / constant order).
 *
 * The phase executors are passed in by the caller (the real driver wires them; the test uses stubs). This is
 * the seam that lets the canonical phase contract be tested independently of the live tier executors.
 */
final class AtlasLoopLiveCycleOrchestrator
{
    public const PHASES = [
        'orient',
        'comprehend',
        'decide-leverage',
        'architect',
        'decompose',
        'implement',
        'certify',
        'close-on-main',
    ];

    public const STATUS_OK = 'ok';

    public const STATUS_FAIL = 'fail';

    public const STATUS_OFF = 'off';

    private const GENESIS_PREV_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * @param  array<string, callable(array<string,mixed>):array<string,mixed>>  $phaseExecutors
     *         Keyed by phase id. Missing keys are silently treated as no-op success.
     * @param  callable():int|null  $clock  Unix-seconds clock (defaults to time()).
     * @param  callable(array<string,mixed>):void|null  $factSink   Optional FACT sink (e.g. attempt ledger appender).
     * @return array{
     *     status:string,
     *     cycle_id:string,
     *     phases_completed:int,
     *     last_good_phase_index:int,
     *     facts:list<array<string,mixed>>,
     *     final_phase:?string,
     *     error:?string
     * }
     */
    public function run(string $cycleId, array $phaseExecutors, ?callable $clock = null, ?callable $factSink = null): array
    {
        if (! AtlasLoopMasterSwitch::enabled()) {
            return $this->offEnvelope();
        }

        $clock ??= static fn (): int => time();
        $facts = [];
        $prevHash = self::GENESIS_PREV_HASH;
        $lastGoodIndex = 0;

        foreach (self::PHASES as $i => $phaseId) {
            $phaseIndex = $i + 1;
            $startedAt = (int) $clock();

            try {
                $executor = $phaseExecutors[$phaseId] ?? null;
                if (is_callable($executor)) {
                    $executor(['cycle_id' => $cycleId, 'phase_id' => $phaseId, 'phase_index' => $phaseIndex]);
                }
                $completedAt = (int) $clock();
                $fact = [
                    'cycle_id' => $cycleId,
                    'phase_id' => $phaseId,
                    'phase_index' => $phaseIndex,
                    'prev_receipt_hash' => $prevHash,
                    'started_at' => $startedAt,
                    'completed_at' => $completedAt,
                    'status' => self::STATUS_OK,
                ];
            } catch (Throwable $e) {
                $completedAt = (int) $clock();
                $fact = [
                    'cycle_id' => $cycleId,
                    'phase_id' => $phaseId,
                    'phase_index' => $phaseIndex,
                    'prev_receipt_hash' => $prevHash,
                    'started_at' => $startedAt,
                    'completed_at' => $completedAt,
                    'status' => self::STATUS_FAIL,
                    'error' => mb_substr($e->getMessage(), 0, 240),
                ];

                $this->emit($fact, $factSink);
                $facts[] = $fact;

                return [
                    'status' => self::STATUS_FAIL,
                    'cycle_id' => $cycleId,
                    'phases_completed' => $lastGoodIndex,
                    'last_good_phase_index' => $lastGoodIndex,
                    'facts' => $facts,
                    'final_phase' => $phaseId,
                    'error' => $fact['error'],
                ];
            }

            $this->emit($fact, $factSink);
            $facts[] = $fact;
            $prevHash = $this->hashReceipt($fact);
            $lastGoodIndex = $phaseIndex;
        }

        return [
            'status' => self::STATUS_OK,
            'cycle_id' => $cycleId,
            'phases_completed' => $lastGoodIndex,
            'last_good_phase_index' => $lastGoodIndex,
            'facts' => $facts,
            'final_phase' => self::PHASES[count(self::PHASES) - 1],
            'error' => null,
        ];
    }

    /**
     * Recompute the receipt-chain hash sequence from a FACT log. Returns the list of expected
     * prev_receipt_hash values per receipt; an auditor compares these against the recorded values.
     *
     * @param  list<array<string,mixed>>  $facts
     * @return list<string>
     */
    public static function recomputeChain(array $facts): array
    {
        $expected = [];
        $prev = self::GENESIS_PREV_HASH;
        foreach ($facts as $fact) {
            $expected[] = $prev;
            if ((string) ($fact['status'] ?? '') === self::STATUS_OK) {
                $prev = self::hashFact($fact);
            }
        }

        return $expected;
    }

    /**
     * @param  array<string,mixed>  $fact
     */
    private function emit(array $fact, ?callable $factSink): void
    {
        if ($factSink !== null) {
            $factSink($fact);
        }
    }

    /**
     * @param  array<string,mixed>  $fact
     */
    private function hashReceipt(array $fact): string
    {
        return self::hashFact($fact);
    }

    /**
     * @param  array<string,mixed>  $fact
     */
    public static function hashFact(array $fact): string
    {
        $canonical = self::canonicalize($fact);

        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private static function canonicalize(array $row): array
    {
        ksort($row);
        foreach ($row as $k => $v) {
            if (is_array($v)) {
                $row[$k] = self::canonicalize($v);
            }
        }

        return $row;
    }

    /**
     * @return array{status:string,cycle_id:string,phases_completed:int,last_good_phase_index:int,facts:list<array<string,mixed>>,final_phase:?string,error:?string}
     */
    private function offEnvelope(): array
    {
        return [
            'status' => self::STATUS_OFF,
            'cycle_id' => '',
            'phases_completed' => 0,
            'last_good_phase_index' => 0,
            'facts' => [],
            'final_phase' => null,
            'error' => null,
        ];
    }
}
