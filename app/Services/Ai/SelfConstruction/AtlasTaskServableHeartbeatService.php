<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use Closure;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Servable-heartbeat — every 5 minutes (via routes/console.php), reads the
 * AtlasTaskCoordinationHealthService snapshot and auto-fires the 3 recovery
 * commands (reap-leases → sweep-malformed → repair-blocked) when the queue is
 * jammed (servable_now=0 while claimable_depth>0).
 *
 * Writes an escalation receipt every tick so the operator has a forensic trail
 * even when the queue is healthy.
 */
final class AtlasTaskServableHeartbeatService
{
    public const SCHEMA = 'atlas.task_serving.servable_heartbeat.v1';

    public const RECOVERY_SEQUENCE = [
        'atlas:acp:reap-leases',
        'atlas:task:sweep-malformed',
        'atlas:task:repair-blocked',
    ];

    /** @var Closure():string */
    private Closure $now;

    /** @var Closure(string):int */
    private Closure $artisan;

    /** @var Closure():array<string,mixed> */
    private Closure $snapshotProvider;

    public function __construct(
        private readonly ?AtlasTaskCoordinationHealthService $health = null,
        private readonly ?string $receiptPath = null,
        ?callable $nowIso = null,
        ?callable $artisanCaller = null,
        ?callable $snapshotProvider = null,
    ) {
        $this->now = Closure::fromCallable($nowIso ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z'));
        $this->artisan = Closure::fromCallable($artisanCaller ?? static fn (string $cmd): int => Artisan::call($cmd, ['--json' => true]));
        $healthRef = $this->health;
        $this->snapshotProvider = Closure::fromCallable($snapshotProvider ?? static function () use ($healthRef): array {
            return ($healthRef ?? new AtlasTaskCoordinationHealthService())->snapshot();
        });
    }

    public function receiptPath(): string
    {
        return $this->receiptPath ?? storage_path('app/atlas/evidence/task-servable-heartbeat.json');
    }

    /**
     * @return array{
     *     schema:string, ok:bool, servable_now:int, claimable_depth:int,
     *     actions_fired:list<string>, receipt_path:string, status:string, timestamp:string
     * }
     */
    public function tick(): array
    {
        $timestamp = (string) ($this->now)();
        try {
            $snapshot = ($this->snapshotProvider)();
        } catch (Throwable $e) {
            $receipt = [
                'schema' => self::SCHEMA,
                'ok' => false,
                'status' => 'health_snapshot_failed',
                'timestamp' => $timestamp,
                'health_snapshot' => [],
                'actions_taken' => [],
                'error' => $e->getMessage(),
            ];
            $this->persistReceipt($receipt);

            return $this->envelope($receipt, [], 0, 0);
        }

        $servableNow = (int) ($snapshot['servable_now'] ?? 0);
        $claimableDepth = (int) ($snapshot['claimable_depth'] ?? 0);

        $actions = [];
        $status = 'ok';
        if ($servableNow === 0 && $claimableDepth > 0) {
            $status = 'recovery_fired';
            foreach (self::RECOVERY_SEQUENCE as $cmd) {
                try {
                    ($this->artisan)($cmd);
                    $actions[] = $cmd;
                } catch (Throwable) {
                    // never let a single recovery cmd wedge the heartbeat
                }
            }
        }

        $receipt = [
            'schema' => self::SCHEMA,
            'ok' => true,
            'status' => $status,
            'timestamp' => $timestamp,
            'health_snapshot' => [
                'servable_now' => $servableNow,
                'claimable_depth' => $claimableDepth,
            ],
            'actions_taken' => $actions,
        ];
        $this->persistReceipt($receipt);

        return $this->envelope($receipt, $actions, $servableNow, $claimableDepth);
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @param  list<string>  $actions
     */
    private function envelope(array $receipt, array $actions, int $servableNow, int $claimableDepth): array
    {
        return [
            'schema' => self::SCHEMA,
            'ok' => (bool) ($receipt['ok'] ?? false),
            'servable_now' => $servableNow,
            'claimable_depth' => $claimableDepth,
            'actions_fired' => $actions,
            'receipt_path' => $this->receiptPath(),
            'status' => (string) ($receipt['status'] ?? ''),
            'timestamp' => (string) ($receipt['timestamp'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function persistReceipt(array $receipt): void
    {
        $path = $this->receiptPath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        @file_put_contents($path, (string) json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
