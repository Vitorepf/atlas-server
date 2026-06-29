<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerReadinessGate;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the native-worker readiness gate is live at the operator surface: observed facts missing a readiness
 * precondition report not-ready with named blockers; complete facts (atlas-native owner + verification +
 * rollback + every required component verified) report ready.
 */
final class AtlasLoopWorkerReadinessCommandTest extends TestCase
{
    /** @return array<string,array{present:bool,verified:bool}> */
    private function allComponents(bool $verified): array
    {
        $map = [];
        foreach (AtlasNativeWorkerReadinessGate::REQUIRED_COMPONENTS as $cid) {
            $map[$cid] = ['present' => $verified, 'verified' => $verified];
        }

        return $map;
    }

    private function evaluate(array $observed): array
    {
        $exit = Artisan::call('atlas:loop:worker-readiness', [
            '--observed' => (string) json_encode($observed),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_missing_preconditions_is_not_ready(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->evaluate([
            'runtime_owner' => 'external_provider', // wrong owner
            'server_side_verification_available' => false,
            'rollback_available' => false,
            'components' => [], // all components missing
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasNativeWorkerReadinessGate::SCHEMA, $d['schema']);
        $this->assertFalse($d['ready'], (string) json_encode($d));
        $this->assertNotEmpty($d['blockers']);
        $this->assertContains('rollback_unavailable', $d['blockers']);
    }

    public function test_complete_facts_are_ready(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->evaluate([
            'runtime_owner' => 'atlas_native',
            'server_side_verification_available' => true,
            'rollback_available' => true,
            'components' => $this->allComponents(true),
        ]);

        $this->assertSame(0, $exit);
        $this->assertTrue($d['ready'], (string) json_encode($d));
        $this->assertSame([], $d['blockers']);
        $this->assertCount(count(AtlasNativeWorkerReadinessGate::REQUIRED_COMPONENTS), $d['components_verified']);
    }

    public function test_missing_observed_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:worker-readiness', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
