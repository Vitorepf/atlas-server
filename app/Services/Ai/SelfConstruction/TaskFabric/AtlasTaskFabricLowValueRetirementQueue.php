<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure retirement queue. Accepts a list of task-spec candidates and classifies
 * each as: retired | protected | ineligible.
 *
 * Retirement eligibility (first matching condition wins; priority order):
 *   1. is_duplicate === true
 *   2. is_stale === true
 *   3. stale_without_commit === true
 *   4. proxy_risk >= PROXY_RISK_THRESHOLD (0.70)          — inflation signal
 *   5. template_similarity >= TEMPLATE_SIMILARITY_THRESHOLD (0.70) — inflation signal
 *   6. repeated_give_back_count >= GIVE_BACK_THRESHOLD (3) — inflation signal
 *   7. quarantine_count >= QUARANTINE_COUNT_THRESHOLD (2)  — inflation signal
 *   8. value_estimate < value_threshold (default 0.2)
 *
 * Inflation signals (4–7) override a high value_estimate so optimistic specs
 * that hide low quality behind a large declared value are still retired.
 *
 * Critical-chain guard (AC2):
 *   If the candidate's id appears in critical_dependency_chains, retirement is
 *   REFUSED regardless of eligibility → emitted in protected[] with reason
 *   'on_critical_dependency_chain'.
 *
 * Replacement/respec contract (AC3):
 *   Every retired item must carry a replacement_or_respec entry so retirement
 *   cannot create queue starvation:
 *     - replacement_candidate present → action='replace', replacement_candidate_id set
 *     - otherwise                    → action='respec_required'
 *
 * Processing order:
 *   1. Check critical chain → protected (fail-closed, blocks retirement).
 *   2. Check retirement eligibility → retired.
 *   3. Otherwise → ineligible.
 *
 * Pure: no I/O, no provider calls, deterministic.
 */
final class AtlasTaskFabricLowValueRetirementQueue
{
    public const SCHEMA = 'atlas.task_fabric.low_value_retirement_queue.v1';

    private const DEFAULT_VALUE_THRESHOLD         = 0.2;
    private const PROXY_RISK_THRESHOLD            = 0.70;
    private const TEMPLATE_SIMILARITY_THRESHOLD   = 0.70;
    private const GIVE_BACK_THRESHOLD             = 3;
    private const QUARANTINE_COUNT_THRESHOLD      = 2;

    /** respec_draft_confidence at/above this, combined with runnable acceptance, blocks retirement. */
    private const RESPEC_CONFIDENCE_THRESHOLD     = 0.70;

    /** proof_strength BELOW this for a 'low_value' candidate routes to needs_evidence instead of deletion. */
    private const NEEDS_EVIDENCE_PROOF_THRESHOLD  = 0.30;

    /** leverage_score AT/ABOVE this protects an otherwise-low-value candidate as genuinely useful (keep). */
    private const LEVERAGE_KEEP_THRESHOLD         = 0.70;

    /** @var array<string,int> retirement_reason → priority (higher = more urgent) */
    private const RETIREMENT_PRIORITY = [
        'duplicate'              => 5,
        'stale'                  => 4,
        'stale_without_commit'   => 4,
        'high_proxy_risk'        => 5,
        'high_template_similarity' => 4,
        'repeated_give_back'     => 3,
        'over_quarantined'       => 3,
        'low_value'              => 1,
    ];

    public const DECISION_RETIRE                  = 'retire';
    public const DECISION_KEEP                     = 'keep';
    public const DECISION_MERGE                    = 'merge';
    public const DECISION_NEEDS_EVIDENCE           = 'needs_evidence';

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function evaluate(array $facts): array
    {
        $candidates       = is_array($facts['candidates'] ?? null) ? $facts['candidates'] : [];
        $criticalChainIds = array_flip(array_map('strval', (array) ($facts['critical_dependency_chains'] ?? [])));
        $valueThreshold   = (float) ($facts['value_threshold'] ?? self::DEFAULT_VALUE_THRESHOLD);

        $retired       = [];
        $protected     = [];
        $ineligible    = [];
        $needsEvidence = [];

        foreach ($candidates as $c) {
            $id    = (string) ($c['id']             ?? '');
            $value = (float)  ($c['value_estimate'] ?? 1.0);

            $entry = ['id' => $id, 'value_estimate' => $value];

            // AC2: critical dependency chain — absolute block on retirement.
            if (isset($criticalChainIds[$id])) {
                $protected[] = array_merge($entry, ['protection_reason' => 'on_critical_dependency_chain', 'decision' => self::DECISION_KEEP]);
                continue;
            }

            // Safe claimable recovery path: a high-confidence respec draft with runnable
            // acceptance means retirement is not the only option — refuse retirement.
            $respecConfidence = (float) ($c['respec_draft_confidence'] ?? 0.0);
            $hasRunnableAcceptance = (bool) ($c['has_runnable_acceptance'] ?? false);
            if ($respecConfidence >= self::RESPEC_CONFIDENCE_THRESHOLD && $hasRunnableAcceptance) {
                $protected[] = array_merge($entry, ['protection_reason' => 'has_safe_claimable_recovery_path', 'decision' => self::DECISION_KEEP]);
                continue;
            }

            $reason = $this->retirementReason($c, $valueThreshold);

            if ($reason !== null) {
                $proofStrength = (float) ($c['proof_strength'] ?? 1.0);
                $leverageScore = (float) ($c['leverage_score'] ?? 0.0);

                // A low-value candidate with genuinely high architectural leverage is not
                // padding — protect it as ineligible/keep rather than deleting real leverage.
                if ($reason === 'low_value' && $leverageScore >= self::LEVERAGE_KEEP_THRESHOLD) {
                    $ineligible[] = array_merge($entry, ['decision' => self::DECISION_KEEP]);
                    continue;
                }

                // A low-value candidate with weak proof backing the retirement claim is not
                // deleted outright — it needs more evidence before a destructive decision.
                if ($reason === 'low_value' && $proofStrength < self::NEEDS_EVIDENCE_PROOF_THRESHOLD) {
                    $needsEvidence[] = array_merge($entry, [
                        'retirement_reason' => $reason,
                        'proof_strength'    => $proofStrength,
                        'decision'          => self::DECISION_NEEDS_EVIDENCE,
                    ]);
                    continue;
                }

                $replacementOrRespec = $this->replacementOrRespec($c);
                $retired[] = array_merge($entry, [
                    'retirement_reason'     => $reason,
                    'evidence_ref'          => $reason,
                    'affected_capability'   => $this->capabilityFrom($c),
                    'safe_next_action'      => $replacementOrRespec['action'] === 'replace' ? 'replace_with_replacement' : 'investigate_and_respec',
                    'replacement_or_respec' => $replacementOrRespec,
                    'decision'              => $replacementOrRespec['action'] === 'replace' ? self::DECISION_MERGE : self::DECISION_RETIRE,
                    'retirement_priority'   => self::RETIREMENT_PRIORITY[$reason] ?? 1,
                ]);
                continue;
            }

            $ineligible[] = array_merge($entry, ['decision' => self::DECISION_KEEP]);
        }

        return [
            'schema_version'      => self::SCHEMA,
            'retired'             => $retired,
            'protected'           => $protected,
            'ineligible'          => $ineligible,
            'needs_evidence'      => $needsEvidence,
            'total_retired'       => count($retired),
            'total_protected'     => count($protected),
            'total_needs_evidence' => count($needsEvidence),
            'value_threshold'     => $valueThreshold,
        ];
    }

    private function retirementReason(array $c, float $threshold): ?string
    {
        // Priority order — first match wins.
        if ((bool) ($c['is_duplicate'] ?? false)) {
            return 'duplicate';
        }
        if ((bool) ($c['is_stale'] ?? false)) {
            return 'stale';
        }
        if ((bool) ($c['stale_without_commit'] ?? false)) {
            return 'stale_without_commit';
        }
        if ((float) ($c['proxy_risk'] ?? 0.0) >= self::PROXY_RISK_THRESHOLD) {
            return 'high_proxy_risk';
        }
        if ((float) ($c['template_similarity'] ?? 0.0) >= self::TEMPLATE_SIMILARITY_THRESHOLD) {
            return 'high_template_similarity';
        }
        if ((int) ($c['repeated_give_back_count'] ?? 0) >= self::GIVE_BACK_THRESHOLD) {
            return 'repeated_give_back';
        }
        if ((int) ($c['quarantine_count'] ?? 0) >= self::QUARANTINE_COUNT_THRESHOLD) {
            return 'over_quarantined';
        }
        if ((float) ($c['value_estimate'] ?? 1.0) < $threshold) {
            return 'low_value';
        }

        return null;
    }

    /** @return array{action:string, replacement_candidate_id:string|null} */
    private function replacementOrRespec(array $c): array
    {
        $replacementId = isset($c['replacement_candidate']) && $c['replacement_candidate'] !== ''
            ? (string) $c['replacement_candidate']
            : null;

        return [
            'action'                   => $replacementId !== null ? 'replace' : 'respec_required',
            'replacement_candidate_id' => $replacementId,
        ];
    }

    private function capabilityFrom(array $c): string
    {
        $cap = (string) ($c['capability'] ?? '');
        if ($cap !== '') {
            return $cap;
        }
        $id = (string) ($c['id'] ?? '');
        $parts = explode('-', $id, 2);

        return $parts[0] !== '' ? $parts[0] : 'unknown';
    }
}
