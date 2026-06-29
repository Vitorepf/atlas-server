<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeReplayDiffService;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasSelfConstructionRealProviderSmokeReplayDiffService::compare()} at the
 * operator surface: diffs two real-provider smoke runs and emits the regression verdict — any mutated protected
 * field or any forbidden runtime flag flipped true after replay blocks the diff.
 *
 * Read-only (MODE read_only_real_provider_smoke_replay_diff): it compares and reports; no execution, dispatch,
 * provider call, or token spend.
 */
final class AtlasLoopSmokeReplayDiffCommand extends Command
{
    protected $signature = 'atlas:loop:smoke-replay-diff {--before=} {--after=} {--json}';

    protected $description = 'Read-only diff of two real-provider smoke runs (protected-field + forbidden-flag regression check).';

    public function handle(): int
    {
        $before = $this->readJson('before');
        $after = $this->readJson('after');
        if ($before === null) {
            return $this->refuse('smoke-replay-diff requires --before=<json object or path>');
        }
        if ($after === null) {
            return $this->refuse('smoke-replay-diff requires --after=<json object or path>');
        }

        $verdict = app(AtlasSelfConstructionRealProviderSmokeReplayDiffService::class)->compare($before, $after);

        if ($this->option('json')) {
            $this->line((string) json_encode($verdict, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('status: '.$verdict['status'].'  mutations: '.$verdict['mutation_count']);
            foreach ($verdict['mutations'] as $m) {
                $this->line('  '.$m['code'].':'.($m['field'] ?? '?'));
            }
        }

        return self::SUCCESS;
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $option): ?array
    {
        $raw = trim((string) $this->option($option));
        if ($raw === '') {
            return null;
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
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
