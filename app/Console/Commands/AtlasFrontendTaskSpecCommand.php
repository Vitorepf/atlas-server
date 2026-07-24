<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendTaskSpecCompilerService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasFrontendTaskSpecCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:frontend:spec
        {--task= : Frontend task or brief}
        {--surface=programming.frontend : Surface}
        {--workspace= : Workspace/project path or identifier}
        {--frontend-app= : Optional frontend app subdirectory inside the selected repository, e.g. apps/web}
        {--route=* : Route hints}
        {--hint=* : Additional provider-safe hints}
        {--acceptance : Acceptance context exists}
        {--asset-context : Asset provenance or placeholder policy exists}
        {--company-profile : Ready company design profile exists}
        {--prototype : Prototype/discovery mode requested}
        {--live : Live visual iteration mode requested}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Compile a provider-safe deterministic Atlas Frontend task spec.';

    public function handle(AtlasFrontendTaskSpecCompilerService $compiler): int
    {
        $payload = $compiler->compile([
            'task' => (string) ($this->option('task') ?: ''),
            'surface' => (string) ($this->option('surface') ?: 'programming.frontend'),
            'workspace' => (string) ($this->option('workspace') ?: ''),
            'frontend_app' => (string) ($this->option('frontend-app') ?: ''),
            'routes' => (array) $this->option('route'),
            'hints' => (array) $this->option('hint'),
            'acceptance' => (bool) $this->option('acceptance'),
            'asset_context' => (bool) $this->option('asset-context'),
            'company_profile' => (bool) $this->option('company-profile'),
            'prototype' => (bool) $this->option('prototype'),
            'live' => (bool) $this->option('live'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->line('Atlas Frontend Task Spec: '.$payload['status']);
        }

        return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
