<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Quaternity;

use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\AtlasLoopOperatorIntentSchemaRegistry;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\SchemaViolation;
use Tests\TestCase;

final class AtlasLoopOperatorIntentSchemaRegistryTest extends TestCase
{
    private function validPayload(): array
    {
        return [
            'schema' => 'operator.intent.v1',
            'id' => 'abc',
            'ts' => 1700000000,
            'verb' => 'FOCUS',
            'object' => 'loop',
            'constraints' => ['sem mexer em marketing'],
            'source_message_id' => 'msg-1',
            'source' => 'chat',
        ];
    }

    public function test_valid_payload_returns_ok(): void
    {
        $result = (new AtlasLoopOperatorIntentSchemaRegistry)->validate($this->validPayload());

        $this->assertTrue($result->ok);
        $this->assertSame([], $result->violations);
        $this->assertSame('operator.intent.v1', $result->version);
    }

    public function test_missing_verb_returns_missing_field_violation(): void
    {
        $payload = $this->validPayload();
        unset($payload['verb']);

        $result = (new AtlasLoopOperatorIntentSchemaRegistry)->validate($payload);

        $this->assertFalse($result->ok);
        $kinds = array_map(static fn (SchemaViolation $v): array => [$v->kind, $v->field], $result->violations);
        $this->assertContains([SchemaViolation::KIND_MISSING_FIELD, 'verb'], $kinds);
    }

    public function test_unknown_field_returns_unknown_field_violation_in_strict_mode(): void
    {
        $payload = $this->validPayload();
        $payload['mood'] = 'curious';

        $result = (new AtlasLoopOperatorIntentSchemaRegistry)->validate($payload);

        $this->assertFalse($result->ok);
        $kinds = array_map(static fn (SchemaViolation $v): array => [$v->kind, $v->field], $result->violations);
        $this->assertContains([SchemaViolation::KIND_UNKNOWN_FIELD, 'mood'], $kinds);
    }

    public function test_invalid_verb_returns_enum_violation(): void
    {
        $payload = $this->validPayload();
        $payload['verb'] = 'BOGUS';

        $result = (new AtlasLoopOperatorIntentSchemaRegistry)->validate($payload);

        $this->assertFalse($result->ok);
        $kinds = array_map(static fn (SchemaViolation $v): array => [$v->kind, $v->field], $result->violations);
        $this->assertContains([SchemaViolation::KIND_ENUM_VIOLATION, 'verb'], $kinds);
    }

    public function test_invalid_source_returns_enum_violation(): void
    {
        $payload = $this->validPayload();
        $payload['source'] = 'sms';

        $result = (new AtlasLoopOperatorIntentSchemaRegistry)->validate($payload);

        $this->assertFalse($result->ok);
        $kinds = array_map(static fn (SchemaViolation $v): array => [$v->kind, $v->field], $result->violations);
        $this->assertContains([SchemaViolation::KIND_ENUM_VIOLATION, 'source'], $kinds);
    }

    public function test_declared_optional_field_is_accepted_not_unknown(): void
    {
        // Regression: `array_keys(required) + array_keys(optional)` dropped optional field NAMES (int-key
        // union), so a declared-optional field was wrongly rejected as KIND_UNKNOWN_FIELD.
        $registry = new AtlasLoopOperatorIntentSchemaRegistry([
            'operator.intent.v2' => [
                'required' => ['schema' => 'string'],
                'optional' => ['note' => 'string'],
                'source' => ['chat'],
                'verbs' => ['FOCUS'],
            ],
        ]);

        $result = $registry->validate(['schema' => 'operator.intent.v2', 'note' => 'an optional note'], 'operator.intent.v2');

        $this->assertTrue($result->ok, 'a payload carrying a declared-optional field must validate');
        $unknownFields = array_map(
            static fn (SchemaViolation $v): string => $v->field,
            array_filter($result->violations, static fn (SchemaViolation $v): bool => $v->kind === SchemaViolation::KIND_UNKNOWN_FIELD),
        );
        $this->assertNotContains('note', $unknownFields, 'a declared-optional field must not be a KIND_UNKNOWN_FIELD violation');
    }

    public function test_current_version_and_versioned_list(): void
    {
        $registry = new AtlasLoopOperatorIntentSchemaRegistry;

        $this->assertSame('operator.intent.v1', $registry->currentVersion());
        $this->assertContains('operator.intent.v1', $registry->versioned());
    }

    public function test_registry_is_singleton_bound_in_provider(): void
    {
        $a = $this->app->make(AtlasLoopOperatorIntentSchemaRegistry::class);
        $b = $this->app->make(AtlasLoopOperatorIntentSchemaRegistry::class);

        $this->assertSame($a, $b, 'extractor/ledger/CLI must all read the SAME singleton');
    }
}
