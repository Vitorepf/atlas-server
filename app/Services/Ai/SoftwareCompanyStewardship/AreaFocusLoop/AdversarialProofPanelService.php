<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Independent adversarial proof panel — second line of defense BEFORE merge.
 *
 * After the owner-flow completes (Fase 2 preflight), after the final-delivery and
 * language-quality gates, and after the AP-806 workcell judge ACCEPTS, this panel
 * runs N independent verifiers whose single mandate is to REFUTE the candidacy:
 *
 *   V1 outcome_achievement  — does the produced diff actually deliver the finding
 *                             (changed files exist, intersect the finding scope)?
 *   V2 validation_honesty   — is the recorded validation a real, passed run, not
 *                             coverage theater (ran=true, passed=true, has commands)?
 *   V3 regression_detection — does the changed product code carry scaffold / TODO /
 *                             placeholder / not-implemented markers (independent of
 *                             the final-delivery gate's own scan)?
 *   V4 metric_measurement   — when an outcome was claimed measured, is the metric
 *                             attribution honest (outcome_met true, real numbers)?
 *
 * Each verifier is DEFAULT-SKEPTICAL: it refutes when the evidence to clear the
 * candidate is absent or contradictory. The panel fails CLOSED — the merge is
 * allowed ONLY when NO verifier refutes (a single honest refutation blocks). The
 * panel never invokes a provider; it judges the cycle's own already-produced
 * evidence, so it adds zero provider cost and is fully deterministic.
 *
 * Backward compatibility: a cycle whose evidence clears every verifier yields
 * merge_allowed=true and the downstream flow is byte-identical to before.
 */
final class AdversarialProofPanelService implements AdversarialProofPanel
{
    public const SCHEMA = 'atlas.proof_panel.verdict.v1';

    public const BLOCKER_PREFIX = 'adversarial_proof_refutation';

    /**
     * Markers of self-declared incompleteness in product code. Mirrors the spirit
     * of the final-delivery law but is evaluated INDEPENDENTLY here so a verifier
     * can refute even if the upstream gate's heuristics let something slip.
     *
     * @var list<string>
     */
    private const INCOMPLETENESS_MARKERS = [
        'TODO',
        'FIXME',
        'XXX',
        'placeholder',
        'not implemented',
        'not_implemented',
        'unimplemented',
        'scaffold',
        'shape-only',
        'shape only',
        'stub out',
        'to be implemented',
    ];

    /**
     * Refute a merge candidate. The input is the same executed + committed cycle
     * facts the AP-806 workcell judge received, optionally enriched with the
     * changed product file contents under `changed_file_contents` so V3 can scan
     * the real code about to merge without re-reading the worktree.
     *
     * @param  array<string,mixed>  $cycle
     * @return array{
     *     schema_version: string,
     *     cycle_id: string,
     *     verifier_count: int,
     *     verifier_verdicts: list<array{verifier:string,refuted:bool,detail:string}>,
     *     refuted_count: int,
     *     majority_refuted: bool,
     *     merge_allowed: bool,
     *     reason: string,
     * }
     */
    public function refute(array $cycle): array
    {
        $verdicts = [
            $this->verifyOutcomeAchievement($cycle),
            $this->verifyValidationHonesty($cycle),
            $this->verifyRegressionDetection($cycle),
            $this->verifyMetricMeasurement($cycle),
        ];

        $refuters = array_values(array_filter($verdicts, static fn (array $v): bool => $v['refuted'] === true));
        $refutedCount = count($refuters);
        $verifierCount = count($verdicts);

        // Fail CLOSED: a single honest refutation withholds the merge. The
        // majority signal is recorded for audit but never relaxes the gate.
        $mergeAllowed = $refutedCount === 0;

        $reason = $mergeAllowed
            ? 'no_verifier_refuted'
            : implode('; ', array_map(
                static fn (array $v): string => $v['verifier'].':'.$v['detail'],
                $refuters,
            ));

        return [
            'schema_version' => self::SCHEMA,
            'cycle_id' => (string) ($cycle['cycle_id'] ?? ''),
            'verifier_count' => $verifierCount,
            'verifier_verdicts' => $verdicts,
            'refuted_count' => $refutedCount,
            'majority_refuted' => $refutedCount * 2 > $verifierCount,
            'merge_allowed' => $mergeAllowed,
            'reason' => $reason,
        ];
    }

    /**
     * V1 — the candidate must actually produce a diff and that diff must intersect
     * the declared scope (allowed/affected files). An empty diff or a diff that
     * touches nothing the finding was scoped to is refuted as not-achieved.
     *
     * @param  array<string,mixed>  $cycle
     * @return array{verifier:string,refuted:bool,detail:string}
     */
    private function verifyOutcomeAchievement(array $cycle): array
    {
        $verifier = 'outcome_achievement';
        $changed = AreaFocusStringListNormalizer::coercedStringValues($cycle['changed_files'] ?? []);
        if ($changed === []) {
            return $this->verdict($verifier, true, 'no_changed_files');
        }

        $scope = AreaFocusStringListNormalizer::uniqueMergedStringValues(
            AreaFocusStringListNormalizer::coercedStringValues($cycle['allowed_files'] ?? []),
            AreaFocusStringListNormalizer::coercedStringValues(data_get($cycle, 'selected_finding.affected_files', [])),
        );

        // No declared scope => the diff itself is the evidence (cannot refute on
        // scope intersection we cannot compute). Existing behavior is unchanged.
        if ($scope === []) {
            return $this->verdict($verifier, false, 'diff_present_no_declared_scope');
        }

        foreach ($changed as $file) {
            if (in_array($file, $scope, true)) {
                return $this->verdict($verifier, false, 'diff_intersects_declared_scope');
            }
        }

        return $this->verdict($verifier, true, 'diff_outside_declared_scope');
    }

    /**
     * V2 — the recorded validation must be a real, passed run with commands, not
     * an empty "ran=true" shell. A missing, unrun, failed or command-less
     * validation is refuted as coverage theater.
     *
     * @param  array<string,mixed>  $cycle
     * @return array{verifier:string,refuted:bool,detail:string}
     */
    private function verifyValidationHonesty(array $cycle): array
    {
        $verifier = 'validation_honesty';
        $validation = is_array($cycle['validation'] ?? null) ? $cycle['validation'] : [];

        if (($validation['ran'] ?? false) !== true) {
            return $this->verdict($verifier, true, 'validation_not_run');
        }
        if (($validation['passed'] ?? false) !== true) {
            return $this->verdict($verifier, true, 'validation_not_passed');
        }
        $commands = AreaFocusStringListNormalizer::coercedStringValues($validation['commands'] ?? []);
        if ($commands === []) {
            return $this->verdict($verifier, true, 'validation_has_no_commands');
        }

        return $this->verdict($verifier, false, 'validation_ran_and_passed');
    }

    /**
     * V3 — the changed PRODUCT code must not carry self-declared incompleteness
     * markers. Evaluated on `changed_file_contents` (rel-path => contents), which
     * is the real code about to merge. Independent of the final-delivery gate.
     *
     * @param  array<string,mixed>  $cycle
     * @return array{verifier:string,refuted:bool,detail:string}
     */
    private function verifyRegressionDetection(array $cycle): array
    {
        $verifier = 'regression_detection';
        $contents = is_array($cycle['changed_file_contents'] ?? null) ? $cycle['changed_file_contents'] : [];

        // AUTÓPSIA 12/06: quando o caller fornece as linhas ADICIONADAS pelo diff
        // (`changed_added_lines`), o scan de incompletude é DIFF-SCOPED — só o que a
        // mudança introduziu pode refutá-la. O scan file-scoped punia alvos reais do
        // Atlas por TODOs pré-existentes que o diff nem tocou (100% das propostas de
        // discovery refutadas para sempre). Sem a chave, o comportamento file-scoped
        // original é preservado (callers legados inalterados).
        $addedLines = is_array($cycle['changed_added_lines'] ?? null) ? $cycle['changed_added_lines'] : [];
        if ($addedLines !== []) {
            $contents = $addedLines; // deleções puras não podem introduzir marker
        }

        foreach ($contents as $rel => $body) {
            if (! is_string($rel) || ! is_string($body)) {
                continue;
            }
            // Test files legitimately reference markers (assertions about them);
            // scan PRODUCT files only, matching the final-delivery law's scope.
            if (str_contains($rel, 'tests/') || str_ends_with($rel, 'Test.php')) {
                continue;
            }
            $haystack = strtolower($body);
            foreach (self::INCOMPLETENESS_MARKERS as $marker) {
                if (str_contains($haystack, strtolower($marker))) {
                    return $this->verdict($verifier, true, 'incompleteness_marker:'.$marker.':'.$rel);
                }
            }
        }

        return $this->verdict($verifier, false, 'no_incompleteness_markers');
    }

    /**
     * V4 — when the cycle CLAIMS an outcome was measured, the measurement must be
     * honest (a present metric with outcome_met=true). A measured=true claim with
     * no metric, or with outcome_met=false, is refuted. When no outcome was
     * claimed, there is nothing to refute (measure-exempt cycles are unchanged).
     *
     * @param  array<string,mixed>  $cycle
     * @return array{verifier:string,refuted:bool,detail:string}
     */
    private function verifyMetricMeasurement(array $cycle): array
    {
        $verifier = 'metric_measurement';

        if (($cycle['outcome_measured'] ?? false) !== true) {
            return $this->verdict($verifier, false, 'no_measured_outcome_claimed');
        }

        $metric = is_array($cycle['outcome_metric'] ?? null) ? $cycle['outcome_metric'] : [];
        if ($metric === []) {
            return $this->verdict($verifier, true, 'measured_claim_without_metric');
        }
        if (($metric['outcome_met'] ?? false) !== true) {
            return $this->verdict($verifier, true, 'measured_claim_metric_not_met');
        }

        return $this->verdict($verifier, false, 'measured_outcome_attributed');
    }

    /**
     * @return array{verifier:string,refuted:bool,detail:string}
     */
    private function verdict(string $verifier, bool $refuted, string $detail): array
    {
        return ['verifier' => $verifier, 'refuted' => $refuted, 'detail' => $detail];
    }
}
