<?php

namespace App\Services\Ai\Company\Ventures\Cost;

use App\Models\AiVentureCostAttribution;
use App\Services\Ai\Strategy\StrategyCanonicalHash;
use App\Services\Ai\Company\Ventures\VentureFoundryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * K2 — per-venture measured burn (Atlas Phase 0 keystone).
 *
 * The denominator for solvency/runway/margin. Records measured cost only;
 * burn is UNKNOWN (null) when a venture has no attributed rows — never zero,
 * never fabricated. token_cost is the honest floor (real operating cost like
 * ad-spend/COGS/infra is higher) and is labelled as such.
 */
class VentureCostAttributionLedger
{
    /**
     * @param  array<string,mixed>  $args
     */
    public function record(array $args): AiVentureCostAttribution
    {
        $ventureId = (string) ($args['venture_id'] ?? '');
        if ($ventureId === '') {
            throw VentureFoundryException::missingField('cost_attribution', 'venture_id');
        }
        $mode = (string) ($args['cost_mode'] ?? 'token_cost');
        if (! in_array($mode, AiVentureCostAttribution::COST_MODES, true)) {
            throw VentureFoundryException::invalidValue('cost_attribution', 'cost_mode', "unknown mode [{$mode}]");
        }
        if (! array_key_exists('cost_microusd', $args) || ! is_numeric($args['cost_microusd'])) {
            throw VentureFoundryException::missingField('cost_attribution', 'cost_microusd');
        }
        $cost = (int) $args['cost_microusd'];
        if ($cost < 0) {
            throw VentureFoundryException::invalidValue('cost_attribution', 'cost_microusd', 'cost cannot be negative');
        }

        $occurredAt = isset($args['occurred_at']) ? (function () use ($args) {
            try {
                return Carbon::parse((string) $args['occurred_at']);
            } catch (\Throwable) {
                return Carbon::now();
            }
        })() : Carbon::now();
        $uuid = (string) Str::uuid();

        return AiVentureCostAttribution::query()->create([
            'uuid' => $uuid,
            'venture_id' => $ventureId,
            'cost_mode' => $mode,
            'cost_microusd' => $cost,
            'category' => $args['category'] ?? null,
            'source_ref' => $args['source_ref'] ?? null,
            'provider' => $args['provider'] ?? null,
            'model' => $args['model'] ?? null,
            'occurred_at' => $occurredAt,
            'attribution_hash' => StrategyCanonicalHash::sha256([
                'uuid' => $uuid,
                'venture_id' => $ventureId,
                'cost_mode' => $mode,
                'cost_microusd' => $cost,
                'occurred_at' => $occurredAt->toIso8601String(),
                'source_ref' => $args['source_ref'] ?? null,
            ]),
        ]);
    }

    /**
     * Total measured burn (micro-USD) in [from, to], or NULL if the venture has
     * no attributed cost at all (UNKNOWN — never reported as zero).
     */
    public function totalCostMicroUsd(string $ventureId, Carbon $from, Carbon $to): ?int
    {
        $q = AiVentureCostAttribution::query()
            ->where('venture_id', $ventureId)
            ->whereBetween('occurred_at', [$from, $to]);
        if ($q->count() === 0) {
            return null; // UNKNOWN
        }

        return (int) $q->sum('cost_microusd');
    }

    /**
     * @return array<string,int> Y-m => micro-USD
     */
    public function monthlyCostMicroUsd(string $ventureId): array
    {
        $rows = AiVentureCostAttribution::query()
            ->where('venture_id', $ventureId)
            ->orderBy('occurred_at')
            ->get(['occurred_at', 'cost_microusd']);

        $byMonth = [];
        foreach ($rows as $row) {
            $m = $row->occurred_at?->format('Y-m');
            if ($m === null) {
                continue;
            }
            $byMonth[$m] = ($byMonth[$m] ?? 0) + (int) $row->cost_microusd;
        }
        ksort($byMonth);

        return $byMonth;
    }
}
