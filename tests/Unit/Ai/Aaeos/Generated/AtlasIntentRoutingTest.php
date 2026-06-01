<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasIntentRoutingService;
use Tests\TestCase;

/**
 * Pins the Intent Routing kernel step contract:
 *   - Output carries intent, initial risk, task type, clarification need.
 *   - Invariant: provider is never chosen here (provider_selected = false).
 *   - Regras para IA: a security/scope/autonomy block forces clarification even
 *     on an otherwise clear, unambiguous request.
 *   - Flow hands off to business-context.
 *   - Example: "Refatora Decide" routes as programming, not conversation.
 * Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/system-graph/intent-routing.md
 */
class AtlasIntentRoutingTest extends TestCase
{
    private function service(): AtlasIntentRoutingService
    {
        return new AtlasIntentRoutingService;
    }

    public function test_refatora_decide_routes_as_programming_not_conversation(): void
    {
        // Doc Example: "Refatora Decide" deve virar intent de programacao.
        $route = $this->service()->route(['text' => 'Refatora Decide']);

        $this->assertSame(AtlasIntentRoutingService::TASK_PROGRAMMING, $route['task_type']);
        $this->assertNotSame(AtlasIntentRoutingService::TASK_CONVERSATION, $route['task_type']);
        // Clear programming ask, no block -> no clarification, hands off downstream.
        $this->assertFalse($route['clarification_needed']);
        $this->assertSame('business-context', $route['next_node']);
        $this->assertSame(AtlasIntentRoutingService::RISK_MEDIUM, $route['initial_risk']);
    }

    public function test_provider_is_never_selected_at_this_stage(): void
    {
        // Contracts invariant: "provider ainda nao e escolhido."
        $route = $this->service()->route(['text' => 'Refatora Decide']);

        $this->assertFalse($route['provider_selected']);
        // Forbidden actions are exposed and include selecting a provider/model.
        $this->assertContains('select_provider_or_model', $route['forbidden_at_this_stage']);
        $this->assertContains('execute_tool', $route['forbidden_at_this_stage']);
    }

    public function test_security_block_forces_clarification_even_when_request_is_clear(): void
    {
        // Regras para IA: nao pular clarificacao quando a intent bloquear seguranca.
        // The text is an unambiguous programming task, yet the security block wins.
        $route = $this->service()->route([
            'text' => 'Refatora Decide',
            'blocks_security' => true,
            'ambiguous' => false,
        ]);

        $this->assertTrue($route['clarification_needed']);
        $this->assertContains('security', $route['clarification_blocks']);
        // A block lifts initial risk to high.
        $this->assertSame(AtlasIntentRoutingService::RISK_HIGH, $route['initial_risk']);
    }

    public function test_scope_and_autonomy_blocks_each_force_clarification(): void
    {
        $scope = $this->service()->route(['text' => 'Refatora Decide', 'blocks_scope' => true]);
        $this->assertTrue($scope['clarification_needed']);
        $this->assertContains('scope', $scope['clarification_blocks']);

        $autonomy = $this->service()->route(['text' => 'Refatora Decide', 'blocks_autonomy' => true]);
        $this->assertTrue($autonomy['clarification_needed']);
        $this->assertContains('autonomy', $autonomy['clarification_blocks']);
    }

    public function test_empty_or_generic_intent_routes_as_conversation_and_forces_clarification(): void
    {
        // Riscos: "Intents genericas demais reduzirem qualidade do contexto."
        // An unresolved intent must not be guessed at -> conversation + clarify.
        $route = $this->service()->route(['text' => '']);

        $this->assertSame(AtlasIntentRoutingService::TASK_CONVERSATION, $route['task_type']);
        $this->assertTrue($route['ambiguous']);
        $this->assertTrue($route['clarification_needed']);
        $this->assertFalse($route['provider_selected']);
    }

    public function test_review_intent_is_not_swallowed_by_the_programming_bucket(): void
    {
        // Precedence: "code review do PR" is review, not generic programming.
        $route = $this->service()->route(['text' => 'faca o code review do PR']);

        $this->assertSame(AtlasIntentRoutingService::TASK_REVIEW, $route['task_type']);
        $this->assertSame(AtlasIntentRoutingService::RISK_MEDIUM, $route['initial_risk']);
    }
}
