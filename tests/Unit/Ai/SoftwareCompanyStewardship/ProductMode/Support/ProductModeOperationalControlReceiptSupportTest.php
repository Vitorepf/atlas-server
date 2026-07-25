<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\ProductMode\Support;

use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\Support\ProductModeOperationalControlReceiptSupport as Support;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionOperatorDecisionService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pure Support peel for Product Mode operational control receipts — no I/O, no ledger, no DB.
 */
final class ProductModeOperationalControlReceiptSupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/SoftwareCompanyStewardship/ProductMode/Support/ProductModeOperationalControlReceiptSupport.php';

    public function test_support_peel_path_and_static_surface(): void
    {
        // tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/Support → repo root = 6 levels
        $abs = dirname(__DIR__, 6).'/'.self::SUPPORT_PATH;
        $this->assertFileExists($abs, 'Support peel must live at '.self::SUPPORT_PATH);

        $ref = new ReflectionClass(Support::class);
        foreach ([
            'decorateReceipt',
            'receiptSummary',
            'extractControlPayload',
            'projectablePolicy',
            'controlPayload',
            'allowedPayloadKeys',
            'controlType',
            'listEnvelope',
            'withEffectiveControlPolicy',
            'backlogObservability',
            'backlogObservabilityClaimPolicy',
            'claimPolicy',
        ] as $method) {
            $this->assertTrue($ref->hasMethod($method), $method);
            $m = $ref->getMethod($method);
            $this->assertTrue($m->isPublic());
            $this->assertTrue($m->isStatic());
        }
    }

    public function test_claim_policy_is_append_only_ledger_not_repo_mutation(): void
    {
        $policy = Support::claimPolicy();

        $this->assertTrue($policy['writes_local_state']);
        $this->assertSame('ap731_jsonl_append_only', $policy['persistence']);
        $this->assertFalse($policy['creates_parallel_ledger']);
        $this->assertTrue($policy['read_only_over_repo']);
        $this->assertFalse($policy['mutates_target_repo']);
        $this->assertFalse($policy['provider_invoked']);
        $this->assertFalse($policy['toggles_kill_switch_directly']);
        $this->assertTrue($policy['operator_review_required']);
    }

    public function test_control_type_normalizes_and_rejects_invalid(): void
    {
        $this->assertSame('safety_control', Support::controlType(' Safety_Control '));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Support::BLOCK_INVALID_CONTROL_TYPE);
        Support::controlType('not_a_control');
    }

    public function test_control_payload_shapes_repo_authorization_and_stable_control_id(): void
    {
        $a = Support::controlPayload('aeos', 'portfolio', 'repo_authorization', [
            'repo' => 'atlas-server',
            'repo_authorization_status' => 'authorized',
            'rationale' => 'onboard',
        ]);
        $b = Support::controlPayload('aeos', 'portfolio', 'repo_authorization', [
            'repository' => 'atlas-server',
            'repo_authorization_status' => 'authorized',
        ]);

        $this->assertSame(Support::RECEIPT_SCHEMA, $a['schema_version']);
        $this->assertSame('repo_authorization', $a['control_type']);
        $this->assertSame(['atlas-server'], $a['authorized_repositories']);
        $this->assertSame('authorized', $a['repo_authorization_status']);
        $this->assertSame('onboard', $a['control_reason']);
        $this->assertStringStartsWith('pmctrl_', $a['control_id']);
        $this->assertSame($a['control_id'], $b['control_id']);
    }

    public function test_allowed_payload_keys_per_control_type(): void
    {
        $this->assertSame(
            ['paused', 'kill_switch', 'lock_active', 'rate_limited', 'pause_until'],
            Support::allowedPayloadKeys('safety_control'),
        );
        $this->assertSame(['autonomy_tier', 'max_allowed_autonomy_tier'], Support::allowedPayloadKeys('autonomy_tier'));
        $this->assertSame([], Support::allowedPayloadKeys('unknown'));
    }

    public function test_projectable_policy_filters_known_keys_only(): void
    {
        $out = Support::projectablePolicy([
            'kill_switch' => true,
            'autonomy_tier' => 3,
            'control_id' => 'pmctrl_x',
            'noise' => 'drop-me',
        ]);

        $this->assertSame(['autonomy_tier' => 3, 'kill_switch' => true], $out);
    }

    public function test_receipt_summary_and_extract_payload(): void
    {
        $record = [
            'decision_id' => 'dec-1',
            'area_id' => 'aeos',
            'portfolio_id' => 'portfolio',
            'target_id' => 'pmctrl_fallback',
            'decision' => StewardshipEvolutionOperatorDecisionService::DECISION_ACCEPT,
            'risk_level' => 'medium',
            'recorded_at' => '2026-07-24T00:00:00+00:00',
            'decision_hash' => 'sha256:x',
            'target_payload' => [
                'control_id' => 'pmctrl_real',
                'control_type' => 'safety_control',
                'repo' => 'atlas-server',
                'kill_switch' => true,
            ],
        ];

        $this->assertSame('safety_control', Support::extractControlPayload($record)['control_type']);
        $this->assertSame([], Support::extractControlPayload(['target_payload' => 'nope']));

        $summary = Support::receiptSummary($record);
        $this->assertSame('dec-1', $summary['decision_id']);
        $this->assertSame('pmctrl_real', $summary['control_id']);
        $this->assertSame('safety_control', $summary['control_type']);
        $this->assertSame('atlas-server', $summary['repo']);
    }

    public function test_decorate_receipt_adds_schema_and_claim_policy(): void
    {
        $decorated = Support::decorateReceipt(['decision_id' => 'd1']);

        $this->assertSame('d1', $decorated['decision_id']);
        $this->assertSame(Support::RECEIPT_SCHEMA, $decorated['product_mode_control_receipt_schema']);
        $this->assertTrue($decorated['product_mode_control_claim_policy']['read_only_over_repo']);
    }

    public function test_list_envelope_and_effective_policy_attachment(): void
    {
        $envelope = Support::listEnvelope('aeos', 'portfolio', [['decision_id' => 'd1']]);
        $this->assertSame(Support::SCHEMA, $envelope['schema_version']);
        $this->assertSame(1, $envelope['receipt_count']);
        $this->assertFalse($envelope['claim_policy']['provider_invoked']);

        $effective = Support::withEffectiveControlPolicy(
            ['evidence_refs' => ['docs_health']],
            ['kill_switch' => false, 'autonomy_tier' => 2],
            ['d1', 'd2'],
        );
        $this->assertFalse($effective['kill_switch']);
        $this->assertSame(2, $effective['autonomy_tier']);
        $this->assertSame(['docs_health'], $effective['evidence_refs']);
        $this->assertSame(2, $effective['control_policy']['applied_receipt_count']);
        $this->assertStringStartsWith('sha256:', $effective['control_policy']['policy_hash']);
    }

    public function test_backlog_observability_counts_and_receipt_backed_controls(): void
    {
        $accept = StewardshipEvolutionOperatorDecisionService::DECISION_ACCEPT;
        $reject = StewardshipEvolutionOperatorDecisionService::DECISION_REJECT;

        $items = [
            [
                'decision_id' => 's1',
                'control_type' => 'safety_control',
                'decision' => $accept,
            ],
            [
                'decision_id' => 's2',
                'control_type' => 'safety_control',
                'decision' => $reject,
            ],
            [
                'decision_id' => 'a1',
                'control_type' => 'autonomy_tier',
                'decision' => $accept,
            ],
            [
                'decision_id' => 'r1',
                'control_type' => 'risk_policy',
                'decision' => $accept,
            ],
        ];

        $payloads = [
            's1' => [
                'control_type' => 'safety_control',
                'paused' => true,
                'kill_switch' => false,
            ],
            'a1' => [
                'control_type' => 'autonomy_tier',
                'autonomy_tier' => 3,
                'max_allowed_autonomy_tier' => 4,
            ],
        ];

        $obs = Support::backlogObservability(
            'aeos',
            'portfolio',
            $items,
            $payloads,
            [
                'paused' => true,
                'kill_switch' => false,
                'autonomy_tier' => 3,
                'max_allowed_autonomy_tier' => 4,
            ],
            50,
        );

        $this->assertSame(Support::CONTROLS_RECEIPTS_BACKLOG_BRIDGE_SCHEMA, $obs['schema_version']);
        $this->assertSame(Support::AP790_BACKLOG_PRODUCT_MODE_CONTROLS_RECEIPTS, $obs['ap790_backlog_item']);
        $this->assertSame(1, $obs['control_type_counts']['safety_control']['accepted']);
        $this->assertSame(1, $obs['control_type_counts']['safety_control']['rejected']);
        $this->assertSame(1, $obs['control_type_counts']['autonomy_tier']['accepted']);
        $this->assertTrue($obs['receipt_backed_controls']['pause']['receipt_backed']);
        $this->assertTrue($obs['receipt_backed_controls']['pause']['value']);
        $this->assertTrue($obs['receipt_backed_controls']['kill_switch']['receipt_backed']);
        $this->assertFalse($obs['receipt_backed_controls']['kill_switch']['value']);
        $this->assertSame(3, $obs['receipt_backed_controls']['autonomy_tier']['tier']);
        $this->assertSame(4, $obs['receipt_backed_controls']['autonomy_tier']['max_allowed_tier']);
        $this->assertSame(4, $obs['receipt_count']);
        $this->assertSame(3, $obs['observable_receipt_count']);
        $this->assertSame(20, $obs['bounded_by']['recent_receipts_limit']);
        $this->assertTrue($obs['claim_policy']['product_mode_controls_receipts_backlog_observable']);
        $this->assertFalse($obs['claim_policy']['toggles_controls_directly']);
    }
}
