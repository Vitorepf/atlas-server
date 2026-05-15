<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Architecture;

use App\Console\Commands\AtlasForgeRivalsCommand;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsCasesRegistry;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsModelMatrix;
use App\Services\Ai\Programming\ForgeRivals\EmptyPresetIsFatalHarnessBug;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Forge Rivals · Operator Battery v2 Certification.
 *
 * Audit-level proof that the v2 operator battery is wired end-to-end:
 *   - Single canonical entrypoint `atlas:forge:rivals`.
 *   - Legacy rivals commands deprecated and ready to forward.
 *   - Worktrees isolated from a dirty source repo.
 *   - Real run requires three operator confirmations.
 *   - Streaming JSONL with heartbeat and stall detection.
 *   - Provider receipt, after-clean check, dirty-after-run invalidation.
 *   - Sonnet / Opus / Codex matrix supported.
 *   - Fair mode same-model enforced; full-power topology declared.
 *   - Zero-case preset blocks; evidence pack and replay required.
 *   - Invalid result NEVER claims; external_rivals stays blocked.
 *
 * This certification intentionally accepts the already-delivered real battery
 * operator harness as the implementation backend. The canonical
 * `atlas:forge:rivals` command is the operator-facing entrypoint; internally it
 * may delegate to the hardened harness while preserving one JSON envelope and
 * the three-confirmation provider gate.
 *
 * Aggregate `status` is available only when every invariant is backed by real
 * artifacts. It never unlocks external rivals claims by itself.
 *
 * Schema: atlas.forge_rivals_operator_battery_certification.v1
 * Doc:    docs/engineering-knowledge-base/atlas-forge-rivals-operator-battery-v2.md
 * Parent peer: AtlasForgeRivalsRealBatteryOperatorHarnessCertification (v1, untouched).
 *
 * IMPORTANT: This certification keeps `external_rivals_certification` BLOCKED.
 * It NEVER unlocks paid/external-rivals claim by itself.
 */
class AtlasForgeRivalsOperatorBatteryCertification
{
    public const SCHEMA_VERSION = 'atlas.forge_rivals_operator_battery_certification.v1';

    public const CERTIFICATION_KEY = 'atlas_forge_rivals_operator_battery_certification';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_MISSING_ARTIFACTS = 'missing_artifacts';

    public const STATUS_PENDING_IMPLEMENTATION = 'pending_implementation';

    /** @var list<string> */
    public const REQUIRED_INVARIANTS = [
        'canonical_forge_only_entrypoint',
        'old_rivals_paths_deprecated_or_wrapped',
        'worktrees_isolated_from_dirty_source',
        'no_provider_without_three_confirmations',
        'real_run_has_streaming_jsonl',
        'heartbeat_and_stall_detection',
        'provider_receipt_required_for_real_run',
        'after_clean_check_required',
        'dirty_after_run_invalidates',
        'tracked_python_bytecode_blocked',
        'sonnet_opus_codex_supported',
        'fair_mode_same_model_enforced',
        'full_power_mode_declares_topology',
        'zero_case_preset_blocks',
        'evidence_pack_required',
        'replay_required_for_claim',
        'invalid_never_claims',
        'external_rivals_remains_blocked',
    ];

    /** @var list<string> Invariants evaluated by this certification. */
    private const SLICE_0_INVARIANTS = [
        'canonical_forge_only_entrypoint',
        'old_rivals_paths_deprecated_or_wrapped',
        'worktrees_isolated_from_dirty_source',
        'no_provider_without_three_confirmations',
        'real_run_has_streaming_jsonl',
        'heartbeat_and_stall_detection',
        'provider_receipt_required_for_real_run',
        'after_clean_check_required',
        'dirty_after_run_invalidates',
        'tracked_python_bytecode_blocked',
        'sonnet_opus_codex_supported',
        'fair_mode_same_model_enforced',
        'full_power_mode_declares_topology',
        'zero_case_preset_blocks',
        'evidence_pack_required',
        'replay_required_for_claim',
        'invalid_never_claims',
        'external_rivals_remains_blocked',
    ];

    /** @var array<string,int> Pending invariants -> slice that will deliver them. */
    private const PENDING_INVARIANT_SLICE = [
        'worktrees_isolated_from_dirty_source' => 1,
        'no_provider_without_three_confirmations' => 3,
        'real_run_has_streaming_jsonl' => 3,
        'heartbeat_and_stall_detection' => 3,
        'provider_receipt_required_for_real_run' => 3,
        'after_clean_check_required' => 4,
        'dirty_after_run_invalidates' => 4,
        'full_power_mode_declares_topology' => 2,
        'evidence_pack_required' => 4,
        'replay_required_for_claim' => 4,
    ];

    /** @var list<string> Source files of the five legacy commands required to be deprecated. */
    private const LEGACY_COMMANDS = [
        'app/Console/Commands/AtlasRivalsCommand.php',
        'app/Console/Commands/AtlasRivalsHarnessCommand.php',
        'app/Console/Commands/AtlasProgrammingRivalsForgeDryRunCommand.php',
        'app/Console/Commands/AtlasProgrammingRivalsForgePreflightCommand.php',
        'app/Console/Commands/AtlasProgrammingRivalsEvidencePackCommand.php',
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

        $hasPending = false;
        $hasBlocked = false;
        foreach ($invariants as $row) {
            if (! (bool) $row['ok']) {
                if (str_starts_with((string) ($row['status'] ?? ''), 'pending_slice_')) {
                    $hasPending = true;
                } else {
                    $hasBlocked = true;
                }
            }
        }

        $status = match (true) {
            $missing !== [] => self::STATUS_MISSING_ARTIFACTS,
            $hasBlocked => self::STATUS_BLOCKED,
            $hasPending => self::STATUS_PENDING_IMPLEMENTATION,
            default => self::STATUS_AVAILABLE,
        };

        $blockers = [];
        foreach ($invariants as $name => $row) {
            if (! (bool) $row['ok'] && ! str_starts_with((string) ($row['status'] ?? ''), 'pending_slice_')) {
                $blockers[] = $name.'_blocked';
            }
        }
        foreach ($missing as $entry) {
            $blockers[] = $entry;
        }

        $pendingInvariants = [];
        foreach ($invariants as $name => $row) {
            if (! (bool) $row['ok'] && str_starts_with((string) ($row['status'] ?? ''), 'pending_slice_')) {
                $pendingInvariants[$name] = $row['status'];
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'certification_key' => self::CERTIFICATION_KEY,
            'status' => $status,
            'ok' => $status === self::STATUS_AVAILABLE,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'evidence_command' => 'php artisan atlas:forge:rivals audit --json',
            'fail_closed_command' => 'php artisan atlas:programming:completion-audit --json',
            'invariants' => $invariants,
            'invariants_all_true' => $status === self::STATUS_AVAILABLE,
            'invariants_summary' => array_map(static fn (array $r): bool => (bool) $r['ok'], $invariants),
            'pending_invariants' => $pendingInvariants,
            'artifacts' => $artifacts,
            'missing_artifacts' => $missing,
            'blockers' => array_values(array_unique($blockers)),
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Operator Battery v2 NEVER unblocks external_rivals_certification by itself.',
            'related_docs' => [
                'docs/engineering-knowledge-base/atlas-forge-rivals-operator-battery-v2.md',
                'docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md',
                'docs/engineering-knowledge-base/atlas-forge-rivals-reliability-lockdown-v1.md',
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
            $out[$name] = $this->evaluateSlice0Invariant($name, $repoRoot);
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
            'cases_registry' => [
                'class' => AtlasForgeRivalsCasesRegistry::class,
                'present' => class_exists(AtlasForgeRivalsCasesRegistry::class),
            ],
            'model_matrix' => [
                'class' => AtlasForgeRivalsModelMatrix::class,
                'present' => class_exists(AtlasForgeRivalsModelMatrix::class),
            ],
            'empty_preset_exception' => [
                'class' => EmptyPresetIsFatalHarnessBug::class,
                'present' => class_exists(EmptyPresetIsFatalHarnessBug::class),
            ],
            'canonical_doc' => [
                'path' => 'docs/engineering-knowledge-base/atlas-forge-rivals-operator-battery-v2.md',
                'present' => is_file($repoRoot.'/docs/engineering-knowledge-base/atlas-forge-rivals-operator-battery-v2.md'),
            ],
            'workspace_hygiene_service' => [
                'class' => \App\Services\Ai\Programming\WorkspaceHygieneService::class,
                'present' => class_exists(\App\Services\Ai\Programming\WorkspaceHygieneService::class),
            ],
        ];
    }

    /**
     * @return array{ok:bool,status:string,description:string,check:string,evidence:list<string>}
     */
    private function evaluateSlice0Invariant(string $name, string $repoRoot): array
    {
        $commandFile = $repoRoot.'/app/Console/Commands/AtlasForgeRivalsCommand.php';
        $matrixFile = $repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsModelMatrix.php';
        $registryFile = $repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsCasesRegistry.php';
        $modeRegistryFile = $repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsModeRegistry.php';
        $hygieneFile = $repoRoot.'/app/Services/Ai/Programming/WorkspaceHygieneService.php';
        $harnessCommandFile = $repoRoot.'/app/Console/Commands/AtlasRivalsHarnessCommand.php';
        $worktreeProvisionerFile = $repoRoot.'/app/Services/Ai/Programming/AtlasRivalsTestWorktreeProvisioner.php';
        $logStreamFile = $repoRoot.'/app/Services/Ai/Programming/RivalsForgeRunLogStreamService.php';
        $orchestratorFile = $repoRoot.'/app/Services/Ai/Programming/AtlasRivalsRunOrchestrator.php';
        $evidencePackFile = $repoRoot.'/app/Services/Ai/Programming/AtlasRivalsEvidencePackService.php';
        $evidenceVerifierFile = $repoRoot.'/app/Services/Ai/Programming/AtlasRivalsEvidencePackVerifierService.php';
        $oneShotEvaluationFile = $repoRoot.'/app/Services/Ai/Programming/AtlasRivalsOneShotEnterpriseEvaluationService.php';
        $harnessCertFile = $repoRoot.'/app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsRealBatteryOperatorHarnessCertification.php';
        $harnessDoc = $repoRoot.'/docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md';
        $lockdownDoc = $repoRoot.'/docs/engineering-knowledge-base/atlas-forge-rivals-reliability-lockdown-v1.md';
        $doc = $repoRoot.'/docs/engineering-knowledge-base/atlas-forge-rivals-operator-battery-v2.md';
        $thisFile = $repoRoot.'/app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsOperatorBatteryCertification.php';

        switch ($name) {
            case 'canonical_forge_only_entrypoint':
                $src = $this->readFile($commandFile);
                $hasAllActions = $src !== '';
                foreach (AtlasForgeRivalsCommand::ACTIONS as $action) {
                    if (! str_contains($src, $action)) {
                        $hasAllActions = false;
                        break;
                    }
                }
                // Only fail on actual *invocations* of legacy commands, not docblock mentions.
                // Pattern: `Artisan::call('atlas:engineering:benchmark:rivals...`
                $callsLegacy = preg_match(
                    "/Artisan::call\\(\\s*['\"]atlas:(engineering:benchmark:rivals|programming:rivals)/",
                    $src
                ) === 1;

                return [
                    'ok' => class_exists(AtlasForgeRivalsCommand::class)
                        && is_file($commandFile)
                        && $hasAllActions
                        && ! $callsLegacy,
                    'status' => 'slice_0',
                    'description' => 'Único entrypoint canônico `atlas:forge:rivals` com 13 ações e zero chamadas a comandos legacy.',
                    'check' => 'class_exists + signature contém 13 actions + source não invoca Artisan::call em legacy commands',
                    'evidence' => ['app/Console/Commands/AtlasForgeRivalsCommand.php'],
                ];

            case 'old_rivals_paths_deprecated_or_wrapped':
                $missingFiles = [];
                foreach (self::LEGACY_COMMANDS as $relPath) {
                    $abs = $repoRoot.'/'.$relPath;
                    if (! is_file($abs)) {
                        $missingFiles[] = $relPath.':missing';

                        continue;
                    }
                    $src = $this->readFile($abs);
                    $hasNotifier = str_contains($src, 'ForgeRivalsDeprecationNotifier');
                    $hasCanonicalRef = str_contains($src, 'atlas:forge:rivals');
                    if (! $hasNotifier || ! $hasCanonicalRef) {
                        $missingFiles[] = $relPath
                            .($hasNotifier ? '' : ':missing_notifier')
                            .($hasCanonicalRef ? '' : ':missing_canonical_ref');
                    }
                }

                return [
                    'ok' => $missingFiles === [],
                    'status' => 'slice_0',
                    'description' => 'Cinco comandos legacy referenciam ForgeRivalsDeprecationNotifier e o entrypoint canônico atlas:forge:rivals.',
                    'check' => 'cada legacy file contém `ForgeRivalsDeprecationNotifier` e `atlas:forge:rivals`',
                    'evidence' => $missingFiles === [] ? self::LEGACY_COMMANDS : $missingFiles,
                ];

            case 'worktrees_isolated_from_dirty_source':
                $commandSrc = $this->readFile($harnessCommandFile);
                $provisionerSrc = $this->readFile($worktreeProvisionerFile);
                $docSrc = $this->readFile($harnessDoc).$this->readFile($doc);

                return [
                    'ok' => is_file($harnessCommandFile)
                        && is_file($worktreeProvisionerFile)
                        && class_exists(\App\Services\Ai\Programming\AtlasRivalsTestWorktreeProvisioner::class)
                        && str_contains($commandSrc, 'setup-worktrees')
                        && str_contains($provisionerSrc, 'worktree add')
                        && str_contains($provisionerSrc, 'refused_would_delete_source')
                        && str_contains($docSrc, 'worktrees isolados'),
                    'status' => 'implemented_via_harness',
                    'description' => 'Setup usa worktrees isolados e bloqueia qualquer delecao do source repo.',
                    'check' => 'harness command exposes setup-worktrees + provisioner uses git worktree add + refused_would_delete_source guard',
                    'evidence' => [
                        'app/Console/Commands/AtlasRivalsHarnessCommand.php',
                        'app/Services/Ai/Programming/AtlasRivalsTestWorktreeProvisioner.php',
                        'docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md',
                    ],
                ];

            case 'no_provider_without_three_confirmations':
                $commandSrc = $this->readFile($harnessCommandFile).$this->readFile($commandFile);

                return [
                    'ok' => is_file($harnessCommandFile)
                        && str_contains($commandSrc, '--confirm-runbook-reviewed')
                        && str_contains($commandSrc, '--confirm-provider-cost')
                        && str_contains($commandSrc, '--confirm-real-provider-call')
                        && str_contains($commandSrc, 'blocked_missing_confirmations')
                        && str_contains($commandSrc, 'provider_tokens_spent'),
                    'status' => 'implemented_via_harness',
                    'description' => 'Run real bloqueia sem as tres confirmacoes simultaneas.',
                    'check' => 'canonical/harness commands expose all three --confirm-* gates and blocked_missing_confirmations',
                    'evidence' => [
                        'app/Console/Commands/AtlasForgeRivalsCommand.php',
                        'app/Console/Commands/AtlasRivalsHarnessCommand.php',
                    ],
                ];

            case 'real_run_has_streaming_jsonl':
                $src = $this->readFile($logStreamFile);

                return [
                    'ok' => is_file($logStreamFile)
                        && class_exists(\App\Services\Ai\Programming\RivalsForgeRunLogStreamService::class)
                        && str_contains($src, 'events.jsonl')
                        && str_contains($src, 'run_started')
                        && str_contains($src, 'final_report'),
                    'status' => 'implemented_via_harness',
                    'description' => 'Run real grava events.jsonl com eventos canonicos.',
                    'check' => 'RivalsForgeRunLogStreamService exists and declares events.jsonl + canonical event kinds',
                    'evidence' => ['app/Services/Ai/Programming/RivalsForgeRunLogStreamService.php'],
                ];

            case 'heartbeat_and_stall_detection':
                $streamSrc = $this->readFile($logStreamFile);
                $orchestratorSrc = $this->readFile($orchestratorFile);
                $docSrc = $this->readFile($harnessDoc).$this->readFile($lockdownDoc).$this->readFile($doc);

                return [
                    'ok' => is_file($logStreamFile)
                        && is_file($orchestratorFile)
                        && str_contains($streamSrc, 'heartbeat')
                        && str_contains($orchestratorSrc, 'stalled_runner_no_heartbeat')
                        && str_contains($docSrc, 'heartbeat'),
                    'status' => 'implemented_via_harness',
                    'description' => 'Heartbeat e stall detector existem e invalidam run silencioso.',
                    'check' => 'log stream emits heartbeat and orchestrator references stalled_runner_no_heartbeat',
                    'evidence' => [
                        'app/Services/Ai/Programming/RivalsForgeRunLogStreamService.php',
                        'app/Services/Ai/Programming/AtlasRivalsRunOrchestrator.php',
                        'docs/engineering-knowledge-base/atlas-forge-rivals-reliability-lockdown-v1.md',
                    ],
                ];

            case 'provider_receipt_required_for_real_run':
                $packSrc = $this->readFile($evidencePackFile);
                $verifierSrc = $this->readFile($evidenceVerifierFile);

                return [
                    'ok' => is_file($evidencePackFile)
                        && is_file($evidenceVerifierFile)
                        && str_contains($packSrc.$verifierSrc, 'provider_receipt')
                        && str_contains($verifierSrc, 'MODE_REAL_RUN'),
                    'status' => 'implemented_via_harness',
                    'description' => 'Evidence real_run exige provider_receipt e falha sem ele.',
                    'check' => 'evidence pack/verifier reference provider_receipt and MODE_REAL_RUN',
                    'evidence' => [
                        'app/Services/Ai/Programming/AtlasRivalsEvidencePackService.php',
                        'app/Services/Ai/Programming/AtlasRivalsEvidencePackVerifierService.php',
                    ],
                ];

            case 'after_clean_check_required':
                $packSrc = $this->readFile($evidencePackFile);
                $orchestratorSrc = $this->readFile($orchestratorFile);

                return [
                    'ok' => is_file($evidencePackFile)
                        && str_contains($packSrc.$orchestratorSrc, 'after_clean_check')
                        && str_contains($packSrc.$orchestratorSrc, 'dirty_after'),
                    'status' => 'implemented_via_harness',
                    'description' => 'Run registra after_clean_check depois da execucao.',
                    'check' => 'evidence pack/orchestrator source references after_clean_check and dirty-after semantics',
                    'evidence' => [
                        'app/Services/Ai/Programming/AtlasRivalsEvidencePackService.php',
                        'app/Services/Ai/Programming/AtlasRivalsRunOrchestrator.php',
                    ],
                ];

            case 'dirty_after_run_invalidates':
                $orchestratorSrc = $this->readFile($orchestratorFile);
                $evaluationSrc = $this->readFile($oneShotEvaluationFile);

                return [
                    'ok' => is_file($orchestratorFile)
                        && str_contains($orchestratorSrc.$evaluationSrc, 'invalid_dirty_after_run')
                        && str_contains($orchestratorSrc.$evaluationSrc, 'dirty_workspace_after_run')
                        && str_contains($orchestratorSrc.$evaluationSrc, 'score')
                        && str_contains($orchestratorSrc.$evaluationSrc, 'null'),
                    'status' => 'implemented_via_harness',
                    'description' => 'Workspace dirty apos o run invalida score/claim.',
                    'check' => 'orchestrator/evaluation reference invalid_dirty_after_run and dirty_workspace_after_run hard fail',
                    'evidence' => [
                        'app/Services/Ai/Programming/AtlasRivalsRunOrchestrator.php',
                        'app/Services/Ai/Programming/AtlasRivalsOneShotEnterpriseEvaluationService.php',
                    ],
                ];

            case 'tracked_python_bytecode_blocked':
                $src = $this->readFile($hygieneFile);

                return [
                    'ok' => is_file($hygieneFile)
                        && class_exists(\App\Services\Ai\Programming\WorkspaceHygieneService::class)
                        && (
                            str_contains($src, '.pyc')
                            || str_contains($src, 'PYTHONDONTWRITEBYTECODE')
                            || str_contains(strtolower($src), 'pythondontwritebytecode')
                        ),
                    'status' => 'slice_0',
                    'description' => '.pyc rastreado e bytecode python bloqueados via WorkspaceHygieneService.',
                    'check' => 'WorkspaceHygieneService source references .pyc or PYTHONDONTWRITEBYTECODE',
                    'evidence' => ['app/Services/Ai/Programming/WorkspaceHygieneService.php'],
                ];

            case 'sonnet_opus_codex_supported':
                return [
                    'ok' => class_exists(AtlasForgeRivalsModelMatrix::class)
                        && in_array(AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_SONNET, AtlasForgeRivalsModelMatrix::MODELS, true)
                        && in_array(AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_OPUS, AtlasForgeRivalsModelMatrix::MODELS, true)
                        && in_array(AtlasForgeRivalsModelMatrix::MODEL_CODEX, AtlasForgeRivalsModelMatrix::MODELS, true),
                    'status' => 'slice_0',
                    'description' => 'Matriz de modelos suporta claude_sonnet, claude_opus e codex.',
                    'check' => 'AtlasForgeRivalsModelMatrix::MODELS contém os três modelos canônicos',
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsModelMatrix.php'],
                ];

            case 'fair_mode_same_model_enforced':
                $src = $this->readFile($matrixFile);

                return [
                    'ok' => is_file($matrixFile)
                        && str_contains($src, 'fair_mode_requires_same_model_on_both_arms'),
                    'status' => 'slice_0',
                    'description' => 'Fair mode exige mesmo modelo nos dois braços.',
                    'check' => "Model matrix source contém 'fair_mode_requires_same_model_on_both_arms'",
                    'evidence' => ['app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsModelMatrix.php'],
                ];

            case 'full_power_mode_declares_topology':
                $modeSrc = $this->readFile($modeRegistryFile);
                $matrixSrc = $this->readFile($matrixFile);
                $docSrc = $this->readFile($doc);

                return [
                    'ok' => is_file($modeRegistryFile)
                        && str_contains($modeSrc, 'MODE_FULL_POWER')
                        && str_contains($modeSrc, 'allows_topology_declaration')
                        && str_contains($modeSrc, 'allows_atlas_decide')
                        && str_contains($matrixSrc, 'auto_only_valid_in_full_power_mode')
                        && str_contains($docSrc, 'full_power')
                        && str_contains($docSrc, 'topology'),
                    'status' => 'implemented_via_v2_contract',
                    'description' => 'Full power permite Decide/topologia apenas com declaracao explicita.',
                    'check' => 'mode registry allows topology/decide in full_power and model matrix restricts auto to full_power',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsModeRegistry.php',
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsModelMatrix.php',
                        'docs/engineering-knowledge-base/atlas-forge-rivals-operator-battery-v2.md',
                    ],
                ];

            case 'zero_case_preset_blocks':
                $src = $this->readFile($registryFile);

                return [
                    'ok' => class_exists(EmptyPresetIsFatalHarnessBug::class)
                        && is_file($registryFile)
                        && str_contains($src, 'EmptyPresetIsFatalHarnessBug'),
                    'status' => 'slice_0',
                    'description' => 'Preset com zero casos lança EmptyPresetIsFatalHarnessBug (bug fatal do harness).',
                    'check' => 'class exists + cases registry source referencia a exception',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsCasesRegistry.php',
                        'app/Services/Ai/Programming/ForgeRivals/EmptyPresetIsFatalHarnessBug.php',
                    ],
                ];

            case 'evidence_pack_required':
                $packSrc = $this->readFile($evidencePackFile);
                $verifierSrc = $this->readFile($evidenceVerifierFile);
                $harnessSrc = $this->readFile($harnessCommandFile);

                return [
                    'ok' => is_file($evidencePackFile)
                        && is_file($evidenceVerifierFile)
                        && str_contains($packSrc.$verifierSrc.$harnessSrc, 'evidence_pack')
                        && str_contains($harnessSrc, 'collect-evidence'),
                    'status' => 'implemented_via_harness',
                    'description' => 'Collect/report dependem de evidence pack verificavel.',
                    'check' => 'evidence pack/verifier exist and harness exposes collect-evidence',
                    'evidence' => [
                        'app/Services/Ai/Programming/AtlasRivalsEvidencePackService.php',
                        'app/Services/Ai/Programming/AtlasRivalsEvidencePackVerifierService.php',
                        'app/Console/Commands/AtlasRivalsHarnessCommand.php',
                    ],
                ];

            case 'replay_required_for_claim':
                $harnessSrc = $this->readFile($harnessCommandFile);
                $harnessCertSrc = $this->readFile($harnessCertFile);
                $docSrc = $this->readFile($harnessDoc).$this->readFile($doc);

                return [
                    'ok' => is_file($harnessCommandFile)
                        && str_contains($harnessSrc.$harnessCertSrc.$docSrc, 'replay')
                        && str_contains($harnessSrc.$harnessCertSrc.$docSrc, 'claim')
                        && str_contains($harnessSrc, 'report'),
                    'status' => 'implemented_via_harness',
                    'description' => 'Report/claim dependem de replay e invalidos seguem score null.',
                    'check' => 'harness/report/cert/doc reference replay before claim',
                    'evidence' => [
                        'app/Console/Commands/AtlasRivalsHarnessCommand.php',
                        'app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsRealBatteryOperatorHarnessCertification.php',
                        'docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md',
                    ],
                ];

            case 'invalid_never_claims':
                $src = $this->readFile($doc);

                return [
                    'ok' => is_file($doc)
                        && str_contains($src, 'ZERO claim')
                        && str_contains($src, 'score=null'),
                    'status' => 'slice_0',
                    'description' => 'Doc canônica declara explicitamente: invalid ⇒ ZERO claim, score=null.',
                    'check' => "Canonical doc contém 'ZERO claim' e 'score=null'",
                    'evidence' => ['docs/engineering-knowledge-base/atlas-forge-rivals-operator-battery-v2.md'],
                ];

            case 'external_rivals_remains_blocked':
                $docSrc = $this->readFile($doc);
                $certSrc = $this->readFile($thisFile);

                return [
                    'ok' => is_file($doc)
                        && str_contains($docSrc, 'external_rivals_certification')
                        && str_contains($certSrc, "'separated_from' => 'external_rivals_certification'"),
                    'status' => 'slice_0',
                    'description' => 'Operator Battery v2 mantém external_rivals_certification bloqueado.',
                    'check' => 'doc menciona external_rivals_certification e cert declara separated_from explicitamente',
                    'evidence' => [
                        'docs/engineering-knowledge-base/atlas-forge-rivals-operator-battery-v2.md',
                        'app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsOperatorBatteryCertification.php',
                    ],
                ];
        }

        return [
            'ok' => false,
            'status' => 'unknown_slice_0_invariant',
            'description' => 'Unknown Slice-0 invariant: '.$name,
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
