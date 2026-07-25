<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Support;

use App\Models\AtlasProgrammingWorkItem;
use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasCodeForgeFastPathService;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Pure Fast Path report sanitization + projection helpers.
 *
 * Extracted from AtlasCodeForgeFastPathService private report/stage helpers.
 * No I/O, no provider calls, no model persistence, no time side effects
 * (callers pass $nowIso / $fallbackRunId when wall-clock or id fallback is required).
 */
final class ForgeFastPathReportSanitizer
{
    /**
     * @param  array<string,mixed>  $dispatchStage
     * @param  array<int,string>  $blockers
     */
    public static function resolveNextAction(string $mode, array $dispatchStage, array $blockers): string
    {
        if ($blockers !== []) {
            return 'resolve_remaining_blockers';
        }

        if ($mode === AtlasCodeForgeFastPathService::MODE_PREPARE_ONLY) {
            return 'review_spec_plan_then_dispatch_forge';
        }

        if ($mode === AtlasCodeForgeFastPathService::MODE_EXECUTE_SYNC) {
            return (string) ($dispatchStage['execution_status'] ?? '') === 'passed'
                ? 'open_atlas_code_review'
                : 'inspect_remaining_blockers';
        }

        return 'poll_async_execution_and_open_review_when_passed';
    }

    /**
     * @param  array<int,array<string,mixed>>  $stages
     */
    public static function computeProgress(array $stages): int
    {
        if ($stages === []) {
            return 0;
        }

        $passed = 0;
        foreach ($stages as $stage) {
            if (in_array((string) ($stage['status'] ?? ''), ['passed', 'degraded'], true)) {
                $passed++;
            }
        }

        return (int) round(($passed / count(AtlasCodeForgeFastPathService::CANONICAL_STAGES)) * 100);
    }

    /**
     * @param  array<int,array<string,mixed>>  $stages
     */
    public static function resolveCurrentStage(array $stages, string $status): string
    {
        if ($status === 'blocked') {
            foreach ($stages as $stage) {
                if ((string) ($stage['status'] ?? '') === 'blocked') {
                    return (string) ($stage['name'] ?? 'unknown');
                }
            }
        }

        $last = end($stages);
        if (is_array($last) && isset($last['name'])) {
            return (string) $last['name'];
        }

        return 'obra_binding';
    }

    /**
     * Pure run projection. Callers supply $nowIso (and optional $fallbackRunId) —
     * no now()/Str::ulid() side effects inside Support.
     *
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    public static function runProjection(array $report, string $nowIso, ?string $fallbackRunId = null): array
    {
        $status = (string) ($report['status'] ?? '');
        $updatedAt = $report['updated_at'] ?? $nowIso;

        return [
            'schema_version' => AtlasCodeForgeFastPathService::RUN_SCHEMA_VERSION,
            'fast_path_run_id' => (string) ($report['fast_path_run_id'] ?? $fallbackRunId ?? ''),
            'obra_id' => $report['obra_id'] ?? null,
            'work_item_id' => $report['work_item_id'] ?? null,
            'work_item_code' => $report['work_item_code'] ?? null,
            'execution_id' => $report['execution_id'] ?? null,
            'history_id' => $report['history_id'] ?? null,
            'checkpoint_id' => $report['checkpoint_id'] ?? null,
            'mode' => (string) ($report['mode'] ?? 'execute_async'),
            'status' => (string) ($report['status'] ?? 'unknown'),
            'current_stage' => (string) ($report['current_stage'] ?? 'unknown'),
            'progress_percent' => (int) ($report['progress_percent'] ?? 0),
            'spec_hash' => $report['spec_hash'] ?? null,
            'plan_hash' => $report['plan_hash'] ?? null,
            'task_count' => (int) ($report['task_count'] ?? 0),
            'started_at' => $report['started_at'] ?? null,
            'updated_at' => $updatedAt,
            'completed_at' => in_array($status, ['passed', 'completed'], true)
                ? ($report['updated_at'] ?? $nowIso)
                : null,
            'blockers' => array_values((array) ($report['blockers'] ?? [])),
            'evidence_refs' => array_values((array) ($report['evidence_refs'] ?? [])),
            'next_action' => (string) ($report['next_action'] ?? 'unknown'),
            'commands' => (array) ($report['commands'] ?? []),
            'external_provider_call' => false,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $stages
     * @return array<int,array<string,mixed>>
     */
    public static function sanitizeStagesForReport(array $stages): array
    {
        return array_map(
            static fn (array $stage): array => self::sanitizeStageForReport($stage),
            $stages,
        );
    }

    /**
     * @param  array<string,mixed>  $stage
     * @return array<string,mixed>
     */
    public static function sanitizeStageForReport(array $stage): array
    {
        $sanitized = [];
        foreach ($stage as $key => $value) {
            if ($key === 'project' && $value instanceof AtlasProject) {
                $sanitized['project_id'] = (string) $value->getKey();
                $sanitized['project_title'] = self::truncateString((string) ($value->title ?? ''), 160);

                continue;
            }
            if ($key === 'project' && is_array($value)) {
                $sanitized['project_id'] = AiValueNormalizer::trimmedStringOrNull(data_get($value, 'id'));
                $sanitized['project_title'] = self::truncateString((string) data_get($value, 'title', ''), 160);

                continue;
            }

            if ($key === 'work_item' && $value instanceof AtlasProgrammingWorkItem) {
                $sanitized['work_item'] = self::workItemSummary($value);

                continue;
            }
            if ($key === 'work_item' && is_array($value)) {
                $sanitized['work_item'] = [
                    'id' => AiValueNormalizer::trimmedStringOrNull(data_get($value, 'id')),
                    'code' => AiValueNormalizer::trimmedStringOrNull(data_get($value, 'code')),
                    'status' => AiValueNormalizer::trimmedStringOrNull(data_get($value, 'status')),
                    'current_stage' => AiValueNormalizer::trimmedStringOrNull(data_get($value, 'current_stage')),
                    'risk_level' => AiValueNormalizer::trimmedStringOrNull(data_get($value, 'risk_level')),
                    'spec_hash' => data_get($value, 'spec_hash'),
                    'plan_hash' => data_get($value, 'plan_hash'),
                    'task_count' => count((array) data_get($value, 'tasks_json', [])),
                ];

                continue;
            }

            $sanitized[$key] = self::sanitizeValueForReport($value);
        }

        return $sanitized;
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    public static function sanitizeReportForStorage(array $report): array
    {
        if (isset($report['stages']) && is_array($report['stages'])) {
            $report['stages'] = self::sanitizeStagesForReport((array) $report['stages']);
        }

        return self::sanitizeValueForReport($report, 0);
    }

    /**
     * @return array<string,mixed>
     */
    public static function workItemSummary(AtlasProgrammingWorkItem $workItem): array
    {
        return [
            'id' => (string) $workItem->id,
            'code' => (string) $workItem->code,
            'status' => (string) $workItem->status,
            'current_stage' => (string) $workItem->current_stage,
            'risk_level' => (string) $workItem->risk_level,
            'spec_hash' => $workItem->spec_hash,
            'plan_hash' => $workItem->plan_hash,
            'task_count' => count((array) $workItem->tasks_json),
        ];
    }

    public static function sanitizeValueForReport(mixed $value, int $depth = 0): mixed
    {
        if ($value instanceof AtlasProject) {
            return [
                'project_id' => (string) $value->getKey(),
                'title' => self::truncateString((string) ($value->title ?? ''), 160),
            ];
        }

        if ($value instanceof AtlasProgrammingWorkItem) {
            return self::workItemSummary($value);
        }

        if (is_string($value)) {
            return self::truncateString($value, 2000);
        }

        if (! is_array($value)) {
            return $value;
        }

        if ($depth >= 6) {
            return ['truncated' => true, 'reason' => 'max_depth'];
        }

        $out = [];
        $count = 0;
        foreach ($value as $key => $nested) {
            if ($count >= 120) {
                $out['truncated'] = true;
                $out['truncated_reason'] = 'max_items';
                break;
            }

            $out[$key] = self::sanitizeValueForReport($nested, $depth + 1);
            $count++;
        }

        return $out;
    }

    public static function truncateString(string $value, int $maxLength): string
    {
        return strlen($value) > $maxLength
            ? substr($value, 0, $maxLength).'...'
            : $value;
    }
}
