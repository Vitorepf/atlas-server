<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\OperatorInterface\AtlasSelfConstructionOperatorVisibilityComposer;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasSelfConstructionOperatorVisibilityComposer::compose()} at the operator surface:
 * reads raw facts (per visibility section) from JSON and emits the operator-facing visibility view — one
 * block per section with {label, status, machine} — as deterministic facts. Pure and read-only; the
 * composer's read-only-with-emergency-stop posture is preserved.
 *
 * --facts accepts inline JSON or a path to a JSON file.
 */
final class AtlasLoopOperatorVisibilityCommand extends Command
{
    protected $signature = 'atlas:loop:operator-visibility {--facts=} {--json}';

    protected $description = 'Read-only: compose the operator visibility view (label/status/machine) from raw facts.';

    public function handle(AtlasSelfConstructionOperatorVisibilityComposer $composer): int
    {
        $factsOption = $this->option('facts');
        if ($factsOption === null || trim((string) $factsOption) === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'facts_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $raw = is_file((string) $factsOption) ? (string) file_get_contents((string) $factsOption) : (string) $factsOption;
        $facts = json_decode($raw, true);
        if (! is_array($facts)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'facts' => (string) $factsOption], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $this->line((string) json_encode(
            $composer->compose($facts),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
