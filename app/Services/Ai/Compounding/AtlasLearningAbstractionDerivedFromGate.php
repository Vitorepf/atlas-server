<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

/**
 * MULTJ-06 — deterministic gate for level-3 principle `derived_from[]` refs.
 *
 * Every ref must resolve against the pattern index built by the ladder
 * aggregator. Broken or missing refs ⇒ reject (author≠judge: the frontier
 * authors the claim; this gate judges structural lineage).
 */
final class AtlasLearningAbstractionDerivedFromGate
{
    public const SCHEMA_VERSION = 'atlas.ai.learning_abstraction_derived_from_gate.v1';

    public const REASON_OK = 'ok';

    public const REASON_EMPTY = 'derived_from_empty';

    public const REASON_UNRESOLVABLE = 'derived_from_unresolvable';

    /**
     * @param  array<string,mixed>  $proposal
     * @param  array<string,array<string,mixed>>  $patternIndex  keyed by pattern ref
     * @return array{admit:bool,reason:string,unresolved:list<string>,resolved:list<string>}
     */
    public function assess(array $proposal, array $patternIndex): array
    {
        $derivedFrom = $proposal['derived_from'] ?? [];
        if (! is_array($derivedFrom) || $derivedFrom === []) {
            return [
                'admit' => false,
                'reason' => self::REASON_EMPTY,
                'unresolved' => [],
                'resolved' => [],
            ];
        }

        $resolved = [];
        $unresolved = [];
        foreach ($derivedFrom as $ref) {
            if (! is_string($ref)) {
                $unresolved[] = (string) $ref;

                continue;
            }
            $trim = trim($ref);
            if ($trim === '') {
                continue;
            }
            if (isset($patternIndex[$trim])) {
                $resolved[] = $trim;
            } else {
                $unresolved[] = $trim;
            }
        }

        if ($unresolved !== []) {
            return [
                'admit' => false,
                'reason' => self::REASON_UNRESOLVABLE,
                'unresolved' => $unresolved,
                'resolved' => $resolved,
            ];
        }

        return [
            'admit' => true,
            'reason' => self::REASON_OK,
            'unresolved' => [],
            'resolved' => $resolved,
        ];
    }
}
