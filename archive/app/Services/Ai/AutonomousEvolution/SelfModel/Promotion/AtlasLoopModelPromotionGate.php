<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SelfModel\Promotion;

final class AtlasLoopModelPromotionGate
{
    /**
     * @param  array<string,mixed>  $candidateEval
     * @param  array<string,mixed>  $incumbentEval
     * @return array{schema:string,promote:bool,blocking_reasons:list<string>}
     */
    public function decide(array $candidateEval, array $incumbentEval, bool $operatorReceiptPresent): array
    {
        $blockingReasons = [];

        if ($candidateEval === [] || ! is_numeric($candidateEval['pass_rate'] ?? null)) {
            $blockingReasons[] = 'candidate_eval_missing';
        }

        if (! $this->candidateIsStrictlyBetter($candidateEval, $incumbentEval)) {
            $blockingReasons[] = 'candidate_not_better';
        }

        if (($candidateEval['regressions'] ?? null) !== []) {
            $blockingReasons[] = 'guardrail_regression';
        }

        if (! $operatorReceiptPresent) {
            $blockingReasons[] = 'operator_receipt_required';
        }

        return [
            'schema' => 'atlas.loop.self_model.model_promotion_gate.v1',
            'promote' => $blockingReasons === [],
            'blocking_reasons' => $blockingReasons,
        ];
    }

    /**
     * @param  array<string,mixed>  $candidateEval
     * @param  array<string,mixed>  $incumbentEval
     */
    private function candidateIsStrictlyBetter(array $candidateEval, array $incumbentEval): bool
    {
        if (! is_numeric($candidateEval['pass_rate'] ?? null) || ! is_numeric($incumbentEval['pass_rate'] ?? null)) {
            return false;
        }

        return (float) $candidateEval['pass_rate'] > (float) $incumbentEval['pass_rate'];
    }
}
