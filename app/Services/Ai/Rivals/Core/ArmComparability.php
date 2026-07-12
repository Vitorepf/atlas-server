<?php

namespace App\Services\Ai\Rivals\Core;

/**
 * Guard that arms in a trial are actually comparable: a same-model bare control is
 * present, every arm pins its provider/model version, and all arms share one frozen
 * resource budget (compute/time/tools/egress). Any drift here manufactures uplift, so
 * it is a blocker, not a warning. Pure over arrays.
 */
final class ArmComparability
{
    /**
     * @param  array{arms?: list<array<string,mixed>>}  $plan  SixArmPlanBuilder output
     * @return list<string> violations (empty = comparable)
     */
    public function audit(array $plan): array
    {
        $arms = array_values(array_filter((array) ($plan['arms'] ?? []), 'is_array'));
        $violations = [];

        $roles = array_column($arms, 'arm_role');
        if (! in_array(SixArmPlanBuilder::ROLE_SAME_MODEL_BARE, $roles, true)) {
            $violations[] = 'missing_same_model_bare_control';
        }

        foreach ($arms as $arm) {
            // the human-artifact baseline is not a model run and carries no provider version
            if (($arm['arm_role'] ?? null) === SixArmPlanBuilder::ROLE_HISTORICAL_HUMAN) {
                continue;
            }
            if ((string) ($arm['provider_version'] ?? '') === '') {
                $violations[] = 'arm_provider_version_unpinned:'.($arm['arm_id'] ?? 'unknown');
            }
        }

        return $violations;
    }

    /**
     * All arms must share one frozen resource budget. The first arm is the reference;
     * any arm whose budget signature differs is flagged.
     *
     * @param  array<string, array<string,mixed>>  $armBudgets  arm_id => budget
     * @return list<string> violations
     */
    public function assertEqualBudgets(array $armBudgets): array
    {
        $reference = null;
        $violations = [];
        foreach ($armBudgets as $armId => $budget) {
            $signature = $this->signature((array) $budget);
            if ($reference === null) {
                $reference = $signature;

                continue;
            }
            if ($signature !== $reference) {
                $violations[] = 'arm_budget_unequal:'.$armId;
            }
        }

        return $violations;
    }

    /** @param array<string,mixed> $budget */
    private function signature(array $budget): string
    {
        ksort($budget);

        return hash('sha256', json_encode($budget, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
}
