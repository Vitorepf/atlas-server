<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;

/**
 * SEED-boundary quality gate for the external brain.
 *
 * {@see AtlasTaskPacketQualityInspector::inspect()} runs UNIVERSALLY (replenisher, internal/minimal packets too),
 * so three excellence signals it emits — vague_objective, acceptance_not_runnable, blind_orphan_wiring_proxy —
 * are ADVISORY there on purpose: promoting them to its BLOCKING_DEFICIENCIES const would starve the
 * auto-replenisher. Here, at the brain's SEED boundary (where a cold worker actually receives an originated
 * packet), they are FATAL. This gate composes inspect() and intersects its deficiencies against those three —
 * it does NOT edit the universal inspector and does NOT enqueue.
 *
 * ponytail: the proxy detector this leans on is a substring proxy-term list (objectiveIsBlindOrphanWiringProxy)
 * — a paraphrase ("attach this dormant class to the flow") escapes it. That ceiling is fine: the real
 * anti-Goodhart backstop is S7 {@see AtlasBrainCycleProgressVerdict}, which only counts a cycle as progress
 * when doc+enqueue+non-rejected-class+grounded-citation ALL hold. This gate is the cheap first screen.
 */
final class AtlasBrainSeedQualityGate
{
    public const SCHEMA = 'atlas.brain.seed_quality_gate.v1';

    /**
     * The three advisory flags inspect() emits that this seed boundary treats as BLOCKING.
     *
     * @var list<string>
     */
    public const BRAIN_FATAL_ADVISORY = [
        'vague_objective',
        'acceptance_not_runnable',
        'blind_orphan_wiring_proxy',
    ];

    public function __construct(
        private readonly ?AtlasTaskPacketQualityInspector $inspector = null,
    ) {}

    /**
     * Evaluate a builder-shaped packet. Blocks on ANY universal BLOCKING deficiency plus the three advisory
     * flags this seed boundary promotes to fatal. Pure — no enqueue, no mutation.
     *
     * @param  array<string, mixed>  $packet
     * @return array{admit:bool, blocking:list<string>, reasons:list<string>}
     */
    public function evaluate(array $packet): array
    {
        $inspection = ($this->inspector ?? new AtlasTaskPacketQualityInspector)->inspect($packet);

        $deficiencies = array_values(array_map('strval', (array) ($inspection['deficiencies'] ?? [])));
        $universalBlocking = array_values(array_map('strval', (array) ($inspection['blocking_deficiencies'] ?? [])));
        $advisoryFatal = array_values(array_intersect($deficiencies, self::BRAIN_FATAL_ADVISORY));

        $blocking = array_values(array_unique(array_merge($universalBlocking, $advisoryFatal)));

        return [
            'admit' => $blocking === [],
            'blocking' => $blocking,
            'reasons' => $blocking,
        ];
    }
}
