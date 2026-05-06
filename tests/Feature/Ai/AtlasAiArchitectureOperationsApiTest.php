<?php

namespace Tests\Feature\Ai;

use Tests\TestCase;

class AtlasAiArchitectureOperationsApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
    }

    public function test_api_exposes_architecture_operations_catalog(): void
    {
        $response = $this->getJson('/ai/architecture/operations', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('architecture_operations.schema_version', 'atlas.architecture_operations.v1')
            ->assertJsonPath('architecture_operations.section', 'arquitetura_mae')
            ->assertJsonPath('architecture_operations.command_count', 19)
            ->assertJsonPath('architecture_operations.commands.0.id', 'architecture_operations')
            ->assertJsonPath('architecture_operations.commands.0.kind', 'catalog')
            ->assertJsonPath('architecture_operations.commands.0.surface', 'cli');

        $commands = array_column($response->json('architecture_operations.commands'), 'command');

        $this->assertContains('atlas ai architecture-operations --json', $commands);
        $this->assertContains('atlas ai architecture-validate', $commands);
        $this->assertContains('atlas engineering knowledge docs-health --json', $commands);
        $this->assertContains('atlas engineering knowledge sync --prune --json', $commands);
        $this->assertContains('atlas engineering knowledge index-code --prune --json', $commands);
        $this->assertContains('atlas ai inbox-action-report --hours=24 --json', $commands);
        $this->assertContains('atlas ai decision-receipt-report --envelope=<id> --json', $commands);
        $this->assertContains('atlas ledger replay --envelope=<id> --json', $commands);
        $this->assertContains('architecture_operations', $response->json('architecture_operations.operation_ids'));
    }

    public function test_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/architecture/operations')
            ->assertUnauthorized();
    }

    public function test_api_filters_architecture_operations_catalog(): void
    {
        $response = $this->getJson('/ai/architecture/operations?kind=evidence_report', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.kind', 'evidence_report')
            ->assertJsonPath('architecture_operations.command_count', 8);

        $this->assertContains('provider_performance_report', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('decision_receipt_report', $response->json('architecture_operations.operation_ids'));
        $this->assertContains('ledger_replay', $response->json('architecture_operations.operation_ids'));
        $this->assertNotContains('architecture_operations', $response->json('architecture_operations.operation_ids'));

        $this->getJson('/ai/architecture/operations?id=provider_performance_report', $this->headers)
            ->assertOk()
            ->assertJsonPath('architecture_operations.filters.id', 'provider_performance_report')
            ->assertJsonPath('architecture_operations.command_count', 1)
            ->assertJsonPath('architecture_operations.commands.0.command', 'atlas ai provider-performance --hours=24 --json');
    }
}
