<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;

use InvalidArgumentException;

/**
 * Atlas Dev never improvises on out-of-scope intents — instead the routing
 * layer declares which Atlas AI flow should handle the request.
 *
 * The flow names listed in {@see self::FLOWS} are the canonical receivers
 * referenced by surface adapters; this DTO does not invoke them, it merely
 * tells the surface where to forward the operator.
 */
final class DelegationSuggestion
{
    public const FLOW_RESEARCH = 'atlas_research';

    public const FLOW_EXPLAIN = 'atlas_explain';

    public const FLOW_DEBUG = 'atlas_debug';

    public const FLOW_CONVERSATION = 'atlas_conversation';

    public const FLOW_FORGE = 'atlas_forge';

    public const FLOWS = [
        self::FLOW_RESEARCH,
        self::FLOW_EXPLAIN,
        self::FLOW_DEBUG,
        self::FLOW_CONVERSATION,
        self::FLOW_FORGE,
    ];

    public function __construct(
        public readonly string $suggestedFlow,
        public readonly string $reason,
    ) {
        if (! in_array($suggestedFlow, self::FLOWS, true)) {
            throw new InvalidArgumentException(
                'DelegationSuggestion.suggestedFlow must be one of: '.implode('|', self::FLOWS)
            );
        }
        if ($reason === '') {
            throw new InvalidArgumentException('DelegationSuggestion.reason must be non-empty.');
        }
    }

    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'suggested_flow' => $this->suggestedFlow,
        ];
    }
}
