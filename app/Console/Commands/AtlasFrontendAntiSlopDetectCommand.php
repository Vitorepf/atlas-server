<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendAntiSlopDetectorService;
use Illuminate\Console\Command;

class AtlasFrontendAntiSlopDetectCommand extends Command
{
    protected $signature = 'atlas:frontend:detect
        {--path= : File or directory to inspect. Defaults to current workspace}
        {--strict : Exit non-zero when high severity findings exist}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Run the deterministic Atlas Frontend anti-AI-slop detector.';

    public function handle(AtlasFrontendAntiSlopDetectorService $detector): int
    {
        $path = (string) ($this->option('path') ?: base_path());
        $payload = $detector->inspectPath($path, (bool) $this->option('strict'));

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Anti-Slop Detector: '.($payload['status'] ?? 'unknown'));
            $this->line('Findings: '.($payload['finding_count'] ?? 0));
        }

        return ($payload['status'] ?? 'failed') === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
