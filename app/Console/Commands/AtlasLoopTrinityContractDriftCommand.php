<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\AtlasLoopTrinityContractDriftDetector;
use Illuminate\Console\Command;

/**
 * Operator-facing forensic CLI for the Trinity anti-decoupling drift detector. Prints the descending list of
 * {@see \App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\TrinityContractBreachFact} records as
 * JSON. NO scoring/severity keys appear in the output (pétreo).
 */
final class AtlasLoopTrinityContractDriftCommand extends Command
{
    public const FROZEN_CONTRACT_KEY = 'atlas.trinity.contract.frozen';

    public const COMMITS_SOURCE_KEY = 'atlas.trinity.contract.commits_source';

    public const PROVIDER_FACTORY_KEY = 'atlas.trinity.contract.provider_factory';

    /** @var string */
    protected $signature = 'atlas:loop:trinity:contract-drift {--window=100} {--json}';

    /** @var string */
    protected $description = 'Forensic list of every commit that broke the frozen Trinity contract.';

    public function handle(): int
    {
        $app = $this->getLaravel();
        $frozen = $app->bound(self::FROZEN_CONTRACT_KEY) ? $app->make(self::FROZEN_CONTRACT_KEY) : null;
        $commitsSource = $app->bound(self::COMMITS_SOURCE_KEY) ? $app->make(self::COMMITS_SOURCE_KEY) : null;
        $providerFactory = $app->bound(self::PROVIDER_FACTORY_KEY) ? $app->make(self::PROVIDER_FACTORY_KEY) : null;

        if (! is_array($frozen) || ! is_callable($commitsSource) || ! is_callable($providerFactory)) {
            $this->line(json_encode([
                'status' => 'skipped',
                'reason' => 'drift_detector_unwired',
                'binding_keys' => [self::FROZEN_CONTRACT_KEY, self::COMMITS_SOURCE_KEY, self::PROVIDER_FACTORY_KEY],
            ], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $window = max(1, (int) $this->option('window'));
        $detector = new AtlasLoopTrinityContractDriftDetector($commitsSource, $providerFactory);
        $facts = $detector->detect($frozen, $window);

        $this->line(json_encode(['facts' => array_map(static fn ($f) => $f->toArray(), $facts)], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
