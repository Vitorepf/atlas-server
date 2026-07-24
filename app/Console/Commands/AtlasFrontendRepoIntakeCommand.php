<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendRepoIntakeService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasFrontendRepoIntakeCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:frontend:intake
        {--workspace= : Local company/product frontend repository path}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless repo intake is ready}';

    protected $description = 'Inspect a local company frontend repository and emit the Atlas Frontend operating map.';

    public function handle(AtlasFrontendRepoIntakeService $intake): int
    {
        $payload = $intake->inspect((string) ($this->option('workspace') ?? ''));

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->line('Atlas Frontend Repo Intake: '.$payload['status']);
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
