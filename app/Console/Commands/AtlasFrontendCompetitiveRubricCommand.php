<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendCompetitiveRubricService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasFrontendCompetitiveRubricCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:frontend:rubric
        {--json : Emit canonical JSON payload}';

    protected $description = 'Emit the Atlas Frontend competitive scoring rubric.';

    public function handle(AtlasFrontendCompetitiveRubricService $rubric): int
    {
        $payload = $rubric->rubric();

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->line(sprintf('Atlas Frontend Competitive Rubric: %s/%s dimensions', count($payload['dimensions']), $payload['score_max']));
        }

        return self::SUCCESS;
    }
}
