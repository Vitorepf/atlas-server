<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendSelectedWorkspaceService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasFrontendSelectedWorkspaceCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:frontend:selected-workspace
        {--task= : Frontend task or operator intent to bind to the selected repository}
        {--workspace= : Operator-selected local company/product frontend repository path}
        {--frontend-app= : Optional frontend app sub-scope inside the selected repository, for example apps/web}
        {--selection-source=atlas_code : Surface that selected the workspace}
        {--portfolio-root= : Optional parent directory that was scanned before selecting this repo}
        {--selection-receipt : Emit the multi-repo selected repository receipt instead of the raw selected workspace contract}
        {--output= : Optional JSON file path for the selection receipt}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless the selected workspace is a project-scoped repo}';

    protected $description = 'Validate the operator-selected local company/product frontend repository path as the primary Atlas Frontend workspace.';

    public function handle(AtlasFrontendSelectedWorkspaceService $selectedWorkspace): int
    {
        $input = [
            'task' => (string) ($this->option('task') ?: ''),
            'workspace' => (string) ($this->option('workspace') ?: ''),
            'frontend_app' => (string) ($this->option('frontend-app') ?: ''),
            'selection_source' => (string) ($this->option('selection-source') ?: 'atlas_code'),
            'portfolio_root' => (string) ($this->option('portfolio-root') ?: ''),
            'output' => (string) ($this->option('output') ?: ''),
        ];
        $payload = (bool) $this->option('selection-receipt')
            ? $selectedWorkspace->selectionReceipt($input)
            : $selectedWorkspace->resolve($input);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->line('Atlas Frontend Selected Workspace: '.$payload['status']);
        }

        $invalidFrontendApp = data_get($payload, 'frontend_app_candidates.status') === 'requested_frontend_app_subscope_invalid'
            || in_array('requested_frontend_app_subscope_not_found', (array) ($payload['blockers'] ?? []), true);
        $readyStatus = (bool) $this->option('selection-receipt') ? 'ready' : 'selected';

        return (bool) $this->option('strict') && (($payload['status'] ?? null) !== $readyStatus || $invalidFrontendApp)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
