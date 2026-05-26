<?php

declare(strict_types=1);

namespace App\Services\Ai\Patamar4;

use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Governance\AtlasTrustBudgetService;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Atlas Subsystem Auto-Rebalance Service — Patamar 4 self-repair.
 *
 * Cenário canon: ACOP detecta latency degradation → diagnóstico interno →
 * propõe rebalance (cache_compact, agrn_reindex_advice, aemor_recompact_advice,
 * mcp_pool_warmup_advice). Operador-driven `--apply` consome Trust Budget na
 * tier apropriada e emite receipt.
 *
 * Hoje este service é planner + applier (advice-only quando não há infra
 * pgvector/redis disponíveis em test env). O receipt sempre é gravado.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-subsystem-auto-rebalance.md
 *
 * Schemas:
 *   - atlas.patamar4.rebalance_plan.v1
 *   - atlas.patamar4.rebalance_apply_receipt.v1
 *
 * Invariants:
 *   - plan() é READ-ONLY; nunca toca infra externa.
 *   - apply() requer Trust Budget consume verdict=allow.
 *   - 4 rebalance kinds canon; mutation runtime impossível.
 *   - Append-only JSONL.
 *   - claim_policy provider-safe.
 */
final class AtlasSubsystemAutoRebalanceService
{
    public const PLAN_SCHEMA = 'atlas.patamar4.rebalance_plan.v1';

    public const APPLY_SCHEMA = 'atlas.patamar4.rebalance_apply_receipt.v1';

    public const KIND_CACHE_COMPACT = 'cache_compact';

    public const KIND_AGRN_REINDEX = 'agrn_reindex_advice';

    public const KIND_AEMOR_RECOMPACT = 'aemor_recompact_advice';

    public const KIND_MCP_POOL_WARMUP = 'mcp_pool_warmup_advice';

    public const VALID_KINDS = [
        self::KIND_CACHE_COMPACT,
        self::KIND_AGRN_REINDEX,
        self::KIND_AEMOR_RECOMPACT,
        self::KIND_MCP_POOL_WARMUP,
    ];

    public const STATUS_NOOP = 'noop';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_DENIED_BUDGET = 'denied_budget';

    public const STATUS_DENIED_KERNEL = 'denied_kernel';

    /** Tier defaults per rebalance kind (operator can challenge via PR). */
    private const KIND_TIER = [
        self::KIND_CACHE_COMPACT => AtlasTrustBudgetService::TIER_LOW,
        self::KIND_AGRN_REINDEX => AtlasTrustBudgetService::TIER_MEDIUM,
        self::KIND_AEMOR_RECOMPACT => AtlasTrustBudgetService::TIER_HIGH,
        self::KIND_MCP_POOL_WARMUP => AtlasTrustBudgetService::TIER_LOW,
    ];

    private ?string $logPathOverride = null;

    /** @var array<string,Closure> opt-in real diagnostic probes per kind */
    private array $probes = [];

    public function __construct(
        private readonly AtlasConstitutionalKernelService $kernel,
        private readonly AtlasTrustBudgetService $trustBudget,
    ) {}

    /**
     * Wire a real diagnostic probe for a given rebalance kind.
     *
     * Closure signature: function(): array{observed: int|float|null, source: string, note?: string, ok?: bool}
     * Operator wires real probes via AppServiceProvider resolving callback.
     */
    public function setProbe(string $kind, ?Closure $probe): void
    {
        if (! in_array($kind, self::VALID_KINDS, true)) {
            throw new InvalidArgumentException("Cannot wire probe for unknown kind '{$kind}'.");
        }
        if ($probe === null) {
            unset($this->probes[$kind]);

            return;
        }
        $this->probes[$kind] = $probe;
    }

    public function setLogPathForTesting(?string $path): void
    {
        $this->logPathOverride = $path;
    }

    public function logPath(): string
    {
        if ($this->logPathOverride !== null) {
            return $this->logPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/patamar4')
            : sys_get_temp_dir().'/atlas/patamar4';

        return $base.DIRECTORY_SEPARATOR.'auto_rebalance.jsonl';
    }

    /**
     * Build a rebalance plan (READ-ONLY). Always records receipt.
     *
     * @return array<string,mixed>
     */
    public function plan(string $kind, string $actor = 'auto_rebalance'): array
    {
        if (! in_array($kind, self::VALID_KINDS, true)) {
            throw new InvalidArgumentException("Unknown rebalance kind '{$kind}'.");
        }

        $tier = self::KIND_TIER[$kind];
        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        $kernelEnv = $this->kernel->validateChange([
            'change_kind' => 'subsystem_auto_rebalance_plan',
            'proposed_effect' => "plan rebalance of kind {$kind}",
            'scope' => ['privacy_class' => 'normal'],
            'actor' => $actor,
        ]);

        $status = $kernelEnv['decision'] === AtlasConstitutionalKernelService::DECISION_BLOCK
            ? self::STATUS_DENIED_KERNEL
            : self::STATUS_PLANNED;

        $diagnostics = $this->diagnose($kind);

        $envelope = [
            'schema_version' => self::PLAN_SCHEMA,
            'planned_at' => $generatedAt,
            'kind' => $kind,
            'tier' => $tier,
            'actor' => $actor,
            'status' => $status,
            'diagnostics' => $diagnostics,
            'recommended_actions' => $this->recommendedActions($kind, $diagnostics),
            'kernel_decision' => $kernelEnv['decision'],
            'kernel_hash' => $kernelEnv['kernel_hash'] ?? $this->kernel->kernelHash(),
        ];
        $envelope['plan_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::PLAN_SCHEMA,
            'planned_at' => $generatedAt,
            'kind' => $kind,
            'status' => $status,
        ], JSON_THROW_ON_ERROR));

        $this->appendJsonl($this->logPath(), $envelope);

        return $envelope;
    }

    /**
     * Apply a rebalance plan. Consumes Trust Budget. Operator-driven.
     *
     * @return array<string,mixed>
     */
    public function apply(string $kind, string $actor, string $reason, string $operatorClass = AtlasTrustBudgetService::CLASS_OPERATOR): array
    {
        if (! in_array($kind, self::VALID_KINDS, true)) {
            throw new InvalidArgumentException("Unknown rebalance kind '{$kind}'.");
        }
        if ($actor === '' || $reason === '') {
            throw new InvalidArgumentException('actor and reason are required.');
        }

        $tier = self::KIND_TIER[$kind];
        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        // Kernel gate.
        $kernelEnv = $this->kernel->validateChange([
            'change_kind' => 'subsystem_auto_rebalance_apply',
            'proposed_effect' => "apply rebalance of kind {$kind}",
            'scope' => ['privacy_class' => 'normal'],
            'actor' => $actor,
        ]);
        if ($kernelEnv['decision'] === AtlasConstitutionalKernelService::DECISION_BLOCK) {
            return $this->recordApply($generatedAt, $kind, $tier, $actor, $reason, $operatorClass, self::STATUS_DENIED_KERNEL, null, 'kernel blocked');
        }

        // Trust Budget consume.
        try {
            $consume = $this->trustBudget->consume([
                'tier' => $tier,
                'operator_class' => $operatorClass,
                'action_kind' => 'auto_rebalance_'.$kind,
                'actor' => $actor,
                'reason' => $reason,
            ]);
        } catch (\Throwable $e) {
            return $this->recordApply($generatedAt, $kind, $tier, $actor, $reason, $operatorClass, self::STATUS_DENIED_BUDGET, null, 'trust budget error: '.substr($e->getMessage(), 0, 120));
        }

        if (($consume['verdict'] ?? '') !== AtlasTrustBudgetService::VERDICT_ALLOW) {
            return $this->recordApply($generatedAt, $kind, $tier, $actor, $reason, $operatorClass, self::STATUS_DENIED_BUDGET, $consume['action_id'] ?? null, 'trust budget verdict: '.($consume['verdict'] ?? 'unknown'));
        }

        // Advice-only execution (real infra rebalance is future work; receipt
        // documents the advice so operator can run it manually). This is
        // honest: service does NOT pretend to reindex pgvector itself.
        $diagnostics = $this->diagnose($kind);

        return $this->recordApply($generatedAt, $kind, $tier, $actor, $reason, $operatorClass, self::STATUS_APPLIED, $consume['action_id'], 'advice emitted; operator runs underlying infra command');
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listReceipts(): array
    {
        return $this->readJsonl($this->logPath());
    }

    public function lastReceipt(): ?array
    {
        $list = $this->listReceipts();

        return $list === [] ? null : $list[count($list) - 1];
    }

    // ---------- internals ----------

    /**
     * @return array<string,mixed>
     */
    private function diagnose(string $kind): array
    {
        $baseline = match ($kind) {
            self::KIND_CACHE_COMPACT => [
                'metric' => 'cache_entry_count',
                'source' => 'AtlasContextCacheCompilerRuntimeService (probe pendente)',
            ],
            self::KIND_AGRN_REINDEX => [
                'metric' => 'agrn_index_stale_fraction',
                'source' => 'AtlasGraphRetrievalNetworkService (probe pendente)',
            ],
            self::KIND_AEMOR_RECOMPACT => [
                'metric' => 'aemor_episode_redundancy',
                'source' => 'AtlasAemorRuntimeService.memoryAudit()',
            ],
            self::KIND_MCP_POOL_WARMUP => [
                'metric' => 'mcp_pool_cold_starts_last_hour',
                'source' => 'AtlasMcpTierService.tierManifest()',
            ],
            default => ['metric' => 'unknown', 'source' => 'unknown'],
        };

        if (isset($this->probes[$kind])) {
            try {
                $probed = ($this->probes[$kind])();
                if (! is_array($probed)) {
                    throw new \UnexpectedValueException('probe returned non-array');
                }
                $observed = $probed['observed'] ?? null;
                if ($observed !== null && ! is_int($observed) && ! is_float($observed)) {
                    $observed = null;
                }

                return [
                    'metric' => $baseline['metric'],
                    'observed' => $observed,
                    'source' => (string) ($probed['source'] ?? $baseline['source']),
                    'note' => (string) ($probed['note'] ?? 'real probe wired'),
                    'probe_status' => 'ok',
                ];
            } catch (\Throwable $e) {
                return [
                    'metric' => $baseline['metric'],
                    'observed' => null,
                    'source' => $baseline['source'],
                    'note' => 'probe_error: '.substr($e->getMessage(), 0, 120),
                    'probe_status' => 'error',
                ];
            }
        }

        return [
            'metric' => $baseline['metric'],
            'observed' => null,
            'source' => $baseline['source'],
            'note' => 'no probe wired — operator can call setProbe()',
            'probe_status' => 'unwired',
        ];
    }

    /**
     * @param  array<string,mixed>  $diagnostics
     * @return list<string>
     */
    private function recommendedActions(string $kind, array $diagnostics): array
    {
        return match ($kind) {
            self::KIND_CACHE_COMPACT => [
                'compact AtlasCachePoolService entries older than 24h',
                'log eviction count em ledger',
            ],
            self::KIND_AGRN_REINDEX => [
                'reindex AGRN partition with stale_fraction > 0.3',
                'verify new index hash against canonical',
            ],
            self::KIND_AEMOR_RECOMPACT => [
                'recompact AEMOR episodes with redundancy > 0.7',
                'preserve top-10 distinct vectors per partition',
            ],
            self::KIND_MCP_POOL_WARMUP => [
                'warm MCP pool to canonical floor',
                'log warmup latency per tier',
            ],
            default => [],
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function recordApply(
        string $appliedAt,
        string $kind,
        string $tier,
        string $actor,
        string $reason,
        string $operatorClass,
        string $status,
        ?string $tbActionId,
        string $note,
    ): array {
        $envelope = [
            'schema_version' => self::APPLY_SCHEMA,
            'applied_at' => $appliedAt,
            'kind' => $kind,
            'tier' => $tier,
            'operator_class' => $operatorClass,
            'actor' => $actor,
            'reason' => $reason,
            'status' => $status,
            'trust_budget_action_id' => $tbActionId,
            'note' => $note,
            'kernel_hash' => $this->kernel->kernelHash(),
        ];
        $envelope['receipt_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::APPLY_SCHEMA,
            'applied_at' => $appliedAt,
            'kind' => $kind,
            'status' => $status,
            'tier' => $tier,
            'operator_class' => $operatorClass,
        ], JSON_THROW_ON_ERROR));

        $this->appendJsonl($this->logPath(), $envelope);

        return $envelope;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonl(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    private function appendJsonl(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            if (function_exists('app')) {
                File::ensureDirectoryExists($dir);
            } else {
                @mkdir($dir, 0775, true);
            }
        }
        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }
        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }
}
