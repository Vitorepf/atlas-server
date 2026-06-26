<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\Supply\AtlasLoopRefillerOrphanWiringPacketBuilder;
use Tests\TestCase;

class AtlasLoopRefillerOrphanWiringPacketBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['atlas.loop.orphan_wiring_red_grindable_packet_enabled' => true]);
    }

    public function test_payload_with_grindable_packet_merges_atoms_and_commands(): void
    {
        $payload = ['verification_atoms' => [['existing' => true]]];
        $packet = [
            'payload' => [
                'verification_atoms' => [['new' => true]],
                'verifier_refuter_commands' => ['php artisan test:thing'],
                'acceptance' => ['extra_gate' => true],
            ],
        ];

        $result = AtlasLoopRefillerOrphanWiringPacketBuilder::payloadWithGrindablePacket($payload, $packet);

        self::assertTrue($result['red_required']);
        self::assertCount(2, $result['verification_atoms']);
        self::assertContains('php artisan test:thing', $result['verifier_refuter_commands']);
        self::assertArrayHasKey('extra_gate', $result['acceptance']);
        self::assertTrue($result['acceptance']['red_required']);
    }

    public function test_payload_with_grindable_packet_handles_flat_packet(): void
    {
        $payload = [];
        $packet = [
            'verification_atoms' => [['a' => 1]],
            'verifier_refuter_commands' => ['cmd1'],
        ];

        $result = AtlasLoopRefillerOrphanWiringPacketBuilder::payloadWithGrindablePacket($payload, $packet);

        self::assertTrue($result['red_required']);
        self::assertSame([['a' => 1]], $result['verification_atoms']);
        self::assertSame(['cmd1'], $result['verifier_refuter_commands']);
    }

    public function test_payload_with_grindable_packet_preserves_existing_atoms(): void
    {
        $payload = ['verification_atoms' => [['old' => true]]];
        $packet = ['verification_atoms' => [['new' => true]]];

        $result = AtlasLoopRefillerOrphanWiringPacketBuilder::payloadWithGrindablePacket($payload, $packet);

        self::assertCount(2, $result['verification_atoms']);
    }

    public function test_payload_with_grindable_packet_sets_red_required_on_acceptance(): void
    {
        $result = AtlasLoopRefillerOrphanWiringPacketBuilder::payloadWithGrindablePacket([], []);

        self::assertTrue($result['red_required']);
        self::assertArrayHasKey('acceptance', $result);
        self::assertTrue($result['acceptance']['red_required']);
    }

    public function test_orphan_wiring_consumer_path_finds_explicit_consumer_path(): void
    {
        $repoRoot = rtrim(sys_get_temp_dir(), '/');
        $testFile = $repoRoot.'/test_consumer_file.php';
        file_put_contents($testFile, '<?php // test');

        try {
            $result = AtlasLoopRefillerOrphanWiringPacketBuilder::orphanWiringConsumerPath(
                ['consumer_path' => 'test_consumer_file.php'],
                $repoRoot,
                'app/Anchor.php',
                'someMethod',
            );

            self::assertSame('test_consumer_file.php', $result);
        } finally {
            @unlink($testFile);
        }
    }

    public function test_orphan_wiring_consumer_path_finds_caller_path(): void
    {
        $repoRoot = rtrim(sys_get_temp_dir(), '/');
        $testFile = $repoRoot.'/test_caller_file.php';
        file_put_contents($testFile, '<?php // test');

        try {
            $result = AtlasLoopRefillerOrphanWiringPacketBuilder::orphanWiringConsumerPath(
                ['caller_path' => 'test_caller_file.php'],
                $repoRoot,
                'app/Anchor.php',
                'someMethod',
            );

            self::assertSame('test_caller_file.php', $result);
        } finally {
            @unlink($testFile);
        }
    }

    public function test_orphan_wiring_consumer_path_ignores_anchor(): void
    {
        $repoRoot = rtrim(sys_get_temp_dir(), '/');
        $testFile = $repoRoot.'/anchor.php';
        file_put_contents($testFile, '<?php // test');

        try {
            $result = AtlasLoopRefillerOrphanWiringPacketBuilder::orphanWiringConsumerPath(
                ['consumer_path' => 'anchor.php'],
                $repoRoot,
                'anchor.php',
                'someMethod',
            );

            // Should NOT return the anchor — falls through to directory scan or sibling
            self::assertNull($result);
        } finally {
            @unlink($testFile);
        }
    }

    public function test_orphan_wiring_consumer_path_finds_sibling_test(): void
    {
        $repoRoot = rtrim(sys_get_temp_dir(), '/');
        $testFile = $repoRoot.'/SiblingTest.php';
        file_put_contents($testFile, '<?php // test');

        try {
            $result = AtlasLoopRefillerOrphanWiringPacketBuilder::orphanWiringConsumerPath(
                ['sibling_test' => 'SiblingTest.php'],
                $repoRoot,
                'app/Anchor.php',
                'nonExistentMethod',
            );

            self::assertSame('SiblingTest.php', $result);
        } finally {
            @unlink($testFile);
        }
    }

    public function test_orphan_wiring_consumer_path_returns_null_when_nothing_found(): void
    {
        $result = AtlasLoopRefillerOrphanWiringPacketBuilder::orphanWiringConsumerPath(
            [],
            rtrim(sys_get_temp_dir(), '/'),
            'app/NonExistent.php',
            'nonExistentMethod',
        );

        self::assertNull($result);
    }

    public function test_with_orphan_wiring_returns_spec_unchanged_when_disabled(): void
    {
        config(['atlas.loop.orphan_wiring_red_grindable_packet_enabled' => false]);
        $spec = ['payload' => ['orphan_fqcn' => 'Foo', 'public_methods' => ['bar']]];

        $result = AtlasLoopRefillerOrphanWiringPacketBuilder::withOrphanWiringRedGrindablePacket($spec, 'app/Anchor.php', '/tmp');

        self::assertSame($spec, $result);
    }

    public function test_with_orphan_wiring_returns_spec_unchanged_when_missing_data(): void
    {
        $spec = ['payload' => []];

        $result = AtlasLoopRefillerOrphanWiringPacketBuilder::withOrphanWiringRedGrindablePacket($spec, '', '/tmp');

        self::assertSame($spec, $result);
    }

    public function test_payload_with_grindable_packet_is_deterministic(): void
    {
        $payload = ['existing' => 'data'];
        $packet = ['verification_atoms' => [['x' => 1]]];

        $a = AtlasLoopRefillerOrphanWiringPacketBuilder::payloadWithGrindablePacket($payload, $packet);
        $b = AtlasLoopRefillerOrphanWiringPacketBuilder::payloadWithGrindablePacket($payload, $packet);

        self::assertSame($a, $b);
    }
}
