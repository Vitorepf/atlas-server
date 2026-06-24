<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SelfModel\Oracle\Domains;

use App\Services\Ai\AutonomousEvolution\SelfModel\Oracle\AtlasLoopModelOutcomeOracle;

/**
 * TRADING outcome oracle (R8.4) — a third domain on the {@see AtlasLoopModelOutcomeOracle} contract, carrying
 * the operator's pétreo anti-overfit rule: "held-out, never in-sample". A result computed on in-sample data is
 * NEVER scored as a win (grounded=false, basis="in_sample_rejected"), no matter how large — only out-of-sample
 * (held-out) P&L counts.
 *
 * HONEST by the interface invariant: grounded ONLY when in_sample_only is explicitly false AND held_out_pnl is
 * present; otherwise grounded=false / score 0.0. A self-declared `self_score` is NEVER read. Pure + deterministic.
 */
final class AtlasLoopTradingOutcomeOracle implements AtlasLoopModelOutcomeOracle
{
    public const SCHEMA = 'atlas.loop.model_outcome.trading.v1';

    public function domain(): string
    {
        return 'trading';
    }

    /**
     * @param  array<string,mixed>  $delivery
     * @return array{schema:string, score:float, grounded:bool, basis:string}
     */
    public function scoreOutcome(array $delivery): array
    {
        // HARD RULE: an in-sample-only result is never a win, regardless of its P&L.
        if (($delivery['in_sample_only'] ?? null) === true) {
            return ['schema' => self::SCHEMA, 'score' => 0.0, 'grounded' => false, 'basis' => 'in_sample_rejected'];
        }

        $hasHeldOut = array_key_exists('held_out_pnl', $delivery) && is_numeric($delivery['held_out_pnl']);
        $isHeldOut = ($delivery['in_sample_only'] ?? null) === false; // must be EXPLICITLY held-out

        if (! $isHeldOut || ! $hasHeldOut) {
            return ['schema' => self::SCHEMA, 'score' => 0.0, 'grounded' => false, 'basis' => 'ungrounded'];
        }

        return ['schema' => self::SCHEMA, 'score' => (float) $delivery['held_out_pnl'], 'grounded' => true, 'basis' => 'held_out_pnl'];
    }
}
