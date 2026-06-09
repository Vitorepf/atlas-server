<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;

final class PlanSliceReadModel
{
    public const STATE_DELIVERED = 'delivered';

    public const STATE_BLOCKED = 'blocked';

    /**
     * @param  array<string,bool>|list<string>  $skip
     * @return array<string,bool>
     */
    public static function normalizeSkip(array $skip): array
    {
        $set = [];
        foreach ($skip as $key => $value) {
            if (is_int($key) && is_string($value)) {
                if ($value !== '') {
                    $set[$value] = true;
                }
            } elseif (is_string($key) && $value) {
                $set[$key] = true;
            }
        }

        return $set;
    }

    /**
     * @param  array<string,mixed>  $decomposedPlan
     * @return list<array<string,mixed>>
     */
    public static function orderedSlices(array $decomposedPlan): array
    {
        $slices = is_array($decomposedPlan['slices'] ?? null) ? $decomposedPlan['slices'] : [];
        $clean = [];
        foreach ($slices as $slice) {
            if (is_array($slice) && (string) ($slice['slice_id'] ?? '') !== '') {
                $clean[] = $slice;
            }
        }
        usort($clean, static fn (array $a, array $b): int => ((int) ($a['sequence'] ?? 0)) <=> ((int) ($b['sequence'] ?? 0)));

        return $clean;
    }

    /**
     * Preserve source order and the tracker-facing row shape for rollups.
     *
     * @param  array<string,mixed>  $plan
     * @return list<array{slice_id:string,depends_on:list<string>,finding_id:string,allowed_files:list<string>,objective:string,delivery:string,acceptance_criteria:list<string>,finding:array<string,mixed>}>
     */
    public static function planSlices(array $plan): array
    {
        $out = [];
        foreach (array_values(array_filter((array) ($plan['slices'] ?? []), 'is_array')) as $slice) {
            $sliceId = (string) ($slice['slice_id'] ?? '');
            if ($sliceId === '') {
                continue;
            }
            $finding = is_array($slice['finding'] ?? null) ? $slice['finding'] : [];
            $out[] = [
                'slice_id' => $sliceId,
                'depends_on' => AreaFocusStringListNormalizer::preserveStrings($slice['depends_on'] ?? []),
                'finding_id' => (string) ($finding['finding_id'] ?? ''),
                'allowed_files' => AreaFocusStringListNormalizer::preserveStrings($slice['allowed_files'] ?? []),
                'objective' => (string) ($slice['objective'] ?? ''),
                'delivery' => (string) ($slice['delivery'] ?? ''),
                'acceptance_criteria' => AreaFocusStringListNormalizer::preserveStrings($slice['acceptance_criteria'] ?? []),
                'finding' => $finding,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $slice
     * @param  array<string,mixed>  $sliceStates
     */
    public static function dependenciesDelivered(array $slice, array $sliceStates): bool
    {
        $deliveredSet = [];
        foreach ((array) ($slice['depends_on'] ?? []) as $dep) {
            $depId = (string) $dep;
            if ($depId === '') {
                continue;
            }
            $depRow = is_array($sliceStates[$depId] ?? null) ? $sliceStates[$depId] : [];
            if ((string) ($depRow['state'] ?? 'planned') === self::STATE_DELIVERED) {
                $deliveredSet[$depId] = true;
            }
        }

        return self::dependenciesSatisfied(
            array_values(array_filter(array_map(
                static fn ($dep): string => (string) $dep,
                (array) ($slice['depends_on'] ?? []),
            ), static fn (string $dep): bool => $dep !== '')),
            $deliveredSet
        );
    }

    /**
     * @param  list<string>  $dependsOn
     * @param  array<string,bool>  $deliveredSet
     */
    public static function dependenciesSatisfied(array $dependsOn, array $deliveredSet): bool
    {
        foreach ($dependsOn as $dep) {
            if (! ($deliveredSet[$dep] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string,mixed>>  $slices
     * @param  array<string,mixed>  $sliceStates
     */
    public static function dependencyOrderPreserved(array $slices, array $sliceStates): bool
    {
        $isDelivered = static function (string $id) use ($sliceStates): bool {
            $state = is_array($sliceStates[$id] ?? null) ? $sliceStates[$id] : [];

            return (string) ($state['state'] ?? '') === self::STATE_DELIVERED;
        };

        foreach ($slices as $slice) {
            $sliceId = (string) ($slice['slice_id'] ?? '');
            if (! $isDelivered($sliceId)) {
                continue;
            }
            foreach ((array) ($slice['depends_on'] ?? []) as $dep) {
                if (is_string($dep) && $dep !== '' && ! $isDelivered($dep)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $slice
     * @return list<string>
     */
    public static function allowedFiles(array $slice): array
    {
        return self::stringSet((array) ($slice['allowed_files'] ?? []));
    }

    /**
     * @param  array<string,mixed>  $slice
     * @param  array<string,mixed>  $cycle
     * @return list<string>
     */
    public static function integrationFiles(array $slice, array $cycle): array
    {
        return self::stringSet([
            ...self::allowedFiles($slice),
            ...(array) ($cycle['changed_files'] ?? []),
        ]);
    }

    /**
     * @param  list<string>  $files
     * @param  array<string,bool>  $claimed
     */
    public static function intersects(array $files, array $claimed): bool
    {
        foreach ($files as $file) {
            if (isset($claimed[$file])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private static function stringSet(array $values): array
    {
        $set = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $set[$value] = true;
            }
        }

        return array_keys($set);
    }
}
