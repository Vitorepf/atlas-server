<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\ProductMode;

use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeOperationalControlReceiptService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeOperationalControlsReadModelService;
use Tests\TestCase;

final class ProductModeOperationalControlReceiptServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_pmctrl_'.uniqid('', true);
        @mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->tmp.'/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function service(): ProductModeOperationalControlReceiptService
    {
        $service = app(ProductModeOperationalControlReceiptService::class);
        $service->setStorageRootForTesting($this->tmp);

        return $service;
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'area_id' => 'agentic_engineering_os',
            'portfolio_id' => 'atlas_software_company',
            'repo' => 'atlas-server',
            'control_type' => 'repo_authorization',
            'repo_authorization_status' => 'authorized_for_atlas_internal',
            'authorized_repositories' => ['atlas-server'],
            'operator_actor' => 'vitor',
            'decision' => 'accept',
            'risk' => 'medium',
            'rationale' => 'authorize internal atlas product mode controls',
        ], $overrides);
    }

    public function test_records_lists_and_replays_product_mode_control_receipts_through_ap731(): void
    {
        $service = $this->service();
        $record = $service->record($this->input());

        $this->assertSame('AP-731', $record['ap_contract']);
        $this->assertSame('product_mode_control', $record['target_type']);
        $this->assertSame(ProductModeOperationalControlReceiptService::RECEIPT_SCHEMA, $record['target_payload']['schema_version']);
        $this->assertSame('repo_authorization', $record['target_payload']['control_type']);
        $this->assertSame('atlas-server', $record['target_payload']['repo']);
        $this->assertFalse($record['executed']);
        $this->assertFalse($record['provider_invoked']);
        $this->assertFalse($record['product_mode_control_claim_policy']['creates_parallel_ledger']);
        $this->assertFalse($record['product_mode_control_claim_policy']['mutates_target_repo']);

        $list = $service->listReceipts('agentic_engineering_os', 'atlas_software_company');
        $this->assertSame(ProductModeOperationalControlReceiptService::SCHEMA, $list['schema_version']);
        $this->assertSame(1, $list['receipt_count']);
        $this->assertSame($record['decision_id'], $list['receipts'][0]['decision_id']);

        $replayed = $service->replay($record['decision_id']);
        $this->assertSame($record['decision_id'], $replayed['decision_id']);
        $this->assertSame('repo_authorization', $replayed['target_payload']['control_type']);
    }

    public function test_accepted_receipts_project_effective_controls_into_ap754(): void
    {
        $service = $this->service();
        $service->record($this->input([
            'control_type' => 'autonomy_tier',
            'autonomy_tier' => 3,
            'max_allowed_autonomy_tier' => 3,
        ]));
        $service->record($this->input([
            'control_type' => 'budget_policy',
            'cycle_budget' => 7,
            'branch_wip_limit' => 4,
            'provider_call_limit' => 2,
        ]));
        $service->record($this->input([
            'control_type' => 'evidence_policy',
            'evidence_refs' => ['docs_health', 'architecture_validate', 'focused_tests', 'owner_sandbox_runtime_run', 'owner_runtime_result'],
        ]));

        $effective = $service->effectiveControls('agentic_engineering_os', 'atlas_software_company');
        $payload = app(ProductModeOperationalControlsReadModelService::class)
            ->project('agentic_engineering_os', 'atlas_software_company', $effective);

        $this->assertSame(ProductModeOperationalControlsReadModelService::STATUS_READY, $payload['status']);
        $this->assertSame(3, $payload['autonomy_tiers']['current_tier']);
        $this->assertSame(3, $payload['autonomy_tiers']['max_allowed_tier']);
        $this->assertSame(7, $payload['budget_policy']['cycle_budget']);
        $this->assertSame(4, $payload['budget_policy']['branch_wip_limit']);
        $this->assertSame(2, $payload['budget_policy']['provider_call_limit']);
        $this->assertSame('complete', $payload['evidence_inspector']['inspector_status']);
        $this->assertSame('applied', $payload['control_receipts']['status']);
        $this->assertSame(3, $payload['control_receipts']['applied_receipt_count']);
        $this->assertStringStartsWith('sha256:', $payload['control_receipts']['policy_hash']);
    }

    public function test_rejected_receipts_do_not_affect_effective_controls(): void
    {
        $service = $this->service();
        $service->record($this->input([
            'control_type' => 'safety_control',
            'decision' => 'reject',
            'kill_switch' => true,
        ]));

        $effective = $service->effectiveControls('agentic_engineering_os', 'atlas_software_company');

        $this->assertSame([], $effective['control_policy']['policy']);
        $this->assertSame(0, $effective['control_policy']['applied_receipt_count']);
    }

    public function test_secret_like_payload_is_not_persisted(): void
    {
        $service = $this->service();
        $record = $service->record($this->input([
            'control_type' => 'risk_policy',
            'max_risk_without_operator' => 'low',
            'api_secret' => 'never-persist',
            'auth_token' => 'also-never',
        ]));

        $raw = implode("\n", array_map('strval', glob($this->tmp.'/*') ? array_map('file_get_contents', glob($this->tmp.'/*')) : []));

        $this->assertArrayNotHasKey('api_secret', $record['target_payload']);
        $this->assertArrayNotHasKey('auth_token', $record['target_payload']);
        $this->assertStringNotContainsString('never-persist', $raw);
        $this->assertStringNotContainsString('also-never', $raw);
    }

    /** AP-790: materialize product_mode_controls_receipts backlog with receipt-backed pause, kill-switch and autonomy. */
    public function test_product_mode_controls_receipts_backlog_observability_surfaces_receipt_backed_pause_kill_switch_and_autonomy(): void
    {
        $service = $this->service();
        $service->record($this->input([
            'control_type' => 'safety_control',
            'paused' => true,
            'kill_switch' => true,
            'rationale' => 'operator pause before unattended 24h run',
        ]));
        $service->record($this->input([
            'control_type' => 'autonomy_tier',
            'autonomy_tier' => 2,
            'max_allowed_autonomy_tier' => 2,
            'rationale' => 'cap autonomy before AP-790 unattended loop',
        ]));
        $service->record($this->input([
            'control_type' => 'safety_control',
            'decision' => 'reject',
            'paused' => false,
            'kill_switch' => false,
        ]));

        $bridge = $service->productModeControlsReceiptsBacklogObservability(
            'agentic_engineering_os',
            'atlas_software_company',
        );

        $this->assertSame(
            ProductModeOperationalControlReceiptService::CONTROLS_RECEIPTS_BACKLOG_BRIDGE_SCHEMA,
            $bridge['schema_version'],
        );
        $this->assertSame(
            ProductModeOperationalControlReceiptService::AP790_BACKLOG_PRODUCT_MODE_CONTROLS_RECEIPTS,
            $bridge['ap790_backlog_item'],
        );
        $this->assertSame(
            ProductModeOperationalControlReceiptService::DEFAULT_BOUNDED_RECEIPT_WINDOW,
            $bridge['bounded_by']['recent_receipts_limit'],
        );
        $this->assertSame(['accepted' => 1, 'rejected' => 1], $bridge['control_type_counts']['safety_control']);
        $this->assertSame(['accepted' => 1, 'rejected' => 0], $bridge['control_type_counts']['autonomy_tier']);
        $this->assertTrue($bridge['receipt_backed_controls']['pause']['receipt_backed']);
        $this->assertTrue($bridge['receipt_backed_controls']['pause']['value']);
        $this->assertTrue($bridge['receipt_backed_controls']['kill_switch']['receipt_backed']);
        $this->assertTrue($bridge['receipt_backed_controls']['kill_switch']['value']);
        $this->assertTrue($bridge['receipt_backed_controls']['autonomy_tier']['receipt_backed']);
        $this->assertSame(2, $bridge['receipt_backed_controls']['autonomy_tier']['tier']);
        $this->assertSame(2, $bridge['receipt_backed_controls']['autonomy_tier']['max_allowed_tier']);
        $this->assertTrue($bridge['effective_policy_slice']['paused']);
        $this->assertTrue($bridge['effective_policy_slice']['kill_switch']);
        $this->assertSame(2, $bridge['effective_policy_slice']['autonomy_tier']);
        $this->assertSame(2, $bridge['effective_policy_slice']['max_allowed_autonomy_tier']);
        $this->assertCount(3, $bridge['recent_receipts']);
        $this->assertLessThanOrEqual(
            ProductModeOperationalControlReceiptService::DEFAULT_BOUNDED_RECEIPT_WINDOW,
            count($bridge['recent_receipts']),
        );
        $this->assertTrue($bridge['claim_policy']['product_mode_controls_receipts_backlog_observable']);
        $this->assertTrue($bridge['claim_policy']['bounded_receipt_window']);
        $this->assertTrue($bridge['claim_policy']['read_only']);
        $this->assertFalse($bridge['claim_policy']['toggles_controls_directly']);
    }
}
