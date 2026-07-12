<?php

namespace App\Services\Ai\Rivals\Core;

/**
 * A trial unit may be invalidated ONLY under rules preregistered before unblinding.
 *
 * Post-unblinding invalidation is forbidden — otherwise the observed results could
 * pick which units "count". Invalidated units stay in the intent-to-treat audit:
 * excluded from the numerator, never deleted. Rotation renews the private corpus so
 * memorized/stale cases retire and only fresh, unused cases are exposed. Pure over
 * arrays: deterministic and replayable.
 */
final class UnitInvalidationPolicy
{
    public const SCHEMA = 'atlas.rivals2.unit_invalidation.v1';

    /**
     * @param  array<string,mixed>  $preregisteredRules  ['frozen_before_unblinding'=>bool, 'allowed_reasons'=>list<string>]
     * @param  array<string,mixed>  $signal  ['unit_id', 'reason', 'observed_after_unblinding'=>bool]
     * @return array<string,mixed>
     */
    public function evaluate(array $preregisteredRules, array $signal): array
    {
        $allowed = array_values(array_map('strval', (array) ($preregisteredRules['allowed_reasons'] ?? [])));
        $reason = (string) ($signal['reason'] ?? '');
        $unitId = (string) ($signal['unit_id'] ?? 'unknown');
        $afterUnblinding = (bool) ($signal['observed_after_unblinding'] ?? false);

        $rejected = null;
        if (($preregisteredRules['frozen_before_unblinding'] ?? false) !== true) {
            $rejected = 'invalidation_rules_not_frozen';
        } elseif ($afterUnblinding) {
            $rejected = 'post_unblinding_invalidation_forbidden';
        } elseif ($reason === '' || ! in_array($reason, $allowed, true)) {
            $rejected = 'reason_not_preregistered:'.$reason;
        }

        $invalidated = $rejected === null;

        return [
            'schema_version' => self::SCHEMA,
            'unit_id' => $unitId,
            'invalidated' => $invalidated,
            'reason' => $invalidated ? $reason : null,
            'rejected_reason' => $rejected,
            // intent-to-treat: an invalidated unit is excluded but stays counted
            'retained_in_itt' => true,
        ];
    }

    /**
     * Rotate a private corpus: select only fresh, unused cases up to the target and
     * retire stale/used ones. Deterministic (sorted by case_id) so a run is replayable.
     *
     * @param  list<array<string,mixed>>  $corpus  each ['case_id','mined_at']
     * @param  list<string>  $usedCaseIds
     * @return array<string,mixed>
     */
    public function rotate(array $corpus, array $usedCaseIds, int $freshTarget, int $maxAgeDays = 30): array
    {
        $used = array_fill_keys(array_map('strval', $usedCaseIds), true);
        $now = now()->getTimestamp();
        $fresh = [];
        $retired = [];
        foreach ($corpus as $case) {
            $id = (string) ($case['case_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $ageDays = (int) floor(($now - strtotime((string) ($case['mined_at'] ?? '@0'))) / 86400);
            if (isset($used[$id])) {
                $retired[] = 'used:'.$id;
            } elseif ($ageDays > $maxAgeDays) {
                $retired[] = 'stale:'.$id;
            } else {
                $fresh[] = $id;
            }
        }
        sort($fresh);
        sort($retired);
        $selected = array_slice($fresh, 0, max(0, $freshTarget));

        return [
            'schema_version' => self::SCHEMA,
            'selected' => $selected,
            'retired' => $retired,
            'sufficient' => count($selected) >= $freshTarget,
        ];
    }
}
