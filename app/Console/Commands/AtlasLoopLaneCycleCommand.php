<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneRuntimeInstanceCycleRunner;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasProjectLaneRuntimeInstanceCycleRunner::run()} at the operator surface: previews
 * a project-lane runtime-instance cycle — which planned actions would apply, be blocked (cross-lane /
 * namespace / write-root / refused-kind) or be withheld — emitting the cycle plan as deterministic facts.
 * Strictly preview/read-only: it FORCES dry-run on and strips any callbacks, so it executes nothing.
 *
 * --instance / --facts / --options accept inline JSON or a path to a JSON file.
 */
final class AtlasLoopLaneCycleCommand extends Command
{
    protected $signature = 'atlas:loop:lane-cycle {--instance=} {--facts=} {--options=} {--json}';

    protected $description = 'Read-only: preview a project-lane runtime-instance cycle (dry-run forced; executes nothing).';

    public function handle(AtlasProjectLaneRuntimeInstanceCycleRunner $runner): int
    {
        $instanceOption = $this->option('instance');
        if ($instanceOption === null || trim((string) $instanceOption) === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'instance_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }
        $instance = $this->decode($instanceOption);
        if ($instance === null) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'option' => 'instance'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $options = $this->decode($this->option('options')) ?? [];
        // FORCE preview: never apply, never run a callback — strictly read-only regardless of supplied options.
        $options['apply'] = false;
        unset($options['action_callbacks']);

        $this->line((string) json_encode(
            $runner->run($instance, $this->decode($this->option('facts')) ?? [], $options),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decode(mixed $value): ?array
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $raw = is_file((string) $value) ? (string) file_get_contents((string) $value) : (string) $value;
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
