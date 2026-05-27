<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\AtlasForge\AtlasForgeParallelDurableCoordinatorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusForgeHandoffBuilderService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOperatorDecisionService;
use Tests\TestCase;

/**
 * AP-729 contract tests for the Area Focus Forge handoff packet builder.
 *
 * Pure, deterministic declaration of an obra candidate targeting the real Forge
 * runtime — never executes Forge, spawns agents, creates branches or mutates repos.
 */
class AreaFocusForgeHandoffBuilderServiceTest extends TestCase
{
    private function service(): AreaFocusForgeHandoffBuilderService
    {
        return app(AreaFocusForgeHandoffBuilderService::class);
    }

    /**
     * @param  array<string,mixed>  $over
     * @return array<string,mixed>
     */
    private function workOrder(string $hash, array $over = []): array
    {
        return array_merge([
            'schema_version' => 'atlas.software_company_stewardship.area_work_order.v1',
            'work_order_id' => 'awo_'.$hash,
            'work_order_hash' => 'sha256:wo'.$hash,
            'route' => AreaFocusForgeHandoffBuilderService::ROUTE_FORGE,
            'title' => 'cross-system obra '.$hash,
            'recommended_action' => 'governed forge slice',
            'area_id' => 'agentic_engineering_os',
            'evidence_refs' => ['ev:'.$hash],
        ], $over);
    }

    /**
     * @param  array<string,mixed>  $over
     * @return array<string,mixed>
     */
    private function acceptReceipt(string $hash, array $over = []): array
    {
        return array_merge([
            'schema_version' => 'atlas.software_company_stewardship.area_focus_operator_decision_receipt.v1',
            'decision' => AreaFocusOperatorDecisionService::DECISION_ACCEPT,
            'decision_id' => 'afod_'.$hash,
            'decision_hash' => 'sha256:dec'.$hash,
            'work_order_id' => 'awo_'.$hash,
            'operator_actor' => 'vitor',
        ], $over);
    }

    /**
     * @param  array<string,mixed>  $over
     * @return array<string,mixed>
     */
    private function evidencePack(string $hash, array $over = []): array
    {
        return array_merge([
            'schema_version' => 'atlas.software_company_stewardship.area_focus_evidence_pack.v1',
            'pack_id' => 'afep_'.$hash,
            'pack_hash' => 'sha256:pack'.$hash,
            'cycle_id' => 'afc_'.$hash,
        ], $over);
    }

    /**
     * @param  array<string,mixed>  $over
     * @return array<string,mixed>
     */
    private function validInput(string $hash, array $over = []): array
    {
        return array_merge([
            'work_order' => $this->workOrder($hash),
            'operator_receipt' => $this->acceptReceipt($hash),
            'evidence_pack' => $this->evidencePack($hash),
            'scope' => ['app/Services/Example.php'],
            'area_id' => 'agentic_engineering_os',
        ], $over);
    }

    public function test_builds_ready_handoff_for_route_forge_with_obra_candidate(): void
    {
        $packet = $this->service()->build($this->validInput('ready1'));

        $this->assertSame(AreaFocusForgeHandoffBuilderService::HANDOFF_SCHEMA, $packet['schema_version']);
        $this->assertSame(AreaFocusForgeHandoffBuilderService::STATUS_READY, $packet['status']);
        $this->assertSame('AP-729', $packet['ap_contract']);
        $this->assertStringStartsWith('affh_', $packet['handoff_id']);
        $this->assertStringStartsWith('sha256:', $packet['handoff_hash']);
        $this->assertSame(AreaFocusForgeHandoffBuilderService::FORGE_TARGET_RUNTIME, $packet['target_runtime']);
        $this->assertSame(AtlasForgeParallelDurableCoordinatorService::SCHEMA_VERSION, $packet['target_runtime']);

        $obra = $packet['obra_candidate'];
        $this->assertSame(AreaFocusForgeHandoffBuilderService::OBRA_CANDIDATE_SCHEMA, $obra['schema_version']);
        $this->assertSame('long_horizon_cross_system', $obra['obra_kind']);
        $this->assertSame(AtlasForgeParallelDurableCoordinatorService::class, $obra['target_coordinator']);
        $this->assertFalse($obra['agents_spawned']);
        $this->assertFalse($obra['forge_executed']);
        $this->assertNotEmpty($packet['evidence_refs']);
        $this->assertNotEmpty($packet['acceptance_gates']);
        $this->assertNotEmpty($packet['rollback_plan']);
        $this->assertSame(['app/Services/Example.php'], $packet['scope']['allowed_paths']);
    }

    public function test_handoff_hash_and_id_are_deterministic(): void
    {
        $first = $this->service()->build($this->validInput('det1'));
        $second = $this->service()->build($this->validInput('det1'));

        $this->assertSame($first['handoff_hash'], $second['handoff_hash']);
        $this->assertSame($first['handoff_id'], $second['handoff_id']);
    }

    public function test_claim_policy_never_executes_or_mutates(): void
    {
        $packet = $this->service()->build($this->validInput('inv1'));
        $policy = $packet['claim_policy'];

        $this->assertTrue($policy['read_only']);
        $this->assertFalse($policy['forge_executed']);
        $this->assertFalse($policy['agents_spawned']);
        $this->assertFalse($policy['branch_created']);
        $this->assertFalse($policy['dispatched']);
        $this->assertFalse($policy['mutates_target_repo']);
        $this->assertFalse($policy['merge_performed']);
        $this->assertFalse($policy['deploy_performed']);
        $this->assertFalse($policy['push_performed']);
        $this->assertFalse($policy['secrets_accessed']);
        $this->assertFalse($policy['destructive_change']);
        $this->assertFalse($policy['parallel_forge_created']);
    }

    public function test_blocks_without_work_order(): void
    {
        $packet = $this->service()->build(['area_id' => 'agentic_engineering_os']);

        $this->assertSame(AreaFocusForgeHandoffBuilderService::STATUS_BLOCKED, $packet['status']);
        $this->assertSame(AreaFocusForgeHandoffBuilderService::BLOCK_WORK_ORDER_REQUIRED, $packet['reason']);
        $this->assertNull($packet['obra_candidate']);
    }

    public function test_blocks_non_forge_route(): void
    {
        $packet = $this->service()->build($this->validInput('dev1', [
            'work_order' => $this->workOrder('dev1', ['route' => 'atlas_dev']),
        ]));

        $this->assertSame(AreaFocusForgeHandoffBuilderService::STATUS_BLOCKED, $packet['status']);
        $this->assertSame(AreaFocusForgeHandoffBuilderService::BLOCK_ROUTE_NOT_FORGE, $packet['reason']);
    }

    public function test_blocks_missing_or_non_accept_operator_receipt(): void
    {
        $missing = $this->service()->build($this->validInput('r0', ['operator_receipt' => null]));
        $this->assertSame(AreaFocusForgeHandoffBuilderService::BLOCK_MISSING_OPERATOR_ACCEPT, $missing['reason']);

        $reject = $this->service()->build($this->validInput('r1', [
            'operator_receipt' => $this->acceptReceipt('r1', ['decision' => 'reject']),
        ]));
        $this->assertSame(AreaFocusForgeHandoffBuilderService::BLOCK_MISSING_OPERATOR_ACCEPT, $reject['reason']);

        $mismatch = $this->service()->build($this->validInput('r2', [
            'operator_receipt' => $this->acceptReceipt('r2', ['work_order_id' => 'awo_other']),
        ]));
        $this->assertSame(AreaFocusForgeHandoffBuilderService::BLOCK_MISSING_OPERATOR_ACCEPT, $mismatch['reason']);
    }

    public function test_blocks_missing_evidence_pack(): void
    {
        $packet = $this->service()->build($this->validInput('ev1', [
            'evidence_pack' => null,
            'work_order' => $this->workOrder('ev1', ['evidence_refs' => []]),
        ]));

        $this->assertSame(AreaFocusForgeHandoffBuilderService::BLOCK_MISSING_EVIDENCE, $packet['reason']);
    }

    public function test_blocks_empty_scope(): void
    {
        $packet = $this->service()->build($this->validInput('sc1', [
            'scope' => [],
            'work_order' => $this->workOrder('sc1', ['affected_paths' => []]),
        ]));

        $this->assertSame(AreaFocusForgeHandoffBuilderService::BLOCK_MISSING_SCOPE, $packet['reason']);
    }

    public function test_scope_falls_back_to_work_order_affected_paths(): void
    {
        $packet = $this->service()->build($this->validInput('fb1', [
            'scope' => null,
            'work_order' => $this->workOrder('fb1', ['affected_paths' => ['docs/ap/foo.md']]),
        ]));

        $this->assertSame(AreaFocusForgeHandoffBuilderService::STATUS_READY, $packet['status']);
        $this->assertSame(['docs/ap/foo.md'], $packet['scope']['allowed_paths']);
    }

    public function test_blocks_unsafe_requested_actions_and_flags(): void
    {
        foreach (['merge', 'deploy', 'push', 'secrets'] as $action) {
            $packet = $this->service()->build($this->validInput('u_'.$action, [
                'requested_actions' => [$action],
            ]));
            $this->assertSame(AreaFocusForgeHandoffBuilderService::BLOCK_UNSAFE_REQUEST, $packet['reason'], "action {$action}");
        }

        $flagged = $this->service()->build($this->validInput('u_flag', ['requires_merge' => true]));
        $this->assertSame(AreaFocusForgeHandoffBuilderService::BLOCK_UNSAFE_REQUEST, $flagged['reason']);
    }
}
