<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\WorkerSwarm\AtlasSelfConstructionWorkerScopedExecutionEnvelope;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the scoped execution envelope composer is live at the operator surface: a complete input composes a
 * VALID envelope scoped to its allowed_files; an input missing a lease / allowed_files / with an
 * allowed-forbidden overlap is INVALID with named blockers.
 */
final class AtlasLoopExecutionEnvelopeCommandTest extends TestCase
{
    private function compose(array $input): array
    {
        $exit = Artisan::call('atlas:loop:execution-envelope', [
            '--input' => (string) json_encode($input),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_complete_input_composes_valid_scoped_envelope(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->compose([
            'task_id' => 't1',
            'lease_id' => 'l1',
            'allowed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
            'gates' => ['phpunit'],
            'evidence_requirements' => ['tests_or_gates_result'],
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasSelfConstructionWorkerScopedExecutionEnvelope::SCHEMA, $d['schema_version']);
        $this->assertTrue($d['valid'], (string) json_encode($d));
        $this->assertSame([], $d['blockers']);
        $this->assertContains('app/Foo.php', $d['allowed_files']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $d['envelope_hash']);
    }

    public function test_incomplete_input_is_invalid_with_blockers(): void
    {
        ['d' => $d] = $this->compose([
            'task_id' => 't1',
            // lease_id missing, allowed_files empty, gates missing
            'allowed_files' => [],
        ]);

        $this->assertFalse($d['valid']);
        $this->assertContains('lease_id_missing', $d['blockers']);
        $this->assertContains('allowed_files_empty', $d['blockers']);
        $this->assertContains('gates_missing', $d['blockers']);
    }

    public function test_allowed_forbidden_overlap_blocks(): void
    {
        ['d' => $d] = $this->compose([
            'lease_id' => 'l1',
            'allowed_files' => ['app/Foo.php'],
            'forbidden_files' => ['app/Foo.php'], // overlap
            'gates' => ['phpunit'],
        ]);

        $this->assertFalse($d['valid']);
        $this->assertContains('allowed_forbidden_overlap:app/Foo.php', $d['blockers']);
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:execution-envelope', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
