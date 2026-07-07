<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use Tests\TestCase;

/**
 * B2a (fechamento ACOS) — the kit-order (Ordem) fields now travel THROUGH the packet
 * builder instead of being stripped by its closed whitelist, so K3 (the source linter)
 * evaluates a REAL Ordem instead of the fail-safe-pass empty that kept K2/K3/K4 dormant.
 * A non-kit packet stays byte-identical (no new keys), so nothing else changes.
 */
final class AtlasKitOrderBuilderCarryTest extends TestCase
{
    private const SELF = 'app/Services/Ai/SelfConstruction/AgentControlPlaneTaskPacketBuilder.php';

    public function test_builder_carries_kit_fields_for_a_kit_order(): void
    {
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build([
            'objective' => 'wire AtlasFooService for the kit',
            'operator_id' => 'tester',
            'allowed_files' => [self::SELF],
            'scope_in' => [self::SELF],
            'acceptance_criteria' => ['php artisan test passes'],
            'glossary' => ['ATS' => self::SELF],
            'frozen_callers' => [['caller' => 'App\\Foo::bar', 'destination' => 'app/Foo.php']],
            'acceptance_test_ref' => ['path' => 'tests/Feature/Ai/AtlasKitOrderBuilderCarryTest.php', 'hash' => 'abc'],
            'stop_and_return' => ['if X then stop'],
        ]);

        self::assertArrayHasKey('glossary', $packet, 'the builder must carry glossary (not strip it)');
        self::assertArrayHasKey('frozen_callers', $packet);
        self::assertArrayHasKey('acceptance_test_ref', $packet);
        self::assertArrayHasKey('stop_and_return', $packet);
        self::assertSame(self::SELF, $packet['glossary']['ATS']);
        self::assertSame('App\\Foo::bar', $packet['frozen_callers'][0]['caller']);
    }

    public function test_non_kit_packet_carries_no_kit_keys_backward_compat(): void
    {
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build([
            'objective' => 'a plain non-kit task',
            'operator_id' => 'tester',
            'allowed_files' => [self::SELF],
            'scope_in' => [self::SELF],
            'acceptance_criteria' => ['x'],
        ]);

        self::assertArrayNotHasKey('glossary', $packet, 'a non-kit packet stays byte-identical (no new kit keys)');
        self::assertArrayNotHasKey('frozen_callers', $packet);
        self::assertArrayNotHasKey('acceptance_test_ref', $packet);
        self::assertArrayNotHasKey('stop_and_return', $packet);
    }

    public function test_k3_evaluates_a_carried_kit_order_out_of_fail_safe_pass(): void
    {
        // A kit-order whose glossary sigla has NO path — a real K3 deficiency that, before
        // B2a, the builder stripped so the inspector never saw it (fail-safe-pass returned
        // zero deficiencies on an empty Ordem).
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build([
            'objective' => 'wire something with a broken glossary',
            'operator_id' => 'tester',
            'allowed_files' => [self::SELF],
            'scope_in' => [self::SELF],
            'acceptance_criteria' => ['x'],
            'glossary' => ['ATS' => ''], // sigla without a path
        ]);

        // The builder carried it …
        self::assertArrayHasKey('glossary', $packet);

        // … so K3 evaluates it and refuses at the source.
        $result = app(AtlasTaskPacketQualityInspector::class)->inspect($packet);
        self::assertContains('glossary_sigla_without_path', $result['deficiencies'],
            'K3 must evaluate the carried Ordem and flag the missing path (not fail-safe-pass)');
        self::assertFalse($result['self_sufficient'], 'a deficient kit-order is refused at the source');
    }
}
