<?php

namespace App\Services\Tools;

use App\Models\AtlasToolRun;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;

class AtlasToolEvidenceQueryService
{
    /**
     * @param  array<string,mixed>  $filters
     * @return Collection<int,AtlasToolRun>
     */
    public function recent(array $filters = []): Collection
    {
        if (! Schema::hasTable('atlas_tool_runs')) {
            return new Collection;
        }

        $query = AtlasToolRun::query()
            ->with(['tool', 'artifacts', 'findings'])
            ->latest('created_at');

        $workspaceHash = $this->workspaceHash($filters['workspace'] ?? null);
        if ($workspaceHash !== null) {
            $query->where('workspace_hash', $workspaceHash);
        }

        foreach (['tool_slug', 'surface', 'status', 'policy_decision', 'run_context_type', 'run_context_id'] as $field) {
            $value = $filters[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $query->where($field, trim($value));
            }
        }

        if (isset($filters['required'])) {
            $query->where('required', filter_var($filters['required'], FILTER_VALIDATE_BOOLEAN));
        }

        $limit = max(1, min(200, is_numeric($filters['limit'] ?? null) ? (int) $filters['limit'] : 20));

        return $query->limit($limit)->get();
    }

    private function workspaceHash(mixed $workspace): ?string
    {
        if (! is_string($workspace) || trim($workspace) === '') {
            return null;
        }

        return hash('sha256', realpath($workspace) ?: $workspace);
    }
}
