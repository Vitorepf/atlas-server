<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\ExternalDeps\AtlasLoopExternalDepInventoryReporter;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopExternalDepInventoryReporter::inventory()} at the operator surface: a
 * read-only inventory of the project's external dependencies (composer packages with declared constraint and
 * resolved version, plus lock/composer-json presence) emitted as JSON. Facts only — reads composer.json/.lock.
 */
final class AtlasLoopExternalDepInventoryCommand extends Command
{
    protected $signature = 'atlas:loop:external-dep-inventory {--json}';

    protected $description = 'Read-only external-dependency inventory (composer packages, constraints, resolved versions).';

    public function handle(AtlasLoopExternalDepInventoryReporter $reporter): int
    {
        $this->line((string) json_encode($reporter->inventory(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
