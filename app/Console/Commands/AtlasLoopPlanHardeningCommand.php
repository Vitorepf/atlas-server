<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopPlanHardeningReview;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopPlanHardeningReview::assess()} at the operator surface: reads a plan + its
 * reviewer findings from a JSON file and emits the hardening decision (implement | harden | replan) with the
 * blocking findings, before a single implementation token is spent.
 *
 * Read-only + pure: the aggregator decides; the live reviewers run behind a seam elsewhere. Findings are only
 * counted if SPECIFIC (non-empty summary; a named node must exist). No provider/DB/mutation.
 */
final class AtlasLoopPlanHardeningCommand extends Command
{
    protected $signature = 'atlas:loop:plan-hardening {--input=} {--json}';

    protected $description = 'Read-only plan-hardening decision (implement|harden|replan) over a plan + reviewer findings.';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            return $this->refuse('plan-hardening requires --input=<path to a readable {plan, findings} JSON>');
        }

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded)) {
            return $this->refuse('input file is not a JSON object');
        }
        $plan = is_array($decoded['plan'] ?? null) ? $decoded['plan'] : [];
        $findings = is_array($decoded['findings'] ?? null) ? array_values($decoded['findings']) : [];
        $reviewerCount = isset($decoded['reviewer_count']) && is_numeric($decoded['reviewer_count'])
            ? max(1, (int) $decoded['reviewer_count'])
            : max(1, count($findings));

        $assessment = app(AtlasLoopPlanHardeningReview::class)->assess($plan, $findings, $reviewerCount);

        $facts = ['schema' => 'atlas.loop.plan_hardening.v1', 'reviewer_count' => $reviewerCount] + $assessment;

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('decision: '.$facts['decision'].'  high_or_critical: '.$facts['high_or_critical'].'  considered: '.$facts['considered'].'  dropped: '.$facts['dropped']);
        }

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->line((string) json_encode([
            'outcome' => 'refused',
            'reason' => 'usage_error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
