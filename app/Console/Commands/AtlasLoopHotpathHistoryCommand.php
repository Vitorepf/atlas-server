<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Hotpath\AtlasCortexHotpathHistoryReporter;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasCortexHotpathHistoryReporter::report()} at the operator surface: reads
 * oldest-first cycle facts from a JSON file and emits the cortex hotpath history — per FQCN, the
 * per-cycle appearance vector over the window — as deterministic facts. Read-only and pure; it only
 * tabulates the supplied facts.
 */
final class AtlasLoopHotpathHistoryCommand extends Command
{
    protected $signature = 'atlas:loop:hotpath-history {--input=} {--json}';

    protected $description = 'Read-only cortex hotpath history (per-FQCN appearance vectors) from cycle facts (JSON list).';

    public function handle(AtlasCortexHotpathHistoryReporter $reporter): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'input_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }
        if (! is_file($input)) {
            $this->line((string) json_encode(['status' => 'input_not_found', 'input' => $input], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $cycleFacts = json_decode((string) file_get_contents($input), true);
        if (! is_array($cycleFacts)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'input' => $input], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $history = $reporter->report(array_values(array_filter($cycleFacts, 'is_array')));

        $this->line((string) json_encode([
            'schema' => 'atlas.loop.hotpath_history.v1',
            'window_size' => count(array_filter($cycleFacts, 'is_array')),
            'count' => count($history),
            'history' => $history,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
