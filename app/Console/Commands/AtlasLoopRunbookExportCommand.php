<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionOperatorRunbookExporterService;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasSelfConstructionRuntimePromotionOperatorRunbookExporterService::build()} at the
 * operator surface: renders the runtime-promotion operator runbook markdown, exports it to the configured
 * storage disk, and reports the export path as deterministic facts. Read-only mode — its ONLY write is the
 * runbook artifact; it enables no runtime, persists no receipt, signs nothing. Export-on by default; pass
 * `{"persist_export": false}` in --options for an in-memory render only.
 *
 * --options accepts inline JSON or a path to a JSON file.
 */
final class AtlasLoopRunbookExportCommand extends Command
{
    protected $signature = 'atlas:loop:runbook-export {--options=} {--json}';

    protected $description = 'Read-only: render + export the runtime-promotion operator runbook and report the path.';

    public function handle(AtlasSelfConstructionRuntimePromotionOperatorRunbookExporterService $exporter): int
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

        // Export by default (the command's job is to export + report the path); --options may opt out.
        if (! array_key_exists('persist_export', $options)) {
            $options['persist_export'] = true;
        }

        $this->line((string) json_encode(
            $exporter->build($options),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
