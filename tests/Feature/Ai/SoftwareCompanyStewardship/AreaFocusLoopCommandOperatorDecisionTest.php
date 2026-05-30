<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SoftwareCompanyStewardship;

use App\Http\Controllers\Ai\SoftwareCompanyStewardship\AreaFocusLoopCommandController;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOperatorDecisionService;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Loop Command Surface · POST operator-decision endpoint (c) contract tests.
 *
 * Drives AreaFocusLoopCommandController@operatorDecision directly with real
 * Request objects and the real AreaFocusOperatorDecisionService it wraps (the
 * receipt build is a pure deterministic operation — no persistence, no provider,
 * no execution), so the HTTP-shaped behaviour is proven without faking.
 *
 * These tests pin the FROZEN contract:
 *   - a valid decision returns the AP-724 receipt verbatim with 201;
 *   - area_id is anchored from the {area} path param unless the body overrides it;
 *   - an accept NEVER executes (executed=false, requires_owner_execution=true, and
 *     none of the autonomy flags flip) — the controller dispatches no owner runtime;
 *   - every decide() InvalidArgumentException maps to a 422 blocked envelope with the
 *     stable machine reason, including the high-risk-accept-without-rationale rule.
 */
final class AreaFocusLoopCommandOperatorDecisionTest extends TestCase
{
    private function controller(): AreaFocusLoopCommandController
    {
        return $this->app->make(AreaFocusLoopCommandController::class);
    }

    /**
     * @param  array<string,mixed>  $body
     */
    private function postRequest(array $body): Request
    {
        return Request::create(
            '/api/ai/software-company-stewardship/loop/agentic_engineering_os/operator-decision',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($body),
        );
    }

    public function test_accept_returns_ap724_receipt_verbatim_and_never_executes(): void
    {
        $request = $this->postRequest([
            'decision' => 'accept',
            'operator_actor' => 'vitor',
            'finding_hash' => 'sha256:deadbeefcafe',
            'inbox_item_id' => 'afib_123',
            'risk' => 'medium',
        ]);

        $response = $this->controller()->operatorDecision($request, 'agentic_engineering_os');

        $this->assertSame(201, $response->getStatusCode());

        $payload = $response->getData(true);

        // Receipt is returned verbatim (AP-724 schema + contract).
        $this->assertSame(AreaFocusOperatorDecisionService::RECEIPT_SCHEMA, $payload['schema_version']);
        $this->assertSame('AP-724', $payload['ap_contract']);
        $this->assertSame('accept', $payload['decision']);
        $this->assertSame('vitor', $payload['operator_actor']);
        $this->assertSame('sha256:deadbeefcafe', $payload['finding_hash']);
        $this->assertSame('afib_123', $payload['inbox_item_id']);
        $this->assertSame('medium', $payload['risk_level']);
        $this->assertStringStartsWith('afod_', $payload['decision_id']);
        $this->assertStringStartsWith('sha256:', $payload['decision_hash']);
        $this->assertSame('release_to_owner_execution_under_operator_review', $payload['next_allowed_action']);

        // Hard honesty guarantees — an accept unlocks the next stage, it NEVER executes.
        $this->assertTrue($payload['requires_owner_execution']);
        $this->assertFalse($payload['executed']);
        $this->assertFalse($payload['atlas_auto_decided']);
        $this->assertFalse($payload['autoapproval_allowed']);
        $this->assertFalse($payload['autoimplementation_allowed']);
        $this->assertFalse($payload['branch_created']);
        $this->assertFalse($payload['provider_invoked']);
        $this->assertFalse($payload['mutates_target_repo']);
        $this->assertFalse($payload['parallel_registry_created']);
        $this->assertTrue($payload['operator_owned']);
    }

    public function test_area_id_is_anchored_from_path_param_when_body_omits_it(): void
    {
        $request = $this->postRequest([
            'decision' => 'reject',
            'operator_actor' => 'vitor',
            'finding_hash' => 'finding-xyz',
        ]);

        $response = $this->controller()->operatorDecision($request, 'agentic_engineering_os');
        $payload = $response->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('agentic_engineering_os', $payload['area_id']);
        // reject unlocks nothing and never requires owner execution.
        $this->assertSame('close_item_no_action', $payload['next_allowed_action']);
        $this->assertFalse($payload['requires_owner_execution']);
    }

    public function test_body_area_id_overrides_path_param(): void
    {
        $request = $this->postRequest([
            'decision' => 'defer',
            'operator_actor' => 'vitor',
            'finding_hash' => 'finding-override',
            'area_id' => 'another_area',
        ]);

        $response = $this->controller()->operatorDecision($request, 'agentic_engineering_os');
        $payload = $response->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('another_area', $payload['area_id']);
    }

    public function test_high_risk_accept_without_rationale_is_rejected_422(): void
    {
        $request = $this->postRequest([
            'decision' => 'accept',
            'operator_actor' => 'vitor',
            'finding_hash' => 'finding-high-risk',
            'risk' => 'high',
            // rationale intentionally omitted — the service must block this.
        ]);

        $response = $this->controller()->operatorDecision($request, 'agentic_engineering_os');

        $this->assertSame(422, $response->getStatusCode());

        $payload = $response->getData(true);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(
            AreaFocusOperatorDecisionService::BLOCK_HIGH_RISK_ACCEPT_RATIONALE,
            $payload['reason'],
        );
        $this->assertStringContainsString(
            AreaFocusOperatorDecisionService::BLOCK_HIGH_RISK_ACCEPT_RATIONALE,
            $payload['detail'],
        );
        $this->assertArrayNotHasKey('decision_id', $payload);
    }

    public function test_high_risk_accept_with_rationale_succeeds(): void
    {
        $request = $this->postRequest([
            'decision' => 'accept',
            'operator_actor' => 'vitor',
            'finding_hash' => 'finding-high-risk-ok',
            'risk' => 'critical',
            'rationale' => 'Operator reviewed the diff and accepts the risk for this hotfix.',
        ]);

        $response = $this->controller()->operatorDecision($request, 'agentic_engineering_os');
        $payload = $response->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('critical', $payload['risk_level']);
        $this->assertSame('accept', $payload['decision']);
        $this->assertTrue($payload['requires_owner_execution']);
        $this->assertFalse($payload['executed']);
    }

    public function test_missing_operator_actor_maps_to_422_blocked(): void
    {
        $request = $this->postRequest([
            'decision' => 'accept',
            'operator_actor' => '',
            'finding_hash' => 'finding-no-actor',
        ]);

        $response = $this->controller()->operatorDecision($request, 'agentic_engineering_os');
        $payload = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(AreaFocusOperatorDecisionService::BLOCK_ACTOR_REQUIRED, $payload['reason']);
    }

    public function test_invalid_decision_maps_to_422_blocked(): void
    {
        $request = $this->postRequest([
            'decision' => 'maybe',
            'operator_actor' => 'vitor',
            'finding_hash' => 'finding-bad-decision',
        ]);

        $response = $this->controller()->operatorDecision($request, 'agentic_engineering_os');
        $payload = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(AreaFocusOperatorDecisionService::BLOCK_INVALID_DECISION, $payload['reason']);
    }

    public function test_missing_finding_hash_maps_to_422_blocked(): void
    {
        $request = $this->postRequest([
            'decision' => 'accept',
            'operator_actor' => 'vitor',
            // finding_hash intentionally omitted.
        ]);

        $response = $this->controller()->operatorDecision($request, 'agentic_engineering_os');
        $payload = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(AreaFocusOperatorDecisionService::BLOCK_ITEM_WITHOUT_HASH, $payload['reason']);
    }
}
