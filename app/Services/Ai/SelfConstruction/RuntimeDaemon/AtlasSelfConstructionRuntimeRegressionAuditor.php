<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\RuntimeDaemon;

/**
 * Pure, facts-only auditor over a soak-runner report ({@see AtlasSelfConstructionRuntimeSoakRunner}).
 *
 * Detects autonomy regressions, fake-green results, missing recovery, missing evidence, weak
 * heartbeat continuity, scheduler manifest mismatch, and any return of operator/human/provider
 * dependency in ordinary progress. Emits a typed verdict (pass | hold | blocked).
 */
final class AtlasSelfConstructionRuntimeRegressionAuditor
{
    public const SCHEMA = 'atlas.self_construction.runtime_regression_auditor.v1';

    public const VERDICT_PASS = 'pass';

    public const VERDICT_HOLD = 'hold';

    public const VERDICT_BLOCKED = 'blocked';

    /** @var list<string> */
    public const REQUIRED_CASES = [
        'green_cycle',
        'empty_queue_replenish',
        'give_back_repair',
        'failed_gate_hold',
        'stale_heartbeat_recovery',
        'pause_resume',
        'safety_stop',
        'scope_expansion_hold',
    ];

    /** @var list<string> */
    public const RECOVERY_CASES = [
        'empty_queue_replenish',
        'stale_heartbeat_recovery',
        'replenisher_recovery',
        'scope_expansion_admit',
    ];

    /**
     * @param  array<string,mixed>  $soakReport
     * @param  array<string,mixed>  $facts  {evidence_refs?:list<string>, scheduler_manifest_match?:bool, heartbeat_continuity_breaks?:int}
     * @return array<string,mixed>
     */
    public function audit(array $soakReport, array $facts = []): array
    {
        $blockers = [];
        $warnings = [];

        $passed = (bool) ($soakReport['passed'] ?? false);
        $tickCount = (int) ($soakReport['tick_count'] ?? 0);
        $failedCount = (int) ($soakReport['failed_count'] ?? 0);
        $dependencyViolations = array_values((array) ($soakReport['dependency_violations'] ?? []));
        $tickResults = array_values((array) ($soakReport['tick_results'] ?? []));
        $evidenceRefs = array_values((array) ($facts['evidence_refs'] ?? []));

        $kinds = array_column($tickResults, 'kind');
        $exercisedCases = array_values(array_unique($kinds));

        if ($tickCount === 0) {
            $blockers[] = 'fake_green:tick_count_zero';
        }

        foreach (self::REQUIRED_CASES as $required) {
            if (! in_array($required, $kinds, true)) {
                $blockers[] = 'missing_required_case:'.$required;
            }
        }

        $recoveryKindsExercised = array_intersect(self::RECOVERY_CASES, $kinds);
        if ($tickCount > 0 && $recoveryKindsExercised === []) {
            $blockers[] = 'missing_recovery:no_recovery_kind_exercised';
        } else {
            // For every exercised recovery kind, the tick result must classify as 'recovered'.
            foreach ($tickResults as $row) {
                if (in_array((string) $row['kind'], self::RECOVERY_CASES, true) && (string) ($row['classification'] ?? '') !== 'recovered') {
                    $blockers[] = 'recovery_case_did_not_recover:'.(string) $row['kind'];
                }
            }
        }

        if ($passed && $evidenceRefs === []) {
            $blockers[] = 'fake_green:success_without_evidence_refs';
        }

        if ($dependencyViolations !== []) {
            foreach ($dependencyViolations as $v) {
                $blockers[] = 'dependency_regression:'.(string) ($v['violation'] ?? '?').'@'.(string) ($v['tick_kind'] ?? '?');
            }
        }

        if (! $passed) {
            $blockers[] = 'soak_failed:'.($failedCount > 0 ? 'tick_failures='.$failedCount : 'soak_passed_false');
        }

        // Soft / refreshable signals → warnings; do not block on their own.
        if (array_key_exists('scheduler_manifest_match', $facts) && ! (bool) $facts['scheduler_manifest_match']) {
            $warnings[] = 'scheduler_manifest_mismatch';
        }
        if ((int) ($facts['heartbeat_continuity_breaks'] ?? 0) > 0) {
            $warnings[] = 'weak_heartbeat_continuity:'.(int) $facts['heartbeat_continuity_breaks'];
        }

        $hasContractBlocker = $dependencyViolations !== []
            || in_array('fake_green:tick_count_zero', $blockers, true)
            || in_array('fake_green:success_without_evidence_refs', $blockers, true);

        if ($blockers === []) {
            $verdict = $warnings !== [] ? self::VERDICT_HOLD : self::VERDICT_PASS;
        } else {
            $verdict = $hasContractBlocker ? self::VERDICT_BLOCKED : self::VERDICT_HOLD;
        }

        $payload = [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'verdict' => $verdict,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'exercised_cases' => $exercisedCases,
            'evidence_refs' => $evidenceRefs,
            'soak_passed' => $passed,
        ];
        $payload['regression_audit_hash'] = $this->auditHash($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function auditHash(array $payload): string
    {
        unset($payload['regression_audit_hash']);
        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'regression_audit_'.substr(hash('sha256', (string) $canonical), 0, 32);
    }
}
