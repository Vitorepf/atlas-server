<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\AtlasLoopTrinityContractAuditor;
use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\AtlasLoopTrinityContractDriftDetector;
use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\AtlasLoopTrinityContractEmitter;
use PHPUnit\Framework\TestCase;

/**
 * Proves the Trinity contract drift detector: a window of commits where every one passes the auditor
 * yields ZERO BreachFact records; a window where a single Maestro commit changes a consume method without
 * re-emitting the contract yields exactly one fact with primitive=maestro, side=consume, counterpart=loop.
 */
final class AtlasLoopTrinityContractDriftDetectorTest extends TestCase
{
    private function frozenContract(): array
    {
        return (new AtlasLoopTrinityContractEmitter)->emit([
            'loop' => [
                'emits' => [['primitive' => 'loop', 'symbol' => 'origination_proposed']],
                'consumes' => [
                    ['primitive' => 'cortex', 'symbol' => 'cortex_role_assigned'],
                    ['primitive' => 'maestro', 'symbol' => 'maestro_packet_resolved'],
                ],
            ],
            'cortex' => [
                'emits' => [['primitive' => 'cortex', 'symbol' => 'cortex_role_assigned']],
                'consumes' => [
                    ['primitive' => 'loop', 'symbol' => 'origination_proposed'],
                    ['primitive' => 'maestro', 'symbol' => 'maestro_packet_resolved'],
                ],
            ],
            'maestro' => [
                'emits' => [['primitive' => 'maestro', 'symbol' => 'maestro_packet_resolved']],
                'consumes' => [
                    ['primitive' => 'loop', 'symbol' => 'origination_proposed'],
                    ['primitive' => 'cortex', 'symbol' => 'cortex_role_assigned'],
                ],
            ],
        ]);
    }

    private function honestProvider(array $frozen): callable
    {
        return static function (string $primitive, string $side) use ($frozen): string {
            $descriptor = $frozen['primitives'][$primitive];
            $entries = $side === AtlasLoopTrinityContractAuditor::SIDE_EMIT
                ? (array) ($descriptor['emits'] ?? [])
                : (array) ($descriptor['consumes'] ?? []);
            $canonical = self::canonicalize(['primitive' => $primitive, 'side' => $side, 'entries' => $entries]);

            return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        };
    }

    public function test_zero_breach_facts_when_every_commit_keeps_contract_intact(): void
    {
        $frozen = $this->frozenContract();
        $commits = [
            ['sha' => 'c1', 'author' => 'alice', 'committed_at_unix' => 1700000000],
            ['sha' => 'c2', 'author' => 'bob', 'committed_at_unix' => 1700000060],
            ['sha' => 'c3', 'author' => 'carol', 'committed_at_unix' => 1700000120],
        ];
        $honest = $this->honestProvider($frozen);
        $detector = new AtlasLoopTrinityContractDriftDetector(
            commitsSource: static fn (int $w): array => $commits,
            providerForCommit: static fn (string $sha): callable => $honest,
        );

        $this->assertSame([], $detector->detect($frozen));
    }

    public function test_single_maestro_commit_breach_names_primitive_side_counterpart_and_sha(): void
    {
        $frozen = $this->frozenContract();
        $commits = [
            ['sha' => 'c1', 'author' => 'alice', 'committed_at_unix' => 1700000000],
            ['sha' => 'breach-sha', 'author' => 'mallory', 'committed_at_unix' => 1700000060],
            ['sha' => 'c3', 'author' => 'carol', 'committed_at_unix' => 1700000120],
        ];
        $honest = $this->honestProvider($frozen);
        $providerFactory = static function (string $sha) use ($honest): callable {
            if ($sha === 'breach-sha') {
                return static function (string $primitive, string $side) use ($honest): string {
                    if ($primitive === 'maestro' && $side === AtlasLoopTrinityContractAuditor::SIDE_CONSUME) {
                        return str_repeat('b', 64); // divergent
                    }

                    return $honest($primitive, $side);
                };
            }

            return $honest;
        };
        $detector = new AtlasLoopTrinityContractDriftDetector(
            commitsSource: static fn (int $w): array => $commits,
            providerForCommit: $providerFactory,
        );

        $facts = $detector->detect($frozen);
        $this->assertCount(1, $facts);
        $this->assertSame('breach-sha', $facts[0]->commitSha);
        $this->assertSame('maestro', $facts[0]->primitive);
        $this->assertSame(AtlasLoopTrinityContractAuditor::SIDE_CONSUME, $facts[0]->side);
        $this->assertSame('loop', $facts[0]->counterpart);
    }

    public function test_facts_sort_descending_by_commit_time(): void
    {
        $frozen = $this->frozenContract();
        $commits = [
            ['sha' => 'oldest', 'author' => 'a', 'committed_at_unix' => 1000],
            ['sha' => 'middle', 'author' => 'b', 'committed_at_unix' => 2000],
            ['sha' => 'newest', 'author' => 'c', 'committed_at_unix' => 3000],
        ];
        $honest = $this->honestProvider($frozen);
        $providerFactory = static function (string $sha) use ($honest): callable {
            // Every commit breaches Loop's emit so we get 3 facts to verify sort.
            return static function (string $primitive, string $side) use ($honest): string {
                if ($primitive === 'loop' && $side === AtlasLoopTrinityContractAuditor::SIDE_EMIT) {
                    return str_repeat('e', 64);
                }

                return $honest($primitive, $side);
            };
        };
        $detector = new AtlasLoopTrinityContractDriftDetector(
            commitsSource: static fn (int $w): array => $commits,
            providerForCommit: $providerFactory,
        );

        $facts = $detector->detect($frozen);
        $this->assertSame(['newest', 'middle', 'oldest'], array_map(static fn ($f) => $f->commitSha, $facts));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn ($v) => self::canonicalize($v), $value);
        }
        ksort($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonicalize($v);
        }

        return $out;
    }
}
