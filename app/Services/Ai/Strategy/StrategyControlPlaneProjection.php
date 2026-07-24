<?php

namespace App\Services\Ai\Strategy;

use App\Services\Ai\Support\ControlPlaneStatusSection;
use App\Models\AiExperimentPlan;
use App\Models\AiMarketModel;
use App\Models\AiOpportunity;
use App\Models\AiStrategyMemo;
use App\Models\AiStrategyRun;
use App\Models\AiUnitEconomics;
use App\Models\AiVentureBlueprint;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Throwable;

class StrategyControlPlaneProjection
{
    public const SCHEMA = 'atlas.ai.strategy.control_plane.v1';

    /**
     * @return array<string,mixed>
     */
    public function snapshot(int $limitRecent = 20): array
    {
        if (! DatabaseTableAvailability::has('ai_strategy_runs')) {
            return [
                'schema' => self::SCHEMA,
                'status' => 'missing',
                'detail' => 'ai_strategy_* tables not present',
                'generated_at' => now()->toJSON(),
            ];
        }

        try {
            return [
                'schema' => self::SCHEMA,
                'status' => 'ready',
                'totals' => $this->totals(),
                'runs' => $this->section(AiStrategyRun::class, 'status', $limitRecent, [
                    'id', 'uuid', 'run_kind', 'status', 'next_action', 'completed_at', 'created_at',
                ]),
                'opportunities' => $this->section(AiOpportunity::class, 'status', $limitRecent, [
                    'id', 'uuid', 'opportunity_id', 'title', 'urgency', 'status', 'confidence', 'created_at',
                ]),
                'venture_blueprints' => $this->section(AiVentureBlueprint::class, 'status', $limitRecent, [
                    'id', 'uuid', 'blueprint_id', 'title', 'status', 'created_at',
                ]),
                'market_models' => $this->section(AiMarketModel::class, 'status', $limitRecent, [
                    'id', 'uuid', 'model_id', 'title', 'tam', 'sam', 'som', 'currency', 'status', 'created_at',
                ]),
                'unit_economics' => $this->section(AiUnitEconomics::class, 'status', $limitRecent, [
                    'id', 'uuid', 'unit_id', 'cac', 'ltv', 'gross_margin', 'payback_months', 'status', 'created_at',
                ]),
                'experiment_plans' => $this->section(AiExperimentPlan::class, 'status', $limitRecent, [
                    'id', 'uuid', 'experiment_id', 'hypothesis_kind', 'status', 'created_at',
                ]),
                'strategy_memos' => $this->section(AiStrategyMemo::class, 'status', $limitRecent, [
                    'id', 'uuid', 'memo_kind', 'title', 'status', 'created_at',
                ]),
                'generated_at' => now()->toJSON(),
            ];
        } catch (Throwable $e) {
            return [
                'schema' => self::SCHEMA,
                'status' => 'degraded',
                'detail' => 'strategy snapshot failed: '.$e->getMessage(),
                'generated_at' => now()->toJSON(),
            ];
        }
    }

    /**
     * @return array<string,int>
     */
    private function totals(): array
    {
        return [
            'runs' => $this->safeCount(AiStrategyRun::class),
            'opportunities' => $this->safeCount(AiOpportunity::class),
            'venture_blueprints' => $this->safeCount(AiVentureBlueprint::class),
            'market_models' => $this->safeCount(AiMarketModel::class),
            'unit_economics' => $this->safeCount(AiUnitEconomics::class),
            'experiment_plans' => $this->safeCount(AiExperimentPlan::class),
            'strategy_memos' => $this->safeCount(AiStrategyMemo::class),
        ];
    }

    /**
     * @param  array<int,string>  $recentColumns
     * @return array<string,mixed>
     */
    private function section(string $modelClass, string $statusColumn, int $limit, array $recentColumns): array
    {
        return ControlPlaneStatusSection::project($modelClass, $statusColumn, $limit, $recentColumns);
    }

    private function safeCount(string $modelClass): int
    {
        if (! class_exists($modelClass)) {
            return 0;
        }
        try {
            return (int) $modelClass::query()->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
