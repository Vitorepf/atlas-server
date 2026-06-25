<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Permissions;

use InvalidArgumentException;

/**
 * SINGLE SOURCE OF TRUTH for the per-phase required authority level. Adds an authority gradient
 * ABOVE the pétreo floor — it cannot weaken sandbox or constitution invariants.
 *
 * Phases (canon: docs/loop-canonical-definition.md):
 *   observe      → READ      (look at world facts, no proposal)
 *   comprehend   → READ      (model the scope, no proposal)
 *   originate    → PROPOSE   (originate a frontier, no write)
 *   project      → PROPOSE   (project candidates, no write)
 *   decompose    → PROPOSE   (split into packets, no write)
 *   implement    → WRITE     (touch allowed_files in scope)
 *   certify      → PROPOSE   (judge; emits proposals; merge is downstream)
 *   merge        → MERGE     (push commits onto main)
 *   learn        → PROPOSE   (record learning facts)
 *
 * Registry is pure: no IO, no provider calls, no static mutation.
 */
final class AtlasLoopPermissionLevelRegistry
{
    /** @var array<string, AtlasLoopPermissionLevel> */
    private const PHASE_LEVELS = [
        'certify' => AtlasLoopPermissionLevel::PROPOSE,
        'comprehend' => AtlasLoopPermissionLevel::READ,
        'decompose' => AtlasLoopPermissionLevel::PROPOSE,
        'implement' => AtlasLoopPermissionLevel::WRITE,
        'learn' => AtlasLoopPermissionLevel::PROPOSE,
        'merge' => AtlasLoopPermissionLevel::MERGE,
        'observe' => AtlasLoopPermissionLevel::READ,
        'originate' => AtlasLoopPermissionLevel::PROPOSE,
        'project' => AtlasLoopPermissionLevel::PROPOSE,
    ];

    public function requiredLevelFor(string $phase): AtlasLoopPermissionLevel
    {
        if (! array_key_exists($phase, self::PHASE_LEVELS)) {
            throw new InvalidArgumentException('unknown_loop_phase:'.$phase);
        }

        return self::PHASE_LEVELS[$phase];
    }

    /**
     * @return array<string,string>
     */
    public function serialized(): array
    {
        $out = [];
        foreach (self::PHASE_LEVELS as $phase => $level) {
            $out[$phase] = $level->label();
        }
        // Keys are already alphabetically declared; assert via explicit ksort for safety.
        ksort($out);

        return $out;
    }

    /**
     * @return list<string>
     */
    public function phases(): array
    {
        $phases = array_keys(self::PHASE_LEVELS);
        sort($phases, SORT_STRING);

        return $phases;
    }
}
