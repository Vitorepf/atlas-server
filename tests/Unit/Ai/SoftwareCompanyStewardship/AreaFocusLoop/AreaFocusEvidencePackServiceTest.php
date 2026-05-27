<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCycleRecorderService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusEvidencePackService;
use Tests\TestCase;

/**
 * Evidence pack projection tests (AP-720): completeness, determinism, inbox
 * readiness and no-secrets. Pure projection over a recorded cycle — no I/O.
 */
class AreaFocusEvidencePackServiceTest extends TestCase
{
    private function service(): AreaFocusEvidencePackService
    {
        return app(AreaFocusEvidencePackService::class);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function cycle(array $overrides = []): array
    {
        return array_merge([
            'schema_version' => AreaFocusCycleRecorderService::CYCLE_SCHEMA,
            'cycle_id' => 'afc_0123456789abcdef',
            'area_id' => 'agentic_engineering_os',
            'report_status' => 'ready',
            'report_hash' => 'sha256:'.str_repeat('a', 64),
            'finding_count' => 2,
            'work_order_count' => 2,
            'inbox_decision_count' => 1,
            'input_hash' => 'sha256:'.str_repeat('1', 64),
            'findings_hash' => 'sha256:'.str_repeat('f', 64),
            'inbox_hash' => 'sha256:'.str_repeat('b', 64),
            'work_orders_hash' => 'sha256:'.str_repeat('c', 64),
            'cycle_hash' => 'sha256:'.str_repeat('d', 64),
            'routing_summary' => ['atlas_dev' => 2],
            'validation_refs' => [['ref' => 'php artisan test', 'status' => 'declared', 'executed' => 'false']],
            'claim_policy' => ['read_only_over_repo' => true, 'secrets_in_payload' => false],
            'generated_at' => '2026-05-26T00:00:00+00:00',
        ], $overrides);
    }

    public function test_complete_pack_from_full_cycle(): void
    {
        $pack = $this->service()->build($this->cycle());

        $this->assertSame(AreaFocusEvidencePackService::PACK_SCHEMA, $pack['schema_version']);
        $this->assertStringStartsWith('afep_', $pack['pack_id']);
        $this->assertSame('afc_0123456789abcdef', $pack['cycle_id']);
        $this->assertTrue($pack['completeness']['complete']);
        $this->assertSame([], $pack['completeness']['missing_fields']);
        $this->assertTrue($pack['morning_inbox_ready']);
        $this->assertStringStartsWith('sha256:', $pack['pack_hash']);
        $this->assertSame('sha256:'.str_repeat('f', 64), $pack['hashes']['findings_hash']);
    }

    public function test_incomplete_when_required_field_missing(): void
    {
        $cycle = $this->cycle();
        unset($cycle['findings_hash']);

        $pack = $this->service()->build($cycle);

        $this->assertFalse($pack['completeness']['complete']);
        $this->assertContains('findings_hash', $pack['completeness']['missing_fields']);
        $this->assertFalse($pack['morning_inbox_ready']);
    }

    public function test_blocked_cycle_is_not_inbox_ready_even_if_complete(): void
    {
        $pack = $this->service()->build($this->cycle(['report_status' => 'blocked']));

        $this->assertTrue($pack['completeness']['complete']);
        $this->assertFalse($pack['morning_inbox_ready']);
    }

    public function test_pack_hash_is_deterministic(): void
    {
        $cycle = $this->cycle();
        $a = $this->service()->build($cycle);
        $b = $this->service()->build($cycle);

        $this->assertSame($a['pack_hash'], $b['pack_hash']);
        $this->assertSame($a['pack_id'], $b['pack_id']);
    }

    public function test_pack_id_derived_from_cycle_id(): void
    {
        $one = $this->service()->build($this->cycle(['cycle_id' => 'afc_aaaa000000000000']));
        $two = $this->service()->build($this->cycle(['cycle_id' => 'afc_bbbb000000000000']));

        $this->assertNotSame($one['pack_id'], $two['pack_id']);
    }

    public function test_claim_policy_enforces_no_secrets_and_projection_only(): void
    {
        $pack = $this->service()->build($this->cycle());

        $this->assertTrue($pack['claim_policy']['pack_is_projection_only']);
        $this->assertFalse($pack['claim_policy']['secrets_in_payload']);
        $this->assertFalse($pack['claim_policy']['writes_state']);
        $this->assertFalse($pack['claim_policy']['mutates_target_repo']);
        $this->assertTrue($pack['claim_policy']['operator_review_required']);
    }
}
