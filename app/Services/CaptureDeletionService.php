<?php

namespace App\Services;

use App\Models\Capture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class CaptureDeletionService
{
    /**
     * Hard-delete an Inbox capture and remove content-bearing projections.
     *
     * Derived objects the operator may have intentionally created (tasks,
     * projects, routines, logs) are not destroyed, but their source pointer is
     * nulled. Candidate/proposal/context/memory records generated from the raw
     * capture are deleted so the raw note cannot keep teaching retrieval.
     *
     * @return array<string, mixed>
     */
    public function delete(Capture $capture): array
    {
        return DB::transaction(function () use ($capture): array {
            $captureId = (string) $capture->id;
            $clientId = (string) $capture->client_id;
            $filePath = $capture->content_file_path;
            $referenceIds = array_values(array_unique(array_filter([$captureId, $clientId])));
            $contextBundleIds = $this->idsContaining('ai_context_bundles', [
                'source_refs',
                'trace_refs',
                'job_refs',
                'metric_refs',
                'file_refs',
                'raw_payload',
                'body_for_thread',
                'summary',
            ], $referenceIds);

            $counts = [];
            $counts['inbox_items'] = $this->deleteMatching('ai_inbox_items', [
                'source_id' => $referenceIds,
                'context_bundle_id' => $contextBundleIds,
            ]);
            $counts['context_bundles'] = $this->deleteMatching('ai_context_bundles', [
                'id' => $contextBundleIds,
            ]);
            $counts['capture_links'] = $this->deleteMatching('capture_links', [
                'capture_id' => [$captureId],
            ]);
            $counts['transcription_jobs'] = $this->deleteMatching('transcription_jobs', [
                'capture_id' => [$captureId],
            ]);
            $counts['semantic_curation_proposals'] = $this->deleteContaining('semantic_curation_proposals', [
                'source_refs',
                'proposed_body',
                'proposed_summary',
                'reason',
                'metadata',
            ], $referenceIds);
            $counts['project_plan_proposals'] = $this->deleteMatching('atlas_project_plan_proposals', [
                'source_capture_id' => [$captureId],
            ]);
            $counts['memory_usages'] = $this->deleteContaining('atlas_memory_entry_usages', [
                'source_ref_json',
                'context_payload_json',
                'metadata',
                'included_reason',
            ], $referenceIds);
            $counts['memory_entries'] = $this->deleteMatching('atlas_memory_entries', [
                'source_id' => $referenceIds,
            ]);

            $counts['tasks_unlinked'] = $this->nullMatching('atlas_tasks', 'source_capture_id', [$captureId]);
            $counts['projects_unlinked'] = $this->nullMatching('atlas_projects', 'source_capture_id', [$captureId]);
            $counts['routines_unlinked'] = $this->nullMatching('atlas_routines', 'source_capture_id', [$captureId]);
            $counts['behavior_logs_unlinked'] = $this->nullMatching('behavior_logs', 'source_capture_id', [$captureId]);
            $counts['digital_sessions_unlinked'] = $this->nullMatching('digital_sessions', 'linked_capture_id', [$captureId]);

            $fileDeleted = $this->deleteFile($filePath);
            $capture->forceDelete();

            return [
                'capture_id' => $captureId,
                'client_id' => $clientId,
                'content_purged' => true,
                'file_deleted' => $fileDeleted,
                'counts' => $counts,
                'deleted_at' => now()->toJSON(),
            ];
        });
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $needles
     * @return list<string>
     */
    private function idsContaining(string $table, array $columns, array $needles): array
    {
        if ($needles === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id')) {
            return [];
        }

        $query = DB::table($table);
        $matched = false;

        $query->where(function ($where) use ($table, $columns, $needles, &$matched): void {
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
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
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);
        $matched = false;

        $query->where(function ($where) use ($table, $columns, &$matched): void {
            foreach ($columns as $column => $values) {
                if ($values === [] || ! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $matched = true;
                $where->orWhereIn($column, $values);
            }
        });

        return $matched ? $query->delete() : 0;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $needles
     */
    private function deleteContaining(string $table, array $columns, array $needles): int
    {
        $ids = $this->idsContaining($table, $columns, $needles);

        return $this->deleteMatching($table, ['id' => $ids]);
    }

    /**
     * @param  list<string>  $values
     */
    private function nullMatching(string $table, string $column, array $values): int
    {
        if ($values === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        $update = [$column => null];
        if (Schema::hasColumn($table, 'updated_at')) {
            $update['updated_at'] = now();
        }

        return DB::table($table)
            ->whereIn($column, $values)
            ->update($update);
    }

    private function deleteFile(?string $filePath): bool
    {
        if (! $filePath) {
            return false;
        }

        $disk = Storage::disk('atlas');
        if (! $disk->exists($filePath)) {
            return false;
        }

        return $disk->delete($filePath);
    }
}
