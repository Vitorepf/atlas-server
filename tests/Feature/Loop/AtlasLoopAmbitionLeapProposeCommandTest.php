<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the ambition-leap proposer is live at the operator surface: a plateau gap with >=3 grounded evidence
 * refs and a non-proxy delta yields a grounded leap; a thin-evidence gap abstains; a proxy delta is rejected.
 */
final class AtlasLoopAmbitionLeapProposeCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-leap-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    private function propose(array $gaps): array
    {
        file_put_contents($this->input, (string) json_encode($gaps));
        $exit = Artisan::call('atlas:loop:ambition-leap-propose', ['--input' => $this->input, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_grounded_plateau_gap_yields_a_leap(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->propose([[
            'gap_id' => 'g1',
            'plateau_signal' => true,
            'evidence_refs' => ['e3', 'e1', 'e2'],
            'target_capability_delta' => 'Originate next-level objectives from the frontier supply lane',
            'scope' => 'loop',
        ]]);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.ambition_leap_propose.v1', $d['schema']);
        $this->assertSame(1, $d['proposal_count'], (string) json_encode($d));
        $leap = $d['leaps'][0];
        $this->assertNull($leap['abstain_reason']);
        $this->assertSame('Originate next-level objectives from the frontier supply lane', $leap['target_capability_delta']);
        $this->assertSame(['e1', 'e2', 'e3'], $leap['grounded_evidence_refs']); // deduped + sorted
    }

    public function test_thin_evidence_gap_abstains(): void
    {
        ['d' => $d] = $this->propose([[
            'gap_id' => 'g2',
            'plateau_signal' => true,
            'evidence_refs' => ['only-one'], // < 3 ⇒ abstain
            'target_capability_delta' => 'A real capability multiplier',
        ]]);

        $this->assertSame('insufficient_grounded_evidence', $d['leaps'][0]['abstain_reason']);
        $this->assertNull($d['leaps'][0]['target_capability_delta']);
    }

    public function test_proxy_delta_is_rejected(): void
    {
        ['d' => $d] = $this->propose([[
            'gap_id' => 'g3',
            'plateau_signal' => true,
            'evidence_refs' => ['e1', 'e2', 'e3'],
            'target_capability_delta' => 'reduce cyclomatic complexity in the loop',
        ]]);

        $this->assertSame('proxy_delta_rejected', $d['leaps'][0]['abstain_reason']);
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:ambition-leap-propose', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
