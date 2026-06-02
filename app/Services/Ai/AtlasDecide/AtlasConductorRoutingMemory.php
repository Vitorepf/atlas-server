<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;

/**
 * Atlas Conductor Routing Memory — lightweight, self-contained learned routing.
 *
 * The conductor records the outcome of every real (LIVE) run here, and on a
 * later run with no operator-directed provider and no ADML route, it consults
 * this memory to AUTO-ROUTE to the provider that has performed best for the
 * same (task_category, role) — by success rate, then lowest average latency.
 *
 * This closes "ADML auto-routing" using the runtime's OWN accrued evidence,
 * with no dependency on the heavy Rivals performance-ledger / battery
 * subsystem: a few real runs and the runtime routes itself.
 *
 * Append-only JSONL. Provider-safe: no winner/benchmark/superiority claims —
 * just honest per-(task,role,provider) success counts.
 */
class AtlasConductorRoutingMemory
{
    public const SCHEMA = 'atlas.conductor.routing_memory.v1';

    public const RECOMMENDATION_SCHEMA = 'atlas.conductor.routing_recommendation.v1';

    /** Minimum recorded runs for a provider before it can be auto-routed. */
    public const MIN_SAMPLES = 1;

    private ?string $logOverride = null;

    public function setLogPathForTesting(?string $path): void
    {
        $this->logOverride = $path;
    }

    public function logPath(): string
    {
        if ($this->logOverride !== null) {
            return $this->logOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/conductor')
            : sys_get_temp_dir().'/atlas/conductor';

        return $base.DIRECTORY_SEPARATOR.'routing_memory.jsonl';
    }

    /**
     * Record a real run outcome for learned routing.
     *
     * @param  array<string,mixed>  $input  ['task_category','role','provider','model','result','latency_ms']
     */
    public function record(array $input): void
    {
        $task = trim((string) ($input['task_category'] ?? ''));
        $role = trim((string) ($input['role'] ?? ''));
        $provider = trim((string) ($input['provider'] ?? ''));
        if ($task === '' || $role === '' || $provider === '') {
            return;
        }

        $this->appendJsonl($this->logPath(), [
            'schema_version' => self::SCHEMA,
            'recorded_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'task_category' => $task,
            'role' => $role,
            'provider' => $provider,
            'model' => (string) ($input['model'] ?? ''),
            'result' => (string) ($input['result'] ?? ''),
            'latency_ms' => isset($input['latency_ms']) && is_numeric($input['latency_ms']) ? max(0, (int) $input['latency_ms']) : null,
        ]);
    }

    /**
     * Best provider for (task_category, role) by success rate, then lowest
     * average latency, then provider key (stable). Null if no qualifying
     * provider has at least MIN_SAMPLES runs.
     *
     * @return array<string,mixed>|null
     */
    public function recommend(string $taskCategory, string $role): ?array
    {
        $task = trim($taskCategory);
        $role = trim($role);
        if ($task === '' || $role === '') {
            return null;
        }

        /** @var array<string,array{n:int,success:int,latency:int}> $stats */
        $stats = [];
        foreach ($this->listEntries() as $e) {
            if ((string) ($e['task_category'] ?? '') !== $task || (string) ($e['role'] ?? '') !== $role) {
                continue;
            }
            $p = (string) ($e['provider'] ?? '');
            if ($p === '') {
                continue;
            }
            $stats[$p] ??= ['n' => 0, 'success' => 0, 'latency' => 0];
            $stats[$p]['n']++;
            if ((string) ($e['result'] ?? '') === 'success') {
                $stats[$p]['success']++;
            }
            $stats[$p]['latency'] += isset($e['latency_ms']) && is_numeric($e['latency_ms']) ? max(0, (int) $e['latency_ms']) : 0;
        }

        $best = null;
        foreach ($stats as $provider => $s) {
            if ($s['n'] < self::MIN_SAMPLES) {
                continue;
            }
            $rate = $s['n'] > 0 ? $s['success'] / $s['n'] : 0.0;
            $avgLatency = $s['n'] > 0 ? $s['latency'] / $s['n'] : (float) PHP_INT_MAX;
            $cand = [
                'schema_version' => self::RECOMMENDATION_SCHEMA,
                'provider' => $provider,
                'success_rate' => round($rate, 4),
                'avg_latency_ms' => (int) round($avgLatency),
                'samples' => $s['n'],
            ];
            if ($best === null || $this->better($cand, $best)) {
                $best = $cand;
            }
        }

        return $best;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listEntries(): array
    {
        return $this->readJsonl($this->logPath());
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    private function better(array $a, array $b): bool
    {
        if ($a['success_rate'] !== $b['success_rate']) {
            return $a['success_rate'] > $b['success_rate'];
        }
        if ($a['avg_latency_ms'] !== $b['avg_latency_ms']) {
            return $a['avg_latency_ms'] < $b['avg_latency_ms'];
        }

        return strcmp((string) $a['provider'], (string) $b['provider']) < 0;
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

    /**
     * @param  array<string,mixed>  $payload
     */
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
        $fp = @fopen($path, 'ab');
        if ($fp === false) {
            return;
        }
        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }
}
