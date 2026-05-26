<?php

declare(strict_types=1);

namespace App\Services\Ai\Patamar4;

use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Reconciliation\AtlasAutonomousReconciliationRuntimeService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Atlas Runtime Degradation Signal Service — Patamar 4 · C2.
 *
 * Closes the canon promise §2.1: "Atlas detecta degradação de si mesmo em
 * runtime e age dentro dos invariantes sem pedir permissão". This service
 * is the **generic ingress** for any subsystem (ACOP, ACMF, AEMOR, MCP,
 * latency telemetry, etc.) to report a degradation signal — and, when
 * severity warrants it, fires a Reconciliation tick out-of-cron-band so
 * Atlas can self-correct immediately rather than waiting for the next
 * every-15-minute window.
 *
 * Signals are append-only JSONL, sha256-tagged, and gated by Kernel
 * validateChange before any auto-tick is fired. Severity threshold is
 * canonical and operator-configurable.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-runtime-degradation-signal.md
 *
 * Schemas:
 *   - atlas.runtime_degradation.signal.v1
 *
 * Invariants:
 *   - Append-only JSONL.
 *   - Kernel gate before any auto-tick.
 *   - Severity canon: low | medium | high | critical.
 *   - Auto-tick fires only when severity >= configured threshold.
 *   - claim_policy provider-safe.
 *   - Service NEVER calls a provider or mutates external state directly;
 *     it just hands off to Reconciliation Runtime which is already audited.
 */
class AtlasRuntimeDegradationSignalService
{
    public const SCHEMA = 'atlas.runtime_degradation.signal.v1';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    public const VALID_SEVERITIES = [
        self::SEVERITY_LOW,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_HIGH,
        self::SEVERITY_CRITICAL,
    ];

    public const SEVERITY_RANK = [
        self::SEVERITY_LOW => 1,
        self::SEVERITY_MEDIUM => 2,
        self::SEVERITY_HIGH => 3,
        self::SEVERITY_CRITICAL => 4,
    ];

    public const DEFAULT_AUTO_TICK_THRESHOLD = self::SEVERITY_HIGH;

    private ?string $logPathOverride = null;

    public function __construct(
        private readonly AtlasConstitutionalKernelService $kernel,
        private readonly AtlasAutonomousReconciliationRuntimeService $reconciliation,
    ) {}

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

        return $base.DIRECTORY_SEPARATOR.'runtime_degradation_signals.jsonl';
    }

    /**
     * Record a degradation signal. When severity >= auto_tick_threshold AND
     * Kernel allows, fires an out-of-cron Reconciliation tick. Returns the
     * envelope (always, regardless of whether a tick was fired).
     *
     * @param  array<string,mixed>  $input  required: source, kind, severity, message; optional: metric_name, observed, threshold
     * @return array<string,mixed>
     */
    public function record(array $input): array
    {
        $source = (string) ($input['source'] ?? '');
        $kind = (string) ($input['kind'] ?? '');
        $severity = (string) ($input['severity'] ?? '');
        $message = (string) ($input['message'] ?? '');

        if ($source === '' || $kind === '' || $message === '') {
            throw new InvalidArgumentException('source, kind, and message are required.');
        }
        if (! in_array($severity, self::VALID_SEVERITIES, true)) {
            throw new InvalidArgumentException("Unknown severity '{$severity}'.");
        }

        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        // Kernel gate.
        $kernelEnv = $this->kernel->validateChange([
            'change_kind' => 'runtime_degradation_signal',
            'proposed_effect' => "record signal kind={$kind} severity={$severity} from {$source}",
            'scope' => ['privacy_class' => 'normal'],
            'actor' => $source,
        ]);
        $kernelBlocked = $kernelEnv['decision'] === AtlasConstitutionalKernelService::DECISION_BLOCK;

        $threshold = (string) config('atlas.patamar4.runtime_degradation_auto_tick_threshold', self::DEFAULT_AUTO_TICK_THRESHOLD);
        if (! in_array($threshold, self::VALID_SEVERITIES, true)) {
            $threshold = self::DEFAULT_AUTO_TICK_THRESHOLD;
        }
        $shouldAutoTick = ! $kernelBlocked
            && (bool) config('atlas.patamar4.runtime_degradation_auto_tick_enabled', true)
            && self::SEVERITY_RANK[$severity] >= self::SEVERITY_RANK[$threshold];

        $autoTick = null;
        $tickReceipt = null;
        if ($shouldAutoTick) {
            try {
                $tickReceipt = $this->reconciliation->tick([
                    'privacy_class' => 'normal',
                    'requested_autonomy' => 'execute_with_approval',
                    'action_kind' => 'stabilize_pipeline',
                    'triggered_by' => 'runtime_degradation_signal',
                    'signal_source' => $source,
                    'signal_kind' => $kind,
                    'signal_severity' => $severity,
                ]);
                $autoTick = [
                    'fired' => true,
                    'tick_hash' => $tickReceipt['tick_hash'] ?? null,
                    'outcome' => $tickReceipt['outcome'] ?? null,
                ];
            } catch (\Throwable $e) {
                $autoTick = [
                    'fired' => false,
                    'error' => 'reconciliation_tick_error: '.substr($e->getMessage(), 0, 120),
                ];
            }
        } else {
            $autoTick = [
                'fired' => false,
                'reason' => $kernelBlocked
                    ? 'kernel_blocked'
                    : (self::SEVERITY_RANK[$severity] < self::SEVERITY_RANK[$threshold]
                        ? 'severity_below_threshold'
                        : 'auto_tick_disabled'),
            ];
        }

        $envelope = [
            'schema_version' => self::SCHEMA,
            'recorded_at' => $generatedAt,
            'source' => $source,
            'kind' => $kind,
            'severity' => $severity,
            'severity_rank' => self::SEVERITY_RANK[$severity],
            'message' => mb_substr($message, 0, 240),
            'metric_name' => $input['metric_name'] ?? null,
            'observed' => $input['observed'] ?? null,
            'threshold' => $input['threshold'] ?? null,
            'kernel_decision' => $kernelEnv['decision'] ?? null,
            'kernel_hash' => $kernelEnv['kernel_hash'] ?? $this->kernel->kernelHash(),
            'auto_tick' => $autoTick,
            'claim_policy' => $this->claimPolicy(),
        ];
        $envelope['signal_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::SCHEMA,
            'recorded_at' => $generatedAt,
            'source' => $source,
            'kind' => $kind,
            'severity' => $severity,
            'message' => $envelope['message'],
        ], JSON_THROW_ON_ERROR));

        $this->appendJsonl($this->logPath(), $envelope);

        return $envelope;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listSignals(int $tail = 50): array
    {
        $all = $this->readJsonl($this->logPath());
        if ($tail <= 0) {
            return $all;
        }

        return array_slice($all, -$tail);
    }

    /**
     * @return array<string,bool>
     */
    public function claimPolicy(): array
    {
        return [
            'benchmark_claim_allowed' => false,
            'rivals_claim_allowed' => false,
            'superiority_claim_allowed' => false,
            'external_rivals_certification_touched' => false,
            'cognitive_immune_law_enforced' => true,
            'provider_safe_only_enforced' => true,
            'local_first_only' => true,
        ];
    }

    // ---------- internals ----------

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
