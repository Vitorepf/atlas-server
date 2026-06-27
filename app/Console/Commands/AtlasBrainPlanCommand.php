<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * BRAIN PLAN — single-purpose wrapper that pulls the L107 doctor surface and prints ONLY the
 * recommended_action block. Useful for "what should I do right now?" without scanning all findings.
 *
 * Pétreo (brain surface).
 */
final class AtlasBrainPlanCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:plan {--scope= : scope slug} {--json} {--raw}';

    /** @var string */
    protected $description = 'Single-purpose: prints the recommended next action from the doctor adviser.';

    public function handle(): int
    {
        $scopeOpt = trim((string) ($this->option('scope') ?? ''));
        $scope = (string) app(AtlasBrainScopeRegistry::class)->resolve($scopeOpt)['slug'];
        $args = $scopeOpt !== '' ? ['--scope' => $scopeOpt] : [];

        $buf = new BufferedOutput;
        Artisan::call('atlas:brain:health-doctor', $args + ['--json' => true, '--raw' => true], $buf);
        $doctor = json_decode(trim($buf->fetch()), true) ?: [];
        $rec = (array) ($doctor['recommended_action'] ?? []);

        $payload = ['scope' => $scope, 'recommended' => $rec['recommended'] ?? null, 'rationale' => (string) ($rec['rationale'] ?? '')];

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (! $this->option('raw')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $this->line((string) json_encode($payload, $flags));

        return self::SUCCESS;
    }
}
