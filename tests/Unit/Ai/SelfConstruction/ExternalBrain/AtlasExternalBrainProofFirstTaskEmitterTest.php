<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProofFirstTaskEmitter;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainProofFirstTaskEmitterTest extends TestCase
{
    private function emitter(): AtlasExternalBrainProofFirstTaskEmitter
    {
        return new AtlasExternalBrainProofFirstTaskEmitter;
    }

    public function test_proof_prework_first_case(): void
    {
        $r = $this->emitter()->emit([
            'targets' => [
                [
                    'target' => 'OrganA',
                    'has_tests' => true,
                    'has_replay_proof' => true,
                    'has_rollback_proof' => true,
                    'has_contract_proof' => true,
                ],
                [
                    'target' => 'OrganB',
                    'has_tests' => false,
                    'has_replay_proof' => false,
                ],
            ],
        ]);

        $types = array_column($r['tasks'], 'type');
        $this->assertSame(['proof_prework', 'compress'], $types);
        $this->assertSame('OrganB', $r['tasks'][0]['target']);
        $this->assertSame('OrganA', $r['tasks'][1]['target']);
    }

    public function test_compress_when_proven_case(): void
    {
        $r = $this->emitter()->emit([
            'targets' => [
                [
                    'target' => 'ProvenOrgan',
                    'action' => 'delete',
                    'has_tests' => true,
                    'has_replay_proof' => true,
                    'has_rollback_proof' => true,
                    'has_contract_proof' => true,
                ],
            ],
        ]);

        $this->assertCount(1, $r['tasks']);
        $this->assertSame('compress', $r['tasks'][0]['type']);
        $this->assertSame('ProvenOrgan', $r['tasks'][0]['target']);
        $this->assertSame('delete', $r['tasks'][0]['action']);
    }

    public function test_partial_proof_debt_produces_only_prework_no_compress(): void
    {
        $r = $this->emitter()->emit([
            'targets' => [
                [
                    'target' => 'HalfProven',
                    'has_tests' => true,
                    'has_replay_proof' => true,
                    'has_rollback_proof' => false,
                    'has_contract_proof' => false,
                ],
            ],
        ]);

        $this->assertCount(1, $r['tasks']);
        $this->assertSame('proof_prework', $r['tasks'][0]['type']);
        $this->assertSame(
            ['provide_rollback_proof_for_HalfProven', 'provide_contract_proof_for_HalfProven'],
            $r['tasks'][0]['required_prework'],
        );
    }

    public function test_multiple_unproven_targets_all_precede_all_compress_tasks(): void
    {
        $r = $this->emitter()->emit([
            'targets' => [
                ['target' => 'Proven1', 'has_tests' => true, 'has_replay_proof' => true, 'has_rollback_proof' => true, 'has_contract_proof' => true],
                ['target' => 'Unproven1'],
                ['target' => 'Proven2', 'has_tests' => true, 'has_replay_proof' => true, 'has_rollback_proof' => true, 'has_contract_proof' => true],
                ['target' => 'Unproven2'],
            ],
        ]);

        $types = array_column($r['tasks'], 'type');
        $this->assertSame(['proof_prework', 'proof_prework', 'compress', 'compress'], $types);
    }

    public function test_empty_targets_produces_no_tasks(): void
    {
        $r = $this->emitter()->emit([]);

        $this->assertSame([], $r['tasks']);
    }

    public function test_schema_present(): void
    {
        $r = $this->emitter()->emit([]);

        $this->assertSame(AtlasExternalBrainProofFirstTaskEmitter::SCHEMA, $r['schema']);
    }
}
