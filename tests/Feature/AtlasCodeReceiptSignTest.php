<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ed25519 receipt signing contract.
 *
 * The desktop signs a canonical payload with libsodium-equivalent ed25519;
 * the server MUST verify and refuse invalid signatures.
 */
class AtlasCodeReceiptSignTest extends TestCase
{
    private function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'X-Atlas-Token' => 'testing-atlas-token-with-enough-length',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Minimal ad-hoc schema so SQLite :memory: holds what we need.
        if (! Schema::hasTable('ai_decisions')) {
            Schema::create('ai_decisions', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('trace_id')->nullable();
                $t->uuid('router_decision_id')->nullable();
                $t->string('policy_version')->nullable();
                $t->string('decision_mode')->nullable();
                $t->string('route_mode')->nullable();
                $t->string('task_type')->nullable();
                $t->string('risk_level')->nullable();
                $t->string('context_strategy')->nullable();
                $t->string('execution_strategy')->nullable();
                $t->string('selected_provider')->nullable();
                $t->string('selected_model')->nullable();
                $t->string('fallback_provider')->nullable();
                $t->string('operator_requested_provider')->nullable();
                $t->string('requested_provider')->nullable();
                $t->boolean('was_overridden')->default(false);
                $t->integer('confidence_score')->default(0);
                $t->json('signals')->nullable();
                $t->json('candidates')->nullable();
                $t->json('constraints')->nullable();
                $t->json('metrics_snapshot')->nullable();
                $t->json('task_profile')->nullable();
                $t->json('execution_graph')->nullable();
                $t->text('reason')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('atlas_ledger_events')) {
            Schema::create('atlas_ledger_events', function (Blueprint $t) {
                $t->uuid('event_id')->primary();
                $t->string('schema_version');
                $t->uuid('tenant_id')->nullable();
                $t->string('operator_id')->nullable();
                $t->uuid('envelope_id')->nullable();
                $t->uuid('receipt_id')->nullable();
                $t->uuid('trace_id')->nullable();
                $t->uuid('correlation_id')->nullable();
                $t->uuid('causation_id')->nullable();
                $t->string('event_type');
                $t->string('emitter_stage');
                $t->string('emitter_version');
                $t->json('payload')->nullable();
                $t->string('payload_hash');
                $t->timestamp('occurred_at');
                $t->timestamps();
            });
        }
    }

    private function createDecisionRow(): string
    {
        $id = (string) Str::uuid();
        DB::table('ai_decisions')->insert([
            'id' => $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $id;
    }

    public function test_sign_endpoint_records_ledger_event_with_valid_ed25519_signature(): void
    {
        $decisionId = $this->createDecisionRow();
        $signedAt = CarbonImmutable::now()->toIso8601String();
        $signerId = 'operator@localhost';
        $canonical = implode("\n", [
            'atlas-decision/v1',
            $decisionId,
            $signedAt,
            $signerId,
        ]);

        $keypair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keypair);
        $public = sodium_crypto_sign_publickey($keypair);
        $signature = sodium_crypto_sign_detached($canonical, $secret);

        $response = $this->withHeaders($this->headers())
            ->postJson("/atlas-code/decisions/{$decisionId}/sign", [
                'signature' => base64_encode($signature),
                'publicKey' => base64_encode($public),
                'signedAt' => $signedAt,
                'signerId' => $signerId,
            ]);

        $response->assertCreated()
            ->assertJsonStructure(['decisionId', 'signatureValid', 'ledgerEventId', 'canonicalSha256'])
            ->assertJsonPath('signatureValid', true);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'atlas_code.receipt.signed',
            'receipt_id' => $decisionId,
        ]);
    }

    public function test_sign_endpoint_rejects_invalid_signature(): void
    {
        $decisionId = $this->createDecisionRow();
        $signedAt = CarbonImmutable::now()->toIso8601String();
        $signerId = 'operator@localhost';

        $keypair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($keypair);
        $bogusSig = random_bytes(SODIUM_CRYPTO_SIGN_BYTES);

        $response = $this->withHeaders($this->headers())
            ->postJson("/atlas-code/decisions/{$decisionId}/sign", [
                'signature' => base64_encode($bogusSig),
                'publicKey' => base64_encode($public),
                'signedAt' => $signedAt,
                'signerId' => $signerId,
            ]);

        $response->assertStatus(422);
    }
}
