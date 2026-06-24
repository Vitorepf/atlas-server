<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\AutonomousEvolution\AtlasLoopRegressionWatcher;
use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopMainHealthSentinel;
use Illuminate\Console\Command;
use Throwable;

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

    public function handle(AtlasLoopMainHealthSentinel $sentinel, AtlasLoopRegressionWatcher $watcher): int
    {
        $repo = trim((string) $this->option('repo')) ?: base_path();
        $result = $sentinel->verify($repo, (int) $this->option('window'));

        // L6 — post-merge ANTI-REGRESSION NET: a reverted regression is re-attempted as a FIX-FORWARD repair
        // task so a long run never silently loses a rung. Flag-gated inside the watcher (OFF => no-op =>
        // byte-identical) and fail-open (a hiccup here never blocks the health report).
        $result['repair'] = $this->maybeEnqueueRepair($watcher, $result);

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
        if ((int) ($result['repair']['enqueued'] ?? 0) > 0) {
            $this->components->twoColumnDetail('Fix-forward repairs enqueued', (string) $result['repair']['enqueued']);
        }

        return self::SUCCESS;
    }

    /**
     * Turn a reverted regression into a claimable fix-forward repair (via {@see AtlasLoopRegressionWatcher},
     * itself flag-gated). The reverted commit IS the attributed culprit, so its changed files are both the
     * failure's related files and the merge's touched files — the sentinel's file-overlap attribution then
     * mints exactly one repair. No reverted_sha / no running campaign => nothing to do.
     *
     * @param  array<string,mixed>  $result  the sentinel verify() output
     * @return array{attributed:int, enqueued:int, unattributed:int}
     */
    private function maybeEnqueueRepair(AtlasLoopRegressionWatcher $watcher, array $result, ?int $windowSize = null): array
    {
        $none = ['attributed' => 0, 'enqueued' => 0, 'unattributed' => 0];
        $sha = trim((string) ($result['reverted_sha'] ?? ''));
        if ($sha === '') {
            return $none;
        }
        try {
            $campaign = AtlasLoopCampaign::query()
                ->where('status', AtlasLoopCampaign::STATUS_RUNNING)
                ->orderByDesc('id')
                ->first();
            if ($campaign === null) {
                return $none;
            }
            $files = array_values(array_filter((array) ($result['changed_files'] ?? []), static fn ($f): bool => is_string($f) && $f !== ''));

            $windowResult = $watcher->enqueueRepairsForWindow(
                (string) $campaign->id,
                [['id' => 'main-health:'.substr($sha, 0, 12), 'related_files' => $files, 'detail' => (string) ($result['reason'] ?? '')]],
                $this->resolveRepairWindowSize($windowSize),
            );
            if (($windowResult['attributed'] ?? 0) > 0 || ($windowResult['enqueued'] ?? 0) > 0) {
                return $windowResult;
            }

            return $watcher->enqueueRepairs(
                (string) $campaign->id,
                [['id' => 'main-health:'.substr($sha, 0, 12), 'related_files' => $files, 'detail' => (string) ($result['reason'] ?? '')]],
                [['commit' => $sha, 'files' => $files, 'merged_at' => now()->toIso8601String()]],
            );
        } catch (Throwable) {
            return $none;
        }
    }

    private function resolveRepairWindowSize(?int $windowSize): int
    {
        if ($windowSize !== null) {
            return max(1, $windowSize);
        }

        if ($this->input !== null) {
            return max(1, (int) $this->option('window'));
        }

        return 10;
    }
}
