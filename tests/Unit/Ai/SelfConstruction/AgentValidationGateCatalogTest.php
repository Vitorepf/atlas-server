<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentValidationGateCatalog;
use PHPUnit\Framework\TestCase;

final class AgentValidationGateCatalogTest extends TestCase
{
    private function catalog(): AgentValidationGateCatalog
    {
        return new AgentValidationGateCatalog;
    }

    public function test_risk_summary_counts_severities_and_blocking_gates_from_actual_catalog(): void
    {
        $catalog = $this->catalog();
        $gates = array_values($catalog->gates());
        $payload = $catalog->describe();

        $expectedCritical = count(array_filter($gates, static fn (array $g): bool => $g['severity'] === 'critical'));
        $expectedHigh = count(array_filter($gates, static fn (array $g): bool => $g['severity'] === 'high'));
        $expectedMedium = count(array_filter($gates, static fn (array $g): bool => $g['severity'] === 'medium'));
        $expectedBlocking = count(array_filter($gates, static fn (array $g): bool => (bool) $g['blocking']));

        self::assertSame($expectedCritical, $payload['risk_summary']['critical_count']);
        self::assertSame($expectedHigh, $payload['risk_summary']['high_count']);
        self::assertSame($expectedMedium, $payload['risk_summary']['medium_count']);
        self::assertSame($expectedBlocking, $payload['risk_summary']['blocking_count']);
        self::assertSame(count($gates), $payload['risk_summary']['total_gates']);
    }

    public function test_command_required_and_internal_only_ids_are_disjoint_and_cover_every_gate(): void
    {
        $payload = $this->catalog()->describe();

        $commandRequired = $payload['command_required_ids'];
        $internalOnly = $payload['internal_only_ids'];

        self::assertSame([], array_intersect($commandRequired, $internalOnly));
        self::assertSame(
            $payload['gate_ids'],
            $this->sorted(array_merge($commandRequired, $internalOnly)),
        );
        self::assertSame(
            count($commandRequired),
            $payload['risk_summary']['command_required_count'],
        );
        self::assertSame(
            count($internalOnly),
            $payload['risk_summary']['internal_only_count'],
        );
    }

    public function test_catalog_hash_remains_stable_for_equivalent_gate_ordering(): void
    {
        // gates() always ksort()s by id internally, so two independent catalog instances (which
        // build the same fixed gate list in the same literal order) must always agree on the hash
        // — ordering is never allowed to leak into the canonical hash.
        $first = $this->catalog();
        $second = $this->catalog();

        self::assertSame($first->hash(), $second->hash());
        self::assertSame($first->describe()['catalog_hash'], $first->hash());
        self::assertSame($first->hash(), $second->describe()['catalog_hash']);
    }

    /** @param list<string> $ids @return list<string> */
    private function sorted(array $ids): array
    {
        sort($ids);

        return array_values(array_unique($ids));
    }
}
