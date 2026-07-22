<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\WireFormat\AtlasLoopInterPrimitiveMessageSchemaRegistry;
use App\Services\Ai\AutonomousEvolution\WireFormat\AtlasLoopInterPrimitiveMessageValidator;
use Tests\TestCase;

final class AtlasLoopInterPrimitiveMessageValidatorTest extends TestCase
{
    private function validator(): AtlasLoopInterPrimitiveMessageValidator
    {
        return new AtlasLoopInterPrimitiveMessageValidator(new AtlasLoopInterPrimitiveMessageSchemaRegistry());
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshotPayload(array $overrides = []): array
    {
        return $overrides + [
            'schema_id' => 'loop.cortex.snapshot.v1',
            'snapshot_id' => 'snap-1',
            'scope_root' => 'app/Demo',
            'built_at_unix' => 1700000000,
            'inventory' => ['App\\Demo\\Foo' => ['kind' => 'class']],
            'edges' => [['from' => 'a', 'to' => 'b']],
            'orphans' => [],
        ];
    }

    public function test_valid_payload_returns_ok_with_byte_shape_ordered_normalized(): void
    {
        $result = $this->validator()->validate('loop.cortex.snapshot.v1', $this->snapshotPayload());

        $this->assertTrue($result->ok());
        $this->assertSame([], $result->errors());
        $this->assertSame(
            ['schema_id', 'snapshot_id', 'scope_root', 'built_at_unix', 'inventory', 'edges', 'orphans'],
            array_keys($result->normalized()),
        );
    }

    public function test_missing_required_field_returns_structured_error(): void
    {
        $payload = $this->snapshotPayload();
        unset($payload['orphans']);

        $result = $this->validator()->validate('loop.cortex.snapshot.v1', $payload);

        $this->assertFalse($result->ok());
        $offending = array_column($result->errors(), 'field');
        $this->assertContains('orphans', $offending);
        $kinds = array_column($result->errors(), 'kind');
        $this->assertContains('missing_required', $kinds);
    }

    public function test_wrong_type_returns_type_mismatch(): void
    {
        $payload = $this->snapshotPayload(['edges' => 'not_an_array']);

        $result = $this->validator()->validate('loop.cortex.snapshot.v1', $payload);

        $this->assertFalse($result->ok());
        $offending = array_filter($result->errors(), static fn (array $e): bool => $e['field'] === 'edges' && $e['kind'] === 'type_mismatch');
        $this->assertNotEmpty($offending);
    }

    public function test_extra_field_returns_extra_field_error(): void
    {
        $payload = $this->snapshotPayload(['unexpected_extra' => 'oops']);

        $result = $this->validator()->validate('loop.cortex.snapshot.v1', $payload);

        $this->assertFalse($result->ok());
        $offending = array_filter($result->errors(), static fn (array $e): bool => $e['field'] === 'unexpected_extra' && $e['kind'] === 'extra_field');
        $this->assertNotEmpty($offending);
    }

    public function test_unknown_schema_id_returns_unknown_schema_id_error(): void
    {
        $result = $this->validator()->validate('not.a.real.schema.v99', []);

        $this->assertFalse($result->ok());
        $err = $result->errors()[0] ?? null;
        $this->assertNotNull($err);
        $this->assertSame('schema_id', $err['field']);
        $this->assertSame('unknown_schema_id', $err['kind']);
    }

    public function test_nullable_contract_allows_null_only_when_field_is_nullable(): void
    {
        // maestro.loop.outcome.v1 has 'error' nullable=true; setting null should NOT fail.
        $payload = [
            'schema_id' => 'maestro.loop.outcome.v1',
            'task_packet_id' => 'pkt-1',
            'outcome' => 'success',
            'verified_chain' => true,
            'completed_at_unix' => 1700000000,
            'evidence_refs' => ['ev-1'],
            'error' => null,
        ];
        $result = $this->validator()->validate('maestro.loop.outcome.v1', $payload);
        $this->assertTrue($result->ok(), 'null on nullable field must be accepted');

        // Setting a non-nullable field to null must fail.
        $payload['task_packet_id'] = null;
        $result = $this->validator()->validate('maestro.loop.outcome.v1', $payload);
        $this->assertFalse($result->ok());
        $kinds = array_column($result->errors(), 'kind');
        $this->assertContains('null_not_allowed', $kinds);
    }

    public function test_validator_is_deterministic_same_payload_yields_byte_identical_result(): void
    {
        $payload = $this->snapshotPayload();
        $a = $this->validator()->validate('loop.cortex.snapshot.v1', $payload);
        $b = $this->validator()->validate('loop.cortex.snapshot.v1', $payload);
        $this->assertSame(json_encode($a->normalized()), json_encode($b->normalized()));
    }

    public function test_validator_source_is_pure_no_side_effects(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/AutonomousEvolution/WireFormat/AtlasLoopInterPrimitiveMessageValidator.php'));
        foreach (['file_put_contents', 'fopen', 'shell_exec', 'proc_open', 'DB::', 'Http::', 'curl_'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "validator must NOT contain {$forbidden}");
        }
    }
}
