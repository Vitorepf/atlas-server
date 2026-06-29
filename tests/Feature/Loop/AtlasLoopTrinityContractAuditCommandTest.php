<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\AtlasLoopTrinityContractAuditor;
use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\AtlasLoopTrinityContractEmitter;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the operator-facing atlas:loop:trinity-contract-audit CLI ITERATES the frozen Trinity contracts from
 * the registry and runs the auditor on each: a clean tree (provider matches the frozen contract) yields a
 * CLEAN verdict per contract; a mutated side yields a BREACH verdict naming the primitive/side/counterpart;
 * with no live-source provider wired each contract is reported `skipped` (never a fabricated CLEAN).
 */
final class AtlasLoopTrinityContractAuditCommandTest extends TestCase
{
    public function test_artisan_command_is_registered(): void
    {
        $this->assertArrayHasKey('atlas:loop:trinity-contract-audit', Artisan::all());
    }

    public function test_clean_audit_emits_clean_verdict_per_contract(): void
    {
        $frozen = $this->makeFrozen();
        $this->bindRegistry([$frozen], $this->honestProvider($frozen));

        $exit = Artisan::call('atlas:loop:trinity-contract-audit', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.trinity_contract_audit.v1', $decoded['schema']);
        $this->assertTrue($decoded['provider_wired']);
        $this->assertSame(1, $decoded['audited']);
        $this->assertSame('CLEAN', $decoded['verdicts'][0]['verdict']);
        $this->assertNull($decoded['verdicts'][0]['breach']);
    }

    public function test_breach_audit_emits_breach_verdict_naming_the_side(): void
    {
        $frozen = $this->makeFrozen();
        $base = $this->honestProvider($frozen);
        $bad = static function (string $primitive, string $side) use ($base): string {
            if ($primitive === 'loop' && $side === AtlasLoopTrinityContractAuditor::SIDE_EMIT) {
                return str_repeat('f', 64);
            }

            return $base($primitive, $side);
        };
        $this->bindRegistry([$frozen], $bad);

        $exit = Artisan::call('atlas:loop:trinity-contract-audit', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $verdict = $decoded['verdicts'][0];
        $this->assertSame('BREACH', $verdict['verdict']);
        $this->assertSame('loop', $verdict['breach']['primitive']);
        $this->assertSame('emit', $verdict['breach']['side']);
        $this->assertSame('cortex', $verdict['breach']['counterpart']);
    }

    public function test_without_provider_each_contract_is_skipped(): void
    {
        // No provider bound ⇒ the command still iterates the default self-identity registry but refuses to
        // fabricate a CLEAN verdict — every contract is reported skipped.
        $exit = Artisan::call('atlas:loop:trinity-contract-audit', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertFalse($decoded['provider_wired']);
        $this->assertGreaterThanOrEqual(1, $decoded['audited']);
        foreach ($decoded['verdicts'] as $verdict) {
            $this->assertSame('skipped', $verdict['verdict']);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function makeFrozen(): array
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

    /**
     * @param  array<string,mixed>  $frozen
     */
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

    /**
     * @param  list<array<string,mixed>>  $contracts
     */
    private function bindRegistry(array $contracts, callable $provider): void
    {
        $this->app->instance('atlas.loop.trinity.frozen_contracts', $contracts);
        $this->app->instance('atlas.loop.trinity.current_fingerprint_provider', $provider);
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
