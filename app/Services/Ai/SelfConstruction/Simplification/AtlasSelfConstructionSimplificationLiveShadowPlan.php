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

    /** @var list<string> */
    private const EVIDENCE_REQUIRED = [
        'shadow_run_receipts',
        'comparison_report',
        'promotion_approval',
    ];

    /**
     * @param  array{
     *   samples?: list<array{old_output?: array<string,mixed>, new_output?: array<string,mixed>}>,
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

        foreach ($samples as $index => $sample) {
            $oldOutput = (array) ($sample['old_output'] ?? []);
            $newOutput = (array) ($sample['new_output'] ?? []);

            foreach ($comparedFields as $field) {
                $oldValue = $oldOutput[$field] ?? null;
                $newValue = $newOutput[$field] ?? null;

                if ($this->diverges($oldValue, $newValue, $toleratedDrift[$field] ?? null)) {
                    $mismatches[] = [
                        'sample_index' => $index,
                        'field' => $field,
                        'old_value' => $oldValue,
                        'new_value' => $newValue,
                    ];
                }
            }
        }

        $blockers = [];
        if ($sampleCount < $minimumSampleCount) {
            $blockers[] = 'sample_count_below_minimum:'.$sampleCount.'<'.$minimumSampleCount;
        }
        if ($mismatches !== []) {
            $blockers[] = 'field_mismatches_detected:'.count($mismatches);
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

    private function diverges(mixed $oldValue, mixed $newValue, ?float $tolerance): bool
    {
        if ($tolerance === null) {
            return $oldValue !== $newValue;
        }

        if (! is_numeric($oldValue) || ! is_numeric($newValue)) {
            return $oldValue !== $newValue;
        }

        return abs((float) $oldValue - (float) $newValue) > $tolerance;
    }
}
