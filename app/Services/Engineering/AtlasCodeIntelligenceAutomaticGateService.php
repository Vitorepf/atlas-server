<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Throwable;

final class AtlasCodeIntelligenceAutomaticGateService
{
    public const SCHEMA_VERSION = 'atlas.code_intelligence.automatic_gate.v1';

    /**
     * @var array<string,array{name:string,why:string,evidence:array<int,string>}>
     */
    private const CONSUMERS = [
        'correct_context' => [
            'name' => 'Contexto correto',
            'why' => 'Context packs precisam carregar docs e code refs atuais.',
            'evidence' => [
                'app/Services/Engineering/EngineeringCodeIntelligenceService.php',
                'app/Services/Engineering/EngineeringContextPackService.php',
            ],
        ],
        'cartography' => [
            'name' => 'Cartografia',
            'why' => 'AURC usa o mapa de realidade como navegacao humana, nao fonte primaria.',
            'evidence' => [
                'app/Services/Engineering/AtlasUniversalRealityCartographyService.php',
                'app/Console/Commands/AtlasUniversalRealityCartographyCommand.php',
            ],
        ],
        'duplication_detection' => [
            'name' => 'Deteccao de duplicacao',
            'why' => 'ACRUI evita recriar runtime, surface ou contrato que ja existe.',
            'evidence' => [
                'app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php',
                'app/Console/Commands/AtlasCodeRealityCommand.php',
            ],
        ],
        'file_selection' => [
            'name' => 'Selecao de arquivos',
            'why' => 'Atlas Dev precisa selecionar arquivos e testes provaveis antes de editar.',
            'evidence' => [
                'app/Services/Ai/Programming/AtlasDev/Discovery/CodeDiscoveryEngine.php',
                'app/Services/Ai/Programming/Sdd/ContextBuilder.php',
            ],
        ],
        'patch_impact' => [
            'name' => 'Impacto de patch',
            'why' => 'Patch impact depende de relacao real entre alvo, testes, docs e edges.',
            'evidence' => [
                'app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php',
                'app/Services/Ai/Programming/AtlasDev/Intelligence/PatchIntelligenceService.php',
            ],
        ],
        'forge' => [
            'name' => 'Forge',
            'why' => 'Obras longas precisam entrar com mapa de codigo e testes atual.',
            'evidence' => [
                'app/Services/Ai/Programming/Forge/ForgeIntakeService.php',
                'app/Services/Ai/Programming/AtlasCodeForgeWorkIntakeService.php',
            ],
        ],
        'atlas_dev' => [
            'name' => 'Atlas Dev',
            'why' => 'O fluxo rapido falha fechado quando code intelligence esta vazio ou stale.',
            'evidence' => [
                'app/Services/Ai/Programming/Governance/Gates/ProgrammingCodeIntelligenceGate.php',
                'app/Services/Ai/Programming/AtlasDev/Discovery/CodeDiscoveryEngine.php',
            ],
        ],
        'acrui' => [
            'name' => 'ACRUI',
            'why' => 'ACRUI classifica uso real, reachability e risco antes de duplicar/deletar.',
            'evidence' => [
                'app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php',
                'docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md',
            ],
        ],
        'software_twin' => [
            'name' => 'Software Twin',
            'why' => 'ASTR precisa de mapa vivo para impacto, qualidade e snapshot.',
            'evidence' => [
                'app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php',
                'docs/engineering-knowledge-base/atlas-software-twin-verified-evolution-runtime.md',
            ],
        ],
        'avcel' => [
            'name' => 'AVCEL',
            'why' => 'AVCEL depende de contexto atual antes de cache, verificacao local e repair.',
            'evidence' => [
                'app/Services/Ai/VerifiedContextExecution/AtlasVerifiedContextExecutionLoopService.php',
                'docs/engineering-knowledge-base/atlas-verified-context-execution-loop.md',
            ],
        ],
        'ai_error_reduction' => [
            'name' => 'Reducao de erro das IAs',
            'why' => 'Session bootstrap e placement devem bloquear contexto velho antes de codar.',
            'evidence' => [
                'app/Services/Ai/Kernel/Architecture/AtlasSessionBootstrapService.php',
                'app/Services/Ai/Kernel/Architecture/AtlasFeaturePlacementService.php',
            ],
        ],
    ];

    /**
     * Repair migration that recreates Code Intelligence read-model tables when the migration ledger
     * is stamped but the physical schema is missing the tables.
     */
    public const SCHEMA_DRIFT_REPAIR_MIGRATION = 'database/migrations/2026_06_25_000100_repair_missing_atlas_engineering_code_intelligence_tables.php';

    public const SCHEMA_DRIFT_REPAIR_COMMAND = 'php artisan migrate --path=database/migrations/2026_06_25_000100_repair_missing_atlas_engineering_code_intelligence_tables.php';

    public function __construct(
        private readonly EngineeringCodeIntelligenceService $codeIntelligence,
        private readonly ?CodeIntelligenceSchemaDriftAuditor $schemaDriftAuditor = null,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(array $options = []): array
    {
        $startedAt = microtime(true);
        $workspace = $this->string($options['workspace'] ?? null) ?: base_path();
        $mode = $this->string($options['mode'] ?? null) ?: 'readiness';
        $autoRefresh = (bool) ($options['auto_refresh'] ?? false);
        $strictFreshness = (bool) ($options['strict_freshness'] ?? ($mode !== 'summary'));
        $maxAgeMinutes = $this->positiveInt($options['max_age_minutes'] ?? null, 1440);
        $runContextType = $this->string($options['run_context_type'] ?? null) ?: 'code_intelligence_automatic_gate';
        $runContextId = $this->string($options['run_context_id'] ?? null);

        $summary = $this->safeSummary($workspace);
        $readiness = null;
        $refresh = [
            'attempted' => false,
            'succeeded' => false,
            'reason' => null,
            'duration_ms' => null,
            'performance' => null,
        ];

        if ($strictFreshness) {
            $readiness = $this->safeReadiness($workspace, $runContextType, $runContextId);
        }

        $schemaDrift = $this->safeSchemaDrift();
        $consumerMatrix = $this->consumerMatrix();
        $blockers = $this->blockers($summary, $readiness, $strictFreshness, $maxAgeMinutes, $consumerMatrix, $schemaDrift);

        if ($autoRefresh && $this->shouldRefresh($summary, $readiness, $blockers)) {
            $refresh = $this->refresh($workspace, $runContextType, $runContextId);
            $summary = $this->safeSummary($workspace);
            $readiness = $strictFreshness
                ? $this->safeReadiness($workspace, $runContextType, $runContextId)
                : $readiness;
            $schemaDrift = $this->safeSchemaDrift();
            $consumerMatrix = $this->consumerMatrix();
            $blockers = $this->blockers($summary, $readiness, $strictFreshness, $maxAgeMinutes, $consumerMatrix, $schemaDrift);
        }

        $warnings = $this->warnings($summary, $readiness, $refresh, $this->docLinksRequiredForWorkspace($workspace));
        $status = $blockers === [] ? ($warnings === [] ? 'ready' : 'watch') : 'blocked';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'workspace' => $workspace,
            'mode' => $mode,
            'automatic_gate' => [
                'blocks_dev_forge_when_blocked' => true,
                'strict_freshness' => $strictFreshness,
                'max_age_minutes' => $maxAgeMinutes,
                'auto_refresh_allowed' => $autoRefresh,
                'default_refresh_command' => 'php artisan atlas:engineering:knowledge index-code --prune --summary-only --json',
                'strict_gate_command' => 'php artisan atlas:engineering:knowledge code-gate --strict --json',
                'auto_gate_command' => 'php artisan atlas:engineering:knowledge code-gate --auto-refresh --strict --json',
            ],
            'summary' => $this->summaryPayload($summary),
            'readiness' => $this->readinessPayload($readiness),
            'refresh' => $refresh,
            'consumers' => $consumerMatrix,
            'schema_drift' => $schemaDrift,
            'repair_guidance' => $this->repairGuidance($schemaDrift, $blockers),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'metrics' => [
                'drift_total' => (int) data_get($readiness, 'summary.drift_total', 0),
                'audit_duration_ms' => (int) data_get($readiness, 'summary.audit_duration_ms', 0),
                'cache_hit_rate' => data_get($readiness, 'audit.performance.cache.hit_rate'),
                'cache_hits' => data_get($readiness, 'audit.performance.cache.hits'),
                'cache_misses' => data_get($readiness, 'audit.performance.cache.misses'),
                'memory_peak_mb' => data_get($readiness, 'audit.performance.memory_peak_mb'),
                'consumer_count' => count($consumerMatrix),
                'consumer_missing_count' => count(array_filter($consumerMatrix, static fn (array $consumer): bool => $consumer['status'] !== 'covered')),
            ],
            'claim_policy' => [
                'provider_calls_made' => false,
                'external_graph_used' => false,
                'context_can_be_trusted_when_blocked' => false,
                'stale_index_allowed' => false,
                'mtime_cache_allowed' => false,
            ],
            'writes' => (bool) ($refresh['attempted'] ?? false),
            'duration_ms' => $this->elapsedMs($startedAt),
            'generated_at' => now()->toJSON(),
        ];
        $payload['gate_hash'] = MissionCanonicalHash::sha256($this->canonicalPayload($payload));

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function safeSummary(string $workspace): array
    {
        try {
            return $this->codeIntelligence->summary(['workspace' => $workspace]);
        } catch (Throwable $e) {
            return [
                'status' => 'summary_failed',
                'table_exists' => false,
                'module_count' => 0,
                'symbol_count' => 0,
                'doc_link_count' => 0,
                'exception' => $e::class,
                'exception_message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function safeReadiness(string $workspace, string $runContextType, ?string $runContextId): array
    {
        try {
            return $this->codeIntelligence->readiness([
                'workspace' => $workspace,
                'run_context_type' => $runContextType,
                'run_context_id' => $runContextId,
            ]);
        } catch (Throwable $e) {
            return [
                'schema_version' => 'atlas.code_intelligence.readiness.v1',
                'status' => 'blocked',
                'workspace' => $workspace,
                'summary' => [
                    'critical_failures' => 1,
                    'warnings' => 0,
                    'module_count' => 0,
                    'symbol_count' => 0,
                    'doc_link_count' => 0,
                    'drift_total' => null,
                    'audit_duration_ms' => 0,
                ],
                'critical_failures' => ['readiness_exception:'.$e::class],
                'warnings' => [],
                'exception_message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function refresh(string $workspace, string $runContextType, ?string $runContextId): array
    {
        $startedAt = microtime(true);
        try {
            $result = $this->codeIntelligence->index([
                'workspace' => $workspace,
                'prune' => true,
                'run_context_type' => $runContextType,
                'run_context_id' => $runContextId,
            ]);

            return [
                'attempted' => true,
                'succeeded' => (bool) ($result['ok'] ?? false),
                'reason' => 'automatic_code_intelligence_refresh',
                'duration_ms' => $this->elapsedMs($startedAt),
                'summary' => $this->summaryPayload((array) ($result['summary'] ?? [])),
                'performance' => $result['performance'] ?? null,
            ];
        } catch (Throwable $e) {
            return [
                'attempted' => true,
                'succeeded' => false,
                'reason' => 'refresh_exception:'.$e::class,
                'duration_ms' => $this->elapsedMs($startedAt),
                'exception_message' => $e->getMessage(),
                'performance' => null,
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $summary
     * @param  array<string,mixed>|null  $readiness
     * @return list<string>
     */
    private function blockers(array $summary, ?array $readiness, bool $strictFreshness, int $maxAgeMinutes, array $consumerMatrix, ?array $schemaDrift = null): array
    {
        $blockers = [];

        $driftStatus = $schemaDrift !== null ? (string) ($schemaDrift['status'] ?? '') : '';
        $driftType = $schemaDrift !== null ? (string) ($schemaDrift['drift_type'] ?? '') : '';
        $migrationLedgerStampedButTablesMissing = $driftStatus === 'schema_drift'
            && in_array($driftType, ['stamped_but_tables_missing', 'stamped_but_tables_partial'], true);

        if (! (bool) ($summary['table_exists'] ?? false)) {
            $blockers[] = $migrationLedgerStampedButTablesMissing
                ? 'code_intelligence_schema_drift_migration_stamped_tables_missing'
                : 'code_intelligence_tables_missing';
        }

        if ($schemaDrift !== null && $driftStatus === 'schema_drift' && $driftType === 'stamped_but_columns_missing') {
            $blockers[] = 'code_intelligence_schema_drift_columns_missing';
        }

        if (($summary['status'] ?? null) !== 'ready') {
            $blockers[] = 'code_intelligence_summary_not_ready';
        }

        foreach (['module_count', 'symbol_count', 'route_count', 'command_count', 'test_count'] as $field) {
            if ((int) ($summary[$field] ?? 0) <= 0) {
                $blockers[] = $field.'_empty';
            }
        }

        if ($strictFreshness && $readiness !== null) {
            if (($readiness['status'] ?? null) !== 'ready') {
                $blockers[] = 'code_intelligence_readiness_blocked';
            }

            foreach ((array) ($readiness['critical_failures'] ?? []) as $failure) {
                $blockers[] = (string) $failure;
            }
        }

        if ($strictFreshness) {
            $blockers = array_merge($blockers, $this->freshnessBlockers($summary, $maxAgeMinutes));
        }

        foreach ($consumerMatrix as $consumer) {
            if (($consumer['status'] ?? null) !== 'covered') {
                $blockers[] = 'consumer_missing:'.(string) ($consumer['id'] ?? 'unknown');
            }
        }

        return EngineeringStringListNormalizer::uniqueNonEmptyStrings($blockers);
    }

    /**
     * @param  array<string,mixed>  $summary
     * @param  array<string,mixed>|null  $readiness
     * @param  array<string,mixed>  $refresh
     * @return list<string>
     */
    private function warnings(array $summary, ?array $readiness, array $refresh, bool $docLinksRequired): array
    {
        $warnings = [];

        if ($docLinksRequired && (int) ($summary['doc_link_count'] ?? 0) <= 0) {
            $warnings[] = 'doc_links_empty';
        }

        if ((int) ($summary['migration_count'] ?? 0) <= 0) {
            $warnings[] = 'migration_count_empty';
        }

        if (($readiness['audit']['performance']['status'] ?? null) === 'slow') {
            $warnings[] = 'code_intelligence_audit_slow';
        }

        if (($refresh['attempted'] ?? false) && ! ($refresh['succeeded'] ?? false)) {
            $warnings[] = 'auto_refresh_failed';
        }

        return EngineeringStringListNormalizer::uniqueNonEmptyStrings($warnings);
    }

    private function docLinksRequiredForWorkspace(string $workspace): bool
    {
        try {
            $identity = app(CodeGraphWorkspaceIdentity::class);

            return $identity->resolve($workspace) === $identity->default();
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * @param  array<string,mixed>  $summary
     * @param  array<string,mixed>|null  $readiness
     * @param  list<string>  $blockers
     */
    private function shouldRefresh(array $summary, ?array $readiness, array $blockers): bool
    {
        if ($blockers === []) {
            return false;
        }

        $refreshable = [
            'code_intelligence_summary_not_ready',
            'code_intelligence_readiness_blocked',
            'audit_not_fresh',
            'drift_detected',
            'index_not_ready',
            'code_intelligence_index_stale_by_age',
            'code_intelligence_last_indexed_at_missing',
            'code_intelligence_last_indexed_at_invalid',
        ];

        if (array_intersect($blockers, $refreshable) === []) {
            return false;
        }

        if (in_array('code_intelligence_tables_missing', $blockers, true) || ! (bool) ($summary['table_exists'] ?? false)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>
     */
    private function summaryPayload(array $summary): array
    {
        return [
            'status' => $summary['status'] ?? 'unknown',
            'table_exists' => (bool) ($summary['table_exists'] ?? true),
            'module_count' => (int) ($summary['module_count'] ?? 0),
            'symbol_count' => (int) ($summary['symbol_count'] ?? 0),
            'doc_link_count' => (int) ($summary['doc_link_count'] ?? 0),
            'route_count' => (int) ($summary['route_count'] ?? 0),
            'command_count' => (int) ($summary['command_count'] ?? 0),
            'migration_count' => (int) ($summary['migration_count'] ?? 0),
            'test_count' => (int) ($summary['test_count'] ?? 0),
            'last_indexed_at' => $summary['last_indexed_at'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return list<string>
     */
    private function freshnessBlockers(array $summary, int $maxAgeMinutes): array
    {
        $lastIndexedAt = $this->string($summary['last_indexed_at'] ?? null);
        if ($lastIndexedAt === null) {
            return ['code_intelligence_last_indexed_at_missing'];
        }

        try {
            $indexedAt = CarbonImmutable::parse($lastIndexedAt);
            $ageMinutes = max(0, $indexedAt->diffInMinutes(CarbonImmutable::now()));
        } catch (Throwable) {
            return ['code_intelligence_last_indexed_at_invalid'];
        }

        return $ageMinutes > $maxAgeMinutes ? ['code_intelligence_index_stale_by_age'] : [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function consumerMatrix(): array
    {
        $rows = [];
        foreach (self::CONSUMERS as $id => $consumer) {
            $missing = array_values(array_filter(
                $consumer['evidence'],
                static fn (string $path): bool => ! File::exists(base_path($path)),
            ));
            $rows[] = [
                'id' => $id,
                'name' => $consumer['name'],
                'why' => $consumer['why'],
                'status' => $missing === [] ? 'covered' : 'missing_evidence',
                'evidence' => $consumer['evidence'],
                'missing_evidence' => $missing,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>|null  $readiness
     * @return array<string,mixed>|null
     */
    private function readinessPayload(?array $readiness): ?array
    {
        if ($readiness === null) {
            return null;
        }

        return [
            'schema_version' => $readiness['schema_version'] ?? 'atlas.code_intelligence.readiness.v1',
            'status' => $readiness['status'] ?? 'unknown',
            'summary' => $readiness['summary'] ?? [],
            'critical_failures' => $readiness['critical_failures'] ?? [],
            'warnings' => $readiness['warnings'] ?? [],
            'audit' => $readiness['audit'] ?? [],
            'duration_ms' => $readiness['duration_ms'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function canonicalPayload(array $payload): array
    {
        unset($payload['generated_at'], $payload['duration_ms'], $payload['gate_hash']);

        return $payload;
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function positiveInt(mixed $value, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max(1, (int) $value);
    }

    private function elapsedMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function safeSchemaDrift(): ?array
    {
        if ($this->schemaDriftAuditor === null) {
            return null;
        }
        try {
            return $this->schemaDriftAuditor->audit();
        } catch (Throwable $e) {
            return [
                'schema_version' => CodeIntelligenceSchemaDriftAuditor::SCHEMA_VERSION,
                'status' => 'unavailable',
                'drift_type' => 'auditor_exception',
                'exception_message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string,mixed>|null  $schemaDrift
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function repairGuidance(?array $schemaDrift, array $blockers): array
    {
        $migrationStampedTablesMissing = in_array(
            'code_intelligence_schema_drift_migration_stamped_tables_missing',
            $blockers,
            true,
        );
        $columnsMissing = in_array('code_intelligence_schema_drift_columns_missing', $blockers, true);

        $actions = [];
        if ($migrationStampedTablesMissing || $columnsMissing) {
            $actions[] = [
                'action' => 'run_schema_drift_repair_migration',
                'migration_path' => self::SCHEMA_DRIFT_REPAIR_MIGRATION,
                'command' => self::SCHEMA_DRIFT_REPAIR_COMMAND,
                'reason' => $migrationStampedTablesMissing
                    ? 'migration_ledger_marks_migration_ran_but_tables_are_missing'
                    : 'migration_ledger_marks_migration_ran_but_required_columns_are_missing',
            ];
        } elseif (in_array('code_intelligence_tables_missing', $blockers, true)) {
            $actions[] = [
                'action' => 'run_migrations',
                'command' => 'php artisan migrate',
                'reason' => 'required_code_intelligence_tables_have_never_been_created',
            ];
        } else {
            $actions[] = [
                'action' => 'refresh_code_intelligence_index',
                'command' => 'php artisan atlas:engineering:knowledge index-code --prune --summary-only --json',
                'reason' => 'index_is_empty_or_stale_but_schema_is_healthy',
            ];
        }

        return [
            'schema_drift_status' => $schemaDrift !== null ? (string) ($schemaDrift['status'] ?? 'unknown') : 'unavailable',
            'schema_drift_type' => $schemaDrift !== null ? (string) ($schemaDrift['drift_type'] ?? 'none') : 'none',
            'recommended_actions' => $actions,
        ];
    }
}
