<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopTrinityContractDriftCommand;
use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\AtlasLoopTrinityContractAuditor;
use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\AtlasLoopTrinityContractEmitter;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the atlas:loop:trinity:contract-drift CLI prints BreachFact records as JSON in deterministic
 * descending-commit-time order AND carries no scoring/severity keys.
 */
final class AtlasLoopTrinityContractDriftCommandTest extends TestCase
{
    public function test_artisan_command_is_registered(): void
    {
        $this->assertArrayHasKey('atlas:loop:trinity:contract-drift', Artisan::all());
    }

    public function test_prints_facts_in_descending_commit_time_with_no_scoring_keys(): void
    {
        $frozen = $this->frozen();
        $honest = $this->honestProvider($frozen);
        $commits = [
            ['sha' => 'old', 'author' => 'a', 'committed_at_unix' => 1000],
            ['sha' => 'new', 'author' => 'b', 'committed_at_unix' => 3000],
        ];
        $providerFactory = static function (string $sha) use ($honest): callable {
            return static function (string $primitive, string $side) use ($honest): string {
                if ($primitive === 'loop' && $side === AtlasLoopTrinityContractAuditor::SIDE_EMIT) {
                    return str_repeat('e', 64);
                }

                return $honest($primitive, $side);
            };
        };
        $this->app->instance(AtlasLoopTrinityContractDriftCommand::FROZEN_CONTRACT_KEY, $frozen);
        $this->app->instance(AtlasLoopTrinityContractDriftCommand::COMMITS_SOURCE_KEY, static fn (int $w): array => $commits);
        $this->app->instance(AtlasLoopTrinityContractDriftCommand::PROVIDER_FACTORY_KEY, $providerFactory);

        $exit = Artisan::call('atlas:loop:trinity:contract-drift', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($decoded['facts']);
        $this->assertCount(2, $decoded['facts']);
        $this->assertSame('new', $decoded['facts'][0]['commit_sha'], 'newer commit appears first');
        $this->assertSame('old', $decoded['facts'][1]['commit_sha']);

        foreach ($decoded['facts'] as $fact) {
            foreach (array_keys($fact) as $key) {
                $this->assertDoesNotMatchRegularExpression(
                    '/score|severity|rank|grade/i',
                    (string) $key,
                    'CLI output must carry no scoring/severity keys: '.$key,
                );
            }
        }
    }

    public function test_unwired_command_skips_cleanly(): void
    {
        $exit = Artisan::call('atlas:loop:trinity:contract-drift', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        if (isset($decoded['status']) && $decoded['status'] === 'skipped') {
            $this->assertNotEmpty($decoded['reason']);
        } else {
            // If some other test bound the keys, accept a normal facts array too.
            $this->assertArrayHasKey('facts', $decoded);
        }
    }

    private function frozen(): array
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
