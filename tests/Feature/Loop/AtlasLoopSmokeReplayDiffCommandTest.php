<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeReplayDiffService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the real-provider smoke replay-diff service is live at the operator surface: a mutated protected field
 * between two runs blocks the diff; identical runs pass; a forbidden runtime flag flipped true after replay is
 * caught.
 */
final class AtlasLoopSmokeReplayDiffCommandTest extends TestCase
{
    private function compare(array $before, array $after): array
    {
        $exit = Artisan::call('atlas:loop:smoke-replay-diff', [
            '--before' => (string) json_encode($before),
            '--after' => (string) json_encode($after),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    /** A run whose protected fields all carry well-formed hashes (the post-freeze
     * hardening requires them present + sha256-shaped). */
    private function validRun(array $over = []): array
    {
        $h = static fn (string $seed): string => hash('sha256', $seed);

        return array_merge([
            'status' => 'passed',
            'provider_run_id' => 'r1',
            'smoke_hash' => $h('smoke'),
            'operator_approval_receipt_hash' => $h('approval'),
            'evidence_ledger_hash' => $h('ledger'),
            'work_product_manifest_hash' => $h('manifest'),
            'cost_event_hash' => $h('cost'),
            'continuation_summary_hash' => $h('continuation'),
            'provider_response_hash' => $h('response'),
        ], $over);
    }

    public function test_mutated_protected_field_is_flagged(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->compare(
            $this->validRun(),
            $this->validRun(['smoke_hash' => hash('sha256', 'mutated')]), // smoke_hash mutated
        );

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasSelfConstructionRealProviderSmokeReplayDiffService::SCHEMA_VERSION, $d['schema_version']);
        $this->assertSame('blocked', $d['status'], (string) json_encode($d));
        $this->assertFalse($d['replay_diff_green']);
        $this->assertContains('smoke_hash', array_column($d['mutations'], 'field'));
    }

    public function test_identical_runs_pass(): void
    {
        $run = $this->validRun();

        ['exit' => $exit, 'd' => $d] = $this->compare($run, $run);

        $this->assertSame(0, $exit);
        $this->assertSame('passed', $d['status']);
        $this->assertSame(0, $d['mutation_count']);
        $this->assertTrue($d['replay_diff_green']);
    }

    public function test_forbidden_runtime_flag_true_is_caught(): void
    {
        ['d' => $d] = $this->compare(
            ['status' => 'passed'],
            ['status' => 'passed', 'provider_called_by_atlas' => true], // forbidden flag flipped
        );

        $this->assertSame('blocked', $d['status']);
        $this->assertContains('provider_called_by_atlas', array_column($d['mutations'], 'field'));
    }

    public function test_missing_before_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:smoke-replay-diff', ['--after' => '{}', '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
