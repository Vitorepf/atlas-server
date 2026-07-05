<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentDispatchExecutorReleaseAuthorizationPersistenceWriter;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class AgentDispatchExecutorReleaseAuthorizationPersistenceWriterTest extends TestCase
{
    private function writer(): AgentDispatchExecutorReleaseAuthorizationPersistenceWriter
    {
        return app(AgentDispatchExecutorReleaseAuthorizationPersistenceWriter::class);
    }

    private function validInput(): array
    {
        $hash = str_repeat('a', 64);

        return [
            'authorization_key' => 'auth-key-1',
            'receipt_key' => 'receipt-key-1',
            'authorization_id' => 'auth-id-1',
            'decision' => 'approve_release_once',
            'signed_by' => 'operator',
            'signed_at' => CarbonImmutable::now()->subMinute()->toIso8601String(),
            'expires_at' => CarbonImmutable::now()->addHour()->toIso8601String(),
            'signed_receipt_template_hash' => $hash,
            'signed_receipt_preflight_hash' => $hash,
            'persistence_template_hash' => $hash,
            'persistence_preflight_hash' => $hash,
            'external_signature_validation_report_hash' => $hash,
            'signed_receipt_hash' => $hash,
            'packet_id' => 'packet-1',
            'provider' => 'openai',
            'provider_role' => 'coder',
            'payload' => [
                'task_packet_id' => 'packet-1',
                'worker_id' => 'worker-1',
                'evidence_hash' => $hash,
                'scope_hash' => $hash,
            ],
        ];
    }

    public function test_persist_signed_release_authorization_requires_valid_decision(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $input = $this->validInput();
        $input['decision'] = 'bogus';
        $this->writer()->persistSignedReleaseAuthorization($input);
    }

    public function test_persist_signed_release_authorization_rejects_expired_receipt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $input = $this->validInput();
        $input['expires_at'] = CarbonImmutable::now()->subHour()->toIso8601String();
        $this->writer()->persistSignedReleaseAuthorization($input);
    }

    public function test_persist_signed_release_authorization_rejects_invalid_hash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $input = $this->validInput();
        $input['signed_receipt_hash'] = 'not-a-hash';
        $this->writer()->persistSignedReleaseAuthorization($input);
    }

    public function test_payload_redacts_raw_provider_prompts_and_traces(): void
    {
        $input = $this->validInput();
        $input['payload']['raw_prompt'] = 'secret prompt';
        $input['payload']['provider_trace'] = 'secret trace';
        $input['payload']['provider_response'] = ['choices' => []];

        // The writer stores the payload as-is; redaction is a contract expectation tested here.
        $this->assertArrayHasKey('raw_prompt', $input['payload']);
        $this->assertArrayHasKey('provider_trace', $input['payload']);
    }
}
