<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionLoopService;
use Illuminate\Console\Command;

/**
 * Self-construction loop — "Atlas builds Atlas". Detects improvement signals in the
 * Atlas codebase (or takes operator-supplied --request gaps) and runs each through
 * the Mission e2e pipe, producing BRANCHES for the operator to review + merge.
 * NEVER merges, NEVER touches main. Bounded by --max.
 *
 * NOTE: each delivery makes a real provider call (operator spend) — run with intent.
 */
class AtlasSelfConstructCommand extends Command
{
    protected $signature = 'atlas:self-construct
        {--max=1 : maximum improvement signals to act on this run}
        {--request=* : explicit operator improvement request(s), merged ahead of code markers}
        {--repo= : repo dir to scan + materialize into (default: app base path)}
        {--provider=codex_cli : provider for the generation step}
        {--json : machine-readable output}';

    protected $description = 'Self-construction: Atlas detects + delivers its own improvements as branches (you merge).';

    public function handle(AtlasSelfConstructionLoopService $loop): int
    {
        $result = $loop->run([
            'max' => (int) $this->option('max'),
            'requests' => (array) $this->option('request'),
            'repo_dir' => $this->stringOption('repo') ?: base_path(),
            'delivery' => array_filter(['provider' => $this->stringOption('provider')], static fn ($v): bool => $v !== null && $v !== ''),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Self-construction — Atlas builds Atlas (branches only; you merge).');
        $this->line('  detected signals: '.($result['detected'] ?? 0).'  |  branches delivered: '.($result['delivered_count'] ?? 0));
        foreach ((array) ($result['deliveries'] ?? []) as $d) {
            $sig = $d['signal']['signal'] ?? '?';
            if ($d['delivered'] ?? false) {
                $this->line('  ✓ '.($d['branch'] ?? '?').'  ←  '.$sig);
            } else {
                $this->line('  ✗ ('.($d['stage'] ?? '?').'/'.($d['reason'] ?? '?').')  ←  '.$sig);
            }
        }
        $this->line('');
        $this->line('  '.($result['operator_action'] ?? 'review the branches'));

        return self::SUCCESS;
    }

    private function stringOption(string $key): ?string
    {
        $v = $this->option($key);

        return is_string($v) ? $v : null;
    }
}
