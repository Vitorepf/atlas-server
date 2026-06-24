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
        return $this->historyForObjectiveKind($fingerprintHash, null);
    }

    /**
     * The {certified, total} tally for a structural fingerprint, optionally scoped to an objective kind.
     *
     * @return array{certified:int, total:int}
     */
    public function historyForObjectiveKind(string $fingerprintHash, ?string $objectiveKind): array
    {
        $fingerprintHash = trim($fingerprintHash);
        if ($fingerprintHash === '' || ! $this->enabled() || ! $this->dbAvailable()) {
            return ['certified' => 0, 'total' => 0];
        }

        try {
            $query = AtlasLoopDecompositionOutcome::query()->where('fingerprint_hash', $fingerprintHash);
            $objectiveKind = is_string($objectiveKind) ? trim($objectiveKind) : '';
            if ($objectiveKind !== '') {
                $query->where('objective_kind', $objectiveKind);
            }
            $total = (clone $query)->count();
            $certified = (clone $query)->where('certified', true)->count();

            return ['certified' => (int) $certified, 'total' => (int) $total];
        } catch (Throwable) {
            return ['certified' => 0, 'total' => 0];
        }
    }

    /**
     * Historically-hard objective kinds from the decomposition outcome corpus.
     *
     * @return list<array{family:string, attempts:int, failed:int, failure_rate:float, source:string}>
     */
    public function hardObjectiveKinds(float $failureRateThreshold = 0.5, int $minAttempts = 3): array
    {
        $failureRateThreshold = max(0.0, min(1.0, $failureRateThreshold));
        $minAttempts = max(1, $minAttempts);
        if (! $this->enabled() || ! $this->dbAvailable()) {
            return [];
        }

        try {
            $rows = AtlasLoopDecompositionOutcome::query()
                ->selectRaw('objective_kind, COUNT(*) as total, SUM(CASE WHEN certified THEN 1 ELSE 0 END) as certified_count')
                ->whereNotNull('objective_kind')
                ->groupBy('objective_kind')
                ->get();
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $family = trim((string) ($row->objective_kind ?? ''));
            $total = (int) ($row->total ?? 0);
            $certified = (int) ($row->certified_count ?? 0);
            $failed = max(0, $total - $certified);
            $failureRate = $total > 0 ? round($failed / $total, 4) : 0.0;
            if ($family === '' || $total < $minAttempts || $failureRate <= $failureRateThreshold) {
                continue;
            }
            $out[] = [
                'family' => $family,
                'attempts' => $total,
                'failed' => $failed,
                'failure_rate' => $failureRate,
                'source' => 'decomposition_outcome_recorder',
            ];
        }

        usort($out, static function (array $a, array $b): int {
            return ((float) $b['failure_rate'] <=> (float) $a['failure_rate'])
                ?: ((int) $b['attempts'] <=> (int) $a['attempts'])
                ?: strcmp((string) $a['family'], (string) $b['family']);
        });

        return $out;
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
