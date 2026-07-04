<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure gate that admits candidate tasks only when they show compound impact,
 * implementability, proof strength, and anti-proxy evidence.
 *
 * Rejects plausible but isolated convenience tasks, and proxy-observability
 * tasks that don't change a downstream decision or gate.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasTaskFabricBrutalValueAdmissionGate
{
    public const SCHEMA = 'atlas.task_fabric.brutal_value_admission_gate.v1';

    public const VERDICT_ADMIT = 'admit';

    public const VERDICT_REJECT = 'reject';

    /**
     * @param  array{
     *   objective?:string,
     *   impact_signals?:array{
     *     reduces_give_back_risk?:bool,
     *     reduces_poison_risk?:bool,
     *     reduces_simplification_debt?:bool,
     *     unblocks_dependent_family?:bool,
     *     is_isolated_convenience?:bool,
     *   },
     *   implementability?:array{
     *     self_sufficient?:bool,
     *     allowed_files_clear?:bool,
     *   },
     *   proof?:array{
     *     has_runnable_proof?:bool,
     *     proof_type?:string,
     *   },
     *   is_proxy_observability?:bool,
     *   changes_downstream_decision?:bool,
     * }  $candidate
     * @return array{
     *   schema:string,
     *   verdict:string,
     *   reasons:list<string>,
     * }
     */
    public function evaluate(array $candidate): array
    {
        $reasons = [];
        $impact = $candidate['impact_signals'] ?? [];
        $implementability = $candidate['implementability'] ?? [];
        $proof = $candidate['proof'] ?? [];
        $isProxy = (bool) ($candidate['is_proxy_observability'] ?? false);
        $changesDecision = (bool) ($candidate['changes_downstream_decision'] ?? false);

        // ── Compound impact: at least 2 positive signals ───────────────────
        $positiveSignals = 0;
        if (($impact['reduces_give_back_risk'] ?? false) === true) { $positiveSignals++; }
        if (($impact['reduces_poison_risk'] ?? false) === true) { $positiveSignals++; }
        if (($impact['reduces_simplification_debt'] ?? false) === true) { $positiveSignals++; }
        if (($impact['unblocks_dependent_family'] ?? false) === true) { $positiveSignals++; }

        $isIsolated = ($impact['is_isolated_convenience'] ?? false) === true;

        if ($isIsolated && $positiveSignals < 2) {
            $reasons[] = 'low_compound_impact: isolated convenience with '.$positiveSignals.' signals';
            return $this->envelope(self::VERDICT_REJECT, $reasons);
        }

        if ($positiveSignals < 1) {
            $reasons[] = 'low_compound_impact: 0 positive signals';
            return $this->envelope(self::VERDICT_REJECT, $reasons);
        }

        // ── Proxy observability: must change a downstream decision ─────────
        if ($isProxy && ! $changesDecision) {
            $reasons[] = 'proxy_observability_without_decision_change';
            return $this->envelope(self::VERDICT_REJECT, $reasons);
        }

        // ── Implementability ───────────────────────────────────────────────
        if (($implementability['self_sufficient'] ?? false) !== true) {
            $reasons[] = 'not_self_sufficient';
            return $this->envelope(self::VERDICT_REJECT, $reasons);
        }

        // ── Proof strength ─────────────────────────────────────────────────
        if (($proof['has_runnable_proof'] ?? false) !== true) {
            $reasons[] = 'no_runnable_proof';
            return $this->envelope(self::VERDICT_REJECT, $reasons);
        }

        // All checks passed.
        $reasons[] = 'compound_impact:'.$positiveSignals.'_signals';
        $reasons[] = 'implementability:self_sufficient';
        $reasons[] = 'proof:runnable';

        return $this->envelope(self::VERDICT_ADMIT, $reasons);
    }

    /** @param  list<string>  $reasons */
    private function envelope(string $verdict, array $reasons): array
    {
        sort($reasons, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'verdict' => $verdict,
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public function decide(array $candidate): array
    {
        $target         = trim((string) ($candidate['target'] ?? ''));
        $objective      = trim((string) ($candidate['objective'] ?? ''));
        $allowedFiles   = is_array($candidate['allowed_files'] ?? null) ? $candidate['allowed_files'] : [];
        $impactScore    = (float) ($candidate['compound_impact_score'] ?? 0.0);
        $giveBackRisk   = (float) ($candidate['give_back_risk_score'] ?? 0.0);
        $knownTargets   = is_array($candidate['known_targets'] ?? null)
            ? array_map('strtolower', array_map('trim', array_map('strval', $candidate['known_targets'])))
            : [];
        $isTemplateFarm = (bool) ($candidate['is_template_farm'] ?? false);
        $workerFloorContext = (bool) ($candidate['worker_floor_context'] ?? false);
        $impactReason = trim((string) ($candidate['impact_reason'] ?? ''));
        $runnableAcceptance = (bool) ($candidate['runnable_acceptance'] ?? false);

        $thresholds = is_array($candidate['thresholds'] ?? null) ? $candidate['thresholds'] : [];
        $minChars       = (int) ($thresholds['min_objective_chars']   ?? self::MIN_OBJECTIVE_CHARS);
        $impactFloor    = (float) ($thresholds['compound_impact_floor']  ?? self::COMPOUND_IMPACT_FLOOR);
        $giveBackCeil   = (float) ($thresholds['give_back_risk_ceiling'] ?? self::GIVE_BACK_RISK_CEILING);

        $rejectionReasons = [];

        // 1. Semantic duplicate.
        if ($target !== '' && in_array(strtolower($target), $knownTargets, true)) {
            $rejectionReasons[] = 'semantic_duplicate';
        }

        // 2. Template farm.
        if ($isTemplateFarm || ($objective !== '' && strlen($objective) < $minChars)) {
            $rejectionReasons[] = 'template_farm';
        }

        // 3. Implementability.
        $hasImpl = false;
        $hasTest = false;
        foreach ($allowedFiles as $file) {
            $file = (string) $file;
            if (str_contains($file, self::TEST_PATTERN)) {
                $hasTest = true;
            } else {
                foreach (self::IMPL_PATTERNS as $pattern) {
                    if (str_contains($file, $pattern)) {
                        $hasImpl = true;
                        break;
                    }
                }
            }
        }
        // Only apply the check when allowed_files was provided.
        if ($allowedFiles !== []) {
            if (! $hasImpl) {
                $rejectionReasons[] = 'implementability_weak:no_implementation_file';
            }
            if (! $hasTest) {
                $rejectionReasons[] = 'implementability_weak:no_test_file';
            }
        }

        // 4. Proof/evidence floor — a candidate with high compound_impact_score must carry
        // concrete proof_floor or evidence_floor evidence; without it, the high-value claim
        // is unsubstantiated and the candidate is rejected with a named deficiency.
        $proofFloor = $candidate['proof_floor'] ?? null;
        $evidenceFloor = $candidate['evidence_floor'] ?? null;
        if ($impactScore >= $impactFloor && empty($proofFloor) && empty($evidenceFloor)) {
            $rejectionReasons[] = 'proof_floor_deficiency:compound_impact_above_floor_without_proof_or_evidence_floor';
        }

        // 5. Compound impact — UNLESS this is a genuinely muscle-feed packet during
        // replenish_soon: worker_floor_context + impact_reason=worker_continuity + a proven
        // impl+test+runnable-acceptance shape. Never lowers the floor for template farms or
        // duplicates — those are rejected by checks 1/2 above regardless of this exception.
        $isWorkerContinuityExempt = $workerFloorContext
            && $impactReason === 'worker_continuity'
            && $hasImpl
            && $hasTest
            && $runnableAcceptance;

        if ($impactScore < $impactFloor && ! $isWorkerContinuityExempt) {
            $rejectionReasons[] = 'compound_impact_low';
        }

        // 5. Give_back risk.
        if ($giveBackRisk >= $giveBackCeil) {
            $rejectionReasons[] = 'give_back_risk_high';
        }

        $admitted = $rejectionReasons === [];

        if (! $admitted) {
            return [
                'schema'            => self::SCHEMA,
                'admitted'          => false,
                'rejection_reasons' => $rejectionReasons,
            ];
        }

        $valueScore = $impactScore * (1.0 - $giveBackRisk);
        $requiredFollowups = [];
        if (! $hasImpl && $allowedFiles !== []) {
            $requiredFollowups[] = 'add_implementation';
        }
        if (! $hasTest && $allowedFiles !== []) {
            $requiredFollowups[] = 'add_test_coverage';
        }

        return [
            'schema'             => self::SCHEMA,
            'admitted'           => true,
            'rejection_reasons'  => [],
            'value_score'        => round($valueScore, 6),
            'risk_score'         => $giveBackRisk,
            'required_followups' => $requiredFollowups,
            'admitted_via_worker_continuity_exception' => $isWorkerContinuityExempt && $impactScore < $impactFloor,
        ];
    }
    private const GIVE_BACK_RISK_CEILING = 0.70;
    private const IMPL_PATTERNS = [
        'Service.php', 'Command.php', 'Controller.php', 'Repository.php',
        'Handler.php', 'Listener.php', 'Job.php', 'Policy.php', 'Provider.php',
        '/Services/', '/Commands/', '/Controllers/', '/Repositories/',
    ];
    private const COMPOUND_IMPACT_FLOOR  = 0.30;
    private const TEST_PATTERN = 'Test.php';
    private const MIN_OBJECTIVE_CHARS    = 30;
}
