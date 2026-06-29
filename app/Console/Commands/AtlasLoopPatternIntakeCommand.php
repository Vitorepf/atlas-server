<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternSourceIntake;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Arms the dormant {@see AtlasLoopPatternSourceIntake::intake()} at the operator surface: quarantines external
 * pattern material into a governed pattern spec — born UN-SELECTABLE (status source_material/candidate, an
 * UNPROVEN gate banner, deny-by-default sandbox) — and emits it as deterministic facts. Pure and read-only:
 * intake to quarantine only; it never installs or promotes.
 *
 * --material accepts inline JSON or a path to a JSON file.
 */
final class AtlasLoopPatternIntakeCommand extends Command
{
    protected $signature = 'atlas:loop:pattern-intake {--material=} {--json}';

    protected $description = 'Read-only: quarantine external pattern material into an un-selectable governed pattern spec.';

    public function handle(AtlasLoopPatternSourceIntake $intake): int
    {
        $materialOption = $this->option('material');
        if ($materialOption === null || trim((string) $materialOption) === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'material_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $raw = is_file((string) $materialOption) ? (string) file_get_contents((string) $materialOption) : (string) $materialOption;
        $material = json_decode($raw, true);
        if (! is_array($material)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'material' => (string) $materialOption], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        try {
            $spec = $intake->intake($material);
        } catch (InvalidArgumentException $e) {
            $this->line((string) json_encode(['status' => 'intake_error', 'reason' => $e->getMessage()], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $this->line((string) json_encode(
            [
                'schema' => 'atlas.loop.pattern_intake.v1',
                'selectable' => $spec->isSelectable(),
                'spec' => $spec->toArray(),
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
