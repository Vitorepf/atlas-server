<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Architecture\AtlasRuntimeLanguageBoundaryReportService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAiRuntimeBoundaryCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:runtime-boundary {--json : Print machine-readable JSON}';

    protected $description = 'Report Atlas AI runtime language boundary status for Laravel, Python, Go and Swift.';

    public function handle(AtlasRuntimeLanguageBoundaryReportService $reporter): int
    {
        $payload = $reporter->report();
        $valid = (bool) data_get($payload, 'boundary.valid');
        $violations = (array) data_get($payload, 'boundary.violations', []);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $valid ? self::SUCCESS : self::FAILURE;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Runtime Boundary</>', $payload['status']);
        $this->components->twoColumnDetail('Violations', (string) $payload['boundary']['violation_count']);
        $this->components->twoColumnDetail('Next action', $payload['next_action']);

        foreach ($violations as $violation) {
            $this->error((string) $violation);
        }

        return $valid ? self::SUCCESS : self::FAILURE;
    }
}
