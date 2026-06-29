<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Federation\AtlasLoopAutopoieticScopeGovernancePipeline;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the autopoietic scope-governance pipeline is live at the operator surface: a loop-core root is never
 * admissible; a complete non-core proposal with an operator receipt is admitted; a proposal lacking a receipt
 * is blocked. requires_operator_receipt is always true.
 */
final class AtlasLoopAutopoieticScopeGovernanceCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-scope-gov-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    private function evaluate(array $proposal): array
    {
        file_put_contents($this->input, (string) json_encode($proposal));
        $exit = Artisan::call('atlas:loop:autopoietic-scope-governance', ['--input' => $this->input, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_loop_core_root_is_never_admissible(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->evaluate([
            'scope_id' => 'newscope',
            'namespace' => 'App\\Services\\Ai\\AutonomousEvolution\\NewScope',
            'operator_intent' => ['evolve the loop'],
            'root' => 'app/Services/Ai/AutonomousEvolution/NewScope',
            'operator_receipt' => 'op-1',
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopAutopoieticScopeGovernancePipeline::SCHEMA, $d['schema']);
        $this->assertFalse($d['admitted']);
        $this->assertContains(AtlasLoopAutopoieticScopeGovernancePipeline::REASON_LOOP_CORE_OVERLAP, $d['blocking_reasons']);
        $this->assertTrue($d['requires_operator_receipt']);
    }

    public function test_complete_non_core_proposal_with_receipt_is_admitted(): void
    {
        ['d' => $d] = $this->evaluate([
            'scope_id' => 'marketing',
            'namespace' => 'App\\Services\\Ai\\Marketing',
            'operator_intent' => ['grow the marketing capability'],
            'root' => 'app/Services/Ai/Marketing',
            'operator_receipt' => 'op-receipt-123',
        ]);

        $this->assertTrue($d['admitted'], (string) json_encode($d));
        $this->assertSame([], $d['blocking_reasons']);
    }

    public function test_missing_receipt_is_blocked(): void
    {
        ['d' => $d] = $this->evaluate([
            'scope_id' => 'marketing',
            'namespace' => 'App\\Services\\Ai\\Marketing',
            'operator_intent' => ['grow the marketing capability'],
            'root' => 'app/Services/Ai/Marketing',
            // no operator_receipt
        ]);

        $this->assertFalse($d['admitted']);
        $this->assertContains(AtlasLoopAutopoieticScopeGovernancePipeline::REASON_OPERATOR_RECEIPT_REQUIRED, $d['blocking_reasons']);
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:autopoietic-scope-governance', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
