<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class MergeRetryEscalationDecider
{
    private const SCHEMA_VERSION = 'atlas.software_company_stewardship.merge_retry_escalation_decision.v1';

    /**
     * @param  array{
     *     attempts: int,
     *     max_attempts: int,
     *     base_is_ancestor: bool,
     *     rebase_failed?: bool,
     *     last_failure_reason?: string|null
     * }  $input
     * @return array{
     *     schema_version: string,
     *     action: 'attempt_merge'|'attempt_rebase_then_merge'|'escalate'|'skip',
     *     escalate: bool,
     *     reason: string
     * }
     */
    public function decide(array $input): array
    {
        $attempts = (int) ($input['attempts'] ?? 0);
        $maxAttempts = (int) ($input['max_attempts'] ?? 0);
        $baseIsAncestor = (bool) ($input['base_is_ancestor'] ?? false);
        $rebaseFailed = (bool) ($input['rebase_failed'] ?? false);
        $lastFailureReason = trim((string) ($input['last_failure_reason'] ?? ''));

        $nextAttempt = $attempts + 1;
        $atOrPastMaxAttempts = $nextAttempt >= $maxAttempts;

        if ($maxAttempts <= 0) {
            return $this->buildResult('skip', false, 'invalid_max_attempts');
        }

        if (! $baseIsAncestor && $rebaseFailed) {
            if ($atOrPastMaxAttempts) {
                return $this->buildResult('escalate', true, 'rebase_failed');
            }

            return $this->buildResult('attempt_rebase_then_merge', false, '');
        }

        if (! $baseIsAncestor && ! $rebaseFailed) {
            return $this->buildResult('attempt_rebase_then_merge', false, '');
        }

        if ($lastFailureReason !== '' && $atOrPastMaxAttempts) {
            return $this->buildResult('escalate', true, $lastFailureReason);
        }

        if ($baseIsAncestor) {
            return $this->buildResult('attempt_merge', false, '');
        }

        return $this->buildResult('attempt_rebase_then_merge', false, '');
    }

    /**
     * @return array{
     *     schema_version: string,
     *     action: 'attempt_merge'|'attempt_rebase_then_merge'|'escalate'|'skip',
     *     escalate: bool,
     *     reason: string
     * }
     */
    private function buildResult(string $action, bool $escalate, string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'action' => $action,
            'escalate' => $escalate,
            'reason' => $reason,
        ];
    }
}
