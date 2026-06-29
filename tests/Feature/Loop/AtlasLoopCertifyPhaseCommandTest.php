<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the certify-phase runner is live at the operator surface and emits deterministic facts: an
 * armed+improved certifier verdict certifies, an unimproved one is inconclusive, and a regressed one is
 * reported regressed (the runner raises but the recorded receipt is surfaced). A missing --receipt is a
 * usage error.
 */
final class AtlasLoopCertifyPhaseCommandTest extends TestCase
{
    public function test_requires_receipt(): void
    {
        $exit = Artisan::call('atlas:loop:certify-phase', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_armed_and_improved_certifies(): void
    {
        $decoded = $this->certify([
            'task_packet_id' => 't1',
            'certifier_result' => ['armed' => true, 'improved' => true, 'regressed' => false, 'frozen_judge_verdict' => 'pass'],
        ]);

        $this->assertSame('certify', $decoded['phase']);
        $this->assertSame('certified', $decoded['status']);
        $this->assertTrue($decoded['certified']);
        $this->assertSame('t1', $decoded['task_packet_id']);
    }

    public function test_no_positive_delta_is_inconclusive(): void
    {
        $decoded = $this->certify([
            'task_packet_id' => 't2',
            'certifier_result' => ['armed' => true, 'improved' => false],
        ]);

        $this->assertSame('inconclusive', $decoded['status']);
        $this->assertFalse($decoded['certified']);
    }

    public function test_regressed_verdict_is_reported(): void
    {
        $decoded = $this->certify([
            'task_packet_id' => 't3',
            'certifier_result' => ['regressed' => true, 'reason' => 'behavior_delta_negative'],
        ]);

        $this->assertSame('regressed', $decoded['status']);
        $this->assertTrue($decoded['regressed']);
        $this->assertFalse($decoded['certified']);
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function certify(array $receipt): array
    {
        $exit = Artisan::call('atlas:loop:certify-phase', [
            '--receipt' => json_encode($receipt),
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
