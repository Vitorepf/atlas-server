<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class MergeTruthValidatorSupport
{
    /**
     * @param  array<string,mixed>  $input
     * @return array{string,string,string,string,string,bool}
     */
    public function normalizedInput(array $input): array
    {
        return [
            trim((string) ($input['main_before'] ?? '')),
            trim((string) ($input['main_after'] ?? '')),
            trim((string) ($input['target_ref_before'] ?? '')),
            trim((string) ($input['target_ref_after'] ?? '')),
            trim((string) ($input['merge_target'] ?? MergeTruthValidator::TARGET_NONE)) ?: MergeTruthValidator::TARGET_NONE,
            (bool) ($input['merge_performed_to_base'] ?? false),
        ];
    }

    public function refAdvanced(string $before, string $after): bool
    {
        return $before !== '' && $after !== '' && $before !== $after;
    }

    /**
     * @return list<string>
     */
    public function violations(bool $performedToBase, string $mergeTarget, bool $mainAdvanced, bool $targetAdvanced): array
    {
        $violations = [];
        if ($performedToBase && ! $mainAdvanced) {
            $violations[] = 'merge_performed_to_base_claimed_but_main_not_advanced';
        }
        if ($mergeTarget === MergeTruthValidator::TARGET_LANE && $mainAdvanced) {
            $violations[] = 'lane_target_but_main_advanced_inconsistent';
        }
        if ($targetAdvanced && ! $mainAdvanced && $mergeTarget !== MergeTruthValidator::TARGET_MAIN) {
            $violations[] = 'lane_only_advance_not_counted_as_real_merge';
        }
        if (! $mainAdvanced && ! $targetAdvanced) {
            $violations[] = 'noop_or_already_up_to_date';
        }

        return $violations;
    }

    public function mergeReal(string $mergeTarget, bool $mainAdvanced, bool $performedToBase): bool
    {
        return $mergeTarget === MergeTruthValidator::TARGET_MAIN && $mainAdvanced && $performedToBase;
    }

    public function reason(bool $mergeReal, bool $mainAdvanced): string
    {
        return $mergeReal
            ? 'real_merge_main_advanced'
            : ($mainAdvanced ? 'main_advanced_but_not_governed_base_merge' : 'main_did_not_advance_no_real_merge');
    }
}
