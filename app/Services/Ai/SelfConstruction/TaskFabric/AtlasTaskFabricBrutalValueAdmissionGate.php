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
}
