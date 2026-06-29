<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the architecture-contract compiler is live at the operator surface: a valid contract compiles into an
 * atomic draft carrying the capability-gap objective, the impl+test allowed_files and a spec_hash; an invalid
 * contract (broad directory / empty seed) is refused.
 */
final class AtlasLoopContractCompileCommandTest extends TestCase
{
    private function compile(array $contract): array
    {
        $exit = Artisan::call('atlas:loop:contract-compile', [
            '--contract' => (string) json_encode($contract),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_valid_contract_compiles_to_atomic_draft(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->compile([
            'contract_id' => 'c1',
            'owner_scope' => 'marketing',
            'capability_gap' => 'Add a widget renderer',
            'candidate_files' => ['app/Marketing/Widget.php', 'tests/Unit/Marketing/WidgetTest.php'],
            'acceptance_seed' => ['the widget renders'],
            'evidence_seed' => ['tests_or_gates_result'],
            'risk_class' => 'standard',
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.contract_compile.v1', $d['schema']);
        $this->assertSame(1, $d['draft_count'], (string) json_encode($d));
        $draft = $d['drafts'][0];
        $this->assertStringContainsString('Add a widget renderer', $draft['objective']);
        $this->assertNotEmpty($draft['allowed_files']);
        $this->assertContains('app/Marketing/Widget.php', $draft['allowed_files']);
        $this->assertContains('tests/Unit/Marketing/WidgetTest.php', $draft['allowed_files']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $draft['spec_hash']);
    }

    public function test_broad_directory_candidate_is_refused(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->compile([
            'contract_id' => 'c1',
            'owner_scope' => 'marketing',
            'capability_gap' => 'Add a thing',
            'candidate_files' => ['app/Marketing/'], // trailing slash ⇒ broad directory
            'acceptance_seed' => ['ok'],
            'evidence_seed' => ['tests_or_gates_result'],
        ]);

        $this->assertNotSame(0, $exit);
        $this->assertSame('compile_refused', $d['reason']);
        $this->assertStringContainsString('broad directory', $d['message']);
    }

    public function test_empty_acceptance_seed_is_refused(): void
    {
        ['d' => $d] = $this->compile([
            'contract_id' => 'c1',
            'owner_scope' => 'marketing',
            'capability_gap' => 'Add a thing',
            'candidate_files' => ['app/Marketing/Widget.php'],
            'acceptance_seed' => [],
            'evidence_seed' => ['tests_or_gates_result'],
        ]);

        $this->assertSame('compile_refused', $d['reason']);
        $this->assertStringContainsString('acceptance_seed', $d['message']);
    }

    public function test_empty_contract_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:contract-compile', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
