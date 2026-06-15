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
        $support = new MergeTruthValidatorSupport;
        [
            $mainBefore,
            $mainAfter,
            $targetBefore,
            $targetAfter,
            $mergeTarget,
            $performedToBase,
        ] = $support->normalizedInput($input);

        $mainAdvanced = $support->refAdvanced($mainBefore, $mainAfter);
        $targetAdvanced = $support->refAdvanced($targetBefore, $targetAfter);
        $mergeReal = $support->mergeReal($mergeTarget, $mainAdvanced, $performedToBase);

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
            'violations' => AreaFocusStringListNormalizer::uniqueStringValues($support->violations($performedToBase, $mergeTarget, $mainAdvanced, $targetAdvanced)),
            'reason' => $support->reason($mergeReal, $mainAdvanced),
        ];
    }
}
