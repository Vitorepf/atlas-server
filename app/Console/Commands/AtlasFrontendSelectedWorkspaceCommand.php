<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendSelectedWorkspaceService;
use Illuminate\Console\Command;

class AtlasFrontendSelectedWorkspaceCommand extends Command
{
    protected $signature = 'atlas:frontend:selected-workspace
        {--task= : Frontend task or operator intent to bind to the selected repository}
        {--workspace= : Operator-selected local company/product frontend repository path}
        {--frontend-app= : Optional frontend app sub-scope inside the selected repository, for example apps/web}
        {--selection-source=atlas_code : Surface that selected the workspace}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless the selected workspace is a project-scoped repo}';

    protected $description = 'Validate the operator-selected local company/product frontend repository path as the primary Atlas Frontend workspace.';

    public function handle(AtlasFrontendSelectedWorkspaceService $selectedWorkspace): int
    {
        $payload = $selectedWorkspace->resolve([
            'task' => (string) ($this->option('task') ?: ''),
            'workspace' => (string) ($this->option('workspace') ?: ''),
            'frontend_app' => (string) ($this->option('frontend-app') ?: ''),
            'selection_source' => (string) ($this->option('selection-source') ?: 'atlas_code'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Selected Workspace: '.$payload['status']);
        }

        $invalidFrontendApp = data_get($payload, 'frontend_app_candidates.status') === 'requested_frontend_app_subscope_invalid';

        return (bool) $this->option('strict') && (($payload['status'] ?? null) !== 'selected' || $invalidFrontendApp)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
