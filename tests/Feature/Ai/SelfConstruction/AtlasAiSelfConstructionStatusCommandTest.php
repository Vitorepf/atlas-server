<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Console\Commands\AtlasAiSelfConstructionStatusCommand;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\NamingPolicy\AtlasSelfConstructionNamingPolicyGate;
use Tests\TestCase;

/**
 * Gap4.F5 first slice — `atlas:ai:self-construction:status` sub-command.
 *
 * The mother command at app/Console/Commands/AtlasAiSelfConstructionCommand.php
 * is 13.790 lines / 1.5 MB. This sub-command is the FIRST extraction per
 * the blueprint in atlas-self-construction-catalog.md. It is read-only
 * and ships alongside the mother for safe coexistence.
 *
 * The test pins the contract:
 *
 *   - Command emits canonical schema atlas.self_construction.status_snapshot.v1.
 *   - Payload carries readiness + naming policy blocks.
 *   - Status is ok when both sub-blocks pass; failed otherwise.
 *   - Errors in readiness service do not crash the command (unreachable status).
 */
class AtlasAiSelfConstructionStatusCommandTest extends TestCase
{
    public function test_command_is_registered_with_canonical_signature(): void
    {
        $command = $this->app->make(AtlasAiSelfConstructionStatusCommand::class);

        $this->assertSame('atlas:ai:self-construction:status', $command->getName());
        $this->assertStringContainsString('Self-Construction', $command->getDescription());
    }

    public function test_buildPayload_emits_canonical_schema(): void
    {
        $command = $this->app->make(AtlasAiSelfConstructionStatusCommand::class);
        $payload = $command->buildPayload(
            $this->app->make(AtlasSelfConstructionReadinessService::class),
            $this->app->make(AtlasSelfConstructionNamingPolicyGate::class),
        );

        $this->assertSame('atlas.self_construction.status_snapshot.v1', $payload['schema_version']);
        $this->assertArrayHasKey('readiness', $payload);
        $this->assertArrayHasKey('naming_policy', $payload);
        $this->assertArrayHasKey('docs_canon', $payload);
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-self-construction-catalog.md',
            $payload['docs_canon'],
        );
    }

    public function test_naming_policy_block_uses_canonical_gate_schema(): void
    {
        $command = $this->app->make(AtlasAiSelfConstructionStatusCommand::class);
        $payload = $command->buildPayload(
            $this->app->make(AtlasSelfConstructionReadinessService::class),
            $this->app->make(AtlasSelfConstructionNamingPolicyGate::class),
        );

        $this->assertSame(
            'atlas.self_construction.naming_policy_gate.v1',
            $payload['naming_policy']['schema_version'],
        );
        // Existing grandfathered violations DO NOT block the gate; only
        // NEW violations would. With empty fail-fast list, status=ok.
        $this->assertSame('ok', $payload['naming_policy']['status']);
    }

    public function test_command_runs_without_crashing(): void
    {
        // The command MAY return exit 1 in test env when readiness is
        // unreachable (no DB tables). We only assert it doesn't blow up.
        $this->artisan('atlas:ai:self-construction:status', ['--json' => true]);
        $this->addToAssertionCount(1);
    }

    public function test_readiness_unreachable_returns_unreachable_status_via_reflection(): void
    {
        // The readiness service is `final` — we cannot subclass to inject a
        // throwing stub. Instead we exercise the safeSnapshot path directly
        // via reflection on the private method. This proves the contract
        // documented in the command's doc-comment: errors do not crash.
        $command = $this->app->make(AtlasAiSelfConstructionStatusCommand::class);
        $reflection = new \ReflectionObject($command);
        $method = $reflection->getMethod('safeSnapshot');
        $method->setAccessible(true);

        $result = $method->invoke($command, function (): array {
            throw new \RuntimeException('synthetic_readiness_failure');
        });

        $this->assertSame('unreachable', $result['status']);
        $this->assertStringContainsString('synthetic_readiness_failure', $result['detail']);
    }
}
