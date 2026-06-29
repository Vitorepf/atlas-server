<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\V3\AtlasLoopV3CapabilityFingerprint;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the V3 capability fingerprint is live at the operator surface: the same fqcn + atoms + patterns yields
 * a stable hash; a changed atom set yields a different hash; an unknown class is refused.
 */
final class AtlasLoopCapabilityFingerprintCommandTest extends TestCase
{
    private const FQCN = AtlasLoopV3CapabilityFingerprint::class;

    private function fingerprint(array $atoms, array $patterns): array
    {
        $exit = Artisan::call('atlas:loop:capability-fingerprint', [
            '--fqcn' => self::FQCN,
            '--atoms' => (string) json_encode($atoms),
            '--patterns' => (string) json_encode($patterns),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_same_inputs_stable_hash_changed_atoms_differ(): void
    {
        ['exit' => $exit, 'd' => $a] = $this->fingerprint(['atom_a'], ['app/Foo']);
        $b = $this->fingerprint(['atom_a'], ['app/Foo'])['d']; // identical inputs
        $c = $this->fingerprint(['atom_b'], ['app/Foo'])['d']; // changed atoms

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopV3CapabilityFingerprint::SCHEMA, $a['schema']);
        $this->assertSame(self::FQCN, $a['fqcn']);
        $this->assertNotEmpty($a['public_methods']);
        $this->assertSame($a['hash'], $b['hash']);       // stable
        $this->assertNotSame($a['hash'], $c['hash']);    // sensitive to atom set
    }

    public function test_unknown_class_is_refused(): void
    {
        $exit = Artisan::call('atlas:loop:capability-fingerprint', ['--fqcn' => 'App\\Totally\\Missing\\Class', '--json' => true]);
        $d = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('fingerprint_refused', $d['reason']);
        $this->assertStringContainsString('unknown_class', $d['message']);
    }

    public function test_missing_fqcn_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:capability-fingerprint', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
