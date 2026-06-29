<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionClosureExecutionPackService;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasSelfConstructionRuntimePromotionClosureExecutionPackService::build()} at the
 * operator surface: builds the read-only runtime-promotion closure execution pack (gap matrix, dossier,
 * closure pack, runbook, ordered operator steps and persistence preflight) from options and emits it as
 * deterministic facts. Build only — it executes NOTHING and persists no receipt.
 *
 * --options accepts inline JSON or a path to a JSON file.
 */
final class AtlasLoopPromotionPackCommand extends Command
{
    protected $signature = 'atlas:loop:promotion-pack {--options=} {--json}';

    protected $description = 'Read-only: build the runtime-promotion closure execution pack (build only, executes nothing).';

    public function handle(AtlasSelfConstructionRuntimePromotionClosureExecutionPackService $service): int
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
