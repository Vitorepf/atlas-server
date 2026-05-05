<?php

namespace Tests\Unit\Ai\Kernel;

use App\Services\Ai\Kernel\Envelope\OperationEnvelope;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use Tests\TestCase;

class OperationEnvelopeFactoryTest extends TestCase
{
    public function test_factory_creates_canonical_envelope_with_operator_origin_and_input_hash(): void
    {
        $envelope = app(OperationEnvelopeFactory::class)->create([
            'operator' => [
                'operator_id' => 'vitor',
                'tenant_id' => 'atlas-single-tenant',
                'workspace' => '/tmp/workspace',
                'default_privacy' => 'private',
            ],
            'origin' => [
                'surface_id' => 'atlas_cli',
                'surface_version' => 'dev',
                'session_id' => 'session-1',
                'received_at' => '2026-05-05T10:00:00Z',
            ],
            'input' => [
                'text' => 'corrija esse bug',
                'attachments' => [['kind' => 'image', 'path' => '/tmp/screen.png']],
                'hints' => ['flow' => 'programming.dev'],
                'locale' => 'pt-BR',
            ],
        ]);

        $this->assertSame(OperationEnvelope::SCHEMA_VERSION, $envelope->schemaVersion);
        $this->assertSame('vitor', $envelope->operator->operatorId);
        $this->assertSame('atlas-single-tenant', $envelope->operator->tenantId);
        $this->assertSame('atlas_cli', $envelope->origin->surfaceId);
        $this->assertSame('corrija esse bug', $envelope->input->primaryText);
        $this->assertSame('received', $envelope->execution->status);
        $this->assertFalse($envelope->hasDecisionReceipt());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $envelope->input->inputHash);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $envelope->audit->chainHash);
    }

    public function test_input_hash_is_deterministic_for_same_input_payload(): void
    {
        $factory = app(OperationEnvelopeFactory::class);

        $first = $factory->create(['text' => 'mesma tarefa', 'hints' => ['flow' => 'programming.dev']]);
        $second = $factory->create(['text' => 'mesma tarefa', 'hints' => ['flow' => 'programming.dev']]);

        $this->assertSame($first->input->inputHash, $second->input->inputHash);
        $this->assertNotSame($first->envelopeId, $second->envelopeId);
        $this->assertNotSame($first->audit->chainHash, $second->audit->chainHash);
    }

    public function test_child_envelope_records_parent_chain_inputs(): void
    {
        $factory = app(OperationEnvelopeFactory::class);
        $parent = $factory->create(['text' => 'primeiro passo']);
        $child = $factory->create([
            'text' => 'continue',
            'parent_envelope_id' => $parent->envelopeId,
            'parent_chain_hash' => $parent->audit->chainHash,
        ]);

        $this->assertSame($parent->envelopeId, $child->parentEnvelopeId);
        $this->assertNotSame($parent->audit->chainHash, $child->audit->chainHash);
    }
}
