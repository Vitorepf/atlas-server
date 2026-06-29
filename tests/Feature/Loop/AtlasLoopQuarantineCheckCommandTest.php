<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the quarantine authorization gate is live at the operator surface and emits deterministic facts: a
 * dead, long-unused, in-scope target with a valid operator receipt is authorized; an out-of-scope target
 * with no evidence is blocked with every reason. A missing --target is a usage error. It authorizes nothing.
 */
final class AtlasLoopQuarantineCheckCommandTest extends TestCase
{
    public function test_requires_target(): void
    {
        $exit = Artisan::call('atlas:loop:quarantine-check', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_dead_unused_in_scope_with_receipt_is_authorized(): void
    {
        $target = 'app/Services/Ai/SelfConstruction/SomeDeadOrgan.php';

        $exit = Artisan::call('atlas:loop:quarantine-check', [
            '--target' => $target,
            '--snapshot' => json_encode(['reachability' => 'dead', 'last_used_at' => '2020-01-01T00:00:00+00:00']),
            '--receipt' => json_encode(['schema_version' => 'atlas.decision_receipt.v2', 'actor' => 'operator:vitor', 'file' => $target]),
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction.quarantine_authorization.v1', $decoded['schema_version']);
        $this->assertSame('authorized', $decoded['decision']);
        $this->assertSame([], $decoded['blocking_reasons']);
    }

    public function test_out_of_scope_unevidenced_is_blocked(): void
    {
        $exit = Artisan::call('atlas:loop:quarantine-check', [
            '--target' => 'app/Models/SomeModel.php',
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('blocked', $decoded['decision']);
        $this->assertContains('path_out_of_self_construction_scope', $decoded['blocking_reasons']);
        $this->assertContains('missing_operator_decision_receipt', $decoded['blocking_reasons']);
    }
}
