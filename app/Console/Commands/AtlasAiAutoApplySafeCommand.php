<?php

namespace App\Console\Commands;

use App\Services\Ai\Autonomy\AtlasAutonomousLearningApplier;
use Illuminate\Console\Command;

/**
 * "Hermes mode" for self-learning: autonomously auto-approve + auto-apply the SAFE
 * reversible learning classes with NO per-item approval; everything else stays queued
 * for the Sunday review. Default-OFF (atlas.ai.autonomous_learning.enabled). Every
 * application is reversible and reported in the weekly digest.
 */
class AtlasAiAutoApplySafeCommand extends Command
{
    protected $signature = 'atlas:ai:auto-apply-safe
        {--limit=50 : Max proposals to process this run}
        {--json : Print machine-readable JSON}';

    protected $description = 'Autonomously apply the SAFE reversible learnings (no approval); everything else queues for Sunday. Default-OFF.';

    public function handle(AtlasAutonomousLearningApplier $applier): int
    {
        $report = $applier->run((int) $this->option('limit'));

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if (($report['enabled'] ?? false) !== true) {
            $this->warn('Autonomous auto-apply is OFF — '.((string) ($report['note'] ?? 'disabled')));
            $this->line('Enable with: ATLAS_AUTONOMOUS_AUTO_APPLY=true (then it applies ONLY safe, reversible, non-sensitive classes).');

            return self::SUCCESS;
        }

        $this->info(sprintf('Autonomous safe-apply: %d auto-applied, %d queued for your Sunday review.', (int) $report['applied'], (int) $report['queued']));

        $rows = [];
        foreach (array_slice($report['items'] ?? [], 0, 30) as $i) {
            $rows[] = [
                substr((string) $i['id'], 0, 8),
                (string) $i['kind'],
                (string) $i['action'],
                (string) ($i['reason'] ?? $i['reverse'] ?? ''),
            ];
        }
        if ($rows !== []) {
            $this->table(['id', 'kind', 'action', 'reason / reverse'], $rows);
        }
        $this->line('<info>Everything auto-applied is reversible and appears in atlas:ai:weekly-memory-digest.</info>');

        return self::SUCCESS;
    }
}
