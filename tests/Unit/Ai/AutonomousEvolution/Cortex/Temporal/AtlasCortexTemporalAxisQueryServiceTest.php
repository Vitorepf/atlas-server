<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\Temporal;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Temporal\AtlasCortexTemporalAxisQueryService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class AtlasCortexTemporalAxisQueryServiceTest extends TestCase
{
    public function test_symbols_touched_within_returns_exact_recent_set(): void
    {
        $service = new AtlasCortexTemporalAxisQueryService(
            new class
            {
                public function report(array $fqcns): array
                {
                    return [
                        'App\\A' => ['fqcn' => 'App\\A', 'resolved' => true, 'last_modified_at' => '2026-06-23T00:00:00Z', 'first_seen_at' => '2026-06-01T00:00:00Z'],
                        'App\\B' => ['fqcn' => 'App\\B', 'resolved' => true, 'last_modified_at' => '2026-06-18T00:00:00Z', 'first_seen_at' => '2026-06-02T00:00:00Z'],
                        'App\\C' => ['fqcn' => 'App\\C', 'resolved' => true, 'last_modified_at' => '2026-06-17T23:59:59Z', 'first_seen_at' => '2026-06-03T00:00:00Z'],
                        'App\\D' => ['fqcn' => 'App\\D', 'resolved' => true, 'last_modified_at' => '2026-06-24T00:00:00Z', 'first_seen_at' => '2026-06-04T00:00:00Z'],
                        'App\\E' => ['fqcn' => 'App\\E', 'resolved' => true, 'last_modified_at' => '2026-05-01T00:00:00Z', 'first_seen_at' => '2026-05-01T00:00:00Z'],
                    ];
                }
            },
            new class
            {
                public function report(array $fqcns): array
                {
                    return [];
                }
            },
            fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-06-24T00:00:00Z')
        );

        $rows = $service->symbolsTouchedWithin(new \DateInterval('P7D'), ['App\\A', 'App\\B', 'App\\C', 'App\\D', 'App\\E']);

        $this->assertSame(['App\\A', 'App\\B', 'App\\C', 'App\\D'], array_column($rows, 'fqcn'));
        $this->assertTrue($rows[0]['touched_within']);
    }

    public function test_orphans_older_than_returns_only_resolved_older_orphans_sorted_by_fqcn(): void
    {
        $service = new AtlasCortexTemporalAxisQueryService(
            new class
            {
                public function report(array $fqcns): array
                {
                    return [];
                }
            },
            new class
            {
                public function report(array $fqcns): array
                {
                    return [
                        'App\\Zed' => ['fqcn' => 'App\\Zed', 'resolved' => true, 'unwired_days' => 31],
                        'App\\Alpha' => ['fqcn' => 'App\\Alpha', 'resolved' => true, 'unwired_days' => 45],
                        'App\\Beta' => ['fqcn' => 'App\\Beta', 'resolved' => false, 'reason' => 'missing'],
                        'App\\Gamma' => ['fqcn' => 'App\\Gamma', 'resolved' => true, 'unwired_days' => 30],
                    ];
                }
            }
        );

        $rows = $service->orphansOlderThan(30, ['App\\Zed', 'App\\Alpha', 'App\\Beta', 'App\\Gamma']);

        $this->assertSame(['App\\Alpha', 'App\\Zed'], array_column($rows, 'fqcn'));
        $this->assertTrue($rows[0]['orphan_older_than_days']);
        $this->assertTrue($rows[1]['orphan_older_than_days']);
    }

    public function test_empty_universe_returns_empty_rows(): void
    {
        $service = new AtlasCortexTemporalAxisQueryService(
            new class
            {
                public function report(array $fqcns): array
                {
                    return [];
                }
            },
            new class
            {
                public function report(array $fqcns): array
                {
                    return [];
                }
            }
        );

        $this->assertSame([], $service->symbolsTouchedWithin(new \DateInterval('P7D'), []));
        $this->assertSame([], $service->symbolsUntouchedSince(CarbonImmutable::parse('2026-06-01T00:00:00Z'), []));
        $this->assertSame([], $service->orphansOlderThan(10, []));
        $this->assertSame([], $service->symbolsAddedBetween(CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-06-24T00:00:00Z'), []));
    }

    public function test_source_exposes_no_shell_or_forbidden_tokens(): void
    {
        $source = file_get_contents(dirname(__DIR__, 6).'/app/Services/Ai/AutonomousEvolution/Discovery/Cortex/Temporal/AtlasCortexTemporalAxisQueryService.php');

        $this->assertIsString($source);
        $this->assertStringNotContainsString('exec', $source);
        $this->assertStringNotContainsString('shell_exec', $source);
        $this->assertStringNotContainsString('proc_open', $source);
        $this->assertStringNotContainsString('Process::', $source);
        $this->assertStringNotContainsString('score', $source);
        $this->assertStringNotContainsString('rank', $source);
        $this->assertStringNotContainsString('hot', $source);
        $this->assertStringNotContainsString('cold', $source);
    }
}
