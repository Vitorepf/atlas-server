<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Discovery;

use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\ContextRetrievalPlan;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;

/**
 * Deterministic doc tier selector for the Atlas Dev fast path.
 *
 * Produces a {@see ContextRetrievalPlan} from {@see OperationEnvelope} +
 * {@see CompactSdd}. Pure function: same input → byte-identical output,
 * no provider call, no filesystem touch, no telemetry side effects.
 *
 * Tier rules align with contract doc atlas-dev-efficient-programming-flow-v1
 * section 15.1 and runbook PR 1.1.
 */
final class DocContextTierSelector
{
    public const TIER_CORE = 'core';

    public const TIER_CODE_INTELLIGENCE = 'code_intelligence';

    public const TIER_SDD = 'sdd';

    public const TIER_INTERFACE = 'interface';

    public const TIER_FORGE = 'forge';

    public const TIER_OBRAS = 'obras';

    public const OPEN_BRAIN_MODE_AUTO = 'auto';

    public const OPEN_BRAIN_MODE_REQUIRED = 'required';

    public const OPEN_BRAIN_MODE_OFF = 'off';

    /**
     * Surfaces whose UX involves visual / interface verification.
     *
     * @var list<string>
     */
    private const INTERFACE_SURFACES = [
        'atlas_desktop_ai',
        'atlas_app',
        'atlas_code',
    ];

    public function select(OperationEnvelope $envelope, CompactSdd $compactSdd): ContextRetrievalPlan
    {
        $taskKind = $compactSdd->taskKind;
        $riskLevel = $compactSdd->riskLevel;
        $mode = $compactSdd->mode;

        $tiers = $this->selectTiers($envelope, $compactSdd);
        $budget = $compactSdd->contextBudget->maxChars;
        $required = $this->requiredSources($envelope, $compactSdd, $tiers);
        $optional = $this->optionalSources($envelope, $compactSdd, $tiers);
        $openBrainMode = $this->resolveOpenBrainMode($envelope, $compactSdd);

        $reasons = [];
        if (in_array($taskKind, ['question'], true)) {
            $reasons[] = 'task_kind=question→read_only_min_context';
        }
        if (in_array($mode, ['escalate_preview'], true)) {
            $reasons[] = 'mode=escalate_preview→forge_tier_locked_in';
        }
        if ($openBrainMode === self::OPEN_BRAIN_MODE_OFF) {
            $reasons[] = 'open_brain_mode=off→required_memory_refs_skipped';
        }

        $truncationPolicy = [
            'open_brain_mode' => $openBrainMode,
            'on_overflow' => 'truncate_optional_then_required',
            'policy' => 'drop_optional_first',
            'reasons' => $reasons,
        ];

        $payload = [
            'budget_chars' => $budget,
            'missing_sources' => [],
            'optional_sources' => array_values($optional),
            'plan_hash' => '',
            'provider_safe' => true,
            'required_sources' => array_values($required),
            'run_id' => $envelope->runId,
            'schema_version' => ContextRetrievalPlan::SCHEMA_VERSION,
            'selected_tiers' => array_values($tiers),
            'truncation_policy' => $truncationPolicy,
        ];

        $planHash = CanonicalHasher::hashWithout($payload, 'plan_hash');

        return new ContextRetrievalPlan(
            runId: $envelope->runId,
            selectedTiers: array_values($tiers),
            budgetChars: $budget,
            requiredSources: array_values($required),
            optionalSources: array_values($optional),
            missingSources: [],
            truncationPolicy: $truncationPolicy,
            providerSafe: true,
            planHash: $planHash,
        );
    }

    /**
     * @return list<string>
     */
    private function selectTiers(OperationEnvelope $envelope, CompactSdd $compactSdd): array
    {
        $taskKind = $compactSdd->taskKind;
        $riskLevel = $compactSdd->riskLevel;
        $mode = $compactSdd->mode;
        $workspaceResolved = $envelope->preflight->workspaceResolved;
        $surfaceId = $envelope->surfaceId;
        $declared = array_values(array_unique($compactSdd->docTiersRequired));

        $tiers = [];

        if ($taskKind !== 'question') {
            $tiers[] = self::TIER_CORE;
        }

        if ($workspaceResolved) {
            $tiers[] = self::TIER_CODE_INTELLIGENCE;
        }

        $sddTriggered = in_array($taskKind, ['patch', 'repair'], true)
            || $this->riskAtLeast($riskLevel, 'R2');
        if ($sddTriggered) {
            $tiers[] = self::TIER_SDD;
        }

        $interfaceTriggered = $taskKind === 'frontend'
            || in_array($surfaceId, self::INTERFACE_SURFACES, true);
        if ($interfaceTriggered) {
            $tiers[] = self::TIER_INTERFACE;
        }

        if ($this->riskAtLeast($riskLevel, 'R4') || $mode === 'escalate_preview') {
            $tiers[] = self::TIER_FORGE;
        }

        if (in_array(self::TIER_OBRAS, $declared, true)) {
            $tiers[] = self::TIER_OBRAS;
        }

        foreach ($declared as $tier) {
            if (! in_array($tier, $tiers, true) && $this->isKnownTier($tier)) {
                $tiers[] = $tier;
            }
        }

        return $this->orderTiers(array_values(array_unique($tiers)));
    }

    /**
     * @param  list<string>  $tiers
     * @return list<string>
     */
    private function requiredSources(OperationEnvelope $envelope, CompactSdd $compactSdd, array $tiers): array
    {
        $required = [];

        if (in_array(self::TIER_CORE, $tiers, true)) {
            $required[] = 'doc://engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md';
        }

        if (in_array(self::TIER_SDD, $tiers, true)) {
            $required[] = 'doc://engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md';
        }

        if (in_array(self::TIER_FORGE, $tiers, true)) {
            $required[] = 'doc://engineering-knowledge-base/atlas-forge-operating-system.md';
        }

        return array_values(array_unique($required));
    }

    /**
     * @param  list<string>  $tiers
     * @return list<string>
     */
    private function optionalSources(OperationEnvelope $envelope, CompactSdd $compactSdd, array $tiers): array
    {
        $optional = [];

        if (in_array(self::TIER_CODE_INTELLIGENCE, $tiers, true)) {
            $optional[] = 'code_intelligence://workspace/'.$envelope->workspaceHash;
        }

        if (in_array(self::TIER_INTERFACE, $tiers, true)) {
            $optional[] = 'doc://engineering-knowledge-base/atlas-desktop-ai-surface.md';
        }

        if (in_array(self::TIER_OBRAS, $tiers, true)) {
            $optional[] = 'doc://engineering-knowledge-base/atlas-forge-operating-system.md#obras';
        }

        return array_values(array_unique($optional));
    }

    private function resolveOpenBrainMode(OperationEnvelope $envelope, CompactSdd $compactSdd): string
    {
        $hint = $envelope->surfaceContext->providerChoice;
        if (is_string($hint)) {
            $lower = strtolower($hint);
            if ($lower === 'off' || $lower === 'no_open_brain') {
                return self::OPEN_BRAIN_MODE_OFF;
            }
            if ($lower === 'required') {
                return self::OPEN_BRAIN_MODE_REQUIRED;
            }
        }

        foreach ($envelope->userConstraints as $constraint) {
            if (! is_string($constraint)) {
                continue;
            }
            $lower = strtolower($constraint);
            if (str_contains($lower, 'open_brain=off') || str_contains($lower, 'no open brain')) {
                return self::OPEN_BRAIN_MODE_OFF;
            }
            if (str_contains($lower, 'open_brain=required')) {
                return self::OPEN_BRAIN_MODE_REQUIRED;
            }
        }

        if ($compactSdd->taskKind === 'question' && $this->riskAtLeast($compactSdd->riskLevel, 'R0', strict: true) === false) {
            return self::OPEN_BRAIN_MODE_AUTO;
        }

        if ($this->riskAtLeast($compactSdd->riskLevel, 'R4')) {
            return self::OPEN_BRAIN_MODE_REQUIRED;
        }

        return self::OPEN_BRAIN_MODE_AUTO;
    }

    private function riskAtLeast(string $level, string $threshold, bool $strict = false): bool
    {
        $rank = ['R0' => 0, 'R1' => 1, 'R2' => 2, 'R3' => 3, 'R4' => 4, 'R5' => 5];
        $a = $rank[$level] ?? 0;
        $b = $rank[$threshold] ?? 0;

        return $strict ? $a > $b : $a >= $b;
    }

    private function isKnownTier(string $tier): bool
    {
        return in_array($tier, [
            self::TIER_CORE,
            self::TIER_CODE_INTELLIGENCE,
            self::TIER_SDD,
            self::TIER_INTERFACE,
            self::TIER_FORGE,
            self::TIER_OBRAS,
        ], true);
    }

    /**
     * @param  list<string>  $tiers
     * @return list<string>
     */
    private function orderTiers(array $tiers): array
    {
        $canonical = [
            self::TIER_CORE,
            self::TIER_CODE_INTELLIGENCE,
            self::TIER_SDD,
            self::TIER_INTERFACE,
            self::TIER_FORGE,
            self::TIER_OBRAS,
        ];

        $ordered = [];
        foreach ($canonical as $tier) {
            if (in_array($tier, $tiers, true)) {
                $ordered[] = $tier;
            }
        }

        return $ordered;
    }
}
