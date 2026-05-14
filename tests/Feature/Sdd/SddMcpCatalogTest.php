<?php

namespace Tests\Feature\Sdd;

use Tests\TestCase;

class SddMcpCatalogTest extends TestCase
{
    private string $token = 'mcp-test-token-with-enough-length-1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        config(['atlas.token' => $this->token]);
    }

    public function test_catalog_endpoint_lists_canonical_resources(): void
    {
        $payload = $this->withHeader('X-Atlas-Token', $this->token)
            ->getJson('/atlas-code/sdd/mcp/resources')
            ->assertOk()
            ->json();

        $this->assertSame('atlas.sdd_mcp_resource_catalog.v1', $payload['schema_version']);
        $this->assertSame('mcp_is_a_surface_not_authority', $payload['authority']);
        $this->assertSame('mutations_only_via_decision_receipt', $payload['mutation_policy']);

        $uris = array_column($payload['resources'], 'uri');
        foreach ([
            'mcp:atlas/operations',
            'mcp:atlas/specs',
            'mcp:atlas/requirements',
            'mcp:atlas/traceability',
            'mcp:atlas/decision-receipts',
            'mcp:atlas/evidence',
            'mcp:atlas/drift-reports',
            'mcp:atlas/learning-proposals',
        ] as $expected) {
            $this->assertContains($expected, $uris, "missing canonical resource {$expected}");
        }

        foreach ($payload['resources'] as $r) {
            $this->assertTrue($r['read_only']);
            $this->assertTrue($r['redacts_secrets']);
        }

        $this->assertContains('mcp:generate_spec', $payload['governed_prompts']);
        $this->assertContains('mcp:validate_drift', $payload['governed_prompts']);
    }

    public function test_catalog_requires_atlas_token(): void
    {
        $this->getJson('/atlas-code/sdd/mcp/resources')->assertStatus(401);
    }
}
