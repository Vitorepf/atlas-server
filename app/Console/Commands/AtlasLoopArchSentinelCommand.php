<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\V4\AtlasLoopV4SelfArchitectureSentinel;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopV4SelfArchitectureSentinel::audit()} at the operator surface with BOTH the
 * accepted proposals AND the forbidden-self-targets floor supplied as input: emits the audit verdict —
 * healthy plus any monoculture / proximity-creep / rationale-decay alerts — as deterministic facts. A
 * proposal whose directory touches a forbidden target's directory is flagged. Pure and read-only.
 *
 * --proposals / --forbidden accept inline JSON or a path to a JSON file.
 */
final class AtlasLoopArchSentinelCommand extends Command
{
    protected $signature = 'atlas:loop:arch-sentinel {--proposals=} {--forbidden=} {--json}';

    protected $description = 'Read-only: audit accepted self-architecture proposals against a forbidden-self-targets floor.';

    public function handle(AtlasLoopV4SelfArchitectureSentinel $sentinel): int
    {
        $proposalsOption = $this->option('proposals');
        if ($proposalsOption === null || trim((string) $proposalsOption) === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'proposals_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }
        $proposals = $this->decode($proposalsOption);
        if ($proposals === null) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'option' => 'proposals'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $verdict = $sentinel->audit(
            array_values(array_filter($proposals, 'is_array')),
            array_values($this->decode($this->option('forbidden')) ?? []),
        );

        $this->line((string) json_encode(
            ['schema' => 'atlas.loop.v4_self_architecture_sentinel.v1'] + $verdict,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<int|string,mixed>|null
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
