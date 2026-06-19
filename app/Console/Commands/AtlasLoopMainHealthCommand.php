<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopMainHealthSentinel;
use Illuminate\Console\Command;

/**
 * LOOP-OS · Fase 1 · Slice 1.5 — the watchdog's EXTERNAL trigger for the post-merge health net. Runs after
 * each automerge drain: re-checks the window's freshly-landed loop commits against post-merge main and
 * `git revert`s a green-in-isolation / RED-in-combination commit (never reset). Deterministic, no provider.
 */
class AtlasLoopMainHealthCommand extends Command
{
    protected $signature = 'atlas:loop:main-health
        {--repo= : Repo root (default: a raiz da app)}
        {--window=10 : Quantos commits recentes inspecionar por loop-commits}
        {--json : Saída JSON canônica}';

    protected $description = 'Sentinela pós-merge: reverte um commit do loop que ficou RED na main (verde isolado, vermelho em combinação).';

    public function handle(AtlasLoopMainHealthSentinel $sentinel): int
    {
        $repo = trim((string) $this->option('repo')) ?: base_path();
        $result = $sentinel->verify($repo, (int) $this->option('window'));

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Main health', (string) $result['status']);
        if (($result['reverted_sha'] ?? null) !== null) {
            $this->components->twoColumnDetail('Reverted', substr((string) $result['reverted_sha'], 0, 12).' — '.(string) $result['reason']);
        } elseif (($result['reason'] ?? null) !== null) {
            $this->components->twoColumnDetail('Reason', (string) $result['reason']);
        }

        return self::SUCCESS;
    }
}
