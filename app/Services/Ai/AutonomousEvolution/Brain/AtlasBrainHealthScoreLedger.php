<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlLedgerTrait;
use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;

/**
 * HEALTH-SCORE LEDGER — append-only NDJSON time-series of the L90 composite score per scope. Writes
 * one row per snapshot tick. Lets the operator plot the score over days/weeks (drift, recovery,
 * regression) instead of polling brain:state and eyeballing trend.
 *
 * Append is the ONLY mutation. Reads are bounded (tail K). Pure file IO, per-scope NDJSON.
 *
 * Pétreo: réu never edits the ledger (else it'd rewrite history to look better — Goodhart, lying
 * mirror).
 */
final class AtlasBrainHealthScoreLedger
{
    use JsonlLedgerTrait;

    public const SCHEMA = 'atlas.brain.health_score_ledger.v1';

    public const DEFAULT_TAIL = 30;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim((string) ($root ?? config('atlas.brain.health_score_root', storage_path('app/atlas/brain/health-score'))), '/');
    }

    public function append(string $scope, int $score, ?int $at = null): ?array
    {
        $scope = $this->slugify(trim($scope));
        if ($scope === '') {
            return null;
        }

        $row = [
            'schema' => self::SCHEMA,
            'scope' => $scope,
            'score' => max(0, min(100, $score)),
            'recorded_at' => $at ?? time(),
        ];

        try {
            (new JsonlReceiptStore($this->pathFor($scope)))->append($row);
        } catch (\Throwable) {
            return null;
        }

        return $row;
    }
}
