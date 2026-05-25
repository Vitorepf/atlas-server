<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendCompetitiveRubricService;
use Illuminate\Console\Command;

class AtlasFrontendCompetitiveRubricCommand extends Command
{
    protected $signature = 'atlas:frontend:rubric
        {--json : Emit canonical JSON payload}';

    protected $description = 'Emit the Atlas Frontend competitive scoring rubric.';

    public function handle(AtlasFrontendCompetitiveRubricService $rubric): int
    {
        $payload = $rubric->rubric();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line(sprintf('Atlas Frontend Competitive Rubric: %s/%s dimensions', count($payload['dimensions']), $payload['score_max']));
        }

        return self::SUCCESS;
    }
}
