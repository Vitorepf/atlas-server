<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\LiveCycle\Integration;

use RuntimeException;
use Throwable;

/**
 * Lets the LiveCycle orchestrator RESUME from the last successfully completed phase after an interrupted
 * cycle (crash, kill, master-switch flip). Without this, the orchestrator restarts from phase 1 and
 * corrupts the receipt chain.
 *
 * INVARIANTS:
 *   - PERSIST {last_good_phase_index, cycle_id, last_receipt_hash, fact_emitted_for_phase} ATOMICALLY
 *     (write-temp + rename + fsync) so a crash mid-write cannot leave a half-state.
 *   - On resume(): verify persisted last_receipt_hash STILL matches the rebuilt phase-chain hash.
 *     Refuse to resume (verified_chain=false) on mismatch — fail-closed.
 *   - IDEMPOTENT — calling resume twice with no checkpoint progress between MUST emit zero new FACTs on
 *     the second call (last_emit_phase tracker), returning the same plan.
 *   - Emits exactly one FACT envelope (cycle.resume.planned) per first-resume — caller persists.
 */
final class AtlasLoopLiveCycleResumeManager
{
    public const FACT_NAME = 'cycle.resume.planned';

    public const TOTAL_PHASES = 8;

    /** @var callable():string|null */
    private $chainHasher;

    /**
     * @param  string  $stateDir  directory holding one cycle-state file per cycle_id
     * @param  null|callable(string $cycleId, int $lastGoodPhase):string  $chainHasher
     *         Builds a fresh chain hash for the (cycle_id, completed phases) tuple — used to detect
     *         tampering with the persisted last_receipt_hash. Default: deterministic synthesis from
     *         (cycle_id + ":" + lastGoodPhase) so production wires in the real receipt composer.
     */
    public function __construct(private readonly string $stateDir, ?callable $chainHasher = null)
    {
        $this->chainHasher = $chainHasher;
    }

    /**
     * Persist a phase-complete checkpoint atomically. Callers (the orchestrator) invoke this after each
     * successful phase. After a crash, the latest checkpoint is what resume() reads.
     */
    public function checkpoint(string $cycleId, int $lastGoodPhaseIndex, string $lastReceiptHash): void
    {
        if ($cycleId === '' || $lastGoodPhaseIndex < 0 || $lastGoodPhaseIndex > self::TOTAL_PHASES || $lastReceiptHash === '') {
            throw new RuntimeException('checkpoint requires non-empty cycle_id, lastGoodPhaseIndex in [0,8], lastReceiptHash');
        }
        // A new checkpoint INVALIDATES the previous emit flag — the next resume() should emit a fresh FACT.
        $payload = [
            'cycle_id' => $cycleId,
            'last_good_phase_index' => $lastGoodPhaseIndex,
            'last_receipt_hash' => $lastReceiptHash,
            'last_emit_phase' => -1, // no resume FACT emitted at this checkpoint yet
        ];
        $this->writeAtomic($cycleId, $payload);
    }

    /**
     * Plan a resume for $cycleId.
     *
     * @return array{
     *     fact:?string,
     *     cycle_id:string,
     *     from_phase:int,
     *     to_phase:int,
     *     verified_chain:bool,
     *     refused:bool,
     *     reason:?string
     * }  fact===null when this call was idempotent (no new FACT emitted)
     */
    public function resume(string $cycleId): array
    {
        if ($cycleId === '') {
            throw new RuntimeException('cycle_id is required');
        }
        $state = $this->loadState($cycleId);
        if ($state === null) {
            // Nothing persisted ⇒ resume = start from phase 1 (still a FACT worth emitting once).
            return $this->emitOrIdempotent($cycleId, fromPhase: 1, verifiedChain: true, refused: false, reason: 'no checkpoint; starting from phase 1');
        }

        $lastGood = (int) ($state['last_good_phase_index'] ?? 0);
        $persistedHash = (string) ($state['last_receipt_hash'] ?? '');
        $expectedHash = $this->hashFor($cycleId, $lastGood);
        $verified = hash_equals($expectedHash, $persistedHash);

        if (! $verified) {
            return $this->emitOrIdempotent($cycleId, fromPhase: 0, verifiedChain: false, refused: true, reason: 'persisted last_receipt_hash does not match rebuilt chain — refusing resume');
        }

        if ($lastGood >= self::TOTAL_PHASES) {
            return $this->emitOrIdempotent($cycleId, fromPhase: self::TOTAL_PHASES, verifiedChain: true, refused: false, reason: 'cycle already complete');
        }

        $fromPhase = $lastGood + 1;

        return $this->emitOrIdempotent($cycleId, fromPhase: $fromPhase, verifiedChain: true, refused: false, reason: 'resuming from next phase');
    }

    /**
     * @return array{fact:?string, cycle_id:string, from_phase:int, to_phase:int, verified_chain:bool, refused:bool, reason:?string}
     */
    private function emitOrIdempotent(string $cycleId, int $fromPhase, bool $verifiedChain, bool $refused, ?string $reason): array
    {
        $state = $this->loadState($cycleId) ?? [
            'cycle_id' => $cycleId,
            'last_good_phase_index' => 0,
            'last_receipt_hash' => '',
            'last_emit_phase' => -1,
        ];
        $alreadyEmitted = (int) ($state['last_emit_phase'] ?? -1) === $fromPhase;

        $envelope = [
            'fact' => $alreadyEmitted ? null : self::FACT_NAME,
            'cycle_id' => $cycleId,
            'from_phase' => $fromPhase,
            'to_phase' => $refused ? 0 : self::TOTAL_PHASES,
            'verified_chain' => $verifiedChain,
            'refused' => $refused,
            'reason' => $reason,
        ];

        if (! $alreadyEmitted) {
            $state['last_emit_phase'] = $fromPhase;
            $this->writeAtomic($cycleId, $state);
        }

        return $envelope;
    }

    private function hashFor(string $cycleId, int $lastGoodPhase): string
    {
        $hasher = $this->chainHasher;
        if (is_callable($hasher)) {
            try {
                return (string) $hasher($cycleId, $lastGoodPhase);
            } catch (Throwable) {
                // fall through to default
            }
        }

        return hash('sha256', $cycleId.':'.$lastGoodPhase);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadState(string $cycleId): ?array
    {
        $path = $this->statePath($cycleId);
        if (! is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeAtomic(string $cycleId, array $payload): void
    {
        $path = $this->statePath($cycleId);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $path.'.tmp.'.bin2hex(random_bytes(6));
        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $bytes = @file_put_contents($tmp, $json, LOCK_EX);
        if ($bytes === false) {
            throw new RuntimeException('resume manager cannot write tmp state '.$tmp);
        }
        // fsync the tmp file before rename so the bytes are durable.
        $fh = @fopen($tmp, 'r');
        if ($fh !== false) {
            @\fsync($fh);
            fclose($fh);
        }
        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('resume manager cannot atomically rename '.$tmp.' -> '.$path);
        }
    }

    public function statePath(string $cycleId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $cycleId) ?? 'cycle';

        return rtrim($this->stateDir, '/').'/'.$safe.'.json';
    }
}
