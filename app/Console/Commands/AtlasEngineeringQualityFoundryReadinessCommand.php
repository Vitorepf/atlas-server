<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\EngineeringKernel\QualityFoundry\QualityFoundryReadinessManifest;
use Illuminate\Console\Command;
use App\Support\YesNo;

final class AtlasEngineeringQualityFoundryReadinessCommand extends Command
{
    protected $signature = 'atlas:engineering:quality-foundry-readiness
        {--evidence= : JSON evidence bundle produced by independent runtime owners}
        {--json : Machine-readable JSON}';

    protected $description = 'Read-only checklist manifest for the Atlas Quality Foundry master plan.';

    public function handle(QualityFoundryReadinessManifest $manifest): int
    {
        $evidence = [];
        $path = $this->option('evidence');
        if (is_string($path) && trim($path) !== '' && is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            $evidence = is_array($decoded) ? $decoded : [];
        }
        $payload = $manifest->build($evidence);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('[atlas:engineering:quality-foundry-readiness] status='.$payload['status']);
        $this->line('  open_items='.$payload['summary']['open_items']);
        $this->line('  completion_allowed='.(YesNo::trueFalse($payload['completion_allowed'])));

        return self::SUCCESS;
    }
}
