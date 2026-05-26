<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService;
use Illuminate\Console\Command;

class AtlasSelfConstructionDetectGapsCommand extends Command
{
    protected $signature = 'atlas:self-construction:detect-gaps {--json : JSON output}';

    protected $description = 'Atlas Self-Construction · detect gaps in the live ACOS scorecard (read-only).';

    public function handle(AtlasSelfConstructionSubsystemBuilderService $svc): int
    {
        $gaps = $svc->detectGaps();
        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => true,
                'action' => 'detect-gaps',
                'gap_count' => count($gaps),
                'gaps' => $gaps,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }
        $this->line('[atlas:self-construction:detect-gaps]');
        $this->line('gap_count='.count($gaps));
        foreach ($gaps as $g) {
            $this->line(sprintf('  %s · %s · %s', $g['kind'], $g['subsystem_acronym'], $g['rationale']));
        }

        return 0;
    }
}
