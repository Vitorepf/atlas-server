<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilitySurfaceMinimizer;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCapabilitySurfaceMinimizerTest extends TestCase
{
    private function minimizer(): AtlasExternalBrainCapabilitySurfaceMinimizer
    {
        return new AtlasExternalBrainCapabilitySurfaceMinimizer;
    }

    public function test_safe_surface_reduction_case(): void
    {
        $r = $this->minimizer()->evaluate([
            'capabilities' => [
                [
                    'id' => 'task-serving',
                    'owner' => 'atlas-dev',
                    'evidence' => 'AtlasTaskServingStack tests green',
                    'entrypoints' => [
                        ['name' => 'atlas:task:next', 'kept' => true],
                        ['name' => 'atlas:task:legacy-next', 'kept' => false, 'active_consumers' => []],
                    ],
                ],
            ],
        ]);

        $this->assertSame('approve', $r['overall_decision']);
        $this->assertSame('approve', $r['capabilities'][0]['decision']);
        $this->assertSame([], $r['capabilities'][0]['reasons']);
    }

    public function test_active_consumer_hold_case_names_exact_consumers(): void
    {
        $r = $this->minimizer()->evaluate([
            'capabilities' => [
                [
                    'id' => 'task-serving',
                    'owner' => 'atlas-dev',
                    'evidence' => 'tests green',
                    'entrypoints' => [
                        ['name' => 'atlas:task:next', 'kept' => true],
                        ['name' => 'atlas:task:legacy-next', 'kept' => false, 'active_consumers' => ['worker-alpha', 'worker-beta']],
                    ],
                ],
            ],
        ]);

        $this->assertSame('hold', $r['overall_decision']);
        $capability = $r['capabilities'][0];
        $this->assertSame('hold', $capability['decision']);
        $this->assertContains('worker-alpha', $capability['blocked_consumers']);
        $this->assertContains('worker-beta', $capability['blocked_consumers']);
    }

    public function test_missing_owner_holds(): void
    {
        $r = $this->minimizer()->evaluate([
            'capabilities' => [
                [
                    'id' => 'no-owner',
                    'evidence' => 'evidence present',
                    'entrypoints' => [['name' => 'a', 'kept' => true]],
                ],
            ],
        ]);

        $this->assertSame('hold', $r['capabilities'][0]['decision']);
        $this->assertContains('missing_owner', $r['capabilities'][0]['reasons']);
    }

    public function test_missing_evidence_holds(): void
    {
        $r = $this->minimizer()->evaluate([
            'capabilities' => [
                [
                    'id' => 'no-evidence',
                    'owner' => 'atlas-dev',
                    'entrypoints' => [['name' => 'a', 'kept' => true]],
                ],
            ],
        ]);

        $this->assertContains('missing_evidence', $r['capabilities'][0]['reasons']);
    }

    public function test_no_kept_entrypoint_holds(): void
    {
        $r = $this->minimizer()->evaluate([
            'capabilities' => [
                [
                    'id' => 'zero-entry',
                    'owner' => 'atlas-dev',
                    'evidence' => 'evidence',
                    'entrypoints' => [['name' => 'a', 'kept' => false]],
                ],
            ],
        ]);

        $this->assertContains('no_intended_entrypoint_remaining', $r['capabilities'][0]['reasons']);
    }

    public function test_multiple_capabilities_mix_approve_and_hold(): void
    {
        $r = $this->minimizer()->evaluate([
            'capabilities' => [
                [
                    'id' => 'safe',
                    'owner' => 'atlas-dev',
                    'evidence' => 'evidence',
                    'entrypoints' => [['name' => 'a', 'kept' => true]],
                ],
                [
                    'id' => 'unsafe',
                    'owner' => 'atlas-dev',
                    'evidence' => 'evidence',
                    'entrypoints' => [['name' => 'b', 'kept' => false, 'active_consumers' => ['x']]],
                ],
            ],
        ]);

        $this->assertSame('hold', $r['overall_decision']);
        $byId = [];
        foreach ($r['capabilities'] as $c) {
            $byId[$c['id']] = $c['decision'];
        }
        $this->assertSame('approve', $byId['safe']);
        $this->assertSame('hold', $byId['unsafe']);
    }

    public function test_empty_capabilities_approves_overall(): void
    {
        $r = $this->minimizer()->evaluate([]);

        $this->assertSame('approve', $r['overall_decision']);
        $this->assertSame([], $r['capabilities']);
    }

    public function test_schema_present(): void
    {
        $r = $this->minimizer()->evaluate([]);

        $this->assertSame(AtlasExternalBrainCapabilitySurfaceMinimizer::SCHEMA, $r['schema']);
    }
}
