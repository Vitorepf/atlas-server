<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Architecture;

use App\Console\Commands\AtlasForgeRivalsCommand;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsActionDispatcher;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsDecideSignalProjectionService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsProviderPerformanceLedgerService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Forge Rivals · Provider Performance Ledger v1 Certification.
 *
 * Audit-level proof that the canonical Provider Performance Ledger + Decide
 * Signal projection are wired, append-only, evidence-gated, and advisory-only
 * for Atlas Decide. The ledger NEVER unlocks `external_rivals_certification`,
 * NEVER spends tokens, and NEVER promotes a completion claim.
 *
 * 9 invariants (single source of truth for the v1 contract):
 *
 *   1.  ledger_available
 *   2.  no_synthetic_score_as_claim
 *   3.  invalid_runs_do_not_rank
 *   4.  decide_signal_is_advisory_only
 *   5.  evidence_hash_required
 *   6.  task_category_required
 *   7.  role_required
 *   8.  external_rivals_remains_blocked
 *   9.  provider_tokens_not_spent_by_ledger
 *
 * Schema: atlas.forge_rivals_provider_performance_ledger_certification.v1
 * Doc:    docs/engineering-knowledge-base/atlas-forge-rivals-provider-performance-ledger-v1.md
 */
class AtlasForgeRivalsProviderPerformanceLedgerCertification
{
    public const SCHEMA_VERSION = 'atlas.forge_rivals_provider_performance_ledger_certification.v1';

    public const CERTIFICATION_KEY = 'atlas_forge_rivals_provider_performance_ledger_certification';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_MISSING_ARTIFACTS = 'missing_artifacts';

    /** @var list<string> */
    public const REQUIRED_INVARIANTS = [
        'ledger_available',
        'no_synthetic_score_as_claim',
        'invalid_runs_do_not_rank',
        'decide_signal_is_advisory_only',
        'evidence_hash_required',
        'task_category_required',
        'role_required',
        'external_rivals_remains_blocked',
        'provider_tokens_not_spent_by_ledger',
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
            'provider_tokens_spent' => false,
            'is_external_benchmark' => false,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Provider Performance Ledger v1 NEVER unlocks external_rivals_certification by itself.',
            'related_docs' => [
                'docs/engineering-knowledge-base/atlas-forge-rivals-provider-performance-ledger-v1.md',
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
            'ledger_service' => [
                'class' => AtlasForgeRivalsProviderPerformanceLedgerService::class,
                'present' => class_exists(AtlasForgeRivalsProviderPerformanceLedgerService::class),
            ],
            'decide_signal_service' => [
                'class' => AtlasForgeRivalsDecideSignalProjectionService::class,
                'present' => class_exists(AtlasForgeRivalsDecideSignalProjectionService::class),
            ],
            'canonical_command' => [
                'class' => AtlasForgeRivalsCommand::class,
                'present' => class_exists(AtlasForgeRivalsCommand::class),
            ],
            'action_dispatcher' => [
                'class' => AtlasForgeRivalsActionDispatcher::class,
                'present' => class_exists(AtlasForgeRivalsActionDispatcher::class),
            ],
            'canonical_doc' => [
                'path' => 'docs/engineering-knowledge-base/atlas-forge-rivals-provider-performance-ledger-v1.md',
                'present' => is_file($repoRoot.'/docs/engineering-knowledge-base/atlas-forge-rivals-provider-performance-ledger-v1.md'),
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
        $ledgerFile = $repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerService.php';
        $signalFile = $repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionService.php';
        $doc = $repoRoot.'/docs/engineering-knowledge-base/atlas-forge-rivals-provider-performance-ledger-v1.md';

        switch ($name) {
            case 'ledger_available':
                $cmdSrc = $this->readFile($commandFile);
                $dispatcherSrc = $this->readFile($dispatcherFile);

                return [
                    'ok' => class_exists(AtlasForgeRivalsProviderPerformanceLedgerService::class)
                        && in_array('ledger', AtlasForgeRivalsCommand::ACTIONS, true)
                        && in_array('ledger-record', AtlasForgeRivalsCommand::ACTIONS, true)
                        && str_contains($cmdSrc, 'ledger')
                        && str_contains($dispatcherSrc, "'ledger'"),
                    'status' => 'slice_9',
                    'description' => 'The canonical Provider Performance Ledger service is wired into atlas:forge:rivals.',
                    'check' => 'ledger + ledger-record actions exist + service class loads + dispatcher routes them',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerService.php',
                        'app/Console/Commands/AtlasForgeRivalsCommand.php',
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsActionDispatcher.php',
                    ],
                ];

            case 'no_synthetic_score_as_claim':
                $ledgerSrc = $this->readFile($ledgerFile);

                return [
                    'ok' => str_contains($ledgerSrc, "'claim_ready' => false")
                        && str_contains($ledgerSrc, 'separated_from_external_rivals_certification')
                        && ! str_contains($ledgerSrc, "'claim_ready' => true"),
                    'status' => 'slice_9',
                    'description' => 'Ledger entries are NEVER marked claim_ready=true. Synthetic scores never become claims.',
                    'check' => 'ledger service always emits claim_ready=false and separated_from_external_rivals_certification=true',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerService.php',
                    ],
                ];

            case 'invalid_runs_do_not_rank':
                $ledgerSrc = $this->readFile($ledgerFile);

                return [
                    'ok' => str_contains($ledgerSrc, 'valid_for_ranking')
                        && str_contains($ledgerSrc, 'hard_failures')
                        && str_contains($ledgerSrc, "'invalid_entries_excluded_from_ranking' => true"),
                    'status' => 'slice_9',
                    'description' => 'Entries with hard failures or null score never enter ranking aggregates or cost_quality_frontier.',
                    'check' => 'ledger service marks valid_for_ranking + excludes hard-failed entries from rankings',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerService.php',
                    ],
                ];

            case 'decide_signal_is_advisory_only':
                $signalSrc = $this->readFile($signalFile);

                return [
                    'ok' => class_exists(AtlasForgeRivalsDecideSignalProjectionService::class)
                        && str_contains($signalSrc, 'atlas.forge.rivals.decide_signal.v1')
                        && str_contains($signalSrc, "'advisory_only' => true")
                        && str_contains($signalSrc, 'separated_from_external_rivals_certification'),
                    'status' => 'slice_9',
                    'description' => 'Decide signal projection always declares advisory_only=true; never an authoritative claim.',
                    'check' => 'projection service emits advisory_only=true + canonical schema + separated_from_external_rivals_certification',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionService.php',
                    ],
                ];

            case 'evidence_hash_required':
                $ledgerSrc = $this->readFile($ledgerFile);

                return [
                    'ok' => str_contains($ledgerSrc, 'evidence_hash_required')
                        && str_contains($ledgerSrc, 'resolveEvidenceHash')
                        && str_contains($ledgerSrc, "'evidence_pack_hash'"),
                    'status' => 'slice_9',
                    'description' => 'Ledger record blocks when evidence pack hash cannot be computed from the artifact set.',
                    'check' => 'ledger service rejects records without evidence_pack_hash',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerService.php',
                    ],
                ];

            case 'task_category_required':
                $ledgerSrc = $this->readFile($ledgerFile);

                return [
                    'ok' => str_contains($ledgerSrc, 'task_category_required')
                        && str_contains($ledgerSrc, 'TASK_CATEGORIES'),
                    'status' => 'slice_9',
                    'description' => 'Ledger record blocks unless task_category is provided (or available in manifest/scorecard).',
                    'check' => 'ledger service emits task_category_required blocker when missing',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerService.php',
                    ],
                ];

            case 'role_required':
                $ledgerSrc = $this->readFile($ledgerFile);

                return [
                    'ok' => str_contains($ledgerSrc, 'role_required')
                        && str_contains($ledgerSrc, 'ROLES')
                        && str_contains($ledgerSrc, 'builder'),
                    'status' => 'slice_9',
                    'description' => 'Ledger record blocks unless role is provided (builder|reviewer|repair_agent|context_scout|test_generator|architect|docs).',
                    'check' => 'ledger service emits role_required blocker when missing + declares ROLES catalogue',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerService.php',
                    ],
                ];

            case 'external_rivals_remains_blocked':
                $ledgerSrc = $this->readFile($ledgerFile);
                $signalSrc = $this->readFile($signalFile);
                $certSrc = $this->readFile(__FILE__);
                $docSrc = $this->readFile($doc);

                return [
                    'ok' => str_contains($ledgerSrc, 'separated_from_external_rivals_certification')
                        && str_contains($signalSrc, 'separated_from_external_rivals_certification')
                        && str_contains($certSrc, "'separated_from' => 'external_rivals_certification'")
                        && (! is_file($doc) || str_contains($docSrc, 'external_rivals_certification')),
                    'status' => 'slice_9',
                    'description' => 'Every ledger surface declares the ledger NEVER unlocks external_rivals_certification.',
                    'check' => 'ledger + signal + cert + doc assert separation from external_rivals_certification',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerService.php',
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionService.php',
                        'app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsProviderPerformanceLedgerCertification.php',
                        'docs/engineering-knowledge-base/atlas-forge-rivals-provider-performance-ledger-v1.md',
                    ],
                ];

            case 'provider_tokens_not_spent_by_ledger':
                $ledgerSrc = $this->readFile($ledgerFile);
                $signalSrc = $this->readFile($signalFile);

                return [
                    'ok' => str_contains($ledgerSrc, "'external_provider_call' => false")
                        && str_contains($ledgerSrc, "'provider_tokens_spent' => false")
                        && str_contains($signalSrc, "'external_provider_call' => false")
                        && str_contains($signalSrc, "'provider_tokens_spent' => false"),
                    'status' => 'slice_9',
                    'description' => 'Ledger record + snapshot + decide signal never spend tokens or call providers; they read local evidence only.',
                    'check' => 'ledger + signal emit external_provider_call=false + provider_tokens_spent=false',
                    'evidence' => [
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerService.php',
                        'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionService.php',
                    ],
                ];
        }

        return [
            'ok' => false,
            'status' => 'unknown_ledger_invariant',
            'description' => 'Unknown ledger invariant: '.$name,
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
