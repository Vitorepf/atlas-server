<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopPlanReadinessGate;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopPlanReadinessGate::assess()} at the operator surface: reads a plan and its
 * allowed_files (and optional goal) from a JSON file and emits the plan-readiness verdict — IMPLEMENT only on
 * an impeccable plan, otherwise REPLAN with the gaps — as deterministic facts. Read-only and pure.
 *
 * Input JSON: {plan:{plan_id, nodes:[...]}, allowed_files:[...], goal?:string}.
 */
final class AtlasLoopPlanReadinessCommand extends Command
{
    protected $signature = 'atlas:loop:plan-readiness {--input=} {--json}';

    protected $description = 'Read-only plan-readiness verdict (IMPLEMENT vs REPLAN) for an obra plan.';

    public function handle(AtlasLoopPlanReadinessGate $gate): int
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
        if (! is_array($payload) || ! is_array($payload['plan'] ?? null)) {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'plan_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $goal = isset($payload['goal']) && trim((string) $payload['goal']) !== '' ? (string) $payload['goal'] : null;
        $verdict = $gate->assess(
            (array) $payload['plan'],
            array_values((array) ($payload['allowed_files'] ?? [])),
            $goal,
        );

        $this->line((string) json_encode(
            ['schema' => 'atlas.loop.plan_readiness.v1'] + $verdict,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
