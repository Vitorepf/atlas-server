<?php

declare(strict_types=1);

/**
 * Elite compaction obra — freeze, keep-list, loop-command sunset.
 *
 * Governed by docs/engineering-knowledge-base/atlas-autonomos-live-system.md
 * and the Defatoração Atlas Elite plan (v2).
 */
return [
    'freeze' => [
        'active' => false,
        'started_at' => '2026-07-08',
        'ended_at' => '2026-07-08',
        'lift_reason' => 'Obra 3 final-dod green (AUT-01 / ELITE-05).',
        'blocks_autonomos_block_origination' => false,
        'reason' => 'Elite compaction obra — Autônomos must not originate new ACOS blocks during defactoration.',
    ],

    'loop_commands' => [
        'deprecated_at' => '2026-07-08',
        'hard_remove_after' => '2026-07-08', // operator override: 90d alias waived for elite residual
        'operator_override_90d' => true,
        'operator_override_reason' => 'Operator authorized skip of Self-Construction 90d alias rule for ACDE loop command hard-delete (elite residual obra; rule applies to other SCOS rename contexts).',
        'replacement_family' => ['atlas:brain:*', 'atlas:task:*'],
        'message' => 'ACDE Loop commands are dead. Use atlas:brain:* (cérebro) or atlas:task:* (músculo). See atlas-autonomos-live-system.md.',
    ],

    'acde_keep_list_alias_note' => 'Obra 1: AtlasAutonomosMasterSwitch aliases AtlasLoopMasterSwitch; other keep-list classes retain AtlasLoop* names until fatia rename.',
    'acde_keep_list_aliases' => [
        'AtlasAutonomosMasterSwitch' => 'AtlasLoopMasterSwitch',
        'AtlasBrainScopeComprehensionModel' => 'AtlasLoopScopeComprehensionModel',
        'AtlasBrainScopeComprehensionModelBuilder' => 'AtlasLoopScopeComprehensionModelBuilder',
        'AtlasBrainScopeComprehensionQuery' => 'AtlasLoopScopeComprehensionQuery',
        'AtlasBrainOriginationPipeline' => 'AtlasLoopOriginationPipeline',
    ],
    'loop_legacy_doc_banner' => '> ⚰️ **LEGADO ACDE / Loop MORTO** — Autônomos vivo = `atlas:brain:*` + `atlas:task:*`. Keep-list `AtlasLoop*` = vivo sob nome legado. Ver `atlas-autonomos-live-system.md`.',
    'acde_keep_list' => [
        'AtlasLoopHarnessGuard',
        'AtlasLoopMasterSwitch',
        'AtlasLoopScopeComprehensionModel',
        'AtlasLoopScopeComprehensionModelBuilder',
        'AtlasLoopScopeComprehensionQuery',
        'AtlasLoopRefillerPayloadNormalizer',
        'AtlasLoopCortexRoleTokenSemanticDisambiguator',
        'AtlasLoopComprehensionCadenceService',
        'AtlasLoopProposalPromotionGate',
        'AtlasLoopSiblingTestResolver',
        'AtlasLoopGiveBackToReplenisherFeedback',
        'AtlasLoopLossObserverService',
        'AtlasLoopComprehensionOriginator',
        'AtlasLoopProjectionOutcomeLedger',
        'AtlasLoopOriginationPipeline',
        'AtlasLoopAmbitionLeapProposer',
        'AtlasLoopAutoArchitectureProposalService',
        'AtlasLoopAutoMergeService',
        'AtlasLoopComprehensionGroundingGate',
        'AtlasLoopContractGapScanner',
        'AtlasLoopFrontierGapModel',
        'AtlasLoopHeavyWorkSelector',
        'AtlasLoopLearningAppendService',
        'AtlasLoopMergeActuator',
        'AtlasLoopPatternLearningLedger',
        'AtlasLoopRefillerSupplyLaneCoordinator',
    ],

    'generated' => [
        'hot_path_enabled' => false,
        'quarantine_namespace' => 'App\\Services\\Ai\\Aaeos\\Quarantine',
        'contract_gate_class' => \App\Services\Ai\AgenticEngineeringOs\Support\AeosGeneratedContractGate::class,
        // Obra 3 / AAEOS-01: the only Generated/*.php with live non-wrapper callers.
        // Keep hot_path_enabled=false — these stay Generated, not Cognition migrate, until explicit decision.
        'allowlist' => [
            'AtlasLearningProposalDecisionService',
            'AtlasMemoryCognitiveImmuneLearningKernelService',
        ],
        // Obra 3 / AAEOS-02 / ACOS-06: Quarantine policy (not blind delete).
        // promote = restore to Generated/Cognition when live callers proven;
        // delete = orphan wrappers only via prune-generated;
        // document_survivor = leave in Quarantine with this note until scored.
        'quarantine_policy' => 'document_survivor',
        'quarantine_policy_note' => '307 Quarantine files: survivors documented; prune-generated only moves wrapper-only orphans; allowlist Generated never quarantined.',
    ],

    'scorecard' => [
        'dual_emit_v3' => true,
        'v4_schema' => 'atlas.cognition.scorecard.v4',
        'v4_module_count' => 15,
    ],

    'prune' => [
        'acde_sample_limit' => 200,
    ],
];
