<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\EngineeringKernel\EngineeringRoleRoster;

/**
 * Independent, conjunctive adjudication gate. Tests alone or author-authored defenses
 * can hide defects, so a green verdict must survive: a blinded judge (never shown the
 * author's defense, never the same model family as the author at high risk), a hidden +
 * implementation-independent oracle set, unignored security/replay results, all 22 role
 * dispositions with no critical block, and a real, elapsed, non-contradictory outcome.
 * The judge/investigator models run out of process; this gate enforces the contract they
 * must satisfy and records judge family/version/disagreements. Pure over arrays.
 */
final class AdversarialAdjudicationGate
{
    public const SCHEMA = 'atlas.rivals2.adversarial_adjudication.v1';

    /** @var list<string> */
    private const HIGH_RISK = ['R4', 'R5'];

    /**
     * @param  array<string,mixed>  $bundle
     * @return array<string,mixed>
     */
    public function evaluate(array $bundle): array
    {
        $blockers = [];
        $uncertainty = [];

        $judge = (array) ($bundle['judge'] ?? []);
        $author = (array) ($bundle['author'] ?? []);
        $risk = strtoupper((string) ($bundle['risk_class'] ?? 'R0'));
        $verdictGreen = (bool) ($bundle['verdict_green'] ?? false);

        // 1. the judge must never receive the author's defense/explanation
        if (($judge['saw_author_defense'] ?? false) === true) {
            $blockers[] = 'judge_saw_author_defense';
        }
        // 2. author and judge must not share a model family at high risk
        $judgeFamily = (string) ($judge['model_family'] ?? '');
        $authorFamily = (string) ($author['model_family'] ?? '');
        if (in_array($risk, self::HIGH_RISK, true) && $judgeFamily !== '' && $judgeFamily === $authorFamily) {
            $blockers[] = 'author_judge_same_family_high_risk';
        }

        // 3-5. oracle composition: hidden proof + an implementation-independent oracle,
        // and security/replay results are never scored away
        $oracles = (array) ($bundle['oracles'] ?? []);
        if (($oracles['hidden_tests'] ?? false) !== true) {
            $blockers[] = 'hidden_test_omitted';
        }
        if (($oracles['implementation_independent'] ?? false) !== true) {
            $blockers[] = 'implementation_dependent_oracle';
        }
        if (($oracles['security'] ?? null) === 'failed' || ($oracles['replay'] ?? null) === 'failed') {
            $blockers[] = 'security_or_replay_failure_ignored';
        }

        // 6-7. outcomes: a green verdict cannot precede an observed outcome, and a
        // contradictory outcome is never ignored
        $outcome = (array) ($bundle['outcome'] ?? []);
        $observed = ($outcome['observed'] ?? false) === true;
        if (! $observed && $verdictGreen) {
            $blockers[] = 'missing_outcome_scored_green';
        }
        if (($outcome['contradictory'] ?? false) === true) {
            $blockers[] = 'contradictory_outcome_ignored';
        }
        if (! $observed || ($outcome['elapsed'] ?? false) !== true) {
            $uncertainty[] = 'outcome_unelapsed_or_unknown';
        }

        // all 22 canonical role dispositions present, none a critical block
        $dispositions = (array) ($bundle['role_dispositions'] ?? []);
        foreach (EngineeringRoleRoster::CANONICAL_ROLES as $role) {
            $entry = $dispositions[$role] ?? null;
            $status = is_array($entry) ? ($entry['status'] ?? null) : $entry;
            if ($status === null) {
                $blockers[] = 'role_disposition_missing:'.$role;
            } elseif ($status === 'block') {
                $blockers[] = 'critical_block:'.$role;
            } elseif (! in_array($status, ['pass', 'not_applicable'], true)) {
                $blockers[] = 'role_disposition_invalid:'.$role;
            }
        }

        $blockers = array_values(array_unique($blockers));
        $uncertainty = array_values(array_unique($uncertainty));

        return [
            'schema_version' => self::SCHEMA,
            'blockers' => $blockers,
            'uncertainty' => $uncertainty,
            'judge_family' => $judgeFamily !== '' ? $judgeFamily : null,
            'judge_version' => ((string) ($judge['version'] ?? '')) !== '' ? (string) $judge['version'] : null,
            'disagreements' => array_values((array) ($judge['disagreements'] ?? [])),
            // conjunctive: no blocker AND no residual uncertainty (outcome elapsed)
            'claim_eligible' => $blockers === [] && $uncertainty === [],
        ];
    }
}
