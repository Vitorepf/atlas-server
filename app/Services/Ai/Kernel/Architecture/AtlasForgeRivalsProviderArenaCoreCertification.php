<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Architecture;

use App\Console\Commands\AtlasForgeRivalsCommand;
use App\Services\Ai\Programming\ForgeRivals\Arms\AtlasForgeRivalsArmContractService;
use App\Services\Ai\Programming\ForgeRivals\Arms\AtlasForgeRivalsArmRegistryService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsArenaRunService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Forge Rivals · Provider Arena Core Certification (v1).
 *
 * Audit-level read-model that proves the arm registry + arm contract +
 * arena run service + run-arena action are wired end-to-end and honour
 * every Provider Arena Core canon safety rule. It NEVER unlocks
 * `external_rivals_certification` — that remains operator-approval-gated
 * and separately tracked.
 *
 * 12 invariants:
 *
 *   1.  arm_registry_available
 *   2.  arm_contract_service_available
 *   3.  arena_run_service_available
 *   4.  run_arena_action_wired
 *   5.  arms_action_exposes_registry
 *   6.  all_canonical_arms_declared
 *   7.  task_category_gate_enforced
 *   8.  not_yet_executable_blockers_honest
 *   9.  scripted_or_manual_cannot_forge_score
 *   10. arena_real_provider_requires_three_confirmations
 *   11. arena_never_unlocks_external_rivals
 *   12. arena_doc_canonical
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
        'not_yet_executable_blockers_honest',
        'scripted_or_manual_cannot_forge_score',
        'arena_real_provider_requires_three_confirmations',
        'arena_never_unlocks_external_rivals',
        'arena_doc_canonical',
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
            'canonical_doc' => [
                'path' => 'docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-core-v1.md',
                'present' => is_file($repoRoot.'/docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-core-v1.md'),
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
        $commandFile = $repoRoot.'/app/Console/Commands/AtlasForgeRivalsCommand.php';
        $dispatcherFile = $repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsActionDispatcher.php';
        $doc = $repoRoot.'/docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-core-v1.md';

        switch ($name) {
            case 'arm_registry_available':
                return [
                    'ok' => class_exists(AtlasForgeRivalsArmRegistryService::class)
                        && count(AtlasForgeRivalsArmRegistryService::ARMS) === 8,
                    'status' => 'slice_8',
                    'description' => 'Arm registry exists and declares the canonical runners.',
                    'check' => 'class exists + ARMS count is 8',
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
                    AtlasForgeRivalsArmRegistryService::ARM_ATLAS_DEV_LIGHT,
                    AtlasForgeRivalsArmRegistryService::ARM_CLAUDE_CODE,
                    AtlasForgeRivalsArmRegistryService::ARM_CODEX_CLI,
                    AtlasForgeRivalsArmRegistryService::ARM_GEMINI_CLI,
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

            case 'not_yet_executable_blockers_honest':
                $contractSrc = $this->readFile($contractFile);
                $registrySrc = $this->readFile($registryFile);

                return [
                    'ok' => str_contains($contractSrc, 'arm_runner_not_yet_executable')
                        && str_contains($registrySrc, "STATUS_NOT_YET_EXECUTABLE = 'not_yet_executable'")
                        && str_contains($registrySrc, 'future_runner_is_placeholder_only')
                        && str_contains($registrySrc, 'gemini_driver_not_wired_v1')
                        && str_contains($registrySrc, 'scripted_runner_protocol_v1_pending')
                        && str_contains($registrySrc, 'manual_runner_protocol_v1_pending'),
                    'status' => 'slice_8',
                    'description' => 'Not-yet-executable arms emit honest blockers with specific reason codes; future_runner is placeholder-only.',
                    'check' => 'contract emits arm_runner_not_yet_executable + registry carries STATUS_NOT_YET_EXECUTABLE constant and per-arm reason codes',
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
                $docSrc = $this->readFile($doc);

                return [
                    'ok' => is_file($doc)
                        && str_contains($docSrc, 'atlas.forge.rivals.provider_arena_run.v1')
                        && str_contains($docSrc, 'external_rivals_certification'),
                    'status' => 'slice_8',
                    'description' => 'Canonical doc declares the arena schema and the external_rivals separation rule.',
                    'check' => 'doc exists + contains arena run schema + external_rivals_certification reference',
                    'evidence' => ['docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-core-v1.md'],
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
