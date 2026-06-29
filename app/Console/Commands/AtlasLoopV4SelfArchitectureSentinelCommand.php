<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\V4\AtlasLoopV4SelfArchitectureSentinel;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopV4SelfArchitectureSentinel::audit()} at the operator surface: reads the
 * accepted self-architecture proposals from a JSON file and, against the pétreo forbidden-self-targets,
 * emits the sentinel verdict — healthy plus any monoculture / proximity-creep / rationale-decay alerts — as
 * deterministic facts. Read-only: it only audits the proposal set; it accepts/blocks nothing.
 *
 * Input JSON: a bare proposal list, or {proposals:[...]} (each: {kind, target_path, rationale}).
 */
final class AtlasLoopV4SelfArchitectureSentinelCommand extends Command
{
    protected $signature = 'atlas:loop:v4-self-architecture-sentinel {--input=} {--json}';

    protected $description = 'Read-only V4 self-architecture sentinel verdict over accepted proposals (vs pétreo targets).';

    public function handle(AtlasLoopV4SelfArchitectureSentinel $sentinel): int
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

        $payload = json_decode((string) file_get_contents($input), true);
        if (! is_array($payload)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'input' => $input], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $proposals = array_key_exists('proposals', $payload) ? (array) $payload['proposals'] : $payload;
        $verdict = $sentinel->audit(
            array_values(array_filter($proposals, 'is_array')),
            AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS,
        );

        $this->line((string) json_encode(
            ['schema' => 'atlas.loop.v4_self_architecture_sentinel.v1'] + $verdict,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
