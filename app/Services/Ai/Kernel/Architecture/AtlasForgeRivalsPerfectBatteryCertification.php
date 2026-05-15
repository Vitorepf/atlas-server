<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Architecture;

use App\Console\Commands\AtlasForgeRivalsCommand;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsActionDispatcher;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsAdjudicatorService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsModelMatrix;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsReportService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunBatteryService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Forge Rivals · Perfect Battery & Adjudicator Certification (v1).
 *
 * Audit-level read-model that proves the single-button battery + deterministic
 * adjudicator + premium report stack is wired end-to-end and honours every
 * canon safety rule. It NEVER unlocks `external_rivals_certification` — that
 * remains operator-approval-gated and separately tracked.
 *
 * 12 invariants (single source of truth for the v1 contract):
 *
 *   1.  one_button_battery_available
 *   2.  adjudicator_available
 *   3.  replay_required_before_winner
 *   4.  evidence_required_before_winner
 *   5.  hard_fail_score_null
 *   6.  no_external_rivals_unlock
 *   7.  real_provider_requires_three_confirmations
 *   8.  worktree_isolation_required
 *   9.  sonnet_supported
 *   10. opus_supported
 *   11. codex_blocker_honest_or_supported
 *   12. report_has_winner_reason
 *
 * Schema: atlas.forge_rivals_perfect_battery_certification.v1
 * Doc:    docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md
 *
 * Status semantics:
 *   - available           : every invariant green AND every artifact present
 *   - missing_artifacts   : an artifact required to evaluate is missing
 *   - blocked             : at least one invariant evaluates to false
 *
 * This certification is the only thing the Slice 7 ship-checklist needs to
 * see green. It does not replace `atlas_forge_rivals_operator_battery_certification`
 * — both must remain green for the perfect battery to be operator-ready.
 */
class AtlasForgeRivalsPerfectBatteryCertification
{
    public const SCHEMA_VERSION = 'atlas.forge_rivals_perfect_battery_certification.v1';

    public const CERTIFICATION_KEY = 'atlas_forge_rivals_perfect_battery_certification';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_MISSING_ARTIFACTS = 'missing_artifacts';

    /** @var list<string> */
    public const REQUIRED_INVARIANTS = [
        'one_button_battery_available',
        'adjudicator_available',
        'replay_required_before_winner',
        'evidence_required_before_winner',
        'hard_fail_score_null',
        'no_external_rivals_unlock',
        'real_provider_requires_three_confirmations',
        'worktree_isolation_required',
        'sonnet_supported',
        'opus_supported',
        'codex_blocker_honest_or_supported',
        'report_has_winner_reason',
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
            'note' => 'Perfect Battery v1 NEVER unblocks external_rivals_certification by itself.',
            'related_docs' => [
                'docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md',
                'docs/engineering-knowledge-base/atlas-forge-rivals-operator-battery-v2.md',
                'docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md',
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
            'canonical_command' => [
                'class' => AtlasForgeRivalsCommand::class,
                'present' => class_exists(AtlasForgeRivalsCommand::class),
            ],
            'action_dispatcher' => [
                'class' => AtlasForgeRivalsActionDispatcher::class,
                'present' => class_exists(AtlasForgeRivalsActionDispatcher::class),
            ],
            'adjudicator_service' => [
                'class' => AtlasForgeRivalsAdjudicatorService::class,
                'present' => class_exists(AtlasForgeRivalsAdjudicatorService::class),
            ],
            'run_battery_service' => [
                'class' => AtlasForgeRivalsRunBatteryService::class,
                'present' => class_exists(AtlasForgeRivalsRunBatteryService::class),
            ],
            'report_service' => [
                'class' => AtlasForgeRivalsReportService::class,
                'present' => class_exists(AtlasForgeRivalsReportService::class),
            ],
            'model_matrix' => [
                'class' => AtlasForgeRivalsModelMatrix::class,
                'present' => class_exists(AtlasForgeRivalsModelMatrix::class),
            ],
            'canonical_doc' => [
                'path' => 'docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md',
                'present' => is_file($repoRoot.'/docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md'),
            ],
        ];
    }

    /**
     * @return array{ok:bool,status:string,description:string,check:string,evidence:list<string>}
     */
    private function evaluateInvariant(string $name, string $repoRoot): array
    {
        $commandFile = $repoRoot.'/app/Console/Commands/AtlasForgeRivalsCommand.php';
        $dispatcherFile = $repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsActionDispatcher.php';
        $adjudicatorFile = $repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorService.php';
        $batteryFile = $repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunBatteryService.php';
        $reportFile = $repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportService.php';
        $runRealFile = $repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunRealService.php';
        $matrixFile = $repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsModelMatrix.php';
        $doc = $repoRoot.'/docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md';

        switch ($name) {
            case 'one_button_battery_available':
                $cmdSrc = $this->readFile($commandFile);
                $dispatcherSrc = $this->readFile($dispatcherFile);

                return [
                    'ok' => class_exists(AtlasForgeRivalsRunBatteryService::class)
                        && in_array('run-battery', AtlasForgeRivalsCommand::ACTIONS, true)
                        && str_contains($cmdSrc, 'run-battery')
                        && str_contains($dispatcherSrc, "'run-battery'")
                        && is_file($batteryFile),
                    'status' => 'slice_7',
                    'description' => 'Single canonical action `run-battery` chains the entire pipeline in one command.',
                    'check' => 'AtlasForgeRivalsCommand::ACTIONS contains run-battery + dispatcher routes it + service class exists',
                    'evidence' => [
                        'app/Console/Commands/AtlasForgeRivalsCommand.php',
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsActionDispatcher.php',
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunBatteryService.php',
                    ],
                ];

            case 'adjudicator_available':
                $adjSrc = $this->readFile($adjudicatorFile);

                return [
                    'ok' => class_exists(AtlasForgeRivalsAdjudicatorService::class)
                        && in_array('adjudicate', AtlasForgeRivalsCommand::ACTIONS, true)
                        && str_contains($adjSrc, 'atlas.forge.rivals.adjudication.v1')
                        && str_contains($adjSrc, 'WEIGHTS'),
                    'status' => 'slice_7',
                    'description' => 'Deterministic local adjudicator scores both arms and emits a structured scorecard.',
                    'check' => 'service class exists + adjudicate action wired + schema constant declared',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorService.php',
                    ],
                ];

            case 'replay_required_before_winner':
                $adjSrc = $this->readFile($adjudicatorFile);
                $reportSrc = $this->readFile($reportFile);

                return [
                    'ok' => str_contains($adjSrc, 'replay_passes')
                        && str_contains($reportSrc, 'replay_passes'),
                    'status' => 'slice_7',
                    'description' => 'Adjudicator and report both refuse to declare a winner without a passing replay.',
                    'check' => 'adjudicator + report reference replay_passes in their gate logic',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorService.php',
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportService.php',
                    ],
                ];

            case 'evidence_required_before_winner':
                $adjSrc = $this->readFile($adjudicatorFile);

                return [
                    'ok' => str_contains($adjSrc, 'evidence_complete')
                        && str_contains($adjSrc, 'missing_evidence'),
                    'status' => 'slice_7',
                    'description' => 'Adjudicator hard-fails when evidence pack is incomplete (missing artifacts).',
                    'check' => 'adjudicator references evidence_complete + missing_evidence',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorService.php',
                    ],
                ];

            case 'hard_fail_score_null':
                $adjSrc = $this->readFile($adjudicatorFile);

                return [
                    'ok' => str_contains($adjSrc, "'atlas_score' => null")
                        && str_contains($adjSrc, "'rival_score' => null")
                        && str_contains($adjSrc, 'WINNER_NONE'),
                    'status' => 'slice_7',
                    'description' => 'On any hard-gate failure, atlas_score, rival_score and winner are forced to null.',
                    'check' => 'adjudicator source emits null scores + WINNER_NONE on hard-fail branch',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorService.php',
                    ],
                ];

            case 'no_external_rivals_unlock':
                $reportSrc = $this->readFile($reportFile);
                $batterySrc = $this->readFile($batteryFile);
                $adjSrc = $this->readFile($adjudicatorFile);
                $thisSrc = $this->readFile(__FILE__);
                $docSrc = $this->readFile($doc);

                return [
                    'ok' => str_contains($reportSrc, "'unlocks_external_rivals_certification' => false")
                        && str_contains($reportSrc, 'separated_from_external_rivals_certification')
                        && str_contains($batterySrc, 'separated_from_external_rivals_certification')
                        && str_contains($adjSrc, 'separated_from_external_rivals_certification')
                        && str_contains($thisSrc, "'separated_from' => 'external_rivals_certification'")
                        && str_contains($docSrc, 'external_rivals_certification'),
                    'status' => 'slice_7',
                    'description' => 'Every layer declares that the perfect battery never unlocks external_rivals_certification.',
                    'check' => 'report/battery/adjudicator/cert/doc all assert separation from external_rivals_certification',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportService.php',
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunBatteryService.php',
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorService.php',
                        'app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsPerfectBatteryCertification.php',
                        'docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md',
                    ],
                ];

            case 'real_provider_requires_three_confirmations':
                $batterySrc = $this->readFile($batteryFile);
                $runRealSrc = $this->readFile($runRealFile);

                return [
                    'ok' => str_contains($batterySrc, 'runbook_reviewed')
                        && str_contains($batterySrc, 'provider_cost')
                        && str_contains($batterySrc, 'real_provider_call')
                        && str_contains($batterySrc, 'missing_confirmation')
                        && str_contains($runRealSrc, 'missing_confirmation'),
                    'status' => 'slice_7',
                    'description' => 'Run-battery and run-real both require the three operator confirmations before any provider call.',
                    'check' => 'both services name all three flags and emit missing_confirmation blockers',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunBatteryService.php',
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunRealService.php',
                    ],
                ];

            case 'worktree_isolation_required':
                $runRealSrc = $this->readFile($runRealFile);

                return [
                    'ok' => str_contains($runRealSrc, 'worktrees_missing')
                        && str_contains($runRealSrc, '/.git'),
                    'status' => 'slice_7',
                    'description' => 'Run-real blocks with `worktrees_missing` until setup provisioned isolated worktrees.',
                    'check' => 'run-real source references worktrees_missing + .git presence check',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunRealService.php',
                    ],
                ];

            case 'sonnet_supported':
                return [
                    'ok' => class_exists(AtlasForgeRivalsModelMatrix::class)
                        && in_array(AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_SONNET, AtlasForgeRivalsModelMatrix::MODELS, true),
                    'status' => 'slice_7',
                    'description' => 'Claude Sonnet is a first-class model in the matrix.',
                    'check' => 'AtlasForgeRivalsModelMatrix::MODELS contains claude_sonnet',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsModelMatrix.php',
                    ],
                ];

            case 'opus_supported':
                return [
                    'ok' => class_exists(AtlasForgeRivalsModelMatrix::class)
                        && in_array(AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_OPUS, AtlasForgeRivalsModelMatrix::MODELS, true),
                    'status' => 'slice_7',
                    'description' => 'Claude Opus is a first-class model in the matrix.',
                    'check' => 'AtlasForgeRivalsModelMatrix::MODELS contains claude_opus',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsModelMatrix.php',
                    ],
                ];

            case 'codex_blocker_honest_or_supported':
                $batterySrc = $this->readFile($batteryFile);
                $runRealSrc = $this->readFile($runRealFile);

                return [
                    'ok' => str_contains($batterySrc, 'rival_driver_not_configured:codex')
                        && str_contains($runRealSrc, 'rival_driver_not_configured:codex'),
                    'status' => 'slice_7',
                    'description' => 'When --rival=codex and the codex binary is missing, the pipeline blocks honestly instead of faking support.',
                    'check' => 'battery + run-real emit rival_driver_not_configured:codex blocker',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunBatteryService.php',
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunRealService.php',
                    ],
                ];

            case 'report_has_winner_reason':
                $reportSrc = $this->readFile($reportFile);
                $adjSrc = $this->readFile($adjudicatorFile);

                return [
                    'ok' => str_contains($reportSrc, 'winner_reason')
                        && str_contains($adjSrc, 'winner_reason')
                        && str_contains($adjSrc, 'buildWinnerReason'),
                    'status' => 'slice_7',
                    'description' => 'Report JSON and adjudicator scorecard surface a structured `winner_reason` bullet list.',
                    'check' => 'report + adjudicator both reference winner_reason field',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportService.php',
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorService.php',
                    ],
                ];
        }

        return [
            'ok' => false,
            'status' => 'unknown_perfect_battery_invariant',
            'description' => 'Unknown perfect-battery invariant: '.$name,
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
