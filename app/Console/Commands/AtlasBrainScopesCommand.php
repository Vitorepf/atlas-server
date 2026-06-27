<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeCatalogSnapshot;
use Illuminate\Console\Command;

/**
 * BRAIN SCOPES — dumps the configured scope cohort via L119 snapshot. Read-only complement to
 * `atlas:brain:catalog` (paths). Pétreo.
 */
final class AtlasBrainScopesCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:scopes {--json} {--raw : single-line JSON}';

    /** @var string */
    protected $description = 'Dump the configured brain scope cohort (slug, label, meta_harness, roots_count).';

    public function handle(): int
    {
        $payload = app(AtlasBrainScopeCatalogSnapshot::class)->snapshot();
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (! $this->option('raw')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $this->line((string) json_encode($payload, $flags));

        return self::SUCCESS;
    }
}
