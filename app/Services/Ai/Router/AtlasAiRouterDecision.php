<?php

declare(strict_types=1);

namespace App\Services\Ai\Router;

use InvalidArgumentException;

final class AtlasAiRouterDecision
{
    public const SCHEMA_VERSION = 'atlas.ai.router.flow_decision.v1';

    public const FLOW_DEV = 'atlas_dev';

    public const FLOW_RESEARCH = 'atlas_research';

    public const FLOW_EXPLAIN = 'atlas_explain';

    public const FLOW_DEBUG = 'atlas_debug';

    public const FLOW_REVIEW = 'atlas_review';

    public const FLOW_PLAN = 'atlas_plan';

    public const FLOW_CONVERSATION = 'atlas_conversation';

    public const FLOW_FORGE = 'atlas_forge';

    // RouterRuntime canon flows (superset adapter): the legacy decision can
    // now carry every RouterRuntimeCanon::ALLOWED_FLOW_IDS flow, so the
    // Hyperflow chain's routing reaches the specialist flow contracts that
    // were previously unreachable (the legacy heuristics never emitted them).
    public const FLOW_FINANCE = 'atlas_finance';

    public const FLOW_MARKETING = 'atlas_marketing';

    public const FLOW_STRATEGY = 'atlas_strategy';

    public const FLOW_CYBER = 'atlas_cyber';

    public const FLOW_PERSONAL_DEVELOPMENT = 'atlas_personal_development';

    public const FLOW_AUTOMATION = 'atlas_automation';

    public const FLOWS = [
        self::FLOW_DEV,
        self::FLOW_RESEARCH,
        self::FLOW_EXPLAIN,
        self::FLOW_DEBUG,
        self::FLOW_REVIEW,
        self::FLOW_PLAN,
        self::FLOW_CONVERSATION,
        self::FLOW_FORGE,
        self::FLOW_FINANCE,
        self::FLOW_MARKETING,
        self::FLOW_STRATEGY,
        self::FLOW_CYBER,
        self::FLOW_PERSONAL_DEVELOPMENT,
        self::FLOW_AUTOMATION,
    ];

    public function __construct(
        public readonly string $flowId,
        public readonly string $flowOrigin,
        public readonly string $commandIntent,
        public readonly string $routingReason,
        public readonly string $routingConfidence,
        public readonly array $handoffPayload,
        public readonly array $alternativeFlowIds = [],
    ) {
        if (! in_array($flowId, self::FLOWS, true)) {
            throw new InvalidArgumentException('Unknown Atlas AI flow id: '.$flowId);
        }
    }

    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'flow_id' => $this->flowId,
            'flow_origin' => $this->flowOrigin,
            'command_intent' => $this->commandIntent,
            'routing_reason' => $this->routingReason,
            'routing_confidence' => $this->routingConfidence,
            'handoff_payload' => $this->handoffPayload,
            'alternative_flow_ids' => $this->alternativeFlowIds,
        ];
    }
}
