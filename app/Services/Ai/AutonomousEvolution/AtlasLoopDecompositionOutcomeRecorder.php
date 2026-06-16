<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopDecompositionOutcome;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopDecompositionShapeFingerprinter;
use Throwable;

/**
 * ACDE Leap 5 — appends + reads the decomposition outcome corpus (the compounding substrate).
 *
 * record() writes ONE row per EXECUTED obra terminal, anchored on the real envelope booleans (certified vs
 * thrashed) — never model self-report. history() returns the {certified, total} tally for a fingerprint that
 * AtlasLoopDecompositionShapePrior turns into a Wilson lower-bound verdict for the readiness gate.
 *
 * Fail-safe + flag-gated: with atlas.loop.decomposition_corpus_enabled OFF (default) record() is a NO-OP and
 * history() reports an empty corpus, so the loop is byte-identical until the operator arms the corpus. Every
 * DB touch is defensively guarded (a container-less / DB-less caller degrades to "empty", never throws).
 */
final class AtlasLoopDecompositionOutcomeRecorder
{
    public function __construct(private readonly ?AtlasLoopDecompositionShapeFingerprinter $fingerprinter = null) {}

    /**
     * Append one terminal outcome for an executed obra. No-op when the corpus flag is OFF (byte-identical).
     *
     * @param  array<string,mixed>  $plan  the executed decomposition plan (structural fingerprint source)
     */
    public function record(array $plan, string $objectiveKind, bool $certified, string $terminalReason, int $rounds = 1): void
    {
        if (! $this->enabled() || ! $this->dbAvailable()) {
            return;
        }

        try {
            $fp = ($this->fingerprinter ?? new AtlasLoopDecompositionShapeFingerprinter)->fingerprint($plan);
            AtlasLoopDecompositionOutcome::create([
                'fingerprint_hash' => (string) $fp['hash'],
                'objective_kind' => trim($objectiveKind) !== '' ? trim($objectiveKind) : null,
                'node_count' => (int) ($fp['node_count'] ?? 0),
                'certified' => $certified,
                'thrashed' => ! $certified,
                'terminal_reason' => mb_substr(trim($terminalReason), 0, 250) ?: null,
                'rounds' => max(0, $rounds),
            ]);
        } catch (Throwable) {
            // best-effort telemetry — a corpus write must NEVER break or alter the obra path.
        }
    }

    /**
     * The {certified, total} tally for a structural fingerprint, or an empty corpus when the flag is OFF /
     * no DB is resolvable (so a pure-unit caller degrades to UNKNOWN — never a false signal).
     *
     * @return array{certified:int, total:int}
     */
    public function history(string $fingerprintHash): array
    {
        $fingerprintHash = trim($fingerprintHash);
        if ($fingerprintHash === '' || ! $this->enabled() || ! $this->dbAvailable()) {
            return ['certified' => 0, 'total' => 0];
        }

        try {
            $total = AtlasLoopDecompositionOutcome::query()->where('fingerprint_hash', $fingerprintHash)->count();
            $certified = AtlasLoopDecompositionOutcome::query()
                ->where('fingerprint_hash', $fingerprintHash)
                ->where('certified', true)
                ->count();

            return ['certified' => (int) $certified, 'total' => (int) $total];
        } catch (Throwable) {
            return ['certified' => 0, 'total' => 0];
        }
    }

    /** Is the corpus flag ON? Read defensively so a container-less caller never fatals (=> treated OFF). */
    public function enabled(): bool
    {
        try {
            $app = function_exists('app') ? app() : null;
            if ($app === null || ! $app->bound('config')) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        return (bool) config('atlas.loop.decomposition_corpus_enabled', false);
    }

    /** Is a DB connection resolvable in this process? (A pure-unit caller with no container is not.) */
    private function dbAvailable(): bool
    {
        try {
            $app = function_exists('app') ? app() : null;

            return $app !== null && $app->bound('db');
        } catch (Throwable) {
            return false;
        }
    }
}
