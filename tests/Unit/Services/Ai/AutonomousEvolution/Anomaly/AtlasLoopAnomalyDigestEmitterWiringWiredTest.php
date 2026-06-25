<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\AutonomousEvolution\Anomaly;

use App\Services\Ai\AutonomousEvolution\Anomaly\AtlasLoopAnomalyDigestEmitter;
use App\Services\Ai\AutonomousEvolution\Anomaly\AtlasLoopAnomalyReceiptLedger;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Tests\TestCase;

/**
 * Wires AtlasLoopAnomalyDigestEmitter into the live `atlas:loop:anomaly digest` flow. Proves the
 * orphan capability is now reached by a real production call path (the CLI). The test seeds a
 * receipt via the ledger, runs the new digest action through Artisan::call, and asserts the
 * emitter's canonical envelope reaches the output.
 */
final class AtlasLoopAnomalyDigestEmitterWiringWiredTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Single shared in-memory ledger across the seed and the CLI resolve.
        $this->app->singleton(AtlasLoopAnomalyReceiptLedger::class);
    }

    public function test_command_class_imports_the_digest_emitter_symbol(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(\App\Console\Commands\AtlasLoopAnomalyCommand::class))->getFileName(),
        );
        $this->assertStringContainsString(
            AtlasLoopAnomalyDigestEmitter::class,
            $source,
            'AtlasLoopAnomalyCommand must reference the emitter so it is no longer an orphan',
        );
    }

    public function test_digest_action_emits_digest_envelope_for_seeded_signal(): void
    {
        $ledger = $this->app->make(AtlasLoopAnomalyReceiptLedger::class);
        $ledger->append([
            'signal' => 'give_back',
            'baseline_rate' => 0.10,
            'current_rate' => 0.35,
            'delta_in_sigmas' => 4.2,
            'window_start' => '2026-06-20T00:00:00Z',
            'window_end' => '2026-06-21T00:00:00Z',
            'observed_at' => '2026-06-21T00:00:00Z',
        ]);

        $exit = Artisan::call('atlas:loop:anomaly', [
            'action' => 'digest',
            '--signal' => ['give_back'],
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $raw = trim(Artisan::output());
        $payload = json_decode($raw, true);
        $this->assertIsArray($payload);

        // Envelope shape produced by AtlasLoopAnomalyDigestEmitter::emit().
        $this->assertSame(AtlasLoopAnomalyDigestEmitter::SECTION, $payload['section']);
        $this->assertSame(AtlasLoopAnomalyDigestEmitter::SCHEMA, $payload['schema_version']);
        $this->assertCount(1, $payload['entries']);
        $this->assertSame('give_back', $payload['entries'][0]['signal']);
        $this->assertSame(0.10, $payload['entries'][0]['baseline_rate']);
        $this->assertSame(0.35, $payload['entries'][0]['current_rate']);
    }

    public function test_digest_action_refuses_without_signal(): void
    {
        $exit = Artisan::call('atlas:loop:anomaly', ['action' => 'digest', '--json' => true]);
        $this->assertNotSame(0, $exit);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertSame('signal_required', $payload['error']);
    }
}
