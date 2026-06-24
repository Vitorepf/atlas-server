<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Telemetry;

use App\Services\Ai\AutonomousEvolution\Telemetry\AtlasLoopTelemetryFactSchemaRegistry;
use InvalidArgumentException;
use Tests\TestCase;

final class AtlasLoopTelemetryFactSchemaRegistryTest extends TestCase
{
    public function test_each_canonical_kind_has_a_frozen_v1_schema(): void
    {
        $registry = new AtlasLoopTelemetryFactSchemaRegistry;

        foreach (['claim', 'lease', 'serve', 'report', 'merge'] as $kind) {
            $schema = $registry->schemaFor($kind);

            $this->assertSame('atlas.loop.telemetry.'.$kind.'.v1', $schema['schema_id']);
            $this->assertSame(
                ['cycle_id', 'kind', 'occurred_at_iso', 'scope', 'schema_version', 'payload'],
                $schema['required_keys']
            );
            $this->assertSame(
                ['score', 'rank', 'grade', 'quality', 'judgement'],
                $schema['forbidden_keys']
            );
        }
    }

    public function test_validate_rejects_forbidden_keys_and_missing_required_and_wrong_type(): void
    {
        $registry = new AtlasLoopTelemetryFactSchemaRegistry;

        $result = $registry->validate('claim', [
            'cycle_id' => 'cycle-1',
            'kind' => 'claim',
            'occurred_at_iso' => '2026-06-24T00:00:00+00:00',
            'scope' => 'loop',
            'schema_version' => 'atlas.loop.telemetry.claim.v1',
            'payload' => ['score' => 10],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertContains('forbidden-key-present:score', $result['reasons']);

        $missing = $registry->validate('lease', [
            'cycle_id' => 'cycle-1',
            'kind' => 'lease',
            'occurred_at_iso' => '2026-06-24T00:00:00+00:00',
            'scope' => 'loop',
        ]);
        $this->assertFalse($missing['ok']);
        $this->assertContains('missing-required:schema_version', $missing['reasons']);
        $this->assertContains('missing-required:payload', $missing['reasons']);

        $wrongType = $registry->validate('serve', [
            'cycle_id' => 'cycle-1',
            'kind' => 'serve',
            'occurred_at_iso' => '2026-06-24T00:00:00+00:00',
            'scope' => 'loop',
            'schema_version' => 'atlas.loop.telemetry.serve.v1',
            'payload' => 'not-an-array',
        ]);
        $this->assertFalse($wrongType['ok']);
        $this->assertContains('wrong-type:payload', $wrongType['reasons']);
    }

    public function test_unknown_kind_is_fail_closed_and_returned_schema_is_immutable(): void
    {
        $registry = new AtlasLoopTelemetryFactSchemaRegistry;

        $schema = $registry->schemaFor('report');
        $schema['forbidden_keys'][] = 'mutated';
        $schema['required_keys'][] = 'tampered';

        $fresh = $registry->schemaFor('report');
        $this->assertSame(
            ['score', 'rank', 'grade', 'quality', 'judgement'],
            $fresh['forbidden_keys']
        );
        $this->assertSame(
            ['cycle_id', 'kind', 'occurred_at_iso', 'scope', 'schema_version', 'payload'],
            $fresh['required_keys']
        );

        $this->expectException(InvalidArgumentException::class);
        $registry->schemaFor('unknown');
    }
}
