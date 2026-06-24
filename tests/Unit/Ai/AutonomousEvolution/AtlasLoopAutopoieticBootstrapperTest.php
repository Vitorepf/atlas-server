<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Autopoiesis\SelfExtension\AtlasLoopAutopoieticBootstrapper;
use App\Services\Ai\AutonomousEvolution\Autopoiesis\SelfExtension\ScopeOverlapsLoopCoreException;
use Tests\TestCase;

final class AtlasLoopAutopoieticBootstrapperTest extends TestCase
{
    public function test_bootstrap_is_idempotent_and_second_call_reports_zero_bytes_written(): void
    {
        $bootstrapper = new AtlasLoopAutopoieticBootstrapper;
        $scope = $this->scope();

        $first = $bootstrapper->bootstrap($scope);
        $second = $bootstrapper->bootstrap($scope);

        $this->assertSame($first['manifest_sha256'], $second['manifest_sha256']);
        $this->assertGreaterThan(0, $first['bytes_written']);
        $this->assertSame(0, $second['bytes_written']);
    }

    public function test_bootstrap_rejects_roots_that_overlap_loop_core_and_writes_nothing(): void
    {
        $bootstrapper = new AtlasLoopAutopoieticBootstrapper;

        try {
            $bootstrapper->bootstrap($this->scope(roots: ['app/Services/Ai/AutonomousEvolution/UnsafeRoot']));
            $this->fail('Expected ScopeOverlapsLoopCoreException was not thrown.');
        } catch (ScopeOverlapsLoopCoreException $exception) {
            $this->assertSame('Scope roots overlap Loop core paths.', $exception->getMessage());
        }

        $safe = $bootstrapper->bootstrap($this->scope());
        $this->assertGreaterThan(0, $safe['bytes_written']);
    }

    public function test_manifest_contains_required_keys_and_primitive_stub_paths(): void
    {
        $bundle = (new AtlasLoopAutopoieticBootstrapper)->bootstrap($this->scope());
        $manifest = $bundle['manifest'];

        $this->assertSame('scope-autopoiesis-01', $manifest['scope_id']);
        $this->assertSame(['modules/AutopoiesisDemo'], $manifest['roots']);
        $this->assertSame('Demo\\Autopoiesis', $manifest['namespace']);
        $this->assertSame('autopoiesis-bootstrapper-v1', $manifest['contract_version']);
        $this->assertArrayHasKey('loop', $manifest['primitives']);
        $this->assertArrayHasKey('cortex', $manifest['primitives']);
        $this->assertArrayHasKey('maestro', $manifest['primitives']);
        $this->assertArrayHasKey('operator_intent', $manifest);
        $this->assertArrayHasKey('manifest_sha256', $manifest);
        $this->assertSame('modules/AutopoiesisDemo/Demo/Autopoiesis/LoopSubstrateContract.php', $manifest['primitives']['loop']);
        $this->assertSame('modules/AutopoiesisDemo/Demo/Autopoiesis/CortexComprehensionContract.php', $manifest['primitives']['cortex']);
        $this->assertSame('modules/AutopoiesisDemo/Demo/Autopoiesis/MaestroOrchestrationContract.php', $manifest['primitives']['maestro']);
    }

    /**
     * @param  list<string>  $roots
     * @return array{
     *   namespace:string,
     *   operator_intent:array{rationale:string,scope_id:string},
     *   roots:list<string>,
     *   scope_id:string
     * }
     */
    private function scope(
        array $roots = ['modules/AutopoiesisDemo'],
    ): array {
        return [
            'namespace' => 'Demo\\Autopoiesis',
            'operator_intent' => [
                'rationale' => 'bootstrap this demo scope for controlled self-extension',
                'scope_id' => 'scope-autopoiesis-01',
            ],
            'roots' => $roots,
            'scope_id' => 'scope-autopoiesis-01',
        ];
    }
}
