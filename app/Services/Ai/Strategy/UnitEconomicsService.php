<?php

namespace App\Services\Ai\Strategy;

use App\Models\AiUnitEconomics;
use Illuminate\Support\Str;

class UnitEconomicsService
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_REJECTED = 'rejected';

    /**
     * @param  array<string,mixed>  $args
     */
    public function create(array $args): AiUnitEconomics
    {
        $unitId = (string) ($args['unit_id'] ?? '');
        if ($unitId === '') {
            throw StrategyDomainException::missingField('unit_economics', 'unit_id');
        }

        $assumptions = (array) ($args['assumptions'] ?? []);
        if ($assumptions === []) {
            throw StrategyDomainException::missingField('unit_economics', 'assumptions');
        }

        $cac = $this->nonNegative($args, 'cac');
        $ltv = $this->nonNegative($args, 'ltv');
        $arpu = $this->nonNegative($args, 'arpu');
        $gm = $this->fraction($args, 'gross_margin');
        $payback = $this->nonNegative($args, 'payback_months');
        $churn = $this->fraction($args, 'churn_monthly');

        if ($cac === null && $ltv === null && $arpu === null) {
            throw StrategyDomainException::missingField('unit_economics', 'cac|ltv|arpu (at least one)');
        }

        $hashInput = [
            'unit_id' => $unitId,
            'cac' => $cac,
            'ltv' => $ltv,
            'arpu' => $arpu,
            'gross_margin' => $gm,
            'payback_months' => $payback,
            'churn_monthly' => $churn,
            'currency' => (string) ($args['currency'] ?? 'USD'),
            'assumptions' => $assumptions,
        ];

        return AiUnitEconomics::query()->create([
            'uuid' => (string) Str::uuid(),
            'venture_blueprint_id' => $args['venture_blueprint_id'] ?? null,
            'opportunity_id' => $args['opportunity_id'] ?? null,
            'strategy_run_id' => $args['strategy_run_id'] ?? null,
            'unit_id' => $unitId,
            'cac' => $cac,
            'ltv' => $ltv,
            'arpu' => $arpu,
            'gross_margin' => $gm,
            'payback_months' => $payback,
            'churn_monthly' => $churn,
            'currency' => (string) ($args['currency'] ?? 'USD'),
            'assumptions' => $assumptions,
            'breakdown' => $args['breakdown'] ?? null,
            'status' => (string) ($args['status'] ?? self::STATUS_DRAFT),
            'unit_economics_hash' => StrategyCanonicalHash::sha256($hashInput),
        ]);
    }

    /**
     * @param  array<string,mixed>  $args
     */
    private function nonNegative(array $args, string $key): ?float
    {
        $value = $args[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        $float = (float) $value;
        if ($float < 0) {
            throw StrategyDomainException::invalidValue('unit_economics', $key, 'cannot be negative');
        }

        return $float;
    }

    /**
     * @param  array<string,mixed>  $args
     */
    private function fraction(array $args, string $key): ?float
    {
        $value = $args[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        $float = (float) $value;
        if ($float < 0 || $float > 1.0) {
            throw StrategyDomainException::invalidValue('unit_economics', $key, 'must be in [0.0, 1.0]');
        }

        return $float;
    }
}
