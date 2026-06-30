<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure finality gate. Assembles evidence and reports precise blockers before
 * the ExternalBrain subsystem can be declared final.
 *
 * Input facts:
 *   dimensions          — list of {name, is_proven, is_stale, is_unwired, is_contradicted,
 *                          is_unintegrated, is_undocumented, is_queue_unsafe,
 *                          has_outcome_learning, evidence_refs?}.
 *   required_dimensions — list of dimension names that must all pass to reach finality.
 *                          Defaults to every dimension in the input list if omitted.
 *
 * Blocker check order per required dimension:
 *   1. missing             — dimension not present in the input list at all.
 *   2. contradicted        — is_contradicted === true.
 *   3. stale               — is_stale === true.
 *   4. unwired             — is_unwired === true.
 *   5. unintegrated        — is_unintegrated === true.
 *   6. undocumented        — is_undocumented === true.
 *   7. queue_unsafe        — is_queue_unsafe === true.
 *   8. no_outcome_learning — has_outcome_learning === false.
 *   9. unproven            — is_proven !== true OR evidence_refs is empty.
 *
 * A dimension is satisfied only when it passes all nine checks.
 *
 * is_final = true when every required dimension is satisfied.
 *
 * readiness_band:
 *   final      — finality_score = 1.0
 *   near_final — finality_score >= 0.80
 *   developing — finality_score >= 0.50
 *   incomplete — finality_score < 0.50
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasExternalBrainFinalityEvidenceBundle
{
    public const SCHEMA = 'atlas.external_brain.finality_evidence_bundle.v1';

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function assemble(array $facts): array
    {
        $rawDimensions = is_array($facts['dimensions']          ?? null) ? $facts['dimensions']          : [];
        $required      = is_array($facts['required_dimensions'] ?? null) ? $facts['required_dimensions'] : null;

        // Index dimensions by name.
        $indexed = [];
        foreach ($rawDimensions as $dim) {
            if (! is_array($dim) || ! isset($dim['name'])) {
                continue;
            }
            $indexed[(string) $dim['name']] = $dim;
        }

        // If no explicit required list, require all present dimensions.
        $requiredNames = $required ?? array_keys($indexed);

        $blockers            = [];
        $satisfiedDimensions = [];
        $bundleEvidence      = [];

        foreach ($requiredNames as $name) {
            $name = (string) $name;

            if (! array_key_exists($name, $indexed)) {
                $blockers[] = ['dimension' => $name, 'blocker_type' => 'missing', 'evidence_refs' => []];
                continue;
            }

            $dim    = $indexed[$name];
            $refs   = is_array($dim['evidence_refs'] ?? null)
                ? array_values(array_map('strval', $dim['evidence_refs']))
                : [];
            $blocker = null;

            if ((bool) ($dim['is_contradicted']    ?? false)) {
                $blocker = 'contradicted';
            } elseif ((bool) ($dim['is_stale']     ?? false)) {
                $blocker = 'stale';
            } elseif ((bool) ($dim['is_unwired']   ?? false)) {
                $blocker = 'unwired';
            } elseif ((bool) ($dim['is_unintegrated'] ?? false)) {
                $blocker = 'unintegrated';
            } elseif ((bool) ($dim['is_undocumented'] ?? false)) {
                $blocker = 'undocumented';
            } elseif ((bool) ($dim['is_queue_unsafe'] ?? false)) {
                $blocker = 'queue_unsafe';
            } elseif (! (bool) ($dim['has_outcome_learning'] ?? false)) {
                $blocker = 'no_outcome_learning';
            } elseif (! (bool) ($dim['is_proven'] ?? false) || $refs === []) {
                $blocker = 'unproven';
            }

            if ($blocker !== null) {
                $blockers[] = ['dimension' => $name, 'blocker_type' => $blocker, 'evidence_refs' => $refs];
            } else {
                $satisfiedDimensions[] = $name;
                foreach ($refs as $ref) {
                    $bundleEvidence[$ref] = true;
                }
            }
        }

        $isFinal       = empty($blockers);
        $totalRequired = count($requiredNames);
        $satisfied     = count($satisfiedDimensions);
        $finalityScore = $totalRequired > 0 ? round($satisfied / $totalRequired, 4) : 1.0;
        $readinessBand = $this->readinessBand($finalityScore, $isFinal);

        $missingCategories = array_values(array_unique(array_column($blockers, 'dimension')));

        return [
            'schema_version'             => self::SCHEMA,
            'is_final'                   => $isFinal,
            'blockers'                   => $blockers,
            'satisfied_dimensions'       => $satisfiedDimensions,
            'bundle_evidence'            => array_values(array_keys($bundleEvidence)),
            'finality_score'             => $finalityScore,
            'readiness_percent'          => round($finalityScore * 100, 2),
            'readiness_band'             => $readinessBand,
            'missing_categories'         => $missingCategories,
            'next_highest_leverage_gap'  => $blockers[0]['dimension'] ?? null,
            'finality_summary'           => $this->summary($isFinal, $totalRequired, $satisfied, $blockers),
        ];
    }

    private function readinessBand(float $score, bool $isFinal): string
    {
        if ($isFinal) {
            return 'final';
        }
        if ($score >= 0.80) {
            return 'near_final';
        }
        if ($score >= 0.50) {
            return 'developing';
        }

        return 'incomplete';
    }

    private function summary(bool $isFinal, int $total, int $satisfied, array $blockers): string
    {
        if ($isFinal) {
            return "all $total required dimensions satisfied; ExternalBrain finality declared";
        }
        $blockerTypes = implode(', ', array_unique(array_column($blockers, 'blocker_type')));

        return "$satisfied/$total dimensions satisfied; blockers: $blockerTypes";
    }
}
