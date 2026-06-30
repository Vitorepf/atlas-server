<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure, deterministic counterfactual reviewer for proposed task batches.
 *
 * Compares the proposed batch against a counterfactual (smaller/different)
 * and recommends keep, shrink, replace, or split based on leverage, diversity,
 * duplicate risk, give_back risk, implementation readiness, and opportunity cost.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasExternalBrainTaskBatchCounterfactualReviewer
{
    public const SCHEMA = 'atlas.external_brain.task_batch_counterfactual_reviewer.v1';

    public const DECISION_KEEP    = 'keep';
    public const DECISION_SHRINK  = 'shrink';
    public const DECISION_REPLACE = 'replace';
    public const DECISION_SPLIT   = 'split';

    /**
     * @param  list<array<string,mixed>>  $proposedBatch
     * @param  list<array<string,mixed>>  $counterfactualBatch
     * @return array{
     *     schema:string,
     *     proposed_score:float,
     *     counterfactual_score:float,
     *     decision:string,
     *     evidence:list<string>,
     *     risks:list<string>,
     *     opportunity_cost:float,
     *     recommended_batch_delta:list<string>,
     * }
     */
    public function review(array $proposedBatch, array $counterfactualBatch = []): array
    {
        $proposedEval = $this->evaluateBatch($proposedBatch);
        $counterEval  = $this->evaluateBatch($counterfactualBatch);

        $evidence = [];
        $risks = array_values(array_unique(array_merge($proposedEval['risks'], $counterEval['risks'])));

        // Decision logic:
        // - REPLACE: counterfactual scores higher than proposed
        // - SHRINK: proposed has high duplicate/give_back risk but still has value
        // - SPLIT: proposed is large with mixed leverage (some high, some low)
        // - KEEP: proposed is strong and beats counterfactual (chain-value-adjusted)
        if ($counterEval['score'] > $proposedEval['score']) {
            $decision = self::DECISION_REPLACE;
            $evidence[] = "counterfactual score {$counterEval['score']} > proposed {$proposedEval['score']}";
            $evidence[] = "counterfactual has {$counterEval['high_leverage']} high-leverage tasks vs {$proposedEval['high_leverage']}";
        } elseif ($proposedEval['duplicate_risk'] > 0.5 || $proposedEval['give_back_risk'] > 0.4) {
            $decision = self::DECISION_SHRINK;
            $evidence[] = "duplicate_risk={$proposedEval['duplicate_risk']} give_back_risk={$proposedEval['give_back_risk']}";
            $evidence[] = "shrinking reduces noise while keeping leverage";
        } elseif (count($proposedBatch) > 5 && $proposedEval['low_leverage'] > 2) {
            $decision = self::DECISION_SPLIT;
            $evidence[] = "batch is large (".count($proposedBatch).") with {$proposedEval['low_leverage']} low-leverage tasks";
            $evidence[] = "splitting isolates high-leverage work from filler";
        } else {
            $decision = self::DECISION_KEEP;
            $evidence[] = "proposed score {$proposedEval['score']} >= counterfactual {$counterEval['score']}";
            if ($proposedEval['diversity'] > 0.6) {
                $evidence[] = "diversity={$proposedEval['diversity']} is healthy";
            }
            if ($proposedEval['chain_value'] > 0.0) {
                $evidence[] = "dependency_chain_value={$proposedEval['chain_value']} unlocks downstream work despite smaller batch size";
            }
        }

        $opportunityCost = round(max(0.0, $counterEval['score'] - $proposedEval['score']), 2);

        return [
            'schema'                   => self::SCHEMA,
            'proposed_score'           => $proposedEval['score'],
            'counterfactual_score'     => $counterEval['score'],
            'decision'                 => $decision,
            'evidence'                 => $evidence,
            'risks'                    => $risks,
            'opportunity_cost'         => $opportunityCost,
            'recommended_batch_delta'  => $this->recommendedBatchDelta($decision, $proposedEval),
        ];
    }

    /** @return list<string> */
    private function recommendedBatchDelta(string $decision, array $proposedEval): array
    {
        return match ($decision) {
            self::DECISION_REPLACE => ['adopt_counterfactual_batch_instead'],
            self::DECISION_SHRINK  => array_filter([
                $proposedEval['duplicate_risk'] > 0.5 ? 'remove_duplicate_allowed_file_tasks' : null,
                $proposedEval['give_back_risk'] > 0.4 ? 'remove_high_give_back_risk_tasks' : null,
            ]),
            self::DECISION_SPLIT   => ['split_low_leverage_tasks_into_separate_batch'],
            default                => [],
        };
    }

    /**
     * @param  list<array<string,mixed>>  $batch
     * @return array{score:float, high_leverage:int, low_leverage:int, diversity:float, duplicate_risk:float, give_back_risk:float, chain_value:float, risks:list<string>}
     */
    private function evaluateBatch(array $batch): array
    {
        $count = count($batch);
        if ($count === 0) {
            return ['score' => 0.0, 'high_leverage' => 0, 'low_leverage' => 0, 'diversity' => 0.0, 'duplicate_risk' => 0.0, 'give_back_risk' => 0.0, 'chain_value' => 0.0, 'risks' => []];
        }

        $highLeverage = 0;
        $lowLeverage = 0;
        $types = [];
        $allowedFiles = [];
        $objectives = [];
        $giveBackRiskSum = 0.0;
        $chainValueSum = 0.0;

        foreach ($batch as $task) {
            $leverage = strtolower(trim((string) ($task['leverage'] ?? 'low')));
            if ($leverage === 'high') {
                $highLeverage++;
            } elseif ($leverage === 'low') {
                $lowLeverage++;
            }
            $type = strtolower(trim((string) ($task['type'] ?? 'unknown')));
            $types[$type] = true;
            foreach ((array) ($task['allowed_files'] ?? []) as $f) {
                $allowedFiles[] = (string) $f;
            }
            $objectives[] = strtolower(trim((string) ($task['objective'] ?? '')));
            $giveBackRiskSum += (float) ($task['give_back_risk'] ?? 0.0);
            $chainValueSum += max(0.0, (float) ($task['dependency_chain_unlock_value'] ?? 0.0));
        }

        // Score: leverage weighted, diversity bonus, opportunity cost penalty for filler,
        // dependency-chain unlock bonus (a small batch that unlocks a lot of downstream
        // work must be able to outscore a larger but shallow counterfactual).
        $leverageScore = ($highLeverage * 2.0 + ($count - $highLeverage - $lowLeverage) * 1.0) / max(1, $count);
        $diversity = count($types) / max(1, $count);
        $fillerPenalty = $lowLeverage * 0.3;
        $chainBonus = min(4.0, $chainValueSum);
        $score = max(0.0, min(10.0, round($leverageScore + ($diversity * 2.0) - $fillerPenalty + $chainBonus, 2)));

        // Duplicate risk: ratio of duplicate allowed_files
        $uniqueFiles = count(array_unique($allowedFiles));
        $duplicateRisk = $uniqueFiles > 0 ? round(1.0 - ($uniqueFiles / max(1, count($allowedFiles))), 2) : 0.0;

        // Give-back risk: average
        $giveBackRisk = round($giveBackRiskSum / $count, 2);

        // Similar objective shapes
        $uniqueObjectives = count(array_unique($objectives));
        if ($uniqueObjectives < $count) {
            $duplicateRisk = max($duplicateRisk, 0.3);
        }

        $risks = [];
        if ($duplicateRisk > 0.3) {
            $risks[] = 'duplicate_allowed_files';
        }
        if ($giveBackRisk > 0.3) {
            $risks[] = 'high_give_back_risk';
        }
        if ($lowLeverage > $highLeverage && $count > 3) {
            $risks[] = 'filler_dominated';
        }

        return [
            'score'          => $score,
            'high_leverage'  => $highLeverage,
            'low_leverage'   => $lowLeverage,
            'diversity'      => round($diversity, 2),
            'duplicate_risk' => $duplicateRisk,
            'give_back_risk' => $giveBackRisk,
            'chain_value'    => round($chainValueSum, 2),
            'risks'          => $risks,
        ];
    }
}
