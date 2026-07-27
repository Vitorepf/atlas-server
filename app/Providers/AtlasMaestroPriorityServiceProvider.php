<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\InFlight\AtlasAaelInFlightReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Aael\Parallel\AtlasAaelParallelLockManager;
use App\Services\Ai\SelfConstruction\Maestro\DynamicPriority\AtlasMaestroPriorityFactSnapshotter;
use App\Services\Ai\SelfConstruction\Maestro\DynamicPriority\AtlasMaestroPriorityReshaper;
use Illuminate\Support\ServiceProvider;

/**
 * Maestro dynamic priority + AAEL inflight ledger DI (full-pass ASP peel).
 */
final class AtlasMaestroPriorityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AtlasMaestroPriorityReshaper::class, function () {
            $configured = config('atlas.maestro.priority.sequence_path');
            $path = is_string($configured) && $configured !== ''
                ? $configured
                : storage_path('app/atlas/maestro/dynamic-priority');

            return new AtlasMaestroPriorityReshaper($path);
        });

        $this->app->singleton(AtlasMaestroPriorityFactSnapshotter::class, function () {
            $emptySource = static fn (): array => [];
            $snapshotsPath = (string) config(
                'atlas.maestro.priority.snapshots_path',
                storage_path('app/atlas/maestro/dynamic-priority/snapshots.jsonl'),
            );

            return new AtlasMaestroPriorityFactSnapshotter(
                pendingPacketsSource: $emptySource,
                leaseHistorySource: $emptySource,
                currentInFlightSource: $emptySource,
                snapshotsPath: $snapshotsPath,
            );
        });

        $this->app->singleton(AtlasAaelInFlightReceiptLedger::class, function () {
            $configured = config('atlas.aael.inflight.ledger_path');
            $path = is_string($configured) && $configured !== ''
                ? $configured
                : storage_path('app/atlas/aael/inflight/receipts.jsonl');

            return new AtlasAaelInFlightReceiptLedger($path);
        });

        // atlas:aael:parallel type-hints this on handle(), and Laravel injects
        // handle() dependencies BEFORE the body runs — so without a binding the
        // command died on invocation even though its own cli_enabled guard would
        // have returned early. Same config-path-with-storage-fallback shape as
        // the in-flight ledger above.
        $this->app->singleton(AtlasAaelParallelLockManager::class, function () {
            $configured = config('atlas.aael.parallel.ledger_path');
            $path = is_string($configured) && $configured !== ''
                ? $configured
                : storage_path('app/atlas/aael/parallel/locks.jsonl');

            return new AtlasAaelParallelLockManager($path);
        });
    }
}
