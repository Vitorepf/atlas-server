<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopContractGapScanner;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeRegistry;
use Illuminate\Console\Command;

/**
 * BRAIN CONTRACT-GAPS — read-only Mode-B surface over {@see AtlasLoopContractGapScanner}. Lists interfaces in a
 * scope with ZERO concrete implementer anywhere in app/ — contracts the architecture DECLARED but never
 * fulfilled (architectural capability debt). The brain (the pasted decide-loop session) reads this and
 * originates "implement contract X" specs — architecture-COMPLETION leverage, not intra-graph node-wiring.
 *
 * Binary, UNAMBIGUOUS signal (a class either `implements X` or it does not), so it never fires a false gap;
 * GROUNDED (real FQCN + the method signatures the implementation must satisfy). Read-only, fail-OPEN. The
 * SAME scanner feeds the automated origination prompt (flag-gated) — single source of truth, zero duplication.
 */
final class AtlasBrainContractGapsCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:contract-gaps {--scope=autonomous : the scope slug to scan} {--json}';

    /** @var string */
    protected $description = 'List interfaces in a scope with ZERO concrete implementers (declared-but-unfulfilled contracts the brain can originate against).';

    public function handle(): int
    {
        $scopeDef = app(AtlasBrainScopeRegistry::class)->resolve((string) $this->option('scope'));
        $slug = (string) $scopeDef['slug'];
        $roots = array_values((array) ($scopeDef['roots'] ?? []));

        $scanner = new AtlasLoopContractGapScanner;
        $gaps = $scanner->capabilityGaps($scanner->phpFilesUnder($roots, base_path()), base_path());

        $payload = ['scope' => $slug, 'count' => count($gaps), 'gaps' => $gaps];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info("brain contract-gaps — scope '{$slug}': {$payload['count']} declared-but-unfulfilled contract(s) (originate the implementation):");
        foreach ($gaps as $gap) {
            $this->line('  ! '.$gap['fqcn'].'  ('.$gap['file'].')');
            foreach ((array) ($gap['methods'] ?? []) as $m) {
                $this->line('      '.$m);
            }
        }
        if ($gaps === []) {
            $this->line('  (none — every declared contract in scope has a concrete implementer)');
        }

        return self::SUCCESS;
    }
}
