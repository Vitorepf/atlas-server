<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\Receipts\AtlasSelfConstructionDecisionBinding;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the decision-binding verifier is live at the operator surface: a downstream receipt that references a
 * known decision with a matching hash is BOUND; one with no decision_ref is missing_binding (not bound).
 */
final class AtlasLoopDecisionBindingCommandTest extends TestCase
{
    private function verify(array $factIndex): array
    {
        $exit = Artisan::call('atlas:loop:decision-binding', [
            '--facts' => (string) json_encode($factIndex),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_matching_ref_and_hash_is_bound(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->verify([
            'index' => [
                'decision_receipts' => ['d1' => ['hash' => 'abc', 'ts' => '2026-06-01T00:00:00Z']],
                'task_receipts' => ['t1' => ['decision_ref' => 'd1', 'decision_hash' => 'abc', 'decision_ts' => '2026-06-02T00:00:00Z']],
            ],
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasSelfConstructionDecisionBinding::SCHEMA, $d['schema']);
        $this->assertTrue($d['all_bound'], (string) json_encode($d));
        $this->assertSame(AtlasSelfConstructionDecisionBinding::STATUS_BOUND, $d['bindings'][0]['status']);
    }

    public function test_missing_decision_ref_is_not_bound(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->verify([
            'index' => [
                'decision_receipts' => ['d1' => ['hash' => 'abc', 'ts' => '2026-06-01T00:00:00Z']],
                'task_receipts' => ['t2' => ['some' => 'row']], // no decision_ref
            ],
        ]);

        $this->assertSame(0, $exit);
        $this->assertFalse($d['all_bound'], (string) json_encode($d));
        $this->assertSame(AtlasSelfConstructionDecisionBinding::STATUS_MISSING, $d['bindings'][0]['status']);
    }

    public function test_missing_facts_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:decision-binding', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
