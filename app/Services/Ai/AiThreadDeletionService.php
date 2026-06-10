<?php

namespace App\Services\Ai;

use App\Models\AiThread;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class AiThreadDeletionService
{
    /**
     * Delete a user-visible Atlas conversation and purge content-bearing records.
     *
     * Evidence Ledger events stay append-only by architecture. Runtime traces are
     * kept only as non-retrievable tombstones so telemetry can explain that work
     * existed without preserving prompt, response, context, memory, or job body.
     *
     * @return array<string, mixed>
     */
    public function delete(AiThread $thread): array
    {
        return DB::transaction(function () use ($thread): array {
            $threadId = (string) $thread->id;
            $deletedAt = now();
            $traceIds = $this->ids('ai_traces', 'thread_id', $threadId);
            $sessionIds = $this->ids('ai_sessions', 'thread_id', $threadId);
            $jobIds = $this->idsIn('ai_jobs', 'trace_id', $traceIds);
            $snapshotIds = $this->idsMatching('ai_context_snapshots', [
                'trace_id' => $traceIds,
                'thread_id' => [$threadId],
                'session_id' => $sessionIds,
            ]);
            $contentReferenceIds = array_values(array_unique(array_filter([
                $threadId,
                ...$traceIds,
                ...$sessionIds,
                ...$jobIds,
                ...$snapshotIds,
            ])));
            $contextBundleIds = $this->idsContaining('ai_context_bundles', [
                'source_refs',
                'trace_refs',
                'job_refs',
                'metric_refs',
                'raw_payload',
                'body_for_thread',
                'summary',
            ], $contentReferenceIds);

            $counts = [];
            $counts['inbox_items'] = $this->deleteMatching('ai_inbox_items', [
                'source_id' => $contentReferenceIds,
                'context_bundle_id' => $contextBundleIds,
            ]);
            $counts['context_bundles'] = $this->deleteMatching('ai_context_bundles', [
                'id' => $contextBundleIds,
            ]);
            $counts['memory_usages'] = $this->deleteMatching('atlas_memory_entry_usages', [
                'thread_id' => [$threadId],
                'trace_id' => $traceIds,
                'session_id' => $sessionIds,
                'context_snapshot_id' => $snapshotIds,
            ]);
            $counts['attachment_index_entries'] = $this->deleteMatching('ai_attachment_index_entries', [
                'thread_id' => [$threadId],
                'trace_id' => $traceIds,
                'ai_job_id' => $jobIds,
            ]);
            $counts['context_snapshots'] = $this->deleteMatching('ai_context_snapshots', [
                'thread_id' => [$threadId],
                'trace_id' => $traceIds,
                'session_id' => $sessionIds,
            ]);
            $counts['memory_deltas'] = $this->deleteMatching('ai_memory_deltas', [
                'source_trace_id' => $traceIds,
                'source_session_id' => $sessionIds,
            ]);
            $counts['quality_actions'] = $this->deleteMatching('ai_quality_actions', [
                'thread_id' => [$threadId],
                'trace_id' => $traceIds,
                'remediation_trace_id' => $traceIds,
                'session_id' => $sessionIds,
            ]);
            $counts['quality_evaluations'] = $this->deleteMatching('ai_quality_evaluations', [
                'thread_id' => [$threadId],
                'trace_id' => $traceIds,
                'session_id' => $sessionIds,
            ]);
            $counts['outcome_links'] = $this->deleteMatching('ai_outcome_links', [
                'thread_id' => [$threadId],
                'trace_id' => $traceIds,
                'session_id' => $sessionIds,
            ]);
            $counts['trace_metric_summaries'] = $this->deleteMatching('ai_trace_metric_summaries', [
                'thread_id' => [$threadId],
                'trace_id' => $traceIds,
                'session_id' => $sessionIds,
            ]);
            $counts['telemetry_events'] = $this->deleteMatching('ai_telemetry_events', [
                'thread_id' => [$threadId],
                'trace_id' => $traceIds,
                'session_id' => $sessionIds,
                'ai_job_id' => $jobIds,
            ]);
            $counts['tool_events'] = $this->deleteMatching('ai_tool_events', [
                'thread_id' => [$threadId],
                'trace_id' => $traceIds,
                'session_id' => $sessionIds,
            ]);
            $counts['router_decisions'] = $this->deleteMatching('ai_router_decisions', [
                'trace_id' => $traceIds,
            ]);
            $counts['decisions'] = $this->deleteMatching('ai_decisions', [
                'trace_id' => $traceIds,
            ]);
            $counts['stream_events'] = $this->deleteMatching('ai_stream_events', [
                'trace_id' => $traceIds,
                'ai_job_id' => $jobIds,
            ]);
            $counts['jobs'] = $this->deleteMatching('ai_jobs', [
                'trace_id' => $traceIds,
                'id' => $jobIds,
            ]);
            $counts['provider_handoffs'] = $this->deleteMatching('ai_provider_handoffs', [
                'thread_id' => [$threadId],
                'session_id' => $sessionIds,
            ]);
            $counts['compactions'] = $this->deleteMatching('ai_compactions', [
                'thread_id' => [$threadId],
                'session_id' => $sessionIds,
            ]);
            $counts['session_states'] = $this->deleteMatching('ai_session_states', [
                'thread_id' => [$threadId],
                'session_id' => $sessionIds,
            ]);
            $counts['messages'] = $this->deleteMatching('ai_messages', [
                'thread_id' => [$threadId],
                'trace_id' => $traceIds,
            ]);
            $counts['sessions'] = $this->deleteMatching('ai_sessions', [
                'thread_id' => [$threadId],
                'id' => $sessionIds,
            ]);

            $counts['traces_tombstoned'] = $this->tombstoneTraces($traceIds, $threadId, $deletedAt);

            $thread->delete();

            return [
                'thread_id' => $threadId,
                'content_purged' => true,
                'traces_tombstoned' => $counts['traces_tombstoned'],
                'counts' => $counts,
                'deleted_at' => $deletedAt->toJSON(),
            ];
        });
    }

    /**
     * @return list<string>
     */
    private function ids(string $table, string $column, string $value): array
    {
        if (! DatabaseTableAvailability::hasColumn($table, $column)) {
            return [];
        }

        return DB::table($table)
            ->where($column, $value)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function idsIn(string $table, string $column, array $values): array
    {
        if ($values === [] || ! DatabaseTableAvailability::hasColumn($table, $column)) {
            return [];
        }

        return DB::table($table)
            ->whereIn($column, $values)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, list<string>>  $columns
     * @return list<string>
     */
    private function idsMatching(string $table, array $columns): array
    {
        if (! DatabaseTableAvailability::hasColumn($table, 'id')) {
            return [];
        }

        $query = DB::table($table);
        $matched = false;

        $query->where(function ($where) use ($table, $columns, &$matched): void {
            foreach ($columns as $column => $values) {
                if ($values === [] || ! DatabaseTableAvailability::hasColumn($table, $column)) {
                    continue;
                }

                $matched = true;
                $where->orWhereIn($column, $values);
            }
        });

        if (! $matched) {
            return [];
        }

        return $query
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $needles
     * @return list<string>
     */
    private function idsContaining(string $table, array $columns, array $needles): array
    {
        if ($needles === [] || ! DatabaseTableAvailability::hasColumn($table, 'id')) {
            return [];
        }

        $query = DB::table($table);
        $matched = false;

        $query->where(function ($where) use ($table, $columns, $needles, &$matched): void {
            foreach ($columns as $column) {
                if (! DatabaseTableAvailability::hasColumn($table, $column)) {
                    continue;
                }

                foreach ($needles as $needle) {
                    $matched = true;
                    $where->orWhere($column, 'like', '%'.$needle.'%');
                }
            }
        });

        if (! $matched) {
            return [];
        }

        return $query
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, list<string>>  $columns
     */
    private function deleteMatching(string $table, array $columns): int
    {
        if (! DatabaseTableAvailability::has($table)) {
            return 0;
        }

        $query = DB::table($table);
        $matched = false;

        $query->where(function ($where) use ($table, $columns, &$matched): void {
            foreach ($columns as $column => $values) {
                if ($values === [] || ! DatabaseTableAvailability::hasColumn($table, $column)) {
                    continue;
                }

                $matched = true;
                $where->orWhereIn($column, $values);
            }
        });

        return $matched ? $query->delete() : 0;
    }

    /**
     * @param  list<string>  $traceIds
     */
    private function tombstoneTraces(array $traceIds, string $threadId, CarbonInterface $deletedAt): int
    {
        if ($traceIds === [] || ! DatabaseTableAvailability::has('ai_traces')) {
            return 0;
        }

        $metadata = [
            'thread_deleted' => true,
            'thread_deleted_at' => $deletedAt->toJSON(),
            'deleted_thread_id_hash' => hash('sha256', $threadId),
            'deletion_scope' => 'content_purge_keep_trace_tombstone',
        ];

        $update = [
            'thread_id' => null,
            'session_id' => null,
            'source_type' => 'system',
            'source_id' => null,
            'operator_input' => '[deleted thread]',
            'intent' => null,
            'skill_versions' => json_encode([], JSON_THROW_ON_ERROR),
            'context_refs' => json_encode([], JSON_THROW_ON_ERROR),
            'prompt_hash' => null,
            'response_hash' => null,
            'response_text' => null,
            'feedback_comment' => null,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ];

        if (DatabaseTableAvailability::hasColumn('ai_traces', 'updated_at')) {
            $update['updated_at'] = $deletedAt;
        }

        return DB::table('ai_traces')
            ->whereIn('id', $traceIds)
            ->update($update);
    }
}
