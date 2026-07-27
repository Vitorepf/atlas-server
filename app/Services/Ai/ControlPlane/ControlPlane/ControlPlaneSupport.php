<?php

declare(strict_types=1);

namespace App\Services\Ai\ControlPlane\ControlPlane;

use App\Models\AiJob;
use App\Models\AiQualityEvaluation;
use App\Models\AiTrace;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Collection;
use Throwable;

final class ControlPlaneSupport
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function hashPayload(array $payload): string
    {
        $canonical = $payload;
        // Exclude time-sensitive and self-referential fields from hash so the
        // hash is deterministic over the *content* of the report.
        unset($canonical['generated_at'], $canonical['window']['since'], $canonical['window']['until'], $canonical['hash']);
        $canonical = $this->canonicalize($canonical);

        return 'sha256:'.hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = $this->canonicalize($item);
        }
        if (! array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }

    public function flowIdFromTrace(AiTrace $trace): string
    {
        $metadata = is_array($trace->metadata) ? $trace->metadata : [];
        $flow = $this->stringOrNull(data_get($metadata, 'hyperflow_runtime.flow_route.flow_id'))
            ?? $this->stringOrNull(data_get($metadata, 'hyperflow_runtime.flow_id'))
            ?? $this->stringOrNull(data_get($metadata, 'specialist_flow_runtime.flow_id'))
            ?? $this->stringOrNull(data_get($metadata, 'flow_id'))
            ?? $this->stringOrNull($trace->intent);

        return $flow ?? 'unknown';
    }

    /**
     * @return array<string,mixed>|null
     */
    public function extractDevHandoff(AiTrace $trace): ?array
    {
        $metadata = is_array($trace->metadata) ? $trace->metadata : [];
        $handoffTarget = $this->stringOrNull(data_get($metadata, 'hyperflow_runtime.handoff_target.kind'))
            ?? $this->stringOrNull(data_get($metadata, 'hyperflow_runtime.handoff_target'))
            ?? $this->stringOrNull(data_get($metadata, 'specialist_flow_runtime.delegation.target_flow_id'));
        if ($handoffTarget !== 'atlas_dev') {
            return null;
        }

        return [
            'trace_id' => (string) $trace->id,
            'flow_id' => $this->flowIdFromTrace($trace),
            'handoff_target' => $handoffTarget,
            'delegation_status' => $this->stringOrNull(data_get($metadata, 'specialist_flow_runtime.delegation.status'))
                ?? $this->stringOrNull(data_get($metadata, 'hyperflow_runtime.dispatch.dispatch_status')),
        ];
    }

    /**
     * @param  array<int,string>  $traceIds
     * @return array<string,int>
     */
    public function receiptCountsByTraceIds(array $traceIds): array
    {
        // Receipts in this codebase are not directly linked to trace_id; they
        // link to router_decision_id + runtime_dispatch_id. For the per-flow
        // aggregation we approximate: count receipts whose router decision
        // intersects the window. A real join requires a separate index column
        // (future hardening). For now we return an empty map and rely on the
        // global receipts section.
        return [];
    }

    /**
     * @param  array<int,string>  $traceIds
     * @return array<string,int>
     */
    public function evidenceCountsByTraceIds(array $traceIds): array
    {
        if (! DatabaseTableAvailability::has('ai_jobs') || $traceIds === []) {
            return [];
        }
        try {
            $rows = AiJob::query()
                ->whereIn('trace_id', $traceIds)
                ->get(['trace_id', 'context_refs']);
        } catch (Throwable) {
            return [];
        }

        $counts = [];
        foreach ($rows as $row) {
            $traceId = (string) $row->trace_id;
            $refs = is_array($row->context_refs) ? count($row->context_refs) : 0;
            $counts[$traceId] = ($counts[$traceId] ?? 0) + $refs;
        }

        return $counts;
    }

    /**
     * @param  array<int,string>  $traceIds
     * @return array<string,int>
     */
    public function handoffCountsByTraceIds(array $traceIds): array
    {
        if (! DatabaseTableAvailability::has('ai_traces') || $traceIds === []) {
            return [];
        }

        try {
            $traces = AiTrace::query()
                ->whereIn('id', $traceIds)
                ->get(['id', 'metadata']);
        } catch (Throwable) {
            return [];
        }

        $counts = [];
        foreach ($traces as $trace) {
            $traceId = (string) $trace->id;
            $hasDev = $this->extractDevHandoff($trace) !== null;
            $counts[$traceId] = $hasDev ? 1 : 0;
        }

        return $counts;
    }

    /**
     * @param  array<int,string>  $traceIds
     * @return array<string,array{status:?string,score:?int}>
     */
    public function qualityByTraceIds(array $traceIds): array
    {
        if (! DatabaseTableAvailability::has('ai_quality_evaluations') || $traceIds === []) {
            return [];
        }

        try {
            $rows = AiQualityEvaluation::query()
                ->whereIn('trace_id', $traceIds)
                ->get(['trace_id', 'status', 'score']);
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $traceId = $this->stringOrNull($row->trace_id);
            if ($traceId === null) {
                continue;
            }
            $out[$traceId] = [
                'status' => $this->stringOrNull($row->status),
                'score' => is_numeric($row->score) ? (int) $row->score : null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,array{status:?string,score:?int}>  $quality
     * @param  array<int,string>  $traceIds
     * @return array<string,mixed>
     */
    public function aggregateQuality(array $quality, array $traceIds): array
    {
        $byStatus = [];
        $scoreSum = 0;
        $scoreCount = 0;
        foreach ($traceIds as $traceId) {
            $entry = $quality[$traceId] ?? null;
            if ($entry === null) {
                continue;
            }
            $status = $entry['status'] ?? 'unknown';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            if ($entry['score'] !== null) {
                $scoreSum += $entry['score'];
                $scoreCount++;
            }
        }

        return [
            'evaluated' => $scoreCount,
            'by_status' => $byStatus,
            'average_score' => $scoreCount > 0 ? (int) round($scoreSum / $scoreCount) : null,
        ];
    }

    /**
     * @param  array<string,int>  $map
     * @param  array<int,string>  $keys
     */
    public function sumByKeys(array $map, array $keys): int
    {
        $total = 0;
        foreach ($keys as $key) {
            $total += $map[$key] ?? 0;
        }

        return $total;
    }

    /**
     * @param  Collection<int,object>  $rows
     * @return array<string,int>
     */
    public function countsBy(Collection $rows, string $field): array
    {
        return $rows
            ->groupBy(fn (object $row): string => (string) ($row->{$field} ?? 'unknown'))
            ->map(fn (Collection $group): int => $group->count())
            ->sortKeys()
            ->all();
    }

    public function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    public function truncate(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed) <= $max) {
            return $trimmed;
        }

        return mb_substr($trimmed, 0, $max - 1).'…';
    }
}
