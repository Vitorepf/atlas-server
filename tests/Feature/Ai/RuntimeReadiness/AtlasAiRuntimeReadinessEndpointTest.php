<?php

namespace Tests\Feature\Ai\RuntimeReadiness;

use Tests\TestCase;

class AtlasAiRuntimeReadinessEndpointTest extends TestCase
{
    public function test_get_runtime_readiness_retorna_payload_canon(): void
    {
        $response = $this->getJson('/atlas/ai/runtime-readiness');

        $response->assertOk();
        $response->assertJsonStructure([
            'schema_version',
            'status',
            'generated_at',
            'summary' => ['total', 'passed', 'partial', 'failed', 'critical_failed', 'warn_failed'],
            'checks',
            'blockers',
            'warnings',
            'evidence_refs',
            'required_commands',
            'claim_policy' => [
                'declares_benchmark',
                'declares_rivals',
                'declares_superiority',
                'declares_teos_certification',
                'invokes_provider',
                'scope',
                'forbidden_claims',
            ],
            'release_scope',
            'certification_hash',
        ]);
        $response->assertJsonPath('schema_version', 'atlas.ai.runtime_readiness.v1');
    }

    public function test_endpoint_status_é_ready_partial_ou_blocked(): void
    {
        $response = $this->getJson('/atlas/ai/runtime-readiness');
        $response->assertOk();

        $status = $response->json('status');
        $this->assertContains($status, ['ready', 'partial', 'blocked']);
    }

    public function test_endpoint_não_declara_benchmark_nem_superioridade(): void
    {
        $response = $this->getJson('/atlas/ai/runtime-readiness');
        $response->assertOk();

        $response->assertJsonPath('claim_policy.declares_benchmark', false);
        $response->assertJsonPath('claim_policy.declares_rivals', false);
        $response->assertJsonPath('claim_policy.declares_superiority', false);
        $response->assertJsonPath('claim_policy.declares_teos_certification', false);
        $response->assertJsonPath('claim_policy.invokes_provider', false);
    }

    public function test_endpoint_carrega_certification_hash_sha256(): void
    {
        $response = $this->getJson('/atlas/ai/runtime-readiness');
        $response->assertOk();

        $hash = $response->json('certification_hash');
        $this->assertIsString($hash);
        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function test_endpoint_lista_required_commands_canonicos(): void
    {
        $response = $this->getJson('/atlas/ai/runtime-readiness');
        $response->assertOk();

        $commands = $response->json('required_commands');
        $this->assertIsArray($commands);
        $this->assertContains('php artisan atlas:ai:runtime-readiness --json', $commands);
        $this->assertContains('php artisan atlas:ai:product-certify --json', $commands);
    }

    public function test_endpoint_é_idempotente_e_nã_o_muta_estado(): void
    {
        $r1 = $this->getJson('/atlas/ai/runtime-readiness');
        $r2 = $this->getJson('/atlas/ai/runtime-readiness');

        $r1->assertOk();
        $r2->assertOk();
        // schema_version e claim_policy nunca mudam entre chamadas.
        $this->assertSame($r1->json('schema_version'), $r2->json('schema_version'));
        $this->assertSame($r1->json('claim_policy.scope'), $r2->json('claim_policy.scope'));
        $this->assertSame($r1->json('release_scope'), $r2->json('release_scope'));
    }
}
