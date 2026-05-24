<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Architecture;

use App\Console\Commands\AtlasForgeRivalsCommand;
use App\Services\Ai\Programming\ForgeRivals\Arms\AtlasForgeRivalsArmContractService;
use App\Services\Ai\Programming\ForgeRivals\Arms\AtlasForgeRivalsArmRegistryService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsArenaRunService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsArmCommandBuilderService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsProviderModelRegistryService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Forge Rivals · Provider Arena Core Certification (v2).
 *
 * Audit-level read-model that proves the arm registry + arm contract +
 * arena run service + run-arena action are wired end-to-end and honour
 * every Provider Arena Core canon safety rule. It NEVER unlocks
 * `external_rivals_certification` — that remains operator-approval-gated
 * and separately tracked.
 *
 * 19 invariants:
 *
 *   1.  arm_registry_available
 *   2.  arm_contract_service_available
 *   3.  arena_run_service_available
 *   4.  run_arena_action_wired
 *   5.  arms_action_exposes_registry
 *   6.  all_canonical_arms_declared
 *   7.  task_category_gate_enforced
 *   8.  declared_non_executable_blockers_honest
 *   9.  scripted_or_manual_cannot_forge_score
 *   10. arena_real_provider_requires_three_confirmations
 *   11. arena_never_unlocks_external_rivals
 *   12. arena_doc_canonical
 *   13. provider_model_registry_available
 *   14. models_action_exposes_registry
 *   15. arm_command_builder_centralized
 *   16. provider_arena_modes_declared
 *   17. provider_arena_real_executor_wired
 *   18. arena_contracts_flow_to_manifest_report_signal
 *   19. cursor_composer_meta_provider_command_shape_locked
 *
 * Schema: atlas.forge_rivals_provider_arena_core_certification.v1
 * Doc:    docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-core-v1.md
 */
class AtlasForgeRivalsProviderArenaCoreCertification
{
    public const SCHEMA_VERSION = 'atlas.forge_rivals_provider_arena_core_certification.v1';

    public const CERTIFICATION_KEY = 'atlas_forge_rivals_provider_arena_core_certification';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_MISSING_ARTIFACTS = 'missing_artifacts';

    /** @var list<string> */
    public const REQUIRED_INVARIANTS = [
        'arm_registry_available',
        'arm_contract_service_available',
        'arena_run_service_available',
        'run_arena_action_wired',
        'arms_action_exposes_registry',
        'all_canonical_arms_declared',
        'task_category_gate_enforced',
        'declared_non_executable_blockers_honest',
        'scripted_or_manual_cannot_forge_score',
        'arena_real_provider_requires_three_confirmations',
        'arena_never_unlocks_external_rivals',
        'arena_doc_canonical',
        'provider_model_registry_available',
        'models_action_exposes_registry',
        'arm_command_builder_centralized',
        'provider_arena_modes_declared',
        'provider_arena_real_executor_wired',
        'arena_contracts_flow_to_manifest_report_signal',
        'cursor_composer_meta_provider_command_shape_locked',
    ];

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(array $options = []): array
    {
        $repoRoot = $this->resolveRepoRoot($options);
        $invariants = $this->invariants($repoRoot);
        $artifacts = $this->artifacts($repoRoot);
        $missing = $this->collectMissingArtifacts($artifacts);

        $hasBlocked = false;
        foreach ($invariants as $row) {
            if (! (bool) ($row['ok'] ?? false)) {
                $hasBlocked = true;
                break;
            }
        }

        $status = match (true) {
            $missing !== [] => self::STATUS_MISSING_ARTIFACTS,
            $hasBlocked => self::STATUS_BLOCKED,
            default => self::STATUS_AVAILABLE,
        };

        $blockers = [];
        foreach ($invariants as $name => $row) {
            if (! (bool) ($row['ok'] ?? false)) {
                $blockers[] = $name.'_blocked';
            }
        }
        foreach ($missing as $entry) {
            $blockers[] = $entry;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'certification_key' => self::CERTIFICATION_KEY,
            'status' => $status,
            'ok' => $status === self::STATUS_AVAILABLE,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'evidence_command' => 'php artisan atlas:forge:rivals audit --json',
            'invariants' => $invariants,
            'invariants_all_true' => $status === self::STATUS_AVAILABLE,
            'invariants_summary' => array_map(static fn (array $r): bool => (bool) ($r['ok'] ?? false), $invariants),
            'artifacts' => $artifacts,
            'missing_artifacts' => $missing,
            'blockers' => array_values(array_unique($blockers)),
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Provider Arena Core v1 NEVER unblocks external_rivals_certification by itself.',
            'related_docs' => [
                'docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-core-v1.md',
                'docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md',
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function invariants(string $repoRoot): array
    {
        $out = [];
        foreach (self::REQUIRED_INVARIANTS as $name) {
            $out[$name] = $this->evaluateInvariant($name, $repoRoot);
        }

        return $out;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function artifacts(string $repoRoot): array
    {
        return [
            'arm_registry' => [
                'class' => AtlasForgeRivalsArmRegistryService::class,
                'present' => class_exists(AtlasForgeRivalsArmRegistryService::class),
            ],
            'arm_contract' => [
                'class' => AtlasForgeRivalsArmContractService::class,
                'present' => class_exists(AtlasForgeRivalsArmContractService::class),
            ],
            'arena_run_service' => [
                'class' => AtlasForgeRivalsArenaRunService::class,
                'present' => class_exists(AtlasForgeRivalsArenaRunService::class),
            ],
            'provider_model_registry' => [
                'class' => AtlasForgeRivalsProviderModelRegistryService::class,
                'present' => class_exists(AtlasForgeRivalsProviderModelRegistryService::class),
            ],
            'arm_command_builder' => [
                'class' => AtlasForgeRivalsArmCommandBuilderService::class,
                'present' => class_exists(AtlasForgeRivalsArmCommandBuilderService::class),
            ],
            'canonical_doc' => [
                'path' => 'docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-core-v1.md',
                'present' => is_file($repoRoot.'/docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-core-v1.md'),
            ],
            'provider_arena_v2_doc' => [
                'path' => 'docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-v2.md',
                'present' => is_file($repoRoot.'/docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-v2.md'),
            ],
        ];
    }

    /**
     * @return array{ok:bool,status:string,description:string,check:string,evidence:list<string>}
     */
    private function evaluateInvariant(string $name, string $repoRoot): array
    {
        $registryFile = $repoRoot.'/app/Services/Ai/Programming/ForgeRivals/Arms/AtlasForgeRivalsArmRegistryService.php';
        $contractFile = $repoRoot.'/app/Services/Ai/Programming/ForgeRivals/Arms/AtlasForgeRivalsArmContractService.php';
        $arenaFile = $repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsArenaRunService.php';
        $modeFile = $repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsModeRegistry.php';
        $modelRegistryFile = $repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderModelRegistryService.php';
        $commandBuilderFile = $repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsArmCommandBuilderService.php';
        $runRealFile = $repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunRealService.php';
        $reportFile = $repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportService.php';
        $commandFile = $repoRoot.'/app/Console/Commands/AtlasForgeRivalsCommand.php';
        $dispatcherFile = $repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsActionDispatcher.php';
        $doc = $repoRoot.'/docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-core-v1.md';
        $docV2 = $repoRoot.'/docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-v2.md';

        switch ($name) {
            case 'arm_registry_available':
                return [
                    'ok' => class_exists(AtlasForgeRivalsArmRegistryService::class)
                        && count(AtlasForgeRivalsArmRegistryService::ARMS) === 10,
                    'status' => 'slice_8',
                    'description' => 'Arm registry exists and declares the canonical runners.',
                    'check' => 'class exists + ARMS count is 10',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/Arms/AtlasForgeRivalsArmRegistryService.php'],
                ];

            case 'arm_contract_service_available':
                $src = $this->readFile($contractFile);

                return [
                    'ok' => class_exists(AtlasForgeRivalsArmContractService::class)
                        && str_contains($src, 'atlas.forge.rivals.arm_contract.v1'),
                    'status' => 'slice_8',
                    'description' => 'Arm contract service exists and declares the canonical schema.',
                    'check' => 'class exists + source carries schema constant',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/Arms/AtlasForgeRivalsArmContractService.php'],
                ];

            case 'arena_run_service_available':
                $src = $this->readFile($arenaFile);

                return [
                    'ok' => class_exists(AtlasForgeRivalsArenaRunService::class)
                        && str_contains($src, 'atlas.forge.rivals.provider_arena_run.v1'),
                    'status' => 'slice_8',
                    'description' => 'Arena run service exists with canonical schema.',
                    'check' => 'class exists + source carries schema constant',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsArenaRunService.php'],
                ];

            case 'run_arena_action_wired':
                $cmdSrc = $this->readFile($commandFile);
                $dispatcherSrc = $this->readFile($dispatcherFile);

                return [
                    'ok' => in_array('run-arena', AtlasForgeRivalsCommand::ACTIONS, true)
                        && str_contains($cmdSrc, 'run-arena')
                        && str_contains($cmdSrc, '--arm-a')
                        && str_contains($cmdSrc, '--arm-b')
                        && str_contains($cmdSrc, '--task-category')
                        && str_contains($dispatcherSrc, "'run-arena'"),
                    'status' => 'slice_8',
                    'description' => '`run-arena` is in ACTIONS and dispatcher routes it; CLI exposes the arena flags.',
                    'check' => 'ACTIONS contains run-arena + signature has arm/task flags + dispatcher routes it',
                    'evidence' => [
                        'app/Console/Commands/AtlasForgeRivalsCommand.php',
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsActionDispatcher.php',
                    ],
                ];

            case 'arms_action_exposes_registry':
                $dispatcherSrc = $this->readFile($dispatcherFile);

                return [
                    'ok' => in_array('arms', AtlasForgeRivalsCommand::ACTIONS, true)
                        && str_contains($dispatcherSrc, 'armsSnapshot'),
                    'status' => 'slice_8',
                    'description' => '`arms` action exposes the registry snapshot to UIs / audits.',
                    'check' => 'ACTIONS contains arms + dispatcher exposes armsSnapshot()',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsActionDispatcher.php',
                    ],
                ];

            case 'all_canonical_arms_declared':
                $allArms = [
                    AtlasForgeRivalsArmRegistryService::ARM_ATLAS_FORGE,
                    AtlasForgeRivalsArmRegistryService::ARM_ATLAS_DEV,
                    AtlasForgeRivalsArmRegistryService::ARM_CLAUDE_CODE,
                    AtlasForgeRivalsArmRegistryService::ARM_CODEX_CLI,
                    AtlasForgeRivalsArmRegistryService::ARM_GEMINI_CLI,
                    AtlasForgeRivalsArmRegistryService::ARM_CURSOR_CLI,
                    AtlasForgeRivalsArmRegistryService::ARM_COMPOSER_2_5,
                    AtlasForgeRivalsArmRegistryService::ARM_SCRIPTED_RUNNER,
                    AtlasForgeRivalsArmRegistryService::ARM_MANUAL_RUNNER,
                    AtlasForgeRivalsArmRegistryService::ARM_FUTURE_RUNNER,
                ];

                return [
                    'ok' => count(array_diff($allArms, AtlasForgeRivalsArmRegistryService::ARMS)) === 0,
                    'status' => 'slice_8',
                    'description' => 'All canonical arms are present in ARMS.',
                    'check' => 'array_diff(expected, ARMS) === []',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/Arms/AtlasForgeRivalsArmRegistryService.php'],
                ];

            case 'task_category_gate_enforced':
                $contractSrc = $this->readFile($contractFile);

                return [
                    'ok' => str_contains($contractSrc, 'task_category_unknown')
                        && str_contains($contractSrc, 'task_category_not_supported_by_arm'),
                    'status' => 'slice_8',
                    'description' => 'Arm contract emits honest blockers when task_category is unknown or unsupported.',
                    'check' => 'contract source references both blocker codes',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/Arms/AtlasForgeRivalsArmContractService.php'],
                ];

            case 'declared_non_executable_blockers_honest':
                $contractSrc = $this->readFile($contractFile);
                $registrySrc = $this->readFile($registryFile);

                return [
                    'ok' => str_contains($contractSrc, 'arm_runner_not_yet_executable')
                        && AtlasForgeRivalsArmRegistryService::STATUS_NOT_YET_EXECUTABLE === 'not_yet_executable'
                        && str_contains($registrySrc, 'future_runner_is_placeholder_only')
                        && str_contains($registrySrc, 'scripted_runner_protocol_v1_pending')
                        && str_contains($registrySrc, 'manual_runner_protocol_v1_pending'),
                    'status' => 'slice_8',
                    'description' => 'Declared non-executable arms emit honest blockers with specific reason codes; future_runner is placeholder-only.',
                    'check' => 'contract emits arm_runner_not_yet_executable + registry carries STATUS_NOT_YET_EXECUTABLE constant and remaining non-executable reason codes',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/Arms/AtlasForgeRivalsArmContractService.php',
                        'app/Services/Ai/Programming/ForgeRivals/Arms/AtlasForgeRivalsArmRegistryService.php',
                    ],
                ];

            case 'scripted_or_manual_cannot_forge_score':
                $registrySrc = $this->readFile($registryFile);

                return [
                    'ok' => str_contains($registrySrc, 'scripted_or_manual_cannot_forge_score')
                        && str_contains($registrySrc, "'max_score_without_evidence' => 0"),
                    'status' => 'slice_8',
                    'description' => 'Scripted/manual safety contract forbids score without evidence.',
                    'check' => 'registry source carries both safety constants',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/Arms/AtlasForgeRivalsArmRegistryService.php'],
                ];

            case 'arena_real_provider_requires_three_confirmations':
                $arenaSrc = $this->readFile($arenaFile);

                return [
                    'ok' => str_contains($arenaSrc, 'runbook_reviewed')
                        && str_contains($arenaSrc, 'provider_cost')
                        && str_contains($arenaSrc, 'real_provider_call')
                        && str_contains($arenaSrc, 'missing_confirmation'),
                    'status' => 'slice_8',
                    'description' => 'Arena run service refuses real-provider runs without all three confirmations.',
                    'check' => 'arena run source references all three flags + missing_confirmation blocker',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsArenaRunService.php'],
                ];

            case 'arena_never_unlocks_external_rivals':
                $arenaSrc = $this->readFile($arenaFile);
                $registrySrc = $this->readFile($registryFile);
                $thisSrc = $this->readFile(__FILE__);

                return [
                    'ok' => str_contains($arenaSrc, 'separated_from_external_rivals_certification')
                        && str_contains($registrySrc, 'never_unlocks_external_rivals_certification')
                        && str_contains($thisSrc, "'separated_from' => 'external_rivals_certification'"),
                    'status' => 'slice_8',
                    'description' => 'Arena layer NEVER unlocks external_rivals_certification.',
                    'check' => 'arena/registry/cert all assert separation from external_rivals_certification',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsArenaRunService.php',
                        'app/Services/Ai/Programming/ForgeRivals/Arms/AtlasForgeRivalsArmRegistryService.php',
                        'app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsProviderArenaCoreCertification.php',
                    ],
                ];

            case 'arena_doc_canonical':
                $docSrc = $this->readFile($doc)."\n".$this->readFile($docV2);

                return [
                    'ok' => is_file($doc)
                        && is_file($docV2)
                        && str_contains($docSrc, 'atlas.forge.rivals.provider_arena_run.v1')
                        && str_contains($docSrc, 'atlas-forge-rivals-provider-arena-v2')
                        && str_contains($docSrc, 'external_rivals_certification'),
                    'status' => 'provider_arena_v2',
                    'description' => 'Canonical docs declare the arena schema, v2 contract and the external_rivals separation rule.',
                    'check' => 'core/v2 docs exist + contain arena run schema + v2 id + external_rivals_certification reference',
                    'evidence' => [
                        'docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-core-v1.md',
                        'docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-v2.md',
                    ],
                ];

            case 'provider_model_registry_available':
                $src = $this->readFile($modelRegistryFile);

                return [
                    'ok' => class_exists(AtlasForgeRivalsProviderModelRegistryService::class)
                        && str_contains($src, 'atlas.forge.rivals.provider_model_registry.v1')
                        && str_contains($src, "'claude'")
                        && str_contains($src, "'codex'")
                        && str_contains($src, "'gemini'")
                        && str_contains($src, "'cursor'")
                        && str_contains($src, "'composer'")
                        && str_contains($src, "'gpt-5.5'")
                        && str_contains($src, "'claude_opus'")
                        && str_contains($src, "'gemini-pro'")
                        && str_contains($src, "'composer-2.5'"),
                    'status' => 'provider_arena_v2',
                    'description' => 'Provider/model ids, aliases and defaults are centralized in ProviderModelRegistry.',
                    'check' => 'registry class exists + declares Claude/Codex/Gemini/Cursor/Composer + Opus/GPT-5.5/Gemini Pro/Composer 2.5',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderModelRegistryService.php'],
                ];

            case 'models_action_exposes_registry':
                $cmdSrc = $this->readFile($commandFile);
                $dispatcherSrc = $this->readFile($dispatcherFile);

                return [
                    'ok' => in_array('models', AtlasForgeRivalsCommand::ACTIONS, true)
                        && str_contains($cmdSrc, 'arm-a-model')
                        && str_contains($cmdSrc, 'arm-b-model')
                        && str_contains($dispatcherSrc, "'models'")
                        && str_contains($dispatcherSrc, 'modelsSnapshot'),
                    'status' => 'provider_arena_v2',
                    'description' => '`models` action exposes canonical provider/model registry and CLI accepts per-arm models.',
                    'check' => 'ACTIONS contains models + dispatcher modelsSnapshot + CLI arm-a-model/arm-b-model flags',
                    'evidence' => [
                        'app/Console/Commands/AtlasForgeRivalsCommand.php',
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsActionDispatcher.php',
                    ],
                ];

            case 'arm_command_builder_centralized':
                $src = $this->readFile($commandBuilderFile);
                $runRealSrc = $this->readFile($runRealFile);

                return [
                    'ok' => class_exists(AtlasForgeRivalsArmCommandBuilderService::class)
                        && str_contains($src, 'atlas.forge.rivals.arm_command_builder.v1')
                        && str_contains($src, 'claudeCommand')
                        && str_contains($src, 'codexCommand')
                        && str_contains($src, 'geminiCommand')
                        && str_contains($src, 'cursorCommand')
                        && str_contains($runRealSrc, 'AtlasForgeRivalsArmCommandBuilderService $commandBuilder')
                        && str_contains($runRealSrc, '$this->commandBuilder->build'),
                    'status' => 'provider_arena_v2',
                    'description' => 'Provider command construction is centralized and RunReal delegates to the builder.',
                    'check' => 'builder class exists + supports claude/codex/gemini + RunReal injects and calls it',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsArmCommandBuilderService.php',
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunRealService.php',
                    ],
                ];

            case 'provider_arena_modes_declared':
                $modeSrc = $this->readFile($modeFile);
                $runRealSrc = $this->readFile($runRealFile);

                return [
                    'ok' => str_contains($modeSrc, 'MODE_PROVIDER_ARENA')
                        && str_contains($modeSrc, 'MODE_PROVIDER_PURE')
                        && str_contains($modeSrc, 'provider_arena')
                        && str_contains($modeSrc, 'provider_pure')
                        && str_contains($runRealSrc, 'MODE_PROVIDER_ARENA')
                        && str_contains($runRealSrc, 'MODE_PROVIDER_PURE'),
                    'status' => 'provider_arena_v2',
                    'description' => 'Provider Arena v2 modes are registered and admitted by RunReal.',
                    'check' => 'ModeRegistry declares provider_arena/provider_pure + RunReal ALLOWED_MODES includes them',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsModeRegistry.php',
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunRealService.php',
                    ],
                ];

            case 'provider_arena_real_executor_wired':
                $arenaSrc = $this->readFile($arenaFile);

                return [
                    'ok' => str_contains($arenaSrc, 'runProviderArenaReal')
                        && str_contains($arenaSrc, "'executor' => 'provider_arena_v2'")
                        && str_contains($arenaSrc, '$this->setup->provision')
                        && str_contains($arenaSrc, '$this->runReal->run')
                        && str_contains($arenaSrc, 'collect-evidence-final')
                        && str_contains($arenaSrc, 'replay-final')
                        && str_contains($arenaSrc, 'adjudicate')
                        && str_contains($arenaSrc, 'report'),
                    'status' => 'provider_arena_v2',
                    'description' => 'Arena v2 has a real executor path through setup, RunReal, evidence, replay, adjudication and report.',
                    'check' => 'ArenaRun source carries runProviderArenaReal with setup/run-real/collect/replay/adjudicate/report phases',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsArenaRunService.php'],
                ];

            case 'arena_contracts_flow_to_manifest_report_signal':
                $runRealSrc = $this->readFile($runRealFile);
                $reportSrc = $this->readFile($reportFile);

                return [
                    'ok' => str_contains($runRealSrc, "'arena_contracts' => \$this->arenaContractSummary")
                        && str_contains($runRealSrc, 'resolved_model_id')
                        && str_contains($reportSrc, 'deriveArenaArm')
                        && str_contains($reportSrc, 'providerPairKey')
                        && str_contains($reportSrc, 'renderArmIdentityTable')
                        && str_contains($reportSrc, "'arm_a' => \$case['arm_a']")
                        && str_contains($reportSrc, 'never_changes_atlas_decide_topology')
                        && str_contains($reportSrc, 'Rivals emits measured evidence; Atlas Decide decides model routing.'),
                    'status' => 'provider_arena_v2',
                    'description' => 'Resolved arm/provider/model ids flow into manifest, report, provider pair and advisory signal.',
                    'check' => 'RunReal writes arena_contracts; Report derives arena arms, provider pair, arm identity table and advisory-only signal rows',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunRealService.php',
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportService.php',
                    ],
                ];

            case 'cursor_composer_meta_provider_command_shape_locked':
                $builderSrc = $this->readFile($commandBuilderFile);
                $arenaSrc = $this->readFile($arenaFile);
                $readinessFile = $repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderArenaReadinessService.php';
                $readinessSrc = $this->readFile($readinessFile);

                return [
                    'ok' => str_contains($builderSrc, 'cursorCommand')
                        && str_contains($builderSrc, "'cursor_cli'")
                        && str_contains($builderSrc, "'composer_2_5'")
                        && str_contains($builderSrc, "'--print'")
                        && str_contains($builderSrc, "'--output-format'")
                        && str_contains($builderSrc, "'stream-json'")
                        && str_contains($builderSrc, "'--model'")
                        && str_contains($builderSrc, "'prompt_transport' => 'stdin'")
                        && str_contains($builderSrc, 'atlas.forge.rivals.cursor_command_shape_summary.v1')
                        && str_contains($builderSrc, "'governed_cursor_cli_shape' => true")
                        && str_contains($builderSrc, "'force_absent' => true")
                        && str_contains($builderSrc, "'resume_absent' => true")
                        && str_contains($arenaSrc, 'command_shape_summary')
                        && str_contains($arenaSrc, "'prompt_transport' => \$plan['prompt_transport'] ?? 'argv'")
                        && str_contains($readinessSrc, 'command_shape_summary')
                        && str_contains($readinessSrc, "'prompt_transport' => \$built['prompt_transport'] ?? 'argv'"),
                    'status' => 'provider_arena_v3',
                    'description' => 'Cursor CLI and Composer 2.5 are locked to the governed meta-provider command shape.',
                    'check' => 'builder emits cursor-agent print/stream-json/model/stdin shape + arena/readiness propagate command_shape_summary and prompt_transport',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsArmCommandBuilderService.php',
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsArenaRunService.php',
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderArenaReadinessService.php',
                    ],
                ];
        }

        return [
            'ok' => false,
            'status' => 'unknown_invariant',
            'description' => 'Unknown arena core invariant: '.$name,
            'check' => '',
            'evidence' => [],
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $artifacts
     * @return list<string>
     */
    private function collectMissingArtifacts(array $artifacts): array
    {
        $missing = [];
        foreach ($artifacts as $key => $artifact) {
            if (! (bool) ($artifact['present'] ?? false)) {
                $missing[] = (string) $key.'_missing';
            }
        }

        return $missing;
    }

    private function readFile(string $path): string
    {
        if (! is_file($path)) {
            return '';
        }

        return (string) file_get_contents($path);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function resolveRepoRoot(array $options): string
    {
        $explicit = $options['workspace'] ?? null;
        if (is_string($explicit) && trim($explicit) !== '' && is_dir(trim($explicit))) {
            return rtrim(trim($explicit), '/');
        }

        if (function_exists('base_path')) {
            return rtrim(base_path(), '/');
        }

        return rtrim(dirname(__DIR__, 4), '/');
    }
}
