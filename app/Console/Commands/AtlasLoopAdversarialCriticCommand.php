<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopAdversarialCritic;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopAdversarialCritic::challenge()} at the operator surface: reads the
 * leverage(ratio)-winner and the floor-passers from a JSON file and emits the adversarial critique — does
 * the cheap ratio-winner get challenged and replaced by a genuinely bigger leap? — as deterministic facts
 * (the canonical quality phase). Read-only and pure.
 *
 * Input JSON: {winner:{...,_score:{components:{...}}}, floor_passers:[...]}.
 */
final class AtlasLoopAdversarialCriticCommand extends Command
{
    protected $signature = 'atlas:loop:adversarial-critic {--input=} {--json}';

    protected $description = 'Read-only adversarial critique: is the ratio-winner the biggest genuine leap (or challenged)?';

    public function handle(AtlasLoopAdversarialCritic $critic): int
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
        if (! is_array($payload) || ! is_array($payload['winner'] ?? null)) {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'winner_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $result = $critic->challenge(
            (array) $payload['winner'],
            array_values(array_filter((array) ($payload['floor_passers'] ?? []), 'is_array')),
        );

        $this->line((string) json_encode(
            ['schema' => 'atlas.loop.adversarial_critic.v1'] + $result,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
