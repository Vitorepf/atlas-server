<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasSpuriousMemoryNoisePurgeService;
use Illuminate\Console\Command;
use App\Support\YesNo;

final class AtlasContextPurgeSpuriousMemoryNoiseCommand extends Command
{
    protected $signature = 'atlas:context:purge-spurious-memory-noise
        {--apply : Persist the purge (default: dry-run report)}
        {--json : Emit JSON output}';

    protected $description = 'FEE-06: purge historical spurious memory demote votes from ai_rag_feedback_events.';

    public function handle(AtlasSpuriousMemoryNoisePurgeService $service): int
    {
        $result = $service->purge(dryRun: ! (bool) $this->option('apply'));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'spurious-memory-noise purge: matched=%d purged=%d dry_run=%s',
            $result['matched'],
            $result['purged'],
            YesNo::trueFalse($result['dry_run']),
        ));

        return self::SUCCESS;
    }
}
