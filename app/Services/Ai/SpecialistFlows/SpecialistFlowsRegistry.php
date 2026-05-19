<?php

declare(strict_types=1);

namespace App\Services\Ai\SpecialistFlows;

use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use App\Services\Ai\SpecialistFlows\Handlers\AtlasAutomationFlowHandler;
use App\Services\Ai\SpecialistFlows\Handlers\AtlasConversationFlowHandler;
use App\Services\Ai\SpecialistFlows\Handlers\AtlasCyberFlowHandler;
use App\Services\Ai\SpecialistFlows\Handlers\AtlasFinanceFlowHandler;
use App\Services\Ai\SpecialistFlows\Handlers\AtlasMarketingFlowHandler;
use App\Services\Ai\SpecialistFlows\Handlers\AtlasPersonalDevelopmentFlowHandler;
use App\Services\Ai\SpecialistFlows\Handlers\AtlasResearchFlowHandler;
use App\Services\Ai\SpecialistFlows\Handlers\AtlasStrategyFlowHandler;

/**
 * Atlas AI Specialist Flows · canonical registry.
 *
 * Resolves a flow_id to its dedicated handler. Programming-anchored flows
 * (`atlas_dev`, `atlas_forge`, `atlas_debug`, `atlas_review`) intentionally
 * have NO handler here — they are owned by the Programming Adapter / Atlas
 * Dev runtime. The registry is the boundary: a finance/cyber/etc request
 * NEVER lands inside Atlas Dev unless explicitly handed off by Programming.
 *
 * Lookup of an unknown flow returns the conversation fallback handler with
 * `fallback_reason` flagged in the emitted contract.
 */
final class SpecialistFlowsRegistry
{
    /** @var array<string,SpecialistFlowHandlerContract> */
    private array $handlers;

    /** @var array<string,SpecialistFlowHandlerContract> */
    private array $legacyHandlers;

    public function __construct(
        AtlasResearchFlowHandler $research,
        AtlasFinanceFlowHandler $finance,
        AtlasMarketingFlowHandler $marketing,
        AtlasStrategyFlowHandler $strategy,
        AtlasCyberFlowHandler $cyber,
        AtlasPersonalDevelopmentFlowHandler $personal,
        AtlasAutomationFlowHandler $automation,
        AtlasConversationFlowHandler $conversation,
    ) {
        $this->handlers = [
            RouterRuntimeCanon::FLOW_RESEARCH => $research,
            RouterRuntimeCanon::FLOW_FINANCE => $finance,
            RouterRuntimeCanon::FLOW_MARKETING => $marketing,
            RouterRuntimeCanon::FLOW_STRATEGY => $strategy,
            RouterRuntimeCanon::FLOW_CYBER => $cyber,
            RouterRuntimeCanon::FLOW_PERSONAL_DEVELOPMENT => $personal,
            RouterRuntimeCanon::FLOW_AUTOMATION => $automation,
            RouterRuntimeCanon::FLOW_CONVERSATION => $conversation,
        ];

        // Programming-anchored flows are owned by the Programming Adapter
        // canon. The registry knows the boundary but does NOT mint handlers
        // for them — that responsibility lives elsewhere.
        $this->legacyHandlers = [];
    }

    public function has(string $flowId): bool
    {
        return isset($this->handlers[$flowId]);
    }

    public function isProgrammingAnchored(string $flowId): bool
    {
        return in_array($flowId, RouterRuntimeCanon::PROGRAMMING_FLOW_IDS, true);
    }

    public function handlerFor(string $flowId): SpecialistFlowHandlerContract
    {
        return $this->handlers[$flowId] ?? $this->handlers[SpecialistFlowsCanon::FALLBACK_FLOW];
    }

    /**
     * Canonical flow_ids this registry owns (excludes programming-anchored).
     *
     * @return array<int,string>
     */
    public function ownedFlowIds(): array
    {
        return array_keys($this->handlers);
    }
}
