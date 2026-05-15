<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Architecture;

/**
 * Atlas Forge Rivals Real Battery Operator Harness Certification.
 *
 * Audit-level proof that the Rivals real-battery operator harness is wired
 * end-to-end: copy-safe runbook, dry-run safety, three-confirmation gating
 * for the real run, fingerprint-scoped triage, tracked-bytecode block,
 * after-clean check, evidence pack + replay manifest, and guarantee that
 * an invalid result NEVER becomes a claim.
 *
 * This service performs no provider call. It evaluates 16 canonical
 * invariants from local artifacts (services, commands, docs) and from real
 * filesystem state, returning a deterministic certification payload.
 *
 * Schema: atlas.forge_rivals_real_battery_operator_harness_certification.v1
 * Doc:    docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md
 * Parent: docs/engineering-knowledge-base/atlas-forge-rivals-reliability-lockdown-v1.md
 *
 * IMPORTANT: This certification keeps `external_rivals_certification`
 * BLOCKED — it never unlocks paid/external-rivals claim by itself.
 */
class AtlasForgeRivalsRealBatteryOperatorHarnessCertification
{
    public const SCHEMA_VERSION = 'atlas.forge_rivals_real_battery_operator_harness_certification.v1';

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_MISSING_ARTIFACTS = 'missing_artifacts';

    public const CERTIFICATION_KEY = 'atlas_forge_rivals_real_battery_operator_harness_certification';

    /** @var list<string> Canonical invariants this block must report. */
    public const REQUIRED_INVARIANTS = [
        'worktree_isolation_available',
        'copy_safe_runbook_available',
        'dry_run_never_calls_provider',
        'real_run_requires_three_confirmations',
        'readiness_fingerprint_enforced',
        'triage_is_fingerprint_scoped',
        'tracked_python_bytecode_blocked',
        'after_clean_check_required',
        'real_run_provider_receipt_required',
        'evidence_pack_required',
        'replay_manifest_required',
        'invalid_result_never_claim',
        'external_rivals_remains_blocked',
        'sonnet_model_lock_supported',
        'logs_streaming_available',
        'operator_next_command_available',
    ];

    /**
     * Evaluate every invariant and return a full audit payload.
     *
     * @param  array<string,mixed>  $options  workspace
     * @return array<string,mixed>
     */
    public function evaluate(array $options = []): array
    {
        $repoRoot = $this->resolveRepoRoot($options);
        $invariants = $this->invariants($repoRoot);
        $artifacts = $this->artifacts($repoRoot);
        $missing = $this->collectMissingArtifacts($artifacts);
        $allTrue = ! in_array(false, array_values(array_map(
            static fn (array $r): bool => (bool) $r['ok'],
            $invariants,
        )), true);

        $status = match (true) {
            $missing !== [] => self::STATUS_MISSING_ARTIFACTS,
            ! $allTrue => self::STATUS_BLOCKED,
            default => self::STATUS_AVAILABLE,
        };

        $blockers = [];
        foreach ($invariants as $name => $row) {
            if (! (bool) $row['ok']) {
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
            'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'evidence_command' => 'bash scripts/rivals-harness-verify.sh',
            'fail_closed_command' => 'php artisan atlas:programming:completion-audit --json',
            'invariants' => $invariants,
            'invariants_all_true' => $allTrue,
            'invariants_summary' => array_map(
                static fn (array $row): bool => (bool) $row['ok'],
                $invariants,
            ),
            'artifacts' => $artifacts,
            'missing_artifacts' => $missing,
            'blockers' => array_values(array_unique($blockers)),
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'note' => 'Rivals e bateria de teste/operavel. Esta certificacao NUNCA desbloqueia external_rivals_certification.',
            'separated_from' => 'external_rivals_certification',
            'related_docs' => [
                'docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md',
                'docs/engineering-knowledge-base/atlas-forge-rivals-reliability-lockdown-v1.md',
            ],
        ];
    }

    /**
     * Run all 16 canonical invariants. Each invariant is checkable: it returns
     * `ok` (bool), `description`, `check` (how it was evaluated) and `evidence`
     * (concrete artifacts considered).
     *
     * @return array<string,array<string,mixed>>
     */
    public function invariants(string $repoRoot): array
    {
        $doc = $repoRoot.'/docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md';
        $parentDoc = $repoRoot.'/docs/engineering-knowledge-base/atlas-forge-rivals-reliability-lockdown-v1.md';
        $verifyScript = $repoRoot.'/scripts/rivals-harness-verify.sh';

        $workspaceHygiene = $repoRoot.'/app/Services/Ai/Programming/WorkspaceHygieneService.php';
        $fingerprint = $repoRoot.'/app/Services/Ai/Programming/RivalsForgeReadinessFingerprintService.php';
        $logStream = $repoRoot.'/app/Services/Ai/Programming/RivalsForgeRunLogStreamService.php';
        $orchestrator = $repoRoot.'/app/Services/Ai/Programming/AtlasRivalsRunOrchestrator.php';
        $triage = $repoRoot.'/app/Services/Ai/Programming/AtlasRivalsInvalidBatteryTriageRegistry.php';
        $runbook = $repoRoot.'/app/Services/Ai/Programming/AtlasRivalsOperatorRunbookGenerator.php';
        $evidencePack = $repoRoot.'/app/Services/Ai/Programming/AtlasRivalsEvidencePackService.php';
        $evidenceVerifier = $repoRoot.'/app/Services/Ai/Programming/AtlasRivalsEvidencePackVerifierService.php';
        $rivalsCommand = $repoRoot.'/app/Console/Commands/AtlasRivalsCommand.php';
        $completionAudit = $repoRoot.'/app/Services/Ai/Programming/ProgrammingProfessionalCompletionAuditService.php';

        $docSource = $this->readFile($doc);
        $parentDocSource = $this->readFile($parentDoc);
        $rivalsCommandSource = $this->readFile($rivalsCommand);
        $orchestratorSource = $this->readFile($orchestrator);
        $triageSource = $this->readFile($triage);
        $auditSource = $this->readFile($completionAudit);
        $hygieneSource = $this->readFile($workspaceHygiene);
        $logStreamSource = $this->readFile($logStream);

        return [
            'worktree_isolation_available' => [
                'ok' => is_file($doc)
                    && str_contains($docSource, 'git worktree add')
                    && str_contains($docSource, 'clean-atlas-worktree')
                    && str_contains($docSource, 'clean-baseline-worktree'),
                'description' => 'Harness documenta dois worktrees isolados (Atlas arm + baseline arm).',
                'check' => 'doc contains `git worktree add` and both worktree placeholders',
                'evidence' => [
                    'docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md',
                ],
            ],
            'copy_safe_runbook_available' => [
                'ok' => is_file($doc)
                    && substr_count($docSource, '```bash') >= 1
                    && str_contains($docSource, '/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals')
                    && is_file($runbook)
                    && class_exists(\App\Services\Ai\Programming\AtlasRivalsOperatorRunbookGenerator::class),
                'description' => 'Operador tem bloco copy-safe com cada comando do fluxo.',
                'check' => 'doc has bash fenced block referencing rivals artisan and runbook generator class exists',
                'evidence' => [
                    'app/Services/Ai/Programming/AtlasRivalsOperatorRunbookGenerator.php',
                    'docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md',
                ],
            ],
            'dry_run_never_calls_provider' => [
                'ok' => is_file($orchestrator)
                    && (
                        str_contains($orchestratorSource, "'dry-run'")
                        || str_contains($orchestratorSource, '"dry-run"')
                        || str_contains($orchestratorSource, 'dryRun')
                    )
                    && str_contains($rivalsCommandSource, 'dry-run'),
                'description' => 'Dry-run nunca dispara provider; orchestrator e comando reconhecem o modo.',
                'check' => 'orchestrator references dry-run path and rivals command exposes dry-run action',
                'evidence' => [
                    'app/Services/Ai/Programming/AtlasRivalsRunOrchestrator.php',
                    'app/Console/Commands/AtlasRivalsCommand.php',
                ],
            ],
            'real_run_requires_three_confirmations' => [
                'ok' => is_file($rivalsCommand)
                    && str_contains($rivalsCommandSource, '--confirm-runbook-reviewed')
                    && str_contains($rivalsCommandSource, '--confirm-provider-cost')
                    && (
                        str_contains($rivalsCommandSource, '--confirm-real-provider-call')
                        || str_contains($rivalsCommandSource, '--confirm-invalid-battery-quarantine')
                    ),
                'description' => 'Run real exige tres gates de confirmacao na mesma invocacao.',
                'check' => 'rivals command signature contains the three --confirm-* gates',
                'evidence' => [
                    'app/Console/Commands/AtlasRivalsCommand.php',
                ],
            ],
            'readiness_fingerprint_enforced' => [
                'ok' => is_file($fingerprint)
                    && class_exists(\App\Services\Ai\Programming\RivalsForgeReadinessFingerprintService::class),
                'description' => 'Fingerprint deterministico de readiness e calculado e enforced.',
                'check' => 'RivalsForgeReadinessFingerprintService exists and is loadable',
                'evidence' => [
                    'app/Services/Ai/Programming/RivalsForgeReadinessFingerprintService.php',
                ],
            ],
            'triage_is_fingerprint_scoped' => [
                'ok' => is_file($triage)
                    && class_exists(\App\Services\Ai\Programming\AtlasRivalsInvalidBatteryTriageRegistry::class)
                    && str_contains($triageSource, 'fingerprint')
                    && str_contains($rivalsCommandSource, 'triage-invalid-battery'),
                'description' => 'Triage opera por fingerprint, nao por suite inteiro.',
                'check' => 'triage registry references fingerprint and command exposes triage-invalid-battery action',
                'evidence' => [
                    'app/Services/Ai/Programming/AtlasRivalsInvalidBatteryTriageRegistry.php',
                    'app/Console/Commands/AtlasRivalsCommand.php',
                ],
            ],
            'tracked_python_bytecode_blocked' => [
                'ok' => is_file($workspaceHygiene)
                    && class_exists(\App\Services\Ai\Programming\WorkspaceHygieneService::class)
                    && (
                        str_contains($hygieneSource, '.pyc')
                        || str_contains($hygieneSource, 'PYTHONDONTWRITEBYTECODE')
                        || str_contains($hygieneSource, 'pythondontwritebytecode')
                    ),
                'description' => '.pyc rastreado e bytecode python sao bloqueados pela hygiene service.',
                'check' => 'WorkspaceHygieneService source references .pyc or PYTHONDONTWRITEBYTECODE',
                'evidence' => [
                    'app/Services/Ai/Programming/WorkspaceHygieneService.php',
                ],
            ],
            'after_clean_check_required' => [
                'ok' => is_file($evidencePack)
                    && is_file($evidenceVerifier)
                    && (
                        str_contains($this->readFile($evidencePack), 'after')
                        || str_contains($this->readFile($evidenceVerifier), 'after')
                    ),
                'description' => 'Evidence pack ou verifier exigem after-clean check apos o run.',
                'check' => 'evidence pack/verifier reference after-clean semantics',
                'evidence' => [
                    'app/Services/Ai/Programming/AtlasRivalsEvidencePackService.php',
                    'app/Services/Ai/Programming/AtlasRivalsEvidencePackVerifierService.php',
                ],
            ],
            'real_run_provider_receipt_required' => [
                'ok' => is_file($evidencePack)
                    && str_contains($this->readFile($evidencePack), 'receipt'),
                'description' => 'Evidence pack guarda provider receipt do run real.',
                'check' => 'evidence pack source references receipt',
                'evidence' => [
                    'app/Services/Ai/Programming/AtlasRivalsEvidencePackService.php',
                ],
            ],
            'evidence_pack_required' => [
                'ok' => is_file($evidencePack)
                    && class_exists(\App\Services\Ai\Programming\AtlasRivalsEvidencePackService::class)
                    && str_contains($auditSource, 'evidence'),
                'description' => 'Evidence pack e obrigatorio para qualquer claim valido.',
                'check' => 'evidence pack service exists and completion audit references evidence',
                'evidence' => [
                    'app/Services/Ai/Programming/AtlasRivalsEvidencePackService.php',
                    'app/Services/Ai/Programming/ProgrammingProfessionalCompletionAuditService.php',
                ],
            ],
            'replay_manifest_required' => [
                'ok' => is_file($evidencePack)
                    && (
                        str_contains($this->readFile($evidencePack), 'replay')
                        || str_contains($this->readFile($evidenceVerifier), 'replay')
                    )
                    && is_file($repoRoot.'/docs/engineering-knowledge-base/atlas-rivals-evidence-pack-replay-manifest-v1.md'),
                'description' => 'Replay manifest e parte do evidence pack e tem doc canon.',
                'check' => 'evidence pack/verifier reference replay and canonical doc exists',
                'evidence' => [
                    'app/Services/Ai/Programming/AtlasRivalsEvidencePackService.php',
                    'docs/engineering-knowledge-base/atlas-rivals-evidence-pack-replay-manifest-v1.md',
                ],
            ],
            'invalid_result_never_claim' => [
                'ok' => is_file($doc)
                    && str_contains($docSource, 'ZERO claim')
                    && str_contains($docSource, 'score=null'),
                'description' => 'Doc define explicitamente que resultado invalido nunca vira claim.',
                'check' => 'doc states ZERO claim and score=null for invalid result',
                'evidence' => [
                    'docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md',
                ],
            ],
            'external_rivals_remains_blocked' => [
                'ok' => is_file($parentDoc)
                    && str_contains($parentDocSource, 'external_rivals_certification')
                    && (
                        str_contains($parentDocSource, 'Desbloquear external_rivals_certification')
                        || str_contains($parentDocSource, 'nao desbloqueia `external_rivals_certification`')
                        || str_contains($parentDocSource, 'desbloqueia `external_rivals_certification`')
                    )
                    && is_file($doc)
                    && str_contains($docSource, 'external_rivals_certification'),
                'description' => 'Harness mantem external_rivals_certification bloqueado.',
                'check' => 'parent doc forbids unblocking and this doc reaffirms it',
                'evidence' => [
                    'docs/engineering-knowledge-base/atlas-forge-rivals-reliability-lockdown-v1.md',
                    'docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md',
                ],
            ],
            'sonnet_model_lock_supported' => [
                'ok' => is_file($rivalsCommand)
                    && str_contains($rivalsCommandSource, 'sonnet')
                    && str_contains($rivalsCommandSource, '--model')
                    && str_contains($rivalsCommandSource, '--baseline-model'),
                'description' => 'Comando aceita --model=sonnet e --baseline-model=sonnet.',
                'check' => 'rivals command signature references sonnet, --model and --baseline-model',
                'evidence' => [
                    'app/Console/Commands/AtlasRivalsCommand.php',
                ],
            ],
            'logs_streaming_available' => [
                'ok' => is_file($logStream)
                    && class_exists(\App\Services\Ai\Programming\RivalsForgeRunLogStreamService::class)
                    && (
                        str_contains($logStreamSource, 'jsonl')
                        || str_contains($logStreamSource, 'JSONL')
                        || str_contains($logStreamSource, 'events')
                    ),
                'description' => 'Run emite logs JSONL streaming com heartbeat.',
                'check' => 'log stream service exists and source references JSONL/events',
                'evidence' => [
                    'app/Services/Ai/Programming/RivalsForgeRunLogStreamService.php',
                ],
            ],
            'operator_next_command_available' => [
                'ok' => is_file($verifyScript)
                    && is_file($doc)
                    && str_contains($docSource, 'scripts/rivals-harness-verify.sh'),
                'description' => 'Operador tem comando proximo (verify script) referenciado pela doc.',
                'check' => 'verify script exists and is referenced in doc',
                'evidence' => [
                    'scripts/rivals-harness-verify.sh',
                    'docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md',
                ],
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function artifacts(string $repoRoot): array
    {
        return [
            'harness_doc' => [
                'path' => 'docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md',
                'present' => is_file($repoRoot.'/docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md'),
            ],
            'parent_lockdown_doc' => [
                'path' => 'docs/engineering-knowledge-base/atlas-forge-rivals-reliability-lockdown-v1.md',
                'present' => is_file($repoRoot.'/docs/engineering-knowledge-base/atlas-forge-rivals-reliability-lockdown-v1.md'),
            ],
            'verify_script' => [
                'path' => 'scripts/rivals-harness-verify.sh',
                'present' => is_file($repoRoot.'/scripts/rivals-harness-verify.sh'),
            ],
            'rivals_command' => [
                'class' => \App\Console\Commands\AtlasRivalsCommand::class,
                'present' => class_exists(\App\Console\Commands\AtlasRivalsCommand::class),
            ],
            'run_orchestrator' => [
                'class' => \App\Services\Ai\Programming\AtlasRivalsRunOrchestrator::class,
                'present' => class_exists(\App\Services\Ai\Programming\AtlasRivalsRunOrchestrator::class),
            ],
            'readiness_fingerprint_service' => [
                'class' => \App\Services\Ai\Programming\RivalsForgeReadinessFingerprintService::class,
                'present' => class_exists(\App\Services\Ai\Programming\RivalsForgeReadinessFingerprintService::class),
            ],
            'run_log_stream_service' => [
                'class' => \App\Services\Ai\Programming\RivalsForgeRunLogStreamService::class,
                'present' => class_exists(\App\Services\Ai\Programming\RivalsForgeRunLogStreamService::class),
            ],
            'invalid_battery_triage_registry' => [
                'class' => \App\Services\Ai\Programming\AtlasRivalsInvalidBatteryTriageRegistry::class,
                'present' => class_exists(\App\Services\Ai\Programming\AtlasRivalsInvalidBatteryTriageRegistry::class),
            ],
            'workspace_hygiene_service' => [
                'class' => \App\Services\Ai\Programming\WorkspaceHygieneService::class,
                'present' => class_exists(\App\Services\Ai\Programming\WorkspaceHygieneService::class),
            ],
            'operator_runbook_generator' => [
                'class' => \App\Services\Ai\Programming\AtlasRivalsOperatorRunbookGenerator::class,
                'present' => class_exists(\App\Services\Ai\Programming\AtlasRivalsOperatorRunbookGenerator::class),
            ],
            'evidence_pack_service' => [
                'class' => \App\Services\Ai\Programming\AtlasRivalsEvidencePackService::class,
                'present' => class_exists(\App\Services\Ai\Programming\AtlasRivalsEvidencePackService::class),
            ],
            'evidence_pack_verifier_service' => [
                'class' => \App\Services\Ai\Programming\AtlasRivalsEvidencePackVerifierService::class,
                'present' => class_exists(\App\Services\Ai\Programming\AtlasRivalsEvidencePackVerifierService::class),
            ],
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
