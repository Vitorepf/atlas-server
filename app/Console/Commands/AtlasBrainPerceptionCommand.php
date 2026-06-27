<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPerceptionBundle;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeRegistry;
use Illuminate\Console\Command;

/**
 * BRAIN PERCEPTION — dumps the L150 perception bundle for a scope: consolidated payload of every
 * read-only perception organ. Read-only. Pétreo.
 */
final class AtlasBrainPerceptionCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:perception {--scope= : scope slug} {--json} {--raw : single-line JSON}';

    /** @var string */
    protected $description = 'Dump the consolidated perception bundle (all read-only perception organs at once).';

    public function handle(): int
    {
        $scopeOpt = trim((string) ($this->option('scope') ?? ''));
        $scope = (string) app(AtlasBrainScopeRegistry::class)->resolve($scopeOpt)['slug'];
        $payload = app(AtlasBrainPerceptionBundle::class)->build($scope);

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (! $this->option('raw')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $this->line((string) json_encode($payload, $flags));

        return self::SUCCESS;
    }
}
