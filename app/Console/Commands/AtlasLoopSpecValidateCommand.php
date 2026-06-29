<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricPacketSpecValidator;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasTaskFabricPacketSpecValidator::validate()} at the operator surface: validates a
 * task-packet spec BEFORE it is seeded and emits the result — self-sufficiency plus the structural blockers
 * and the advisory worker-instruction lint — as deterministic facts. Pure and read-only; it constructs the
 * validator with no inspector (no delegation) and never edits it.
 *
 * --spec accepts inline JSON or a path to a JSON file.
 */
final class AtlasLoopSpecValidateCommand extends Command
{
    protected $signature = 'atlas:loop:spec-validate {--spec=} {--json}';

    protected $description = 'Read-only: validate a task-packet spec (self-sufficiency, blockers) before seeding.';

    public function handle(): int
    {
        $specOption = $this->option('spec');
        if ($specOption === null || trim((string) $specOption) === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'spec_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $raw = is_file((string) $specOption) ? (string) file_get_contents((string) $specOption) : (string) $specOption;
        $spec = json_decode($raw, true);
        if (! is_array($spec)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'spec' => (string) $specOption], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $this->line((string) json_encode(
            (new AtlasTaskFabricPacketSpecValidator)->validate($spec),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
