<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * CROSS-LEVERAGE REGISTRY — the single canonical MANIFEST of every cross-leverage primitive in the Loop area,
 * paired with the consumers each is INTENDED to light. Today these primitives are scattered with no inventory
 * (we cannot prove which sites are armed without a hand grep); this registry is the fact-driven source of truth
 * the Multi-Site Wiring Planner (wave 8.2) and the Armed Coverage Reporter (wave 8.4) consume.
 *
 * PURE DECLARATION: it computes NO score, ranks NOTHING, mutates no code, merges nothing — it only declares
 * {primitive_id, file_path, intended_consumer_paths, config_flag, status}. The pétreo floor is preserved: a
 * read-only manifest can never arm or merge anything. Flag default OFF
 * (ATLAS_LOOP_CROSS_LEVERAGE_REGISTRY_ENABLED=false) ⇒ {@see primitives} returns [] (byte-identical no-op).
 */
final class AtlasLoopCrossLeverageRegistry
{
    public const SCHEMA = 'atlas.loop.cross_leverage_registry.v1';

    /** A primitive is DECLARED (in this manifest), INTENDED (consumers named), WIRED (a call-site exists), or ARMED (flag on). */
    public const STATUS_DECLARED = 'declared';

    public const STATUS_INTENDED = 'intended';

    public const STATUS_WIRED = 'wired';

    public const STATUS_ARMED = 'armed';

    /**
     * The hand-declared manifest. Every path is repo-relative and MUST resolve (the registry test asserts
     * file_exists on each, so the manifest can never drift to a dead symbol). Consumers are all under
     * app/Services/Ai/AutonomousEvolution/.
     *
     * @var list<array{primitive_id:string, file_path:string, config_flag:string, status:string, intended_consumer_paths:list<string>}>
     */
    private const MANIFEST = [
        [
            'primitive_id' => 'atlas_loop_leverage_selector',
            'file_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopLeverageSelector.php',
            'config_flag' => 'atlas.loop.leverage_first_enabled',
            'status' => self::STATUS_INTENDED,
            'intended_consumer_paths' => [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopOriginationPipeline.php',
                'app/Services/Ai/AutonomousEvolution/AtlasLoopOriginationProducer.php',
                'app/Services/Ai/AutonomousEvolution/Campaign/AtlasLoopCampaignSupervisor.php',
            ],
        ],
        [
            'primitive_id' => 'atlas_loop_cross_type_leverage_selector',
            'file_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopCrossTypeLeverageSelector.php',
            'config_flag' => 'atlas.loop.cross_type_leverage_enabled',
            'status' => self::STATUS_INTENDED,
            'intended_consumer_paths' => [
                'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopDocGapSupplyLane.php',
                'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopDedupSupplyLane.php',
                'app/Services/Ai/AutonomousEvolution/AtlasLoopOrphanWiringExecutionAdapter.php',
            ],
        ],
        [
            'primitive_id' => 'atlas_loop_anti_farm_floor',
            'file_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopAntiFarmFloor.php',
            'config_flag' => 'atlas.loop.anti_farm_floor_enabled',
            'status' => self::STATUS_WIRED,
            'intended_consumer_paths' => [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopOriginationPipeline.php',
                'app/Services/Ai/AutonomousEvolution/AtlasLoopOrphanWiringExecutionAdapter.php',
            ],
        ],
        [
            'primitive_id' => 'atlas_loop_architect_phase_gate',
            'file_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopArchitectPhaseGate.php',
            'config_flag' => 'atlas.loop.architect_phase_enabled',
            'status' => self::STATUS_INTENDED,
            'intended_consumer_paths' => [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopOriginationPipeline.php',
                'app/Services/Ai/AutonomousEvolution/AtlasLoopOriginationProducer.php',
            ],
        ],
    ];

    /**
     * The cross-leverage manifest, or [] when the registry flag is OFF (default). Read-only, no side effects.
     *
     * @return list<array{primitive_id:string, file_path:string, config_flag:string, status:string, intended_consumer_paths:list<string>}>
     */
    public function primitives(): array
    {
        if (! (bool) config('atlas.loop.cross_leverage_registry_enabled', false)) {
            return []; // flag OFF ⇒ byte-identical no-op
        }

        return self::MANIFEST;
    }
}
