<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Pure plan: before a collapsed circuit may replace the organs it merges, the old
 * and new circuits must run side by side in shadow mode over a minimum sample of
 * real inputs. Any non-tolerated field divergence, or too few samples, blocks
 * promotion — a circuit collapse is never trusted on a single lucky run.
 *
 * Pure / deterministic. No I/O — callers supply the already-recorded shadow samples.
 */
final class AtlasSelfConstructionSimplificationLiveShadowPlan
{
    public const SCHEMA = 'atlas.self_construction.simplification_live_shadow_plan.v1';

    private const DEFAULT_MINIMUM_SAMPLE_COUNT = 10;

    private const MISMATCH_MATERIAL = 'material';

    private const MISMATCH_TOLERATED = 'tolerated';

    /** @var list<string> */
    private const EVIDENCE_REQUIRED = [
        'shadow_run_receipts',
        'comparison_report',
        'promotion_approval',
        'material_diff_report',
        'sample_identity_receipts',
    ];

    /**
     * @param  array{
     *   samples?: list<array{sample_id?: string, old_output?: array<string,mixed>, new_output?: array<string,mixed>}>,
     *   compared_fields?: list<string>,
     *   tolerated_drift?: array<string, float>,
     *   minimum_sample_count?: int,
     * }  $shadowRun
     * @return array{
     *   schema: string,
     *   sample_count: int,
     *   compared_fields: list<string>,
     *   tolerated_drift: array<string, float>,
     *   mismatches: list<array<string,mixed>>,
     *   promotion_allowed: bool,
     *   blockers: list<string>,
     *   evidence_required: list<string>,
     * }
     */
    public function plan(array $shadowRun): array
    {
        $samples = array_values((array) ($shadowRun['samples'] ?? []));
        $comparedFields = array_values((array) ($shadowRun['compared_fields'] ?? []));
        $toleratedDrift = (array) ($shadowRun['tolerated_drift'] ?? []);
        $minimumSampleCount = max(1, (int) ($shadowRun['minimum_sample_count'] ?? self::DEFAULT_MINIMUM_SAMPLE_COUNT));

        $sampleCount = count($samples);
        $mismatches = [];
        $blockers = [];

        // Stable sample identity: every sample must carry a non-empty, unique sample_id — without
        // it a mismatch (or a clean match) can't be traced back to a specific real input, so a
        // circuit collapse could silently promote on unidentifiable samples.
        $seenSampleIds = [];
        foreach ($samples as $index => $sample) {
            $sampleId = trim((string) ($sample['sample_id'] ?? ''));
            if ($sampleId === '') {
                $blockers[] = 'missing_sample_id:'.$index;

                continue;
            }
            if (isset($seenSampleIds[$sampleId])) {
                $blockers[] = 'duplicate_sample_id:'.$sampleId;

                continue;
            }
            $seenSampleIds[$sampleId] = true;
        }

        foreach ($samples as $index => $sample) {
            $oldOutput = (array) ($sample['old_output'] ?? []);
            $newOutput = (array) ($sample['new_output'] ?? []);

            foreach ($comparedFields as $field) {
                $oldValue = $oldOutput[$field] ?? null;
                $newValue = $newOutput[$field] ?? null;

                $classification = $this->classifyDivergence($oldValue, $newValue, $toleratedDrift[$field] ?? null);
                if ($classification !== null) {
                    $mismatches[] = [
                        'sample_index' => $index,
                        'field' => $field,
                        'old_value' => $oldValue,
                        'new_value' => $newValue,
                        'classification' => $classification,
                    ];
                }
            }
        }

        $materialMismatchCount = count(array_filter(
            $mismatches,
            static fn (array $m): bool => $m['classification'] === self::MISMATCH_MATERIAL,
        ));

        if ($sampleCount < $minimumSampleCount) {
            $blockers[] = 'sample_count_below_minimum:'.$sampleCount.'<'.$minimumSampleCount;
        }
        if ($materialMismatchCount > 0) {
            $blockers[] = 'material_field_mismatches_detected:'.$materialMismatchCount;
        }

        return [
            'schema' => self::SCHEMA,
            'sample_count' => $sampleCount,
            'compared_fields' => $comparedFields,
            'tolerated_drift' => $toleratedDrift,
            'mismatches' => $mismatches,
            'promotion_allowed' => $blockers === [],
            'blockers' => $blockers,
            'evidence_required' => self::EVIDENCE_REQUIRED,
        ];
    }

    /** Returns null when the values are identical (no mismatch at all); else 'material' or 'tolerated'. */
    private function classifyDivergence(mixed $oldValue, mixed $newValue, ?float $tolerance): ?string
    {
        if ($oldValue === $newValue) {
            return null;
        }

        if ($tolerance !== null && is_numeric($oldValue) && is_numeric($newValue)
            && abs((float) $oldValue - (float) $newValue) <= $tolerance) {
            return self::MISMATCH_TOLERATED;
        }

        return self::MISMATCH_MATERIAL;
    }

    /**
     * Compiles the BEFORE-the-fact shadow run plan for a risky consolidation candidate: the old
     * and new circuits run side by side over the same sampled inputs. A candidate that mutates
     * side effects (writes, sends, dispatches) without proven isolation is blocked outright — a
     * live shadow run must never actually double-execute an uncontained side effect.
     *
     * @param  array{
     *   candidate_id?:           string,
     *   old_circuit?:            string,
     *   new_circuit?:            string,
     *   sample_input_refs?:      list<string>,
     *   has_side_effects?:       bool,
     *   side_effects_isolated?:  bool,
     *   isolation_proof_ref?:    string,
     *   minimum_sample_count?:   int,
     * }  $candidate
     * @return array<string,mixed>
     */
    public function planShadowRun(array $candidate): array
    {
        $candidateId = trim((string) ($candidate['candidate_id'] ?? ''));
        $oldCircuit = trim((string) ($candidate['old_circuit'] ?? ''));
        $newCircuit = trim((string) ($candidate['new_circuit'] ?? ''));
        $sampleInputRefs = array_values(array_unique(array_map('strval', (array) ($candidate['sample_input_refs'] ?? []))));
        $hasSideEffects = (bool) ($candidate['has_side_effects'] ?? false);
        $sideEffectsIsolated = (bool) ($candidate['side_effects_isolated'] ?? false);
        $isolationProofRef = trim((string) ($candidate['isolation_proof_ref'] ?? ''));
        $requiredSampleFloor = max(1, (int) ($candidate['minimum_sample_count'] ?? self::DEFAULT_MINIMUM_SAMPLE_COUNT));

        // A claimed isolation flag with no concrete proof reference is just as unsafe as no
        // isolation at all — either omission blocks a side-effecting candidate outright.
        if ($hasSideEffects && (! $sideEffectsIsolated || $isolationProofRef === '')) {
            $blockers = [];
            if (! $sideEffectsIsolated) {
                $blockers[] = 'side_effects_not_isolated';
            }
            if ($isolationProofRef === '') {
                $blockers[] = 'missing_isolation_proof_ref';
            }

            return [
                'schema' => self::SCHEMA,
                'action' => 'blocked',
                'candidate_id' => $candidateId,
                'blockers' => $blockers,
                'shadow_steps' => [],
            ];
        }

        $shadowSteps = [];
        foreach ($sampleInputRefs as $ref) {
            $shadowSteps[] = "shadow_run:{$oldCircuit}:{$ref}";
            $shadowSteps[] = "shadow_run:{$newCircuit}:{$ref}";
        }

        return [
            'schema' => self::SCHEMA,
            'action' => 'shadow_run_planned',
            'candidate_id' => $candidateId,
            'shadow_steps' => $shadowSteps,
            'sample_input_refs' => $sampleInputRefs,
            'diff_receipt_required' => true,
            'required_sample_floor' => $requiredSampleFloor,
            'promotion_requirement' => 'zero_material_diffs_and_sample_count_at_or_above_floor:'.$requiredSampleFloor,
            'blockers' => [],
        ];
    }
}
