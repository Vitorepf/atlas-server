<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory;

final class MemoryScopeContradictionClassifier
{
    private const SCHEMA_VERSION = 'atlas.memory.scope_contradiction.v1';

    private const KIND_UNRELATED = 'unrelated';

    private const KIND_NO_CONTRADICTION = 'no_contradiction';

    private const KIND_SCOPE_REDUNDANT = 'scope_redundant';

    private const KIND_LEGITIMATE_SCOPE_OVERRIDE = 'legitimate_scope_override';

    private const KIND_DIRECT_SCOPE_CONTRADICTION = 'direct_scope_contradiction';

    /**
     * Memory types que disparam escalation humana, espelhando
     * AtlasMemoryConflictResolutionService::HIGH_RISK_MEMORY_TYPES (line 72).
     *
     * @var list<string>
     */
    private const HIGH_RISK_MEMORY_TYPES = [
        'decision',
        'architecture',
        'policy',
    ];

    /**
     * @param  array{key?: string, scope_rank?: int, polarity?: string, memory_type?: string}  $broader
     * @param  array{key?: string, scope_rank?: int, polarity?: string, memory_type?: string}  $narrower
     * @return array{
     *     schema_version: string,
     *     kind: string,
     *     override_legitimate: bool,
     *     escalate: bool,
     *     reasons: list<string>
     * }
     */
    public function classify(array $broader, array $narrower): array
    {
        $broaderKey = $this->stringValue($broader, 'key');
        $narrowerKey = $this->stringValue($narrower, 'key');

        $broaderPolarity = $this->stringValue($broader, 'polarity');
        $narrowerPolarity = $this->stringValue($narrower, 'polarity');

        $broaderRank = $this->intValue($broader, 'scope_rank');
        $narrowerRank = $this->intValue($narrower, 'scope_rank');

        $broaderMemoryType = $this->stringValue($broader, 'memory_type');

        $samePolarity = $broaderPolarity === $narrowerPolarity;
        $sameRank = $broaderRank === $narrowerRank;

        // Rule order: exactly 7, first match wins for kind.
        if ($broaderKey !== $narrowerKey) {
            // R1: keys differ.
            $kind = self::KIND_UNRELATED;
            $overrideLegitimate = false;
            $rule = 'R1';
        } elseif ($samePolarity && $sameRank) {
            // R2: same key + same polarity + same scope_rank.
            $kind = self::KIND_NO_CONTRADICTION;
            $overrideLegitimate = false;
            $rule = 'R2';
        } elseif ($samePolarity) {
            // R3: same key + same polarity + different scope_rank.
            $kind = self::KIND_SCOPE_REDUNDANT;
            $overrideLegitimate = true;
            $rule = 'R3';
        } elseif ($narrowerRank < $broaderRank) {
            // R4: same key + opposite polarity + narrower.scope_rank < broader.scope_rank.
            $kind = self::KIND_LEGITIMATE_SCOPE_OVERRIDE;
            $overrideLegitimate = true;
            $rule = 'R4';
        } else {
            // R5: same key + opposite polarity + equal scope_rank, AND the
            // fall-through where narrower.scope_rank > broader.scope_rank
            // (broader is the exception, narrower the wide contradicting rule).
            $kind = self::KIND_DIRECT_SCOPE_CONTRADICTION;
            $overrideLegitimate = false;
            $rule = 'R5';
        }

        // R6: escalation gate.
        $escalate = $kind === self::KIND_DIRECT_SCOPE_CONTRADICTION
            || ($kind === self::KIND_LEGITIMATE_SCOPE_OVERRIDE
                && in_array($broaderMemoryType, self::HIGH_RISK_MEMORY_TYPES, true));

        // R7: reasons lists the firing rule name(s).
        $reasons = [$rule.':'.$kind];

        if ($escalate) {
            $reasons[] = 'R6:escalate';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'kind' => $kind,
            'override_legitimate' => $overrideLegitimate,
            'escalate' => $escalate,
            'reasons' => $reasons,
        ];
    }

    private function stringValue(array $side, string $key): string
    {
        $value = $side[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    private function intValue(array $side, string $key): int
    {
        $value = $side[$key] ?? 0;

        return is_int($value) ? $value : (int) $value;
    }
}
