<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Thin daily-facing router for `atlas:aaeos` (TRI-HYGIENE W3).
 * Real observe floors live at `atlas:aeos:observe`.
 * Daily control plane: run|cycle|scorecard|certify.
 */
final class AtlasAaeosRouterCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aaeos
        {action? : Optional AEOS action — forwarded to atlas:aeos:observe}
        {--json : Machine-readable help when no action}';

    protected $description = 'AAEOS router: daily use atlas:aaeos:run|certify; advanced observe → atlas:aeos:observe';

    public function handle(): int
    {
        $action = $this->argument('action');
        if ($action === null || $action === '') {
            $help = [
                'schema' => 'atlas.aaeos.router.v1',
                'daily' => [
                    'atlas:aaeos:run',
                    'atlas:aaeos:cycle',
                    'atlas:aaeos:scorecard',
                    'atlas:aaeos:certify',
                    'atlas:cli:cockpit',
                ],
                'advanced_observe' => 'atlas:aeos:observe {action}',
                'maturity' => 'atlas:aeos:* (renamed from atlas:aaeos:maturity etc.)',
                'map' => 'docs/engineering-knowledge-base/atlas-cli-daily-map.md',
            ];
            if ((bool) $this->option('json')) {
                $this->line($this->encode($help));
            } else {
                $this->components->info('AAEOS router (thin)');
                $this->line('Daily:  atlas:aaeos:run | cycle | scorecard | certify | atlas:cli:cockpit');
                $this->line('Observe floors (advanced): atlas:aeos:observe {action}');
                $this->line('Maturity: atlas:aeos:maturity | department-status | …');
                $this->line('Map: docs/engineering-knowledge-base/atlas-cli-daily-map.md');
            }

            return self::SUCCESS;
        }

        $this->components->warn('Forwarding to atlas:aeos:observe (god observe CLI). Prefer atlas:aaeos:run for daily work.');
        // Re-dispatch full argv-like: pass only action; operator should use aeos:observe for options
        return (int) Artisan::call('atlas:aeos:observe', [
            'action' => $action,
            '--json' => (bool) $this->option('json'),
        ], $this->output);
    }
}
