<?php

namespace App\Services\Ai\ControlPlane;

use Illuminate\Support\Facades\Schema;
use Throwable;

class AtlasControlPlaneToolService
{
    public const COMPONENT = 'tool';

    private const REQUIRED_TABLES = [
        'ai_tool_definitions',
        'ai_tool_capabilities',
        'ai_tool_plans',
        'ai_tool_invocations',
        'ai_tool_receipts',
        'ai_tool_health_checks',
        'ai_tool_validation_runs',
    ];

    /**
     * Per-runtime full snapshot for Tool Runtime. Reuses an external service if
     * one exists; otherwise builds a minimal aggregate from models directly.
     *
     * @return array<string,mixed>
     */
    public function snapshot(int $limitRecent = 20): array
    {
        $availability = $this->tableAvailability();
        if ($availability['status'] === AtlasControlPlaneStatus::MISSING) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::MISSING,
                'detail' => 'Tool Runtime tables not present',
                'tables' => $availability['tables'],
            ];
        }

        try {
            $definitions = $this->safeGroupCounts('\\App\\Models\\AiToolDefinition', 'status');
            $capabilities = $this->safeGroupCounts('\\App\\Models\\AiToolCapability', 'status');
            $invocations = $this->safeGroupCounts('\\App\\Models\\AiToolInvocation', 'invocation_status');
            $plans = $this->safeGroupCounts('\\App\\Models\\AiToolPlan', 'status');
            $health = $this->safeGroupCounts('\\App\\Models\\AiToolHealthCheck', 'status');
            $validation = $this->safeGroupCounts('\\App\\Models\\AiToolValidationRun', 'status');

            $recentInvocations = $this->safeRecent(
                '\\App\\Models\\AiToolInvocation',
                $limitRecent,
                static fn ($i): array => [
                    'id' => $i->id,
                    'uuid' => $i->uuid ?? null,
                    'tool_definition_id' => $i->tool_definition_id ?? null,
                    'invocation_status' => $i->invocation_status ?? null,
                    'mission_id' => $i->mission_id ?? null,
                    'created_at' => optional($i->created_at)->toJSON(),
                ],
            );

            $recentReceipts = $this->safeRecent(
                '\\App\\Models\\AiToolReceipt',
                $limitRecent,
                static fn ($r): array => [
                    'id' => $r->id,
                    'uuid' => $r->uuid ?? null,
                    'receipt_hash' => $r->receipt_hash ?? null,
                    'created_at' => optional($r->created_at)->toJSON(),
                ],
            );

            return [
                'schema' => 'atlas.ai.control_plane.tool.v1',
                'component' => self::COMPONENT,
                'status' => $availability['status'],
                'tables' => $availability['tables'],
                'tool_definitions' => $definitions,
                'capabilities' => $capabilities,
                'plans' => $plans,
                'invocations' => $invocations,
                'health_checks' => $health,
                'validation_runs' => $validation,
                'recent_invocations' => $recentInvocations,
                'recent_receipts' => $recentReceipts,
            ];
        } catch (Throwable $e) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::DEGRADED,
                'detail' => 'tool snapshot failed: '.$e->getMessage(),
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

        try {
            return [
                'component' => self::COMPONENT,
                'status' => $availability['status'],
                'tables' => $availability['tables'],
                'totals' => [
                    'tool_definitions' => $this->safeCount('\\App\\Models\\AiToolDefinition'),
                    'capabilities' => $this->safeCount('\\App\\Models\\AiToolCapability'),
                    'plans' => $this->safeCount('\\App\\Models\\AiToolPlan'),
                    'invocations' => $this->safeCount('\\App\\Models\\AiToolInvocation'),
                    'receipts' => $this->safeCount('\\App\\Models\\AiToolReceipt'),
                    'health_checks' => $this->safeCount('\\App\\Models\\AiToolHealthCheck'),
                    'validation_runs' => $this->safeCount('\\App\\Models\\AiToolValidationRun'),
                ],
            ];
        } catch (Throwable $e) {
            return [
                'component' => self::COMPONENT,
                'status' => AtlasControlPlaneStatus::DEGRADED,
                'detail' => 'tool summary failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function tableAvailability(): array
    {
        $tables = [];
        $present = 0;
        foreach (self::REQUIRED_TABLES as $table) {
            $exists = Schema::hasTable($table);
            $tables[$table] = $exists;
            if ($exists) {
                $present++;
            }
        }

        $status = match (true) {
            $present === 0 => AtlasControlPlaneStatus::MISSING,
            $present === count(self::REQUIRED_TABLES) => AtlasControlPlaneStatus::READY,
            default => AtlasControlPlaneStatus::DEGRADED,
        };

        return [
            'status' => $status,
            'tables' => $tables,
            'tables_present' => $present,
            'tables_required' => count(self::REQUIRED_TABLES),
        ];
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

    /**
     * @return array<string,int>
     */
    private function safeGroupCounts(string $modelClass, string $groupBy): array
    {
        if (! class_exists($modelClass)) {
            return [];
        }
        try {
            return $modelClass::query()
                ->selectRaw("{$groupBy} as bucket, COUNT(*) as total")
                ->groupBy($groupBy)
                ->pluck('total', 'bucket')
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function safeRecent(string $modelClass, int $limit, callable $serializer): array
    {
        if (! class_exists($modelClass)) {
            return [];
        }
        try {
            return $modelClass::query()
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
                ->map($serializer)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
