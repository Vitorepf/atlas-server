<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Merge-truth enforcement (operator mandate, 2026-05-31): a merge/promotion only
 * counts as a REAL merge when main actually advanced. "Already up to date", a
 * no-op, or a lane-ONLY advance (the integration lane moved but main did not)
 * must NEVER be counted as a real merge / autonomy.
 *
 * PURE: the caller captures the four refs around the operation and whether the
 * governor reported a base merge; this class returns the truth.
 *
 * merge_real === true  IFF  merge_target === 'main'
 *                            AND main_before !== main_after (main advanced)
 *                            AND merge_performed_to_base === true.
 */
final class MergeTruthValidator
{
    public const SCHEMA_VERSION = 'atlas.software_company_stewardship.merge_truth.v1';

    public const TARGET_MAIN = 'main';

    public const TARGET_LANE = 'integration_lane';

    public const TARGET_NONE = 'none';

    /**
     * @param  array<string,mixed>  $input  {main_before, main_after, target_ref_before, target_ref_after, merge_target, merge_performed_to_base:bool, merge_hash?}
     * @return array{schema_version:string, merge_real:bool, main_advanced:bool, target_advanced:bool, merge_target:string, main_before:string, main_after:string, target_ref_before:string, target_ref_after:string, violations:list<string>, reason:string}
     */
    public function validate(array $input): array
    {
        $mainBefore = trim((string) ($input['main_before'] ?? ''));
        $mainAfter = trim((string) ($input['main_after'] ?? ''));
        $targetBefore = trim((string) ($input['target_ref_before'] ?? ''));
        $targetAfter = trim((string) ($input['target_ref_after'] ?? ''));
        $mergeTarget = trim((string) ($input['merge_target'] ?? self::TARGET_NONE)) ?: self::TARGET_NONE;
        $performedToBase = (bool) ($input['merge_performed_to_base'] ?? false);

        $mainAdvanced = $mainBefore !== '' && $mainAfter !== '' && $mainBefore !== $mainAfter;
        $targetAdvanced = $targetBefore !== '' && $targetAfter !== '' && $targetBefore !== $targetAfter;

        $violations = [];
        if ($performedToBase && ! $mainAdvanced) {
            $violations[] = 'merge_performed_to_base_claimed_but_main_not_advanced';
        }
        if ($mergeTarget === self::TARGET_LANE && $mainAdvanced) {
            $violations[] = 'lane_target_but_main_advanced_inconsistent';
        }
        if ($targetAdvanced && ! $mainAdvanced && $mergeTarget !== self::TARGET_MAIN) {
            $violations[] = 'lane_only_advance_not_counted_as_real_merge';
        }
        if (! $mainAdvanced && ! $targetAdvanced) {
            $violations[] = 'noop_or_already_up_to_date';
        }

        $mergeReal = $mergeTarget === self::TARGET_MAIN && $mainAdvanced && $performedToBase;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'merge_real' => $mergeReal,
            'main_advanced' => $mainAdvanced,
            'target_advanced' => $targetAdvanced,
            'merge_target' => $mergeTarget,
            'main_before' => $mainBefore,
            'main_after' => $mainAfter,
            'target_ref_before' => $targetBefore,
            'target_ref_after' => $targetAfter,
            'violations' => AreaFocusStringListNormalizer::uniqueStringValues($violations),
            'reason' => $mergeReal
                ? 'real_merge_main_advanced'
                : ($mainAdvanced ? 'main_advanced_but_not_governed_base_merge' : 'main_did_not_advance_no_real_merge'),
        ];
    }
}
