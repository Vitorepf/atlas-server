<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopRecursiveSelfImprovementGate;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopRecursiveSelfImprovementGate::evaluate()} at the operator surface: emits the
 * recursive-safety verdict for a self-edit proposal (admitted? auto-apply? parked? refused-pétreo?) given a
 * target path + intent (harden|improve).
 *
 * Read-only + deterministic: it DECIDES, it never edits, applies, or parks anything for real — a cert-organ
 * self-edit is refused no matter the intent, and any legal self-edit stays propose-only unless the operator's
 * auto-apply policy is ON.
 */
final class AtlasLoopRecursiveSelfImprovementGateCommand extends Command
{
    protected $signature = 'atlas:loop:recursive-self-improvement-gate {--target=} {--intent=improve} {--json}';

    protected $description = 'Read-only recursive self-improvement safety verdict for a self-edit proposal.';

    public function handle(): int
    {
        $target = trim((string) $this->option('target'));
        if ($target === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'recursive-self-improvement-gate requires --target=<repo-relative path>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $intent = trim((string) $this->option('intent')) ?: AtlasLoopRecursiveSelfImprovementGate::KIND_IMPROVE;
        $verdict = app(AtlasLoopRecursiveSelfImprovementGate::class)->evaluate($target, $intent);

        $facts = ['schema' => 'atlas.loop.recursive_self_improvement_gate.v1', 'target' => $target, 'intent' => $intent] + $verdict;

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('target: '.$target);
            $this->line('status: '.$facts['status']);
            $this->line('admitted: '.($facts['admitted'] ? 'yes' : 'no').'  auto_apply: '.($facts['auto_apply'] ? 'yes' : 'no'));
        }

        return self::SUCCESS;
    }
}
