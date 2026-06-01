<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

final class DestructiveChangeBalanceScoreContract
{
    public const SCHEMA_VERSION = 'atlas.software_company_stewardship.destructive_change_balance_score.v1';

    private const MIN_REMOVALS_FLOOR = 30;
    private const REMOVAL_RATIO_FLOOR = 3.0;
    private const TRIVIAL_PRODUCT_GROWTH = 5;

    /**
     * @return array{
     *     schema_version: string,
     *     verdict: 'removal_dominant_low_replacement'|'balanced',
     *     removal_dominant: bool,
     *     reason: string,
     *     net_balance: int,
     *     removal_ratio: float,
     *     signals: array{
     *         total_insertions: int,
     *         total_removals: int,
     *         product_insertions: int,
     *         product_removals: int,
     *         net_balance: int,
     *         removal_ratio: float,
     *         product_growth_trivial: bool,
     *         removals_meet_floor: bool
     *     }
     * }
     */
    public function toArray(
        int $totalInsertions,
        int $totalRemovals,
        int $productInsertions,
        int $productRemovals,
    ): array {
        $totalInsertions = max(0, $totalInsertions);
        $totalRemovals = max(0, $totalRemovals);
        $productInsertions = max(0, $productInsertions);
        $productRemovals = max(0, $productRemovals);

        $netBalance = $totalInsertions - $totalRemovals;
        $removalRatio = (float) ($totalRemovals / max(1, $totalInsertions));
        $removalsMeetFloor = $totalRemovals >= self::MIN_REMOVALS_FLOOR;
        $productGrowthTrivial = $productInsertions <= self::TRIVIAL_PRODUCT_GROWTH;
        $removalDominant = $removalsMeetFloor
            && $removalRatio >= self::REMOVAL_RATIO_FLOOR
            && $productGrowthTrivial;

        $verdict = $removalDominant ? 'removal_dominant_low_replacement' : 'balanced';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $verdict,
            'removal_dominant' => $removalDominant,
            'reason' => $removalDominant ? $verdict : 'change_balance_within_tolerance',
            'net_balance' => $netBalance,
            'removal_ratio' => $removalRatio,
            'signals' => [
                'total_insertions' => $totalInsertions,
                'total_removals' => $totalRemovals,
                'product_insertions' => $productInsertions,
                'product_removals' => $productRemovals,
                'net_balance' => $netBalance,
                'removal_ratio' => $removalRatio,
                'product_growth_trivial' => $productGrowthTrivial,
                'removals_meet_floor' => $removalsMeetFloor,
            ],
        ];
    }
}
