<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeEndgameService;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasSelfConstructionRealProviderSmokeEndgameService::build()} at the operator
 * surface: builds the real-provider smoke endgame report (the smoke under review + the required-evidence /
 * operator-approval / provider-observation / token-cost / work-product contracts and the persistence
 * preflight) and emits it as deterministic facts. Read-only — it composes the report; it runs no provider.
 *
 * --options accepts inline JSON or a path to a JSON file.
 */
final class AtlasLoopSmokeEndgameCommand extends Command
{
    protected $signature = 'atlas:loop:smoke-endgame {--options=} {--json}';

    protected $description = 'Read-only: build the real-provider smoke endgame report (runs no provider).';

    public function handle(AtlasSelfConstructionRealProviderSmokeEndgameService $service): int
    {
        $optionsValue = $this->option('options');
        $options = [];
        if ($optionsValue !== null && trim((string) $optionsValue) !== '') {
            $raw = is_file((string) $optionsValue) ? (string) file_get_contents((string) $optionsValue) : (string) $optionsValue;
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                $this->line((string) json_encode(['status' => 'invalid_json', 'options' => (string) $optionsValue], JSON_UNESCAPED_SLASHES));

                return self::INVALID;
            }
            $options = $decoded;
        }

        $this->line((string) json_encode(
            $service->build($options),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
