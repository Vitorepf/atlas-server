<?php

namespace App\Services\Ai\AutomationDomain;

use App\Models\AiAutomationPlan;
use App\Models\AiAutomationRun;

/**
 * Plans browser automation flows. Never opens a browser. Produces a
 * structured plan ready to hand to a real browser runtime, with explicit
 * safety factors (auth, anti-bot, scraping risk) so PolicyBridge can gate
 * before any execution.
 */
class BrowserAutomationPlanningService
{
    public function __construct(private readonly AutomationPlanService $plans) {}

    /**
     * @param  array<string,mixed>  $args
     */
    public function plan(AiAutomationRun $run, array $args): AiAutomationPlan
    {
        $targetUrl = (string) ($args['target_url'] ?? '');
        if ($targetUrl === '') {
            throw AutomationDomainException::missingField('target_url');
        }
        $scope = (string) ($args['scope'] ?? 'read-only');
        $authRequired = (bool) ($args['auth_required'] ?? false);
        $official = (bool) ($args['official_api_exists'] ?? false);
        $rateLimit = isset($args['rate_limit_per_minute']) ? (int) $args['rate_limit_per_minute'] : null;
        $tosRestrictive = (bool) ($args['tos_restrictive'] ?? $authRequired);

        $safetyFactors = [
            'auth_required' => $authRequired,
            'tos_restrictive' => $tosRestrictive,
            'rate_limit_per_minute' => $rateLimit,
            'scope' => $scope,
            'official_api_exists' => $official,
            'anti_bot_protection' => (bool) ($args['anti_bot_protection'] ?? $tosRestrictive),
        ];

        // Default conservative status: anything that requires auth, has
        // restrictive ToS, or hits a site with anti-bot protection is `blocked`
        // until Policy approves. This is the canonical anti-action stance.
        $status = ($authRequired || $tosRestrictive || ($safetyFactors['anti_bot_protection'] ?? false))
            ? AutomationDomainCanon::PLAN_STATUS_BLOCKED
            : AutomationDomainCanon::PLAN_STATUS_PLANNED;

        $payload = [
            'target_url' => $targetUrl,
            'scope' => $scope,
            'auth_required' => $authRequired,
            'steps' => array_values((array) ($args['steps'] ?? [])),
            'evidence_kinds' => ['screenshot', 'command', 'receipt'],
            'tool_capability_id' => $args['tool_capability_id'] ?? 'browser.readonly',
            'official_api_exists' => $official,
        ];

        return $this->plans->record($run, [
            'plan_type' => AutomationDomainCanon::PLAN_BROWSER,
            'title' => (string) ($args['title'] ?? "Browser automation: {$targetUrl}"),
            'summary' => $args['summary'] ?? null,
            'payload' => $payload,
            'safety_factors' => $safetyFactors,
            'rollback' => $args['rollback'] ?? [
                'strategy' => 'abort_session',
                'restore' => 'close_browser_window',
            ],
            'status' => $status,
        ]);
    }
}
