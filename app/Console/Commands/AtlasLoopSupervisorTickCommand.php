<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\UnattendedRuntime\AtlasSelfConstructionUnattendedSupervisorCycle;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasSelfConstructionUnattendedSupervisorCycle::tick()} at the operator surface:
 * for a facts snapshot, previews what the unattended supervisor WOULD decide (classification + planned /
 * blocked recovery actions) as deterministic facts. Strictly advisory — it runs tick() with an EMPTY
 * callbacks array and no apply option, so no action is executed and nothing is mutated (dry_run).
 *
 * --facts accepts inline JSON or a path to a JSON file.
 */
final class AtlasLoopSupervisorTickCommand extends Command
{
    protected $signature = 'atlas:loop:supervisor-tick {--facts=} {--json}';

    protected $description = 'Read-only/advisory: preview the unattended supervisor decision for a facts snapshot.';

    public function handle(AtlasSelfConstructionUnattendedSupervisorCycle $cycle): int
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

        // EMPTY callbacks + no apply option ⇒ strictly advisory: nothing executes, nothing mutates.
        $this->line((string) json_encode(
            $cycle->tick($facts, [], []),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
