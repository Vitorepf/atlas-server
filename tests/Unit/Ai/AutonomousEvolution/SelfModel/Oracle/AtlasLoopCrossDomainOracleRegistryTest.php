<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\SelfModel\Oracle;

use App\Services\Ai\AutonomousEvolution\SelfModel\Oracle\AtlasLoopCodeDomainOutcomeOracle;
use App\Services\Ai\AutonomousEvolution\SelfModel\Oracle\AtlasLoopCrossDomainOracleRegistry;
use App\Services\Ai\AutonomousEvolution\SelfModel\Oracle\AtlasLoopModelOutcomeOracle;
use PHPUnit\Framework\TestCase;

/**
 * Proves the cross-domain oracle registry: register/retrieve by domain, null (no throw) for an unknown domain,
 * sorted domain list, and last-wins replacement.
 */
final class AtlasLoopCrossDomainOracleRegistryTest extends TestCase
{
    /** A minimal extra-domain oracle (the interface is implementable for any domain). */
    private function oracleFor(string $domain): AtlasLoopModelOutcomeOracle
    {
        return new class($domain) implements AtlasLoopModelOutcomeOracle
        {
            public function __construct(private string $domain) {}

            public function domain(): string
            {
                return $this->domain;
            }

            public function scoreOutcome(array $delivery): array
            {
                return ['schema' => 'test', 'score' => 0.0, 'grounded' => false, 'basis' => 'stub'];
            }
        };
    }

    public function test_registers_and_retrieves_the_code_oracle(): void
    {
        $registry = new AtlasLoopCrossDomainOracleRegistry;
        $code = new AtlasLoopCodeDomainOutcomeOracle;
        $registry->register($code);

        $this->assertSame($code, $registry->oracleFor('code'));
    }

    public function test_unknown_domain_returns_null_without_throwing(): void
    {
        $registry = new AtlasLoopCrossDomainOracleRegistry;

        $this->assertNull($registry->oracleFor('unknown'));
    }

    public function test_domains_are_listed_sorted(): void
    {
        $registry = new AtlasLoopCrossDomainOracleRegistry;
        $registry->register($this->oracleFor('finance'));
        $registry->register(new AtlasLoopCodeDomainOutcomeOracle); // 'code'
        $registry->register($this->oracleFor('marketing'));

        $this->assertSame(['code', 'finance', 'marketing'], $registry->domains());
    }

    public function test_re_registering_a_domain_replaces_last_wins(): void
    {
        $registry = new AtlasLoopCrossDomainOracleRegistry;
        $first = new AtlasLoopCodeDomainOutcomeOracle;
        $second = new AtlasLoopCodeDomainOutcomeOracle;

        $registry->register($first);
        $registry->register($second);

        $this->assertSame($second, $registry->oracleFor('code'), 'last registration wins');
        $this->assertSame(['code'], $registry->domains(), 'still a single code domain entry');
    }
}
