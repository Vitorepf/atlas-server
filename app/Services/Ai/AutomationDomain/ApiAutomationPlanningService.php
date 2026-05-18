<?php

namespace App\Services\Ai\AutomationDomain;

use App\Models\AiAutomationPlan;
use App\Models\AiAutomationRun;

/**
 * Plans API automation flows. Never calls a remote API. Produces a
 * structured plan with auth flow, idempotency policy, cost band and
 * expected evidence kinds so PolicyBridge can gate before any HTTP request.
 */
class ApiAutomationPlanningService
{
    public function __construct(private readonly AutomationPlanService $plans) {}

    /**
     * @param  array<string,mixed>  $args
     */
    public function plan(AiAutomationRun $run, array $args): AiAutomationPlan
    {
        $endpoint = (string) ($args['endpoint'] ?? '');
        if ($endpoint === '') {
            throw AutomationDomainException::missingField('endpoint');
        }
        $method = strtoupper((string) ($args['method'] ?? 'GET'));
        if (! in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw AutomationDomainException::missingField('method');
        }

        $requiresAuth = (bool) ($args['auth_required'] ?? true);
        $writeOperation = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $expectedCost = (string) ($args['expected_cost_band'] ?? 'low');
        $idempotency = (string) ($args['idempotency'] ?? ($writeOperation ? 'required' : 'not_applicable'));

        $safetyFactors = [
            'auth_required' => $requiresAuth,
            'write_operation' => $writeOperation,
            'expected_cost_band' => $expectedCost,
            'idempotency' => $idempotency,
            'rate_limit_per_minute' => $args['rate_limit_per_minute'] ?? null,
            'pii_in_payload' => (bool) ($args['pii_in_payload'] ?? false),
        ];

        // Block any plan that writes to an external API with auth required,
        // or carries PII, until Policy approves. Read-only public endpoints
        // stay `planned`.
        $needsApproval = $writeOperation || $requiresAuth || ($safetyFactors['pii_in_payload'] ?? false);
        $status = $needsApproval
            ? AutomationDomainCanon::PLAN_STATUS_BLOCKED
            : AutomationDomainCanon::PLAN_STATUS_PLANNED;

        $payload = [
            'endpoint' => $endpoint,
            'method' => $method,
            'request_schema' => $args['request_schema'] ?? null,
            'response_schema' => $args['response_schema'] ?? null,
            'auth' => $args['auth'] ?? ($requiresAuth ? ['mode' => 'unknown'] : ['mode' => 'public']),
            'idempotency_key_strategy' => $args['idempotency_key_strategy'] ?? ($writeOperation ? 'deterministic_uuid_v5' : null),
            'evidence_kinds' => ['command', 'receipt', 'artifact'],
            'tool_capability_id' => $args['tool_capability_id'] ?? 'api.readonly',
        ];

        return $this->plans->record($run, [
            'plan_type' => AutomationDomainCanon::PLAN_API,
            'title' => (string) ($args['title'] ?? "API automation: {$method} {$endpoint}"),
            'summary' => $args['summary'] ?? null,
            'payload' => $payload,
            'safety_factors' => $safetyFactors,
            'rollback' => $args['rollback'] ?? ($writeOperation ? [
                'strategy' => 'compensating_request',
                'requires_idempotency_key' => true,
            ] : ['strategy' => 'noop_read_only']),
            'status' => $status,
        ]);
    }
}
