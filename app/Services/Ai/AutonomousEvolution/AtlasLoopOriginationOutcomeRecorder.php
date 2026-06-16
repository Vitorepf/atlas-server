<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopOriginationOutcome;
use Throwable;

/**
 * ACDE lever O2 — records the operator's accept/reject on O1 origination proposals and turns the history into
 * a deterministic BACK-OFF prior the origination producer reads (the 3rd multiplier — the human accept/reject
 * sharpens the NEXT authored origination).
 *
 * record() appends one decision keyed by a deterministic SHAPE TOKEN (the origination's structure: target
 * directory + criteria bucket — never the content). backsOff() returns true iff a shape has accumulated enough
 * decisions (>= minSamples) and its accept-rate has fallen below the operator-frozen target — exactly the
 * priorBacksOff contract used for refactor sequences, so a thin/novel shape NEVER backs off (no false
 * silencing). The feedback is contained in the origination subsystem: NOTHING in the never-merge / readiness
 * layer reads this — the producer consults it, the operator's CLI feeds it.
 *
 * Anti-Goodhart: the only writer is the human accept/reject (a CLI), never the loop grading its own
 * origination. Fail-safe + flag-gated: with atlas.loop.origination_outcome_enabled OFF (default) record() is a
 * NO-OP, history() is empty and backsOff() is false — byte-identical until armed. Every DB touch is guarded.
 */
final class AtlasLoopOriginationOutcomeRecorder
{
    /**
     * A deterministic shape token for an origination: its target DIRECTORY + a coarse criteria bucket. Similar
     * originations share a token, so the operator rejecting one informs the prior for the whole shape.
     */
    public function shapeToken(string $targetPath, int $criteriaCount): string
    {
        $dir = trim(str_replace('\\', '/', dirname(ltrim(trim($targetPath), '/'))), '/');
        if ($dir === '' || $dir === '.') {
            $dir = '(root)';
        }
        $bucket = match (true) {
            $criteriaCount <= 0 => 'none',
            $criteriaCount <= 3 => 'small',
            $criteriaCount <= 6 => 'medium',
            default => 'large',
        };

        return substr(hash('sha256', $dir.'|'.$bucket), 0, 40);
    }

    /** Append one operator decision. No-op when OFF / no DB (byte-identical). */
    public function record(string $shapeToken, bool $accepted, ?string $proposalId = null, ?string $targetPath = null): void
    {
        $shapeToken = trim($shapeToken);
        if ($shapeToken === '' || ! $this->enabled() || ! $this->dbAvailable()) {
            return;
        }

        try {
            AtlasLoopOriginationOutcome::create([
                'shape_token' => $shapeToken,
                'accepted' => $accepted,
                'proposal_id' => $proposalId !== null && trim($proposalId) !== '' ? trim($proposalId) : null,
                'target_path' => $targetPath !== null && trim($targetPath) !== '' ? trim($targetPath) : null,
            ]);
        } catch (Throwable) {
            // best-effort telemetry — the operator review must never break on a ledger write.
        }
    }

    /**
     * The {accepted, total} tally for a shape token, or an empty ledger when OFF / no DB.
     *
     * @return array{accepted:int, total:int}
     */
    public function history(string $shapeToken): array
    {
        $shapeToken = trim($shapeToken);
        if ($shapeToken === '' || ! $this->enabled() || ! $this->dbAvailable()) {
            return ['accepted' => 0, 'total' => 0];
        }

        try {
            $total = AtlasLoopOriginationOutcome::query()->where('shape_token', $shapeToken)->count();
            $accepted = AtlasLoopOriginationOutcome::query()
                ->where('shape_token', $shapeToken)
                ->where('accepted', true)
                ->count();

            return ['accepted' => (int) $accepted, 'total' => (int) $total];
        } catch (Throwable) {
            return ['accepted' => 0, 'total' => 0];
        }
    }

    /**
     * Should the producer BACK OFF this shape? True iff the operator has decided on it at least minSamples
     * times AND its accept-rate is below targetRate. A thin/novel shape (total < minSamples) NEVER backs off —
     * the same conservative contract as the refactor-sequence prior, so a new origination is never silenced on
     * weak evidence. Pure: callers pass the tally so the rule is testable without a DB.
     */
    public static function backsOff(int $accepted, int $total, int $minSamples, float $targetRate): bool
    {
        if ($total < max(1, $minSamples)) {
            return false;
        }

        return ($accepted / $total) < $targetRate;
    }

    /** Convenience: read the ledger for a shape and apply the back-off rule (empty/OFF => false). */
    public function shapeBacksOff(string $shapeToken, int $minSamples, float $targetRate): bool
    {
        $tally = $this->history($shapeToken);

        return self::backsOff($tally['accepted'], $tally['total'], $minSamples, $targetRate);
    }

    /** Is the origination-outcome ledger ON? Read defensively so a container-less caller never fatals (=> OFF). */
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

        return (bool) config('atlas.loop.origination_outcome_enabled', false);
    }

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
