<?php

namespace App\Services\Ai\ToolRuntime;

use App\Models\AiToolDefinition;
use App\Models\AiToolHealthCheck;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ToolHealthService
{
    /**
     * Run a doctor pass over every registered tool. The check is metadata-driven
     * and does NOT perform any external network or process call.
     *
     * @return array<string,mixed>
     */
    public function doctor(): array
    {
        $records = [];
        $summary = ['healthy' => 0, 'degraded' => 0, 'failed' => 0, 'unknown' => 0];

        foreach (AiToolDefinition::query()->orderBy('tool_id')->get() as $tool) {
            $record = $this->checkTool($tool);
            $records[] = [
                'tool_id' => $tool->tool_id,
                'status' => $record->status,
                'missing_count' => count($record->missing_requirements ?? []),
                'health_hash' => $record->health_hash,
            ];
            $summary[$record->status] = ($summary[$record->status] ?? 0) + 1;
        }

        return [
            'ok' => $summary['failed'] === 0,
            'summary' => $summary,
            'records' => $records,
        ];
    }

    public function checkTool(AiToolDefinition $tool): AiToolHealthCheck
    {
        $checks = $this->runChecks($tool);
        $missing = array_values(array_filter(
            $checks,
            static fn (array $c): bool => $c['status'] !== 'passed',
        ));

        $status = $this->resolveStatus($missing, $checks);

        $hash = MissionCanonicalHash::sha256([
            'tool_id' => $tool->tool_id,
            'checks' => $checks,
            'missing' => $missing,
            'status' => $status,
            'checked_at' => Carbon::now()->toISOString(),
        ]);

        $record = AiToolHealthCheck::query()->create([
            'uuid' => (string) Str::uuid(),
            'tool_definition_id' => $tool->id,
            'status' => $status,
            'checked_requirements' => $checks,
            'missing_requirements' => $missing,
            'output_summary' => null,
            'checked_at' => Carbon::now(),
            'health_hash' => $hash,
        ]);

        $tool->health_status = $status;
        $tool->save();

        return $record;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function runChecks(AiToolDefinition $tool): array
    {
        return [
            [
                'requirement' => 'tool_status_active',
                'status' => $tool->status === 'active' ? 'passed' : 'failed',
                'detail' => 'status='.$tool->status,
            ],
            [
                'requirement' => 'input_schema_present',
                'status' => is_array($tool->input_schema) && $tool->input_schema !== [] ? 'passed' : 'failed',
                'detail' => 'input_schema keys='.count((array) $tool->input_schema),
            ],
            [
                'requirement' => 'output_schema_present',
                'status' => is_array($tool->output_schema) && $tool->output_schema !== [] ? 'passed' : 'failed',
                'detail' => 'output_schema keys='.count((array) $tool->output_schema),
            ],
            [
                'requirement' => 'side_effects_declared',
                'status' => is_array($tool->side_effects) && array_key_exists('mutates', $tool->side_effects) ? 'passed' : 'failed',
                'detail' => 'side_effects.mutates declared',
            ],
            [
                'requirement' => 'evidence_emitted_declared',
                'status' => is_array($tool->evidence_emitted) && $tool->evidence_emitted !== [] ? 'passed' : 'failed',
                'detail' => 'evidence_emitted='.implode(',', (array) $tool->evidence_emitted),
            ],
            [
                'requirement' => 'has_capabilities',
                'status' => $tool->capabilities()->count() > 0 ? 'passed' : 'failed',
                'detail' => 'capabilities='.$tool->capabilities()->count(),
            ],
            [
                'requirement' => 'authority_group_valid',
                'status' => in_array($tool->authority_group, ToolRuntimeCanon::AUTHORITY_GROUPS, true) ? 'passed' : 'failed',
                'detail' => 'authority_group='.$tool->authority_group,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $missing
     * @param  array<int,array<string,mixed>>  $checks
     */
    private function resolveStatus(array $missing, array $checks): string
    {
        if ($missing === []) {
            return 'healthy';
        }
        $missingCount = count($missing);
        if ($missingCount >= count($checks)) {
            return 'failed';
        }

        return 'degraded';
    }
}
