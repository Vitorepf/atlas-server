<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignDirectionAdvisorService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasFrontendDesignDirectionCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:frontend:directions
        {--task= : Frontend task or brief}
        {--surface=programming.frontend : Surface}
        {--hint=* : Additional provider-safe hints}
        {--company-profile-hash= : Optional inspected company profile hash/ref}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Generate governed Atlas Frontend design directions for ambiguous or broad briefs.';

    public function handle(AtlasFrontendDesignDirectionAdvisorService $advisor): int
    {
        $payload = $advisor->advise([
            'task' => (string) ($this->option('task') ?: ''),
            'surface' => (string) ($this->option('surface') ?: 'programming.frontend'),
            'hints' => (array) $this->option('hint'),
            'company_profile_hash' => (string) ($this->option('company-profile-hash') ?: ''),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->line('Atlas Frontend Design Directions: '.$payload['status']);
        }

        return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
