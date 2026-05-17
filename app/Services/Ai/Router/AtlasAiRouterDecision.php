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

    public const FLOWS = [
        self::FLOW_DEV,
        self::FLOW_RESEARCH,
        self::FLOW_EXPLAIN,
        self::FLOW_DEBUG,
        self::FLOW_REVIEW,
        self::FLOW_PLAN,
        self::FLOW_CONVERSATION,
        self::FLOW_FORGE,
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
