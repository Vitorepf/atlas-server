<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\PacketEvolution;

use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\AtlasMaestroPacketSchemaVersioning;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroPacketSchemaVersioningTest extends TestCase
{
    private function registry(array $extraVersions = [], ?callable $currentResolver = null): AtlasMaestroPacketSchemaVersioning
    {
        return new AtlasMaestroPacketSchemaVersioning($extraVersions, $currentResolver ?? static fn (): ?string => null);
    }

    // ── AC2: current / latest / supported / deprecated / unknown schema states ──

    public function test_schema_state_current_for_the_default_active_version(): void
    {
        $registry = $this->registry();

        $this->assertSame('current', $registry->schemaState(AtlasMaestroPacketSchemaVersioning::CANONICAL_V1));
    }

    public function test_schema_state_supported_for_non_current_active_or_preview(): void
    {
        $preview = ['id' => 'atlas.v2-preview', 'version' => 2, 'status' => AtlasMaestroPacketSchemaVersioning::STATUS_PREVIEW, 'required_fields' => [], 'additive_fields' => [], 'removed_fields' => [], 'introduced_at' => '', 'successor' => null];
        $registry = $this->registry([$preview]);

        $this->assertSame('supported', $registry->schemaState('atlas.v2-preview'));
    }

    public function test_schema_state_deprecated(): void
    {
        $deprecated = ['id' => 'atlas.v0-deprecated', 'version' => 0, 'status' => AtlasMaestroPacketSchemaVersioning::STATUS_DEPRECATED, 'required_fields' => [], 'additive_fields' => [], 'removed_fields' => [], 'introduced_at' => '', 'successor' => null];
        $registry = $this->registry([$deprecated]);

        $this->assertSame('deprecated', $registry->schemaState('atlas.v0-deprecated'));
    }

    public function test_schema_state_unknown_for_retired_version(): void
    {
        $retired = ['id' => 'atlas.v0-retired', 'version' => 0, 'status' => AtlasMaestroPacketSchemaVersioning::STATUS_RETIRED, 'required_fields' => [], 'additive_fields' => [], 'removed_fields' => [], 'introduced_at' => '', 'successor' => null];
        $registry = $this->registry([$retired]);

        $this->assertSame('unknown', $registry->schemaState('atlas.v0-retired'));
    }

    public function test_schema_state_unknown_for_unregistered_version(): void
    {
        $registry = $this->registry();

        $this->assertSame('unknown', $registry->schemaState('not.a.real.version.v99'));
    }

    public function test_schema_state_is_deterministic(): void
    {
        $registry = $this->registry();

        $this->assertSame(
            $registry->schemaState(AtlasMaestroPacketSchemaVersioning::CANONICAL_V1),
            $registry->schemaState(AtlasMaestroPacketSchemaVersioning::CANONICAL_V1),
        );
    }

    public function test_current_and_latest_expose_version_rows(): void
    {
        $registry = $this->registry();

        $this->assertSame(AtlasMaestroPacketSchemaVersioning::CANONICAL_V1, $registry->current()['id']);
        $this->assertSame(AtlasMaestroPacketSchemaVersioning::CANONICAL_V1, $registry->latest()['id']);
    }

    // ── AC3: successor paths for deprecated-but-migratable, none for unknown ──

    public function test_successor_of_resolves_for_deprecated_migratable_schema(): void
    {
        $v2 = ['id' => 'v2-id', 'version' => 2, 'status' => 'preview', 'required_fields' => [], 'additive_fields' => [], 'removed_fields' => [], 'introduced_at' => '', 'successor' => null];
        $v1WithSuccessor = ['id' => 'v1-bridge', 'version' => 1, 'status' => 'deprecated', 'required_fields' => [], 'additive_fields' => [], 'removed_fields' => [], 'introduced_at' => '', 'successor' => 'v2-id'];

        $registry = $this->registry([$v1WithSuccessor, $v2]);
        $successor = $registry->successorOf('v1-bridge');

        $this->assertNotNull($successor);
        $this->assertSame('v2-id', $successor['id']);
    }

    public function test_successor_of_returns_null_for_unknown_schema(): void
    {
        $registry = $this->registry();

        $this->assertNull($registry->successorOf('not.a.real.version.v99'));
    }

    public function test_successor_of_returns_null_when_no_successor_declared(): void
    {
        $registry = $this->registry();

        $this->assertNull($registry->successorOf(AtlasMaestroPacketSchemaVersioning::CANONICAL_V1));
    }

    // ── AC4: describes required fields per version + detects missing ones ──

    public function test_missing_required_fields_empty_when_packet_has_everything(): void
    {
        $registry = $this->registry();

        $missing = $registry->missingRequiredFields(AtlasMaestroPacketSchemaVersioning::CANONICAL_V1, [
            'task_packet_id' => 'task-1',
            'objective' => 'do the thing',
            'allowed_files' => ['app/Foo.php'],
            'acceptance_criteria' => ['exits 0'],
            'required_evidence' => ['tests_or_gates_result'],
        ]);

        $this->assertSame([], $missing);
    }

    public function test_missing_required_fields_names_absent_fields(): void
    {
        $registry = $this->registry();

        $missing = $registry->missingRequiredFields(AtlasMaestroPacketSchemaVersioning::CANONICAL_V1, [
            'task_packet_id' => 'task-1',
            'objective' => 'do the thing',
        ]);

        $this->assertContains('allowed_files', $missing);
        $this->assertContains('acceptance_criteria', $missing);
        $this->assertContains('required_evidence', $missing);
        $this->assertNotContains('task_packet_id', $missing);
    }

    public function test_missing_required_fields_treats_empty_string_and_empty_array_as_missing(): void
    {
        $registry = $this->registry();

        $missing = $registry->missingRequiredFields(AtlasMaestroPacketSchemaVersioning::CANONICAL_V1, [
            'task_packet_id' => '',
            'objective' => 'do the thing',
            'allowed_files' => [],
            'acceptance_criteria' => ['exits 0'],
            'required_evidence' => ['tests_or_gates_result'],
        ]);

        $this->assertContains('task_packet_id', $missing);
        $this->assertContains('allowed_files', $missing);
    }

    public function test_missing_required_fields_returns_all_required_fields_for_unknown_version(): void
    {
        $registry = $this->registry();

        $missing = $registry->missingRequiredFields('not.a.real.version.v99', ['task_packet_id' => 'x']);

        $this->assertSame([], $missing);
    }
}
