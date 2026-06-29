<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainInternalizationPipeline;
use PHPUnit\Framework\TestCase;

/**
 * Consumer-side anti-fabricated-capability floor: the high-trust `wiring` candidate is minted ONLY when the
 * source capsule carries REAL proof (non-empty evidence.tests_or_gates_result), not merely a self-declared
 * certified=true. The other candidate kinds and the no-auto-promote invariant are untouched.
 */
final class AtlasBrainInternalizationPipelineTest extends TestCase
{
    /** @return array<string,mixed> */
    private function certifiedWithProof(): array
    {
        return [
            'task_packet_id' => 'c-proof',
            'objective' => 'Wire the registry into origination',
            'certified' => true,
            'files_touched' => ['app/Foo.php'],
            'evidence' => ['diff' => 'abc', 'tests_or_gates_result' => 'OK'],
            'learning' => 'mirrored test path raises throughput',
            'metrics' => ['duration_s' => 9],
            'validation' => ['certified' => true, 'reasons' => []],
        ];
    }

    /** @return array<string,mixed> */
    private function certifiedWithoutProof(): array
    {
        return [
            'task_packet_id' => 'c-noproof',
            'objective' => 'Wire without proof',
            'certified' => true,
            'files_touched' => ['app/Bar.php'],
            'evidence' => ['diff' => 'abc'], // NO tests_or_gates_result
            'learning' => 'still learned something',
            'failures' => ['flaky_gate'],
            'metrics' => ['duration_s' => 3],
            'validation' => ['certified' => true, 'reasons' => []],
        ];
    }

    public function test_certified_with_proof_yields_a_wiring_candidate_carrying_the_proof(): void
    {
        $cands = AtlasBrainInternalizationPipeline::candidatesFrom([$this->certifiedWithProof()]);
        $kinds = array_column($cands, 'kind');

        self::assertContains('wiring', $kinds, 'certified + files + proof ⇒ wiring candidate');

        $wiring = array_values(array_filter($cands, static fn (array $c): bool => $c['kind'] === 'wiring'))[0];
        self::assertSame('OK', $wiring['evidence_ref']['tests_or_gates_result'], 'evidence_ref carries the proof string for a downstream gate');
    }

    public function test_certified_without_proof_yields_no_wiring_but_keeps_other_kinds(): void
    {
        $cands = AtlasBrainInternalizationPipeline::candidatesFrom([$this->certifiedWithoutProof()]);
        $kinds = array_column($cands, 'kind');

        self::assertNotContains('wiring', $kinds, 'self-declared certified without proof ⇒ NO high-trust wiring candidate');
        // The other candidate kinds are derived from independent signals and must be unaffected.
        self::assertContains('policy', $kinds, 'failures still yield a policy candidate');
        self::assertContains('reflection', $kinds, 'learning still yields a reflection candidate');
        self::assertContains('metric', $kinds, 'metrics still yield a metric candidate');
    }

    public function test_candidates_are_never_auto_promoted(): void
    {
        $cands = AtlasBrainInternalizationPipeline::candidatesFrom([$this->certifiedWithProof(), $this->certifiedWithoutProof()]);

        self::assertNotEmpty($cands);
        foreach ($cands as $cand) {
            self::assertFalse($cand['promoted'], 'NEVER auto-promote');
            self::assertTrue($cand['requires_gate']);
            self::assertContains($cand['kind'], AtlasBrainInternalizationPipeline::KINDS);
        }
    }
}
