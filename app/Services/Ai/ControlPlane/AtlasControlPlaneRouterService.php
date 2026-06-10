<?php

namespace App\Services\Ai\ControlPlane;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use Throwable;

class AtlasControlPlaneRouterService
{
    public const COMPONENT = 'router';

    /**
     * Tables checked in tolerance mode. Two router generations may coexist:
     * the legacy ai_router_decisions/ai_specialist_flow_executions tables and
     * the newer ai_atlas_* router runtime tables. We tolerate either being
     * absent.
     */
    private const POSSIBLE_TABLES = [
        'ai_router_decisions',
        'ai_specialist_flow_executions',
        'ai_atlas_intent_classifications',
        'ai_atlas_router_decisions',
        'ai_atlas_flow_routes',
        'ai_atlas_runtime_dispatches',
        'ai_atlas_decision_receipts',
    ];

    /**
     * @return array<string,mixed>
     */
    public function snapshot(int $limitRecent = 20): array
    {
        $availability = $this->tableAvailability();
        if ($availability['status'] === AtlasControlPlaneStatus::MISSING) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::MISSING,
                'detail' => 'Router Runtime tables not present',
                'tables' => $availability['tables'],
            ];
        }

        try {
            return [
                'schema' => 'atlas.ai.control_plane.router.v1',
                'component' => self::COMPONENT,
                'status' => $availability['status'],
                'tables' => $availability['tables'],
                'intent_classifications' => $this->intentSection($limitRecent),
                'router_decisions' => $this->decisionSection($limitRecent),
                'flow_routes' => $this->flowRoutesSection($limitRecent),
                'runtime_dispatches' => $this->runtimeDispatchSection($limitRecent),
                'decision_receipts' => $this->decisionReceiptSection($limitRecent),
                'specialist_flow_executions' => $this->specialistFlowSection($limitRecent),
            ];
        } catch (Throwable $e) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::DEGRADED,
                'detail' => 'router snapshot failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        $availability = $this->tableAvailability();
        if ($availability['status'] === AtlasControlPlaneStatus::MISSING) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::MISSING,
            ];
        }

        return [
            'component' => self::COMPONENT,
            'status' => $availability['status'],
            'tables' => $availability['tables'],
            'totals' => [
                'router_decisions_legacy' => $this->safeCount('ai_router_decisions'),
                'specialist_flow_executions' => $this->safeCount('ai_specialist_flow_executions'),
                'intent_classifications' => $this->safeCount('ai_atlas_intent_classifications'),
                'router_decisions' => $this->safeCount('ai_atlas_router_decisions'),
                'flow_routes' => $this->safeCount('ai_atlas_flow_routes'),
                'runtime_dispatches' => $this->safeCount('ai_atlas_runtime_dispatches'),
                'decision_receipts' => $this->safeCount('ai_atlas_decision_receipts'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function tableAvailability(): array
    {
        $tables = [];
        $present = 0;
        foreach (self::POSSIBLE_TABLES as $table) {
            $exists = DatabaseTableAvailability::has($table);
            $tables[$table] = $exists;
            if ($exists) {
                $present++;
            }
        }

        $status = match (true) {
            $present === 0 => AtlasControlPlaneStatus::MISSING,
            $present === count(self::POSSIBLE_TABLES) => AtlasControlPlaneStatus::READY,
            default => AtlasControlPlaneStatus::DEGRADED,
        };

        return [
            'status' => $status,
            'tables' => $tables,
            'tables_present' => $present,
            'tables_required' => count(self::POSSIBLE_TABLES),
        ];
    }

    private function safeCount(string $table): ?int
    {
        if (! DatabaseTableAvailability::has($table)) {
            return null;
        }
        try {
            return (int) DB::table($table)->count();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function intentSection(int $limit): array
    {
        return $this->buildSection(
            'ai_atlas_intent_classifications',
            'intent_type',
            $limit,
            ['id', 'uuid', 'intent_type', 'status', 'confidence', 'created_at'],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function decisionSection(int $limit): array
    {
        return $this->buildSection(
            'ai_atlas_router_decisions',
            'routing_mode',
            $limit,
            ['id', 'uuid', 'primary_domain', 'routing_mode', 'created_at'],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function flowRoutesSection(int $limit): array
    {
        return $this->buildSection(
            'ai_atlas_flow_routes',
            'status',
            $limit,
            ['id', 'uuid', 'flow_id', 'status', 'created_at'],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeDispatchSection(int $limit): array
    {
        return $this->buildSection(
            'ai_atlas_runtime_dispatches',
            'status',
            $limit,
            ['id', 'uuid', 'runtime_id', 'status', 'created_at'],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function decisionReceiptSection(int $limit): array
    {
        return $this->buildSection(
            'ai_atlas_decision_receipts',
            'status',
            $limit,
            ['id', 'uuid', 'receipt_hash', 'status', 'created_at'],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function specialistFlowSection(int $limit): array
    {
        return $this->buildSection(
            'ai_specialist_flow_executions',
            'status',
            $limit,
            ['id', 'uuid', 'specialist_flow_id', 'status', 'created_at'],
        );
    }

    /**
     * @param  array<int,string>  $recentColumns
     * @return array<string,mixed>
     */
    private function buildSection(string $table, string $groupBy, int $limit, array $recentColumns): array
    {
        if (! DatabaseTableAvailability::has($table)) {
            return ['status' => AtlasControlPlaneStatus::MISSING, 'detail' => "table {$table} not present"];
        }

        try {
            $base = DB::table($table);
            $total = (int) $base->count();
            $byBucket = $this->safeGroupBy($table, $groupBy);
            $recent = $this->safeRecent($table, $limit, $recentColumns);

            return [
                'status' => AtlasControlPlaneStatus::READY,
                'count' => $total,
                'by_bucket' => $byBucket,
                'recent' => $recent,
            ];
        } catch (Throwable $e) {
            return [
                'status' => AtlasControlPlaneStatus::DEGRADED,
                'detail' => "{$table} section failed: ".$e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string,int>
     */
    private function safeGroupBy(string $table, string $groupBy): array
    {
        try {
            $rows = DB::table($table)
                ->select($groupBy, DB::raw('COUNT(*) as total'))
                ->groupBy($groupBy)
                ->pluck('total', $groupBy)
                ->all();

            return array_map(static fn ($v): int => (int) $v, $rows);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<int,string>  $columns
     * @return array<int,array<string,mixed>>
     */
    private function safeRecent(string $table, int $limit, array $columns): array
    {
        try {
            $existing = array_filter($columns, static fn (string $c): bool => DatabaseTableAvailability::hasColumn($table, $c));
            if ($existing === []) {
                return [];
            }

            return DB::table($table)
                ->select($existing)
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
