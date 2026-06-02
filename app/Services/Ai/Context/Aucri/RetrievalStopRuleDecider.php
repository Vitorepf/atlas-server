<?php

declare(strict_types=1);

namespace App\Services\Ai\Context\Aucri;

final class RetrievalStopRuleDecider
{
    private const SCHEMA_VERSION = 'atlas.aucri.retrieval_stop_rule.v1';

    private const MARGINAL_YIELD_COLLAPSE_CUTOFF = 0.05;

    /**
     * Decide whether an iterative retrieval loop should stop or continue.
     *
     * All telemetry is passed IN by the caller; this decider performs no I/O,
     * no clock reads and no randomness. The rule cascade is evaluated in strict
     * order and the first matching rule wins, appending its documented reason.
     *
     * @return array{schema_version: string, decision: string, reasons: list<string>}
     */
    public function decide(
        float $sufficiencyScore,
        float $sufficiencyThreshold,
        int $lastRoundNewTokens,
        int $lastRoundMarginalCost,
        int $roundsDone,
        int $maxRounds,
    ): array {
        $sufficient = $sufficiencyScore >= $sufficiencyThreshold;

        // (1) Hard cap on rounds — always wins first.
        if ($roundsDone >= $maxRounds) {
            return $this->result('stop', 'max_rounds_reached');
        }

        // (2) Sufficient and the last round produced no new signal.
        if ($sufficient && $lastRoundNewTokens <= 0) {
            return $this->result('stop', 'sufficient_and_no_new_signal');
        }

        // (3) Sufficient but the marginal yield per unit cost has collapsed.
        if ($sufficient
            && $lastRoundMarginalCost > 0
            && $lastRoundNewTokens > 0
            && $this->marginalYieldRatio($lastRoundNewTokens, $lastRoundMarginalCost) < self::MARGINAL_YIELD_COLLAPSE_CUTOFF
        ) {
            return $this->result('stop', 'sufficient_and_marginal_yield_collapsed');
        }

        // (4) The first round is mandatory: never stop before retrieving once.
        if ($roundsDone === 0) {
            return $this->result('continue', 'first_round_mandatory');
        }

        // (5) Sufficiency has not yet been reached.
        if ($sufficiencyScore < $sufficiencyThreshold) {
            return $this->result('continue', 'sufficiency_below_threshold');
        }

        // (6) Sufficient, but the marginal yield still justifies another round.
        return $this->result('continue', 'sufficient_but_marginal_yield_remains');
    }

    private function marginalYieldRatio(int $newTokens, int $marginalCost): float
    {
        return $newTokens / $marginalCost;
    }

    /**
     * @return array{schema_version: string, decision: string, reasons: list<string>}
     */
    private function result(string $decision, string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'decision' => $decision,
            'reasons' => [$reason],
        ];
    }
}
