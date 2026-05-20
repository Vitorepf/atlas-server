<?php

namespace App\Console\Commands;

use App\Services\Ai\AgenticWorkcell\AtlasAgenticWorkcellCertificationService;
use Illuminate\Console\Command;

class AtlasAgenticWorkcellCertifyCommand extends Command
{
    protected $signature = 'atlas:agentic-workcell:certify {--json : Emit JSON} {--strict : Exit non-zero unless passed}';

    protected $description = 'Certify AAWR, the Atlas Agentic Workcell Runtime.';

    public function handle(AtlasAgenticWorkcellCertificationService $certification): int
    {
        $payload = $certification->certify();
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            $this->components->twoColumnDetail('AAWR certification', (string) $payload['status']);
            $this->components->twoColumnDetail('Checks', $payload['summary']['pass'].'/'.$payload['summary']['total']);
            $this->components->twoColumnDetail('Hash', (string) $payload['certification_hash']);
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'passed'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
