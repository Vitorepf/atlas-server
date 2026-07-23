<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;


/**
 * Immutable result of {@see RoutingDecisionEngine::decide()}.
 *
 * `kind` is one of the five canonical routes; `blockers` lists the observable
 * conditions that downstream services must resolve (workspace missing,
 * blocking discovery ambiguity, intent_clarity=blocking, etc.). `reasons`
 * records the rule trace for audit. `delegation` is populated only when
 * `kind = delegate_to_other_flow` and names the Atlas AI flow the surface
 * should forward the operator to.
 */
final class RoutingDecision
{
    public const READ_ONLY_ANSWER = 'read_only_answer';

    public const ATLAS_DEV_FAST_PATH = 'atlas_dev_fast_path';

    public const FORGE_PROMOTION_PREVIEW = 'forge_promotion_preview';

    public const BLOCKED = 'blocked';

    public const DELEGATE_TO_OTHER_FLOW = 'delegate_to_other_flow';

    public const KINDS = [
        self::READ_ONLY_ANSWER,
        self::ATLAS_DEV_FAST_PATH,
        self::FORGE_PROMOTION_PREVIEW,
        self::BLOCKED,
        self::DELEGATE_TO_OTHER_FLOW,
    ];

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $blockers
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $reasons,
        public readonly array $blockers,
        public readonly ?DelegationSuggestion $delegation = null,
    ) {}

    public function isWrite(): bool
    {
        return $this->kind === self::ATLAS_DEV_FAST_PATH;
    }

    public function isBlocked(): bool
    {
        return $this->kind === self::BLOCKED;
    }

    public function isDelegation(): bool
    {
        return $this->kind === self::DELEGATE_TO_OTHER_FLOW;
    }

    public function suggestedFlow(): ?string
    {
        return $this->delegation?->suggestedFlow;
    }
}
