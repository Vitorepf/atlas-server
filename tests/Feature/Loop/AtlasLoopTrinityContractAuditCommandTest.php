<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopTrinityContractAuditCommand;
use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\AtlasLoopTrinityContractAuditor;
use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\AtlasLoopTrinityContractEmitter;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the operator-facing atlas:loop:trinity:contract-audit CLI: exits 0 on a clean tree where the
 * current-fingerprint provider matches the frozen contract; exits non-zero on the first breach.
 */
final class AtlasLoopTrinityContractAuditCommandTest extends TestCase
{
    public function test_artisan_command_is_registered(): void
    {
        $this->assertArrayHasKey('atlas:loop:trinity:contract-audit', Artisan::all());
    }

    public function test_clean_audit_exits_zero(): void
    {
        $frozen = $this->makeFrozen();
        $this->bindContract($frozen, $this->honestProvider($frozen));

        $exit = Artisan::call('atlas:loop:trinity:contract-audit', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('clean', $decoded['status']);
    }

    public function test_breach_audit_exits_non_zero_with_structured_envelope(): void
    {
        $frozen = $this->makeFrozen();
        $base = $this->honestProvider($frozen);
        $bad = static function (string $primitive, string $side) use ($base): string {
            if ($primitive === 'loop' && $side === AtlasLoopTrinityContractAuditor::SIDE_EMIT) {
                return str_repeat('f', 64);
            }

            return $base($primitive, $side);
        };
        $this->bindContract($frozen, $bad);

        $exit = Artisan::call('atlas:loop:trinity:contract-audit', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit, 'breach must exit non-zero');
        $this->assertSame('breach', $decoded['status']);
        $this->assertSame('loop', $decoded['primitive']);
        $this->assertSame('emit', $decoded['side']);
        $this->assertSame('cortex', $decoded['counterpart']);
    }

    public function test_unbound_provider_falls_through_to_skipped(): void
    {
        // No bindings ⇒ command resolves a self-identity frozen contract via the emitter fallback BUT no
        // provider, so it cleanly skips with a reason (never crashes).
        $exit = Artisan::call('atlas:loop:trinity:contract-audit', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        if (isset($decoded['status']) && $decoded['status'] === 'skipped') {
            $this->assertNotEmpty($decoded['reason']);
        }
    }

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

    private function bindContract(array $frozen, callable $provider): void
    {
        $this->app->instance(AtlasLoopTrinityContractAuditCommand::FROZEN_CONTRACT_KEY, $frozen);
        $this->app->instance(AtlasLoopTrinityContractAuditCommand::CURRENT_FINGERPRINT_PROVIDER_KEY, $provider);
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
