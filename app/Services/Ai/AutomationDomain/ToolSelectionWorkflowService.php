<?php

namespace App\Services\Ai\AutomationDomain;

use App\Models\AiAutomationRun;
use App\Models\AiAutomationToolDecision;
use App\Models\AiToolCapability;
use App\Models\AiToolDefinition;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Atlas Tool Economy decision matrix, persisted.
 *
 *   use_existing | api_call | buy_or_subscribe | clone_repo
 *   adapt_open_source | build_internal | manual_fallback | do_not_use
 *
 * The workflow ALWAYS evaluates alternatives before picking. Decisions are
 * deterministic given the input + the present Tool Runtime catalog.
 *
 * Tolerant: if `ai_tool_definitions` / `ai_tool_capabilities` don't exist
 * yet (Meta 5 missing), the alternatives list omits the registry source and
 * the workflow still emits a decision (typically `build_internal` or
 * `manual_fallback`).
 */
class ToolSelectionWorkflowService
{
    /**
     * @param  array<string,mixed>  $args
     *
     * Args:
     *   need: string (required) — "browser scrape Instagram", "Slack notification"...
     *   capability_id: ?string — capability the Atlas Router/agent thinks it needs
     *   risk_level: 'low'|'medium'|'high'|'critical' (default 'medium')
     *   official_api_available: bool
     *   open_source_candidate: ?array{ url, license, stars, last_commit_at }
     *   budget_band: 'free'|'low'|'medium'|'high'
     *   prefer_internal: bool
     *   security_sensitive: bool
     */
    public function decide(array $args, ?AiAutomationRun $run = null): AiAutomationToolDecision
    {
        $need = (string) ($args['need'] ?? '');
        if ($need === '') {
            throw AutomationDomainException::missingField('need');
        }

        $alternatives = $this->collectAlternatives($args);
        [$decisionKind, $selectedToolId, $score, $justification] = $this->score($alternatives, $args);

        $hashInput = [
            'need' => $need,
            'decision_kind' => $decisionKind,
            'selected_tool_id' => $selectedToolId,
            'alternatives' => $alternatives,
            'safety_factors' => $args['safety_factors'] ?? null,
        ];

        return AiAutomationToolDecision::query()->create([
            'uuid' => (string) Str::uuid(),
            'automation_run_id' => $run?->id,
            'need' => $need,
            'decision_kind' => $decisionKind,
            'selected_tool_id' => $selectedToolId,
            'alternatives' => $alternatives,
            'safety_factors' => isset($args['safety_factors']) && is_array($args['safety_factors']) ? $args['safety_factors'] : null,
            'score' => $score,
            'justification' => $justification,
            'decision_hash' => AutomationCanonicalHash::sha256($hashInput),
        ]);
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array<int,array<string,mixed>>
     */
    private function collectAlternatives(array $args): array
    {
        $alternatives = [];
        $capabilityId = isset($args['capability_id']) ? (string) $args['capability_id'] : null;
        $existing = $this->findExistingToolForCapability($capabilityId);
        if ($existing !== null) {
            $alternatives[] = [
                'source' => 'tool_registry',
                'kind' => AutomationDomainCanon::DECISION_USE_EXISTING,
                'tool_id' => $existing['tool_id'],
                'capability_id' => $capabilityId,
                'why' => 'present in ai_tool_definitions, ready and accessible',
                'score' => 0.85,
            ];
        }

        if ((bool) ($args['official_api_available'] ?? false)) {
            $alternatives[] = [
                'source' => 'tool_economy',
                'kind' => AutomationDomainCanon::DECISION_API_CALL,
                'tool_id' => 'api.official',
                'capability_id' => $capabilityId,
                'why' => 'official API exists; prefer over scraping/browser automation',
                'score' => 0.78,
            ];
        }

        if ((bool) ($args['saas_candidate'] ?? false)) {
            $alternatives[] = [
                'source' => 'tool_economy',
                'kind' => AutomationDomainCanon::DECISION_BUY_OR_SUBSCRIBE,
                'tool_id' => 'saas.candidate',
                'capability_id' => $capabilityId,
                'why' => 'SaaS candidate identified; needs budget approval',
                'score' => 0.55,
                'requires' => ['budget_approval'],
            ];
        }

        $repo = (array) ($args['open_source_candidate'] ?? []);
        if (! empty($repo['url'])) {
            $licenseOk = in_array(strtolower((string) ($repo['license'] ?? '')), ['mit', 'apache-2.0', 'bsd-3-clause', 'mpl-2.0'], true);
            $alternatives[] = [
                'source' => 'tool_economy',
                'kind' => $licenseOk ? AutomationDomainCanon::DECISION_CLONE_REPO : AutomationDomainCanon::DECISION_DO_NOT_USE,
                'tool_id' => 'repo:'.($repo['url']),
                'capability_id' => $capabilityId,
                'why' => $licenseOk
                    ? 'open-source repo with permissive license; needs security/test audit before use'
                    : 'open-source repo license incompatible — do_not_use',
                'score' => $licenseOk ? 0.6 : 0.0,
                'license' => $repo['license'] ?? null,
            ];
        }

        // build_internal always present so we never run out of options.
        $alternatives[] = [
            'source' => 'tool_economy',
            'kind' => AutomationDomainCanon::DECISION_BUILD_INTERNAL,
            'tool_id' => 'internal.builder',
            'capability_id' => $capabilityId,
            'why' => 'fallback: build a minimal internal tool with full evidence + receipts',
            'score' => (bool) ($args['prefer_internal'] ?? false) ? 0.7 : 0.4,
        ];

        // Manual fallback is always available — operator can do it by hand.
        $alternatives[] = [
            'source' => 'tool_economy',
            'kind' => AutomationDomainCanon::DECISION_MANUAL_FALLBACK,
            'tool_id' => 'manual.operator',
            'capability_id' => $capabilityId,
            'why' => 'operator-driven action; safe default when automation is risky',
            'score' => 0.3,
        ];

        return $alternatives;
    }

    /**
     * @param  array<int,array<string,mixed>>  $alternatives
     * @param  array<string,mixed>  $args
     * @return array{0:string,1:?string,2:float,3:string}
     */
    private function score(array $alternatives, array $args): array
    {
        $riskLevel = (string) ($args['risk_level'] ?? 'medium');
        $securitySensitive = (bool) ($args['security_sensitive'] ?? false);

        // Security-sensitive needs cannot use cloned repos or buy_or_subscribe
        // without explicit security review. Bias toward use_existing or
        // build_internal.
        if ($securitySensitive) {
            foreach ($alternatives as &$alt) {
                if (in_array($alt['kind'], [AutomationDomainCanon::DECISION_CLONE_REPO, AutomationDomainCanon::DECISION_BUY_OR_SUBSCRIBE], true)) {
                    $alt['score'] = ((float) $alt['score']) * 0.4;
                    $alt['why'] .= ' (downweighted: security_sensitive)';
                }
            }
            unset($alt);
        }

        if ($riskLevel === 'critical') {
            foreach ($alternatives as &$alt) {
                if ($alt['kind'] === AutomationDomainCanon::DECISION_MANUAL_FALLBACK) {
                    $alt['score'] = max((float) $alt['score'], 0.65);
                    $alt['why'] .= ' (upweighted: risk_level=critical)';
                }
            }
            unset($alt);
        }

        usort($alternatives, static fn ($a, $b): int => ((float) $b['score']) <=> ((float) $a['score']));
        $best = $alternatives[0];
        $kind = (string) $best['kind'];
        $selected = $kind === AutomationDomainCanon::DECISION_DO_NOT_USE
            ? null
            : (string) ($best['tool_id'] ?? '');

        $why = (string) $best['why'];

        return [$kind, $selected !== '' ? $selected : null, (float) $best['score'], $why];
    }

    /**
     * @return array{tool_id:string,name?:string}|null
     */
    private function findExistingToolForCapability(?string $capabilityId): ?array
    {
        if ($capabilityId === null || $capabilityId === '') {
            return null;
        }
        if (! Schema::hasTable('ai_tool_definitions') || ! Schema::hasTable('ai_tool_capabilities')) {
            return null;
        }
        try {
            $capability = AiToolCapability::query()->where('capability_id', $capabilityId)->first();
            if ($capability === null) {
                return null;
            }
            $tool = AiToolDefinition::query()->where('id', $capability->tool_definition_id)->first();
            if ($tool === null) {
                return null;
            }

            return [
                'tool_id' => (string) $tool->tool_id,
                'name' => (string) $tool->name,
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
