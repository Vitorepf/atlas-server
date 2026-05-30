<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Forge\Intelligence;

use App\Models\AiForgeIntake;
use App\Models\AiForgeWorkPacket;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Forge\Intelligence\ForgeProviderProjectionService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ForgeProviderProjectionServiceTest extends TestCase
{
    private ForgeProviderProjectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ForgeProviderProjectionService();
    }

    #[Test]
    public function build_returns_schema_version(): void
    {
        $packet = $this->createWorkPacket();
        $intake = $this->createIntake();
        $result = $this->service->build($packet, $intake, [], [], []);

        $this->assertArrayHasKey('schema_version', $result);
        $this->assertSame('atlas.forge.provider_projection.v1', $result['schema_version']);
    }

    #[Test]
    public function build_returns_provider_safe_true(): void
    {
        $packet = $this->createWorkPacket();
        $result = $this->service->build($packet, null, [], [], []);

        $this->assertArrayHasKey('provider_safe', $result);
        $this->assertTrue($result['provider_safe']);
    }

    #[Test]
    public function build_returns_surface_and_flow(): void
    {
        $packet = $this->createWorkPacket();
        $result = $this->service->build($packet, null, [], [], []);

        $this->assertSame('atlas_forge', $result['surface']);
        $this->assertSame('programming.forge', $result['flow']);
    }

    #[Test]
    public function build_includes_packet_id_as_string(): void
    {
        $packet = $this->createWorkPacket();
        $result = $this->service->build($packet, null, [], [], []);

        $this->assertArrayHasKey('packet_id', $result);
        $this->assertIsString($result['packet_id']);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $result['packet_id']);
    }

    #[Test]
    public function build_includes_obrasection_from_intake(): void
    {
        $packet = $this->createWorkPacket();
        $intake = $this->createIntake();
        $result = $this->service->build($packet, $intake, [], [], []);

        $this->assertArrayHasKey('prompt_sections', $result);
        $this->assertArrayHasKey('obra', $result['prompt_sections']);
        $this->assertSame('Test Title', $result['prompt_sections']['obra']['title']);
        $this->assertSame('Test Intent', $result['prompt_sections']['obra']['intent']);
    }

    #[Test]
    public function build_handles_null_intake_gracefully(): void
    {
        $packet = $this->createWorkPacket();
        $result = $this->service->build($packet, null, [], [], []);

        $this->assertNull($result['prompt_sections']['obra']['title']);
        $this->assertNull($result['prompt_sections']['obra']['intent']);
        $this->assertSame([], $result['prompt_sections']['obra']['definition_of_done']);
    }

    #[Test]
    public function build_includes_work_packet_section(): void
    {
        $packet = $this->createWorkPacket();
        $result = $this->service->build($packet, null, [], [], []);

        $this->assertArrayHasKey('prompt_sections', $result);
        $this->assertArrayHasKey('work_packet', $result['prompt_sections']);
        $this->assertSame('Test Objective', $result['prompt_sections']['work_packet']['objective']);
        $this->assertSame('test_scope', $result['prompt_sections']['work_packet']['scope']);
    }

    #[Test]
    public function build_includes_bounds_from_scope_guard(): void
    {
        $packet = $this->createWorkPacket();
        $scopeGuard = [
            'status' => 'allowed',
            'allowed_files' => ['src/Controller.php', 'src/Service.php'],
            'forbidden_rules' => ['no_migrations', 'no_config'],
        ];
        $result = $this->service->build($packet, null, [], $scopeGuard, []);

        $this->assertArrayHasKey('bounds', $result['prompt_sections']);
        $this->assertSame(['src/Controller.php', 'src/Service.php'], $result['prompt_sections']['bounds']['allowed_files']);
        $this->assertSame(['no_migrations', 'no_config'], $result['prompt_sections']['bounds']['forbidden_rules']);
    }

    #[Test]
    public function build_includes_verification_from_test_impact(): void
    {
        $packet = $this->createWorkPacket();
        $testImpact = [
            'commands' => ['test:unit', 'test:integration'],
        ];
        $result = $this->service->build($packet, null, [], [], $testImpact);

        $this->assertArrayHasKey('verification', $result['prompt_sections']);
        $this->assertSame(['test:unit', 'test:integration'], $result['prompt_sections']['verification']['focused_tests']);
    }

    #[Test]
    public function build_sendable_is_true_when_no_blocks(): void
    {
        $packet = $this->createWorkPacket();
        $result = $this->service->build($packet, null, [], [], []);

        $this->assertArrayHasKey('sendable', $result);
        $this->assertTrue($result['sendable']);
    }

    #[Test]
    public function build_sendable_is_false_when_context_gate_blocked(): void
    {
        $packet = $this->createWorkPacket();
        $contextGate = ['status' => 'blocked'];
        $result = $this->service->build($packet, null, $contextGate, [], []);

        $this->assertFalse($result['sendable']);
    }

    #[Test]
    public function build_sendable_is_false_when_scope_guard_blocked(): void
    {
        $packet = $this->createWorkPacket();
        $scopeGuard = ['status' => 'blocked'];
        $result = $this->service->build($packet, null, [], $scopeGuard, []);

        $this->assertFalse($result['sendable']);
    }

    #[Test]
    public function build_sendable_is_false_when_both_blocked(): void
    {
        $packet = $this->createWorkPacket();
        $contextGate = ['status' => 'blocked'];
        $scopeGuard = ['status' => 'blocked'];
        $result = $this->service->build($packet, null, $contextGate, $scopeGuard, []);

        $this->assertFalse($result['sendable']);
    }

    #[Test]
    public function build_includes_forbidden_provider_behavior(): void
    {
        $packet = $this->createWorkPacket();
        $result = $this->service->build($packet, null, [], [], []);

        $this->assertArrayHasKey('forbidden_provider_behavior', $result);
        $expected = [
            'do_not_execute_without_evidence',
            'do_not_touch_files_outside_scope',
            'do_not_claim_completion_without_gate_result',
            'do_not_drop_observer_context_or_receipts',
        ];
        $this->assertSame($expected, $result['forbidden_provider_behavior']);
    }

    #[Test]
    public function build_includes_projection_hash(): void
    {
        $packet = $this->createWorkPacket();
        $result = $this->service->build($packet, null, [], [], []);

        $this->assertArrayHasKey('projection_hash', $result);
        $this->assertNotEmpty($result['projection_hash']);
    }

    #[Test]
    public function build_projection_hash_is_deterministic(): void
    {
        $packet = $this->createWorkPacket();
        $result1 = $this->service->build($packet, null, [], [], []);
        $result2 = $this->service->build($packet, null, [], [], []);

        $this->assertSame($result1['projection_hash'], $result2['projection_hash']);
    }

    #[Test]
    public function build_normalizes_definition_of_done_from_intake(): void
    {
        $intake = new AiForgeIntake();
        $intake->obra_title = 'Test';
        $intake->normalized_intent = 'Intent';
        $intake->definition_of_done = ['item1', 'item2'];

        $packet = $this->createWorkPacket();
        $result = $this->service->build($packet, $intake, [], [], []);

        $this->assertSame(['item1', 'item2'], $result['prompt_sections']['obra']['definition_of_done']);
    }

    #[Test]
    public function build_normalizes_empty_arrays_to_indexed(): void
    {
        $packet = $this->createWorkPacket();
        $result = $this->service->build($packet, null, [], [], []);

        $this->assertIsArray($result['prompt_sections']['obra']['definition_of_done']);
        $this->assertIsArray($result['prompt_sections']['bounds']['allowed_files']);
        $this->assertIsArray($result['prompt_sections']['bounds']['forbidden_rules']);
        $this->assertIsArray($result['prompt_sections']['verification']['focused_tests']);
    }

    #[Test]
    public function build_casts_acceptance_criteria_to_indexed_array(): void
    {
        $packet = $this->createWorkPacket();
        $packet->acceptance_criteria = ['first', 'second'];

        $result = $this->service->build($packet, null, [], [], []);

        $this->assertSame(['first', 'second'], $result['prompt_sections']['work_packet']['acceptance_criteria']);
    }

    #[Test]
    public function build_casts_required_evidence_to_indexed_array(): void
    {
        $packet = $this->createWorkPacket();
        $packet->required_evidence = ['evidence1', 'evidence2'];

        $result = $this->service->build($packet, null, [], [], []);

        $this->assertSame(['evidence1', 'evidence2'], $result['prompt_sections']['work_packet']['required_evidence']);
    }

    private function createWorkPacket(): AiForgeWorkPacket
    {
        $packet = new AiForgeWorkPacket();
        $packet->packet_id = '11111111-1111-1111-1111-111111111111';
        $packet->objective = 'Test Objective';
        $packet->scope = 'test_scope';
        $packet->acceptance_criteria = null;
        $packet->required_evidence = null;

        return $packet;
    }

    private function createIntake(): AiForgeIntake
    {
        $intake = new AiForgeIntake();
        $intake->obra_title = 'Test Title';
        $intake->normalized_intent = 'Test Intent';
        $intake->definition_of_done = null;

        return $intake;
    }
}