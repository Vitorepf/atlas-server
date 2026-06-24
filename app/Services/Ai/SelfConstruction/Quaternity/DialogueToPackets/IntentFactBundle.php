<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets;

/**
 * VERBATIM operator-intent FACTS captured by the Maestro intent surface — NOT a free-form LLM rewrite. Each
 * phrase is preserved exactly as the operator typed it; scope_tags are the primitive areas the surface tagged
 * (e.g. "loop", "cortex", "maestro"). The proposer reads this and the {@see CortexGroundingSnapshot} to emit a
 * deterministic candidate packet shape — no inference about what the operator "really meant".
 */
final class IntentFactBundle
{
    /**
     * @param  list<string>  $phrases       verbatim operator phrases, oldest→newest
     * @param  list<int>     $timestamps    UTC unix seconds, one per phrase (same length as $phrases)
     * @param  list<string>  $scopeTags     primitive scopes the intent surface tagged
     * @param  list<string>  $verbs         action verbs lifted from the phrases ("add", "fix", "audit", …)
     */
    public function __construct(
        public readonly array $phrases,
        public readonly array $timestamps,
        public readonly array $scopeTags,
        public readonly array $verbs = [],
    ) {
    }
}
