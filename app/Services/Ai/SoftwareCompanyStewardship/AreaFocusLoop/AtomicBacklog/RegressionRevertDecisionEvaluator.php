<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

final class RegressionRevertDecisionEvaluator
{
    private const SCHEMA_VERSION = 'atlas.loop.regression_revert_decision.v1';

    private const GIT_REVERT = 'revert_no_edit';

    private const GIT_NONE = 'none';

    /**
     * @return array{
     *     schema_version: string,
     *     decision: string,
     *     revert_allowed: bool,
     *     blockers: list<string>,
     *     git_operation: string
     * }
     */
    public function decide(array $appliedLearning, array $measurement, array $workspaceState): array
    {
        $learningWasApplied = $this->learningWasApplied($appliedLearning);
        $regressionMeasured = $this->regressionMeasured($measurement);

        if (! $learningWasApplied || ! $regressionMeasured) {
            return $this->result('no_op', false, [], self::GIT_NONE);
        }

        $blockers = [];

        if ($this->hasDirtyHumanWork($workspaceState)) {
            $blockers[] = 'dirty_human_work';
        }

        if (! $this->hasRevertPort($workspaceState)) {
            $blockers[] = 'missing_revert_port';
        }

        if ($blockers !== []) {
            return $this->result('block_revert', false, $blockers, self::GIT_NONE);
        }

        return $this->result('revert', true, [], self::GIT_REVERT);
    }

    /**
     * @param  list<string>  $blockers
     * @return array{
     *     schema_version: string,
     *     decision: string,
     *     revert_allowed: bool,
     *     blockers: list<string>,
     *     git_operation: string
     * }
     */
    private function result(string $decision, bool $revertAllowed, array $blockers, string $gitOperation): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'decision' => $decision,
            'revert_allowed' => $revertAllowed,
            'blockers' => $blockers,
            'git_operation' => $gitOperation,
        ];
    }

    private function learningWasApplied(array $appliedLearning): bool
    {
        if (($appliedLearning['applied'] ?? false) === true) {
            return true;
        }

        return is_string($appliedLearning['applied_ref'] ?? null)
            && ($appliedLearning['applied_ref'] ?? '') !== '';
    }

    private function regressionMeasured(array $measurement): bool
    {
        if (($measurement['regression_detected'] ?? false) === true) {
            return true;
        }

        if (array_key_exists('score_before', $measurement) && array_key_exists('score_after', $measurement)) {
            return $this->floatValue($measurement, 'score_after') < $this->floatValue($measurement, 'score_before');
        }

        return false;
    }

    private function hasDirtyHumanWork(array $workspaceState): bool
    {
        if (($workspaceState['dirty_human_work'] ?? false) === true) {
            return true;
        }

        return $this->intValue($workspaceState, 'uncommitted_human_changes') > 0;
    }

    private function hasRevertPort(array $workspaceState): bool
    {
        return ($workspaceState['revert_port_available'] ?? false) === true;
    }

    private function floatValue(array $payload, string $key): float
    {
        $value = $payload[$key] ?? 0;

        return is_float($value) || is_int($value) ? (float) $value : (float) (is_numeric($value) ? $value : 0);
    }

    private function intValue(array $payload, string $key): int
    {
        $value = $payload[$key] ?? 0;

        return is_int($value) ? $value : (int) (is_numeric($value) ? $value : 0);
    }
}
