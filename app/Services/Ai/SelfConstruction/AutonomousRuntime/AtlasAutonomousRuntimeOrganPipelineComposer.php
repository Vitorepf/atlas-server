<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\AutonomousRuntime;

/**
 * Pure composer: snapshots of every Self-Construction ORGAN → ONE ordered cycle plan. NEVER calls
 * providers, shells, workers, git, or live runtimes.
 *
 * INPUT: facts array keyed by organ name:
 *   { control_plane:{...}, strategy_council:{...}, architecture_council:{...}, task_fabric:{...},
 *     maestro:{...}, worker_swarm:{...}, verification_court:{...}, merge_governor:{...},
 *     knowledge_sync:{...}, learning_transfer:{...} }
 *
 * OUTPUT:
 *   { schema, plan_status ∈ {ready,blocked}, ordered_stages:list<{organ, facts}>,
 *     missing_organs:list<string>, blockers:list<string> }
 *
 * INVARIANTS:
 *   - DETERMINISTIC: stages always emitted in ORGAN_ORDER.
 *   - Missing organ facts ⇒ recorded as 'missing_organ:<name>' blocker (never defaulted).
 *   - PURE: no I/O.
 */
final class AtlasAutonomousRuntimeOrganPipelineComposer
{
    public const SCHEMA = 'atlas.autonomousruntime.organ_pipeline.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    /** Canonical execution order — Control Plane decides first; Learning Transfer closes the loop. */
    public const ORGAN_ORDER = [
        'control_plane',
        'strategy_council',
        'architecture_council',
        'task_fabric',
        'maestro',
        'worker_swarm',
        'verification_court',
        'merge_governor',
        'knowledge_sync',
        'learning_transfer',
    ];

    /**
     * @param  array<string,array<string,mixed>>  $organFacts
     * @return array{schema:string, plan_status:string, ordered_stages:list<array{organ:string, facts:array<string,mixed>}>, missing_organs:list<string>, blockers:list<string>}
     */
    public function compose(array $organFacts): array
    {
        $stages = [];
        $missing = [];
        foreach (self::ORGAN_ORDER as $organ) {
            $facts = $organFacts[$organ] ?? null;
            if (! is_array($facts)) {
                $missing[] = $organ;

                continue;
            }
            $stages[] = ['organ' => $organ, 'facts' => $facts];
        }

        $blockers = array_map(static fn (string $o): string => 'missing_organ:'.$o, $missing);
        sort($blockers, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'plan_status' => $blockers === [] ? self::STATUS_READY : self::STATUS_BLOCKED,
            'ordered_stages' => $stages,
            'missing_organs' => $missing,
            'blockers' => $blockers,
        ];
    }
}
