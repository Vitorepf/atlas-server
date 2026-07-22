<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\Active;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\AtlasCortexCounterfactualProbe;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\AtlasCortexHypotheticalChangeWalker;
use PHPUnit\Framework\TestCase;

/**
 * Proves the counterfactual probe: it keeps ONLY the hard-break FACTs (excluding soft warnings), refuses an
 * unresolved target with a single UNRESOLVED_TARGET fact (never silent empty), surfaces UNKNOWN_REGION blind
 * spots, and is idempotent.
 */
final class AtlasCortexCounterfactualProbeTest extends TestCase
{
    /** @param list<array<string,mixed>> $facts */
    private function walker(array $facts): object
    {
        return new class($facts)
        {
            public function __construct(private array $facts) {}

            public function walk(string $target, array|string $edit): array
            {
                return $this->facts;
            }
        };
    }

    /** @return array<string,mixed> */
    private function fact(string $reason, string $file, int $line): array
    {
        return ['propagation_reason' => $reason, 'caller_file' => $file, 'caller_line' => $line, 'caller_symbol' => 'X'];
    }

    public function test_keeps_only_hard_breaks_and_excludes_soft_warnings(): void
    {
        $walker = $this->walker([
            $this->fact('call_site_dangling', 'app/A.php', 10),
            $this->fact('interface_contract_violated', 'app/B.php', 20),
            $this->fact('downstream_required_field_missing', 'app/C.php', 30),
            $this->fact('behavior_changed', 'app/D.php', 40),       // soft
            $this->fact('side_effect_removed', 'app/E.php', 50),    // soft
        ]);

        $facts = (new AtlasCortexCounterfactualProbe($walker))->probe('App\\Target');

        $this->assertCount(3, $facts, 'exactly the 3 hard breaks');
        $this->assertSame(['call_site_dangling', 'interface_contract_violated', 'downstream_required_field_missing'], array_column($facts, 'broken_contract'));
        $this->assertSame(['remove_call', 'replace_with_no_op', 'inline_constant'], array_column($facts, 'minimum_repair_hint'));
        $this->assertSame('app/A.php:10', $facts[0]['caller']);
    }

    public function test_unresolved_target_returns_single_fact_not_empty(): void
    {
        $walker = $this->walker([$this->fact('call_site_dangling', 'app/A.php', 10)]);
        $probe = new AtlasCortexCounterfactualProbe($walker, isResolved: static fn (string $t): bool => false);

        $facts = $probe->probe('App\\Unknown');

        $this->assertCount(1, $facts, 'never a silent empty narrowing');
        $this->assertSame(AtlasCortexCounterfactualProbe::UNRESOLVED_TARGET, $facts[0]['fact']);
    }

    public function test_surfaces_unknown_region_blind_spots(): void
    {
        $walker = $this->walker([
            $this->fact('symbol_removed', 'app/A.php', 10),               // hard (walker removal vocabulary)
            $this->fact('unindexed_region', AtlasCortexCounterfactualProbe::UNKNOWN_REGION, 0),
        ]);

        $facts = (new AtlasCortexCounterfactualProbe($walker))->probe('App\\Target');

        $kinds = array_map(static fn (array $f): string => (string) ($f['fact'] ?? $f['broken_contract'] ?? ''), $facts);
        $this->assertContains('call_site_dangling', $kinds, 'symbol_removed maps to a hard break');
        $this->assertContains(AtlasCortexCounterfactualProbe::UNKNOWN_REGION, $kinds, 'blind spot surfaced, not dropped');
    }

    public function test_is_idempotent(): void
    {
        $walker = $this->walker([
            $this->fact('call_site_dangling', 'app/A.php', 10),
            $this->fact('behavior_changed', 'app/D.php', 40),
        ]);
        $probe = new AtlasCortexCounterfactualProbe($walker);

        $this->assertSame(json_encode($probe->probe('App\\Target')), json_encode($probe->probe('App\\Target')));
    }

    public function test_default_edit_with_real_walker_returns_dangling_callers_not_empty(): void
    {
        $index = [
            'edges' => [
                'app/Target.php' => [
                    ['caller_file' => 'app/CallerA.php', 'caller_line' => 5, 'caller_symbol' => 'CallerA'],
                    ['caller_file' => 'app/CallerB.php', 'caller_line' => 12, 'caller_symbol' => 'CallerB'],
                ],
            ],
            'caller_locations' => [],
            'unindexed_edges' => [],
        ];

        $walker = new AtlasCortexHypotheticalChangeWalker($index);
        $probe = new AtlasCortexCounterfactualProbe($walker);

        // Default edit (kind=remove) must produce hard breaks, not []
        $facts = $probe->probe('app/Target.php');

        $this->assertNotEmpty($facts, 'default removal probe must return dangling callers, not silent empty');
        $contracts = array_column($facts, 'broken_contract');
        $this->assertContains('call_site_dangling', $contracts);
    }
}
